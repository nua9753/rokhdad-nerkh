<?php
/**
 * Plugin Name: رخداد نرخ — قیمت لحظه‌ای طلا و ارز
 * Description: لندینگ، کارت قیمت، جدول، نمودار و ماشین‌حساب طلا و ارز با داده‌ی BrsApi. صفحه‌ی مستقل با نامک دلخواه، تزریق در برگه‌ها و نوشته‌ها، شورت‌کد برای هر ماژول، هماهنگ با Yoast SEO و Rank Math.
 * Version: 0.7.5
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Gostaresh News
 * Text Domain: rokhdad-nerkh
 * Update URI: https://github.com/nua9753/rokhdad-nerkh
 */

defined( 'ABSPATH' ) || exit;

define( 'NRY_VER', '0.7.5' );
define( 'NRY_FILE', __FILE__ );
define( 'NRY_DIR', plugin_dir_path( __FILE__ ) );
define( 'NRY_URL', plugin_dir_url( __FILE__ ) );
// Updates come from this repository's Releases. Can be overridden in wp-config.php.
if ( ! defined( 'NRY_GITHUB_REPO' ) ) {
	define( 'NRY_GITHUB_REPO', 'nua9753/rokhdad-nerkh' );
}

require_once NRY_DIR . 'includes/functions.php';
require_once NRY_DIR . 'includes/class-nry-history.php';
require_once NRY_DIR . 'includes/class-nry-api.php';
require_once NRY_DIR . 'includes/class-nry-markets.php';
require_once NRY_DIR . 'includes/class-nry-image.php';
require_once NRY_DIR . 'includes/class-nry-seo.php';
require_once NRY_DIR . 'includes/class-nry-render.php';
require_once NRY_DIR . 'includes/class-nry-shortcodes.php';
require_once NRY_DIR . 'includes/class-nry-pages.php';
require_once NRY_DIR . 'includes/class-nry-autolink.php';
require_once NRY_DIR . 'includes/class-nry-daily.php';
require_once NRY_DIR . 'includes/class-nry-rest.php';
require_once NRY_DIR . 'includes/class-nry-admin.php';
require_once NRY_DIR . 'includes/class-nry-wizard.php';
require_once NRY_DIR . 'includes/class-nry-updater.php';

add_action(
	'plugins_loaded',
	function () {
		NRY_History::init();
		NRY_Api::init();
		NRY_Seo::init();
		NRY_Shortcodes::init();
		NRY_Pages::init();
		NRY_Autolink::init();
		NRY_Daily::init();
		NRY_Rest::init();
		if ( is_admin() ) {
			NRY_Admin::init();
			NRY_Wizard::init();
		}
		if ( is_admin() || wp_doing_cron() ) {
			NRY_Updater::init();
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		NRY_Api::init();
		NRY_History::install();
		NRY_Api::ensure_schedule();
		if ( '' === trim( (string) nry_opt( 'api_key', '' ) ) ) {
			update_option( 'nry_open_wizard', 1, false );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'nry_refresh' );
		wp_clear_scheduled_hook( 'nry_daily' );
	}
);
