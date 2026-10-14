<?php
defined( 'ABSPATH' ) || exit;

/**
 * Links the first mention of a market phrase in each post to that market's page
 * (the page the plugin built, or the category, tag, page or address chosen in settings).
 * It runs before other content filters, so these links win over other auto-linkers.
 */
class NRY_Autolink {

	/** Text inside these elements is never linked. */
	const SKIP = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'button', 'textarea', 'code', 'pre', 'figcaption', 'caption', 'th', 'summary', 'label', 'nav', 'select', 'svg' );

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'run' ), 8 );
	}

	/** Where a market's phrases link to; '' when there is no target yet. */
	public static function target_url( $market_id ) {
		$all = (array) nry_opt( 'autolink_target', array() );
		$t   = isset( $all[ $market_id ] ) && is_array( $all[ $market_id ] ) ? $all[ $market_id ] : array();
		$type = isset( $t['type'] ) ? $t['type'] : 'page';
		$id   = isset( $t['id'] ) ? (int) $t['id'] : 0;
		if ( 'cat' === $type && $id ) {
			$url = get_category_link( $id );
		} elseif ( 'tag' === $type && $id ) {
			$url = get_tag_link( $id );
		} elseif ( 'post' === $type && $id ) {
			$url = 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '';
		} elseif ( 'url' === $type && ! empty( $t['url'] ) ) {
			$url = $t['url'];
		} else {
			$url = NRY_Pages::landing_url( $market_id );
		}
		return is_string( $url ) ? $url : '';
	}

	/** The market's title, with and without «امروز». */
	public static function default_words( $market_id ) {
		$m = NRY_Markets::get( $market_id );
		if ( ! $m || '' === trim( $m['title'] ) ) {
			return '';
		}
		$short = trim( preg_replace( '/\s+/u', ' ', str_replace( 'امروز', '', $m['title'] ) ) );
		$words = array( $m['title'], $short );
		// The title of the page built for this market counts too, whatever it was named.
		foreach ( NRY_Pages::landing_pages() as $page ) {
			if ( get_post_meta( $page->ID, '_nry_market', true ) === $market_id ) {
				$words[] = get_the_title( $page );
			}
		}
		return implode( '، ', array_unique( array_filter( $words ) ) );
	}

	public static function run( $content ) {
		if ( ! nry_opt( 'autolink', 0 ) || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$saved = (array) nry_opt( 'autolink_words', array() );
		foreach ( array_keys( NRY_Markets::all() ) as $mid ) {
			$url = self::target_url( $mid );
			// One link per market, none if the writer already linked there, and never a link to the post itself.
			if ( ! $url || false !== strpos( $content, $url ) || false !== strpos( $content, urldecode( $url ) ) || untrailingslashit( $url ) === untrailingslashit( (string) get_permalink() ) ) {
				continue;
			}
			$words = NRY_Markets::split( str_replace( '،', ',', ! empty( $saved[ $mid ] ) ? $saved[ $mid ] : self::default_words( $mid ) ) );
			// Longest phrase first, so "قیمت طلا امروز" wins over "قیمت طلا".
			usort(
				$words,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$content = self::link_first( $content, $words, $url );
		}
		return $content;
	}

	/** Wrap the first occurrence of any phrase that sits in plain text. */
	public static function link_first( $html, $words, $url ) {
		if ( ! $words ) {
			return $html;
		}
		// Shortcodes and HTML comments (block markup) are set aside so nothing inside them is touched.
		$kept = array();
		$html = preg_replace_callback(
			'/<!--.*?-->|\[[^\[\]]*\]/s',
			function ( $m ) use ( &$kept ) {
				$kept[] = $m[0];
				return '<nry-keep ' . ( count( $kept ) - 1 ) . '>';
			},
			$html
		);
		$restore = function ( $s ) use ( &$kept ) {
			return preg_replace_callback(
				'/<nry-keep (\d+)>/',
				function ( $m ) use ( &$kept ) {
					return $kept[ (int) $m[1] ];
				},
				$s
			);
		};
		$parts = preg_split( '/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $restore( $html );
		}
		$depth = 0;
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '/^<\s*(\/?)\s*([a-zA-Z0-9]+)/', $part, $m ) && in_array( strtolower( $m[2] ), self::SKIP, true ) ) {
					if ( '/' === $m[1] ) {
						$depth = max( 0, $depth - 1 );
					} elseif ( '/>' !== substr( $part, -2 ) ) {
						$depth++;
					}
				}
				continue;
			}
			if ( $depth > 0 ) {
				continue;
			}
			// The earliest mention in the text wins; at the same spot, the longer phrase.
			$pos  = false;
			$word = '';
			foreach ( $words as $w ) {
				$p = strpos( $part, $w );
				if ( false !== $p && ( false === $pos || $p < $pos ) ) {
					$pos  = $p;
					$word = $w;
				}
			}
			if ( false !== $pos ) {
				$link        = '<a href="' . esc_url( $url ) . '">' . esc_html( $word ) . '</a>';
				$parts[ $i ] = substr( $part, 0, $pos ) . $link . substr( $part, $pos + strlen( $word ) );
				return $restore( implode( '', $parts ) );
			}
		}
		return $restore( $html );
	}
}
