<?php
/**
 * REST API used by the front-end script for live updates and bidding.
 *
 * GET    /huutokauppa/v1/status?ids=1,2,3
 * POST   /huutokauppa/v1/items/{id}/bid       amount
 * POST   /huutokauppa/v1/items/{id}/autobid   max
 * DELETE /huutokauppa/v1/items/{id}/autobid
 * POST   /huutokauppa/v1/items/{id}/watch
 */

defined( 'ABSPATH' ) || exit;

class HK_Rest {

	const NS = 'huutokauppa/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids'     => array( 'required' => true ),
					'history' => array( 'required' => false ),
				),
			)
		);
		$item = '/items/(?P<id>\d+)';
		register_rest_route(
			self::NS,
			$item . '/bid',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bid' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			self::NS,
			$item . '/autobid',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'autobid' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'remove_autobid' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
		register_rest_route(
			self::NS,
			$item . '/watch',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'watch' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/** Snapshot with display strings added. */
	public static function public_snapshot( int $id, bool $with_history = false ): array {
		$user = get_current_user_id();
		$s    = HK_Item::snapshot( $id, $user );
		$s['price_text']    = null === $s['price'] ? __( 'No bids', 'huutokauppa' ) : hk_money( $s['price'] );
		$s['next_min_text'] = hk_money( $s['next_min'] );
		$s['end_text']      = $s['end'] ? hk_format_time( $s['end'] ) : '';
		$s['my_max_text']   = null === $s['my_max'] ? '' : hk_money( $s['my_max'] );
		if ( $with_history ) {
			$s['history'] = self::history( $id );
		}
		return $s;
	}

	public static function history( int $id, int $limit = 10 ): array {
		$user = get_current_user_id();
		$out  = array();
		foreach ( HK_Bidding::bids( $id, $limit ) as $b ) {
			$out[] = array(
				'bidder' => hk_bidder_alias( $id, (int) $b->user_id ),
				'mine'   => $user && (int) $b->user_id === $user,
				'amount' => hk_money( $b->amount ),
				'auto'   => (bool) $b->is_auto,
				'time'   => hk_format_time( (int) strtotime( $b->created_at . ' UTC' ), false ),
			);
		}
		return $out;
	}

	private static function visible_item( int $id ): bool {
		$post = get_post( $id );
		return $post && HK_Post_Types::ITEM === $post->post_type && 'publish' === $post->post_status;
	}

	public static function status( WP_REST_Request $r ): WP_REST_Response {
		$ids = array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $r['ids'] ) ) ) ), 0, 50 );
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::visible_item( $id ) ) {
				$out[] = self::public_snapshot( $id, (bool) $r['history'] );
			}
		}
		$res = new WP_REST_Response( array( 'now' => hk_now(), 'items' => $out ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function result( array $r, int $id ): WP_REST_Response {
		if ( $r['ok'] ) {
			$r['snapshot'] = self::public_snapshot( $id, true );
		}
		return new WP_REST_Response( $r, $r['ok'] ? 200 : 400 );
	}

	public static function bid( WP_REST_Request $r ): WP_REST_Response {
		$id     = (int) $r['id'];
		$amount = hk_parse_amount( $r['amount'] );
		if ( ! self::visible_item( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'hk_not_found', 'message' => __( 'Item not found.', 'huutokauppa' ) ), 404 );
		}
		if ( null === $amount ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'hk_amount', 'message' => __( 'Enter the bid as a number, for example 150.', 'huutokauppa' ) ), 400 );
		}
		return self::result( HK_Bidding::place_bid( $id, get_current_user_id(), $amount ), $id );
	}

	public static function autobid( WP_REST_Request $r ): WP_REST_Response {
		$id  = (int) $r['id'];
		$max = hk_parse_amount( $r['max'] );
		if ( ! self::visible_item( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'hk_not_found', 'message' => __( 'Item not found.', 'huutokauppa' ) ), 404 );
		}
		if ( null === $max ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'hk_amount', 'message' => __( 'Enter the maximum as a number, for example 500.', 'huutokauppa' ) ), 400 );
		}
		return self::result( HK_Bidding::set_autobid( $id, get_current_user_id(), $max ), $id );
	}

	public static function remove_autobid( WP_REST_Request $r ): WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! self::visible_item( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'hk_not_found', 'message' => __( 'Item not found.', 'huutokauppa' ) ), 404 );
		}
		HK_Bidding::remove_autobid( $id, get_current_user_id() );
		return self::result( array( 'ok' => true, 'code' => 'hk_removed', 'message' => __( 'Automatic bid removed.', 'huutokauppa' ) ), $id );
	}

	public static function watch( WP_REST_Request $r ): WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! self::visible_item( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$on = HK_Users::toggle_watch( $id, get_current_user_id() );
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'watching' => $on,
				'message'  => $on ? __( 'Added to your watch list.', 'huutokauppa' ) : __( 'Removed from your watch list.', 'huutokauppa' ),
			)
		);
	}
}
