<?php
/** Remove opt-in plugin data only; never remove products, orders or stock. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$andiya_pg_settings = get_option( 'apg_settings', array() );
if ( empty( $andiya_pg_settings['delete_data'] ) ) {
	return; }
global $wpdb;
foreach ( array( 'batches', 'rows', 'baselines', 'quotes', 'groups', 'sources', 'audit' ) as $andiya_pg_table ) {
	$andiya_pg_name = $wpdb->prefix . 'apg_' . $andiya_pg_table;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $andiya_pg_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static plugin-owned table names.
}
foreach ( array( '_apg_state', '_apg_exclude', '_apg_checked', '_apg_expires', '_apg_source' ) as $andiya_pg_key ) {
	delete_metadata( 'post', 0, $andiya_pg_key, '', true ); }
foreach ( array( 'apg_settings', 'apg_schema', 'apg_running', 'apg_deactivation_report', 'apg_deactivating' ) as $andiya_pg_option ) {
	delete_option( $andiya_pg_option ); }
foreach ( array( 'administrator', 'shop_manager' ) as $andiya_pg_role_name ) {
	$andiya_pg_role = get_role( $andiya_pg_role_name );
	if ( $andiya_pg_role ) {
		foreach ( array( 'apg_manage_prices', 'apg_manage_quotes', 'apg_view_history' ) as $andiya_pg_cap ) {
			$andiya_pg_role->remove_cap( $andiya_pg_cap ); }
	}
}
wp_clear_scheduled_hook( 'apg_process' );
wp_clear_scheduled_hook( 'apg_cleanup' );
wp_clear_scheduled_hook( 'apg_sources_tick' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'apg_lock_' ) . '%', $wpdb->esc_like( '_transient_apg_rate_' ) . '%', $wpdb->esc_like( '_transient_timeout_apg_rate_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
