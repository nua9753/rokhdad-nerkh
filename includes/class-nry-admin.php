<?php
defined( 'ABSPATH' ) || exit;

class NRY_Admin {

	const SLUG = 'rokhdad-nerkh';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nry_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nry_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_nry_create_page', array( __CLASS__, 'create_page' ) );
		add_action( 'admin_post_nry_font', array( __CLASS__, 'upload_font' ) );
		add_action( 'admin_post_nry_daily_now', array( __CLASS__, 'daily_now' ) );
		add_action( 'admin_post_nry_market_save', array( __CLASS__, 'market_save' ) );
		add_action( 'admin_post_nry_market_delete', array( __CLASS__, 'market_delete' ) );
		add_action( 'admin_post_nry_page_editable', array( __CLASS__, 'page_editable' ) );
		add_action( 'wp_ajax_nry_tags', array( __CLASS__, 'tag_search' ) );
	}

	public static function menu() {
		add_menu_page( 'رخداد نرخ', 'رخداد نرخ', 'manage_options', self::SLUG, array( __CLASS__, 'screen' ), 'dashicons-chart-line', 58 );
	}

	private static function tabs() {
		return array(
			'connect'    => 'اتصال و داده',
			'markets'    => 'بازارها',
			'pages'      => 'صفحه‌ها',
			'inject'     => 'نمایش در محتوا',
			'seo'        => 'سئو',
			'auto'       => 'خودکارسازی',
			'design'     => 'ظاهر',
			'shortcodes' => 'شورت‌کدها',
		);
	}

	private static function url( $tab ) {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	private static function back( $tab, $type, $message ) {
		set_transient( 'nry_notice_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( $action );
	}

	/* ---------- handlers ---------- */

	public static function save() {
		self::guard( 'nry_save' );
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'connect';
		$saved = get_option( 'nry_settings', array() );
		$s     = is_array( $saved ) ? $saved : array();

		if ( 'connect' === $tab ) {
			$old_key       = isset( $s['api_key'] ) ? $s['api_key'] : '';
			$s['api_key']  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			$old_plan      = isset( $s['plan'] ) ? $s['plan'] : 'free';
			$s['plan']     = ( isset( $_POST['plan'] ) && 'pro' === $_POST['plan'] ) ? 'pro' : 'free';
			$s['interval'] = min( 60, max( 1, isset( $_POST['interval'] ) ? (int) $_POST['interval'] : 5 ) );
			$s['toman']    = empty( $_POST['toman'] ) ? 0 : 1;
			update_option( 'nry_settings', $s );
			NRY_Api::reschedule();
			if ( ( $s['api_key'] !== $old_key || $s['plan'] !== $old_plan ) && '' !== $s['api_key'] ) {
				$r = NRY_Api::refresh();
				if ( is_wp_error( $r ) ) {
					self::back( $tab, 'error', 'تنظیمات ذخیره شد، ولی دریافت قیمت ناموفق بود: ' . $r->get_error_message() );
				}
				self::back( $tab, 'success', 'ذخیره شد و ' . nry_fa( $r ) . ' نماد دریافت شد.' );
			}
		} elseif ( 'inject' === $tab ) {
			$rules = array();
			$empty = array();
			$raw   = isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ? wp_unslash( $_POST['rule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( array_slice( $raw, 0, 30 ) as $r ) {
				if ( ! empty( $r['remove'] ) ) {
					continue;
				}
				$code = isset( $r['code'] ) ? trim( wp_kses_post( $r['code'] ) ) : '';
				// A module picked from the list is added here too, so it works even when the page script does not run.
				if ( ! empty( $r['add'] ) && 0 === strpos( (string) $r['add'], '[gn_' ) && false === strpos( $code, (string) $r['add'] ) ) {
					$code = trim( $code . "\n" . sanitize_text_field( $r['add'] ) );
				}
				// «آخرین مطالب»: a category or tag picked in the rule becomes a [gn_posts] module.
				$ptype = isset( $r['posts_type'] ) ? $r['posts_type'] : '';
				$pterm = 'cat' === $ptype ? absint( isset( $r['posts_cat'] ) ? $r['posts_cat'] : 0 ) : ( 'tag' === $ptype ? self::tag_id( isset( $r['posts_tag'] ) ? $r['posts_tag'] : '' ) : 0 );
				if ( $pterm ) {
					$count = in_array( (int) ( isset( $r['posts_count'] ) ? $r['posts_count'] : 5 ), array( 5, 10 ), true ) ? (int) $r['posts_count'] : 5;
					$code  = trim( $code . "\n" . '[gn_posts ' . ( 'cat' === $ptype ? 'cat' : 'tag' ) . '="' . $pterm . '" count="' . $count . '"]' );
				}
				$where = isset( $r['where'] ) && in_array( $r['where'], array( 'page', 'cat', 'tag', 'id', 'posts' ), true ) ? $r['where'] : 'page';
				$field = array( 'page' => 'page_id', 'cat' => 'cat_id', 'tag' => 'tag_id', 'id' => 'post_id' );
				$id    = 'tag' === $where ? self::tag_id( isset( $r['tag_name'] ) ? $r['tag_name'] : '' ) : ( isset( $field[ $where ], $r[ $field[ $where ] ] ) ? absint( $r[ $field[ $where ] ] ) : 0 );
				// The always-empty «قانون جدید» box is skipped only while nothing at all was chosen in it.
				if ( ! empty( $r['blank'] ) && '' === $code && ! $id ) {
					continue;
				}
				if ( '' === $code ) {
					$empty[] = count( $rules ) + 1;
				}
				$rules[] = array(
					'on'    => empty( $r['on'] ) ? 0 : 1,
					'where' => $where,
					'id'    => $id,
					'scope' => isset( $r['scope'] ) && in_array( $r['scope'], array( 'posts', 'archive', 'both' ), true ) ? $r['scope'] : 'posts',
					'pos'   => isset( $r['pos'] ) && in_array( $r['pos'], array( 'before', 'after', 'para', 'desc' ), true ) ? $r['pos'] : 'before',
					'para'  => isset( $r['para'] ) ? max( 1, absint( $r['para'] ) ) : 1,
					'code'  => $code,
				);
			}
			$s['inject'] = $rules;
			update_option( 'nry_settings', $s );
			if ( $empty ) {
				self::back( $tab, 'warning', 'ذخیره شد. قانون ' . nry_fa( implode( '، ', $empty ) ) . ' هنوز چیزی برای نمایش ندارد؛ در بخش «چه چیزی نمایش داده شود» یک ماژول یا «آخرین مطالب» را انتخاب کنید.' );
			}
		} elseif ( 'symbols' === $tab ) {
			$out = array();
			$raw = isset( $_POST['sym'] ) && is_array( $_POST['sym'] ) ? wp_unslash( $_POST['sym'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $fields ) {
				foreach ( (array) $fields as $k => $v ) {
					$v = sanitize_text_field( $v );
					if ( '' !== $v ) {
						$out[ sanitize_key( $market ) ][ sanitize_key( $k ) ] = $v;
					}
				}
			}
			$s['symbols'] = $out;
			update_option( 'nry_settings', $s );
		} elseif ( 'design' === $tab ) {
			foreach ( array( 'navy', 'gold', 'red' ) as $k ) {
				$c = isset( $_POST[ $k ] ) ? sanitize_hex_color( wp_unslash( $_POST[ $k ] ) ) : '';
				if ( $c ) {
					$s[ $k ] = $c;
				} else {
					unset( $s[ $k ] );
				}
			}
			update_option( 'nry_settings', $s );
		} elseif ( 'seo' === $tab ) {
			$s['seo_on'] = empty( $_POST['seo_on'] ) ? 0 : 1;
			$out         = array();
			$raw         = isset( $_POST['seo'] ) && is_array( $_POST['seo'] ) ? wp_unslash( $_POST['seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $fields ) {
				foreach ( array( 'title', 'desc' ) as $k ) {
					$v = isset( $fields[ $k ] ) ? sanitize_text_field( $fields[ $k ] ) : '';
					if ( '' !== $v ) {
						$out[ sanitize_key( $market ) ][ $k ] = $v;
					}
				}
			}
			$s['seo'] = $out;
			update_option( 'nry_settings', $s );
		} elseif ( 'auto' === $tab ) {
			$s['autolink'] = empty( $_POST['autolink'] ) ? 0 : 1;
			$words         = array();
			$raw           = isset( $_POST['words'] ) && is_array( $_POST['words'] ) ? wp_unslash( $_POST['words'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $v ) {
				$v = sanitize_text_field( $v );
				if ( '' !== $v ) {
					$words[ sanitize_key( $market ) ] = $v;
				}
			}
			$s['autolink_words'] = $words;
			$targets             = array();
			$raw                 = isset( $_POST['target'] ) && is_array( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $t ) {
				$type = isset( $t['type'] ) && in_array( $t['type'], array( 'page', 'cat', 'tag', 'post', 'url' ), true ) ? $t['type'] : 'page';
				$ids  = array( 'cat' => 'cat_id', 'tag' => 'tag_id', 'post' => 'post_id' );
				$targets[ sanitize_key( $market ) ] = array(
					'type' => $type,
					'id'   => 'tag' === $type ? self::tag_id( isset( $t['tag_name'] ) ? $t['tag_name'] : '' ) : ( isset( $ids[ $type ], $t[ $ids[ $type ] ] ) ? absint( $t[ $ids[ $type ] ] ) : 0 ),
					'url'  => 'url' === $type && isset( $t['url'] ) ? esc_url_raw( $t['url'] ) : '',
				);
			}
			$s['autolink_target'] = $targets;
			$bodies               = array();
			$raw                  = isset( $_POST['bodies'] ) && is_array( $_POST['bodies'] ) ? wp_unslash( $_POST['bodies'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $list ) {
				$list = array_values( array_filter( array_map( 'trim', array_map( 'sanitize_textarea_field', array_slice( (array) $list, 0, 5 ) ) ) ) );
				if ( $list ) {
					$bodies[ sanitize_key( $market ) ] = $list;
				}
			}
			$s['daily_bodies'] = $bodies;
			$dtags             = array();
			$raw               = isset( $_POST['daily_tags'] ) && is_array( $_POST['daily_tags'] ) ? wp_unslash( $_POST['daily_tags'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $v ) {
				$v = implode( '، ', NRY_Markets::split( sanitize_text_field( $v ) ) );
				if ( '' !== $v ) {
					$dtags[ sanitize_key( $market ) ] = $v;
				}
			}
			$s['daily_tags'] = $dtags;
			$ex              = array();
			$raw             = isset( $_POST['daily_excerpts'] ) && is_array( $_POST['daily_excerpts'] ) ? wp_unslash( $_POST['daily_excerpts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $market => $v ) {
				$lines = array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', sanitize_textarea_field( $v ) ) ) ) ), 0, 10 );
				if ( $lines ) {
					$ex[ sanitize_key( $market ) ] = $lines;
				}
			}
			$s['daily_excerpts'] = $ex;
			$s['daily_on']       = empty( $_POST['daily_on'] ) ? 0 : 1;
			$s['daily_hour']     = min( 23, max( 0, isset( $_POST['daily_hour'] ) ? (int) $_POST['daily_hour'] : 11 ) );
			$s['daily_cat']      = isset( $_POST['daily_cat'] ) ? absint( $_POST['daily_cat'] ) : 0;
			$markets             = isset( $_POST['daily_markets'] ) && is_array( $_POST['daily_markets'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['daily_markets'] ) ) : array();
			$s['daily_markets']  = array_values( array_intersect( $markets, array_keys( NRY_Markets::all() ) ) );
			$s['daily_titles']        = isset( $_POST['daily_titles'] ) ? sanitize_textarea_field( wp_unslash( $_POST['daily_titles'] ) ) : '';
			$mode                     = isset( $_POST['daily_publish'] ) ? sanitize_key( wp_unslash( $_POST['daily_publish'] ) ) : 'draft';
			$s['daily_publish']       = in_array( $mode, array( 'draft', 'publish', 'schedule' ), true ) ? $mode : 'draft';
			$s['daily_publish_hour']  = min( 23, max( 0, isset( $_POST['daily_publish_hour'] ) ? (int) $_POST['daily_publish_hour'] : 12 ) );
			$s['daily_analysis']      = empty( $_POST['daily_analysis'] ) ? 0 : 1;
			$s['image_on'] = empty( $_POST['image_on'] ) ? 0 : 1;
			update_option( 'nry_settings', $s );
			NRY_Daily::reschedule();
		}
		self::back( $tab, 'success', 'تنظیمات ذخیره شد.' );
	}

	/** Store the TTF used for the daily image in uploads/rokhdad-nerkh/font.ttf. */
	public static function upload_font() {
		self::guard( 'nry_font' );
		if ( empty( $_FILES['font']['tmp_name'] ) || ! is_uploaded_file( $_FILES['font']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::back( 'auto', 'error', 'فایلی انتخاب نشده است.' );
		}
		$name = isset( $_FILES['font']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['font']['name'] ) ) : '';
		$head = file_get_contents( $_FILES['font']['tmp_name'], false, null, 0, 4 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions
		// TrueType files start with 00 01 00 00 or "true"; anything else is refused.
		if ( 'ttf' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || ! in_array( $head, array( "\x00\x01\x00\x00", 'true' ), true ) ) {
			self::back( 'auto', 'error', 'فقط فایل فونت TTF پذیرفته می‌شود.' );
		}
		$dir = NRY_Image::dir();
		if ( ! wp_mkdir_p( $dir['path'] ) || ! move_uploaded_file( $_FILES['font']['tmp_name'], $dir['path'] . '/font.ttf' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::back( 'auto', 'error', 'ذخیره‌ی فونت ناموفق بود.' );
		}
		self::back( 'auto', 'success', 'فونت ذخیره شد.' );
	}

	public static function daily_now() {
		self::guard( 'nry_daily_now' );
		$log = NRY_Daily::run();
		self::back( 'auto', 'success', $log ? implode( ' | ', $log ) : 'کاری برای انجام نبود؛ پیش‌نویس روزانه و تصویر هر دو خاموش‌اند.' );
	}

	public static function test() {
		self::guard( 'nry_test' );
		$r = NRY_Api::refresh();
		if ( is_wp_error( $r ) ) {
			self::back( 'connect', 'error', 'اتصال ناموفق بود: ' . $r->get_error_message() );
		}
		self::back( 'connect', 'success', 'اتصال برقرار است؛ ' . nry_fa( $r ) . ' نماد دریافت شد.' );
	}

	public static function create_page() {
		self::guard( 'nry_create_page' );
		$market = isset( $_POST['market'] ) ? sanitize_key( wp_unslash( $_POST['market'] ) ) : 'gold';
		$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$slug   = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';
		$id     = NRY_Pages::create( $market, $title, $slug, $status );
		if ( is_wp_error( $id ) ) {
			self::back( 'pages', 'error', 'ساخت صفحه ناموفق بود: ' . $id->get_error_message() );
		}
		self::back( 'pages', 'success', 'صفحه ساخته شد: ' . urldecode( get_permalink( $id ) ) );
	}

	/** Turn a page that holds only [gn_landing] into editable title, lead, guide and FAQ. */
	public static function page_editable() {
		self::guard( 'nry_page_editable' );
		$id   = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page = $id ? get_post( $id ) : null;
		$mid  = $page ? get_post_meta( $id, '_nry_market', true ) : '';
		if ( ! $page || ! $mid || ! NRY_Markets::get( $mid ) || ! current_user_can( 'edit_post', $id ) ) {
			self::back( 'pages', 'error', 'برگه پیدا نشد.' );
		}
		wp_update_post( array( 'ID' => $id, 'post_content' => NRY_Pages::editable_content( $mid, $page->post_title ) ) );
		self::back( 'pages', 'success', 'متن برگه‌ی «' . $page->post_title . '» حالا در ویرایشگر وردپرس قابل ویرایش است.' );
	}

	/**
	 * Tag picker: a text box with suggestions loaded while typing.
	 * A full dropdown would list every tag of the site, which on a news site is tens of thousands.
	 */
	private static function tag_field( $name, $id, $term_id ) {
		static $printed = false;
		$term = $term_id ? get_term( (int) $term_id, 'post_tag' ) : null;
		$val  = $term && ! is_wp_error( $term ) ? $term->name : '';
		echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text" list="nry-tag-list" autocomplete="off" placeholder="نام برچسب را بنویسید…" data-nry-tag>';
		if ( $printed ) {
			return;
		}
		$printed = true;
		// Plain URL for script use (wp_nonce_url() would encode & as &amp;).
		$url     = admin_url( 'admin-ajax.php?action=nry_tags&_wpnonce=' . wp_create_nonce( 'nry_tags' ) );
		echo '<datalist id="nry-tag-list"></datalist>';
		echo "<script>(function(){var list=document.getElementById('nry-tag-list'),t=null,url=" . wp_json_encode( $url ) . ";document.addEventListener('input',function(e){var f=e.target;if(!f.hasAttribute||!f.hasAttribute('data-nry-tag')){return;}clearTimeout(t);var q=f.value.trim();if(q.length<2){return;}t=setTimeout(function(){fetch(url+'&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(names){list.innerHTML='';(names||[]).forEach(function(n){var o=document.createElement('option');o.value=n;list.appendChild(o);});}).catch(function(){});},250);});})();</script>";
	}

	/** Up to 20 tag names matching what was typed. */
	public static function tag_search() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'nry_tags', '_wpnonce', false ) ) {
			wp_send_json( array() );
		}
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$names = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'search' => $q, 'number' => 20, 'fields' => 'names', 'orderby' => 'count', 'order' => 'DESC' ) );
		wp_send_json( is_wp_error( $names ) ? array() : array_values( $names ) );
	}

	/** Tag typed by name (or slug, or ID) to its ID; 0 when no such tag exists. */
	private static function tag_id( $text ) {
		$text = trim( sanitize_text_field( (string) $text ) );
		if ( '' === $text ) {
			return 0;
		}
		if ( ctype_digit( $text ) && get_term( (int) $text, 'post_tag' ) ) {
			return (int) $text;
		}
		foreach ( array( 'name', 'slug' ) as $by ) {
			$term = get_term_by( $by, 'name' === $by ? $text : sanitize_title( $text ), 'post_tag' );
			if ( $term ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/* ---------- screens ---------- */

	public static function screen() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connect';
		}
		echo '<div class="wrap"><h1 style="padding:4px 0 8px"><img src="' . esc_url( NRY_URL . 'assets/logo.png' ) . '" alt="رخداد نرخ" width="158" height="56" style="height:56px;width:auto;vertical-align:middle"></h1>';
		$notice = get_transient( 'nry_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'nry_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function form_open( $tab ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nry_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'nry_save' );
	}

	private static function tab_connect() {
		$s    = nry_settings();
		$snap = get_option( 'nry_prices', array() );
		$err  = get_option( 'nry_last_error', array() );
		$n    = isset( $snap['items'] ) ? count( $snap['items'] ) : 0;

		self::form_open( 'connect' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="nry-plan">نوع وب‌سرویس BrsApi</label></th><td><select id="nry-plan" name="plan"><option value="free"' . selected( 'free', $s['plan'], false ) . '>رایگان (Gold_Currency)</option><option value="pro"' . selected( 'pro', $s['plan'], false ) . '>حرفه‌ای (Gold_Currency_Pro)</option></select><p class="description">با کلید رایگان، «رایگان» را انتخاب کنید. در این حالت نمودار و کمترین/بیشترین روز از قیمت‌هایی ساخته می‌شود که خود افزونه از لحظه‌ی نصب ثبت می‌کند.</p></td></tr>';
		echo '<tr><th scope="row"><label for="nry-key">کلید API</label></th><td><input id="nry-key" name="api_key" type="password" class="regular-text" dir="ltr" autocomplete="off" value="' . esc_attr( $s['api_key'] ) . '"><p class="description">کلید فقط در پایگاه داده‌ی همین سایت ذخیره می‌شود و در خروجی صفحه‌ها نمی‌آید.</p></td></tr>';
		echo '<tr><th scope="row"><label for="nry-int">فاصله‌ی به‌روزرسانی</label></th><td><input id="nry-int" name="interval" type="number" min="1" max="60" class="small-text" value="' . esc_attr( $s['interval'] ) . '"> دقیقه</td></tr>';
		echo '<tr><th scope="row">واحد نمایش</th><td><label><input type="checkbox" name="toman" value="1"' . checked( 1, (int) $s['toman'], false ) . '> قیمت‌های ریالی به تومان تبدیل شوند</label></td></tr>';
		echo '</table>';
		submit_button( 'ذخیره' );
		echo '</form>';

		echo '<h2>وضعیت داده</h2><p>';
		if ( $n ) {
			echo 'آخرین دریافت: <strong>' . esc_html( nry_fa( wp_date( 'Y/m/d H:i', (int) $snap['fetched'] ) ) ) . '</strong> — ' . esc_html( nry_fa( $n ) ) . ' نماد.';
		} else {
			echo 'هنوز قیمتی دریافت نشده است.';
		}
		echo '</p>';
		if ( $n ) {
			$main = NRY_Markets::get( 'gold' );
			$days = $main ? NRY_History::days( $main['main'] ) : 0;
			echo '<p>تاریخچه‌ی ثبت‌شده در سایت: <strong>' . esc_html( nry_fa( $days ) ) . ' روز</strong>' . ( NRY_Api::is_pro() ? ' (در نسخه‌ی حرفه‌ای، نمودار در اصل از خود وب‌سرویس خوانده می‌شود).' : '. نمودار هفتگی، ماهانه و سالانه به‌مرور با همین داده پر می‌شود.' ) . '</p>';
		}
		if ( ! empty( $err['message'] ) ) {
			echo '<div class="notice notice-error inline"><p>آخرین خطا (' . esc_html( nry_fa( wp_date( 'H:i', (int) $err['time'] ) ) ) . '): ' . esc_html( $err['message'] ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_test">';
		wp_nonce_field( 'nry_test' );
		submit_button( 'تست اتصال و دریافت قیمت‌ها', 'secondary', 'submit', false );
		echo '</form>';

		if ( $n ) {
			echo '<h2>نمادهای دریافت‌شده</h2><p class="description">ستون «نماد» همان چیزی است که در شورت‌کدها و برگه‌ی «نمادها» می‌نویسید.</p>';
			echo '<div style="max-height:420px;overflow:auto;border:1px solid #c3c4c7"><table class="widefat striped"><thead><tr><th>نماد</th><th>نام</th><th>قیمت</th><th>واحد</th><th>بخش</th></tr></thead><tbody>';
			foreach ( $snap['items'] as $item ) {
				echo '<tr><td dir="ltr" style="text-align:right"><code>' . esc_html( $item['symbol'] ) . '</code></td><td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( nry_num( $item['price'] ) ) . '</td><td>' . esc_html( $item['unit'] ) . '</td><td>' . esc_html( $item['section'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	private static function tab_pages() {
		echo '<h2>ساخت صفحه‌ی مستقل</h2><p>یک برگه‌ی واقعی وردپرس با قالب تمام‌عرض رخداد نرخ ساخته می‌شود. نامک می‌تواند لاتین یا فارسی باشد و بعداً از ویرایشگر برگه قابل تغییر است.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_create_page">';
		wp_nonce_field( 'nry_create_page' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="nry-m">بازار</label></th><td><select id="nry-m" name="market">';
		foreach ( NRY_Markets::all() as $id => $m ) {
			echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $m['title'] ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="nry-t">عنوان برگه</label></th><td><input id="nry-t" name="title" type="text" class="regular-text" placeholder="قیمت طلا امروز"><p class="description">خالی بماند، عنوان پیش‌فرض بازار استفاده می‌شود.</p></td></tr>';
		echo '<tr><th scope="row"><label for="nry-s">نامک (آدرس)</label></th><td><code>' . esc_html( urldecode( home_url( '/' ) ) ) . '</code> <input id="nry-s" name="slug" type="text" class="regular-text" placeholder="gold-price یا قیمت-طلا"></td></tr>';
		echo '<tr><th scope="row"><label for="nry-st">وضعیت</label></th><td><select id="nry-st" name="status"><option value="draft">پیش‌نویس</option><option value="publish">منتشرشده</option></select></td></tr>';
		echo '</table>';
		submit_button( 'ساخت صفحه' );
		echo '</form>';

		$pages = NRY_Pages::landing_pages();
		echo '<h2>صفحه‌های ساخته‌شده</h2>';
		if ( ! $pages ) {
			echo '<p>هنوز صفحه‌ای ساخته نشده است.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>عنوان</th><th>آدرس</th><th>وضعیت</th><th></th></tr></thead><tbody>';
		foreach ( $pages as $p ) {
			$st = get_post_status_object( $p->post_status );
			echo '<tr><td>' . esc_html( get_the_title( $p ) ) . '</td><td dir="ltr" style="text-align:right">' . esc_html( urldecode( get_permalink( $p ) ) ) . '</td><td>' . esc_html( $st ? $st->label : $p->post_status ) . '</td><td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">ویرایش</a> | <a href="' . esc_url( get_permalink( $p ) ) . '" target="_blank" rel="noopener">نمایش</a>';
			if ( NRY_Pages::is_fixed( $p ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px"><input type="hidden" name="action" value="nry_page_editable"><input type="hidden" name="page_id" value="' . (int) $p->ID . '">';
				wp_nonce_field( 'nry_page_editable' );
				submit_button( 'متن صفحه را قابل ویرایش کن', 'small', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">در صفحه‌های ساخته‌شده، عنوان، توضیح زیر عنوان، متن راهنما و پرسش‌ها داخل خود برگه‌اند و در ویرایشگر وردپرس ویرایش می‌شوند: عنوان و توضیح در شورت‌کد <code dir="ltr">[gn_landing title="…" lead="…"]</code>، متن و پرسش‌ها بین آن و <code dir="ltr">[/gn_landing]</code>. هر شورت‌کد دیگری هم آنجا کار می‌کند. کارت، تابلو، نمودار و جدول‌ها از داده‌ی زنده ساخته می‌شوند.</p>';
	}

	private static function tab_inject() {
		$rules   = array_values( (array) nry_opt( 'inject', array() ) );
		$rules[] = array( 'on' => 1, 'where' => 'page', 'id' => 0, 'pos' => 'before', 'para' => 1, 'code' => '', 'blank' => 1 );
		echo '<p>ماژول‌ها را بدون ساخت صفحه‌ی جدید، داخل برگه‌ها یا نوشته‌های موجود نشان دهید. هر تعداد قانون که بخواهید؛ آخرین کادر همیشه برای قانون تازه خالی است.</p>';
		self::form_open( 'inject' );
		foreach ( $rules as $i => $r ) {
			$r     = array_merge( array( 'on' => 0, 'where' => 'page', 'id' => 0, 'pos' => 'before', 'para' => 1, 'code' => '' ), $r );
			$n     = 'rule[' . (int) $i . ']';
			$blank = ! empty( $r['blank'] );
			$id    = (int) $r['id'];
			$show  = function ( $targets ) use ( $r ) {
				// Correct fields are visible from the start, even before the page script runs.
				return in_array( $r['where'], explode( ' ', $targets ), true ) ? '' : ' style="display:none"';
			};
			echo '<div class="nry-rule" data-nry-rule><h2>' . ( $blank ? 'قانون جدید' : 'قانون ' . esc_html( nry_fa( $i + 1 ) ) ) . '</h2>' . ( $blank ? '<input type="hidden" name="' . esc_attr( $n ) . '[blank]" value="1">' : '' );
			if ( ! $blank && '' === trim( $r['code'] ) ) {
				echo '<div class="notice notice-warning inline"><p>این قانون هنوز چیزی برای نمایش ندارد.</p></div>';
			}
			echo '<table class="form-table" role="presentation">';
			echo '<tr><th scope="row">وضعیت</th><td><label><input type="checkbox" name="' . esc_attr( $n ) . '[on]" value="1"' . checked( 1, (int) $r['on'], false ) . '> فعال</label>';
			if ( ! $blank ) {
				echo ' &nbsp; <label><input type="checkbox" name="' . esc_attr( $n ) . '[remove]" value="1"> حذف این قانون</label>';
			}
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="nry-w' . (int) $i . '">کجا نمایش داده شود</label></th><td><select id="nry-w' . (int) $i . '" name="' . esc_attr( $n ) . '[where]" data-nry-where>';
			foreach ( array( 'page' => 'یک برگه', 'cat' => 'یک دسته', 'tag' => 'یک برچسب', 'id' => 'یک نوشته‌ی مشخص', 'posts' => 'همه‌ی نوشته‌ها' ) as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $r['where'], false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
			echo '<span data-nry-target="page"' . $show( 'page' ) . '><label class="screen-reader-text" for="nry-pg' . (int) $i . '">برگه</label>';
			wp_dropdown_pages( array( 'name' => $n . '[page_id]', 'id' => 'nry-pg' . (int) $i, 'selected' => 'page' === $r['where'] ? $id : 0, 'show_option_none' => '— انتخاب برگه —', 'option_none_value' => 0, 'post_status' => array( 'publish', 'draft', 'private' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</span>';
			echo '<span data-nry-target="cat"' . $show( 'cat' ) . '><label class="screen-reader-text" for="nry-ct' . (int) $i . '">دسته</label>';
			wp_dropdown_categories( array( 'name' => $n . '[cat_id]', 'id' => 'nry-ct' . (int) $i, 'hide_empty' => 0, 'hierarchical' => 1, 'selected' => 'cat' === $r['where'] ? $id : 0, 'show_option_none' => '— انتخاب دسته —', 'option_none_value' => 0 ) );
			echo '</span>';
			echo '<span data-nry-target="tag"' . $show( 'tag' ) . '><label class="screen-reader-text" for="nry-tg' . (int) $i . '">برچسب</label>';
			self::tag_field( $n . '[tag_name]', 'nry-tg' . (int) $i, 'tag' === $r['where'] ? $id : 0 );
			echo '</span>';
			echo '<span data-nry-target="cat tag"' . $show( 'cat tag' ) . '> <label class="screen-reader-text" for="nry-sc' . (int) $i . '">در کجای دسته یا برچسب</label><select id="nry-sc' . (int) $i . '" name="' . esc_attr( $n ) . '[scope]">';
			foreach ( array( 'posts' => 'در نوشته‌های آن', 'archive' => 'در صفحه‌ی آرشیو آن', 'both' => 'هم نوشته‌ها و هم صفحه‌ی آرشیو' ) as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, isset( $r['scope'] ) ? $r['scope'] : 'posts', false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></span>';
			echo '<span data-nry-target="id"' . $show( 'id' ) . '><label>شناسه‌ی نوشته: <input type="number" min="0" class="small-text" name="' . esc_attr( $n ) . '[post_id]" value="' . esc_attr( 'id' === $r['where'] ? $id : '' ) . '"></label>';
			if ( 'id' === $r['where'] && $id && get_post( $id ) ) {
				echo ' <span class="description">' . esc_html( get_the_title( $id ) ) . '</span>';
			}
			echo '</span></td></tr>';

			echo '<tr><th scope="row"><label for="nry-p' . (int) $i . '">جایگاه</label></th><td><select id="nry-p' . (int) $i . '" name="' . esc_attr( $n ) . '[pos]">';
			foreach ( array( 'before' => 'قبل از محتوا', 'after' => 'بعد از محتوا', 'para' => 'بعد از پاراگراف شماره‌ی…', 'desc' => 'داخل توضیح آرشیو (فقط صفحه‌ی آرشیو)' ) as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $r['pos'], false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> <label>پاراگراف: <input type="number" min="1" class="small-text" name="' . esc_attr( $n ) . '[para]" value="' . esc_attr( $r['para'] ) . '"></label>';
			echo '<p class="description">در صفحه‌ی آرشیو دسته یا برچسب: «قبل از محتوا» یعنی بالای فهرست نوشته‌ها و «بعد از محتوا» زیر آن. اگر قالب سایت فهرست را جور دیگری می‌سازد، «داخل توضیح آرشیو» را امتحان کنید.</p></td></tr>';

			echo '<tr><th scope="row"><label for="nry-c' . (int) $i . '">چه چیزی نمایش داده شود</label></th><td>';
			echo '<label class="screen-reader-text" for="nry-add' . (int) $i . '">افزودن ماژول</label><select id="nry-add' . (int) $i . '" name="' . esc_attr( $n ) . '[add]" data-nry-add><option value="">افزودن ماژول…</option>';
			foreach ( NRY_Markets::all() as $mid => $m ) {
				echo '<optgroup label="' . esc_attr( $m['title'] ) . '">';
				foreach ( array( 'price_card market="%s" link="auto"' => 'کارت قیمت', 'board market="%s"' => 'تابلوی چهار شاخص', 'table market="%s"' => 'جدول‌ها', 'chart market="%s"' => 'نمودار', 'posts market="%s" count="5"' => 'آخرین مطالب دسته یا برچسب بازار', 'landing market="%s"' => 'کل لندینگ' ) as $code => $label ) {
					echo '<option value="' . esc_attr( '[gn_' . sprintf( $code, $mid ) . ']' ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '</select><br><textarea id="nry-c' . (int) $i . '" name="' . esc_attr( $n ) . '[code]" rows="3" class="large-text code" dir="ltr" style="margin-top:6px">' . esc_textarea( $r['code'] ) . '</textarea>';
			echo '<p class="description">از فهرست بالا انتخاب کنید یا شورت‌کد را دستی بنویسید.</p></td></tr>';
			echo '<tr data-nry-posts><th scope="row"><label for="nry-pt' . (int) $i . '">آخرین مطالب</label></th><td><select id="nry-pt' . (int) $i . '" name="' . esc_attr( $n ) . '[posts_type]" data-nry-ptype><option value="">— افزوده نشود —</option><option value="tag">از یک برچسب</option><option value="cat">از یک دسته</option></select> ';
			echo '<span data-nry-pv="tag" style="display:none">';
			self::tag_field( $n . '[posts_tag]', 'nry-ptg' . (int) $i, 0 );
			echo '</span><span data-nry-pv="cat" style="display:none">';
			wp_dropdown_categories( array( 'name' => $n . '[posts_cat]', 'id' => 'nry-pc' . (int) $i, 'hide_empty' => 0, 'hierarchical' => 1, 'show_option_none' => '— انتخاب دسته —', 'option_none_value' => 0 ) );
			echo '</span> <label>تعداد: <select name="' . esc_attr( $n ) . '[posts_count]"><option value="5">۵</option><option value="10">۱۰</option></select></label>';
			echo '<p class="description">فهرست آخرین نوشته‌های آن برچسب یا دسته، همین‌جا و در همان جایگاه نمایش داده می‌شود. بعد از ذخیره به شکل <code dir="ltr">[gn_posts …]</code> در کادر بالا می‌آید و می‌توانید جابه‌جا یا حذفش کنید.</p></td></tr>';
			echo '</table></div>';
		}
		submit_button( 'ذخیره' );
		echo '</form>';
		?>
		<script>
		document.querySelectorAll('[data-nry-rule]').forEach(function (rule) {
			var where = rule.querySelector('[data-nry-where]');
			var sync = function () {
				rule.querySelectorAll('[data-nry-target]').forEach(function (t) {
					t.style.display = t.getAttribute('data-nry-target').split(' ').indexOf(where.value) > -1 ? '' : 'none';
				});
			};
			where.addEventListener('change', sync);
			sync();
			var pt = rule.querySelector('[data-nry-ptype]');
			var psync = function () {
				rule.querySelectorAll('[data-nry-pv]').forEach(function (e) { e.style.display = e.getAttribute('data-nry-pv') === pt.value ? '' : 'none'; });
			};
			pt.addEventListener('change', psync);
			psync();
			var add = rule.querySelector('[data-nry-add]'), code = rule.querySelector('textarea');
			add.addEventListener('change', function () {
				if (add.value) { code.value = (code.value.trim() ? code.value.trim() + '\n' : '') + add.value; add.value = ''; }
			});
		});
		</script>
		<?php
	}

	public static function market_save() {
		self::guard( 'nry_market_save' );
		$id     = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$is_new = ! empty( $_POST['is_new'] );
		$all    = NRY_Markets::all();
		if ( '' === $id || ( $is_new && isset( $all[ $id ] ) ) || ( ! $is_new && ! isset( $all[ $id ] ) ) ) {
			self::back( 'markets', 'error', $is_new ? 'شناسه خالی است یا قبلاً استفاده شده. فقط حروف لاتین کوچک، عدد و خط تیره.' : 'بازار پیدا نشد.' );
		}
		$f = isset( $_POST['m'] ) && is_array( $_POST['m'] ) ? wp_unslash( $_POST['m'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$t = function ( $k ) use ( $f ) {
			return isset( $f[ $k ] ) ? sanitize_text_field( $f[ $k ] ) : '';
		};
		$o = array();
		foreach ( array( 'label', 'title', 'hl', 'kicker', 'lead', 'main', 'main_label', 'chart_title', 'guide_title', 'faq_title' ) as $k ) {
			$o[ $k ] = $t( $k );
		}
		if ( '' === $o['title'] || '' === $o['main'] ) {
			self::back( 'markets', 'error', 'عنوان صفحه و نماد اصلی لازم است.' );
		}
		$o['slug']  = sanitize_title( $t( 'slug' ) );
		$o['stamp'] = array( $t( 'stamp1' ), $t( 'stamp2' ) );
		$o['board'] = NRY_Markets::split( $t( 'board' ) );
		$o['calc']  = in_array( $t( 'calc' ), array( 'gold', 'fx' ), true ) ? $t( 'calc' ) : '';
		$o['guide'] = isset( $f['guide'] ) ? wp_kses_post( $f['guide'] ) : '';
		$o['faq']   = NRY_Markets::parse_faq( isset( $f['faq'] ) ? sanitize_textarea_field( $f['faq'] ) : '' );
		$tables     = array();
		$rows       = isset( $f['tables'] ) && is_array( $f['tables'] ) ? $f['tables'] : array();
		foreach ( array_slice( $rows, 0, 4 ) as $i => $row ) {
			$symbols = NRY_Markets::split( isset( $row['symbols'] ) ? sanitize_text_field( $row['symbols'] ) : '' );
			if ( $symbols ) {
				$tables[ 't' . (int) $i ] = array(
					'title'   => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
					'head'    => isset( $row['head'] ) ? sanitize_text_field( $row['head'] ) : '',
					'symbols' => $symbols,
				);
			}
		}
		$o['tables'] = $tables;
		$o['posts_tax']   = in_array( $t( 'posts_tax' ), array( 'category', 'post_tag' ), true ) ? $t( 'posts_tax' ) : '';
		$o['posts_term']  = 'category' === $o['posts_tax'] ? absint( $t( 'posts_cat' ) ) : ( 'post_tag' === $o['posts_tax'] ? self::tag_id( $t( 'posts_tag_name' ) ) : 0 );
		$o['posts_count'] = min( 20, max( 1, absint( $t( 'posts_count' ) ) ) );
		$o['posts_title'] = $t( 'posts_title' );
		if ( '' === $o['label'] ) {
			$o['label'] = $o['title'];
		}
		if ( ! NRY_Markets::is_builtin( $id ) ) {
			$o['custom'] = 1;
		}
		$saved = get_option( 'nry_settings', array() );
		$s     = is_array( $saved ) ? $saved : array();
		if ( ! isset( $s['markets'] ) || ! is_array( $s['markets'] ) ) {
			$s['markets'] = array();
		}
		$s['markets'][ $id ] = $o;
		unset( $s['symbols'][ $id ] );
		update_option( 'nry_settings', $s );
		self::back( 'markets', 'success', 'بازار «' . $o['title'] . '» ذخیره شد.' );
	}

	/** Custom market: delete. Built-in market: drop the edits and return to defaults. */
	public static function market_delete() {
		self::guard( 'nry_market_delete' );
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$saved = get_option( 'nry_settings', array() );
		$s     = is_array( $saved ) ? $saved : array();
		unset( $s['markets'][ $id ], $s['symbols'][ $id ] );
		update_option( 'nry_settings', $s );
		self::back( 'markets', 'success', NRY_Markets::is_builtin( $id ) ? 'بازار به حالت پیش‌فرض برگشت.' : 'بازار حذف شد. برگه‌ی آن، اگر ساخته شده، دست نخورده است.' );
	}

	private static function tab_markets() {
		$edit = isset( $_GET['market'] ) ? sanitize_key( wp_unslash( $_GET['market'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $edit ) {
			self::market_form( $edit );
			return;
		}
		$saved = (array) nry_opt( 'markets', array() );
		echo '<p>هر بازار یک صفحه‌ی قیمت است: یک نماد اصلی برای کارت، چهار نماد برای تابلو، چند جدول، و متن راهنما و پرسش‌ها. بازارهای پیش‌فرض را می‌توانید ویرایش کنید یا بازار تازه بسازید.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'markets' ) . '&market=new' ) . '">بازار جدید</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>بازار</th><th>شناسه</th><th>نوع</th><th>نماد اصلی</th><th>صفحه</th><th></th></tr></thead><tbody>';
		foreach ( NRY_Markets::all() as $id => $m ) {
			$item = NRY_Api::one( $m['main'] );
			$url  = NRY_Pages::landing_url( $id );
			$type = ! NRY_Markets::is_builtin( $id ) ? 'سفارشی' : ( isset( $saved[ $id ] ) ? 'پیش‌فرض، ویرایش‌شده' : 'پیش‌فرض' );
			echo '<tr><td><strong>' . esc_html( $m['title'] ) . '</strong></td><td><code dir="ltr">' . esc_html( $id ) . '</code></td><td>' . esc_html( $type ) . '</td>';
			echo '<td><code dir="ltr">' . esc_html( $m['main'] ) . '</code> ' . ( $item ? '<span style="color:#007017">پیدا شد</span>' : '<span style="color:#b32d2e">در داده‌ها نیست</span>' ) . '</td>';
			echo '<td>' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">نمایش</a>' : '<a href="' . esc_url( self::url( 'pages' ) ) . '">ساخت صفحه</a>' ) . '</td>';
			echo '<td><a href="' . esc_url( self::url( 'markets' ) . '&market=' . $id ) . '">ویرایش</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function market_form( $id ) {
		$new = 'new' === $id;
		$m   = $new ? NRY_Markets::skeleton() : NRY_Markets::get( $id );
		if ( ! $m ) {
			echo '<p>بازار پیدا نشد.</p>';
			return;
		}
		$text = function ( $key, $label, $value, $hint = '', $ltr = false ) {
			$fid = 'nry-mf-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $fid ) . '" type="text" class="large-text"' . ( $ltr ? ' dir="ltr"' : '' ) . ' name="m[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">' . ( $hint ? '<p class="description">' . wp_kses( $hint, array( 'code' => array( 'dir' => array() ) ) ) . '</p>' : '' ) . '</td></tr>';
		};

		echo '<p><a href="' . esc_url( self::url( 'markets' ) ) . '">→ همه‌ی بازارها</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_market_save">';
		wp_nonce_field( 'nry_market_save' );
		if ( $new ) {
			echo '<input type="hidden" name="is_new" value="1">';
		} else {
			echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		}

		echo '<h2>' . ( $new ? 'بازار جدید' : 'ویرایش: ' . esc_html( $m['title'] ) ) . '</h2><table class="form-table" role="presentation">';
		if ( $new ) {
			echo '<tr><th scope="row"><label for="nry-mf-id">شناسه</label></th><td><input id="nry-mf-id" type="text" class="regular-text" dir="ltr" name="id" placeholder="silver"><p class="description">حروف لاتین کوچک، عدد و خط تیره. در شورت‌کد استفاده می‌شود: <code dir="ltr">[gn_landing market="silver"]</code></p></td></tr>';
		}
		$text( 'title', 'عنوان صفحه', $m['title'], 'مثلاً: قیمت نقره امروز' );
		$text( 'hl', 'کلمه‌ی برجسته در عنوان', $m['hl'], 'زیر این کلمه خط طلایی کشیده می‌شود. مثلاً: نقره' );
		$text( 'label', 'نام کوتاه بازار', $m['label'] );
		$text( 'kicker', 'سطر بالای عنوان', $m['kicker'], 'مثلاً: بازار نقره تهران' );
		$text( 'lead', 'توضیح زیر عنوان', $m['lead'] );
		$text( 'slug', 'نامک پیشنهادی صفحه', urldecode( $m['slug'] ), '', true );
		echo '</table>';

		echo '<h2>داده</h2><p class="description">نام نمادها را از برگه‌ی «اتصال و داده» بردارید. <code dir="ltr">~کلمه</code> یعنی همه‌ی ردیف‌هایی که نامشان آن کلمه‌ها را دارد؛ <code dir="ltr">@gold</code>، <code dir="ltr">@currency</code> یا <code dir="ltr">@cryptocurrency</code> یعنی همه‌ی ردیف‌های آن بخش.</p><table class="form-table" role="presentation">';
		$text( 'main', 'نماد اصلی (کارت قیمت و نمودار)', $m['main'], '', true );
		$text( 'main_label', 'برچسب روی کارت', $m['main_label'] );
		$text( 'stamp1', 'مُهر کارت، سطر اول', $m['stamp'][0], 'کوتاه؛ مثلاً ۷۵۰ یا USD' );
		$text( 'stamp2', 'مُهر کارت، سطر دوم', isset( $m['stamp'][1] ) ? $m['stamp'][1] : '' );
		$text( 'board', 'تابلوی چهار شاخص', implode( ', ', $m['board'] ), 'تا چهار نماد، با ویرگول.', true );
		$text( 'chart_title', 'عنوان نمودار', $new ? '' : $m['chart_title'] );
		echo '<tr><th scope="row"><label for="nry-mf-calc">ابزار کنار صفحه</label></th><td><select id="nry-mf-calc" name="m[calc]">';
		foreach ( array( '' => 'هیچ', 'gold' => 'ماشین‌حساب طلا', 'fx' => 'مبدل ارز' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $m['calc'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr></table>';

		echo '<h2>جدول‌ها</h2><table class="widefat"><thead><tr><th>عنوان جدول</th><th>سرستون اول</th><th>نمادها</th></tr></thead><tbody>';
		$tables = array_values( $m['tables'] );
		for ( $i = 0; $i < 4; $i++ ) {
			$t = isset( $tables[ $i ] ) ? $tables[ $i ] : array( 'title' => '', 'head' => '', 'symbols' => array() );
			echo '<tr><td><input type="text" class="large-text" aria-label="عنوان جدول ' . (int) ( $i + 1 ) . '" name="m[tables][' . (int) $i . '][title]" value="' . esc_attr( $t['title'] ) . '"></td>';
			echo '<td><input type="text" class="large-text" aria-label="سرستون جدول ' . (int) ( $i + 1 ) . '" name="m[tables][' . (int) $i . '][head]" value="' . esc_attr( $t['head'] ) . '"></td>';
			echo '<td><input type="text" class="large-text" dir="ltr" aria-label="نمادهای جدول ' . (int) ( $i + 1 ) . '" name="m[tables][' . (int) $i . '][symbols]" value="' . esc_attr( implode( ', ', $t['symbols'] ) ) . '"></td></tr>';
		}
		echo '</tbody></table><p class="description">ردیفی که نماد ندارد ذخیره نمی‌شود.</p>';

		echo '<h2>مطالب مرتبط</h2><p class="description">آخرین نوشته‌های یک دسته یا برچسب، زیر جدول‌های همین صفحه نشان داده می‌شود.</p><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="nry-mf-ptax">منبع</label></th><td><select id="nry-mf-ptax" name="m[posts_tax]" data-nry-ptax>';
		foreach ( array( '' => 'نمایش داده نشود', 'category' => 'یک دسته', 'post_tag' => 'یک برچسب' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $m['posts_tax'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <span data-nry-pt="category">';
		wp_dropdown_categories( array( 'name' => 'm[posts_cat]', 'id' => 'nry-mf-pcat', 'hide_empty' => 0, 'hierarchical' => 1, 'selected' => 'category' === $m['posts_tax'] ? (int) $m['posts_term'] : 0, 'show_option_none' => '— انتخاب دسته —', 'option_none_value' => 0 ) );
		echo '</span><span data-nry-pt="post_tag">';
		self::tag_field( 'm[posts_tag_name]', 'nry-mf-ptag', 'post_tag' === $m['posts_tax'] ? (int) $m['posts_term'] : 0 );
		echo '</span></td></tr>';
		echo '<tr><th scope="row"><label for="nry-mf-pcount">تعداد</label></th><td><select id="nry-mf-pcount" name="m[posts_count]">';
		foreach ( array( 5, 10 ) as $c ) {
			echo '<option value="' . (int) $c . '"' . selected( $c, (int) $m['posts_count'], false ) . '>' . esc_html( nry_fa( $c ) ) . ' مطلب آخر</option>';
		}
		echo '</select></td></tr>';
		$text( 'posts_title', 'تیتر بخش', $m['posts_title'], 'خالی بماند: «آخرین مطالب ' . esc_html( $m['label'] ) . '»' );
		echo '</table>';
		echo "<script>(function(){var s=document.querySelector('[data-nry-ptax]');if(!s){return;}var f=function(){document.querySelectorAll('[data-nry-pt]').forEach(function(e){e.style.display=e.getAttribute('data-nry-pt')===s.value?'':'none';});};s.addEventListener('change',f);f();})();</script>";

		echo '<h2>متن صفحه</h2><table class="form-table" role="presentation">';
		$text( 'guide_title', 'تیتر راهنما', $m['guide_title'] );
		echo '<tr><th scope="row"><label for="nry-mf-guide">متن راهنما</label></th><td><textarea id="nry-mf-guide" name="m[guide]" rows="9" class="large-text">' . esc_textarea( $m['guide'] ) . '</textarea><p class="description">HTML ساده: <code dir="ltr">&lt;p&gt;</code>، <code dir="ltr">&lt;h3&gt;</code>، <code dir="ltr">&lt;a&gt;</code>. این متن را مخصوص سایت خودتان بنویسید.</p></td></tr>';
		$text( 'faq_title', 'تیتر پرسش‌ها', $m['faq_title'] );
		echo '<tr><th scope="row"><label for="nry-mf-faq">پرسش‌ها</label></th><td><textarea id="nry-mf-faq" name="m[faq]" rows="6" class="large-text">' . esc_textarea( NRY_Markets::faq_text( $m['faq'] ) ) . '</textarea><p class="description">هر سطر یک پرسش: <code>پرسش | پاسخ</code>. اسکیمای FAQPage از همین‌ها ساخته می‌شود.</p></td></tr>';
		echo '</table>';
		submit_button( $new ? 'ساخت بازار' : 'ذخیره' );
		echo '</form>';

		$saved = (array) nry_opt( 'markets', array() );
		if ( ! $new && ( ! NRY_Markets::is_builtin( $id ) || isset( $saved[ $id ] ) ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_market_delete"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'nry_market_delete' );
			submit_button( NRY_Markets::is_builtin( $id ) ? 'بازگشت به متن و نمادهای پیش‌فرض' : 'حذف این بازار', 'delete', 'submit', false );
			echo '</form>';
		}
	}


	private static function tab_seo() {
		$s     = nry_settings();
		$saved = (array) $s['seo'];
		$with  = defined( 'WPSEO_VERSION' ) ? 'Yoast SEO' : ( class_exists( 'RankMath' ) ? 'Rank Math' : '' );
		echo '<p>' . ( $with ? 'افزونه‌ی ' . esc_html( $with ) . ' فعال است؛ عنوان و توضیح از طریق همان افزونه اعمال می‌شود و اسکیمای مسیر صفحه را هم خودش می‌سازد.' : 'افزونه‌ی سئو فعال نیست؛ رخداد نرخ عنوان، توضیح متا و اسکیمای مسیر صفحه را خودش می‌نویسد.' ) . ' اگر در کادر سئوی خودِ برگه عنوان یا توضیح دستی نوشته باشید، همان استفاده می‌شود.</p>';
		echo '<p class="description">متغیرها: <code>{price}</code> <code>{unit}</code> <code>{change}</code> <code>{date}</code> <code>{date_long}</code> <code>{weekday}</code> <code>{time}</code> <code>{name}</code> <code>{title}</code> <code>{site}</code></p>';
		self::form_open( 'seo' );
		echo '<p><label><input type="checkbox" name="seo_on" value="1"' . checked( 1, (int) $s['seo_on'], false ) . '> عنوان و توضیح صفحه‌های قیمت از الگوهای زیر ساخته شود</label></p>';
		foreach ( NRY_Markets::all() as $id => $m ) {
			$def = NRY_Seo::default_templates( $id );
			$cur = isset( $saved[ $id ] ) ? $saved[ $id ] : array();
			echo '<h2>' . esc_html( $m['title'] ) . '</h2><table class="form-table" role="presentation">';
			echo '<tr><th scope="row"><label for="nry-seo-t-' . esc_attr( $id ) . '">عنوان سئو</label></th><td><input id="nry-seo-t-' . esc_attr( $id ) . '" type="text" class="large-text" name="seo[' . esc_attr( $id ) . '][title]" value="' . esc_attr( isset( $cur['title'] ) ? $cur['title'] : '' ) . '" placeholder="' . esc_attr( $def['title'] ) . '"></td></tr>';
			echo '<tr><th scope="row"><label for="nry-seo-d-' . esc_attr( $id ) . '">توضیح متا</label></th><td><textarea id="nry-seo-d-' . esc_attr( $id ) . '" rows="2" class="large-text" name="seo[' . esc_attr( $id ) . '][desc]" placeholder="' . esc_attr( $def['desc'] ) . '">' . esc_textarea( isset( $cur['desc'] ) ? $cur['desc'] : '' ) . '</textarea>';
			echo '<p class="description">پیش‌نمایش: ' . esc_html( nry_fill( ! empty( $cur['desc'] ) ? $cur['desc'] : $def['desc'], NRY_Seo::vars( $id ) ) ) . '</p></td></tr>';
			echo '</table>';
		}
		submit_button( 'ذخیره' );
		echo '</form>';
	}

	private static function tab_auto() {
		$s     = nry_settings();
		$words = (array) $s['autolink_words'];
		self::form_open( 'auto' );

		echo '<h2>لینک‌ساز خودکار</h2><p>در هر نوشته، اولین جایی که یکی از این عبارت‌ها در متن بیاید به مقصد همان بازار لینک می‌شود؛ پیش‌فرض، صفحه‌ای است که افزونه ساخته، ولی می‌توانید دسته، برچسب یا برگه‌ی دیگری را مقصد کنید. این لینک‌ها پیش از لینک‌سازهای دیگر ساخته می‌شوند. تیترها و لینک‌های موجود دست نمی‌خورند و اگر نویسنده خودش به آن مقصد لینک داده باشد، لینک دوم ساخته نمی‌شود.</p>';
		echo '<p><label><input type="checkbox" name="autolink" value="1"' . checked( 1, (int) $s['autolink'], false ) . '> لینک‌ساز فعال باشد</label></p><table class="form-table" role="presentation">';
		$targets = (array) nry_opt( 'autolink_target', array() );
		foreach ( NRY_Markets::all() as $id => $m ) {
			$t  = isset( $targets[ $id ] ) && is_array( $targets[ $id ] ) ? array_merge( array( 'type' => 'page', 'id' => 0, 'url' => '' ), $targets[ $id ] ) : array( 'type' => 'page', 'id' => 0, 'url' => '' );
			$n  = 'target[' . $id . ']';
			$to = NRY_Autolink::target_url( $id );
			echo '<tr data-nry-link><th scope="row"><label for="nry-w-' . esc_attr( $id ) . '">عبارت‌های ' . esc_html( $m['label'] ) . '</label></th><td><input id="nry-w-' . esc_attr( $id ) . '" type="text" class="large-text" name="words[' . esc_attr( $id ) . ']" value="' . esc_attr( isset( $words[ $id ] ) ? $words[ $id ] : '' ) . '" placeholder="' . esc_attr( NRY_Autolink::default_words( $id ) ) . '">';
			echo '<p><label for="nry-lt-' . esc_attr( $id ) . '">مقصد لینک: </label><select id="nry-lt-' . esc_attr( $id ) . '" name="' . esc_attr( $n ) . '[type]" data-nry-lt>';
			foreach ( array( 'page' => 'صفحه‌ای که افزونه ساخته', 'cat' => 'یک دسته', 'tag' => 'یک برچسب', 'post' => 'یک برگه‌ی دیگر', 'url' => 'آدرس دلخواه' ) as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $t['type'], false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> <span data-nry-lv="cat">';
			wp_dropdown_categories( array( 'name' => $n . '[cat_id]', 'id' => 'nry-lc-' . $id, 'hide_empty' => 0, 'hierarchical' => 1, 'selected' => 'cat' === $t['type'] ? (int) $t['id'] : 0, 'show_option_none' => '— انتخاب دسته —', 'option_none_value' => 0 ) );
			echo '</span><span data-nry-lv="tag">';
			self::tag_field( $n . '[tag_name]', 'nry-lg-' . $id, 'tag' === $t['type'] ? (int) $t['id'] : 0 );
			echo '</span><span data-nry-lv="post">';
			wp_dropdown_pages( array( 'name' => $n . '[post_id]', 'id' => 'nry-lp-' . $id, 'selected' => 'post' === $t['type'] ? (int) $t['id'] : 0, 'show_option_none' => '— انتخاب برگه —', 'option_none_value' => 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</span><span data-nry-lv="url"><input type="url" class="regular-text" dir="ltr" name="' . esc_attr( $n ) . '[url]" value="' . esc_attr( $t['url'] ) . '" placeholder="https://"></span></p>';
			echo '<p class="description">' . ( $to ? 'لینک فعلی: <code dir="ltr">' . esc_html( urldecode( $to ) ) . '</code>' : 'هنوز مقصدی نیست؛ صفحه‌ی این بازار را بسازید یا مقصد دیگری انتخاب کنید.' ) . '</p></td></tr>';
		}
		echo '</table>';
		echo "<script>document.querySelectorAll('[data-nry-link]').forEach(function(r){var s=r.querySelector('[data-nry-lt]');var f=function(){r.querySelectorAll('[data-nry-lv]').forEach(function(e){e.style.display=e.getAttribute('data-nry-lv')===s.value?'':'none';});};s.addEventListener('change',f);f();});</script>";

		echo '<h2>پیش‌نویس روزانه</h2><p>هر روز یک نوشته‌ی پیش‌نویس با قیمت‌های همان روز ساخته می‌شود. عددها ثابت ذخیره می‌شوند و بعداً تغییر نمی‌کنند. در روزهایی که بازار بسته است و تاریخ قیمت عوض نشده، نوشته‌ی تکراری ساخته نمی‌شود.</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">وضعیت</th><td><label><input type="checkbox" name="daily_on" value="1"' . checked( 1, (int) $s['daily_on'], false ) . '> هر روز ساعت <input type="number" name="daily_hour" min="0" max="23" class="small-text" value="' . esc_attr( $s['daily_hour'] ) . '"> پیش‌نویس ساخته شود</label></td></tr>';
		echo '<tr><th scope="row">بازارها</th><td>';
		foreach ( NRY_Markets::all() as $id => $m ) {
			echo '<label style="margin-left:16px"><input type="checkbox" name="daily_markets[]" value="' . esc_attr( $id ) . '"' . checked( true, in_array( $id, (array) $s['daily_markets'], true ), false ) . '> ' . esc_html( $m['label'] ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="nry-dc">دسته</label></th><td>';
		wp_dropdown_categories( array( 'name' => 'daily_cat', 'id' => 'nry-dc', 'hide_empty' => 0, 'show_option_none' => '— پیش‌فرض سایت —', 'option_none_value' => 0, 'selected' => (int) $s['daily_cat'] ) );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="nry-dp">انتشار</label></th><td><select id="nry-dp" name="daily_publish">';
		foreach ( array( 'draft' => 'پیش‌نویس بماند تا خودم منتشر کنم', 'publish' => 'همان لحظه منتشر شود', 'schedule' => 'برای ساعت مشخصِ همان روز زمان‌بندی شود' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $s['daily_publish'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <label>ساعت انتشار: <input type="number" name="daily_publish_hour" min="0" max="23" class="small-text" value="' . esc_attr( $s['daily_publish_hour'] ) . '"></label><p class="description">در حالت زمان‌بندی، اگر ساعت انتشار گذشته باشد نوشته همان لحظه منتشر می‌شود.</p></td></tr>';
		echo '<tr><th scope="row">خلاصه‌ی خودکار</th><td><label><input type="checkbox" name="daily_analysis" value="1"' . checked( 1, (int) $s['daily_analysis'], false ) . '> ابتدای نوشته، خلاصه‌ای از روی داده‌ها نوشته شود</label><p class="description">تغییر امروز، روند هفته، جایگاه قیمت در ۳۰ روز گذشته، قیمت‌های مرتبط و بیشترین تغییر جدول. فقط آنچه عددها نشان می‌دهند؛ علت تغییر را خودتان اضافه کنید.</p></td></tr>';
		$titles = NRY_Daily::titles();
		echo '<tr><th scope="row"><label for="nry-dt">الگوهای عنوان</label></th><td><textarea id="nry-dt" name="daily_titles" rows="5" class="large-text">' . esc_textarea( $s['daily_titles'] ) . '</textarea><p class="description">هر سطر یک الگو؛ هر روز الگوی بعدی استفاده می‌شود تا عنوان دو روز پشت‌سرهم شبیه نباشد. خالی بماند، این الگوها استفاده می‌شود:</p><ol style="margin:4px 20px 0 0">';
		foreach ( $titles as $tpl ) {
			echo '<li>' . esc_html( nry_fill( $tpl, NRY_Seo::vars( 'gold' ) ) ) . '</li>';
		}
		echo '</ol><p class="description">متغیرهای اضافه: <code dir="ltr">{label}</code> نام کوتاه بازار، <code dir="ltr">{move}</code> افزایش/کاهش/ثبات.</p></td></tr>';
		$dtags = (array) nry_opt( 'daily_tags', array() );
		$dex   = (array) nry_opt( 'daily_excerpts', array() );
		echo '<tr><th scope="row">برچسب‌های ثابت</th><td>';
		foreach ( NRY_Markets::all() as $id => $m ) {
			echo '<p><label for="nry-dtag-' . esc_attr( $id ) . '" style="display:inline-block;min-width:110px">' . esc_html( $m['label'] ) . '</label> <input id="nry-dtag-' . esc_attr( $id ) . '" type="text" class="regular-text" name="daily_tags[' . esc_attr( $id ) . ']" value="' . esc_attr( isset( $dtags[ $id ] ) ? $dtags[ $id ] : '' ) . '" placeholder="قیمت طلا، قیمت سکه، بازار طلا"></p>';
		}
		echo '<p class="description">این برچسب‌ها به هر نوشته‌ی روزانه‌ی همان بازار زده می‌شوند. چند برچسب را با ویرگول جدا کنید؛ برچسبی که هنوز در سایت نیست ساخته می‌شود.</p></td></tr>';
		$exdefs = NRY_Daily::default_excerpts();
		echo '<tr><th scope="row">چکیده‌ها</th><td><p class="description" style="margin-top:0">هر نوشته‌ی روزانه یک چکیده می‌گیرد که به‌صورت تصادفی از این فهرست انتخاب می‌شود و هیچ‌وقت با چکیده‌ی نوشته‌ی قبلی همان بازار یکی نیست. هر سطر یک چکیده (تا ۱۰ سطر)؛ متغیرهای عنوان اینجا هم کار می‌کنند. خالی بماند، پنج چکیده‌ی پیش‌فرض (متن کم‌رنگ) استفاده می‌شود.</p>';
		foreach ( NRY_Markets::all() as $id => $m ) {
			echo '<p><label for="nry-dex-' . esc_attr( $id ) . '"><strong>' . esc_html( $m['label'] ) . '</strong></label><br><textarea id="nry-dex-' . esc_attr( $id ) . '" name="daily_excerpts[' . esc_attr( $id ) . ']" rows="5" class="large-text" placeholder="' . esc_attr( implode( "\n", $exdefs ) ) . '">' . esc_textarea( isset( $dex[ $id ] ) ? implode( "\n", (array) $dex[ $id ] ) : '' ) . '</textarea></p>';
		}
		echo '</td></tr>';
		$bodies = (array) nry_opt( 'daily_bodies', array() );
		$defs   = NRY_Daily::default_bodies();
		echo '<tr><th scope="row">الگوهای متن نوشته</th><td><p class="description" style="margin-top:0">برای هر بازار تا پنج الگو. هر روز الگوی بعدی استفاده می‌شود و جمله‌های خلاصه هم هر روز با یکی از پنج بیان متفاوت نوشته می‌شوند تا نوشته‌ها تکراری به نظر نرسند. خط خالی یعنی پاراگراف تازه. اگر برای بازاری هیچ الگویی ننویسید، پنج الگوی پیش‌فرض (متن کم‌رنگ داخل کادرها) استفاده می‌شود.</p>';
		echo '<p class="description">جمله‌ها: <code dir="ltr">{today}</code> تغییر امروز، <code dir="ltr">{week}</code> روند هفته، <code dir="ltr">{month}</code> جایگاه در ۳۰ روز، <code dir="ltr">{related}</code> قیمت‌های مرتبط، <code dir="ltr">{mover}</code> بیشترین تغییر جدول، <code dir="ltr">{more}</code> لینک به صفحه‌ی قیمت. <code dir="ltr">{tables}</code> روی یک خط جدا، جای جدول‌ها. متغیرهای عنوان هم اینجا کار می‌کنند. جمله‌ای که داده‌اش هنوز نیست، خودبه‌خود حذف می‌شود.</p>';
		foreach ( NRY_Markets::all() as $id => $m ) {
			$mine = isset( $bodies[ $id ] ) ? array_values( (array) $bodies[ $id ] ) : array();
			echo '<details style="margin:10px 0"' . ( $mine ? ' open' : '' ) . '><summary style="cursor:pointer;font-weight:600;min-height:32px">' . esc_html( $m['label'] ) . ( $mine ? ' — ' . esc_html( nry_fa( count( $mine ) ) ) . ' الگوی اختصاصی' : '' ) . '</summary>';
			for ( $i = 0; $i < 5; $i++ ) {
				$fid = 'nry-b-' . $id . '-' . $i;
				echo '<p><label for="' . esc_attr( $fid ) . '">الگوی ' . esc_html( nry_fa( $i + 1 ) ) . '</label><br><textarea id="' . esc_attr( $fid ) . '" name="bodies[' . esc_attr( $id ) . '][' . (int) $i . ']" rows="6" class="large-text" placeholder="' . esc_attr( $defs[ $i ] ) . '">' . esc_textarea( isset( $mine[ $i ] ) ? $mine[ $i ] : '' ) . '</textarea></p>';
			}
			echo '</details>';
		}
		echo '</td></tr>';
		echo '</table>';

		echo '<h2>تصویر روزانه</h2><p>یک تصویر ۱۲۰۰×۶۷۵ با قیمت روز ساخته می‌شود؛ تصویر شاخص پیش‌نویس روزانه و تصویر اشتراک‌گذاری صفحه‌ی قیمت (اگر خود برگه تصویر شاخص نداشته باشد).</p>';
		echo '<p><label><input type="checkbox" name="image_on" value="1"' . checked( 1, (int) $s['image_on'], false ) . '> تصویر روزانه ساخته شود</label></p>';
		submit_button( 'ذخیره' );
		echo '</form>';

		echo '<h2>فونت تصویر</h2>';
		if ( ! function_exists( 'imagettftext' ) ) {
			echo '<div class="notice notice-warning inline"><p>کتابخانه‌ی GD با پشتیبانی FreeType روی این سرور فعال نیست؛ تصویر ساخته نمی‌شود.</p></div>';
		}
		echo '<p>' . ( NRY_Image::font() ? 'فونت فعلی: <code dir="ltr">' . esc_html( basename( NRY_Image::font() ) ) . '</code>' : 'هنوز فونتی بارگذاری نشده است.' ) . ' یک فایل TTF فارسی (مثلاً فونت خود سایت) بارگذاری کنید.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_font">';
		wp_nonce_field( 'nry_font' );
		echo '<label for="nry-font" class="screen-reader-text">فایل فونت</label><input id="nry-font" type="file" name="font" accept=".ttf"> ';
		submit_button( 'بارگذاری فونت', 'secondary', 'submit', false );
		echo '</form>';
		foreach ( array_keys( NRY_Markets::all() ) as $id ) {
			$url = NRY_Image::latest_url( $id );
			if ( $url ) {
				echo '<p><img src="' . esc_url( $url ) . '" alt="" style="max-width:480px;height:auto;border:1px solid #c3c4c7"></p>';
			}
		}

		echo '<h2>اجرای دستی</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_daily_now">';
		wp_nonce_field( 'nry_daily_now' );
		submit_button( 'همین حالا تصویر و پیش‌نویس امروز را بساز', 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function tab_design() {
		$s = nry_settings();
		echo '<p>رنگ‌های ماژول‌ها در صفحه‌های سایت. خالی بماند، رنگ پیش‌فرض استفاده می‌شود.</p>';
		self::form_open( 'design' );
		echo '<table class="form-table" role="presentation">';
		foreach ( array( 'navy' => array( 'رنگ اصلی (تیتر، خط‌ها، ماشین‌حساب)', '#0a2463' ), 'gold' => array( 'رنگ تأکید (کارت قیمت، انتخاب فعال)', '#e3ae2b' ), 'red' => array( 'رنگ نشانه (خط کنار تیتر بخش)', '#d8232a' ) ) as $k => $f ) {
			$v = sanitize_hex_color( $s[ $k ] ) ? $s[ $k ] : $f[1];
			echo '<tr><th scope="row"><label for="nry-c-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td><input id="nry-c-' . esc_attr( $k ) . '" type="color" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" style="width:64px;height:36px;padding:2px;vertical-align:middle"> <code dir="ltr">' . esc_html( $v ) . '</code> <span class="description">پیش‌فرض: <code dir="ltr">' . esc_html( $f[1] ) . '</code></span></td></tr>';
		}
		echo '</table>';
		submit_button( 'ذخیره' );
		echo '</form>';
	}

	private static function tab_shortcodes() {
		$codes = array(
			'[gn_landing market="gold"]'                 => 'کل لندینگ یک بازار (gold یا dollar).',
			'[gn_price_card market="gold" link="auto"]'  => 'کارت زرد قیمت اصلی بازار، با لینک به لندینگ همان بازار.',
			'[gn_price_card symbol="USD" label="دلار"]' => 'کارت زرد برای هر نماد دلخواه.',
			'[gn_price symbol="USD"]'                    => 'فقط عدد زنده، برای وسط جمله. با unit="0" واحد حذف می‌شود؛ با field="pct" درصد تغییر می‌آید.',
			'[gn_board market="gold"]'                   => 'تابلوی چهار شاخص. یا: symbols="USD,EUR,AED,GBP"',
			'[gn_table market="gold" group="coin"]'      => 'یک جدول از بازار. بدون group همه‌ی جدول‌های بازار می‌آیند.',
			'[gn_table symbols="USD,EUR" title="ارزها"]' => 'جدول دلخواه از هر نماد.',
			'[gn_chart market="gold"]'                   => 'نمودار نماد اصلی بازار. یا: symbol="USD"',
			'[gn_calculator type="gold"]'                => 'ماشین‌حساب طلا. با type="fx" مبدل ارز.',
			'[gn_guide market="gold"]'                   => 'متن راهنمای بازار.',
			'[gn_faq market="gold"]'                     => 'پرسش‌های پرتکرار، همراه با اسکیمای FAQPage. با پرسش‌های خودتان: [gn_faq title="…"]پرسش | پاسخ[/gn_faq]، هر پرسش در یک سطر.',
			'[gn_posts market="gold" count="5"]'         => 'آخرین نوشته‌های دسته یا برچسبی که در تنظیمات بازار انتخاب شده. یا هر برچسب و دسته‌ی دلخواه: tag="قیمت طلا" یا cat="12"، با title="…" برای تیتر.',
			'[gn_landing market="gold" title="…" lead="…"]…[/gn_landing]' => 'لندینگ با عنوان و توضیح دلخواه؛ متنی که بین دو شورت‌کد بنویسید جای راهنما و پرسش‌های پیش‌فرض می‌آید.',
		);
		echo '<table class="widefat striped" style="margin-top:16px"><thead><tr><th>شورت‌کد</th><th>خروجی</th></tr></thead><tbody>';
		foreach ( $codes as $code => $desc ) {
			echo '<tr><td dir="ltr" style="text-align:right;white-space:nowrap"><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
