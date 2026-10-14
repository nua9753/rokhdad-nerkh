<?php
defined( 'ABSPATH' ) || exit;

class NRY_Shortcodes {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( 'NRY_Render', 'register_assets' ), 5 );
		foreach ( array( 'price_card', 'price', 'board', 'table', 'chart', 'calculator', 'guide', 'faq', 'landing', 'posts' ) as $name ) {
			add_shortcode( 'gn_' . $name, array( __CLASS__, $name ) );
		}
	}

	private static function market( $atts ) {
		return ! empty( $atts['market'] ) ? NRY_Markets::get( sanitize_key( $atts['market'] ) ) : null;
	}

	/** [gn_price_card symbol="USD"] or [gn_price_card market="gold" link="/gold-price"] */
	public static function price_card( $atts ) {
		$atts = shortcode_atts( array( 'symbol' => '', 'market' => '', 'label' => '', 'link' => '', 'link_text' => '', 'share' => '1' ), $atts, 'gn_price_card' );
		NRY_Render::assets();
		$args = array( 'label' => $atts['label'], 'link' => $atts['link'], 'link_text' => $atts['link_text'], 'share' => '0' !== (string) $atts['share'] );
		$m    = self::market( $atts );
		$ref  = $atts['symbol'];
		if ( '' === $ref ) {
			if ( ! $m ) {
				$m = NRY_Markets::get( 'gold' );
			}
			$ref           = $m['main'];
			$args['stamp'] = $m['stamp'];
			$args['title'] = $m['title'];
			if ( '' === $args['label'] ) {
				$args['label'] = $m['main_label'];
			}
		}
		if ( 'auto' === $atts['link'] ) {
			$args['link'] = $m ? NRY_Pages::landing_url( sanitize_key( $atts['market'] ? $atts['market'] : 'gold' ) ) : '';
		}
		return NRY_Render::card( $ref, $args );
	}

	/** [gn_price symbol="USD"] — a live number inside a sentence. */
	public static function price( $atts ) {
		$atts = shortcode_atts( array( 'symbol' => 'USD', 'field' => 'price', 'unit' => '1' ), $atts, 'gn_price' );
		NRY_Render::assets();
		return NRY_Render::inline( $atts['symbol'], 'pct' === $atts['field'] ? 'pct' : 'price', '0' !== (string) $atts['unit'] );
	}

	/** [gn_board market="gold"] or [gn_board symbols="USD,EUR,AED"] */
	public static function board( $atts ) {
		$atts = shortcode_atts( array( 'market' => '', 'symbols' => '' ), $atts, 'gn_board' );
		NRY_Render::assets();
		$m    = self::market( $atts );
		$refs = '' !== $atts['symbols'] ? NRY_Markets::split( $atts['symbols'] ) : ( $m ? $m['board'] : array() );
		return NRY_Render::board( $refs );
	}

	/** [gn_table market="gold" group="coin"] or [gn_table symbols="USD,EUR" title="..."] */
	public static function table( $atts ) {
		$atts = shortcode_atts( array( 'market' => '', 'group' => '', 'symbols' => '', 'title' => '', 'head' => '' ), $atts, 'gn_table' );
		NRY_Render::assets();
		if ( '' !== $atts['symbols'] ) {
			return NRY_Render::table( $atts['title'], $atts['head'], NRY_Markets::split( $atts['symbols'] ) );
		}
		$m = self::market( $atts );
		if ( ! $m ) {
			return '';
		}
		$out = '';
		foreach ( $m['tables'] as $tid => $t ) {
			if ( '' === $atts['group'] || $atts['group'] === $tid ) {
				$out .= NRY_Render::table( '' !== $atts['title'] ? $atts['title'] : $t['title'], $t['head'], $t['symbols'] );
			}
		}
		return $out;
	}

	/** [gn_chart symbol="USD"] or [gn_chart market="gold"] */
	public static function chart( $atts ) {
		$atts = shortcode_atts( array( 'symbol' => '', 'market' => '', 'title' => '' ), $atts, 'gn_chart' );
		NRY_Render::assets();
		$m = self::market( $atts );
		if ( '' !== $atts['symbol'] ) {
			return NRY_Render::chart( $atts['symbol'], $atts['title'] );
		}
		if ( ! $m ) {
			$m = NRY_Markets::get( 'gold' );
		}
		return NRY_Render::chart( $m['main'], '' !== $atts['title'] ? $atts['title'] : $m['chart_title'] );
	}

	/** [gn_calculator type="gold"] or [gn_calculator type="fx"] */
	public static function calculator( $atts ) {
		$atts = shortcode_atts( array( 'type' => 'gold' ), $atts, 'gn_calculator' );
		NRY_Render::assets();
		$type = 'fx' === $atts['type'] ? 'fx' : 'gold';
		return NRY_Render::calculator( $type, NRY_Markets::get( 'fx' === $type ? 'dollar' : 'gold' ) );
	}

	public static function guide( $atts ) {
		$atts = shortcode_atts( array( 'market' => 'gold' ), $atts, 'gn_guide' );
		NRY_Render::assets();
		$m = self::market( $atts );
		return $m ? NRY_Render::guide( $m ) : '';
	}

	/** [gn_faq market="gold"] or, with your own questions: [gn_faq title="..."]پرسش | پاسخ[/gn_faq] */
	public static function faq( $atts, $content = null ) {
		$atts = shortcode_atts( array( 'market' => 'gold', 'title' => '' ), $atts, 'gn_faq' );
		NRY_Render::assets();
		$m = self::market( $atts );
		if ( ! $m ) {
			$m = NRY_Markets::skeleton();
		}
		if ( null !== $content && '' !== trim( wp_strip_all_tags( $content ) ) ) {
			$text    = preg_replace( '/<br\s*\/?>|<\/p>/i', "\n", $content );
			$m['faq'] = NRY_Markets::parse_faq( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( '' !== $atts['title'] ) {
			$m['faq_title'] = $atts['title'];
		}
		return NRY_Render::faq( $m );
	}

	/** [gn_posts market="gold" count="5"] — latest posts of the category or tag linked to the market. */
	public static function posts( $atts ) {
		$atts = shortcode_atts( array( 'market' => 'gold', 'count' => 0 ), $atts, 'gn_posts' );
		NRY_Render::assets();
		$m = self::market( $atts );
		return $m ? NRY_Render::posts( $m, (int) $atts['count'] ) : '';
	}

	/** [gn_landing market="gold"] — the whole page. heading: auto | h1 | h2 */
	public static function landing( $atts, $content = null ) {
		$atts = shortcode_atts( array( 'market' => 'gold', 'heading' => 'auto', 'title' => '', 'lead' => '' ), $atts, 'gn_landing' );
		NRY_Render::assets();
		$heading = $atts['heading'];
		if ( 'auto' === $heading ) {
			// Our own template prints no theme title, so the landing owns the H1 there.
			$heading = NRY_Pages::uses_template() ? 'h1' : 'h2';
		}
		$over = array( 'title' => sanitize_text_field( $atts['title'] ), 'lead' => sanitize_text_field( $atts['lead'] ) );
		if ( null !== $content ) {
			// Stray paragraph tags left at the edges by the editor around the opening and closing tags.
			$inner           = preg_replace( '/^\s*(<\/p>|<br\s*\/?>)\s*|\s*(<p>|<br\s*\/?>)\s*$/i', '', do_shortcode( $content ) );
			$over['content'] = force_balance_tags( $inner );
		}
		return NRY_Render::landing( sanitize_key( $atts['market'] ), $heading, $over );
	}
}
