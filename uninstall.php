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
$hk_admin = get_role( 'administrator' );
if ( $hk_admin ) {
	foreach ( array( 'edit_hk_item', 'read_hk_item', 'delete_hk_item', 'edit_hk_items', 'edit_others_hk_items', 'publish_hk_items', 'read_private_hk_items', 'delete_hk_items', 'delete_private_hk_items', 'delete_published_hk_items', 'delete_others_hk_items', 'edit_private_hk_items', 'edit_published_hk_items', 'manage_auctions', 'manage_hk_item_categories' ) as $hk_cap ) {
		$hk_admin->remove_cap( $hk_cap );
	}
}
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_hk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_hk\\_%'" ); // phpcs:ignore

if ( ! defined( 'HK_REMOVE_ALL_DATA' ) || ! HK_REMOVE_ALL_DATA ) {
	return;
}

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
