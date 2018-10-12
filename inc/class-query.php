<?php
/**
 * Query controller.
 *
 * @package google-analytics-bridge
 */

namespace GAB;

use WP_Error;

/**
 * Query controller.
 */
class Query extends Base {

	/**
	 * Gets the metrics based
	 *
	 * @param string|array $metrics Metrics to query for (e.g. 'ga:pageviews').
	 * @param array        $args    Additional arguments to modify the query.
	 * @return array Metrics for period, with ga:pagePath as unique indicator.
	 */
	public static function get_page_path_metrics( $metrics, $args = array() ) {
		$was_string = false;
		if ( is_string( $metrics ) ) {
			$metrics    = array( $metrics );
			$was_string = true;
		}

		$defaults = array(
			'date_range' => array(),
			'total'      => 25,
		);
		$args     = array_merge( $defaults, $args );

		$date_range = array_merge(
			array(
				'startDate' => date( 'Y-m-d', strtotime( '7 days ago' ) ),
				'endDate'   => date( 'Y-m-d' ), // today.
			),
			$args['date_range']
		);
		// GA API v4 supports multiple date ranges, but this API only supports one.
		$date_range = array( $date_range );

		$request_body = array(
			'reportRequests' => array(),
		);

		$metric_objects  = array();
		$orderby_objects = array();
		foreach ( $metrics as $metric ) {
			$metric_objects[]  = array(
				'expression' => $metric,
			);
			$orderby_objects[] = array(
				'fieldName' => $metric,
				'sortOrder' => 'DESCENDING',
			);
		}

		$request_body['reportRequests'][] = array(
			'dateRanges' => $date_ranges,
			'dimensions' => array(
				array(
					'name' => 'ga:pagePath',
				),
			),
			'orderBys'   => $orderby_objects,
			'metrics'    => $metric_objects,
			'pageSize'   => $args['total'],
		);

		$response = self::get_google_analytics_v4_data( $request_body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$structured_response = array();
		foreach ( $response['reports'][0]['data']['rows'] as $row ) {
			$values = array();
			foreach ( $row['metrics'] as $metric ) {
				$values[] = $metric['values'][0];
			}
			if ( $was_string ) {
				$values = $values[0];
			}
			$structured_response[ $row['dimensions'][0] ] = $values;
		}
		return $structured_response;
	}

	/**
	 * Makes a request to the Google Analytics v3 API.
	 *
	 * @param array $request_args Arguments in the API request.
	 * @return array|WP_Error
	 */
	public static function get_google_analytics_realtime_data( $request_args ) {
		if ( empty( $request_args ) ) {
			return new WP_Error( 'gab_missing_request_args', __( '$request_args is missing any entries.', 'google-analytics-bridge' ) );
		}
		$request_args['ids'] = self::get_profile_id();

		$auth_details = self::get_current_google_token();
		if ( empty( $auth_details ) ) {
			return array();
		}
		$request_url = add_query_arg( $request_args, 'https://www.googleapis.com/analytics/v3/data/realtime' );
		$response    = wp_remote_get(
			$request_url,
			array(
				'timeout' => 3,
				'headers' => array(
					'Authorization' => 'Bearer ' . $auth_details['access_token'],
					'Content-Type'  => 'application/json; charset=UTF-8',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		return $result;
	}

	/**
	 * Makes a request to the Google Analytics v4 API.
	 *
	 * @param array $request_body Body of the request to be sent.
	 * @return array|WP_Error
	 */
	public static function get_google_analytics_v4_data( $request_body ) {
		if ( empty( $request_body['reportRequests'] ) ) {
			return new WP_Error( 'gab_missing_report_requests', __( '$request_body is missing a \'reportRequests\' key.', 'google-analytics-bridge' ) );
		}
		foreach ( $request_body['reportRequests'] as &$report ) {
			if ( ! isset( $report['viewId'] ) ) {
				$report['viewId'] = self::get_profile_id();
			}
		}
		$auth_details = self::get_current_google_token();
		if ( empty( $auth_details ) ) {
			return array();
		}
		$request = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $auth_details['access_token'],
				'Content-Type'  => 'application/json; charset=UTF-8',
			),
			'body'    => wp_json_encode( $request_body ),
			// GA API can be slow. Allow longer timeouts when priming cache on cron.
			// @codingStandardsIgnoreLine
			'timeout' => defined( 'DOING_CRON' ) && DOING_CRON ? 30 : 3,
		);
		$response = wp_remote_post( 'https://analyticsreporting.googleapis.com/v4/reports:batchGet', $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		return $result;
	}

	/**
	 * Makes a cached remote request with a failback mechanism.
	 *
	 * @param callback $callback        Remote request function to call.
	 * @param mixed    $callback_args   Arguments to pass to the callback.
	 * @param integer  $cache_expiry    Cache expiry time in minutes.
	 * @param integer  $failback_expiry Failback expiry time in minutes.
	 * @return mixed
	 */
	protected static function make_remote_request_with_cache_and_failback( $callback, $callback_args, $cache_expiry = 15, $failback_expiry = 60 ) {
		$primary_cache_key  = 'bgra_cached_request_' . md5( wp_json_encode( $callback_args ) );
		$failback_cache_key = $primary_cache_key . '_failback';
		$cache_value        = wp_cache_get( $primary_cache_key );
		// If the primary cache doesn't exist, then $cache_value===false.
		// However, if the most recent API request failed, then $cache_value===''.
		// IF the most recent API request failed, then the cache will expire much sooner.
		if ( false !== $cache_value ) {
			if ( '' !== $cache_value ) {
				return $cache_value;
			}
			return wp_cache_get( $failback_cache_key );
		}

		$response_body = $callback( $callback_args );
		if ( ! is_wp_error( $response_body ) ) {
			$cache_expiry = $cache_expiry * MINUTE_IN_SECONDS;
			wp_cache_set( $failback_cache_key, $response_body, '', $failback_expiry * HOUR_IN_SECONDS );
		} else {
			// Empty response body will cause failback value to be used.
			$response_body = '';
			$cache_expiry  = 3 * MINUTE_IN_SECONDS;
		}
		wp_cache_set( $primary_cache_key, $response_body, '', $cache_expiry );
		// Use the failback value if the response was errored.
		if ( '' === $response_body ) {
			$response_body = wp_cache_get( $failback_cache_key );
		}
		return $response_body;
	}


}
