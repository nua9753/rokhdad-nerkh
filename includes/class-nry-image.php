<?php
defined( 'ABSPATH' ) || exit;

/**
 * Daily share image (1200×675 PNG) drawn with GD.
 * GD does not shape or reorder Persian text, so this class does both: letters are mapped to their
 * joined presentation forms and each word is reversed into visual order. The font must contain the
 * Arabic presentation forms (Vazirmatn, Vazir, Sahel, IRANSans and most Persian fonts do).
 */
class NRY_Image {

	/** letter => isolated, final, initial, medial (code points; 0 = form does not exist) */
	const FORMS = array(
		0x0621 => array( 0xFE80, 0, 0, 0 ),
		0x0622 => array( 0xFE81, 0xFE82, 0, 0 ),
		0x0623 => array( 0xFE83, 0xFE84, 0, 0 ),
		0x0624 => array( 0xFE85, 0xFE86, 0, 0 ),
		0x0625 => array( 0xFE87, 0xFE88, 0, 0 ),
		0x0626 => array( 0xFE89, 0xFE8A, 0xFE8B, 0xFE8C ),
		0x0627 => array( 0xFE8D, 0xFE8E, 0, 0 ),
		0x0628 => array( 0xFE8F, 0xFE90, 0xFE91, 0xFE92 ),
		0x067E => array( 0xFB56, 0xFB57, 0xFB58, 0xFB59 ),
		0x0629 => array( 0xFE93, 0xFE94, 0, 0 ),
		0x062A => array( 0xFE95, 0xFE96, 0xFE97, 0xFE98 ),
		0x062B => array( 0xFE99, 0xFE9A, 0xFE9B, 0xFE9C ),
		0x062C => array( 0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0 ),
		0x0686 => array( 0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D ),
		0x062D => array( 0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4 ),
		0x062E => array( 0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8 ),
		0x062F => array( 0xFEA9, 0xFEAA, 0, 0 ),
		0x0630 => array( 0xFEAB, 0xFEAC, 0, 0 ),
		0x0631 => array( 0xFEAD, 0xFEAE, 0, 0 ),
		0x0632 => array( 0xFEAF, 0xFEB0, 0, 0 ),
		0x0698 => array( 0xFB8A, 0xFB8B, 0, 0 ),
		0x0633 => array( 0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4 ),
		0x0634 => array( 0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8 ),
		0x0635 => array( 0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC ),
		0x0636 => array( 0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0 ),
		0x0637 => array( 0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4 ),
		0x0638 => array( 0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8 ),
		0x0639 => array( 0xFEC9, 0xFECA, 0xFECB, 0xFECC ),
		0x063A => array( 0xFECD, 0xFECE, 0xFECF, 0xFED0 ),
		0x0641 => array( 0xFED1, 0xFED2, 0xFED3, 0xFED4 ),
		0x0642 => array( 0xFED5, 0xFED6, 0xFED7, 0xFED8 ),
		0x0643 => array( 0xFED9, 0xFEDA, 0xFEDB, 0xFEDC ),
		0x06A9 => array( 0xFB8E, 0xFB8F, 0xFB90, 0xFB91 ),
		0x06AF => array( 0xFB92, 0xFB93, 0xFB94, 0xFB95 ),
		0x0644 => array( 0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0 ),
		0x0645 => array( 0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4 ),
		0x0646 => array( 0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8 ),
		0x0648 => array( 0xFEED, 0xFEEE, 0, 0 ),
		0x0647 => array( 0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC ),
		0x0649 => array( 0xFEEF, 0xFEF0, 0, 0 ),
		0x064A => array( 0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4 ),
		0x06CC => array( 0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF ),
	);

	/** lam + alef variant => isolated ligature, final ligature */
	const LAM_ALEF = array(
		0x0627 => array( 0xFEFB, 0xFEFC ),
		0x0622 => array( 0xFEF5, 0xFEF6 ),
		0x0623 => array( 0xFEF7, 0xFEF8 ),
		0x0625 => array( 0xFEF9, 0xFEFA ),
	);

	public static function dir() {
		$u = wp_upload_dir();
		return array(
			'path' => trailingslashit( $u['basedir'] ) . 'rokhdad-nerkh',
			'url'  => trailingslashit( $u['baseurl'] ) . 'rokhdad-nerkh',
		);
	}

	/** Path of the TTF used for drawing: the uploaded one, else any font shipped in the plugin's fonts folder. */
	public static function font() {
		$d    = self::dir();
		$path = '';
		if ( file_exists( $d['path'] . '/font.ttf' ) ) {
			$path = $d['path'] . '/font.ttf';
		} else {
			$bundled = glob( NRY_DIR . 'fonts/*.ttf' );
			if ( $bundled ) {
				$path = $bundled[0];
			}
		}
		return apply_filters( 'nry_image_font', $path );
	}

	public static function ready() {
		return function_exists( 'imagettftext' ) && '' !== (string) self::font();
	}

	public static function latest_url( $market_id ) {
		$d    = self::dir();
		$file = $d['path'] . '/' . sanitize_key( $market_id ) . '-latest.png';
		return file_exists( $file ) ? $d['url'] . '/' . sanitize_key( $market_id ) . '-latest.png?v=' . filemtime( $file ) : '';
	}

	private static function chr( $cp ) {
		return function_exists( 'mb_chr' ) ? mb_chr( $cp, 'UTF-8' ) : html_entity_decode( '&#' . $cp . ';', ENT_NOQUOTES, 'UTF-8' );
	}

	private static function has_arabic( $text ) {
		return (bool) preg_match( '/[\x{0621}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}]/u', $text );
	}

	/** One word: join its letters and return it in visual (left-to-right) order. */
	public static function shape( $word ) {
		if ( ! self::has_arabic( $word ) ) {
			return $word;
		}
		$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
		$cps   = array();
		foreach ( $chars as $c ) {
			$cps[] = function_exists( 'mb_ord' ) ? mb_ord( $c, 'UTF-8' ) : hexdec( bin2hex( mb_convert_encoding( $c, 'UTF-32BE', 'UTF-8' ) ) );
		}
		$out = array();
		$n   = count( $cps );
		for ( $i = 0; $i < $n; $i++ ) {
			$cp = $cps[ $i ];
			if ( 0x200C === $cp ) { // half-space: breaks joining, draws nothing
				continue;
			}
			if ( ! isset( self::FORMS[ $cp ] ) ) {
				$out[] = $cp;
				continue;
			}
			$prev      = $i > 0 ? $cps[ $i - 1 ] : 0;
			$next      = $i + 1 < $n ? $cps[ $i + 1 ] : 0;
			$join_prev = isset( self::FORMS[ $prev ] ) && 0 !== self::FORMS[ $prev ][2];
			$join_next = isset( self::FORMS[ $next ] ) && 0 !== self::FORMS[ $next ][1];
			$dual      = 0 !== self::FORMS[ $cp ][2];

			if ( 0x0644 === $cp && isset( self::LAM_ALEF[ $next ] ) ) {
				$out[] = self::LAM_ALEF[ $next ][ $join_prev ? 1 : 0 ];
				$i++;
				continue;
			}
			if ( $join_prev && $join_next && $dual ) {
				$out[] = self::FORMS[ $cp ][3];
			} elseif ( $join_prev && 0 !== self::FORMS[ $cp ][1] ) {
				$out[] = self::FORMS[ $cp ][1];
			} elseif ( $join_next && $dual ) {
				$out[] = self::FORMS[ $cp ][2];
			} else {
				$out[] = self::FORMS[ $cp ][0];
			}
		}
		$s = '';
		foreach ( array_reverse( $out ) as $cp ) {
			$s .= self::chr( $cp );
		}
		return $s;
	}

	private static function width( $size, $font, $text ) {
		$b = imagettfbbox( $size, 0, $font, $text );
		return $b ? abs( $b[2] - $b[0] ) : 0;
	}

	/** Words of a right-to-left line, shaped, with their widths. */
	private static function layout( $size, $font, $text ) {
		$words = array();
		$total = 0;
		$space = (int) round( $size * 0.5 );
		foreach ( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$shaped  = self::shape( $w );
			$width   = self::width( $size, $font, $shaped );
			$words[] = array( $shaped, $width );
			$total  += $width;
		}
		$total += $space * max( 0, count( $words ) - 1 );
		return array( $words, $total, $space );
	}

	/** Draw a line whose right edge is at $right. Returns the x of its left edge. */
	private static function text_right( $im, $size, $right, $baseline, $color, $font, $text ) {
		list( $words, $total, $space ) = self::layout( $size, $font, $text );
		$x = $right;
		foreach ( $words as $w ) {
			$x -= $w[1];
			imagettftext( $im, $size, 0, (int) $x, (int) $baseline, $color, $font, $w[0] );
			$x -= $space;
		}
		return $right - $total;
	}

	private static function text_left( $im, $size, $left, $baseline, $color, $font, $text ) {
		list( , $total ) = self::layout( $size, $font, $text );
		self::text_right( $im, $size, $left + $total, $baseline, $color, $font, $text );
	}

	private static function text_center( $im, $size, $cx, $baseline, $color, $font, $text ) {
		list( , $total ) = self::layout( $size, $font, $text );
		self::text_right( $im, $size, $cx + $total / 2, $baseline, $color, $font, $text );
	}

	private static function color( $im, $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			$hex = '000000';
		}
		return imagecolorallocate( $im, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Build today's image for a market. Returns array( path, url ) or WP_Error. */
	public static function generate( $market_id ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'nry_image', 'برای ساخت تصویر، کتابخانه‌ی GD با FreeType و یک فونت TTF لازم است.' );
		}
		$m    = NRY_Markets::get( $market_id );
		$item = $m ? NRY_Api::one( $m['main'] ) : null;
		if ( ! $item ) {
			return new WP_Error( 'nry_image', 'قیمت نماد اصلی در دسترس نیست.' );
		}
		$d    = NRY_Api::display( $item );
		$font = self::font();
		$W    = 1200;
		$H    = 675;
		$im   = imagecreatetruecolor( $W, $H );
		imageantialias( $im, true );

		$navy  = self::color( $im, nry_opt( 'navy' ) );
		$gold  = self::color( $im, nry_opt( 'gold' ) );
		$red   = self::color( $im, nry_opt( 'red' ) );
		$deep  = self::color( $im, '#8f6200' );
		$white = self::color( $im, '#ffffff' );
		$pale  = self::color( $im, '#dbe6ff' );

		imagefilledrectangle( $im, 0, 0, $W, $H, $navy );
		imagefilledrectangle( $im, 0, 0, $W, 8, $red );

		// Title row on navy.
		$left = self::text_right( $im, 46, 1140, 104, $white, $font, $m['title'] );
		imagefilledrectangle( $im, (int) $left - 34, 70, (int) $left - 16, 88, $red );
		self::text_left( $im, 26, 60, 100, $pale, $font, nry_weekday( $item['unix'] ? $item['unix'] : null ) . ' ' . nry_date_long( $d['date'] ) );

		// The slab.
		imagefilledrectangle( $im, 40, 150, 1160, 600, $gold );
		imagesetthickness( $im, 2 );
		imagerectangle( $im, 54, 164, 1146, 586, $deep );

		self::text_right( $im, 34, 1096, 240, $navy, $font, $m['main_label'] );

		// Purity / symbol stamp.
		imagesetthickness( $im, 4 );
		imagearc( $im, 160, 232, 116, 116, 0, 360, $navy );
		self::text_center( $im, 28, 160, 236, $navy, $font, $m['stamp'][0] );
		if ( ! empty( $m['stamp'][1] ) ) {
			self::text_center( $im, 14, 160, 264, $navy, $font, $m['stamp'][1] );
		}

		// Price and unit.
		$price = nry_num( $d['price'] );
		$size  = 120;
		while ( $size > 60 && self::width( $size, $font, $price ) > 760 ) {
			$size -= 6;
		}
		$pw = self::width( $size, $font, $price );
		imagettftext( $im, $size, 0, (int) ( 1096 - $pw ), 420, $navy, $font, $price );
		self::text_right( $im, 36, 1096 - $pw - 28, 420, $navy, $font, $d['unit'] );

		imagesetthickness( $im, 2 );
		imageline( $im, 96, 470, 1104, 470, $deep );

		// Change today.
		$lx = self::text_right( $im, 28, 1096, 540, $navy, $font, 'تغییر امروز' );
		$pct = nry_pct( $d['pct'] );
		$pcw = self::width( 36, $font, $pct );
		$px  = $lx - 36 - $pcw;
		imagettftext( $im, 36, 0, (int) $px, 542, $navy, $font, $pct );
		if ( 0.0 !== (float) $d['pct'] ) {
			$ax = $px - 44;
			$up = $d['pct'] > 0;
			imagefilledpolygon(
				$im,
				$up ? array( $ax, 540, $ax + 30, 540, $ax + 15, 512 ) : array( $ax, 512, $ax + 30, 512, $ax + 15, 540 ),
				$navy
			);
		}
		self::text_left( $im, 24, 96, 540, $navy, $font, 'ساعت ' . nry_fa( $d['time'] ) );

		// Site name.
		self::text_right( $im, 24, 1140, 648, $pale, $font, get_bloginfo( 'name' ) );

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			imagedestroy( $im );
			return new WP_Error( 'nry_image', 'پوشه‌ی uploads/rokhdad-nerkh قابل نوشتن نیست.' );
		}
		$stamp = preg_replace( '/\D/', '', nry_norm( $d['date'] ) );
		$name  = sanitize_key( $market_id ) . '-' . ( $stamp ? $stamp : gmdate( 'Ymd' ) ) . '.png';
		$path  = $dir['path'] . '/' . $name;
		$ok    = imagepng( $im, $path, 8 );
		imagedestroy( $im );
		if ( ! $ok ) {
			return new WP_Error( 'nry_image', 'ذخیره‌ی تصویر ناموفق بود.' );
		}
		copy( $path, $dir['path'] . '/' . sanitize_key( $market_id ) . '-latest.png' );
		return array( 'path' => $path, 'url' => $dir['url'] . '/' . $name );
	}

	/** Register a generated file in the media library and return the attachment id. */
	public static function attach( $file, $title, $parent = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => sanitize_text_field( $title ),
				'post_status'    => 'inherit',
				'guid'           => $file['url'],
			),
			$file['path'],
			$parent
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['path'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
		return (int) $id;
	}
}
