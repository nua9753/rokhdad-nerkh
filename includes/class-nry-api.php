<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data layer: talks to BrsApi, normalises the response and caches it.
 * Nothing outside this class knows the shape of the remote JSON.
 */
class NRY_Api {

	/** Free feed: current prices only. */
	const ENDPOINT_FREE = 'https://Api.BrsApi.ir/Market/Gold_Currency.php';
	/** Paid feed: more symbols plus the history endpoints. */
	const ENDPOINT_PRO  = 'https://Api.BrsApi.ir/Market/Gold_Currency_Pro.php';

	public static function is_pro() {
		return 'pro' === nry_opt( 'plan', 'free' );
	}

	/** Fallback name keywords for symbols, used when the exact symbol is not in the feed. */
	const ALIASES = array(
		'IR_GOLD_18K'      => array( 'طلا', '18' ),
		'IR_GOLD_24K'      => array( 'طلا', '24' ),
		'IR_GOLD_MELTED'   => array( 'آب' ),
		'XAUUSD'           => array( 'انس', 'طلا' ),
		'IR_COIN_EMAMI'    => array( 'امامی' ),
		'IR_COIN_BAHAR'    => array( 'بهار' ),
		'IR_COIN_HALF'     => array( 'نیم' ),
		'IR_COIN_QUARTER'  => array( 'ربع' ),
		'IR_COIN_1G'       => array( 'گرمی' ),
		'USDT_IRT'         => array( 'تتر' ),
	);

	private static $cache = null;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'nry_refresh', array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function schedules( $schedules ) {
		$minutes                    = max( 1, (int) nry_opt( 'interval', 5 ) );
		$schedules['nry_interval'] = array(
			'interval' => $minutes * 60,
			'display'  => 'Rokhdad Nerkh refresh',
		);
		return $schedules;
	}

	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( 'nry_refresh' ) ) {
			wp_schedule_event( time() + 30, 'nry_interval', 'nry_refresh' );
		}
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( 'nry_refresh' );
		self::ensure_schedule();
	}

	/** One GET to the API. Returns decoded array or WP_Error. */
	public static function request( $args ) {
		$key = trim( (string) nry_opt( 'api_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'nry_no_key', 'کلید API وارد نشده است.' );
		}
		$query = array( 'key' => $key ) + $args;
		$url   = ( self::is_pro() ? self::ENDPOINT_PRO : self::ENDPOINT_FREE ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'headers'    => array( 'Accept' => 'application/json' ),
				// BrsApi rejects requests without a browser-like agent.
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'nry_bad_json', 'پاسخ API قابل خواندن نبود (کد ' . $code . ').' );
		}
		if ( 200 !== $code || ( isset( $data['successful'] ) && ! $data['successful'] ) ) {
			$msg = ! empty( $data['message_error'] ) ? (string) $data['message_error'] : 'خطای API (کد ' . $code . ').';
			return new WP_Error( 'nry_api_error', $msg );
		}
		return $data;
	}

	/**
	 * Walk any JSON shape and collect every object that looks like a price row.
	 * The nearest named parent key is kept as the row's section.
	 */
	private static function collect_prices( $node, $section, &$out ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['symbol'] ) && isset( $node['price'] ) && is_scalar( $node['symbol'] ) ) {
			$symbol = (string) $node['symbol'];
			$price  = (float) $node['price'];
			$pct    = isset( $node['change_percent'] ) ? (float) $node['change_percent'] : 0.0;
			if ( isset( $node['change_value'] ) ) {
				$change = (float) $node['change_value'];
			} else {
				// Crypto rows carry only a percentage; work the amount back from it.
				$change = ( 100 + $pct ) > 0 ? $price * $pct / ( 100 + $pct ) : 0.0;
			}
			$out[ $symbol ] = array(
				'symbol'  => $symbol,
				'name'    => isset( $node['name'] ) ? (string) $node['name'] : $symbol,
				'name_en' => isset( $node['name_en'] ) ? (string) $node['name_en'] : '',
				'price'   => $price,
				'change'  => $change,
				'pct'     => $pct,
				'unit'    => isset( $node['unit'] ) ? (string) $node['unit'] : '',
				'date'    => isset( $node['date'] ) ? (string) $node['date'] : '',
				'time'    => isset( $node['time'] ) ? (string) $node['time'] : '',
				'unix'    => isset( $node['time_unix'] ) ? (int) $node['time_unix'] : 0,
				'section' => (string) $section,
			);
			return;
		}
		foreach ( $node as $k => $child ) {
			self::collect_prices( $child, ( is_string( $k ) && ! is_numeric( $k ) ) ? $k : $section, $out );
		}
	}

	private static function collect_history( $node, &$out ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		$has_value = isset( $node['price'] ) || isset( $node['close'] );
		if ( $has_value && ( isset( $node['date'] ) || isset( $node['time'] ) || isset( $node['time_unix'] ) ) ) {
			$out[] = $node;
			return;
		}
		foreach ( $node as $child ) {
			self::collect_history( $child, $out );
		}
	}

	/** Fetch live prices and store them. Returns item count or WP_Error. */
	public static function refresh() {
		$data = self::request( array() );
		if ( is_wp_error( $data ) ) {
			update_option( 'nry_last_error', array( 'time' => time(), 'message' => $data->get_error_message() ), false );
			return $data;
		}
		$items = array();
		self::collect_prices( $data, '', $items );
		if ( empty( $items ) ) {
			$err = new WP_Error( 'nry_empty', 'پاسخ API هیچ قیمتی نداشت.' );
			update_option( 'nry_last_error', array( 'time' => time(), 'message' => $err->get_error_message() ), false );
			return $err;
		}
		self::$cache = array( 'fetched' => time(), 'items' => $items );
		update_option( 'nry_prices', self::$cache, false );
		delete_option( 'nry_last_error' );
		NRY_History::record( $items );
		return count( $items );
	}

	/** Cached snapshot: array( fetched, items ). Refreshes inline when badly stale. */
	public static function snapshot() {
		if ( null === self::$cache ) {
			$saved       = get_option( 'nry_prices', array() );
			self::$cache = is_array( $saved ) && isset( $saved['items'] ) ? $saved : array( 'fetched' => 0, 'items' => array() );
		}
		$max_age = max( 1, (int) nry_opt( 'interval', 5 ) ) * 120;
		if ( time() - (int) self::$cache['fetched'] > $max_age && '' !== trim( (string) nry_opt( 'api_key', '' ) ) && ! get_transient( 'nry_lock' ) ) {
			set_transient( 'nry_lock', 1, 60 );
			self::refresh();
		}
		return self::$cache;
	}

	public static function items() {
		$s = self::snapshot();
		return $s['items'];
	}

	/**
	 * Resolve a reference to price rows.
	 * "USD" is an exact symbol (with name-keyword fallback); "~دلار سنا" returns every row whose name has all words.
	 */
	public static function find( $ref ) {
		$ref   = trim( (string) $ref );
		$items = self::items();
		if ( '' === $ref || empty( $items ) ) {
			return array();
		}
		if ( 0 === strpos( $ref, '~' ) ) {
			return self::by_keywords( preg_split( '/\s+/', nry_norm( substr( $ref, 1 ) ), -1, PREG_SPLIT_NO_EMPTY ), 0 );
		}
		if ( 0 === strpos( $ref, '@' ) ) {
			$section = strtolower( substr( $ref, 1 ) );
			$found   = array();
			foreach ( $items as $item ) {
				if ( strtolower( $item['section'] ) === $section ) {
					$found[] = $item;
					if ( count( $found ) >= 30 ) {
						break;
					}
				}
			}
			return $found;
		}
		if ( isset( $items[ $ref ] ) ) {
			return array( $items[ $ref ] );
		}
		foreach ( $items as $symbol => $item ) {
			if ( 0 === strcasecmp( $symbol, $ref ) ) {
				return array( $item );
			}
		}
		$alias = strtoupper( $ref );
		if ( isset( self::ALIASES[ $alias ] ) ) {
			return self::by_keywords( self::ALIASES[ $alias ], 1 );
		}
		return array();
	}

	private static function by_keywords( $words, $limit ) {
		$found = array();
		if ( empty( $words ) ) {
			return $found;
		}
		foreach ( self::items() as $item ) {
			$name = nry_norm( $item['name'] );
			$ok   = true;
			foreach ( $words as $w ) {
				if ( false === strpos( $name, nry_norm( $w ) ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$found[] = $item;
				if ( $limit && count( $found ) >= $limit ) {
					break;
				}
			}
		}
		return $found;
	}

	/** Resolve a list of references to unique rows, in order. */
	public static function pick( $refs, $limit = 0 ) {
		$out = array();
		foreach ( (array) $refs as $ref ) {
			foreach ( self::find( $ref ) as $item ) {
				$out[ $item['symbol'] ] = $item;
				if ( $limit && count( $out ) >= $limit ) {
					return array_values( $out );
				}
			}
		}
		return array_values( $out );
	}

	public static function one( $ref ) {
		$found = self::find( $ref );
		return $found ? $found[0] : null;
	}

	private static function is_rial( $unit ) {
		return nry_opt( 'toman', 1 ) && false !== strpos( (string) $unit, 'ریال' );
	}

	/** A row in display units (Rial converted to Toman when enabled). */
	public static function display( $item ) {
		$div = self::is_rial( $item['unit'] ) ? 10 : 1;
		return array(
			'symbol' => $item['symbol'],
			'name'   => nry_fa( $item['name'] ),
			'price'  => $item['price'] / $div,
			'change' => $item['change'] / $div,
			'pct'    => $item['pct'],
			'unit'   => 10 === $div ? 'تومان' : $item['unit'],
			'date'   => $item['date'],
			'time'   => $item['time'],
		);
	}

	/**
	 * Chart points for a symbol. $range: d (24h), w, m, y (daily closes).
	 * Returns list of array( t => label, v => value ) in chronological order.
	 * The paid feed is asked first when enabled; otherwise (or when it has nothing)
	 * the points the plugin recorded itself are used.
	 */
	public static function history( $symbol, $range ) {
		$range = in_array( $range, array( 'd', 'w', 'm', 'y' ), true ) ? $range : 'd';
		if ( self::is_pro() ) {
			$out = self::remote_history( $symbol, $range );
			if ( count( $out ) >= 2 ) {
				return $out;
			}
		}
		$item = self::one( $symbol );
		$div  = ( $item && self::is_rial( $item['unit'] ) ) ? 10 : 1;
		$out  = array();
		foreach ( NRY_History::points( $item ? $item['symbol'] : $symbol, $range ) as $r ) {
			$out[] = array( 't' => nry_fa( $r['t'] ), 'v' => $r['v'] / $div );
		}
		return $out;
	}

	private static function remote_history( $symbol, $range ) {
		$mode  = 'd' === $range ? 1 : 2;
		$key   = 'nry_h' . $mode . '_' . md5( $symbol );
		$rows  = get_transient( $key );
		if ( false === $rows ) {
			$data = self::request( array( 'history' => $mode, 'symbol' => $symbol ) );
			if ( is_wp_error( $data ) ) {
				set_transient( $key, array(), 120 );
				return array();
			}
			$raw = array();
			self::collect_history( $data, $raw );
			$item = self::one( $symbol );
			$rows = array();
			foreach ( $raw as $r ) {
				$unit   = isset( $r['unit'] ) ? $r['unit'] : ( $item ? $item['unit'] : '' );
				$div    = self::is_rial( $unit ) ? 10 : 1;
				$value  = isset( $r['close'] ) ? $r['close'] : $r['price'];
				$date   = isset( $r['date'] ) ? (string) $r['date'] : '';
				$time   = isset( $r['time'] ) ? (string) $r['time'] : '';
				$rows[] = array(
					't' => 1 === $mode ? ( '' !== $time ? $time : $date ) : $date,
					'v' => (float) $value / $div,
					's' => isset( $r['time_unix'] ) ? (string) (int) $r['time_unix'] : $date . ' ' . $time,
				);
			}
			usort(
				$rows,
				function ( $a, $b ) {
					return strnatcmp( $a['s'], $b['s'] );
				}
			);
			if ( count( $rows ) > 400 ) {
				$rows = array_slice( $rows, -400 );
			}
			set_transient( $key, $rows, 1 === $mode ? 600 : 6 * HOUR_IN_SECONDS );
		}
		if ( 2 === $mode ) {
			$take = array( 'w' => 7, 'm' => 30, 'y' => 365 );
			$rows = array_slice( $rows, -$take[ $range ] );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 't' => nry_fa( $r['t'] ), 'v' => $r['v'] );
		}
		return $out;
	}

	/** Lowest and highest value of the last 24h, or null. */
	public static function day_range( $symbol ) {
		$points = self::history( $symbol, 'd' );
		if ( count( $points ) < 2 ) {
			return null;
		}
		$values = array_column( $points, 'v' );
		return array( 'low' => min( $values ), 'high' => max( $values ) );
	}
}
