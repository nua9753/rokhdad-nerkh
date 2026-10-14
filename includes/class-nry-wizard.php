<?php
defined( 'ABSPATH' ) || exit;

/** Four-step setup: connect, build pages, switch on automation, done. */
class NRY_Wizard {

	const SLUG = 'rokhdad-nerkh-setup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_post_nry_wizard', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_submenu_page( NRY_Admin::SLUG, 'راه‌اندازی رخداد نرخ', 'راه‌اندازی', 'manage_options', self::SLUG, array( __CLASS__, 'screen' ) );
	}

	/** Open the wizard once, right after activation. */
	public static function maybe_redirect() {
		if ( ! get_option( 'nry_open_wizard' ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( 'nry_open_wizard' );
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_safe_redirect( self::url( 1 ) );
		exit;
	}

	private static function url( $step ) {
		return admin_url( 'admin.php?page=' . self::SLUG . '&step=' . (int) $step );
	}

	private static function go( $step, $error = '' ) {
		if ( '' !== $error ) {
			set_transient( 'nry_wizard_error_' . get_current_user_id(), $error, 60 );
		}
		wp_safe_redirect( self::url( $step ) );
		exit;
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'nry_wizard' );
		$step  = isset( $_POST['step'] ) ? (int) $_POST['step'] : 1;
		$saved = get_option( 'nry_settings', array() );
		$s     = is_array( $saved ) ? $saved : array();

		if ( 1 === $step ) {
			$s['plan']    = ( isset( $_POST['plan'] ) && 'pro' === $_POST['plan'] ) ? 'pro' : 'free';
			$s['api_key'] = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			update_option( 'nry_settings', $s );
			if ( '' === $s['api_key'] ) {
				self::go( 1, 'کلید API را وارد کنید.' );
			}
			$r = NRY_Api::refresh();
			if ( is_wp_error( $r ) ) {
				self::go( 1, 'اتصال برقرار نشد: ' . $r->get_error_message() );
			}
			NRY_Api::reschedule();
			self::go( 2 );
		}

		if ( 2 === $step ) {
			$raw = isset( $_POST['page'] ) && is_array( $_POST['page'] ) ? wp_unslash( $_POST['page'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( NRY_Markets::all() as $mid => $m ) {
				if ( empty( $raw[ $mid ]['on'] ) || NRY_Pages::landing_url( $mid ) ) {
					continue;
				}
				$slug = isset( $raw[ $mid ]['slug'] ) ? sanitize_text_field( $raw[ $mid ]['slug'] ) : '';
				$res  = NRY_Pages::create( $mid, '', $slug, 'publish' );
				if ( is_wp_error( $res ) ) {
					self::go( 2, 'ساخت صفحه‌ی «' . $m['title'] . '» ناموفق بود: ' . $res->get_error_message() );
				}
			}
			self::go( 3 );
		}

		if ( 3 === $step ) {
			$cat             = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
			$s['autolink']   = empty( $_POST['autolink'] ) ? 0 : 1;
			$s['daily_on']   = empty( $_POST['daily_on'] ) ? 0 : 1;
			$s['daily_cat']  = $cat;
			$s['daily_hour'] = min( 23, max( 0, isset( $_POST['daily_hour'] ) ? (int) $_POST['daily_hour'] : 11 ) );
			$s['image_on']   = empty( $_POST['image_on'] ) ? 0 : 1;
			if ( ! empty( $_POST['card_in_cat'] ) && $cat ) {
				$rules    = isset( $s['inject'] ) && is_array( $s['inject'] ) ? $s['inject'] : array();
				$rules[0] = array( 'on' => 1, 'where' => 'cat', 'id' => $cat, 'pos' => 'before', 'para' => 1, 'code' => '[gn_price_card market="gold" link="auto"]' );
				$s['inject'] = $rules;
			}
			update_option( 'nry_settings', $s );
			NRY_Daily::reschedule();
			self::go( 4 );
		}
		self::go( 1 );
	}

	public static function screen() {
		$step  = isset( $_GET['step'] ) ? min( 4, max( 1, (int) $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$error = get_transient( 'nry_wizard_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'nry_wizard_error_' . get_current_user_id() );
		}
		$names = array( 1 => 'اتصال', 2 => 'صفحه‌ها', 3 => 'خودکارسازی', 4 => 'پایان' );

		echo '<div class="wrap" style="max-width:760px"><h1 style="display:flex;align-items:center;gap:14px;padding:4px 0 8px"><img src="' . esc_url( NRY_URL . 'assets/logo.png' ) . '" alt="رخداد نرخ" width="158" height="56" style="height:56px;width:auto"><span>راه‌اندازی</span></h1>';
		echo '<ol style="display:flex;gap:0;list-style:none;margin:16px 0 0;padding:0;border-top:3px solid #0a2463">';
		foreach ( $names as $i => $label ) {
			$on = $i === $step;
			echo '<li style="flex:1;margin:0;padding:10px 12px;border-left:1px solid #d7dce6;font-weight:' . ( $on ? '700' : '400' ) . ';color:' . ( $i <= $step ? '#0a2463' : '#646970' ) . ';box-shadow:' . ( $on ? 'inset 0 -3px 0 #e3ae2b' : 'none' ) . '">' . esc_html( nry_fa( $i ) . '. ' . $label ) . '</li>';
		}
		echo '</ol><div style="background:#fff;border:1px solid #d7dce6;border-top:0;padding:20px 24px">';
		if ( $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $step < 4 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nry_wizard"><input type="hidden" name="step" value="' . (int) $step . '">';
			wp_nonce_field( 'nry_wizard' );
		}

		if ( 1 === $step ) {
			echo '<h2>۱. اتصال به منبع قیمت</h2><p>کلید وب‌سرویس BrsApi را وارد و نوع آن را انتخاب کنید. با زدن «ادامه» اتصال آزمایش و اولین قیمت‌ها دریافت می‌شود.</p>';
			$plan = nry_opt( 'plan', 'free' );
			echo '<p><label><input type="radio" name="plan" value="free"' . checked( 'free', $plan, false ) . '> وب‌سرویس رایگان</label> &nbsp; <label><input type="radio" name="plan" value="pro"' . checked( 'pro', $plan, false ) . '> وب‌سرویس حرفه‌ای (Pro)</label></p>';
			echo '<p><label for="nry-wk"><strong>کلید API</strong></label><br><input id="nry-wk" name="api_key" type="password" class="regular-text" dir="ltr" autocomplete="off" value="' . esc_attr( nry_opt( 'api_key', '' ) ) . '"></p>';
			submit_button( 'آزمایش اتصال و ادامه' );
		} elseif ( 2 === $step ) {
			echo '<h2>۲. صفحه‌های قیمت</h2><p>برای هر بازار یک برگه با آدرس دلخواه ساخته می‌شود. نامک می‌تواند لاتین یا فارسی باشد. اگر می‌خواهید قیمت‌ها را فقط داخل برگه‌های موجود نشان دهید، تیک‌ها را بردارید و بعداً از «نمایش در محتوا» تنظیم کنید.</p>';
			foreach ( NRY_Markets::all() as $mid => $m ) {
				$has = NRY_Pages::landing_url( $mid );
				echo '<fieldset style="border-top:1px solid #d7dce6;padding:12px 0"><legend class="screen-reader-text">' . esc_html( $m['title'] ) . '</legend>';
				if ( $has ) {
					echo '<p><strong>' . esc_html( $m['title'] ) . '</strong> — قبلاً ساخته شده: <span dir="ltr">' . esc_html( urldecode( $has ) ) . '</span></p>';
				} else {
					echo '<p><label><input type="checkbox" name="page[' . esc_attr( $mid ) . '][on]" value="1"' . checked( true, in_array( $mid, array( 'gold', 'dollar' ), true ), false ) . '> <strong>' . esc_html( $m['title'] ) . '</strong></label></p>';
					echo '<p><label for="nry-ws-' . esc_attr( $mid ) . '">آدرس:</label> <code>' . esc_html( urldecode( home_url( '/' ) ) ) . '</code> <input id="nry-ws-' . esc_attr( $mid ) . '" type="text" name="page[' . esc_attr( $mid ) . '][slug]" value="' . esc_attr( $m['slug'] ) . '" class="regular-text"></p>';
				}
				echo '</fieldset>';
			}
			submit_button( 'ساخت صفحه‌ها و ادامه' );
		} elseif ( 3 === $step ) {
			echo '<h2>۳. خودکارسازی</h2><p>همه‌ی این موارد بعداً از برگه‌ی «خودکارسازی» قابل تغییر است.</p>';
			echo '<p><label for="nry-wc"><strong>دسته‌ی مطالب روزانه‌ی قیمت</strong></label><br>';
			wp_dropdown_categories( array( 'name' => 'cat', 'id' => 'nry-wc', 'hide_empty' => 0, 'show_option_none' => '— انتخاب نشده —', 'option_none_value' => 0, 'selected' => (int) nry_opt( 'daily_cat', 0 ) ) );
			echo '</p>';
			echo '<p><label><input type="checkbox" name="card_in_cat" value="1" checked> کارت قیمت، بالای همه‌ی نوشته‌های این دسته نمایش داده شود</label></p>';
			echo '<p><label><input type="checkbox" name="autolink" value="1" checked> اولین «قیمت طلا» و «قیمت دلار» در هر نوشته به صفحه‌ی خودش لینک شود</label></p>';
			echo '<p><label><input type="checkbox" name="daily_on" value="1"> هر روز ساعت <input type="number" name="daily_hour" min="0" max="23" class="small-text" value="' . esc_attr( nry_opt( 'daily_hour', 11 ) ) . '"> یک پیش‌نویس با قیمت‌های همان روز ساخته شود</label></p>';
			echo '<p><label><input type="checkbox" name="image_on" value="1" checked> هر روز تصویر قیمت (۱۲۰۰×۶۷۵) ساخته شود</label>';
			if ( ! NRY_Image::ready() ) {
				echo '<br><span class="description">برای تصویر، یک فونت TTF فارسی لازم است؛ از برگه‌ی «خودکارسازی» بارگذاری کنید.</span>';
			}
			echo '</p>';
			submit_button( 'ذخیره و پایان' );
		} else {
			echo '<h2>رخداد نرخ آماده است</h2><ul style="list-style:disc;padding-right:20px">';
			foreach ( NRY_Pages::landing_pages() as $p ) {
				echo '<li><a href="' . esc_url( get_permalink( $p ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $p ) ) . '</a> — <span dir="ltr">' . esc_html( urldecode( get_permalink( $p ) ) ) . '</span></li>';
			}
			echo '</ul><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . NRY_Admin::SLUG ) ) . '">رفتن به تنظیمات</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . NRY_Admin::SLUG . '&tab=shortcodes' ) ) . '">فهرست شورت‌کدها</a></p>';
		}

		if ( $step < 4 ) {
			echo '</form>';
		}
		echo '</div></div>';
	}
}
