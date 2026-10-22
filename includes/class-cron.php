<?php
/**
 * Background processing of ended auctions.
 *
 * WP-Cron only runs when the site gets visits. For exact timing, disable it in
 * wp-config.php (DISABLE_WP_CRON) and call wp-cron.php from the system cron
 * every few minutes. Bidding itself never depends on cron: an auction closes
 * the moment its end time passes.
 */

defined( 'ABSPATH' ) || exit;

class HK_Cron {

	const HOOK = 'hk_process_ended';

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'process_ended' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedules( array $s ): array {
		$s['hk_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'huutokauppa' ),
		);
		return $s;
	}

	public static function schedule(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hk_five_minutes', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Returns the ids of items that were waiting for a decision and got reported. */
	public static function process_ended(): array {
		$ids = get_posts(
			array(
				'post_type'      => HK_Post_Types::ITEM,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_hk_end',
						'value'   => hk_now(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_hk_result',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_hk_end_notified',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$report = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( HK_Item::STATE_ENDED !== HK_Item::state( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_hk_end_notified', hk_now() );
			if ( ! HK_Bidding::highest_bid( $id ) ) {
				update_post_meta( $id, '_hk_result', 'no_bids' );
				update_post_meta( $id, '_hk_result_at', hk_now() );
				continue;
			}
			if ( HK_Settings::get( 'auto_accept' ) ) {
				HK_Winner::accept( $id );
				continue;
			}
			$report[] = $id;
		}

		if ( $report && HK_Settings::get( 'notify_email' ) ) {
			HK_Mailer::send( 'ended', HK_Settings::get( 'notify_email' ), array( 'items' => $report ) );
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . HK_Install::table( 'login_attempts' ) . ' WHERE attempted_at < %d', hk_now() - DAY_IN_SECONDS ) ); // phpcs:ignore

		return $report;
	}
}
