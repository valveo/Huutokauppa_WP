<?php
/**
 * Read/write access to an auction item's data.
 *
 * Times are stored as UNIX timestamps (UTC). Prices as decimal strings.
 */

defined( 'ABSPATH' ) || exit;

class HK_Item {

	const STATE_DRAFT    = 'draft';     // Not published (pending approval, draft, rejected).
	const STATE_UPCOMING = 'upcoming';  // Published, start time in the future.
	const STATE_LIVE     = 'live';      // Bidding open.
	const STATE_ENDED    = 'ended';     // Time is up, waiting for a decision.
	const STATE_SOLD     = 'sold';      // Winner accepted.
	const STATE_UNSOLD   = 'unsold';    // Rejected or no bids.

	const SELLER_FIELDS = array( 'name', 'business_id', 'address', 'postcode', 'city', 'phone', 'email' );

	public static function is_item( $post ): bool {
		$post = get_post( $post );
		return $post && HK_Post_Types::ITEM === $post->post_type;
	}

	public static function start( int $id ): int {
		return (int) get_post_meta( $id, '_hk_start', true );
	}

	public static function end( int $id ): int {
		return (int) get_post_meta( $id, '_hk_end', true );
	}

	public static function start_price( int $id ): float {
		return (float) get_post_meta( $id, '_hk_start_price', true );
	}

	public static function min_raise( int $id ): float {
		$v = (float) get_post_meta( $id, '_hk_min_raise', true );
		return $v > 0 ? $v : (float) HK_Settings::get( 'default_min_raise' );
	}

	/** 'plus' = VAT added on top, 'margin' = VAT 0 % / margin scheme, 'none' = nothing shown. */
	public static function vat_mode( int $id ): string {
		$v = (string) get_post_meta( $id, '_hk_vat', true );
		return in_array( $v, array( 'plus', 'margin', 'none' ), true ) ? $v : 'none';
	}

	public static function result( int $id ): string {
		return (string) get_post_meta( $id, '_hk_result', true );
	}

	public static function winner( int $id ): int {
		return (int) get_post_meta( $id, '_hk_winner', true );
	}

	public static function seller_user( int $id ): int {
		return (int) get_post_meta( $id, '_hk_seller_user', true );
	}

	public static function seller( int $id ): array {
		$v = get_post_meta( $id, '_hk_seller', true );
		$v = is_array( $v ) ? $v : array();
		return array_merge( array_fill_keys( self::SELLER_FIELDS, '' ), $v );
	}

	public static function gallery( int $id ): array {
		$v = get_post_meta( $id, '_hk_gallery', true );
		$ids = is_array( $v ) ? array_values( array_filter( array_map( 'absint', $v ) ) ) : array();
		if ( ! $ids && has_post_thumbnail( $id ) ) {
			$ids = array( (int) get_post_thumbnail_id( $id ) );
		}
		return $ids;
	}

	public static function state( int $id, ?int $now = null ): string {
		$now  = $now ?? hk_now();
		$post = get_post( $id );
		if ( ! $post || HK_Post_Types::ITEM !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return self::STATE_DRAFT;
		}
		$result = self::result( $id );
		if ( 'accepted' === $result ) {
			return self::STATE_SOLD;
		}
		if ( in_array( $result, array( 'rejected', 'no_bids' ), true ) ) {
			return self::STATE_UNSOLD;
		}
		$start = self::start( $id );
		$end   = self::end( $id );
		if ( ! $end ) {
			return self::STATE_DRAFT;
		}
		if ( $start && $now < $start ) {
			return self::STATE_UPCOMING;
		}
		return $now < $end ? self::STATE_LIVE : self::STATE_ENDED;
	}

	public static function is_live( int $id ): bool {
		return self::STATE_LIVE === self::state( $id );
	}

	/** Per-language field with fallback to the main content. $field: title|content|delivery. */
	public static function localized( int $id, string $field, ?string $lang = null ): string {
		$lang = $lang ?? hk_lang_code();
		$i18n = get_post_meta( $id, '_hk_i18n', true );
		if ( is_array( $i18n ) && ! empty( $i18n[ $lang ][ $field ] ) ) {
			return (string) $i18n[ $lang ][ $field ];
		}
		$post = get_post( $id );
		switch ( $field ) {
			case 'title':
				return $post ? $post->post_title : '';
			case 'content':
				return $post ? $post->post_content : '';
			case 'delivery':
				return (string) get_post_meta( $id, '_hk_delivery', true );
		}
		return '';
	}

	/**
	 * Save the auction fields that both the admin screen and the seller form use.
	 * Unknown keys are ignored. Values are expected to be already validated.
	 */
	public static function save_fields( int $id, array $f ): void {
		$map = array(
			'start'       => '_hk_start',
			'end'         => '_hk_end',
			'start_price' => '_hk_start_price',
			'min_raise'   => '_hk_min_raise',
			'vat'         => '_hk_vat',
			'location'    => '_hk_location',
			'delivery'    => '_hk_delivery',
			'contact'     => '_hk_contact',
			'seller'      => '_hk_seller',
			'seller_user' => '_hk_seller_user',
			'gallery'     => '_hk_gallery',
			'requested'   => '_hk_requested',
			'i18n'        => '_hk_i18n',
		);
		foreach ( $map as $key => $meta ) {
			if ( array_key_exists( $key, $f ) ) {
				update_post_meta( $id, $meta, $f[ $key ] );
			}
		}
		if ( array_key_exists( 'gallery', $f ) ) {
			$gallery = array_values( array_filter( (array) $f['gallery'] ) );
			if ( $gallery ) {
				set_post_thumbnail( $id, (int) $gallery[0] );
			} else {
				delete_post_thumbnail( $id );
			}
		}
		if ( ! metadata_exists( 'post', $id, '_hk_price' ) ) {
			HK_Bidding::refresh_cache( $id );
		}
	}

	/** Public-facing snapshot used by templates and the REST API. */
	public static function snapshot( int $id, int $user_id = 0 ): array {
		$high  = HK_Bidding::highest_bid( $id );
		$state = self::state( $id );
		$data  = array(
			'id'           => $id,
			'state'        => $state,
			'start'        => self::start( $id ),
			'end'          => self::end( $id ),
			'start_price'  => self::start_price( $id ),
			'min_raise'    => self::min_raise( $id ),
			'price'        => $high ? (float) $high->amount : null,
			'bid_count'    => HK_Bidding::bid_count( $id ),
			'next_min'     => HK_Bidding::next_minimum( $id ),
			'leading'      => $user_id && $high && (int) $high->user_id === $user_id,
			'my_max'       => null,
		);
		if ( $user_id ) {
			$auto = HK_Bidding::get_autobid( $id, $user_id );
			$data['my_max'] = $auto ? (float) $auto->max_amount : null;
		}
		return $data;
	}

	/** Duplicate an item as a new draft (no bids, no result). */
	public static function duplicate( int $id ): int {
		$post = get_post( $id );
		if ( ! $post ) {
			return 0;
		}
		$new = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				'post_status'  => 'draft',
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_author'  => $post->post_author,
			)
		);
		if ( ! $new || is_wp_error( $new ) ) {
			return 0;
		}
		$skip = array( '_hk_result', '_hk_winner', '_hk_result_at', '_hk_end_notified', '_hk_price', '_hk_bid_count', '_hk_approved_sent', '_edit_lock', '_edit_last' );
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $v ) {
				add_post_meta( $new, $key, maybe_unserialize( $v ) );
			}
		}
		wp_set_object_terms( $new, wp_get_object_terms( $id, HK_Post_Types::CATEGORY, array( 'fields' => 'ids' ) ), HK_Post_Types::CATEGORY );
		HK_Bidding::refresh_cache( $new );
		return (int) $new;
	}
}
