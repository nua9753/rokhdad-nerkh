<?php
defined( 'ABSPATH' ) || exit;

/**
 * SEO layer.
 * - Title and description templates for landing pages, applied through Yoast SEO or Rank Math when one is active,
 *   and directly when neither is.
 * - Structured data collected while modules render (FAQ, breadcrumb), printed once in the footer.
 * - dateModified and og:image kept in step with the data.
 */
class NRY_Seo {

	private static $faq     = array();
	private static $crumbs  = array();
	private static $context = false;

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'output' ), 20 );

		// Yoast SEO.
		foreach ( array( 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'title' ), 20 );
		}
		foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'description' ), 20 );
		}
		add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'image' ), 20 );
		add_filter( 'wpseo_twitter_image', array( __CLASS__, 'image' ), 20 );
		add_filter( 'wpseo_schema_webpage', array( __CLASS__, 'yoast_webpage' ), 20 );
		add_action( 'wpseo_register_extra_replacements', array( __CLASS__, 'yoast_vars' ) );

		// Rank Math.
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'description' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/image', array( __CLASS__, 'image' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/image', array( __CLASS__, 'image' ), 20 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'rankmath_jsonld' ), 20 );
		add_action( 'rank_math/vars/register_extra_replacements', array( __CLASS__, 'rankmath_vars' ) );

		// No SEO plugin.
		add_filter( 'pre_get_document_title', array( __CLASS__, 'plain_title' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'plain_head' ), 2 );
	}

	public static function has_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
	}

	public static function default_templates( $market_id ) {
		$d = array(
			'gold'   => array(
				'title' => 'قیمت طلا امروز | قیمت لحظه‌ای طلای ۱۸ عیار، سکه و مثقال',
				'desc'  => 'قیمت لحظه‌ای طلای ۱۸ عیار امروز {date_long}: {price} {unit}. جدول قیمت سکه و مثقال، نمودار تغییرات و ماشین‌حساب طلا.',
			),
			'coin'   => array(
				'title' => 'قیمت سکه امروز | سکه امامی، بهار آزادی، نیم و ربع',
				'desc'  => 'قیمت لحظه‌ای سکه امامی امروز {date_long}: {price} {unit}. جدول قیمت انواع سکه و نمودار تغییرات.',
			),
			'crypto' => array(
				'title' => 'قیمت ارز دیجیتال امروز | بیت‌کوین، اتریوم و تتر',
				'desc'  => 'قیمت لحظه‌ای {name} امروز {date_long}: {price} {unit}. جدول قیمت ارزهای دیجیتال و نمودار تغییرات.',
			),
			'dollar' => array(
				'title' => 'قیمت دلار امروز | قیمت لحظه‌ای دلار، یورو، درهم و تتر',
				'desc'  => 'قیمت لحظه‌ای دلار امروز {date_long}: {price} {unit}. جدول قیمت ارزها، نمودار تغییرات و مبدل ارز.',
			),
		);
		return isset( $d[ $market_id ] ) ? $d[ $market_id ] : array( 'title' => '{title} | {site}', 'desc' => '{title} {date_long}: {name} {price} {unit}.' );
	}

	/** Template variables for one market. */
	public static function vars( $market_id ) {
		$m = NRY_Markets::get( $market_id );
		if ( ! $m ) {
			return array();
		}
		$item = NRY_Api::one( $m['main'] );
		$d    = $item ? NRY_Api::display( $item ) : null;
		return array(
			'title'     => $m['title'],
			'label'     => $m['label'],
			'move'      => $d ? ( $d['pct'] > 0 ? 'افزایش' : ( $d['pct'] < 0 ? 'کاهش' : 'ثبات' ) ) : '',
			'name'      => $d ? $d['name'] : $m['main_label'],
			'price'     => $d ? nry_num( $d['price'] ) : '',
			'unit'      => $d ? $d['unit'] : '',
			'change'    => $d ? nry_pct( $d['pct'] ) : '',
			'date'      => $d ? nry_fa( $d['date'] ) : '',
			'date_long' => $d ? nry_date_long( $d['date'] ) : '',
			'time'      => $d ? nry_fa( $d['time'] ) : '',
			'weekday'   => nry_weekday( $item && $item['unix'] ? $item['unix'] : null ),
			'site'      => get_bloginfo( 'name' ),
		);
	}

	/** The landing page being viewed, or null. */
	private static function context() {
		if ( false !== self::$context ) {
			return self::$context;
		}
		self::$context = null;
		if ( is_admin() || ! is_singular() ) {
			return null;
		}
		$id  = get_queried_object_id();
		$mid = get_post_meta( $id, '_nry_market', true );
		if ( $mid && NRY_Markets::get( $mid ) ) {
			self::$context = array( 'post' => $id, 'market' => $mid, 'vars' => self::vars( $mid ) );
		}
		return self::$context;
	}

	private static function template( $kind ) {
		$c = self::context();
		if ( ! $c || ! nry_opt( 'seo_on', 1 ) ) {
			return '';
		}
		// A title or description typed by hand in the SEO plugin's box always wins.
		$manual = 'title' === $kind ? array( '_yoast_wpseo_title', 'rank_math_title' ) : array( '_yoast_wpseo_metadesc', 'rank_math_description' );
		foreach ( $manual as $key ) {
			if ( '' !== trim( (string) get_post_meta( $c['post'], $key, true ) ) ) {
				return '';
			}
		}
		$saved = nry_opt( 'seo', array() );
		$def   = self::default_templates( $c['market'] );
		$tpl   = ! empty( $saved[ $c['market'] ][ $kind ] ) ? $saved[ $c['market'] ][ $kind ] : $def[ $kind ];
		return nry_fill( $tpl, $c['vars'] );
	}

	public static function title( $title ) {
		$t = self::template( 'title' );
		return '' !== $t ? $t : $title;
	}

	public static function description( $desc ) {
		$t = self::template( 'desc' );
		return '' !== $t ? $t : $desc;
	}

	public static function image( $image ) {
		$c = self::context();
		if ( ! $c || ! nry_opt( 'image_on', 1 ) || has_post_thumbnail( $c['post'] ) ) {
			return $image;
		}
		$latest = NRY_Image::latest_url( $c['market'] );
		return $latest ? $latest : $image;
	}

	private static function modified() {
		$snap = NRY_Api::snapshot();
		return ! empty( $snap['fetched'] ) ? gmdate( 'c', (int) $snap['fetched'] ) : '';
	}

	public static function yoast_webpage( $data ) {
		if ( self::context() && self::modified() ) {
			$data['dateModified'] = self::modified();
		}
		return $data;
	}

	public static function rankmath_jsonld( $data ) {
		if ( self::context() && self::modified() && isset( $data['WebPage'] ) && is_array( $data['WebPage'] ) ) {
			$data['WebPage']['dateModified'] = self::modified();
		}
		return $data;
	}

	/* ----- replacement variables usable in the SEO plugins' own templates ----- */

	public static function var_value( $key ) {
		$c    = self::context();
		$vars = $c ? $c['vars'] : self::vars( 'gold' );
		return isset( $vars[ $key ] ) ? $vars[ $key ] : '';
	}

	public static function yoast_vars() {
		if ( ! function_exists( 'wpseo_register_var_replacement' ) ) {
			return;
		}
		wpseo_register_var_replacement( '%%nry_price%%', function () { return NRY_Seo::var_value( 'price' ); }, 'advanced', 'رخداد نرخ: قیمت نماد اصلی' );
		wpseo_register_var_replacement( '%%nry_date%%', function () { return NRY_Seo::var_value( 'date_long' ); }, 'advanced', 'رخداد نرخ: تاریخ آخرین قیمت' );
	}

	public static function rankmath_vars() {
		if ( ! function_exists( 'rank_math_register_var_replacement' ) ) {
			return;
		}
		rank_math_register_var_replacement(
			'nry_price',
			array( 'name' => 'رخداد نرخ: قیمت', 'description' => 'قیمت نماد اصلی لندینگ', 'variable' => 'nry_price', 'example' => '۱۲٬۳۴۵٬۶۷۸' ),
			function () { return NRY_Seo::var_value( 'price' ); }
		);
		rank_math_register_var_replacement(
			'nry_date',
			array( 'name' => 'رخداد نرخ: تاریخ', 'description' => 'تاریخ آخرین قیمت', 'variable' => 'nry_date', 'example' => '۱۶ مهر ۱۴۰۵' ),
			function () { return NRY_Seo::var_value( 'date_long' ); }
		);
	}

	/* ----- fallback when no SEO plugin is active ----- */

	public static function plain_title( $title ) {
		if ( self::has_seo_plugin() ) {
			return $title;
		}
		$t = self::template( 'title' );
		return '' !== $t ? $t : $title;
	}

	public static function plain_head() {
		if ( self::has_seo_plugin() ) {
			return;
		}
		$d = self::template( 'desc' );
		if ( '' !== $d ) {
			echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $d ) . '">' . "\n";
		}
		$c = self::context();
		if ( $c && nry_opt( 'image_on', 1 ) ) {
			$img = NRY_Image::latest_url( $c['market'] );
			if ( $img ) {
				echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
			}
		}
	}

	/* ----- structured data collected during render ----- */

	public static function add_faq( $pairs ) {
		foreach ( (array) $pairs as $pair ) {
			if ( ! empty( $pair[0] ) && ! empty( $pair[1] ) ) {
				self::$faq[ md5( $pair[0] ) ] = $pair;
			}
		}
	}

	public static function set_breadcrumb( $name, $url ) {
		self::$crumbs = array(
			array( get_bloginfo( 'name' ), home_url( '/' ) ),
			array( $name, $url ),
		);
	}

	public static function output() {
		$graph = array();
		// Yoast and Rank Math print their own breadcrumb schema; ours would duplicate it.
		if ( self::$crumbs && ! self::has_seo_plugin() ) {
			$list = array();
			foreach ( self::$crumbs as $i => $c ) {
				$list[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => wp_strip_all_tags( $c[0] ),
					'item'     => $c[1],
				);
			}
			$graph[] = array( '@type' => 'BreadcrumbList', 'itemListElement' => $list );
		}
		if ( self::$faq ) {
			$main = array();
			foreach ( self::$faq as $pair ) {
				$main[] = array(
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( $pair[0] ),
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $pair[1] ) ),
				);
			}
			$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => $main );
		}
		if ( ! $graph ) {
			return;
		}
		$data = array( '@context' => 'https://schema.org', '@graph' => $graph );
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
