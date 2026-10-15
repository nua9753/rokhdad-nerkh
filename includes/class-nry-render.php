<?php
defined( 'ABSPATH' ) || exit;

/** Builds the HTML of every module. All output is escaped here. */
class NRY_Render {

	private static $uid = 0;

	public static function register_assets() {
		wp_register_style( 'rokhdad-nerkh', NRY_URL . 'assets/rokhdad-nerkh.css', array(), NRY_VER );
		$vars = sprintf(
			'.nry{--nry-navy:%s;--nry-gold:%s;--nry-red:%s}',
			sanitize_hex_color( nry_opt( 'navy' ) ) ?: '#0a2463',
			sanitize_hex_color( nry_opt( 'gold' ) ) ?: '#e3ae2b',
			sanitize_hex_color( nry_opt( 'red' ) ) ?: '#d8232a'
		);
		wp_add_inline_style( 'rokhdad-nerkh', $vars );
		wp_register_script( 'rokhdad-nerkh', NRY_URL . 'assets/rokhdad-nerkh.js', array(), NRY_VER, true );
		wp_localize_script(
			'rokhdad-nerkh',
			'NRY',
			array(
				'rest'  => esc_url_raw( rest_url( 'rokhdad-nerkh/v1/' ) ),
				'site'  => get_bloginfo( 'name' ),
				'every' => 60,
			)
		);
	}

	public static function assets() {
		if ( ! wp_style_is( 'rokhdad-nerkh', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'rokhdad-nerkh' );
		wp_enqueue_script( 'rokhdad-nerkh' );
	}

	/** Shown to editors only, so a wrong symbol is easy to spot without breaking the page for visitors. */
	private static function missing( $what ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="nry nry-note">رخداد نرخ: «' . esc_html( $what ) . '» در داده‌های دریافت‌شده پیدا نشد. از تنظیمات رخداد نرخ، اتصال و نمادها را بررسی کنید.</p>';
		}
		return '';
	}

	private static function head( $title, $tag = 'h2', $id = '' ) {
		if ( '' === (string) $title ) {
			return '';
		}
		$tag = in_array( $tag, array( 'h2', 'h3', 'div' ), true ) ? $tag : 'h2';
		return '<div class="nry-head"><' . $tag . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</' . $tag . '><i></i><s></s></div>';
	}

	private static function chg( $d ) {
		return '<span class="nry-chg"><i class="nry-arrow" aria-hidden="true"></i><span dir="ltr" data-f="pct">' . esc_html( nry_pct( $d['pct'] ) ) . '</span></span>';
	}

	private static function when( $d ) {
		return esc_html( nry_fa( trim( $d['time'] . ' — ' . $d['date'], ' —' ) ) );
	}

	/** The yellow headline price card. */
	public static function card( $ref, $args = array() ) {
		$item = NRY_Api::one( $ref );
		if ( ! $item ) {
			return self::missing( $ref );
		}
		$d     = NRY_Api::display( $item );
		$label = ! empty( $args['label'] ) ? $args['label'] : $d['name'];
		$range = NRY_Api::day_range( $item['symbol'] );

		$share = ! isset( $args['share'] ) || $args['share'];
		$h     = '<div class="nry nry-card ' . esc_attr( nry_dir_class( $d['pct'] ) ) . '" data-nry="' . esc_attr( $item['symbol'] ) . '"'
			. ' data-title="' . esc_attr( ! empty( $args['title'] ) ? $args['title'] : $label ) . '" data-label="' . esc_attr( $label ) . '" data-unit="' . esc_attr( $d['unit'] ) . '"'
			. ' data-stamp1="' . esc_attr( ! empty( $args['stamp'][0] ) ? $args['stamp'][0] : '' ) . '" data-stamp2="' . esc_attr( ! empty( $args['stamp'][1] ) ? $args['stamp'][1] : '' ) . '"><div class="nry-card__in">';
		$h .= '<div class="nry-card__top"><span class="nry-card__label">' . esc_html( $label ) . '</span>';
		if ( ! empty( $args['stamp'][0] ) ) {
			$h .= '<span class="nry-stamp" aria-hidden="true"><b>' . esc_html( $args['stamp'][0] ) . '</b>' . ( ! empty( $args['stamp'][1] ) ? '<small>' . esc_html( $args['stamp'][1] ) . '</small>' : '' ) . '</span>';
		}
		$h .= '</div>';
		$h .= '<div class="nry-card__price"><span data-f="price">' . esc_html( nry_num( $d['price'] ) ) . '</span> <small>' . esc_html( $d['unit'] ) . '</small></div>';
		$h .= '<div class="nry-card__foot">';
		$h .= '<div><span>تغییر امروز</span><strong>' . self::chg( $d ) . '</strong></div>';
		if ( $range ) {
			$h .= '<div><span>کمترین ۲۴ ساعت</span><strong>' . esc_html( nry_num( $range['low'] ) ) . '</strong></div>';
			$h .= '<div><span>بیشترین ۲۴ ساعت</span><strong>' . esc_html( nry_num( $range['high'] ) ) . '</strong></div>';
		} else {
			$h .= '<div><span>مقدار تغییر</span><strong data-f="change">' . esc_html( nry_num( abs( $d['change'] ) ) ) . '</strong></div>';
		}
		$h .= '</div>';
		$h .= '<div class="nry-card__time">آخرین به‌روزرسانی: <span data-f="time">' . self::when( $d ) . '</span></div>';
		if ( ! empty( $args['link'] ) ) {
			$h .= '<a class="nry-card__link" href="' . esc_url( $args['link'] ) . '">' . esc_html( ! empty( $args['link_text'] ) ? $args['link_text'] : 'جدول کامل قیمت‌ها' ) . ' ←</a>';
		}
		if ( $share ) {
			$h .= '<button type="button" class="nry-card__share" data-nry-share>اشتراک‌گذاری به‌صورت تصویر</button>';
		}
		$h .= '</div></div>';
		return $h;
	}

	/** A bare live number for use inside a sentence. */
	public static function inline( $ref, $field = 'price', $with_unit = true ) {
		$item = NRY_Api::one( $ref );
		if ( ! $item ) {
			return self::missing( $ref );
		}
		$d = NRY_Api::display( $item );
		if ( 'pct' === $field ) {
			return '<span class="nry-inline" data-nry="' . esc_attr( $item['symbol'] ) . '"><span dir="ltr" data-f="pct">' . esc_html( nry_pct( $d['pct'] ) ) . '</span></span>';
		}
		return '<span class="nry-inline" data-nry="' . esc_attr( $item['symbol'] ) . '"><span data-f="price">' . esc_html( nry_num( $d['price'] ) ) . '</span>' . ( $with_unit ? ' ' . esc_html( $d['unit'] ) : '' ) . '</span>';
	}

	/** Strip of up to four secondary prices. */
	public static function board( $refs, $exclude = '' ) {
		$items = array();
		foreach ( NRY_Api::pick( $refs, 8 ) as $it ) {
			if ( $it['symbol'] !== $exclude && count( $items ) < 4 ) {
				$items[] = $it;
			}
		}
		if ( ! $items ) {
			return self::missing( implode( '، ', (array) $refs ) );
		}
		$h = '<div class="nry nry-board">';
		foreach ( $items as $item ) {
			$d  = NRY_Api::display( $item );
			$h .= '<div class="nry-board__cell ' . esc_attr( nry_dir_class( $d['pct'] ) ) . '" data-nry="' . esc_attr( $item['symbol'] ) . '">';
			$h .= '<span class="nry-board__name">' . esc_html( $d['name'] ) . ' <small>· ' . esc_html( $d['unit'] ) . '</small></span>';
			$h .= '<span class="nry-board__price" data-f="price">' . esc_html( nry_num( $d['price'] ) ) . '</span>';
			$h .= self::chg( $d );
			$h .= '</div>';
		}
		return $h . '</div>';
	}

	public static function table( $title, $head, $refs, $id = '' ) {
		$items = NRY_Api::pick( $refs );
		if ( ! $items ) {
			return '';
		}
		$h  = '<section class="nry nry-table"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . self::head( $title );
		$h .= '<div class="nry-table__scroll"><table>';
		if ( $title ) {
			$h .= '<caption>' . esc_html( $title ) . '</caption>';
		}
		$h .= '<thead><tr><th scope="col">' . esc_html( $head ? $head : 'نام' ) . '</th><th scope="col">قیمت</th><th scope="col">تغییر</th><th scope="col">درصد</th><th scope="col">زمان</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$d  = NRY_Api::display( $item );
			$h .= '<tr class="' . esc_attr( nry_dir_class( $d['pct'] ) ) . '" data-nry="' . esc_attr( $item['symbol'] ) . '">';
			$h .= '<th scope="row">' . esc_html( $d['name'] ) . '</th>';
			$h .= '<td class="nry-table__price"><span data-f="price">' . esc_html( nry_num( $d['price'] ) ) . '</span> <small>' . esc_html( $d['unit'] ) . '</small></td>';
			$h .= '<td data-f="change">' . esc_html( nry_num( abs( $d['change'] ) ) ) . '</td>';
			$h .= '<td>' . self::chg( $d ) . '</td>';
			$h .= '<td class="nry-table__time" data-f="time">' . esc_html( nry_fa( $d['time'] ) ) . '</td>';
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div></section>';
	}

	public static function chart( $ref, $title = '' ) {
		$item = NRY_Api::one( $ref );
		if ( ! $item ) {
			return self::missing( $ref );
		}
		$d = NRY_Api::display( $item );
		if ( '' === $title ) {
			$title = 'نمودار قیمت ' . $d['name'];
		}
		$h  = '<section class="nry nry-chart" id="nry-chart" data-nry-chart="' . esc_attr( $item['symbol'] ) . '">' . self::head( $title );
		$h .= '<div class="nry-tabs" role="group" aria-label="بازه زمانی">';
		foreach ( array( 'd' => 'روزانه', 'w' => 'هفتگی', 'm' => 'ماهانه', 'y' => 'سالانه' ) as $k => $label ) {
			$h .= '<button type="button" data-range="' . esc_attr( $k ) . '" aria-pressed="' . ( 'd' === $k ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		$h .= '</div>';
		$h .= '<div class="nry-chart__plot" tabindex="0" role="img" aria-label="' . esc_attr( $title ) . '؛ با کلیدهای چپ و راست بین نقطه‌ها جابه‌جا شوید"></div>';
		$h .= '<div class="nry-chart__axis"><span data-a="from"></span><span data-a="range"></span><span data-a="to"></span></div>';
		return $h . '</section>';
	}

	public static function calculator( $type, $market = null ) {
		self::$uid++;
		$u = 'nry' . self::$uid;
		if ( 'fx' === $type ) {
			return self::calc_fx( $u );
		}
		$item  = NRY_Api::one( $market && ! empty( $market['main'] ) ? $market['main'] : 'GOLD18K' );
		$price = $item ? NRY_Api::display( $item ) : null;
		$p     = $price ? nry_num( round( $price['price'] ), 0 ) : '';

		$h  = '<section class="nry nry-calc" id="nry-calculator" data-nry-calc="gold">';
		$h .= '<div class="nry-calc__top"><h2>ماشین‌حساب طلا</h2><button type="button" class="nry-calc__reset" data-reset>پاک کردن</button></div>';
		$h .= '<div class="nry-field"><span id="' . $u . 'k">عیار</span><div class="nry-seg" role="group" aria-labelledby="' . $u . 'k">';
		foreach ( array( 18, 21, 24 ) as $k ) {
			$h .= '<button type="button" data-k="' . $k . '" aria-pressed="' . ( 18 === $k ? 'true' : 'false' ) . '">' . esc_html( nry_fa( $k ) ) . ' عیار</button>';
		}
		$h .= '</div></div>';
		$h .= '<div class="nry-field"><label for="' . $u . 'w">وزن طلا</label><div class="nry-field__row"><input id="' . $u . 'w" data-i="w" inputmode="decimal" placeholder="مثلاً ۳٫۵" autocomplete="off">';
		$h .= '<div class="nry-seg nry-seg--unit" role="group" aria-label="واحد وزن"><button type="button" data-u="g" aria-pressed="true">گرم</button><button type="button" data-u="m" aria-pressed="false">مثقال</button></div></div></div>';
		$h .= '<div class="nry-field"><label for="' . $u . 'p">قیمت هر گرم ۱۸ عیار (تومان)</label><input id="' . $u . 'p" data-i="p" data-default="' . esc_attr( $p ) . '" value="' . esc_attr( $p ) . '" inputmode="numeric" placeholder="قیمت لحظه‌ای" autocomplete="off"><small>از قیمت لحظه‌ای پر می‌شود و قابل تغییر است.</small></div>';
		$h .= '<div class="nry-field__three">';
		$h .= '<div class="nry-field"><label for="' . $u . 'a">اجرت ٪</label><input id="' . $u . 'a" data-i="a" inputmode="decimal" placeholder="٪" autocomplete="off"></div>';
		$h .= '<div class="nry-field"><label for="' . $u . 's">سود ٪</label><input id="' . $u . 's" data-i="s" data-default="۷" value="۷" inputmode="decimal" autocomplete="off"></div>';
		$h .= '<div class="nry-field"><label for="' . $u . 't">مالیات ٪</label><input id="' . $u . 't" data-i="t" data-default="۱۰" value="۱۰" inputmode="decimal" autocomplete="off"></div>';
		$h .= '</div>';
		$h .= '<div class="nry-receipt" aria-live="polite"><dl>';
		foreach ( array( 'base' => 'ارزش طلا', 'wage' => 'اجرت ساخت', 'profit' => 'سود فروشنده', 'tax' => 'مالیات بر ارزش افزوده' ) as $k => $label ) {
			$h .= '<div><dt>' . esc_html( $label ) . '</dt><dd data-o="' . esc_attr( $k ) . '">—</dd></div>';
		}
		$h .= '</dl><div class="nry-receipt__total"><span>قیمت نهایی</span><span><b data-o="total">—</b> <small>تومان</small></span></div></div>';
		$h .= '<p class="nry-calc__note">محاسبه تقریبی است؛ اجرت و سود هر فروشنده فرق می‌کند.</p>';
		return $h . '</section>';
	}

	private static function calc_fx( $u ) {
		$curs = NRY_Api::pick( array( 'USD', 'EUR', 'AED' ), 3 );
		if ( ! $curs ) {
			return self::missing( 'USD' );
		}
		$h  = '<section class="nry nry-calc" id="nry-calculator" data-nry-calc="fx">';
		$h .= '<div class="nry-calc__top"><h2>مبدل ارز</h2><button type="button" class="nry-calc__reset" data-reset>پاک کردن</button></div>';
		$h .= '<div class="nry-field"><span id="' . $u . 'c">ارز</span><div class="nry-seg" role="group" aria-labelledby="' . $u . 'c">';
		foreach ( $curs as $i => $item ) {
			$d  = NRY_Api::display( $item );
			$h .= '<button type="button" data-cur="' . esc_attr( $item['symbol'] ) . '" data-rate="' . esc_attr( round( $d['price'] ) ) . '" data-name="' . esc_attr( $d['name'] ) . '" aria-pressed="' . ( 0 === $i ? 'true' : 'false' ) . '">' . esc_html( $d['name'] ) . '</button>';
		}
		$h .= '</div></div>';
		$h .= '<div class="nry-field"><span id="' . $u . 'd">جهت تبدیل</span><div class="nry-seg" role="group" aria-labelledby="' . $u . 'd"><button type="button" data-dir="f" aria-pressed="true">ارز به تومان</button><button type="button" data-dir="t" aria-pressed="false">تومان به ارز</button></div></div>';
		$h .= '<div class="nry-field"><label for="' . $u . 'a">مبلغ (<span data-l="in"></span>)</label><input id="' . $u . 'a" data-i="amt" inputmode="decimal" placeholder="مثلاً ۱۰۰" autocomplete="off"></div>';
		$h .= '<div class="nry-field"><label for="' . $u . 'r">نرخ هر واحد (تومان)</label><input id="' . $u . 'r" data-i="rate" inputmode="numeric" placeholder="قیمت لحظه‌ای" autocomplete="off"><small>از قیمت لحظه‌ای پر می‌شود و قابل تغییر است.</small></div>';
		$h .= '<div class="nry-receipt" aria-live="polite"><dl><div><dt>مبلغ تومانی به ریال</dt><dd data-o="rial">—</dd></div></dl>';
		$h .= '<div class="nry-receipt__total"><span>معادل</span><span><b data-o="total">—</b> <small data-l="out"></small></span></div></div>';
		return $h . '</section>';
	}

	public static function guide( $market ) {
		if ( empty( $market['guide'] ) ) {
			return '';
		}
		return '<article class="nry nry-guide" id="nry-guide">' . self::head( $market['guide_title'] ) . wp_kses_post( $market['guide'] ) . '</article>';
	}

	/** Latest posts of the category or tag linked to a market. */
	public static function posts( $market, $count = 0 ) {
		$tax  = isset( $market['posts_tax'] ) ? $market['posts_tax'] : '';
		$term = isset( $market['posts_term'] ) ? (int) $market['posts_term'] : 0;
		if ( ! in_array( $tax, array( 'category', 'post_tag' ), true ) || ! $term ) {
			return '';
		}
		$count = $count ? (int) $count : ( ! empty( $market['posts_count'] ) ? (int) $market['posts_count'] : 5 );
		$query = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => min( 20, max( 1, $count ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( 'category' === $tax ) {
			$query['cat'] = $term;
		} else {
			$query['tag_id'] = $term;
		}
		$posts = get_posts( $query );
		if ( ! $posts ) {
			return '';
		}
		$title = ! empty( $market['posts_title'] ) ? $market['posts_title'] : 'آخرین مطالب ' . $market['label'];
		$h     = '<section class="nry nry-posts" id="nry-posts">' . self::head( $title ) . '<ul class="nry-posts__list">';
		foreach ( $posts as $p ) {
			$url   = get_permalink( $p );
			$thumb = function_exists( 'get_the_post_thumbnail' ) ? get_the_post_thumbnail( $p, 'medium', array( 'class' => 'nry-posts__img', 'loading' => 'lazy', 'alt' => '' ) ) : '';
			$h    .= '<li class="nry-posts__item' . ( $thumb ? '' : ' nry-posts__item--text' ) . '">';
			if ( $thumb ) {
				$h .= '<a class="nry-posts__thumb" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . $thumb . '</a>';
			}
			$h .= '<div class="nry-posts__body"><a class="nry-posts__title" href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $p ) ) . '</a>';
			$h .= '<time datetime="' . esc_attr( get_the_date( 'c', $p ) ) . '">' . esc_html( nry_fa( get_the_date( '', $p ) ) ) . '</time></div></li>';
		}
		$link = get_term_link( $term, $tax );
		$h   .= '</ul>' . ( is_wp_error( $link ) ? '' : '<a class="nry-posts__more" href="' . esc_url( $link ) . '">همه‌ی مطالب ' . esc_html( isset( $market['label'] ) ? $market['label'] : '' ) . ' ←</a>' ) . '</section>';
		return $h;
	}

	public static function faq( $market ) {
		if ( empty( $market['faq'] ) ) {
			return '';
		}
		NRY_Seo::add_faq( $market['faq'] );
		$h = '<section class="nry nry-faq" id="nry-faq">' . self::head( $market['faq_title'] ) . '<div class="nry-faq__list">';
		foreach ( $market['faq'] as $i => $pair ) {
			$h .= '<details' . ( 0 === $i ? ' open' : '' ) . '><summary>' . esc_html( $pair[0] ) . '</summary><p>' . esc_html( $pair[1] ) . '</p></details>';
		}
		return $h . '</div></section>';
	}

	/** Links to the other landing pages created by the plugin. */
	private static function other_markets( $current ) {
		$links = '';
		foreach ( NRY_Pages::landing_pages() as $page ) {
			$mid = get_post_meta( $page->ID, '_nry_market', true );
			if ( $mid === $current || 'publish' !== $page->post_status ) {
				continue;
			}
			$links .= '<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>';
		}
		if ( '' === $links ) {
			return '';
		}
		return '<nav class="nry nry-links" aria-label="بازارهای دیگر"><div class="nry-head nry-head--small"><h2>بازارهای دیگر</h2><i></i></div>' . $links . '</nav>';
	}

	/** The whole landing page for one market. */
	public static function landing( $market_id, $heading = 'h2', $over = array() ) {
		$m = NRY_Markets::get( $market_id );
		if ( ! $m ) {
			return self::missing( $market_id );
		}
		// Title and lead written on the page itself win over the market defaults.
		if ( ! empty( $over['title'] ) ) {
			$m['title']      = $over['title'];
			$m['title_html'] = NRY_Markets::title_html( $over['title'], $m['hl'] );
		}
		if ( isset( $over['lead'] ) && '' !== $over['lead'] ) {
			$m['lead'] = $over['lead'];
		}
		$main = NRY_Api::one( $m['main'] );
		$tag  = 'h1' === $heading ? 'h1' : 'h2';

		if ( is_singular() ) {
			NRY_Seo::set_breadcrumb( $m['title'], get_permalink() );
		}

		$h = '<div class="nry nry-landing">';
		$h .= '<nav class="nry-crumb" aria-label="مسیر صفحه"><a href="' . esc_url( home_url( '/' ) ) . '">خانه</a><span>/</span><span>' . esc_html( $m['title'] ) . '</span></nav>';
		$h .= '<div class="nry-dateline"><span class="nry-dateline__mark">' . esc_html( $m['kicker'] ) . '</span>';
		if ( $main ) {
			$d  = NRY_Api::display( $main );
			$h .= '<span>' . esc_html( nry_fa( $d['date'] ) ) . '</span><span>آخرین به‌روزرسانی: ' . esc_html( nry_fa( $d['time'] ) ) . '</span>';
		}
		$h .= '</div>';

		$h .= '<div class="nry-mast"><div class="nry-mast__text">';
		$h .= '<' . $tag . ' class="nry-title">' . wp_kses( $m['title_html'], array( 'mark' => array() ) ) . '</' . $tag . '>';
		$h .= '<p class="nry-lead">' . esc_html( $m['lead'] ) . '</p>';
		$h .= '<nav class="nry-toc" aria-label="فهرست این صفحه"><a href="#nry-chart">نمودار</a><a href="#nry-tables">جدول قیمت‌ها</a>' . ( $m['calc'] ? '<a href="#nry-calculator">' . ( 'fx' === $m['calc'] ? 'مبدل ارز' : 'ماشین‌حساب' ) . '</a>' : '' ) . '<a href="#nry-faq">پرسش‌ها</a></nav>';
		$h .= '</div>';
		$h .= self::card( $m['main'], array( 'label' => $m['main_label'], 'stamp' => $m['stamp'], 'title' => $m['title'] ) );
		$h .= '</div>';

		$h .= self::board( $m['board'], $main ? $main['symbol'] : '' );

		$h .= '<div class="nry-cols"><div class="nry-main">';
		$h .= self::chart( $m['main'], $m['chart_title'] );
		$h .= '<div id="nry-tables" class="nry-stack">';
		foreach ( $m['tables'] as $tid => $t ) {
			$h .= self::table( $t['title'], $t['head'], $t['symbols'], 'nry-table-' . $tid );
		}
		$h .= '</div>';
		$h .= self::posts( $m );
		if ( isset( $over['content'] ) && '' !== trim( wp_strip_all_tags( $over['content'], true ) ) ) {
			// Text written in the page editor replaces the market's guide and FAQ.
			$h .= '<div class="nry nry-guide nry-content" id="nry-guide">' . $over['content'] . '</div>';
		} else {
			$h .= self::guide( $m );
			$h .= self::faq( $m );
		}
		$h .= '</div>';
		$side = ( $m['calc'] ? self::calculator( $m['calc'], $m ) : '' ) . self::other_markets( $market_id );
		if ( '' !== $side ) {
			$h .= '<aside class="nry-side">' . $side . '</aside>';
		}
		$h .= '</div></div>';
		return $h;
	}
}
