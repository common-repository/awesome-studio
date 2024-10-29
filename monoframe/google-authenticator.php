<?php
 
class aw2_google_authenticator {

	/**
	 * The single instance of the class.
	 */
	protected static $_instance = null;

	/**
	 * Ensures only one instance of this class is loaded.
	 */
	public static function single_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor for this class.
	 */
	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'initialize' ) );
	}

	/**
	 * Attach actions / filters.
	 */
	public function initialize() {

		// Retrive Google authenticator enabled check.
		$is_enabled_google_authenticator = aw2_library::get( "site_settings.opt-g-suite-or-google-authenticator-enable-check" );

		// Is Google Authenticator activated Globally, if yes hook actions/filters.
		if( 'google-authenticator-login' === $is_enabled_google_authenticator ) {
			$this->hook_google_authenticator_actions_filters();
		}
	}

	/**
	 * Hook required actions and filters.
	 */
	public function hook_google_authenticator_actions_filters() {

		// Add Google Authenticator text field to login form.
		add_action( 'login_form', array( $this, 'aw2_gsuite_login_with_google_authenticator' ) );

		// Validate OTP enetered on login form submit.
		add_filter( 'authenticate', array( $this, 'aw2_google_authenticator_validate_totp'), 50, 3 );

		// Require only if Base32 Class is not loaded.
		if ( ! class_exists( 'Base32' ) ) {
			require_once( 'apis/google-authenticator/base32.php' );
		}

		// Following hooks required for only admin side.
		if ( is_admin() ) {
			// Hook admin side JS.
			add_action( 'admin_enqueue_scripts', array( $this, 'g_authenticator_qr_code_js' ) );

			// POST request to generate secret key.
			add_action( 'admin_footer', array( $this, 'generate_seceret_key_ajax_request' ) );

			// Ajax callback to generate Base32 secret key.
			add_action( 'wp_ajax_generate_serect_key_ajax_callback', array( $this, 'generate_serect_key_ajax_callback' ) );

			// Show Google Authenticator Settings Fields in user profile in the backend.
			add_action( 'show_user_profile',  array( $this, 'show_g_authenticator_settings_fields' ) );
			add_action( 'edit_user_profile', array( $this, 'show_g_authenticator_settings_fields' ) );

			// Update profile Settings Fields.
			add_action( 'personal_options_update', array( $this, 'update_g_authenticator_settings' ) );
			add_action( 'edit_user_profile_update', array( $this, 'update_g_authenticator_settings' ) );
		}
	}

	/**
	 * Enque QR code JS.
	 */
	public function g_authenticator_qr_code_js() {
		wp_register_script( 'aw2_qr_code_js', plugins_url( 'jquery.qrcode.min.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_script( 'aw2_qr_code_js' );
	}

	/**
	 * Validate enetered OTP on login form submit.
	 *
	 * @param object $user WP_User object.
	 *
	 * @param string $username Username.
	 *
	 * @param string $password Password.
	 *
	 * @return WP_User object
	 *
	 */
	public function aw2_google_authenticator_validate_totp( $user, $username = null, $password = null ) {

		// Get User by login.
		$user = get_user_by( 'login', $username );

		if ( false === $user ) {
			// Else Get User by email address.
			$user = get_user_by( 'email', $username );
		}

		// If user is found then only proceed.
		if( false !== $user ) {
			try {

				// Check if the user has activated 2-factor Authentication.
				$is_enabled_google_authenticator_by_user = get_user_meta( $user->ID, 'aw2_is_google_authenticator_enabled', true);

				if( empty( $is_enabled_google_authenticator_by_user ) ) {
					return $user;
				}

				$ga_entered_otp = "";

				// Get secret key.
				$ga_client_secret =	get_user_meta( $user->ID, 'aw2_google_authenticator_secret_key', true);

				if ( isset( $_POST['google_authenticator_entered_otp'] ) ) {
					$ga_entered_otp = sanitize_text_field ( $_POST['google_authenticator_entered_otp'] );
				}

				// If empty OTP throw error.
				if( empty( $ga_entered_otp ) ) {
					// If entered OTP is empty, then throw Exception.
					throw new Exception( "Please Enter OTP, generated by Google Authenticator App" );
				} elseif ( 6 !== strlen( $ga_entered_otp )  ) {
					// OTP must be 6 digit.
					throw new Exception( "Please, Enter a 6 digit valid OTP" );
				}

				// Validate TOTP.
				$timeslot = $this->validate_totp( $ga_entered_otp , $ga_client_secret, $user->ID );

				if( false === $timeslot ) {
					throw new Exception( "Error, wrong OTP entered! Pelase try again" );
				}

				// Store successful login attempt.
				update_user_meta( $user->ID, 'aw2_google_authenticator_prev_login_time', $timeslot, true );

			} catch ( Exception $exception ) {
				$user = new WP_Error('g-authenticator-errors', $exception->getMessage() );
			}
		}
		return $user;
	}

	/**
	 * Render, Google Authenticator OTP field.
	 */
	public function aw2_gsuite_login_with_google_authenticator() {
		?>
		<p>
			<label for="google_authenticator_entered_otp">Enter Google Authenticator Code<br>
			<input name="google_authenticator_entered_otp" id="google_authenticator_entered_otp" class="input" value="" size="20" type="text"></label>
		</p>
		<?php
		}

		/**
		 * Validate TOTP, with entered OTP.
		 *
		 * @param string $ga_entered_otp Username.
		 *
		 * @param string $ga_client_secret WP_User object
		 *
		 * @param int $user_id User Id.
		 *
		 * @return bool
		 */
		public function validate_totp( $ga_entered_otp , $ga_client_secret, $user_id ) {

			$tm = floor( time() / 30 );

			$secret_key = Base32::decode( $ga_client_secret );

			// Check if previous sucessful login attempt.
			$previous_login = get_user_meta( $user_id, 'aw2_google_authenticator_prev_login_time', true );

			// Retrive increased time window.
			$otp_time_window = get_user_meta( $user_id, 'aw2_google_authenticator_time_window', true );

			// Increase Time window, if option is set.
			if( '1' === $otp_time_window ) {
				$start_time_window = -10;
				$end_time_window = 10;
			} else {
				$start_time_window = -1;
				$end_time_window = 1;
			}

			// Keys from 30 seconds or 5 minutes before and after are valid as well.
			for ( $i = $start_time_window; $i <= $end_time_window; $i++ ) {
				// Time Packed into binary string
				$time = chr(0).chr(0).chr(0).chr(0).pack( 'N*', $tm + $i );

				// Hash it with users secret key
				$hm = hash_hmac( 'SHA1', $time, $secret_key, true );

				$offset = ord( substr( $hm, -1) ) & 0x0F;

				// Retrive just 4 bytes from the result.
				$hashpart = substr( $hm, $offset, 4);

				// Unpack the binary value
				$value = unpack( "N", $hashpart );
				$value = $value[1];

				// Extract Only 32 bits of the value
				$value = $value & 0x7FFFFFFF;
				$value = $value % 1000000;

				if ( $value === (int) $ga_entered_otp ) {
					// Check for replay (Man-in-the-middle) attack.
					if ( $previous_login >= ($tm+$i) ) {
						error_log(" May be replay (Man-in-the-middle) attack detected");
						return false;
					}

					// Return timeslot in which login happened.
					return $tm+$i;
				}
			}
			return false;
		}

		/**
		 * Generate secret key.
		 */
		public function generate_serect_key_ajax_callback() {
			// Retrive POST nonce data.
			$nonce = $_POST['g_a_secret_key_nonce'];

			// Check if valid nonce.
			if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'g_a_secret_key' ) ) {
				echo json_encode( array( "error" => "Error occured, Refresh this page or Please try again later" ) );
			} else {
				$secret = $this->create_base32_secrect_key();
				echo json_encode( array( "secret_key" => $secret ) );
			}
			wp_die();
		}

		/**
		 * Create Base32 secret key.
		 *
		 * @return string Secret Key.
		 */
		private function create_base32_secrect_key() {
			// Base32 valid characters.
			$base_32_valid_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
			$secret = '';
			for ( $i = 0; $i < 16; $i++ ) {
				$secret .= substr( $base_32_valid_chars, wp_rand( 0, strlen( $base_32_valid_chars ) - 1 ), 1 );
			}
			return $secret;
		}

		/**
		 * JS required for Google Authenticator functionality.
		 * 1) POST request to Generate secret key.
		 * 2) Show hide QR code.
		 * 3) Render QR code.
		 */
		function generate_seceret_key_ajax_request() {

			// If query parameter user_id is set, which means profile other than self is been edited.
			if( isset( $_GET['user_id'] ) ){
				$user_id = filter_input( INPUT_GET, 'user_id', FILTER_SANITIZE_NUMBER_INT );
			} else {
				$user_id = get_current_user_id();
			}

			$user_data = get_userdata( $user_id );
			?>

			<script type="text/javascript" >

			jQuery(document).ready( function( $ ) {
				// On click Generate new secret key.
				$('#aw2_google_authenticator_generate_secret_key').click(function() {

					var ga_secret_key = $('#aw2_google_authenticator_secret_key');

					// Call Specified action, which will return secret Key.
					var data = {
						'action': 'generate_serect_key_ajax_callback',
						'g_a_secret_key_nonce' : '<?php echo wp_create_nonce( 'g_a_secret_key' ); ?>'
					};

					/**
			 		 * This is Key URI used for Googlge Authenticator for QR code, for more details please visit
					 * https://github.com/google/google-authenticator/wiki/Key-Uri-Format
					*/
					// Make the POST request to generate New secret Key.
					jQuery.post( ajaxurl, data, function( response ) {
						var response = JSON.parse( response );

						$('#aw2_secret_key_nonce_error').remove();
						if( response.error ) {
							$('#aw2_google_authenticator_generate_secret_key').after( '<span style="font-style: italic; color: red;margin-inline-start: 10px;" id="aw2_secret_key_nonce_error">' + response.error + '</span>' );
						} else if( response.secret_key ) {
							ga_secret_key.val(  response.secret_key );

							// Render the qrcode.
							render_qr_code( response.secret_key );
						}
					}); // on Button Click.
				});

				// Show hide QR code box. Render it if required.
				$('#aw2_show_hide_qr_code').click(function() {
					var ga_secret_key = $('#aw2_google_authenticator_secret_key').val();
					var qr_code_div = $('#aw2_qr_code_div');
					render_qr_code( ga_secret_key );
					toggle_qr_code( qr_code_div );
				});

				function toggle_qr_code( qr_code_div ) {
					if ( qr_code_div.is(':hidden')) {
						qr_code_div.show('slow');
					} else {
						qr_code_div.hide('slow');
					}
				} // toggle_qr_code.

				// Render QR code.
				function render_qr_code( secret_key ) {
					// Build QR code key URI.
					var qrcode_key_uri = "otpauth://totp/<?php echo $user_data->user_email; ?>?issuer=<?php echo get_bloginfo( 'name' ); ?>&secret=";

					var qr_code_div = $( '#aw2_qr_code_div' );
					// Update the newly created Key URI.
					qrcode_key_uri += secret_key;
					qr_code_div.html( "" );
					// Create and append it to div.
					qr_code_div.qrcode( qrcode_key_uri );
				}
			});
			</script>
			<?php
		}

		/**
		 * Show Google authenticator Settings Fields in User Profile edit.
		 *
		 * @param  object $user WP_User.
		 */
		public function show_g_authenticator_settings_fields( $user ) {

			// Check if 2-factor authentication is enabled.
			$is_google_authenticator_enabled = get_user_meta( $user->ID, 'aw2_is_google_authenticator_enabled', true );

			// Retrieve secret key.
			$ga_secret_key = get_user_meta( $user->ID, 'aw2_google_authenticator_secret_key', true );

			// Retrieve increased time window check.
			$ga_time_window_enabled = get_user_meta( $user->ID, 'aw2_google_authenticator_time_window', true );

			// Build the Account name string.
			$account_name = get_bloginfo( 'name' ) . ' (' . $user->user_email . ')';

			if( empty( $ga_secret_key ) ) {
				$ga_secret_key = $this->create_base32_secrect_key();
			}
			// Render Google Authenticator Settings fields
			?>
			<h3>Enable 2-Factor Authentication using Google Authenticator App</h3>
			<table class="form-table">
				<tr>
					<th><label for="aw2_is_google_authenticator_enabled">Enable Google Authenticator</label></th>
					<td>
						<input type='checkbox' name="aw2_is_google_authenticator_enabled"	<?php isset( $is_google_authenticator_enabled ) ? checked( $is_google_authenticator_enabled, 1 ) : ''; ?>
						value='1'>
						<br />
						<span class="description">Enable 2-factor Authentication, for your account. Install Google Authenticator App from
							<a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2&hl=en" target="blank">here</a></span>
						</td>
					</tr>
					<tr>
						<th><label for="aw2_google_authenticator_secret_key">Secret key</label></th>
						<td>
							<div>
								Manually enter Secret Key into Google Authenticator App
								<br /><br />
								<input type="text" name="aw2_google_authenticator_secret_key"
								id="aw2_google_authenticator_secret_key" value="<?php echo esc_attr( $ga_secret_key ); ?>" class="regular-text"
								readonly="readonly" size="20" />
								<button type="button" class="button" name="aw2_google_authenticator_generate_secret_key" id="aw2_google_authenticator_generate_secret_key">Generate Secret Key</button>
							</div>
							<span class="description">If secret key is renegerated, you need to re-enter secret key or rescan QR code.</span>
							<br/><br/> OR <br/><br/>
							<div>
								Scan QR code using Google Authenticator App.
								<button type="button" class="button" id="aw2_show_hide_qr_code">View / Hide Qr code</button>
								<br />
								<div id="aw2_qr_code_div" style="display:none"></div>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="aw2_google_authenticator_time_window">Increase time window </label></th>
						<td>
							<input type='checkbox' name="aw2_google_authenticator_time_window"	<?php isset( $ga_time_window_enabled ) ? checked( $ga_time_window_enabled, 1 ) : ''; ?>
							value='1'>
							<br />
							<span class="description">Increase the time window for Authentication to ±5 min insted of default ±30 sec window</span>
						</td>
					</tr>
					<tr>
						<th><label for="aw2_google_authenticator_account_name">Account Name</label></th>
						<td>
							<?php echo $account_name; ?>
							<br />
							<span class="description">Enter this as Account Name, when manually Entering the provide secret key</span>
						</td>
					</tr>
				</table>
				<?php
			}

			/**
		   * Show Google authenticator Settings Fields in User Profile edit.
		 	 *
			 * @param int $user_id User Id.
			 */
			public function update_g_authenticator_settings( $user_id ) {

				if ( ! current_user_can( 'edit_user', $user_id ) ) {
					return false;
				}

				// Update if G-Authenticator is enabled/ Disabled.
				update_user_meta( $user_id, 'aw2_is_google_authenticator_enabled', sanitize_text_field( $_POST['aw2_is_google_authenticator_enabled'] ) );

				// Update G-Authenticator secret key.
				update_user_meta( $user_id, 'aw2_google_authenticator_secret_key', sanitize_text_field( $_POST['aw2_google_authenticator_secret_key'] ) );

				// Update G-Authenticator time window.
				update_user_meta( $user_id, 'aw2_google_authenticator_time_window', sanitize_text_field( $_POST['aw2_google_authenticator_time_window'] ) );
			}
		}

		// Load the single instance.
	aw2_google_authenticator::single_instance();