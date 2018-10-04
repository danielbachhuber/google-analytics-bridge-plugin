<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Google_Analytics_Bridge
 */

$gab_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $gab_tests_dir ) {
	$gab_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $gab_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $gab_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // WPCS: XSS ok.
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $gab_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function gab_manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/google-analytics-bridge.php';
}
tests_add_filter( 'muplugins_loaded', 'gab_manually_load_plugin' );

// Start up the WP testing environment.
require $gab_tests_dir . '/includes/bootstrap.php';
