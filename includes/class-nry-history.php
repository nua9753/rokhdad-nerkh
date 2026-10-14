<?php
defined( 'ABSPATH' ) || exit;

/**
 * Local price history.
 *
 * The free BrsApi feed only returns the current price, so the plugin keeps its own
 * record: one point per symbol every ten minutes (kept two days, for the 24h chart
 * and the day's low/high) and one closing value per symbol per day (kept about a year).
 */
class NRY_History {

	const DB_VER   = '1';
	const INTRADAY = 1;
	const DAILY    = 2;
	const BUCKET   = 600;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nry_history';
	}

	public static function maybe_install() {
		if ( self::DB_VER !== get_option( 'nry_db_ver' ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			symbol varchar(40) NOT NULL,
			kind tinyint(1) NOT NULL,
			t bigint(20) unsigned NOT NULL,
			p double NOT NULL,
			PRIMARY KEY  (symbol,kind,t)
			) {$charset};"
		);
		update_option( 'nry_db_ver', self::DB_VER, false );
	}

	/** "1405/07/16" to 14050716. Falls back to the site's own date when the row has none. */
	private static function day_key( $date ) {
		$digits = preg_replace( '/\D/', '', nry_norm( $date ) );
		return 8 === strlen( $digits ) ? (int) $digits : (int) wp_date( 'Ymd' );
	}

	/** Store the current snapshot. Called after every successful refresh. */
	public static function record( $items ) {
		global $wpdb;
		if ( empty( $items ) || self::DB_VER !== get_option( 'nry_db_ver' ) ) {
			return;
		}
		$table  = self::table();
		$now    = time();
		$bucket = $now - ( $now % self::BUCKET );
		$rows   = array();
		$newest = 0;
		foreach ( $items as $item ) {
			$price = (float) $item['price'];
			if ( $price <= 0 ) {
				continue;
			}
			$day    = self::day_key( $item['date'] );
			$newest = max( $newest, $day );
			$rows[] = $wpdb->prepare( '(%s,%d,%d,%s)', $item['symbol'], self::INTRADAY, $bucket, (string) $price );
			$rows[] = $wpdb->prepare( '(%s,%d,%d,%s)', $item['symbol'], self::DAILY, $day, (string) $price );
		}
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			// Same bucket or same day is overwritten, so the last value of the day is its close.
			$wpdb->query( "REPLACE INTO {$table} (symbol,kind,t,p) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! get_transient( 'nry_hist_prune' ) ) {
			set_transient( 'nry_hist_prune', 1, 86400 );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE kind = %d AND t < %d", self::INTRADAY, $now - 2 * 86400 ) ); // phpcs:ignore WordPress.DB
			if ( $newest ) {
				// Day keys are yyyymmdd, so 10100 back is thirteen months.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE kind = %d AND t < %d", self::DAILY, $newest - 10100 ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Recorded points for a symbol, oldest first: list of array( t => label, v => raw value ).
	 * $range: d (last 24h, ten-minute points) or w, m, y (daily closes).
	 */
	public static function points( $symbol, $range ) {
		global $wpdb;
		if ( self::DB_VER !== get_option( 'nry_db_ver' ) ) {
			return array();
		}
		$table = self::table();
		$out   = array();
		if ( 'd' === $range ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t, p FROM {$table} WHERE symbol = %s AND kind = %d AND t >= %d ORDER BY t ASC", $symbol, self::INTRADAY, time() - 86400 ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				$out[] = array( 't' => wp_date( 'H:i', (int) $r['t'] ), 'v' => (float) $r['p'] );
			}
			return $out;
		}
		$take = array( 'w' => 7, 'm' => 30, 'y' => 365 );
		$n    = isset( $take[ $range ] ) ? $take[ $range ] : 30;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t, p FROM {$table} WHERE symbol = %s AND kind = %d ORDER BY t DESC LIMIT %d", $symbol, self::DAILY, $n ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( array_reverse( (array) $rows ) as $r ) {
			$k     = str_pad( (string) (int) $r['t'], 8, '0', STR_PAD_LEFT );
			$out[] = array( 't' => substr( $k, 0, 4 ) . '/' . substr( $k, 4, 2 ) . '/' . substr( $k, 6, 2 ), 'v' => (float) $r['p'] );
		}
		return $out;
	}

	/** How many days of closing prices are stored for a symbol. */
	public static function days( $symbol ) {
		return count( self::points( $symbol, 'y' ) );
	}

	public static function uninstall() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		delete_option( 'nry_db_ver' );
	}
}
