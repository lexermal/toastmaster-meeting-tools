<?php
/**
 * Uninstall: drops the plugin tables and options.
 *
 * @package TM_Meeting_Tools
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'role_types', 'members', 'meetings', 'agenda_items', 'timings', 'filler_counts', 'feedback' ) as $tmmt_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tmmt_' . $tmmt_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- fixed table names.
}
foreach ( array( 'tmmt_db_version', 'tmmt_tools_password_hash', 'tmmt_color_scheme' ) as $tmmt_option ) {
	delete_option( $tmmt_option );
}
// Rate limit transients expire by themselves; remove any left over.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tmmt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tmmt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
