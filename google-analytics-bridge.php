<?php
/**
 * Plugin Name:     Google Analytics Bridge
 * Plugin URI:      https://handbuilt.co
 * Description:     API bridge between WordPress and Google Analytics
 * Author:          Daniel Bachhuber
 * Author URI:      https://handbuilt.co
 * Text Domain:     google-analytics-bridge
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Google_Analytics_Bridge
 */

// Your code starts here.
add_action( 'init', array( 'GAB\Admin', 'handle_google_auth_callback' ) );
add_action( 'init', array( 'GAB\Admin', 'handle_google_disconnect_callback' ) );
add_action( 'admin_menu', array( 'GAB\Admin', 'action_admin_menu' ) );

/**
 * Register the class autoloader
 */
spl_autoload_register(
	function( $class ) {
			$class = ltrim( $class, '\\' );
		if ( 0 !== stripos( $class, 'GAB\\' ) ) {
			return;
		}

		$parts = explode( '\\', $class );
		array_shift( $parts ); // Don't need "GAB".
		$last    = array_pop( $parts ); // File should be 'class-[...].php'.
		$last    = 'class-' . $last . '.php';
		$parts[] = $last;
		$file    = dirname( __FILE__ ) . '/inc/' . str_replace( '_', '-', strtolower( implode( '/', $parts ) ) );
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
