<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data layer: talks to the nerkh.io web service, normalises the response and caches it.
 * Nothing outside this class knows the shape of the remote JSON.
 *
 * One request (GET /v1/prices/json/all) returns every item of the gold, currency and crypto
 * sections, so each refresh costs exactly one request of the plan's quota:
 *   { "data": { "status": 200, "timestamp": 1779657560, "date": "1405-03-04 00:49:20",
 *               "gold": { "GOLD18K": { "current": "18454300", "min": {…}, "max": {…}, "update": "…" }, … },
 *               "currency": { "USD": { "current": "169910", … }, … },
 *               "crypto": { "BTC": { "current": "…" }, … } } }
 * Prices are in Toman (the gold ounce in dollars).
 */
class NRY_Api {

	const BASE     = 'https://api.nerkh.io/v1';
	const ENDPOINT = '/prices/json/all';

	/**
	 * nerkh.io plans: requests per hour (0 = unlimited) and per day.
	 * The free plan allows 6 an hour and 150 a day, so prices refresh at most every 10 minutes.
	 */
	const PLANS = array(
		'free'   => array( 'label' => 'رایگان', 'hour' => 6, 'day' => 150 ),
		'bronze' => array( 'label' => 'برنز', 'hour' => 60, 'day' => 500 ),
		'silver' => array( 'label' => 'نقره', 'hour' => 0, 'day' => 2000 ),
		'gold'   => array( 'label' => 'طلا', 'hour' => 0, 'day' => 3500 ),
	);

	/** Persian names for nerkh.io codes (the feed itself carries only codes). */
	const NAMES = array(
		'GOLD18K'     => 'طلای ۱۸ عیار',
		'GOLD24K'     => 'طلای ۲۴ عیار',
		'MAZANEH'     => 'مثقال طلا',
		'OUNCE'       => 'انس طلا',
		'SEKE_EMAMI'  => 'سکه امامی',
		'SEKE_BAHAR'  => 'سکه بهار آزادی',
		'SEKE_NIM'    => 'نیم‌سکه',
		'SEKE_ROB'    => 'ربع‌سکه',
		'SEKE_1G'     => 'سکه یک‌گرمی',
		'SEKE_PRS100' => 'سکه پارسیان ۱۰۰ سوت',
		'SEKE_PRS200' => 'سکه پارسیان ۲۰۰ سوت',
		'SEKE_PRS300' => 'سکه پارسیان ۳۰۰ سوت',
		'SEKE_PRS400' => 'سکه پارسیان ۴۰۰ سوت',
		'SEKE_PRS500' => 'سکه پارسیان ۵۰۰ سوت',
		'SEKE_PRS600' => 'سکه پارسیان ۶۰۰ سوت',
		'SEKE_PRS700' => 'سکه پارسیان ۷۰۰ سوت',
		'USD'         => 'دلار آمریکا',
		'EUR'         => 'یورو',
		'GBP'         => 'پوند انگلیس',
		'TRY'         => 'لیر ترکیه',
		'AED'         => 'درهم امارات',
		'CNY'         => 'یوان چین',
		'CAD'         => 'دلار کانادا',
		'AUD'         => 'دلار استرالیا',
		'CHF'         => 'فرانک سوئیس',
		'JPY'         => 'ین ژاپن',
		'IQD'         => 'دینار عراق',
		'KWD'         => 'دینار کویت',
		'SAR'         => 'ریال عربستان',
		'QAR'         => 'ریال قطر',
		'OMR'         => 'ریال عمان',
		'RUB'         => 'روبل روسیه',
		'AFN'         => 'افغانی',
		'BTC'         => 'بیت‌کوین',
		'ETH'         => 'اتریوم',
		'USDT'        => 'تتر',
		'XRP'         => 'ریپل',
		'BNB'         => 'بایننس‌کوین',
		'SOL'         => 'سولانا',
		'DOGE'        => 'دوج‌کوین',
		'TRX'         => 'ترون',
		'ADA'         => 'کاردانو',
		'TON'         => 'تون‌کوین',
		'LTC'         => 'لایت‌کوین',
		'SHIB'        => 'شیبا اینو',
	);

	/**
	 * Symbols used before the move to nerkh.io (BrsApi), so saved markets, rules and shortcodes keep working.
	 */
	const OLD_SYMBOLS = array(
		'IR_GOLD_18K'     => 'GOLD18K',
		'IR_GOLD_24K'     => 'GOLD24K',
		'IR_COIN_EMAMI'   => 'SEKE_EMAMI',
		'IR_COIN_BAHAR'   => 'SEKE_BAHAR',
		'IR_COIN_HALF'    => 'SEKE_NIM',
		'IR_COIN_QUARTER' => 'SEKE_ROB',
		'IR_COIN_1G'      => 'SEKE_1G',
		'XAUUSD'          => 'OUNCE',
		'USDT_IRT'        => 'USDT',
		'MESGHAL'         => 'MAZANEH',
	);

	/** Old section names. */
	const OLD_SECTIONS = array( 'cryptocurrency' => 'crypto' );

	/** Name keywords tried when a symbol is not in the feed. */
	const ALIASES = array(
		'IR_GOLD_MELTED' => array( 'آب' ),
		'GOLD_MELTED'    => array( 'آب' ),
	);

	private static $cache = null;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'nry_refresh', array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/* ---------- plan and quota ---------- */

	public static function plan() {
		$p = (string) nry_opt( 'plan', 'free' );
		return isset( self::PLANS[ $p ] ) ? $p : 'free';
	}

	/** Shortest refresh interval (minutes) the plan's quota allows, keeping a margin for manual tests. */
	public static function min_interval() {
		$p   = self::PLANS[ self::plan() ];
		$day = (int) ceil( 1440 / max( 1, $p['day'] - 10 ) );
		$hr  = $p['hour'] ? (int) ceil( 60 / max( 1, $p['hour'] - 1 ) ) : 1;
		return max( 1, $day, $hr );
	}

	public static function interval() {
		return min( 60, max( self::min_interval(), (int) nry_opt( 'interval', 15 ) ) );
	}

	/** Requests made in the last hour and today, from the plugin's own log. */
	public static function usage() {
		$log  = get_option( 'nry_calls', array() );
		$log  = is_array( $log ) ? $log : array();
		$now  = time();
		$day  = (int) wp_date( 'Ymd' );
		$hour = 0;
		$today = 0;
		foreach ( $log as $t ) {
			if ( $t > $now - HOUR_IN_SECONDS ) {
				$hour++;
			}
			if ( (int) wp_date( 'Ymd', $t ) === $day ) {
				$today++;
			}
		}
		return array( 'hour' => $hour, 'day' => $today, 'log' => $log );
	}

	/** WP_Error when one more request would go over the plan's quota. */
	private static function over_quota() {
		$p = self::PLANS[ self::plan() ];
		$u = self::usage();
		if ( $p['hour'] && $u['hour'] >= $p['hour'] ) {
			$oldest = min( array_filter( $u['log'], function ( $t ) { return $t > time() - HOUR_IN_SECONDS; } ) );
			return new WP_Error( 'nry_quota', 'سقف ' . nry_fa( $p['hour'] ) . ' درخواست در ساعت پلن ' . $p['label'] . ' پر شده است؛ درخواست بعدی از ساعت ' . nry_fa( wp_date( 'H:i', $oldest + HOUR_IN_SECONDS ) ) . ' ممکن است. قیمت‌های قبلی روی سایت می‌مانند.' );
		}
		if ( $u['day'] >= $p['day'] ) {
			return new WP_Error( 'nry_quota', 'سقف ' . nry_fa( $p['day'] ) . ' درخواست روزانه‌ی پلن ' . $p['label'] . ' پر شده است؛ تا فردا قیمت‌های قبلی روی سایت می‌مانند.' );
		}
		return null;
	}

	private static function log_call() {
		$log   = self::usage()['log'];
		$log[] = time();
		// Only the last 26 hours are needed.
		$log = array_values( array_filter( $log, function ( $t ) { return $t > time() - 26 * HOUR_IN_SECONDS; } ) );
		update_option( 'nry_calls', $log, false );
	}

	/* ---------- schedule ---------- */

	public static function schedules( $schedules ) {
		$schedules['nry_interval'] = array(
			'interval' => self::interval() * 60,
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

	/* ---------- request ---------- */

	/** One GET to the web service. Returns decoded array or WP_Error. */
	public static function request( $path = self::ENDPOINT ) {
		$key = trim( (string) nry_opt( 'api_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'nry_no_key', 'توکن وب‌سرویس nerkh.io وارد نشده است.' );
		}
		$quota = self::over_quota();
		if ( $quota ) {
			return $quota;
		}
		self::log_call();
		$url = self::BASE . $path . '?' . http_build_query( array( 'x-api-key' => $key ), '', '&', PHP_QUERY_RFC3986 );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					// Both documented methods: the token as x-api-key and as a Bearer token.
					'Authorization' => 'Bearer ' . $key,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'nry_http', 'اتصال به api.nerkh.io برقرار نشد: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		$msg  = '';
		if ( is_array( $data ) ) {
			foreach ( array( $data, isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() ) as $level ) {
				foreach ( array( 'message', 'error', 'detail' ) as $k ) {
					if ( '' === $msg && ! empty( $level[ $k ] ) && is_string( $level[ $k ] ) && 'Success' !== $level[ $k ] ) {
						$msg = $level[ $k ];
					}
				}
			}
		}
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'nry_auth', 'توکن nerkh.io پذیرفته نشد (کد ' . nry_fa( $code ) . ')' . ( $msg ? ': ' . $msg : '' ) . '. توکن را از پنل nerkh.io دوباره کپی کنید و مطمئن شوید پکیج آن فعال است.' );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'nry_rate', 'nerkh.io سقف درخواست را پر اعلام کرد (کد ۴۲۹)' . ( $msg ? ': ' . $msg : '' ) . '. قیمت‌های قبلی روی سایت می‌مانند.' );
		}
		if ( 200 !== $code || ! is_array( $data ) ) {
			return new WP_Error( 'nry_api_error', 'پاسخ nerkh.io قابل استفاده نبود (کد ' . nry_fa( $code ) . ')' . ( $msg ? ': ' . $msg : '' ) . '.' );
		}
		return $data;
	}

	/* ---------- parsing ---------- */

	/**
	 * A number from the feed, whatever its format: 6214700, "6214700", "6,214,700", "۶٬۲۱۴٬۷۰۰",
	 * "103959.5", "۳٫۵", or an object holding the value. Returns null when there is no number.
	 */
	public static function to_number( $v ) {
		if ( is_array( $v ) ) {
			foreach ( array( 'current', 'value', 'price', 'p', 'amount', 'close' ) as $k ) {
				if ( isset( $v[ $k ] ) ) {
					return self::to_number( $v[ $k ] );
				}
			}
			return null;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$s = nry_norm( (string) $v );
		$s = str_replace( array( '٫', '/', '−', '–' ), array( '.', '.', '-', '-' ), $s );
		$s = preg_replace( '/[^0-9.\-eE]/u', '', $s );
		if ( substr_count( $s, '.' ) > 1 ) {
			$s = str_replace( '.', '', $s );
		}
		return is_numeric( $s ) ? (float) $s : null;
	}

	/** A value from a min/max block: the 24-hour one when the feed has it. */
	private static function window( $block ) {
		if ( ! is_array( $block ) ) {
			return null;
		}
		foreach ( array( '24hour', '1day', 'day', '24h' ) as $k ) {
			if ( isset( $block[ $k ] ) ) {
				return self::to_number( $block[ $k ] );
			}
		}
		return null;
	}

	/** "1405-03-04 00:49:20" → array( "1405/03/04", "00:49" ). */
	private static function split_date( $s ) {
		$s = trim( nry_norm( (string) $s ) );
		if ( preg_match( '/^(\d{4})[\-\/](\d{1,2})[\-\/](\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?/', $s, $m ) ) {
			return array( sprintf( '%04d/%02d/%02d', $m[1], $m[2], $m[3] ), isset( $m[4] ) ? sprintf( '%02d:%02d', $m[4], $m[5] ) : '' );
		}
		return array( '', '' );
	}

	/** Rows of one section, from `gold: { CODE: {…} }` or a list of objects with a code field. */
	private static function section_rows( $node ) {
		$rows = array();
		if ( ! is_array( $node ) ) {
			return $rows;
		}
		foreach ( $node as $k => $info ) {
			if ( ! is_array( $info ) ) {
				continue;
			}
			$code = is_string( $k ) ? $k : '';
			foreach ( array( 'symbol', 'code', 'key', 'slug' ) as $f ) {
				if ( '' === $code && ! empty( $info[ $f ] ) && is_string( $info[ $f ] ) ) {
					$code = $info[ $f ];
				}
			}
			if ( '' !== $code ) {
				$rows[ strtoupper( $code ) ] = $info;
			}
		}
		return $rows;
	}

	/** Normalised items from a decoded response. */
	public static function parse( $raw ) {
		$data = isset( $raw['data'] ) && is_array( $raw['data'] ) ? $raw['data'] : $raw;
		list( $fdate, $ftime ) = self::split_date( isset( $data['date'] ) ? $data['date'] : '' );
		$unix  = isset( $data['timestamp'] ) ? (int) $data['timestamp'] : time();
		$items = array();

		$sections = array();
		foreach ( array( 'gold', 'currency', 'crypto' ) as $s ) {
			if ( isset( $data[ $s ] ) && is_array( $data[ $s ] ) ) {
				$sections[ $s ] = $data[ $s ];
			}
		}
		// A single-section endpoint answers with data.prices.
		if ( ! $sections && isset( $data['prices'] ) && is_array( $data['prices'] ) ) {
			$sections['prices'] = $data['prices'];
		}

		foreach ( $sections as $section => $node ) {
			foreach ( self::section_rows( $node ) as $code => $info ) {
				$price = self::to_number( isset( $info['current'] ) ? $info['current'] : ( isset( $info['price'] ) ? $info['price'] : null ) );
				if ( null === $price ) {
					continue;
				}
				list( $d, $t ) = self::split_date( isset( $info['update'] ) ? $info['update'] : '' );
				$pct = null;
				foreach ( array( 'change_percent', 'changePercent', 'percent', 'change_24h', 'change' ) as $f ) {
					if ( null === $pct && isset( $info[ $f ] ) && ! is_array( $info[ $f ] ) ) {
						$pct = self::to_number( $info[ $f ] );
					}
				}
				$name = isset( self::NAMES[ $code ] ) ? self::NAMES[ $code ] : '';
				foreach ( array( 'name_fa', 'title', 'name', 'fa' ) as $f ) {
					if ( '' === $name && ! empty( $info[ $f ] ) && is_string( $info[ $f ] ) ) {
						$name = $info[ $f ];
					}
				}
				$items[ $code ] = array(
					'symbol'  => $code,
					'name'    => '' !== $name ? $name : $code,
					'name_en' => $code,
					'price'   => $price,
					'pct'     => $pct,
					'change'  => null,
					'low'     => isset( $info['min'] ) ? self::window( $info['min'] ) : null,
					'high'    => isset( $info['max'] ) ? self::window( $info['max'] ) : null,
					'unit'    => 'OUNCE' === $code ? 'دلار' : 'تومان',
					'date'    => '' !== $fdate ? $fdate : $d,
					'time'    => '' !== $t ? $t : $ftime,
					'unix'    => $unix,
					'section' => 'prices' === $section ? self::guess_section( $code ) : $section,
					'raw'     => is_scalar( isset( $info['current'] ) ? $info['current'] : '' ) ? substr( (string) ( isset( $info['current'] ) ? $info['current'] : '' ), 0, 40 ) : '',
				);
			}
		}

		// Crypto: Toman or dollars. Bitcoin above a million can only be Toman.
		if ( isset( $items['BTC'] ) && $items['BTC']['price'] < 1000000 ) {
			foreach ( $items as $code => $it ) {
				if ( 'crypto' === $it['section'] ) {
					$items[ $code ]['unit'] = 'دلار';
				}
			}
		}
		return $items;
	}

	private static function guess_section( $code ) {
		if ( 0 === strpos( $code, 'GOLD' ) || 0 === strpos( $code, 'SEKE' ) || in_array( $code, array( 'MAZANEH', 'OUNCE' ), true ) ) {
			return 'gold';
		}
		return in_array( $code, array( 'BTC', 'ETH', 'USDT', 'XRP', 'BNB', 'SOL', 'DOGE', 'TRX', 'ADA', 'TON', 'LTC', 'SHIB' ), true ) ? 'crypto' : 'currency';
	}

	/** Daily change from the plugin's own record when the feed does not send one. */
	private static function fill_change( &$items ) {
		foreach ( $items as $code => $it ) {
			if ( null === $it['pct'] ) {
				$prev = NRY_History::prev_close( $code, $it['date'] );
				$items[ $code ]['pct'] = $prev > 0 ? ( $it['price'] - $prev ) / $prev * 100 : 0.0;
			}
			$p = (float) $items[ $code ]['pct'];
			$items[ $code ]['pct']    = $p;
			$items[ $code ]['change'] = ( 100 + $p ) > 0 ? $it['price'] * $p / ( 100 + $p ) : 0.0;
		}
	}

	/** Fetch live prices and store them. Returns item count or WP_Error. */
	public static function refresh() {
		$data = self::request();
		if ( is_wp_error( $data ) ) {
			update_option( 'nry_last_error', array( 'time' => time(), 'message' => $data->get_error_message() ), false );
			return $data;
		}
		$items = self::parse( $data );
		$good  = 0;
		foreach ( $items as $it ) {
			if ( $it['price'] > 0 ) {
				$good++;
			}
		}
		if ( ! $items || ! $good ) {
			$first = $items ? reset( $items ) : null;
			$err   = new WP_Error( 'nry_empty', $items ? 'قیمت‌های پاسخ nerkh.io صفر یا ناخوانا بود و ذخیره نشد؛ آخرین قیمت‌های سالم سر جایشان ماندند. نمونه برای ' . $first['symbol'] . ': «' . $first['raw'] . '»' : 'پاسخ nerkh.io هیچ قیمتی نداشت. ساختار پاسخ: ' . substr( wp_json_encode( array_keys( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data ) ), 0, 200 ) );
			update_option( 'nry_last_error', array( 'time' => time(), 'message' => $err->get_error_message() ), false );
			return $err;
		}
		self::fill_change( $items );
		self::$cache = array( 'fetched' => time(), 'items' => $items, 'source' => 'nerkh' );
		update_option( 'nry_prices', self::$cache, false );
		delete_option( 'nry_last_error' );
		NRY_History::record( $items );
		return count( $items );
	}

	/** Cached snapshot: array( fetched, items ). Refreshes inline when badly stale and the quota allows. */
	public static function snapshot() {
		if ( null === self::$cache ) {
			$saved = get_option( 'nry_prices', array() );
			// Prices saved from the old source are dropped: their symbols no longer exist.
			self::$cache = is_array( $saved ) && isset( $saved['items'] ) && isset( $saved['source'] ) && 'nerkh' === $saved['source'] ? $saved : array( 'fetched' => 0, 'items' => array() );
		}
		$max_age = self::interval() * 120;
		if ( time() - (int) self::$cache['fetched'] > $max_age && '' !== trim( (string) nry_opt( 'api_key', '' ) ) && ! get_transient( 'nry_lock' ) ) {
			set_transient( 'nry_lock', 1, 5 * MINUTE_IN_SECONDS );
			self::refresh();
		}
		return self::$cache;
	}

	public static function items() {
		$s = self::snapshot();
		return $s['items'];
	}

	/* ---------- lookup ---------- */

	/**
	 * Resolve a reference to price rows.
	 * "USD" is an exact symbol; "~مثقال" returns every row whose name has all the words;
	 * "@gold", "@currency", "@crypto" every row of that section.
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
			$section = isset( self::OLD_SECTIONS[ $section ] ) ? self::OLD_SECTIONS[ $section ] : $section;
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
		$code = strtoupper( $ref );
		if ( isset( self::OLD_SYMBOLS[ $code ] ) ) {
			$code = self::OLD_SYMBOLS[ $code ];
		}
		if ( isset( $items[ $code ] ) ) {
			return array( $items[ $code ] );
		}
		if ( isset( self::ALIASES[ $code ] ) ) {
			return self::by_keywords( self::ALIASES[ $code ], 1 );
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

	/** A row ready for display. nerkh.io already sends Toman. */
	public static function display( $item ) {
		return array(
			'symbol' => $item['symbol'],
			'name'   => nry_fa( $item['name'] ),
			'price'  => (float) $item['price'],
			'change' => (float) $item['change'],
			'pct'    => (float) $item['pct'],
			'unit'   => $item['unit'],
			'date'   => $item['date'],
			'time'   => $item['time'],
		);
	}

	/**
	 * Chart points for a symbol from the plugin's own record. $range: d (24h), w, m, y (daily closes).
	 * Returns list of array( t => label, v => value ) in chronological order.
	 */
	public static function history( $symbol, $range ) {
		$range = in_array( $range, array( 'd', 'w', 'm', 'y' ), true ) ? $range : 'd';
		$item  = self::one( $symbol );
		$out   = array();
		foreach ( NRY_History::points( $item ? $item['symbol'] : $symbol, $range ) as $r ) {
			$out[] = array( 't' => nry_fa( $r['t'] ), 'v' => $r['v'] );
		}
		return $out;
	}

	/** Lowest and highest value of the last 24h: from the feed when it sends them, else from the record. */
	public static function day_range( $symbol ) {
		$item = self::one( $symbol );
		if ( $item && isset( $item['low'], $item['high'] ) && $item['low'] > 0 && $item['high'] >= $item['low'] ) {
			return array( 'low' => min( $item['low'], $item['price'] ), 'high' => max( $item['high'], $item['price'] ) );
		}
		$points = self::history( $symbol, 'd' );
		if ( count( $points ) < 2 ) {
			return null;
		}
		$values = array_column( $points, 'v' );
		return array( 'low' => min( $values ), 'high' => max( $values ) );
	}
}
