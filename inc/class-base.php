<?php

namespace GAB;

/**
 * Base controller for the plugin.
 */
class Base {

	/**
	 * Option storing oAuth connection details.
	 *
	 * @var string
	 */
	protected static $stored_credentials_option  = 'gab_oauth2';

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
	 * Gets the stored oAuth connection details.
	 *
	 * @return array
	 */
	public static function get_google_auth_details() {
		return get_option( self::$stored_credentials_option, array() );
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

}
