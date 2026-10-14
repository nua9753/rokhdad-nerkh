<?php
defined( 'ABSPATH' ) || exit;

/** Standalone landing pages (real WordPress pages) and injection into existing content. */
class NRY_Pages {

	const TEMPLATE = 'rokhdad-nerkh-landing';

	private static $injecting = false;

	public static function init() {
		add_filter( 'theme_page_templates', array( __CLASS__, 'register_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'load_template' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'the_content', array( __CLASS__, 'inject' ), 12 );
		add_action( 'wp', array( __CLASS__, 'early_assets' ) );
		add_action( 'loop_start', array( __CLASS__, 'archive_top' ) );
		add_action( 'loop_end', array( __CLASS__, 'archive_bottom' ) );
		add_filter( 'get_the_archive_description', array( __CLASS__, 'archive_desc' ) );
	}

	public static function register_template( $templates ) {
		$templates[ self::TEMPLATE ] = 'رخداد نرخ — لندینگ تمام‌عرض';
		return $templates;
	}

	public static function uses_template() {
		if ( ! is_page() ) {
			return false;
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return false;
		}
		return self::TEMPLATE === get_page_template_slug( get_queried_object_id() );
	}

	public static function load_template( $template ) {
		if ( self::uses_template() ) {
			return NRY_DIR . 'templates/landing.php';
		}
		return $template;
	}

	public static function body_class( $classes ) {
		if ( self::uses_template() ) {
			$classes[] = 'nry-template';
		}
		return $classes;
	}

	/** Create a real page holding the landing shortcode. $slug may be Latin or Persian. */
	public static function create( $market_id, $title, $slug, $status = 'publish' ) {
		$m = NRY_Markets::get( $market_id );
		if ( ! $m ) {
			return new WP_Error( 'nry_market', 'بازار انتخاب‌شده معتبر نیست.' );
		}
		$title = '' !== trim( $title ) ? $title : $m['title'];
		$slug  = '' !== trim( $slug ) ? $slug : $m['slug'];
		$id    = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'draft',
				'post_title'   => sanitize_text_field( $title ),
				'post_name'    => sanitize_title( $slug ),
				'post_content' => self::editable_content( $market_id, $title ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_nry_market', sanitize_key( $market_id ) );
		update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
		return $id;
	}

	/** Shortcode attribute value: no double quotes or brackets. */
	private static function attr( $s ) {
		return str_replace( array( '"', '[', ']' ), array( '”', '(', ')' ), wp_strip_all_tags( (string) $s ) );
	}

	/**
	 * Page content in blocks: the landing modules stay in one shortcode, while the title, lead,
	 * guide text and FAQ are ordinary, editable content inside it.
	 */
	public static function editable_content( $market_id, $title = '' ) {
		$m   = NRY_Markets::get( $market_id );
		$mid = sanitize_key( $market_id );
		$out = '<!-- wp:shortcode -->[gn_landing market="' . $mid . '" title="' . self::attr( '' !== trim( $title ) ? $title : $m['title'] ) . '" lead="' . self::attr( $m['lead'] ) . "\"]<!-- /wp:shortcode -->\n\n";
		if ( '' !== trim( (string) $m['guide_title'] ) ) {
			$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $m['guide_title'] ) . "</h2>\n<!-- /wp:heading -->\n\n";
		}
		$guide = (string) $m['guide'];
		if ( preg_match_all( '/<(p|h3)\b[^>]*>(.*?)<\/\1>|<(div|ul|ol|table|figure)\b.*?<\/\3>/s', $guide, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $f ) {
				if ( 'p' === $f[1] ) {
					$out .= "<!-- wp:paragraph -->\n<p>" . $f[2] . "</p>\n<!-- /wp:paragraph -->\n\n";
				} elseif ( 'h3' === $f[1] ) {
					$out .= "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . $f[2] . "</h3>\n<!-- /wp:heading -->\n\n";
				} else {
					$out .= "<!-- wp:html -->\n" . $f[0] . "\n<!-- /wp:html -->\n\n";
				}
			}
		} elseif ( '' !== trim( $guide ) ) {
			$out .= "<!-- wp:html -->\n" . $guide . "\n<!-- /wp:html -->\n\n";
		}
		if ( ! empty( $m['faq'] ) ) {
			$out .= '<!-- wp:shortcode -->[gn_faq market="' . $mid . '" title="' . self::attr( $m['faq_title'] ) . "\"]\n" . NRY_Markets::faq_text( $m['faq'] ) . "\n[/gn_faq]<!-- /wp:shortcode -->\n\n";
		}
		return $out . '<!-- wp:shortcode -->[/gn_landing]<!-- /wp:shortcode -->';
	}

	/** True when a page still holds only the old single landing shortcode. */
	public static function is_fixed( $page ) {
		return (bool) preg_match( '/^\s*(<!-- wp:shortcode -->)?\s*\[gn_landing[^\]]*\]\s*(<!-- \/wp:shortcode -->)?\s*$/', (string) $page->post_content );
	}

	public static function landing_pages() {
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'meta_key'       => '_nry_market', // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	public static function landing_url( $market_id ) {
		foreach ( self::landing_pages() as $page ) {
			if ( 'publish' === $page->post_status && get_post_meta( $page->ID, '_nry_market', true ) === $market_id ) {
				return get_permalink( $page );
			}
		}
		return '';
	}

	private static function rules_for( $post ) {
		$match = array();
		foreach ( (array) nry_opt( 'inject', array() ) as $rule ) {
			if ( empty( $rule['on'] ) || empty( $rule['code'] ) ) {
				continue;
			}
			$where = isset( $rule['where'] ) ? $rule['where'] : 'id';
			$id    = isset( $rule['id'] ) ? (int) $rule['id'] : 0;
			$hit   = false;
			if ( 'id' === $where || 'page' === $where ) {
				$hit = $id && (int) $post->ID === $id;
			} elseif ( 'cat' === $where || 'tag' === $where ) {
				$scope = isset( $rule['scope'] ) ? $rule['scope'] : 'posts';
				if ( 'archive' !== $scope && $id && 'post' === $post->post_type ) {
					$hit = 'cat' === $where ? has_category( $id, $post ) : has_tag( $id, $post );
				}
			} elseif ( 'posts' === $where ) {
				$hit = 'post' === $post->post_type;
			}
			if ( $hit ) {
				$match[] = $rule;
			}
		}
		return $match;
	}

	/** Add modules before, after, or inside the content of matching pages and posts. */
	public static function inject( $content ) {
		if ( self::$injecting || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post ) {
			return $content;
		}
		foreach ( self::rules_for( $post ) as $rule ) {
			self::$injecting = true;
			$block           = do_shortcode( $rule['code'] );
			self::$injecting = false;
			if ( '' === trim( $block ) ) {
				continue;
			}
			$pos = isset( $rule['pos'] ) ? $rule['pos'] : 'before';
			if ( 'after' === $pos ) {
				$content .= $block;
			} elseif ( 'para' === $pos ) {
				$n     = max( 1, isset( $rule['para'] ) ? (int) $rule['para'] : 1 );
				$parts = explode( '</p>', $content );
				if ( count( $parts ) > $n ) {
					$parts[ $n - 1 ] .= '</p>' . $block;
					$content          = implode( '</p>', array_slice( $parts, 0, $n ) ) . implode( '</p>', array_slice( $parts, $n ) );
				} else {
					$content .= $block;
				}
			} else {
				$content = $block . $content;
			}
		}
		return $content;
	}

	/** Rules for the category or tag archive being viewed. */
	private static function archive_rules() {
		if ( ! is_category() && ! is_tag() ) {
			return array();
		}
		$term  = get_queried_object();
		$match = array();
		foreach ( (array) nry_opt( 'inject', array() ) as $rule ) {
			if ( empty( $rule['on'] ) || empty( $rule['code'] ) || empty( $rule['id'] ) ) {
				continue;
			}
			$where = isset( $rule['where'] ) ? $rule['where'] : '';
			$scope = isset( $rule['scope'] ) ? $rule['scope'] : 'posts';
			if ( 'posts' === $scope || ! ( ( 'cat' === $where && is_category() ) || ( 'tag' === $where && is_tag() ) ) ) {
				continue;
			}
			if ( $term && isset( $term->term_id ) && (int) $term->term_id === (int) $rule['id'] ) {
				$match[] = $rule;
			}
		}
		return $match;
	}

	private static function archive_blocks( $positions ) {
		$out = '';
		foreach ( self::archive_rules() as $rule ) {
			$pos = isset( $rule['pos'] ) ? $rule['pos'] : 'before';
			$pos = 'para' === $pos ? 'before' : $pos;
			if ( in_array( $pos, $positions, true ) ) {
				$out .= do_shortcode( $rule['code'] );
			}
		}
		return $out;
	}

	private static $archive_done = array();

	/** «قبل از محتوا» on an archive: above the list of posts. */
	public static function archive_top( $query ) {
		if ( is_admin() || ! $query->is_main_query() || isset( self::$archive_done['top'] ) ) {
			return;
		}
		self::$archive_done['top'] = 1;
		echo self::archive_blocks( array( 'before' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** «بعد از محتوا» on an archive: below the list of posts. */
	public static function archive_bottom( $query ) {
		if ( is_admin() || ! $query->is_main_query() || isset( self::$archive_done['bottom'] ) ) {
			return;
		}
		self::$archive_done['bottom'] = 1;
		echo self::archive_blocks( array( 'after' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** «داخل توضیح آرشیو»: before the category or tag description printed by the theme. */
	public static function archive_desc( $description ) {
		if ( is_admin() || isset( self::$archive_done['desc'] ) ) {
			return $description;
		}
		$block = self::archive_blocks( array( 'desc' ) );
		if ( '' === $block ) {
			return $description;
		}
		self::$archive_done['desc'] = 1;
		return $block . $description;
	}

	/** Load CSS in the head when we already know the page shows a module. */
	public static function early_assets() {
		if ( self::archive_rules() ) {
			add_action( 'wp_enqueue_scripts', array( 'NRY_Render', 'assets' ), 20 );
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		if ( false !== strpos( (string) $post->post_content, '[gn_' ) || self::rules_for( $post ) ) {
			add_action( 'wp_enqueue_scripts', array( 'NRY_Render', 'assets' ), 20 );
		}
	}
}
