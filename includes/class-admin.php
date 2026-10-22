<?php
/**
 * Administration: item edit screen, list columns, winner decisions,
 * duplicating items and CSV exports.
 */

defined( 'ABSPATH' ) || exit;

class HK_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes_' . HK_Post_Types::ITEM, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . HK_Post_Types::ITEM, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . HK_Post_Types::ITEM . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . HK_Post_Types::ITEM . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . HK_Post_Types::ITEM . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
		add_filter( 'views_edit-' . HK_Post_Types::ITEM, array( __CLASS__, 'views' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_hk_duplicate', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_post_hk_decide', array( __CLASS__, 'decide' ) );
		add_action( 'admin_post_hk_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'reject_button' ) );
		add_action( 'admin_post_hk_reject_submission', array( __CLASS__, 'reject_submission' ) );
	}

	public static function menu(): void {
		$parent = 'edit.php?post_type=' . HK_Post_Types::ITEM;
		add_submenu_page( $parent, __( 'Export', 'huutokauppa' ), __( 'Export', 'huutokauppa' ), 'manage_auctions', 'hk-export', array( __CLASS__, 'export_page' ) );
		add_submenu_page( $parent, __( 'Auction settings', 'huutokauppa' ), __( 'Settings', 'huutokauppa' ), 'manage_options', 'hk-settings', array( 'HK_Settings', 'render_page' ) );

		// Bubble with the number of things needing attention.
		global $menu;
		$n = self::count_pending() + self::count_waiting();
		if ( $n ) {
			foreach ( $menu as &$m ) {
				if ( $parent === $m[2] ) {
					$m[0] .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $n . '</span></span>';
				}
			}
		}
	}

	public static function count_pending(): int {
		$c = wp_count_posts( HK_Post_Types::ITEM );
		return (int) ( $c->pending ?? 0 );
	}

	public static function count_waiting(): int {
		$q = new WP_Query( self::waiting_args( array( 'posts_per_page' => 1, 'fields' => 'ids' ) ) );
		return (int) $q->found_posts;
	}

	private static function waiting_args( array $extra = array() ): array {
		return array_merge(
			array(
				'post_type'   => HK_Post_Types::ITEM,
				'post_status' => 'publish',
				'meta_query'  => array(
					'relation' => 'AND',
					array( 'key' => '_hk_end', 'value' => hk_now(), 'compare' => '<=', 'type' => 'NUMERIC' ),
					array( 'key' => '_hk_result', 'compare' => 'NOT EXISTS' ),
				),
			),
			$extra
		);
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || HK_Post_Types::ITEM !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'hk-admin', HK_URL . 'assets/css/admin.css', array(), HK_VERSION );
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'hk-admin', HK_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), HK_VERSION, true );
			wp_localize_script(
				'hk-admin',
				'HKAdmin',
				array(
					'title'  => __( 'Item images', 'huutokauppa' ),
					'button' => __( 'Use these images', 'huutokauppa' ),
					'remove' => __( 'Remove', 'huutokauppa' ),
				)
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Edit screen
	 * ---------------------------------------------------------------- */

	public static function meta_boxes( WP_Post $post ): void {
		// An ended auction needs a decision, so show the bids first.
		if ( HK_Item::STATE_ENDED === HK_Item::state( $post->ID ) ) {
			add_meta_box( 'hk_bids', __( 'Bids and result', 'huutokauppa' ), array( __CLASS__, 'box_bids' ), null, 'normal', 'high' );
		}
		add_meta_box( 'hk_auction', __( 'Auction', 'huutokauppa' ), array( __CLASS__, 'box_auction' ), null, 'normal', 'high' );
		add_meta_box( 'hk_gallery', __( 'Images', 'huutokauppa' ), array( __CLASS__, 'box_gallery' ), null, 'normal', 'high' );
		if ( HK_Settings::extra_languages() ) {
			add_meta_box( 'hk_i18n', __( 'Translations', 'huutokauppa' ), array( __CLASS__, 'box_i18n' ), null, 'normal', 'default' );
		}
		if ( HK_Item::STATE_ENDED !== HK_Item::state( $post->ID ) ) {
			add_meta_box( 'hk_bids', __( 'Bids and result', 'huutokauppa' ), array( __CLASS__, 'box_bids' ), null, 'normal', 'default' );
		}
		add_meta_box( 'hk_seller', __( 'Seller', 'huutokauppa' ), array( __CLASS__, 'box_seller' ), null, 'side', 'default' );
		remove_meta_box( 'postimagediv', HK_Post_Types::ITEM, 'side' ); // The gallery sets the featured image.
	}

	public static function box_auction( WP_Post $post ): void {
		wp_nonce_field( 'hk_save_' . $post->ID, 'hk_admin_nonce' );
		$id        = $post->ID;
		$start     = HK_Item::start( $id );
		$end       = HK_Item::end( $id );
		$has_bids  = HK_Bidding::bid_count( $id ) > 0;
		$requested = get_post_meta( $id, '_hk_requested', true );
		$price     = get_post_meta( $id, '_hk_start_price', true );
		$raise     = get_post_meta( $id, '_hk_min_raise', true );
		$vat       = HK_Item::vat_mode( $id );
		$default_d = (int) HK_Settings::get( 'default_duration' );
		?>
		<?php if ( is_array( $requested ) && 'pending' === $post->post_status ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				printf(
					/* translators: 1: start price, 2: minimum raise, 3: end time */
					esc_html__( 'The seller requested: start price %1$s, minimum raise %2$s, end %3$s. Check the values below and publish to open the auction.', 'huutokauppa' ),
					esc_html( hk_money( $requested['start_price'] ?? 0 ) ),
					esc_html( hk_money( $requested['min_raise'] ?? 0 ) ),
					esc_html( ! empty( $requested['end'] ) ? hk_format_time( (int) $requested['end'] ) : __( 'not given', 'huutokauppa' ) )
				);
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( $has_bids ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This item already has bids. Changing the price fields does not change existing bids.', 'huutokauppa' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table hk-admin-table" role="presentation">
			<tr>
				<th><label for="hk_start"><?php esc_html_e( 'Bidding starts', 'huutokauppa' ); ?></label></th>
				<td><input type="datetime-local" id="hk_start" name="hk[start]" value="<?php echo esc_attr( hk_ts_to_local_input( $start ?: null ) ); ?>">
					<p class="description"><?php esc_html_e( 'Empty = when the item is published.', 'huutokauppa' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="hk_end"><?php esc_html_e( 'Bidding ends', 'huutokauppa' ); ?></label></th>
				<td><input type="datetime-local" id="hk_end" name="hk[end]" value="<?php echo esc_attr( hk_ts_to_local_input( $end ?: null ) ); ?>">
					<?php /* translators: %d: number of days */ ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Empty = %d days after the start.', 'huutokauppa' ), $default_d ) ); ?> <?php echo esc_html( wp_timezone_string() ); ?></p></td>
			</tr>
			<tr>
				<th><label for="hk_price"><?php esc_html_e( 'Start price', 'huutokauppa' ); ?></label></th>
				<td><input type="text" inputmode="decimal" class="small-text" id="hk_price" name="hk[start_price]" value="<?php echo esc_attr( '' === $price ? '' : number_format_i18n( (float) $price, 2 ) ); ?>"> <?php echo esc_html( HK_Settings::get( 'currency_symbol' ) ); ?></td>
			</tr>
			<tr>
				<th><label for="hk_raise"><?php esc_html_e( 'Minimum raise', 'huutokauppa' ); ?></label></th>
				<td><input type="text" inputmode="decimal" class="small-text" id="hk_raise" name="hk[min_raise]" value="<?php echo esc_attr( '' === $raise ? '' : number_format_i18n( (float) $raise, 2 ) ); ?>" placeholder="<?php echo esc_attr( number_format_i18n( (float) HK_Settings::get( 'default_min_raise' ), 2 ) ); ?>"> <?php echo esc_html( HK_Settings::get( 'currency_symbol' ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'VAT', 'huutokauppa' ); ?></th>
				<td>
					<label><input type="radio" name="hk[vat]" value="margin" <?php checked( $vat, 'margin' ); ?>> <?php esc_html_e( 'VAT 0 % / margin scheme', 'huutokauppa' ); ?></label><br>
					<?php /* translators: %s: VAT rate */ ?>
					<label><input type="radio" name="hk[vat]" value="plus" <?php checked( $vat, 'plus' ); ?>> <?php echo esc_html( sprintf( __( 'VAT %s %% is added', 'huutokauppa' ), number_format_i18n( (float) HK_Settings::get( 'vat_rate' ), 1 ) ) ); ?></label><br>
					<label><input type="radio" name="hk[vat]" value="none" <?php checked( $vat, 'none' ); ?>> <?php esc_html_e( 'Show nothing', 'huutokauppa' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="hk_location"><?php esc_html_e( 'Location', 'huutokauppa' ); ?></label></th>
				<td><input type="text" class="regular-text" id="hk_location" name="hk[location]" value="<?php echo esc_attr( (string) get_post_meta( $id, '_hk_location', true ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="hk_delivery"><?php esc_html_e( 'Delivery', 'huutokauppa' ); ?></label></th>
				<td><textarea class="large-text" rows="3" id="hk_delivery" name="hk[delivery]"><?php echo esc_textarea( (string) get_post_meta( $id, '_hk_delivery', true ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="hk_contact"><?php esc_html_e( 'Contact person', 'huutokauppa' ); ?></label></th>
				<td><textarea class="large-text" rows="3" id="hk_contact" name="hk[contact]"><?php echo esc_textarea( (string) get_post_meta( $id, '_hk_contact', true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown publicly on the item page.', 'huutokauppa' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	public static function box_gallery( WP_Post $post ): void {
		$ids = HK_Item::gallery( $post->ID );
		?>
		<ul class="hk-admin-gallery" data-hk-gallery>
			<?php foreach ( $ids as $att ) : ?>
				<li data-id="<?php echo (int) $att; ?>">
					<?php echo wp_get_attachment_image( $att, 'thumbnail' ); ?>
					<button type="button" class="hk-admin-gallery__remove" aria-label="<?php esc_attr_e( 'Remove', 'huutokauppa' ); ?>">×</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<input type="hidden" name="hk[gallery]" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" data-hk-gallery-input>
		<p><button type="button" class="button" data-hk-gallery-add><?php esc_html_e( 'Add images', 'huutokauppa' ); ?></button>
			<span class="description"><?php esc_html_e( 'Drag to reorder. The first image is the main image.', 'huutokauppa' ); ?></span></p>
		<?php
	}

	public static function box_i18n( WP_Post $post ): void {
		$i18n = get_post_meta( $post->ID, '_hk_i18n', true );
		$i18n = is_array( $i18n ) ? $i18n : array();
		echo '<p class="description">' . esc_html__( 'Shown to visitors browsing the site in that language. Empty fields fall back to the main title and description.', 'huutokauppa' ) . '</p>';
		foreach ( HK_Settings::extra_languages() as $lang ) {
			$v = $i18n[ $lang ] ?? array();
			?>
			<fieldset class="hk-i18n">
				<legend><?php echo esc_html( strtoupper( $lang ) ); ?></legend>
				<p><label><?php esc_html_e( 'Title', 'huutokauppa' ); ?><br>
					<input type="text" class="large-text" name="hk[i18n][<?php echo esc_attr( $lang ); ?>][title]" value="<?php echo esc_attr( $v['title'] ?? '' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Description', 'huutokauppa' ); ?><br>
					<textarea class="large-text" rows="6" name="hk[i18n][<?php echo esc_attr( $lang ); ?>][content]"><?php echo esc_textarea( $v['content'] ?? '' ); ?></textarea></label></p>
				<p><label><?php esc_html_e( 'Delivery', 'huutokauppa' ); ?><br>
					<textarea class="large-text" rows="2" name="hk[i18n][<?php echo esc_attr( $lang ); ?>][delivery]"><?php echo esc_textarea( $v['delivery'] ?? '' ); ?></textarea></label></p>
			</fieldset>
			<?php
		}
	}

	public static function box_seller( WP_Post $post ): void {
		$s      = HK_Item::seller( $post->ID );
		$uid    = HK_Item::seller_user( $post->ID );
		$labels = array(
			'name'        => __( 'Name', 'huutokauppa' ),
			'business_id' => __( 'Business ID', 'huutokauppa' ),
			'address'     => __( 'Street address', 'huutokauppa' ),
			'postcode'    => __( 'Postcode', 'huutokauppa' ),
			'city'        => __( 'City', 'huutokauppa' ),
			'phone'       => __( 'Phone', 'huutokauppa' ),
			'email'       => __( 'E-mail', 'huutokauppa' ),
		);
		if ( $uid && get_userdata( $uid ) ) {
			echo '<p>' . esc_html__( 'Submitted by', 'huutokauppa' ) . ' <a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html( get_userdata( $uid )->display_name ) . '</a></p>';
		}
		foreach ( $labels as $k => $label ) {
			printf(
				'<p><label for="hk_seller_%1$s">%2$s</label><br><input type="%4$s" class="widefat" id="hk_seller_%1$s" name="hk[seller][%1$s]" value="%3$s"></p>',
				esc_attr( $k ),
				esc_html( $label ),
				esc_attr( $s[ $k ] ),
				'email' === $k ? 'email' : 'text'
			);
		}
		echo '<p class="description">' . esc_html__( 'Not shown publicly.', 'huutokauppa' ) . '</p>';
	}

	public static function box_bids( WP_Post $post ): void {
		$id    = $post->ID;
		$state = HK_Item::state( $id );
		$bids  = HK_Bidding::bids( $id );
		$high  = HK_Bidding::highest_bid( $id );
		echo '<p><strong>' . esc_html__( 'Status:', 'huutokauppa' ) . '</strong> ' . esc_html( HK_Frontend::state_label( $state ) ?: __( 'Not published', 'huutokauppa' ) );
		if ( $high ) {
			$u = get_userdata( (int) $high->user_id );
			echo ' · <strong>' . esc_html__( 'Highest bid:', 'huutokauppa' ) . '</strong> ' . esc_html( hk_money( $high->amount ) ) . ' (' . esc_html( $u ? $u->display_name . ', ' . $u->user_email : '#' . $high->user_id ) . ')';
		}
		echo '</p>';

		$action = static function ( string $decision, string $label, string $class = 'button' ) use ( $id ): string {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=hk_decide&item=' . $id . '&decision=' . $decision ), 'hk_decide_' . $id );
			return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
		};
		if ( HK_Item::STATE_ENDED === $state && $high ) {
			echo '<p class="hk-decide">' . $action( 'accept', __( 'Accept highest bid and notify winner', 'huutokauppa' ), 'button button-primary' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. $action( 'reject', __( 'Reject (lowest price not reached) and notify bidder', 'huutokauppa' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( in_array( $state, array( HK_Item::STATE_SOLD, HK_Item::STATE_UNSOLD ), true ) && HK_Item::result( $id ) !== 'no_bids' ) {
			echo '<p>' . $action( 'reset', __( 'Undo decision', 'huutokauppa' ) ) . '<span class="description">' . esc_html__( 'E-mails already sent are not withdrawn.', 'huutokauppa' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		if ( ! $bids ) {
			echo '<p>' . esc_html__( 'No bids yet.', 'huutokauppa' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Bidder', 'huutokauppa' ) . '</th><th>' . esc_html__( 'Amount', 'huutokauppa' ) . '</th><th>' . esc_html__( 'Time', 'huutokauppa' ) . '</th></tr></thead><tbody>';
			foreach ( $bids as $b ) {
				$u = get_userdata( (int) $b->user_id );
				echo '<tr><td>' . ( $u ? '<a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a> <span class="description">' . esc_html( $u->user_email ) . '</span>' : '#' . (int) $b->user_id ) . '</td>'
					. '<td>' . esc_html( hk_money( $b->amount ) ) . ( $b->is_auto ? ' <span class="description">' . esc_html__( 'automatic', 'huutokauppa' ) . '</span>' : '' ) . '</td>'
					. '<td>' . esc_html( hk_format_time( (int) strtotime( $b->created_at . ' UTC' ), false ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			$csv = wp_nonce_url( admin_url( 'admin-post.php?action=hk_export&what=bids&item=' . $id ), 'hk_export' );
			echo '<p><a href="' . esc_url( $csv ) . '">' . esc_html__( 'Download bids as CSV', 'huutokauppa' ) . '</a></p>';
		}

		global $wpdb;
		$t     = HK_Install::table( 'autobids' );
		$autos = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d ORDER BY max_amount DESC", $id ) ); // phpcs:ignore
		if ( $autos ) {
			echo '<h4>' . esc_html__( 'Automatic bids', 'huutokauppa' ) . '</h4><ul>';
			foreach ( $autos as $a ) {
				$u = get_userdata( (int) $a->user_id );
				echo '<li>' . esc_html( ( $u ? $u->display_name : '#' . $a->user_id ) . ': ' . hk_money( $a->max_amount ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['hk_admin_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['hk_admin_nonce'] ), 'hk_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = wp_unslash( $_POST['hk'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $in ) ) {
			return;
		}

		$start = hk_local_input_to_ts( self::scalar( $in, 'start' ) );
		$end   = hk_local_input_to_ts( self::scalar( $in, 'end' ) );
		if ( 'publish' === $post->post_status ) {
			$start = $start ?: ( HK_Item::start( $post_id ) ?: hk_now() );
			$end   = $end ?: $start + DAY_IN_SECONDS * (int) HK_Settings::get( 'default_duration' );
		}
		if ( $start && $end && $end <= $start ) {
			set_transient( 'hk_admin_notice_' . get_current_user_id(), array( 'error', __( 'The end time must be after the start time. The end time was not changed.', 'huutokauppa' ) ), 60 );
			$end = HK_Item::end( $post_id ) ?: $start + DAY_IN_SECONDS * (int) HK_Settings::get( 'default_duration' );
		}

		$seller_in = is_array( $in['seller'] ?? null ) ? $in['seller'] : array();
		$i18n_in   = is_array( $in['i18n'] ?? null ) ? $in['i18n'] : array();
		$seller    = array();
		foreach ( HK_Item::SELLER_FIELDS as $f ) {
			$v            = is_scalar( $seller_in[ $f ] ?? '' ) ? (string) ( $seller_in[ $f ] ?? '' ) : '';
			$seller[ $f ] = 'email' === $f ? sanitize_email( $v ) : sanitize_text_field( $v );
		}

		$i18n = array();
		foreach ( HK_Settings::extra_languages() as $lang ) {
			$v             = is_array( $i18n_in[ $lang ] ?? null ) ? array_map( static fn( $x ) => is_scalar( $x ) ? (string) $x : '', $i18n_in[ $lang ] ) : array();
			$i18n[ $lang ] = array(
				'title'    => sanitize_text_field( $v['title'] ?? '' ),
				'content'  => wp_kses_post( $v['content'] ?? '' ),
				'delivery' => sanitize_textarea_field( $v['delivery'] ?? '' ),
			);
		}

		$gallery = array_values( array_filter( array_map( 'absint', explode( ',', self::scalar( $in, 'gallery' ) ) ) ) );
		$fields  = array(
			'start'       => $start ?: 0,
			'end'         => $end ?: 0,
			'start_price' => hk_parse_amount( $in['start_price'] ?? '' ) ?? 0,
			'min_raise'   => hk_parse_amount( $in['min_raise'] ?? '' ) ?? 0,
			'vat'         => in_array( $in['vat'] ?? '', array( 'plus', 'margin', 'none' ), true ) ? $in['vat'] : 'none',
			'location'    => sanitize_text_field( self::scalar( $in, 'location' ) ),
			'delivery'    => sanitize_textarea_field( self::scalar( $in, 'delivery' ) ),
			'contact'     => sanitize_textarea_field( self::scalar( $in, 'contact' ) ),
			'seller'      => $seller,
			'gallery'     => $gallery,
		);
		if ( HK_Settings::extra_languages() ) {
			$fields['i18n'] = $i18n;
		}
		HK_Item::save_fields( $post_id, $fields );
		HK_Bidding::refresh_cache( $post_id );
	}

	private static function scalar( array $in, string $key ): string {
		return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
	}

	/** "Reject submission" link in the publish box for pending items. */
	public static function reject_button( WP_Post $post ): void {
		if ( HK_Post_Types::ITEM !== $post->post_type || 'pending' !== $post->post_status ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=hk_reject_submission&item=' . $post->ID ), 'hk_reject_' . $post->ID );
		echo '<div class="misc-pub-section"><a class="submitdelete" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( __( 'Reject this submission?', 'huutokauppa' ) ) . '\')">' . esc_html__( 'Reject submission', 'huutokauppa' ) . '</a></div>';
	}

	public static function reject_submission(): void {
		$id = absint( $_GET['item'] ?? 0 );
		check_admin_referer( 'hk_reject_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'huutokauppa' ) );
		}
		wp_update_post( array( 'ID' => $id, 'post_status' => 'hk_rejected' ) );
		set_transient( 'hk_admin_notice_' . get_current_user_id(), array( 'success', __( 'Submission rejected.', 'huutokauppa' ) ), 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . HK_Post_Types::ITEM ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Decisions
	 * ---------------------------------------------------------------- */

	public static function decide(): void {
		$id = absint( $_GET['item'] ?? 0 );
		check_admin_referer( 'hk_decide_' . $id );
		if ( ! current_user_can( 'manage_auctions' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'huutokauppa' ) );
		}
		$decision = sanitize_key( $_GET['decision'] ?? '' );
		$ok       = false;
		switch ( $decision ) {
			case 'accept':
				$ok  = HK_Winner::accept( $id );
				$msg = __( 'Highest bid accepted and the winner notified.', 'huutokauppa' );
				break;
			case 'reject':
				$ok  = HK_Winner::reject( $id );
				$msg = __( 'Highest bid rejected and the bidder notified.', 'huutokauppa' );
				break;
			case 'reset':
				HK_Winner::reset( $id );
				$ok  = true;
				$msg = __( 'Decision undone.', 'huutokauppa' );
				break;
		}
		set_transient(
			'hk_admin_notice_' . get_current_user_id(),
			$ok ? array( 'success', $msg ) : array( 'error', __( 'The auction is not waiting for a decision.', 'huutokauppa' ) ),
			60
		);
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'post.php?action=edit&post=' . $id ) );
		exit;
	}

	public static function notices(): void {
		$key = 'hk_admin_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( is_array( $n ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}
	}

	/* ------------------------------------------------------------------
	 * List table
	 * ---------------------------------------------------------------- */

	public static function columns( array $cols ): array {
		$new = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				continue;
			}
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['hk_thumb'] = '';
				$new['hk_price'] = __( 'Highest bid', 'huutokauppa' );
				$new['hk_bids']  = __( 'Bids', 'huutokauppa' );
				$new['hk_end']   = __( 'Ends', 'huutokauppa' );
				$new['hk_state'] = __( 'Status', 'huutokauppa' );
			}
		}
		return $new;
	}

	public static function column( string $col, int $id ): void {
		switch ( $col ) {
			case 'hk_thumb':
				$g = HK_Item::gallery( $id );
				echo $g ? wp_get_attachment_image( $g[0], array( 48, 48 ) ) : '';
				break;
			case 'hk_price':
				$high = HK_Bidding::highest_bid( $id );
				echo esc_html( $high ? hk_money( $high->amount ) : '—' );
				echo '<br><span class="description">' . esc_html( hk_money( HK_Item::start_price( $id ) ) ) . '</span>';
				break;
			case 'hk_bids':
				echo (int) get_post_meta( $id, '_hk_bid_count', true );
				break;
			case 'hk_end':
				$end = HK_Item::end( $id );
				echo $end ? esc_html( hk_format_time( $end, false ) ) : '—';
				break;
			case 'hk_state':
				$state = HK_Item::state( $id );
				$label = HK_Frontend::state_label( $state );
				if ( HK_Item::STATE_ENDED === $state ) {
					echo '<strong class="hk-needs-decision">' . esc_html__( 'Waiting for decision', 'huutokauppa' ) . '</strong>';
				} elseif ( 'pending' === get_post_status( $id ) ) {
					echo '<strong>' . esc_html__( 'Waiting for approval', 'huutokauppa' ) . '</strong>';
				} else {
					echo esc_html( $label );
				}
				break;
		}
	}

	public static function sortable( array $cols ): array {
		$cols['hk_end']   = 'hk_end';
		$cols['hk_price'] = 'hk_price';
		$cols['hk_bids']  = 'hk_bids';
		return $cols;
	}

	public static function list_query( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || HK_Post_Types::ITEM !== $q->get( 'post_type' ) ) {
			return;
		}
		$map = array( 'hk_end' => '_hk_end', 'hk_price' => '_hk_price', 'hk_bids' => '_hk_bid_count' );
		$by  = $q->get( 'orderby' );
		if ( isset( $map[ $by ] ) ) {
			$q->set( 'meta_key', $map[ $by ] );
			$q->set( 'orderby', 'meta_value_num' );
		}
		if ( 'waiting' === ( $_GET['hk_view'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args = self::waiting_args();
			$q->set( 'post_status', 'publish' );
			$q->set( 'meta_query', $args['meta_query'] );
		}
	}

	public static function views( array $views ): array {
		$n = self::count_waiting();
		if ( $n ) {
			$url                = admin_url( 'edit.php?post_type=' . HK_Post_Types::ITEM . '&hk_view=waiting' );
			$current            = 'waiting' === ( $_GET['hk_view'] ?? '' ) ? ' class="current"' : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$views['hk_waiting'] = '<a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html__( 'Waiting for decision', 'huutokauppa' ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		return $views;
	}

	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( HK_Post_Types::ITEM === $post->post_type && current_user_can( 'edit_hk_items' ) ) {
			$url                    = wp_nonce_url( admin_url( 'admin.php?action=hk_duplicate&item=' . $post->ID ), 'hk_duplicate_' . $post->ID );
			$actions['hk_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'huutokauppa' ) . '</a>';
		}
		return $actions;
	}

	public static function duplicate(): void {
		$id = absint( $_GET['item'] ?? 0 );
		check_admin_referer( 'hk_duplicate_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'huutokauppa' ) );
		}
		$new = HK_Item::duplicate( $id );
		wp_safe_redirect( $new ? admin_url( 'post.php?action=edit&post=' . $new ) : wp_get_referer() );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Export
	 * ---------------------------------------------------------------- */

	public static function export_page(): void {
		$url = static fn( $what ) => wp_nonce_url( admin_url( 'admin-post.php?action=hk_export&what=' . $what ), 'hk_export' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export', 'huutokauppa' ); ?></h1>
			<p><?php esc_html_e( 'CSV files open in Excel and LibreOffice (semicolon separated, UTF-8).', 'huutokauppa' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $url( 'newsletter' ) ); ?>"><?php esc_html_e( 'Newsletter subscribers', 'huutokauppa' ); ?></a></p>
			<p><a class="button" href="<?php echo esc_url( $url( 'users' ) ); ?>"><?php esc_html_e( 'All bidders with contact details', 'huutokauppa' ); ?></a></p>
			<p><a class="button" href="<?php echo esc_url( $url( 'results' ) ); ?>"><?php esc_html_e( 'Ended auctions and winners', 'huutokauppa' ); ?></a></p>
		</div>
		<?php
	}

	public static function export(): void {
		check_admin_referer( 'hk_export' );
		if ( ! current_user_can( 'manage_auctions' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'huutokauppa' ) );
		}
		$what = sanitize_key( $_GET['what'] ?? '' );
		$rows = array();
		$name = 'huutokauppa-' . $what;

		switch ( $what ) {
			case 'newsletter':
				$rows[] = array( __( 'Name', 'huutokauppa' ), __( 'E-mail', 'huutokauppa' ) );
				foreach ( get_users( array( 'meta_key' => 'hk_newsletter', 'meta_value' => '1' ) ) as $u ) {
					$rows[] = array( $u->display_name, $u->user_email );
				}
				break;
			case 'users':
				$labels = HK_Users::field_labels();
				$rows[] = array_merge( array( __( 'Name', 'huutokauppa' ), __( 'E-mail', 'huutokauppa' ) ), array_values( $labels ), array( __( 'Newsletter', 'huutokauppa' ), __( 'Blocked', 'huutokauppa' ) ) );
				foreach ( get_users() as $u ) {
					$r = array( $u->display_name, $u->user_email );
					foreach ( array_keys( $labels ) as $f ) {
						$r[] = HK_Users::get( $u->ID, $f );
					}
					$r[]    = get_user_meta( $u->ID, 'hk_newsletter', true ) ? 1 : 0;
					$r[]    = HK_Users::is_blocked( $u->ID ) ? 1 : 0;
					$rows[] = $r;
				}
				break;
			case 'results':
				$rows[] = array( 'ID', __( 'Item', 'huutokauppa' ), __( 'Ended', 'huutokauppa' ), __( 'Status', 'huutokauppa' ), __( 'Highest bid', 'huutokauppa' ), __( 'Bidder', 'huutokauppa' ), __( 'E-mail', 'huutokauppa' ), __( 'Phone', 'huutokauppa' ), __( 'Seller', 'huutokauppa' ) );
				$ids    = get_posts(
					array(
						'post_type'      => HK_Post_Types::ITEM,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_key'       => '_hk_end',
						'orderby'        => 'meta_value_num',
						'order'          => 'DESC',
						'meta_query'     => array( array( 'key' => '_hk_end', 'value' => hk_now(), 'compare' => '<=', 'type' => 'NUMERIC' ) ),
					)
				);
				foreach ( $ids as $id ) {
					$high   = HK_Bidding::highest_bid( (int) $id );
					$u      = $high ? get_userdata( (int) $high->user_id ) : null;
					$rows[] = array(
						$id,
						get_the_title( $id ),
						wp_date( 'Y-m-d H:i', HK_Item::end( (int) $id ) ),
						HK_Frontend::state_label( HK_Item::state( (int) $id ) ),
						$high ? number_format( (float) $high->amount, 2, ',', '' ) : '',
						$u ? $u->display_name : '',
						$u ? $u->user_email : '',
						$u ? HK_Users::get( $u->ID, 'phone' ) : '',
						HK_Item::seller( (int) $id )['name'],
					);
				}
				break;
			case 'bids':
				$item = absint( $_GET['item'] ?? 0 );
				$name = 'huutokauppa-bids-' . $item;
				$rows[] = array( __( 'Time', 'huutokauppa' ), __( 'Amount', 'huutokauppa' ), __( 'Automatic', 'huutokauppa' ), __( 'Bidder', 'huutokauppa' ), __( 'E-mail', 'huutokauppa' ), __( 'Phone', 'huutokauppa' ) );
				foreach ( HK_Bidding::bids( $item ) as $b ) {
					$u      = get_userdata( (int) $b->user_id );
					$rows[] = array(
						wp_date( 'Y-m-d H:i:s', (int) strtotime( $b->created_at . ' UTC' ) ),
						number_format( (float) $b->amount, 2, ',', '' ),
						$b->is_auto ? 1 : 0,
						$u ? $u->display_name : '#' . $b->user_id,
						$u ? $u->user_email : '',
						$u ? HK_Users::get( $u->ID, 'phone' ) : '',
					);
				}
				break;
			default:
				wp_die( esc_html__( 'Unknown export.', 'huutokauppa' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name . '-' . wp_date( 'Ymd' ) ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel detects UTF-8.
		foreach ( $rows as $r ) {
			// Neutralise spreadsheet formulas in user-supplied values.
			$r = array_map( static fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v, $r );
			fputcsv( $out, $r, ';', '"', '\\' );
		}
		fclose( $out );
		exit;
	}
}
