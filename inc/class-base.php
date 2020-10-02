<?php
/**
 * Base controller for the plugin.
 *
 * @package google-analytics-bridge
 */

namespace GAB;

/**
 * Base controller for the plugin.
 */
class Base {

	/**
	 * URL used for the token request.
	 *
	 * @var string
	 */
	protected static $google_token_url = 'https://accounts.google.com/o/oauth2/token';

	/**
	 * Option storing oAuth connection details.
	 *
	 * @var string
	 */
	protected static $stored_credentials_option = 'gab_oauth2';

	/**
	 * Gets the Google Analytics client ID for use by plugin.
	 *
	 * @return string
	 */
	public static function get_client_id() {
		return apply_filters( 'gab_ga_client_id', '' );
	}

	/**
	 * Gets the Google Analytics client secret for use by plugin.
	 *
	 * @return string
	 */
	public static function get_client_secret() {
		return apply_filters( 'gab_ga_client_secret', '' );
	}

	/**
	 * Gets the Google Analytics profile ID used by the plugin.
	 *
	 * @return string
	 */
	public static function get_profile_id() {
		return apply_filters( 'gab_ga_profile_id', '' );
	}

	/**
	 * Whether or not authentication is via the user flow or service account.
	 *
	 * @return string
	 */
	public static function get_authentication_mode() {
		return apply_filters( 'gab_ga_authentication_mode', 'user' );
	}

	/**
	 * Gets the stored oAuth connection details.
	 *
	 * @return array
	 */
	protected static function get_google_auth_details() {
		return get_option( self::$stored_credentials_option, array() );
	}

	/**
	 * Get the current Google oAuth token.
	 *
	 * @return array|false
	 */
	protected static function get_current_google_token() {
		if ( ! self::get_google_auth_details()
			&& 'user' === self::get_authentication_mode() ) {
			return false;
		}

		if ( self::is_google_access_token_expired() ) {
			$access_refreshed = 'service' === self::get_authentication_mode() ? self::refresh_google_access_token_with_jwt() : self::refresh_google_access_token();

			if ( ! $access_refreshed || is_wp_error( $access_refreshed ) ) {
				return false;
			}
		}

		return self::get_google_auth_details();
	}

	/**
	 * Whether or not the GA access token is expired.
	 *
	 * @return boolean
	 */
	protected static function is_google_access_token_expired() {
		$access_details = self::get_google_auth_details();
		if ( empty( $access_details['expire_time'] ) || $access_details['expire_time'] < time() + 60 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Refreshes the Google access token if needed.
	 *
	 * @return true|WP_Error
	 */
	protected static function refresh_google_access_token() {
		$access_details = self::get_google_auth_details();

		// Fetch the actual token from the Google.
		$response = wp_remote_post(
			self::$google_token_url,
			array(
				'body' => array(
					'client_id'     => self::get_client_id(),
					'client_secret' => self::get_client_secret(),
					'grant_type'    => 'refresh_token',
					'refresh_token' => $access_details['refresh_token'],
				),
			)
		);

		$response_body = wp_remote_retrieve_body( $response );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// translators: Displays the response body from Google.
			return new \WP_Error( 'oauth-refresh', sprintf( esc_html__( 'Error fetching oauth2 token from Google: %s', 'google-analytics-bridge' ), wp_kses_post( '<pre>' . $response_body . '</pre>' ) ) );
		}

		$data = json_decode( $response_body );

		if ( empty( $data->access_token ) ) {
			// translators: Displays the response body from Google.
			return new \WP_Error( 'oauth-refresh', sprintf( esc_html__( 'Error fetching oauth2 token from Google: %s', 'google-analytics-bridge' ), wp_kses_post( '<pre>' . $response_body . '</pre>' ) ) );
		}

		update_option(
			self::$stored_credentials_option,
			array(
				'access_token'      => sanitize_text_field( $data->access_token ),
				'expire_time'       => time() + (int) $data->expires_in,
				'refresh_token'     => $access_details['refresh_token'],
				'original_response' => wp_remote_retrieve_body( $response ),
			)
		);

		return true;
	}

	/**
	 * Refreshes the Google access token from a JWT if needed.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh_google_access_token_with_jwt() {
		$access_details = apply_filters( 'gab_ga_service_account_details', array() );
		if ( empty( $access_details ) ) {
			return new \WP_Error( 'oauth-refresh', esc_html__( 'Service account details are missing.', 'google-analytics-bridge' ) );
		}

		$header = wp_json_encode( array(
			'typ' => 'JWT',
			'alg' => 'RS256',
		) );
		$payload = wp_json_encode( array(
			'iss'   => $access_details['client_email'],
			'aud'   => $access_details['token_uri'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'exp'   => time() + 3600,
			'iat'   => time(),
		) );

		$segments = [
			self::base64url_encode( $header ),
			self::base64url_encode( $payload ),
		];

		openssl_sign(
			implode( '.', $segments ),
			$signature,
			$access_details['private_key'],
			'SHA256'
		);

		$segments[] = self::base64url_encode( $signature );

		$jwt = implode( '.', $segments );

		$response = wp_remote_post(
			$access_details['token_uri'],
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		$response_body = wp_remote_retrieve_body( $response );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// translators: Displays the response body from Google.
			return new \WP_Error( 'oauth-refresh', sprintf( esc_html__( 'Error fetching oauth2 token from Google: %s', 'google-analytics-bridge' ), wp_kses_post( '<pre>' . $response_body . '</pre>' ) ) );
		}

		$data = json_decode( $response_body );

		if ( empty( $data->access_token ) ) {
			// translators: Displays the response body from Google.
			return new \WP_Error( 'oauth-refresh', sprintf( esc_html__( 'Error fetching oauth2 token from Google: %s', 'google-analytics-bridge' ), wp_kses_post( '<pre>' . $response_body . '</pre>' ) ) );
		}

		update_option(
			self::$stored_credentials_option,
			array(
				'access_token'      => sanitize_text_field( $data->access_token ),
				'expire_time'       => time() + (int) $data->expires_in,
				'original_response' => wp_remote_retrieve_body( $response ),
			)
		);

		return true;
	}

	/**
	 * base64 encodes a string for use in a URL.
	 *
	 * @param string String to encode.
	 * @return string
	 */
	protected static function base64url_encode( $data ) {
		return str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $data ) );
	}

}
