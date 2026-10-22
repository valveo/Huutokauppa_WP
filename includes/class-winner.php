<?php
/**
 * Deciding the outcome of an ended auction.
 */

defined( 'ABSPATH' ) || exit;

class HK_Winner {

	/** Accept the highest bid. Notifies the winner. */
	public static function accept( int $item_id ): bool {
		if ( HK_Item::STATE_ENDED !== HK_Item::state( $item_id ) ) {
			return false;
		}
		$high = HK_Bidding::highest_bid( $item_id );
		if ( ! $high ) {
			self::set_result( $item_id, 'no_bids', 0 );
			return false;
		}
		self::set_result( $item_id, 'accepted', (int) $high->user_id );
		HK_Mailer::send( 'won', (int) $high->user_id, array( 'item_id' => $item_id, 'amount' => $high->amount ) );
		do_action( 'hk_winner_accepted', $item_id, (int) $high->user_id, (float) $high->amount );
		return true;
	}

	/** Reject the highest bid (did not reach the seller's lowest price). Notifies the bidder. */
	public static function reject( int $item_id ): bool {
		if ( HK_Item::STATE_ENDED !== HK_Item::state( $item_id ) ) {
			return false;
		}
		$high = HK_Bidding::highest_bid( $item_id );
		self::set_result( $item_id, $high ? 'rejected' : 'no_bids', 0 );
		if ( $high ) {
			HK_Mailer::send( 'not_sold', (int) $high->user_id, array( 'item_id' => $item_id, 'amount' => $high->amount ) );
		}
		do_action( 'hk_winner_rejected', $item_id );
		return true;
	}

	/** Undo a decision so the auction is "ended, waiting for a decision" again. */
	public static function reset( int $item_id ): void {
		delete_post_meta( $item_id, '_hk_result' );
		delete_post_meta( $item_id, '_hk_winner' );
		delete_post_meta( $item_id, '_hk_result_at' );
	}

	private static function set_result( int $item_id, string $result, int $winner ): void {
		update_post_meta( $item_id, '_hk_result', $result );
		update_post_meta( $item_id, '_hk_winner', $winner );
		update_post_meta( $item_id, '_hk_result_at', hk_now() );
	}
}
