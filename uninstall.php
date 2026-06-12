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

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tt_schedule" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tt_postcodes" ); // phpcs:ignore WordPress.DB

$timestamp = wp_next_scheduled( 'tur_takvimi_daily_regen' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'tur_takvimi_daily_regen' );
}
