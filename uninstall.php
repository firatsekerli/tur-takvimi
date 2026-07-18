<?php
/**
 * Uninstall cleanup.
 *
 * Removes plugin options and custom tables. Content (locations/routes) is
 * left intact by design — deleting posts on uninstall is destructive and
 * unexpected. Drop tables only.
 *
 * @package TurTakvimi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'tur_takvimi_settings' );
delete_option( 'tur_takvimi_version' );
delete_option( 'tur_takvimi_db_version' );
delete_option( 'tur_takvimi_wa_groups' );
delete_option( 'tur_takvimi_wa_channels' );
delete_option( 'tur_takvimi_wa_api' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tt_schedule" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tt_postcodes" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tt_subscribers" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tt_notify_log" ); // phpcs:ignore WordPress.DB

foreach ( array( 'tur_takvimi_daily_regen', 'tur_takvimi_daily_notify' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
}
