<?php
defined( 'ABSPATH' ) || exit;

/** Read-only endpoints used by the front-end script for live numbers and charts. */
class NRY_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'rokhdad-nerkh/v1',
			'/prices',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'prices' ),
			)
		);
		register_rest_route(
			'rokhdad-nerkh/v1',
			'/history',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'history' ),
			)
		);
	}

	public static function prices( $request ) {
		$wanted = array_slice( NRY_Markets::split( (string) $request->get_param( 's' ) ), 0, 60 );
		$items  = NRY_Api::items();
		$out    = array();
		foreach ( $wanted as $symbol ) {
			if ( isset( $items[ $symbol ] ) ) {
				$d              = NRY_Api::display( $items[ $symbol ] );
				$out[ $symbol ] = array(
					'price'  => $d['price'],
					'change' => $d['change'],
					'pct'    => $d['pct'],
					'time'   => $d['time'],
					'date'   => $d['date'],
				);
			}
		}
		$snap = NRY_Api::snapshot();
		return rest_ensure_response( array( 'fetched' => (int) $snap['fetched'], 'items' => $out ) );
	}

	public static function history( $request ) {
		$symbol = (string) $request->get_param( 'symbol' );
		$items  = NRY_Api::items();
		// Only symbols present in the live feed are accepted, so the endpoint cannot be used to spend API quota.
		if ( ! isset( $items[ $symbol ] ) ) {
			return new WP_Error( 'nry_symbol', 'نماد ناشناخته.', array( 'status' => 404 ) );
		}
		$d      = NRY_Api::display( $items[ $symbol ] );
		$points = NRY_Api::history( $symbol, (string) $request->get_param( 'range' ) );
		$out    = array( 'unit' => $d['unit'], 'points' => $points );
		if ( count( $points ) < 2 ) {
			$out['note'] = 'نمودار این بازه از قیمت‌های ثبت‌شده در همین سایت ساخته می‌شود و هنوز داده‌ی کافی جمع نشده است.';
		}
		return rest_ensure_response( $out );
	}
}
