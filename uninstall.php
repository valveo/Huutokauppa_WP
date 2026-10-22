<?php
/**
 * Uninstall. Auction data (items, bids, users' auction details) is kept unless
 * wp-config.php defines HK_REMOVE_ALL_DATA as true, so deleting the plugin by
 * accident does not destroy the bid history.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hk_db_version' );
wp_clear_scheduled_hook( 'hk_process_ended' );
remove_role( 'hk_manager' );

if ( ! defined( 'HK_REMOVE_ALL_DATA' ) || ! HK_REMOVE_ALL_DATA ) {
	return;
}

global $wpdb;
foreach ( array( 'bids', 'autobids', 'watch', 'login_attempts' ) as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'hk_' . $t ); // phpcs:ignore
}
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'hk_item' ) );
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}
$terms = get_terms( array( 'taxonomy' => 'hk_category', 'hide_empty' => false, 'fields' => 'ids' ) );
if ( is_array( $terms ) ) {
	foreach ( $terms as $t ) {
		wp_delete_term( (int) $t, 'hk_category' );
	}
}
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'hk\\_%'" ); // phpcs:ignore
delete_option( 'hk_settings' );
