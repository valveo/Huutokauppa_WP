<?php
/**
 * Activation: database tables, roles, default pages and cron.
 */

defined( 'ABSPATH' ) || exit;

class HK_Install {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'hk_' . $name;
	}

	public static function activate(): void {
		// plugins_loaded has already run during activation, so load translations here for the page titles.
		load_plugin_textdomain( 'huutokauppa', false, dirname( plugin_basename( HK_FILE ) ) . '/languages' );
		self::create_tables();
		self::add_roles();
		HK_Post_Types::register();
		self::create_pages();
		HK_Cron::schedule();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		HK_Cron::unschedule();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'hk_db_version' ) !== HK_DB_VERSION ) {
			self::create_tables();
			self::add_roles();
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'bids' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			amount decimal(12,2) NOT NULL,
			is_auto tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item_amount (item_id,amount),
			KEY user_id (user_id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'autobids' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			max_amount decimal(12,2) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_user (item_id,user_id),
			KEY user_id (user_id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'watch' ) . " (
			item_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (item_id,user_id),
			KEY user_id (user_id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'login_attempts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			attempted_at int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id,attempted_at)
		) $c;"
		);
		update_option( 'hk_db_version', HK_DB_VERSION );
	}

	/** Capabilities for the auction post type. */
	public static function item_caps(): array {
		return array(
			'edit_hk_item', 'read_hk_item', 'delete_hk_item',
			'edit_hk_items', 'edit_others_hk_items', 'publish_hk_items', 'read_private_hk_items',
			'delete_hk_items', 'delete_private_hk_items', 'delete_published_hk_items', 'delete_others_hk_items',
			'edit_private_hk_items', 'edit_published_hk_items',
			'manage_auctions',
		);
	}

	public static function add_roles(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::item_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		$caps = array_fill_keys( self::item_caps(), true );
		$caps += array(
			'read'         => true,
			'upload_files' => true,
			'list_users'   => true,
			'manage_hk_item_categories' => true,
		);
		remove_role( 'hk_manager' );
		add_role( 'hk_manager', __( 'Auction manager', 'huutokauppa' ), $caps );
		if ( $admin ) {
			$admin->add_cap( 'manage_hk_item_categories' );
		}
	}

	public static function create_pages(): void {
		$pages = array(
			'auctions' => array( __( 'Auctions', 'huutokauppa' ), '[huutokauppa]' ),
			'account'  => array( __( 'My bids', 'huutokauppa' ), '[huutokauppa_omat]' ),
			'submit'   => array( __( 'Submit an item', 'huutokauppa' ), '[huutokauppa_ilmoita]' ),
			'register' => array( __( 'Register', 'huutokauppa' ), '[huutokauppa_rekisteroidy]' ),
			'profile'  => array( __( 'My details', 'huutokauppa' ), '[huutokauppa_tiedot]' ),
		);
		$update = array();
		foreach ( $pages as $key => [ $title, $shortcode ] ) {
			$existing = (int) HK_Settings::get( 'page_' . $key );
			if ( $existing && get_post( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$update[ 'page_' . $key ] = $id;
			}
		}
		if ( $update ) {
			HK_Settings::update( $update );
		}
	}
}
