<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases, shown and installed by WordPress like any plugin from the directory.
 *
 * Each release on the repository in NRY_GITHUB_REPO carries a tag (v0.7.0 or 0.7.0) and an asset
 * named rokhdad-nerkh.zip. A private repository also needs, in wp-config.php:
 *   define( 'NRY_GITHUB_TOKEN', 'github_pat_…' );   // read-only token for that one repository
 */
class NRY_Updater {

	const ASSET = 'rokhdad-nerkh.zip';
	const CACHE = 'nry_gh_release';

	public static function init() {
		if ( ! defined( 'NRY_GITHUB_REPO' ) || ! preg_match( '#^[\w.-]+/[\w.-]+$#', NRY_GITHUB_REPO ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . self::basename(), array( __CLASS__, 'links' ) );
		add_action( 'admin_post_nry_check_update', array( __CLASS__, 'check_now' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
	}

	private static function basename() {
		return plugin_basename( NRY_DIR . 'rokhdad-nerkh.php' );
	}

	private static function token() {
		return defined( 'NRY_GITHUB_TOKEN' ) ? trim( (string) NRY_GITHUB_TOKEN ) : '';
	}

	/** Latest release as array( version, package, url, notes, date ), cached for six hours. */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . NRY_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$data = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		$out  = array();
		if ( 200 === (int) wp_remote_retrieve_response_code( $res ) && is_array( $data ) && ! empty( $data['tag_name'] ) ) {
			$package = '';
			foreach ( isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
				if ( isset( $asset['name'] ) && self::ASSET === $asset['name'] ) {
					// A private repository's assets are only reachable through the API address.
					$package = self::token() ? $asset['url'] : $asset['browser_download_url'];
				}
			}
			$out = array(
				'version' => ltrim( (string) $data['tag_name'], 'vV' ),
				'package' => $package ? $package : ( isset( $data['zipball_url'] ) ? $data['zipball_url'] : '' ),
				'url'     => isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . NRY_GITHUB_REPO,
				'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
				'date'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			);
		}
		// A failed check is remembered for an hour, so a blocked GitHub never slows the dashboard.
		set_site_transient( self::CACHE, $out, $out ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $out;
	}

	private static function info( $rel ) {
		return (object) array(
			'id'           => 'github.com/' . NRY_GITHUB_REPO,
			'slug'         => 'rokhdad-nerkh',
			'plugin'       => self::basename(),
			'new_version'  => $rel['version'],
			'package'      => $rel['package'],
			'url'          => $rel['url'],
			'requires_php' => '7.4',
			'icons'        => array( '1x' => NRY_URL . 'assets/logo.png' ),
		);
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel = self::release();
		if ( empty( $rel['version'] ) || empty( $rel['package'] ) ) {
			return $transient;
		}
		$file = self::basename();
		if ( version_compare( $rel['version'], NRY_VER, '>' ) ) {
			$transient->response[ $file ] = self::info( $rel );
		} else {
			unset( $transient->response[ $file ] );
			// Listed as up to date, which also lets the auto-update toggle appear.
			$transient->no_update[ $file ] = self::info( $rel );
		}
		return $transient;
	}

	/** The «View details» window. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'rokhdad-nerkh' !== $args->slug ) {
			return $result;
		}
		$rel = self::release();
		if ( empty( $rel['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'رخداد نرخ',
			'slug'          => 'rokhdad-nerkh',
			'version'       => $rel['version'],
			'author'        => 'گسترش نیوز',
			'homepage'      => $rel['url'],
			'download_link' => $rel['package'],
			'last_updated'  => $rel['date'],
			'requires_php'  => '7.4',
			'sections'      => array(
				'changelog' => '' !== trim( $rel['notes'] ) ? wpautop( esc_html( $rel['notes'] ) ) : '<p>نسخه‌ی ' . esc_html( $rel['version'] ) . '</p>',
			),
		);
	}

	/** Token for the private repository, sent to GitHub only. */
	public static function auth( $args, $url ) {
		$token = self::token();
		if ( '' === $token || 0 !== strpos( $url, 'https://api.github.com/repos/' . NRY_GITHUB_REPO . '/' ) ) {
			return $args;
		}
		$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Authorization'] = 'Bearer ' . $token;
		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
		return $args;
	}

	/** GitHub's source archive unpacks to owner-repo-hash/; the plugin must stay in rokhdad-nerkh/. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( empty( $extra['plugin'] ) || self::basename() !== $extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . 'rokhdad-nerkh/';
		if ( trailingslashit( $source ) === $want ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want ) ) {
			return $want;
		}
		return new WP_Error( 'nry_update_folder', 'پوشه‌ی به‌روزرسانی رخداد نرخ قابل جابه‌جایی نبود.' );
	}

	public static function links( $links ) {
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=nry_check_update' ), 'nry_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">بررسی به‌روزرسانی</a>';
		return $links;
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'nry_check_update' );
		self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}
}
