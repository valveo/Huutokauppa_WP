<?php
/**
 * Bidding engine: manual bids, automatic (proxy) bids, anti-sniping.
 *
 * Rules carried over from the original Huutokauppa:
 * - The smallest acceptable bid is the current highest bid + minimum raise,
 *   or the start price + minimum raise when there are no bids yet.
 * - A bid placed less than N seconds (default 300) before the end moves the
 *   end time to N seconds after that bid.
 * - An automatic bid ("korotusautomaatti") bids on the user's behalf up to the
 *   maximum they set. Instead of recording every intermediate step like the old
 *   implementation did, the outcome is calculated directly: the strongest
 *   automatic bidder ends up one minimum raise above the strongest competitor,
 *   capped at their own maximum. Ties go to whoever set that maximum first.
 *
 * All changes to one item happen under a MySQL named lock so that two bids
 * arriving at the same time cannot both be accepted against the same price.
 */

defined( 'ABSPATH' ) || exit;

class HK_Bidding {

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	public static function highest_bid( int $item_id ): ?object {
		global $wpdb;
		$t = HK_Install::table( 'bids' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d ORDER BY amount DESC, id ASC LIMIT 1", $item_id ) );
		return $row ?: null;
	}

	public static function bid_count( int $item_id ): int {
		global $wpdb;
		$t = HK_Install::table( 'bids' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE item_id = %d", $item_id ) );
	}

	/** Newest first. */
	public static function bids( int $item_id, int $limit = 0 ): array {
		global $wpdb;
		$t   = HK_Install::table( 'bids' );
		$sql = $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d ORDER BY id DESC", $item_id ); // phpcs:ignore
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	/** User ids in the order they first bid on the item (for anonymous aliases). */
	public static function bidder_order( int $item_id ): array {
		global $wpdb;
		$t = HK_Install::table( 'bids' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $t WHERE item_id = %d GROUP BY user_id ORDER BY MIN(id)", $item_id ) );
		return array_map( 'intval', $ids );
	}

	public static function get_autobid( int $item_id, int $user_id ): ?object {
		global $wpdb;
		$t = HK_Install::table( 'autobids' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d AND user_id = %d", $item_id, $user_id ) );
		return $row ?: null;
	}

	/** Smallest bid the next bidder may place. */
	public static function next_minimum( int $item_id ): float {
		$high  = self::highest_bid( $item_id );
		$raise = HK_Item::min_raise( $item_id );
		$base  = $high ? (float) $high->amount : HK_Item::start_price( $item_id );
		/**
		 * Whether the first bid must exceed the start price by the minimum raise
		 * (the original behaviour). Return false to accept the start price itself.
		 */
		if ( ! $high && ! apply_filters( 'hk_first_bid_includes_raise', true, $item_id ) ) {
			return round( $base, 2 );
		}
		return round( $base + $raise, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Permission checks
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a user may bid on an item. Returns true or a WP_Error explaining why not.
	 */
	public static function can_bid( int $item_id, int $user_id ) {
		if ( ! $user_id ) {
			return new WP_Error( 'hk_login', __( 'Log in to bid on this item.', 'huutokauppa' ) );
		}
		if ( HK_Users::is_blocked( $user_id ) ) {
			return new WP_Error( 'hk_blocked', __( 'Your account has been blocked from bidding.', 'huutokauppa' ) );
		}
		if ( ! HK_Users::is_activated( $user_id ) ) {
			return new WP_Error( 'hk_inactive', __( 'Confirm your e-mail address before bidding. Check your inbox for the activation link.', 'huutokauppa' ) );
		}
		if ( HK_Item::seller_user( $item_id ) === $user_id ) {
			return new WP_Error( 'hk_own', __( 'You cannot bid on your own item.', 'huutokauppa' ) );
		}
		if ( ! HK_Item::is_live( $item_id ) ) {
			return new WP_Error( 'hk_closed', __( 'Bidding on this item is not open.', 'huutokauppa' ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * Place a manual bid.
	 *
	 * @return array{ok:bool, code:string, message:string, snapshot?:array}
	 */
	public static function place_bid( int $item_id, int $user_id, float $amount ): array {
		return self::locked(
			$item_id,
			function () use ( $item_id, $user_id, $amount ) {
				$check = self::can_bid( $item_id, $user_id );
				if ( is_wp_error( $check ) ) {
					return self::fail( $check->get_error_code(), $check->get_error_message() );
				}
				$min = self::next_minimum( $item_id );
				if ( $amount + 0.0001 < $min ) {
					return self::fail(
						'hk_too_low',
						/* translators: %s: smallest accepted bid */
						sprintf( __( 'The bid is too small. The smallest accepted bid is %s.', 'huutokauppa' ), hk_money( $min ) )
					);
				}

				self::insert_bid( $item_id, $user_id, $amount, false );
				self::resolve_autobids( $item_id );
				self::after_change( $item_id );

				$high    = self::highest_bid( $item_id );
				$leading = $high && (int) $high->user_id === $user_id;
				$message = $leading
					? __( 'Your bid was accepted. You are the highest bidder.', 'huutokauppa' )
					: __( 'Your bid was accepted, but another bidder\'s automatic bid outbid it.', 'huutokauppa' );
				return array(
					'ok'       => true,
					'code'     => $leading ? 'hk_leading' : 'hk_outbid',
					'message'  => $message,
					'snapshot' => HK_Item::snapshot( $item_id, $user_id ),
				);
			}
		);
	}

	/**
	 * Create or update the user's automatic bid. Bids immediately when needed.
	 */
	public static function set_autobid( int $item_id, int $user_id, float $max ): array {
		return self::locked(
			$item_id,
			function () use ( $item_id, $user_id, $max ) {
				$check = self::can_bid( $item_id, $user_id );
				if ( is_wp_error( $check ) ) {
					return self::fail( $check->get_error_code(), $check->get_error_message() );
				}
				$high = self::highest_bid( $item_id );
				$is_leader = $high && (int) $high->user_id === $user_id;
				// A leader may set any maximum above their current bid; others must reach the next minimum.
				$min = $is_leader ? (float) $high->amount + 0.01 : self::next_minimum( $item_id );
				if ( $max + 0.0001 < $min ) {
					return self::fail(
						'hk_too_low',
						/* translators: %s: smallest accepted maximum */
						sprintf( __( 'The maximum is too small. It must be at least %s.', 'huutokauppa' ), hk_money( $min ) )
					);
				}

				global $wpdb;
				$t        = HK_Install::table( 'autobids' );
				$now      = gmdate( 'Y-m-d H:i:s', hk_now() );
				$existing = self::get_autobid( $item_id, $user_id );
				if ( $existing ) {
					$wpdb->update( $t, array( 'max_amount' => $max, 'updated_at' => $now ), array( 'id' => $existing->id ), array( '%f', '%s' ), array( '%d' ) );
				} else {
					$wpdb->insert(
						$t,
						array(
							'item_id'    => $item_id,
							'user_id'    => $user_id,
							'max_amount' => $max,
							'created_at' => $now,
							'updated_at' => $now,
						),
						array( '%d', '%d', '%f', '%s', '%s' )
					);
				}

				$placed = self::resolve_autobids( $item_id );
				if ( $placed ) {
					self::after_change( $item_id );
				}

				$high    = self::highest_bid( $item_id );
				$leading = $high && (int) $high->user_id === $user_id;
				if ( $leading ) {
					$message = $existing ? __( 'Automatic bid updated. You are the highest bidder.', 'huutokauppa' ) : __( 'Automatic bid activated. You are the highest bidder.', 'huutokauppa' );
				} else {
					$message = __( 'Automatic bid saved, but another bidder\'s maximum is higher.', 'huutokauppa' );
				}
				return array(
					'ok'       => true,
					'code'     => $leading ? 'hk_leading' : 'hk_outbid',
					'message'  => $message,
					'snapshot' => HK_Item::snapshot( $item_id, $user_id ),
				);
			}
		);
	}

	public static function remove_autobid( int $item_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( HK_Install::table( 'autobids' ), array( 'item_id' => $item_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
	}

	/**
	 * Let automatic bids respond to the current situation.
	 * Must be called while holding the item lock. Returns true if bids were placed.
	 */
	public static function resolve_autobids( int $item_id ): bool {
		global $wpdb;
		$t = HK_Install::table( 'autobids' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$autos = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d ORDER BY max_amount DESC, updated_at ASC, id ASC", $item_id ) );
		if ( ! $autos ) {
			return false;
		}

		$high   = self::highest_bid( $item_id );
		$leader = $high ? (int) $high->user_id : 0;
		$next   = self::next_minimum( $item_id );
		$raise  = HK_Item::min_raise( $item_id );
		$seller = HK_Item::seller_user( $item_id );

		$eligible = array_values(
			array_filter(
				$autos,
				static fn( $a ) => (float) $a->max_amount + 0.0001 >= $next
					&& (int) $a->user_id !== $seller
					&& ! HK_Users::is_blocked( (int) $a->user_id )
			)
		);
		if ( ! $eligible ) {
			return false;
		}

		$winner = $eligible[0];
		$second = $eligible[1] ?? null;

		if ( (int) $winner->user_id === $leader && ! $second ) {
			return false; // The leader's own automatic bid has nobody to answer.
		}

		// What the winner has to beat: the strongest other automatic bid, or the current price.
		$beat = $second ? (float) $second->max_amount : ( $high ? (float) $high->amount : null );
		if ( null === $beat ) {
			$amount = $next;
		} else {
			$amount = max( $next, min( (float) $winner->max_amount, $beat + $raise ) );
		}
		$amount = round( $amount, 2 );

		// Record the runner-up's final automatic bid so the history shows the competition.
		if ( $second && (float) $second->max_amount < $amount - 0.0001 && (float) $second->max_amount + 0.0001 >= $next ) {
			self::insert_bid( $item_id, (int) $second->user_id, (float) $second->max_amount, true );
		}
		if ( ! ( (int) $winner->user_id === $leader && $high && (float) $high->amount >= $amount ) ) {
			self::insert_bid( $item_id, (int) $winner->user_id, $amount, true );
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	private static function insert_bid( int $item_id, int $user_id, float $amount, bool $auto ): void {
		global $wpdb;
		$wpdb->insert(
			HK_Install::table( 'bids' ),
			array(
				'item_id'    => $item_id,
				'user_id'    => $user_id,
				'amount'     => $amount,
				'is_auto'    => $auto ? 1 : 0,
				'created_at' => gmdate( 'Y-m-d H:i:s', hk_now() ),
			),
			array( '%d', '%d', '%f', '%d', '%s' )
		);
		do_action( 'hk_bid_placed', $item_id, $user_id, $amount, $auto );
	}

	/** Anti-sniping and cached values after bids were placed. */
	private static function after_change( int $item_id ): void {
		$window = (int) HK_Settings::get( 'extend_seconds' );
		$now    = hk_now();
		if ( $window > 0 && HK_Item::end( $item_id ) - $now < $window ) {
			update_post_meta( $item_id, '_hk_end', $now + $window );
		}
		self::refresh_cache( $item_id );
	}

	/** Keep sortable copies of price and bid count in post meta. */
	public static function refresh_cache( int $item_id ): void {
		$high = self::highest_bid( $item_id );
		update_post_meta( $item_id, '_hk_price', $high ? (float) $high->amount : HK_Item::start_price( $item_id ) );
		update_post_meta( $item_id, '_hk_bid_count', self::bid_count( $item_id ) );
	}

	private static function fail( string $code, string $message ): array {
		return array( 'ok' => false, 'code' => $code, 'message' => $message );
	}

	/** Run $fn while holding a per-item MySQL lock. */
	private static function locked( int $item_id, callable $fn ): array {
		global $wpdb;
		$name = substr( 'hk_' . $wpdb->prefix . $item_id, 0, 64 );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) );
		if ( 1 !== $got ) {
			return self::fail( 'hk_busy', __( 'The item is busy. Please try again.', 'huutokauppa' ) );
		}
		try {
			// Fresh meta in case another request changed it while we waited.
			wp_cache_delete( $item_id, 'post_meta' );
			clean_post_cache( $item_id );
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}
