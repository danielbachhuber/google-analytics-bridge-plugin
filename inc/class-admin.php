<?php
/**
 * Controller for admin interfaces.
 *
 * @package google-analytics-bridge
 */

namespace GAB;

/**
 * Controller for admin interfaces.
 */
class Admin extends Base {

	/**
	 * Capability required for accessing settings page.
	 *
	 * @var string
	 */
	protected static $capability = 'manage_options';

	/**
	 * Local URI used for the connection callback.
	 *
	 * @var string
	 */
	protected static $connect_callback_uri = 'oauth2callback/gab';

	/**
	 * Identifier used for disconnecting the Google connection.
	 *
	 * @var string
	 */
	protected static $disconnect_callback_option = 'gab-disconnect-google';

	/**
	 * URL used for the initial authorization request.
	 *
	 * @var string
	 */
	protected static $google_auth_url = 'https://accounts.google.com/o/oauth2/auth';

	/**
	 * Requested authorization scope from Google.
	 *
	 * @var string
	 */
	protected static $google_scope_requested = 'https://www.googleapis.com/auth/analytics.readonly';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	protected static $settings_page = 'google_analytics_bridge';

	/**
	 * Registers the admin link under the Options menu.
	 */
	public static function action_admin_menu() {
		if ( 'user' !== self::get_authentication_mode() ) {
			return;
		}
		add_options_page( esc_html__( 'Google Analytics Bridge', 'google-analytics-bridge' ), esc_html__( 'Google Analytics Bridge', 'google-analytics-bridge' ), self::$capability, self::$settings_page, array( __CLASS__, 'handle_settings_page' ) );
	}

	/**
	 * Renders the settings page.
	 */
	public static function handle_settings_page() {
		self::get_template_part( 'settings', array(), true );
	}

	/**
	 * Handle authentication callback from Google
	 */
	public static function handle_google_auth_callback() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_uri = sanitize_text_field( $_SERVER['REQUEST_URI'] );
		if ( false === stripos( $request_uri, self::$connect_callback_uri ) ) {
			return;
		}

		if ( empty( $_GET['code'] ) ) { // phpcs:ignore: WordPress.Security.NonceVerification.NoNonceVerification
			wp_die( esc_html__( 'Invalid authorization code.', 'google-analytics-bridge' ) );
		}

		if ( ! current_user_can( self::$capability ) ) {
			wp_die( esc_html__( 'You don\'t have access to perform this action. Please contact an administrator.', 'google-analytics-bridge' ) );
		}

		// Fetch the actual token from the Google.
		$response = wp_remote_post(
			self::$google_token_url,
			array(
				'body' => array(
					'code'          => sanitize_text_field( $_GET['code'] ), // phpcs:ignore: WordPress.Security.NonceVerification.NoNonceVerification
					'client_id'     => self::get_client_id(),
					'client_secret' => self::get_client_secret(),
					'redirect_uri'  => self::get_oauth_callback_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$response_body = wp_remote_retrieve_body( $response );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// translators: Displays response body from Google.
			wp_die( sprintf( esc_html__( 'Error fetching oauth2 token from Google: %s', 'google-analytics-bridge' ), wp_kses_post( '<pre>' . $response_body . '</pre>' ) ) );
		}

		$data = json_decode( $response_body );
		if ( empty( $data->access_token ) ) {
			// translators: Displays response body from Google.
			wp_die( sprintf( esc_html__( 'Error fetching oauth2 token from Google: %s', 'google-analytics-bridge' ), wp_kses_post( '<pre>' . $response_body . '</pre>' ) ) );
		}

		$refresh_token = ! empty( $data->refresh_token ) ? sanitize_text_field( $data->refresh_token ) : '';
		update_option(
			self::$stored_credentials_option,
			array(
				'access_token'      => sanitize_text_field( $data->access_token ),
				'expire_time'       => time() + (int) $data->expires_in,
				'refresh_token'     => $refresh_token,
				'original_response' => wp_remote_retrieve_body( $response ),
			)
		);
		$query_args   = array(
			'success' => 'google-connect',
			'page'    => self::$settings_page,
		);
		$redirect_url = add_query_arg( $query_args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle a request to disconnect Google auth
	 */
	public static function handle_google_disconnect_callback() {
		if ( empty( $_GET['action'] ) || self::$disconnect_callback_option !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.NoNonceVerification
			return;
		}

		$nonce = sanitize_text_field( $_GET['nonce'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( ! current_user_can( self::$capability ) || ! wp_verify_nonce( $nonce, self::$disconnect_callback_option ) ) {
			wp_die( esc_html__( "You shouldn't be doing this, sorry.", 'google-analytics-bridge' ) );
		}

		update_option( self::$stored_credentials_option, '' );
		$query_args   = array(
			'page'    => self::$settings_page,
			'success' => 'google-disconnect',
		);
		$redirect_url = add_query_arg( $query_args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Build the auth URL to link to, which begins the auth process.
	 */
	public static function get_auth_callback_url() {
		$query_args = array(
			'client_id'       => self::get_client_id(),
			'redirect_uri'    => self::get_oauth_callback_redirect_uri(),
			'response_type'   => 'code',
			'access_type'     => 'offline',
			'approval_prompt' => 'force',
			'scope'           => rawurlencode( self::$google_scope_requested ),
		);
		return add_query_arg( $query_args, self::$google_auth_url );
	}

	/**
	 * Build the disconnect URL to link to, which begins the de-auth process.
	 *
	 * @return string
	 */
	public static function get_disconnect_callback_url() {
		$query_args = array(
			'action' => self::$disconnect_callback_option,
			'nonce'  => wp_create_nonce( self::$disconnect_callback_option ),
		);

		return add_query_arg( $query_args, admin_url( 'index.php' ) );
	}

	/**
	 * Builds the redirect_uri to send to Google with the auth callback request.
	 *
	 * Because Google disallows .dev and .local domains as callback urls, we
	 * replace those with localhost here. In test environments, the developer is
	 * responsible for building a local pass-through redirect from localhost to
	 * the domain of your dev site. Note: it also works to just let Google
	 * redirect to 'localhost', then changing the domain in your address bar and
	 * passing the request on yourself.
	 */
	protected function get_oauth_callback_redirect_uri() {
		$redirect_uri = home_url( self::$connect_callback_uri );
		$host         = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$host_bits    = explode( '.', $host );

		$tld = array_pop( $host_bits );
		if ( in_array( $tld, array( 'dev', 'local', 'test' ), true ) ) {
			$redirect_uri = str_replace( $host, 'localhost', $redirect_uri );
		}

		return apply_filters( 'gab_redirect_uri', $redirect_uri );
	}

	/**
	 * Get a rendered template part
	 *
	 * @param string  $template Template part to render.
	 * @param array   $vars     Any variables to pass through to the template.
	 * @param boolean $render   Whether or not to render the template part.
	 * @return string|null
	 */
	public static function get_template_part( $template, $vars = array(), $render = false ) {
		$full_path = dirname( dirname( __FILE__ ) ) . '/parts/' . $template . '.php';

		if ( ! file_exists( $full_path ) ) {
			return '';
		}

		if ( ! $render ) {
			ob_start();
		}
		// @codingStandardsIgnoreStart
		if ( ! empty( $vars ) ) {
			extract( $vars );
		}
		// @codingStandardsIgnoreEnd
		include $full_path;
		if ( ! $render ) {
			return ob_get_clean();
		}
	}

}
