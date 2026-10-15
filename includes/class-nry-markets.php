<?php
defined( 'ABSPATH' ) || exit;

/**
 * Market definitions: which symbols go where, and the editorial copy.
 * Four markets ship with the plugin; every one of them can be edited from the «بازارها» screen,
 * and new ones can be created there. Everything is filterable via `nry_markets`.
 *
 * Symbol references: "USD" exact symbol · "~دلار سنا" every row whose name has those words ·
 * "@gold", "@currency", "@crypto" every row of that section of the nerkh.io feed.
 */
class NRY_Markets {

	const BUILTIN = array( 'gold', 'coin', 'dollar', 'crypto' );

	public static function builtin() {
		return array(
			'gold'   => array(
				'label'       => 'طلا',
				'slug'        => 'gold-price',
				'title'       => 'قیمت طلا امروز',
				'hl'          => 'طلا',
				'kicker'      => 'بازار طلا و سکه تهران',
				'lead'        => 'قیمت لحظه‌ای طلای ۱۸ عیار، مثقال طلا، سکه و انس جهانی؛ همراه با نمودار تغییرات و ماشین‌حساب طلا.',
				'main'        => 'GOLD18K',
				'main_label'  => 'هر گرم طلای ۱۸ عیار',
				'stamp'       => array( '۷۵۰', 'عیار ۱۸' ),
				'board'       => array( 'MAZANEH', 'SEKE_EMAMI', 'OUNCE', 'USD', 'GOLD24K' ),
				'tables'      => array(
					'gold' => array( 'title' => 'جدول قیمت طلا امروز', 'head' => 'نوع طلا', 'symbols' => array( 'GOLD18K', 'GOLD24K', 'MAZANEH', '~آب‌شده', 'OUNCE' ) ),
					'coin' => array( 'title' => 'جدول قیمت سکه امروز', 'head' => 'نوع سکه', 'symbols' => array( 'SEKE_EMAMI', 'SEKE_BAHAR', 'SEKE_NIM', 'SEKE_ROB', 'SEKE_1G' ) ),
					'parsian' => array( 'title' => 'قیمت سکه پارسیان', 'head' => 'نوع سکه', 'symbols' => array( '~پارسیان' ) ),
				),
				'chart_title' => 'نمودار قیمت طلای ۱۸ عیار',
				'calc'        => 'gold',
				'guide_title' => 'قیمت طلا چگونه تعیین می‌شود؟',
				'guide'       => '<p>قیمت طلا در بازار ایران از سه عامل اصلی اثر می‌گیرد: قیمت انس جهانی طلا، نرخ دلار در بازار داخلی و میزان عرضه و تقاضا. با تغییر هر یک از این سه، قیمت هر گرم طلای ۱۸ عیار و به دنبال آن قیمت سکه نیز تغییر می‌کند.</p>'
					. '<div class="nry-facts"><div><b>۷۵۰</b><strong>عیار طلای ۱۸</strong><span>یعنی ۷۵ درصد طلای خالص</span></div><div><b>۴٫۶۰۸۳</b><strong>گرم در هر مثقال</strong><span>مبنای معاملات عمده بازار تهران</span></div><div><b>۸٫۱۳۳</b><strong>گرم وزن سکه امامی</strong><span>با عیار ۹۰۰</span></div></div>'
					. '<h3>تفاوت طلای ۱۸ عیار و ۲۴ عیار</h3><p>طلای ۲۴ عیار طلای خالص با خلوص ۹۹۹٫۹ است و بیشتر به شکل شمش معامله می‌شود. طلای ۱۸ عیار خلوص ۷۵۰ دارد و بیشتر زیورآلات بازار ایران با همین عیار ساخته می‌شود.</p>'
					. '<h3>فرمول محاسبه قیمت طلای ساخته‌شده</h3><p class="nry-formula"><mark>قیمت نهایی</mark> = (قیمت هر گرم × وزن) <i>+</i> اجرت ساخت <i>+</i> سود فروشنده <i>+</i> مالیات بر ارزش افزوده</p>'
					. '<p>مالیات بر ارزش افزوده فقط روی اجرت ساخت و سود فروشنده حساب می‌شود، نه روی اصل طلا.</p>',
				'faq_title'   => 'پرسش‌های پرتکرار قیمت طلا',
				'faq'         => array(
					array( 'قیمت طلای ۱۸ عیار امروز چقدر است؟', 'قیمت هر گرم طلای ۱۸ عیار در لحظه در بالای همین صفحه و در جدول قیمت طلا نمایش داده می‌شود و با هر تغییر بازار به‌روز می‌شود.' ),
					array( 'حباب سکه چیست؟', 'حباب سکه فاصله قیمت بازار سکه با ارزش ذاتی آن است. ارزش ذاتی از روی وزن و عیار سکه، قیمت انس جهانی و نرخ دلار محاسبه می‌شود.' ),
					array( 'وزن و عیار سکه امامی چقدر است؟', 'سکه تمام بهار آزادی طرح جدید (امامی) ۸٫۱۳۳ گرم وزن دارد و عیار آن ۹۰۰ است.' ),
				),
			),
			'coin'   => array(
				'label'       => 'سکه',
				'slug'        => 'coin-price',
				'title'       => 'قیمت سکه امروز',
				'hl'          => 'سکه',
				'kicker'      => 'بازار سکه تهران',
				'lead'        => 'قیمت لحظه‌ای سکه امامی، بهار آزادی، نیم‌سکه، ربع‌سکه و سکه گرمی؛ همراه با نمودار تغییرات.',
				'main'        => 'SEKE_EMAMI',
				'main_label'  => 'سکه امامی',
				'stamp'       => array( '۹۰۰', 'عیار' ),
				'board'       => array( 'SEKE_BAHAR', 'SEKE_NIM', 'SEKE_ROB', 'SEKE_1G' ),
				'tables'      => array(
					'coin'    => array( 'title' => 'جدول قیمت سکه امروز', 'head' => 'نوع سکه', 'symbols' => array( 'SEKE_EMAMI', 'SEKE_BAHAR', 'SEKE_NIM', 'SEKE_ROB', 'SEKE_1G' ) ),
					'parsian' => array( 'title' => 'قیمت سکه پارسیان', 'head' => 'نوع سکه', 'symbols' => array( '~پارسیان' ) ),
				),
				'chart_title' => 'نمودار قیمت سکه امامی',
				'calc'        => '',
				'guide_title' => 'قیمت سکه چگونه تعیین می‌شود؟',
				'guide'       => '<p>قیمت سکه از قیمت انس جهانی طلا، نرخ دلار و میزان تقاضا در بازار اثر می‌گیرد. فاصله‌ی قیمت بازار با ارزش طلای داخل سکه را «حباب سکه» می‌گویند.</p>'
					. '<div class="nry-facts"><div><b>۸٫۱۳۳</b><strong>گرم وزن سکه تمام</strong><span>امامی و بهار آزادی</span></div><div><b>۴٫۰۶۶</b><strong>گرم وزن نیم‌سکه</strong><span>نصف سکه تمام</span></div><div><b>۲٫۰۳۳</b><strong>گرم وزن ربع‌سکه</strong><span>یک‌چهارم سکه تمام</span></div></div>'
					. '<h3>تفاوت سکه امامی و بهار آزادی</h3><p>هر دو سکه‌ی تمام با وزن و عیار یکسان هستند. سکه امامی طرح جدید و سکه بهار آزادی طرح قدیم است و قیمت آن‌ها در بازار کمی با هم فرق دارد.</p>',
				'faq_title'   => 'پرسش‌های پرتکرار قیمت سکه',
				'faq'         => array(
					array( 'قیمت سکه امامی امروز چقدر است؟', 'قیمت سکه امامی در لحظه در بالای همین صفحه و در جدول قیمت سکه نمایش داده می‌شود.' ),
					array( 'حباب سکه چیست؟', 'حباب سکه فاصله قیمت بازار سکه با ارزش ذاتی آن است. ارزش ذاتی از روی وزن و عیار سکه، قیمت انس جهانی و نرخ دلار محاسبه می‌شود.' ),
					array( 'وزن و عیار سکه امامی چقدر است؟', 'سکه تمام بهار آزادی طرح جدید (امامی) ۸٫۱۳۳ گرم وزن دارد و عیار آن ۹۰۰ است.' ),
				),
			),
			'dollar' => array(
				'label'       => 'دلار',
				'slug'        => 'dollar-price',
				'title'       => 'قیمت دلار امروز',
				'hl'          => 'دلار',
				'kicker'      => 'بازار ارز تهران',
				'lead'        => 'قیمت لحظه‌ای دلار، یورو، درهم و تتر؛ همراه با نمودار تغییرات و مبدل ارز.',
				'main'        => 'USD',
				'main_label'  => 'دلار آمریکا',
				'stamp'       => array( 'USD', 'دلار' ),
				'board'       => array( 'EUR', 'AED', 'USDT', 'GBP' ),
				'tables'      => array(
					'dollar' => array( 'title' => 'جدول قیمت دلار امروز', 'head' => 'نوع دلار', 'symbols' => array( 'USD', 'USDT' ) ),
					'fx'     => array( 'title' => 'قیمت سایر ارزها', 'head' => 'ارز', 'symbols' => array( 'EUR', 'AED', 'GBP', 'TRY', 'CNY' ) ),
				),
				'chart_title' => 'نمودار قیمت دلار',
				'calc'        => 'fx',
				'guide_title' => 'قیمت دلار چگونه تعیین می‌شود؟',
				'guide'       => '<p>قیمت دلار در ایران یک عدد واحد نیست. در بازار آزاد، عرضه و تقاضا، انتظارات تورمی و خبرهای سیاسی و اقتصادی نرخ را جابه‌جا می‌کنند؛ در کنار آن، نرخ‌های رسمی نیز اعلام می‌شود. قیمت دلار روی قیمت طلا و سکه هم مستقیم اثر می‌گذارد.</p>'
					. '<h3>تبدیل دلار به تومان</h3><p class="nry-formula"><mark>مبلغ به تومان</mark> = مبلغ به دلار <i>×</i> نرخ دلار</p><p>هر تومان برابر با ۱۰ ریال است.</p>',
				'faq_title'   => 'پرسش‌های پرتکرار قیمت دلار',
				'faq'         => array(
					array( 'قیمت دلار امروز چقدر است؟', 'قیمت دلار در لحظه در بالای همین صفحه و در جدول قیمت دلار نمایش داده می‌شود و با هر تغییر بازار به‌روز می‌شود.' ),
					array( 'دلار آزاد با نرخ‌های رسمی چه فرقی دارد؟', 'دلار آزاد نرخ معامله در بازار است. نرخ‌های رسمی، مثل سنا و نیما، در سامانه‌های بانک مرکزی اعلام می‌شوند.' ),
					array( 'تتر چیست و چرا کنار دلار می‌آید؟', 'تتر (USDT) یک ارز دیجیتال است که ارزش آن به دلار آمریکا وابسته است؛ برای همین قیمت آن به تومان معمولاً نزدیک به قیمت دلار حرکت می‌کند.' ),
				),
			),
			'crypto' => array(
				'label'       => 'ارز دیجیتال',
				'slug'        => 'crypto-price',
				'title'       => 'قیمت ارز دیجیتال امروز',
				'hl'          => 'ارز دیجیتال',
				'kicker'      => 'بازار ارزهای دیجیتال',
				'lead'        => 'قیمت لحظه‌ای بیت‌کوین، اتریوم، تتر و دیگر ارزهای دیجیتال؛ همراه با نمودار تغییرات.',
				'main'        => 'BTC',
				'main_label'  => 'بیت‌کوین',
				'stamp'       => array( 'BTC', 'بیت‌کوین' ),
				'board'       => array( 'ETH', 'USDT', 'XRP', 'BNB', '@crypto' ),
				'tables'      => array(
					'crypto' => array( 'title' => 'جدول قیمت ارزهای دیجیتال', 'head' => 'ارز دیجیتال', 'symbols' => array( '@crypto' ) ),
				),
				'chart_title' => 'نمودار قیمت بیت‌کوین',
				'calc'        => '',
				'guide_title' => 'قیمت ارز دیجیتال چگونه تعیین می‌شود؟',
				'guide'       => '<p>قیمت ارزهای دیجیتال در بازارهای جهانی و به دلار تعیین می‌شود و شبانه‌روز، بدون تعطیلی، تغییر می‌کند. قیمت تومانی آن‌ها از ضرب قیمت دلاری در نرخ تتر یا دلار به دست می‌آید؛ برای همین هم از بازار جهانی اثر می‌گیرد و هم از نرخ ارز داخلی.</p>',
				'faq_title'   => 'پرسش‌های پرتکرار ارز دیجیتال',
				'faq'         => array(
					array( 'قیمت بیت‌کوین امروز چقدر است؟', 'قیمت بیت‌کوین در لحظه در بالای همین صفحه و در جدول قیمت ارزهای دیجیتال نمایش داده می‌شود.' ),
					array( 'تتر چیست؟', 'تتر (USDT) یک ارز دیجیتال است که ارزش آن به دلار آمریکا وابسته است.' ),
				),
			),
		);
	}

	/** Blank market used as the base of a custom one. */
	public static function skeleton() {
		return array(
			'label' => '', 'slug' => '', 'title' => '', 'hl' => '', 'kicker' => '', 'lead' => '',
			'main' => '', 'main_label' => '', 'stamp' => array( '', '' ), 'board' => array(), 'tables' => array(),
			'chart_title' => '', 'calc' => '', 'guide_title' => '', 'guide' => '', 'faq_title' => '', 'faq' => array(),
			'posts_tax' => '', 'posts_term' => 0, 'posts_count' => 5, 'posts_title' => '',
			'custom' => 1,
		);
	}

	public static function all() {
		$markets = self::builtin();

		// Legacy symbol overrides from the old «نمادها» screen.
		$legacy = nry_opt( 'symbols', array() );
		foreach ( $markets as $id => &$m ) {
			if ( empty( $legacy[ $id ] ) || ! is_array( $legacy[ $id ] ) ) {
				continue;
			}
			if ( ! empty( $legacy[ $id ]['main'] ) ) {
				$m['main'] = $legacy[ $id ]['main'];
			}
			if ( ! empty( $legacy[ $id ]['board'] ) ) {
				$m['board'] = self::split( $legacy[ $id ]['board'] );
			}
			foreach ( $m['tables'] as $tid => &$t ) {
				if ( ! empty( $legacy[ $id ][ 'table_' . $tid ] ) ) {
					$t['symbols'] = self::split( $legacy[ $id ][ 'table_' . $tid ] );
				}
			}
			unset( $t );
		}
		unset( $m );

		// Edits and custom markets saved from the «بازارها» screen.
		foreach ( (array) nry_opt( 'markets', array() ) as $id => $saved ) {
			if ( ! is_array( $saved ) ) {
				continue;
			}
			if ( isset( $markets[ $id ] ) ) {
				$markets[ $id ] = array_merge( $markets[ $id ], $saved );
			} elseif ( ! empty( $saved['custom'] ) ) {
				$markets[ $id ] = array_merge( self::skeleton(), $saved );
			}
		}

		foreach ( $markets as $id => &$m ) {
			$m += array( 'posts_tax' => '', 'posts_term' => 0, 'posts_count' => 5, 'posts_title' => '' );
			if ( '' === (string) $m['chart_title'] ) {
				$m['chart_title'] = 'نمودار ' . $m['main_label'];
			}
			if ( '' === (string) $m['slug'] ) {
				$m['slug'] = $id . '-price';
			}
			$m['title_html'] = self::title_html( $m['title'], $m['hl'] );
		}
		unset( $m );

		return apply_filters( 'nry_markets', $markets );
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function is_builtin( $id ) {
		return in_array( $id, self::BUILTIN, true );
	}

	/** Title with its key word wrapped in <mark>, already escaped. */
	public static function title_html( $title, $hl ) {
		$title = esc_html( $title );
		$hl    = esc_html( (string) $hl );
		if ( '' === $hl || false === strpos( $title, $hl ) ) {
			return $title;
		}
		$pos = strpos( $title, $hl );
		return substr( $title, 0, $pos ) . '<mark>' . $hl . '</mark>' . substr( $title, $pos + strlen( $hl ) );
	}

	public static function split( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[,،\n]+/u', (string) $text ) ) ) );
	}

	/** "question | answer" per line. */
	public static function parse_faq( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$parts = explode( '|', $line, 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) && '' !== trim( $parts[1] ) ) {
				$out[] = array( trim( $parts[0] ), trim( $parts[1] ) );
			}
		}
		return $out;
	}

	public static function faq_text( $pairs ) {
		$lines = array();
		foreach ( (array) $pairs as $p ) {
			$lines[] = $p[0] . ' | ' . $p[1];
		}
		return implode( "\n", $lines );
	}
}
