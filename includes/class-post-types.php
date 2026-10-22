<?php
/**
 * Auction item post type, category taxonomy and the "rejected" status.
 */

defined( 'ABSPATH' ) || exit;

class HK_Post_Types {

	const ITEM     = 'hk_item';
	const CATEGORY = 'hk_category';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_post_type(
			self::ITEM,
			array(
				'labels'          => array(
					'name'               => __( 'Auction items', 'huutokauppa' ),
					'singular_name'      => __( 'Auction item', 'huutokauppa' ),
					'menu_name'          => __( 'Auctions', 'huutokauppa' ),
					'add_new'            => __( 'Add item', 'huutokauppa' ),
					'add_new_item'       => __( 'Add auction item', 'huutokauppa' ),
					'edit_item'          => __( 'Edit auction item', 'huutokauppa' ),
					'view_item'          => __( 'View auction item', 'huutokauppa' ),
					'search_items'       => __( 'Search items', 'huutokauppa' ),
					'not_found'          => __( 'No items found.', 'huutokauppa' ),
					'not_found_in_trash' => __( 'No items found in trash.', 'huutokauppa' ),
					'all_items'          => __( 'All items', 'huutokauppa' ),
				),
				'public'          => true,
				'has_archive'     => false,
				'menu_icon'       => 'dashicons-hammer',
				'menu_position'   => 26,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest'    => false,
				'rewrite'         => array(
					/* translators: URL slug for auction items */
					'slug'       => _x( 'item', 'URL slug', 'huutokauppa' ),
					'with_front' => false,
				),
				'capability_type' => array( 'hk_item', 'hk_items' ),
				'map_meta_cap'    => true,
			)
		);

		register_taxonomy(
			self::CATEGORY,
			self::ITEM,
			array(
				'labels'            => array(
					'name'          => __( 'Categories', 'huutokauppa' ),
					'singular_name' => __( 'Category', 'huutokauppa' ),
					'add_new_item'  => __( 'Add category', 'huutokauppa' ),
					'edit_item'     => __( 'Edit category', 'huutokauppa' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					/* translators: URL slug for auction categories */
					'slug'       => _x( 'auction-category', 'URL slug', 'huutokauppa' ),
					'with_front' => false,
				),
				'capabilities'      => array(
					'manage_terms' => 'manage_hk_item_categories',
					'edit_terms'   => 'manage_hk_item_categories',
					'delete_terms' => 'manage_hk_item_categories',
					'assign_terms' => 'edit_hk_items',
				),
			)
		);

		register_post_status(
			'hk_rejected',
			array(
				'label'                     => __( 'Rejected', 'huutokauppa' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of rejected items */
				'label_count'               => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'huutokauppa' ),
			)
		);
	}
}
