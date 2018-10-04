<?php

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


}
