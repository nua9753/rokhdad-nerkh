<?php
defined( 'ABSPATH' ) || exit;

/** All plugin settings, merged over defaults. */
function nry_settings() {
	$defaults = array(
		'api_key'        => '',
		'plan'           => 'free',
		'interval'       => 5,
		'toman'          => 1,
		'navy'           => '#0a2463',
		'gold'           => '#e3ae2b',
		'red'            => '#d8232a',
		'symbols'        => array(),
		'inject'         => array(),
		// SEO
		'seo_on'         => 1,
		'seo'            => array(),
		// automation
		'autolink'       => 0,
		'autolink_words' => array(),
		'daily_on'       => 0,
		'daily_hour'     => 11,
		'daily_cat'      => 0,
		'daily_markets'  => array( 'gold' ),
		'daily_title'    => '{title} {weekday} {date_long} / {name} چند شد؟',
		'image_on'       => 1,
		'daily_titles'   => '',
		'daily_publish'  => 'draft',
		'daily_publish_hour' => 12,
		'daily_analysis' => 1,
	);
	$saved    = get_option( 'nry_settings', array() );
	return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
}

function nry_opt( $key, $default = null ) {
	$s = nry_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : $default;
}

/** Latin and Arabic digits to Persian digits. */
function nry_fa( $text ) {
	return strtr(
		(string) $text,
		array(
			'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
			'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
			'٠' => '۰', '١' => '۱', '٢' => '۲', '٣' => '۳', '٤' => '۴',
			'٥' => '۵', '٦' => '۶', '٧' => '۷', '٨' => '۸', '٩' => '۹',
		)
	);
}

/** Text normalised for keyword matching (digits, Arabic letters, half-space). */
function nry_norm( $text ) {
	$text = strtr(
		(string) $text,
		array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			'ي' => 'ی', 'ك' => 'ک', "\xE2\x80\x8C" => ' ',
		)
	);
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
}

/** Format a number with Persian digits and separators. */
function nry_num( $n, $decimals = null ) {
	if ( null === $n || '' === $n || ! is_numeric( $n ) ) {
		return '—';
	}
	$n = (float) $n;
	$auto = null === $decimals;
	if ( $auto ) {
		$decimals = ( abs( $n ) < 100000 && floor( $n ) != $n ) ? 2 : 0;
	}
	$a = abs( $n );
	if ( $auto && $a > 0 && $a < 1 ) {
		// Small coins (0.0000148 dollars) need more places than two; keep four significant digits.
		$s = number_format( $n, min( 10, 3 - (int) floor( log10( $a ) ) ), '.', '' );
		$s = preg_replace( '/0+$/', '', $s );
		$p = strpos( $s, '.' );
		$s = str_pad( $s, $p + 3, '0' );
		return nry_fa( str_replace( '.', '٫', $s ) );
	}
	return nry_fa( number_format( $n, $decimals, '٫', '٬' ) );
}

/** Signed percentage like +۰٫۸۲٪ (true minus sign for negatives). */
function nry_pct( $p ) {
	if ( ! is_numeric( $p ) ) {
		return '—';
	}
	$p    = (float) $p;
	$sign = $p > 0 ? '+' : ( $p < 0 ? '−' : '' );
	return $sign . nry_fa( number_format( abs( $p ), 2, '٫', '٬' ) ) . '٪';
}

function nry_dir_class( $p ) {
	$p = (float) $p;
	return $p > 0 ? 'nry-up' : ( $p < 0 ? 'nry-down' : 'nry-flat' );
}

/** "1405/07/16" (as the API returns it) to "۱۶ مهر ۱۴۰۵". Falls back to the input. */
function nry_date_long( $jalali ) {
	$months = array( 1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
	$parts  = preg_split( '/[\/\-]/', nry_norm( $jalali ) );
	if ( 3 !== count( $parts ) || ! isset( $months[ (int) $parts[1] ] ) ) {
		return nry_fa( $jalali );
	}
	return nry_fa( (int) $parts[2] . ' ' . $months[ (int) $parts[1] ] . ' ' . (int) $parts[0] );
}

/** Persian weekday name for a unix time, in the site's timezone. */
function nry_weekday( $unix = null ) {
	$names = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );
	$w     = (int) wp_date( 'w', $unix ? (int) $unix : time() );
	return $names[ $w ];
}

/** Replace {tokens} in a template. */
function nry_fill( $template, $vars ) {
	$map = array();
	foreach ( $vars as $k => $v ) {
		$map[ '{' . $k . '}' ] = $v;
	}
	return trim( preg_replace( '/\s+/u', ' ', strtr( (string) $template, $map ) ) );
}
