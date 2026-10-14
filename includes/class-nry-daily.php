<?php
defined( 'ABSPATH' ) || exit;

/** Once a day: refresh the share images and prepare a post with that day's prices and a data-driven summary. */
class NRY_Daily {

	public static function init() {
		add_action( 'nry_daily', array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/** Next occurrence of the configured hour, in the site's timezone. */
	private static function next_time() {
		$hour = min( 23, max( 0, (int) nry_opt( 'daily_hour', 11 ) ) );
		$dt   = new DateTime( 'now', wp_timezone() );
		$dt->setTime( $hour, 0, 0 );
		if ( $dt->getTimestamp() <= time() ) {
			$dt->modify( '+1 day' );
		}
		return $dt->getTimestamp();
	}

	public static function ensure_schedule() {
		$wanted = nry_opt( 'daily_on', 0 ) || nry_opt( 'image_on', 1 );
		$next   = wp_next_scheduled( 'nry_daily' );
		if ( $wanted && ! $next ) {
			wp_schedule_event( self::next_time(), 'daily', 'nry_daily' );
		} elseif ( ! $wanted && $next ) {
			wp_clear_scheduled_hook( 'nry_daily' );
		}
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( 'nry_daily' );
		self::ensure_schedule();
	}

	/** Returns a list of human-readable result lines. */
	public static function run() {
		$log = array();
		$r   = NRY_Api::refresh();
		if ( is_wp_error( $r ) ) {
			return array( 'دریافت قیمت ناموفق بود: ' . $r->get_error_message() );
		}
		$images = array();
		if ( nry_opt( 'image_on', 1 ) ) {
			foreach ( array_keys( NRY_Markets::all() ) as $mid ) {
				$img = NRY_Image::generate( $mid );
				if ( is_wp_error( $img ) ) {
					$log[] = 'تصویر ' . $mid . ': ' . $img->get_error_message();
				} else {
					$images[ $mid ] = $img;
					$log[]          = 'تصویر ' . $mid . ' ساخته شد.';
				}
			}
		}
		if ( nry_opt( 'daily_on', 0 ) ) {
			foreach ( (array) nry_opt( 'daily_markets', array( 'gold' ) ) as $mid ) {
				$res   = self::create_draft( $mid, isset( $images[ $mid ] ) ? $images[ $mid ] : null );
				$log[] = is_wp_error( $res ) ? 'نوشته‌ی ' . $mid . ': ' . $res->get_error_message() : 'نوشته‌ی ' . $mid . ' ساخته شد (شناسه ' . $res . ').';
			}
		}
		return $log;
	}

	private static function exists( $key ) {
		$found = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_nry_daily', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return ! empty( $found );
	}

	/* ---------- titles ---------- */

	public static function default_titles() {
		return array(
			'{title} {weekday} {date_long} / {name} چند شد؟',
			'{name} امروز {date_long} چند است؟ / {title}',
			'{title} {date_long}؛ {name} به {price} {unit} رسید',
			'قیمت {label} امروز {weekday} {date_long} / {move} {name}',
			'{title} {date_long} / آخرین قیمت {name} و جدول کامل',
		);
	}

	public static function titles() {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) nry_opt( 'daily_titles', '' ) ) ) ) );
		return $lines ? $lines : self::default_titles();
	}

	/** Next template in the rotation, so two days in a row never share a headline pattern. */
	private static function next_title( $market_id, $vars ) {
		$titles = self::titles();
		$idx    = get_option( 'nry_title_idx', array() );
		$idx    = is_array( $idx ) ? $idx : array();
		$i      = isset( $idx[ $market_id ] ) ? ( (int) $idx[ $market_id ] + 1 ) % count( $titles ) : 0;
		$idx[ $market_id ] = $i;
		update_option( 'nry_title_idx', $idx, false );
		return nry_fill( $titles[ $i ], $vars );
	}

	/* ---------- body templates ---------- */

	/** Built-in body templates. A blank line separates paragraphs; {tables} marks where the price tables go. */
	public static function default_bodies() {
		return array(
			"{today} {week}\n\n{month}\n\n{related} {mover}\n\n{tables}\n\n{more}",
			"به گزارش {site}، در بازار امروز {weekday} {date_long}، {today}\n\n{related}\n\n{tables}\n\n{week} {month}\n\n{more}",
			"آخرین قیمت {label} ساعت {time} امروز {date_long} به‌روز شد. {today}\n\n{week}\n\n{tables}\n\n{mover} {related}\n\n{month}\n\n{more}",
			"{name} امروز چند است؟ {today}\n\n{month} {week}\n\n{related}\n\n{tables}\n\n{mover}\n\n{more}",
			"{title} {weekday} {date_long}: {today}\n\n{tables}\n\n{week}\n\n{related} {mover}\n\n{month}\n\n{more}",
		);
	}

	/** The market's own templates (up to five), or the built-in ones. */
	public static function bodies( $market_id ) {
		$all  = (array) nry_opt( 'daily_bodies', array() );
		$mine = isset( $all[ $market_id ] ) ? array_values( array_filter( array_map( 'trim', (array) $all[ $market_id ] ) ) ) : array();
		return $mine ? $mine : self::default_bodies();
	}

	/** One of several phrasings; $k shifts the choice so sentences of one post do not all use the same slot. */
	private static function pick( $list, $k ) {
		return $list[ ( self::$turn + $k ) % count( $list ) ];
	}

	private static $turn = 0;

	private static function plain_pct( $p ) {
		return nry_fa( number_format( abs( (float) $p ), 2, '٫', '٬' ) ) . ' درصد';
	}

	/**
	 * Sentences describing what the numbers did, keyed by template variable:
	 * today, week, month, related, mover. Only states what the data shows; it never guesses at causes.
	 * Each has five phrasings and the post's turn number chooses among them.
	 */
	public static function parts( $market_id ) {
		$m    = NRY_Markets::get( $market_id );
		$item = $m ? NRY_Api::one( $m['main'] ) : null;
		$out  = array( 'today' => '', 'week' => '', 'month' => '', 'related' => '', 'mover' => '' );
		if ( ! $item ) {
			return $out;
		}
		$d     = NRY_Api::display( $item );
		$name  = $m['main_label'];
		$price = nry_num( $d['price'] ) . ' ' . $d['unit'];
		$p     = (float) $d['pct'];
		$pct   = self::plain_pct( $p );
		$amt   = nry_num( abs( $d['change'] ) ) . ' ' . $d['unit'];

		if ( 0.0 === $p ) {
			$out['today'] = self::pick(
				array(
					"{$name} در آخرین به‌روزرسانی تغییری نداشت و روی {$price} ماند.",
					"قیمت {$name} امروز ثابت ماند و {$price} است.",
					"{$name} بدون تغییر نسبت به روز قبل، {$price} معامله می‌شود.",
					"بازار {$name} امروز آرام بود؛ قیمت روی {$price} ثابت ماند.",
					"{$name} امروز همان قیمت روز قبل را دارد: {$price}.",
				),
				0
			);
		} elseif ( $p > 0 ) {
			$out['today'] = self::pick(
				array(
					"{$name} در آخرین به‌روزرسانی با {$pct} افزایش به {$price} رسید.",
					"{$name} امروز {$pct} گران‌تر شد و به {$price} رسید.",
					"قیمت {$name} با رشد {$pct} اکنون {$price} است.",
					"{$name} امروز روند صعودی داشت و با {$pct} افزایش روی {$price} ایستاد.",
					"{$name} نسبت به روز قبل {$amt} ({$pct}) بالا رفت و {$price} قیمت خورد.",
				),
				0
			);
		} else {
			$out['today'] = self::pick(
				array(
					"{$name} در آخرین به‌روزرسانی با {$pct} کاهش به {$price} رسید.",
					"{$name} امروز {$pct} ارزان‌تر شد و به {$price} رسید.",
					"قیمت {$name} با افت {$pct} اکنون {$price} است.",
					"{$name} امروز روند نزولی داشت و با {$pct} کاهش روی {$price} ایستاد.",
					"{$name} نسبت به روز قبل {$amt} ({$pct}) پایین آمد و {$price} قیمت خورد.",
				),
				0
			);
		}

		$week = NRY_Api::history( $item['symbol'], 'w' );
		if ( count( $week ) >= 2 && $week[0]['v'] > 0 ) {
			$from = $week[0]['v'];
			$chg  = ( $d['price'] - $from ) / $from * 100;
			$f    = nry_num( $from );
			$wp   = self::plain_pct( $chg );
			// A jump this large is almost certainly a unit mismatch in the history, not a market move.
			if ( abs( $chg ) <= 60 ) {
				if ( abs( $chg ) < 0.05 ) {
					$out['week'] = self::pick(
						array(
							'در یک هفته‌ی گذشته قیمت تقریباً ثابت مانده است.',
							'قیمت نسبت به هفته‌ی گذشته تغییر محسوسی نکرده است.',
							'در هفت روز اخیر قیمت در همان محدوده مانده است.',
							'روند هفتگی تقریباً بدون تغییر بوده است.',
							'مقایسه با یک هفته پیش تفاوت چندانی نشان نمی‌دهد.',
						),
						1
					);
				} else {
					$up          = $chg > 0;
					$out['week'] = self::pick(
						array(
							"در یک هفته‌ی گذشته قیمت از {$f} به {$price} رسیده است؛ یعنی {$wp} " . ( $up ? 'رشد' : 'افت' ) . '.',
							"نسبت به یک هفته پیش که قیمت {$f} بود، {$wp} " . ( $up ? 'افزایش' : 'کاهش' ) . ' دیده می‌شود.',
							'روند هفتگی ' . ( $up ? 'صعودی' : 'نزولی' ) . " بوده و قیمت در هفت روز اخیر {$wp} " . ( $up ? 'بالا رفته' : 'پایین آمده' ) . ' است.',
							"قیمت هفته‌ی گذشته {$f} بود و حالا {$wp} " . ( $up ? 'بیشتر' : 'کمتر' ) . ' است.',
							"در هفت روز اخیر، {$name} {$wp} " . ( $up ? 'گران‌تر' : 'ارزان‌تر' ) . ' شده است.',
						),
						1
					);
				}
			}
		}

		$month = NRY_Api::history( $item['symbol'], 'm' );
		if ( count( $month ) >= 5 ) {
			$values = array_column( $month, 'v' );
			$low    = min( min( $values ), $d['price'] );
			$high   = max( max( $values ), $d['price'] );
			if ( $high > $low && $low > 0 && $high / $low < 3 ) {
				$pos  = ( $d['price'] - $low ) / ( $high - $low );
				$l    = nry_num( $low );
				$h    = nry_num( $high );
				$u    = $d['unit'];
				$near = $pos >= 0.8 ? 'top' : ( $pos <= 0.2 ? 'bottom' : 'mid' );
				$where = array(
					'top'    => array( 'نزدیک به سقف', 'در نزدیکی بالاترین سطح', 'نزدیک بیشترین قیمت' ),
					'bottom' => array( 'نزدیک به کف', 'در نزدیکی پایین‌ترین سطح', 'نزدیک کمترین قیمت' ),
					'mid'    => array( 'در میانه‌ی', 'جایی میان کف و سقف', 'در میانه‌ی' ),
				);
				$w            = $where[ $near ][ self::$turn % 3 ];
				$out['month'] = self::pick(
					array(
						"در ۳۰ روز گذشته کمترین قیمت {$l} و بیشترین قیمت {$h} {$u} بوده و قیمت فعلی {$w} این بازه است.",
						"بازه‌ی قیمت یک ماه اخیر از {$l} تا {$h} {$u} بوده و قیمت امروز {$w} آن قرار دارد.",
						"نگاهی به یک ماه گذشته: کف {$l} و سقف {$h} {$u}؛ قیمت فعلی {$w} این محدوده است.",
						"طی ۳۰ روز اخیر قیمت بین {$l} و {$h} {$u} نوسان داشته و اکنون {$w} این بازه است.",
						"کمترین و بیشترین قیمت ماه گذشته {$l} و {$h} {$u} بوده؛ قیمت امروز {$w} این دامنه است.",
					),
					2
				);
				if ( $values[0] > 0 ) {
					$mc = ( $d['price'] - $values[0] ) / $values[0] * 100;
					if ( abs( $mc ) >= 0.05 ) {
						$out['month'] .= ' ' . self::pick(
							array(
								'تغییر قیمت در این مدت ' . self::plain_pct( $mc ) . ' ' . ( $mc > 0 ? 'رشد' : 'افت' ) . ' بوده است.',
								'برآیند این یک ماه ' . self::plain_pct( $mc ) . ' ' . ( $mc > 0 ? 'افزایش' : 'کاهش' ) . ' است.',
								'در مجموع قیمت در این مدت ' . self::plain_pct( $mc ) . ' ' . ( $mc > 0 ? 'بالا رفته' : 'پایین آمده' ) . ' است.',
							),
							3
						);
					}
				}
			}
		}

		$related = array();
		foreach ( NRY_Api::pick( ! empty( $m['related'] ) ? $m['related'] : $m['board'], 4 ) as $it ) {
			if ( $it['symbol'] === $item['symbol'] || count( $related ) >= 2 ) {
				continue;
			}
			$rd        = NRY_Api::display( $it );
			$rp        = (float) $rd['pct'];
			$related[] = $rd['name'] . ( 0.0 === $rp ? ' بدون تغییر' : ' با ' . self::plain_pct( $rp ) . ' ' . ( $rp > 0 ? 'افزایش' : 'کاهش' ) );
		}
		if ( $related ) {
			$verb           = 'همراه بود' . ( count( $related ) > 1 ? 'ند' : '' );
			$list           = implode( ' و ', $related );
			$out['related'] = self::pick(
				array(
					"هم‌زمان، {$list} {$verb}.",
					"در همین حال، {$list} {$verb}.",
					"در بازارهای مرتبط، {$list} {$verb}.",
					"هم‌زمان با این تغییر، {$list} {$verb}.",
					"نگاهی به قیمت‌های مرتبط: {$list} {$verb}.",
				),
				4
			);
		}

		$tables = array_values( $m['tables'] );
		if ( $tables ) {
			$top = null;
			foreach ( NRY_Api::pick( $tables[0]['symbols'] ) as $it ) {
				if ( $it['symbol'] !== $item['symbol'] && ( null === $top || abs( $it['pct'] ) > abs( $top['pct'] ) ) ) {
					$top = $it;
				}
			}
			if ( $top && abs( $top['pct'] ) > 0 ) {
				$td           = NRY_Api::display( $top );
				$tp           = self::plain_pct( $top['pct'] ) . ' ' . ( $top['pct'] > 0 ? 'افزایش' : 'کاهش' );
				$tt           = $tables[0]['title'];
				$tn           = $td['name'];
				$out['mover'] = self::pick(
					array(
						"در «{$tt}» بیشترین تغییر مربوط به {$tn} بود: {$tp}.",
						"پرنوسان‌ترین ردیف «{$tt}» امروز {$tn} بود با {$tp}.",
						"بیشترین جابه‌جایی «{$tt}» را {$tn} داشت: {$tp}.",
						"در میان ردیف‌های «{$tt}»، {$tn} با {$tp} بیشترین تغییر را ثبت کرد.",
						"{$tn} با {$tp}، بیشترین تغییر «{$tt}» را داشت.",
					),
					5
				);
			}
		}
		return $out;
	}

	/** Kept for code that used the old filter: the summary as a list of paragraphs. */
	public static function analysis( $market_id ) {
		$p   = self::parts( $market_id );
		$out = array_values( array_filter( array( trim( $p['today'] . ' ' . $p['week'] ), $p['month'], trim( $p['related'] . ' ' . $p['mover'] ) ) ) );
		return (array) apply_filters( 'nry_daily_analysis', $out, $market_id );
	}

	/** Next body template in the rotation, and the turn number that also picks the sentence phrasings. */
	private static function next_body( $market_id ) {
		$bodies = self::bodies( $market_id );
		$idx    = get_option( 'nry_body_idx', array() );
		$idx    = is_array( $idx ) ? $idx : array();
		$i      = isset( $idx[ $market_id ] ) ? (int) $idx[ $market_id ] + 1 : 0;
		$idx[ $market_id ] = $i;
		update_option( 'nry_body_idx', $idx, false );
		self::$turn = $i;
		return $bodies[ $i % count( $bodies ) ];
	}

	/** Fill a body template into paragraph and table blocks. */
	private static function render_body( $tpl, $m, $vars, $parts, $tables, $more ) {
		$vals = array();
		foreach ( array_merge( $vars, $parts ) as $k => $v ) {
			$vals[ $k ] = esc_html( (string) $v );
		}
		$vals['label'] = esc_html( $m['label'] );
		$vals['more']  = $more;
		$out           = '';
		$has_tables    = false;
		foreach ( preg_split( '/\R\s*\R/u', trim( $tpl ) ) as $para ) {
			if ( '{tables}' === trim( $para ) ) {
				$out       .= $tables;
				$has_tables = true;
				continue;
			}
			$text = nry_fill( esc_html( $para ), $vals );
			// A paragraph made only of variables that had nothing to say is dropped.
			if ( '' === trim( wp_strip_all_tags( $text ), " \t\n.،:؛" ) ) {
				continue;
			}
			$out .= "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		return $has_tables ? $out : $out . $tables;
	}

	/** Static snapshot of one table: the numbers must not change after the day is over. */
	private static function snapshot_table( $t ) {
		$items = NRY_Api::pick( $t['symbols'] );
		if ( ! $items ) {
			return '';
		}
		$rows = '';
		foreach ( $items as $item ) {
			$d     = NRY_Api::display( $item );
			$rows .= '<tr><td>' . esc_html( $d['name'] ) . '</td><td>' . esc_html( nry_num( $d['price'] ) . ' ' . $d['unit'] ) . '</td><td>' . esc_html( nry_pct( $d['pct'] ) ) . '</td></tr>';
		}
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $t['title'] ) . "</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><thead><tr><th>" . esc_html( $t['head'] ) . '</th><th>قیمت</th><th>تغییر</th></tr></thead><tbody>' . $rows . "</tbody></table></figure>\n<!-- /wp:table -->\n\n";
	}

	/** Status and date for the new post, from the «انتشار» setting. */
	private static function publish_fields() {
		$mode = nry_opt( 'daily_publish', 'draft' );
		if ( 'publish' === $mode ) {
			return array( 'post_status' => 'publish' );
		}
		if ( 'schedule' === $mode ) {
			$dt = new DateTime( 'now', wp_timezone() );
			$dt->setTime( min( 23, max( 0, (int) nry_opt( 'daily_publish_hour', 12 ) ) ), 0, 0 );
			if ( $dt->getTimestamp() <= time() ) {
				return array( 'post_status' => 'publish' );
			}
			$local = $dt->format( 'Y-m-d H:i:s' );
			return array( 'post_status' => 'future', 'post_date' => $local, 'post_date_gmt' => get_gmt_from_date( $local ) );
		}
		return array( 'post_status' => 'draft' );
	}

	/** Create today's post for a market. Returns post id or WP_Error. */
	public static function create_draft( $market_id, $image = null ) {
		$m    = NRY_Markets::get( $market_id );
		$item = $m ? NRY_Api::one( $m['main'] ) : null;
		if ( ! $item ) {
			return new WP_Error( 'nry_daily', 'قیمت نماد اصلی در دسترس نیست.' );
		}
		// Keyed by the API's own date, so a closed-market day never produces a second post.
		$key = $market_id . '|' . nry_norm( $item['date'] );
		if ( self::exists( $key ) ) {
			return new WP_Error( 'nry_daily', 'برای این تاریخ قبلاً نوشته ساخته شده است.' );
		}
		$vars  = NRY_Seo::vars( $market_id );
		$title = self::next_title( $market_id, $vars );

		$tables = '';
		foreach ( $m['tables'] as $t ) {
			$tables .= self::snapshot_table( $t );
		}
		$url  = NRY_Pages::landing_url( $market_id );
		$more = '';
		$tpl  = self::next_body( $market_id );
		if ( $url ) {
			$a    = '<a href="' . esc_url( $url ) . '">' . esc_html( $m['title'] ) . '</a>';
			$when = esc_html( $vars['date_long'] . ' ساعت ' . $vars['time'] );
			$more = self::pick(
				array(
					"قیمت‌های این نوشته مربوط به {$when} است. برای قیمت لحظه‌ای، صفحه‌ی {$a} را ببینید.",
					"عددهای بالا مربوط به {$when} است؛ قیمت لحظه‌ای و نمودار را در صفحه‌ی {$a} دنبال کنید.",
					"این قیمت‌ها در {$when} ثبت شده‌اند. آخرین قیمت‌ها همیشه در صفحه‌ی {$a} به‌روز است.",
					"برای دیدن قیمت لحظه‌ای و نمودار، به صفحه‌ی {$a} بروید. قیمت‌های این نوشته مربوط به {$when} است.",
					"قیمت‌های بالا متعلق به {$when} است و صفحه‌ی {$a} آن‌ها را لحظه‌به‌لحظه به‌روز می‌کند.",
				),
				6
			);
		}

		if ( nry_opt( 'daily_analysis', 1 ) ) {
			$content = self::render_body( $tpl, $m, $vars, self::parts( $market_id ), $tables, $more );
		} else {
			$content = "<!-- wp:paragraph -->\n<p>" . esc_html( 'قیمت ' . $m['main_label'] . ' امروز ' . $vars['weekday'] . ' ' . $vars['date_long'] . ' ' . $vars['price'] . ' ' . $vars['unit'] . ' است.' ) . "</p>\n<!-- /wp:paragraph -->\n\n" . $tables
				. ( $more ? "<!-- wp:paragraph -->\n<p>" . $more . "</p>\n<!-- /wp:paragraph -->\n" : '' );
		}

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$post   = array_merge(
			array(
				'post_type'    => 'post',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => get_current_user_id() ? get_current_user_id() : ( $admins ? (int) $admins[0] : 0 ),
			),
			self::publish_fields()
		);
		$cat    = (int) nry_opt( 'daily_cat', 0 );
		if ( $cat ) {
			$post['post_category'] = array( $cat );
		}
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_nry_daily', $key );
		if ( $image ) {
			$att = NRY_Image::attach( $image, $title, $id );
			if ( $att ) {
				set_post_thumbnail( $id, $att );
			}
		}
		return $id;
	}
}
