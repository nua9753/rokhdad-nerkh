<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'nry_settings' );
delete_option( 'nry_prices' );
delete_option( 'nry_last_error' );
delete_option( 'nry_open_wizard' );
wp_clear_scheduled_hook( 'nry_refresh' );
wp_clear_scheduled_hook( 'nry_daily' );

global $wpdb;
// Remove cached chart history.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_nry\_%' OR option_name LIKE '\_transient\_timeout\_nry\_%'" ); // phpcs:ignore WordPress.DB

// Remove the locally recorded price history.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nry_history" ); // phpcs:ignore WordPress.DB
delete_option( 'nry_db_ver' );
delete_option( 'nry_title_idx' );
delete_option( 'nry_body_idx' );
delete_site_transient( 'nry_gh_release' );
delete_option( 'nry_repair_071' );
delete_option( 'nry_excerpt_last' );
