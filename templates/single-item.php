<?php
/**
 * Auction item page content.
 *
 * Override: your-theme/huutokauppa/single-item.php
 *
 * @var int            $id
 * @var int            $user_id
 * @var array          $snap        HK_Rest::public_snapshot() with history.
 * @var string         $description Rendered description HTML.
 * @var int[]          $gallery     Attachment ids.
 * @var true|WP_Error  $can_bid
 * @var bool           $watching
 * @var array|null     $flash       array( 'type' => ..., 'message' => ... )
 */

defined( 'ABSPATH' ) || exit;

$state    = $snap['state'];
$live     = HK_Item::STATE_LIVE === $state;
$vat      = HK_Frontend::vat_note( $id );
$location = (string) get_post_meta( $id, '_hk_location', true );
$delivery = HK_Item::localized( $id, 'delivery' );
$contact  = (string) get_post_meta( $id, '_hk_contact', true );
$extend   = (int) HK_Settings::get( 'extend_seconds' );
$post_url = admin_url( 'admin-post.php' );
$back     = get_permalink( $id );
?>
<div class="hk-item alignwide" data-hk-item="<?php echo (int) $id; ?>" data-hk-state="<?php echo esc_attr( $state ); ?>" data-hk-history="1">

	<?php if ( $flash ) : ?>
		<?php echo hk_notice( $flash['message'], $flash['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
	<div class="hk-live-msg" aria-live="polite"></div>

	<div class="hk-item__top">
		<div class="hk-gallery">
			<?php if ( $gallery ) : ?>
				<a class="hk-gallery__main" href="<?php echo esc_url( (string) wp_get_attachment_image_url( $gallery[0], 'full' ) ); ?>" target="_blank" rel="noopener">
					<?php echo wp_get_attachment_image( $gallery[0], 'large', false, array( 'class' => 'hk-gallery__img' ) ); ?>
				</a>
				<?php if ( count( $gallery ) > 1 ) : ?>
					<ul class="hk-gallery__thumbs">
						<?php foreach ( $gallery as $n => $att ) : ?>
							<li>
								<button type="button" class="hk-gallery__thumb<?php echo 0 === $n ? ' is-active' : ''; ?>"
									data-large="<?php echo esc_url( (string) wp_get_attachment_image_url( $att, 'large' ) ); ?>"
									data-full="<?php echo esc_url( (string) wp_get_attachment_image_url( $att, 'full' ) ); ?>"
									aria-label="<?php /* translators: %d: image number */ echo esc_attr( sprintf( __( 'Show image %d', 'huutokauppa' ), $n + 1 ) ); ?>">
									<?php echo wp_get_attachment_image( $att, 'thumbnail' ); ?>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php else : ?>
				<div class="hk-gallery__empty"><?php esc_html_e( 'No image', 'huutokauppa' ); ?></div>
			<?php endif; ?>
		</div>

		<div class="hk-box">
			<p class="hk-state hk-state--<?php echo esc_attr( $state ); ?>" data-hk-field="state_label"><?php echo esc_html( HK_Frontend::state_label( $state ) ); ?></p>

			<dl class="hk-facts">
				<dt><?php esc_html_e( 'Highest bid', 'huutokauppa' ); ?></dt>
				<dd class="hk-price" data-hk-field="price_text"><?php echo esc_html( $snap['price_text'] ); ?></dd>

				<dt><?php esc_html_e( 'Start price', 'huutokauppa' ); ?></dt>
				<dd><?php echo esc_html( hk_money( $snap['start_price'] ) ); ?></dd>

				<dt><?php esc_html_e( 'Bids', 'huutokauppa' ); ?></dt>
				<dd data-hk-field="bid_count"><?php echo (int) $snap['bid_count']; ?></dd>

				<?php if ( HK_Item::STATE_UPCOMING === $state ) : ?>
					<dt><?php esc_html_e( 'Bidding starts', 'huutokauppa' ); ?></dt>
					<dd><?php echo esc_html( hk_format_time( $snap['start'] ) ); ?></dd>
				<?php endif; ?>

				<dt><?php esc_html_e( 'Time left', 'huutokauppa' ); ?></dt>
				<dd class="hk-countdown" data-hk-countdown data-hk-end="<?php echo (int) $snap['end']; ?>" data-hk-start="<?php echo (int) $snap['start']; ?>">&nbsp;</dd>

				<dt><?php esc_html_e( 'Ends', 'huutokauppa' ); ?></dt>
				<dd>
					<span data-hk-field="end_text"><?php echo esc_html( $snap['end_text'] ); ?></span>
					<?php if ( $extend && $live ) : ?>
						<small class="hk-hint">
							<?php
							/* translators: %d: minutes */
							echo esc_html( sprintf( _n( 'or %d minute after the latest bid', 'or %d minutes after the latest bid', max( 1, (int) round( $extend / 60 ) ), 'huutokauppa' ), max( 1, (int) round( $extend / 60 ) ) ) );
							?>
						</small>
					<?php endif; ?>
				</dd>
			</dl>
			<?php if ( $vat ) : ?>
				<p class="hk-hint"><?php echo esc_html( $vat ); ?></p>
			<?php endif; ?>

			<p class="hk-leading" data-hk-leading <?php echo ( $snap['leading'] && $live ) ? '' : 'hidden'; ?>><?php esc_html_e( 'You are the highest bidder', 'huutokauppa' ); ?></p>

			<?php if ( $live && true === $can_bid ) : ?>
				<form class="hk-form hk-form--bid" method="post" action="<?php echo esc_url( $post_url ); ?>" data-hk-action="bid">
					<input type="hidden" name="action" value="hk_bid">
					<input type="hidden" name="item" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="redirect" value="<?php echo esc_url( $back ); ?>">
					<?php wp_nonce_field( 'hk_bid_' . $id, 'hk_nonce' ); ?>
					<label for="hk-amount-<?php echo (int) $id; ?>"><?php esc_html_e( 'Your bid', 'huutokauppa' ); ?></label>
					<div class="hk-input">
						<input type="text" inputmode="decimal" autocomplete="off" required id="hk-amount-<?php echo (int) $id; ?>" name="amount" placeholder="<?php echo esc_attr( number_format_i18n( $snap['next_min'], 0 ) ); ?>">
						<span class="hk-input__unit"><?php echo esc_html( HK_Settings::get( 'currency_symbol' ) ); ?></span>
						<button type="submit" class="hk-button"><?php esc_html_e( 'Place bid', 'huutokauppa' ); ?></button>
					</div>
					<p class="hk-hint">
						<?php esc_html_e( 'Smallest accepted bid:', 'huutokauppa' ); ?> <strong data-hk-field="next_min_text"><?php echo esc_html( $snap['next_min_text'] ); ?></strong>
						· <?php esc_html_e( 'Minimum raise:', 'huutokauppa' ); ?> <?php echo esc_html( hk_money( $snap['min_raise'] ) ); ?>
					</p>
				</form>

				<details class="hk-auto" <?php echo null !== $snap['my_max'] ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Automatic bidding', 'huutokauppa' ); ?></summary>
					<p class="hk-hint"><?php esc_html_e( 'Enter the most you are willing to pay. We bid for you, one minimum raise at a time, only as much as needed to keep you in the lead.', 'huutokauppa' ); ?></p>
					<form class="hk-form hk-form--auto" method="post" action="<?php echo esc_url( $post_url ); ?>" data-hk-action="autobid">
						<input type="hidden" name="action" value="hk_autobid">
						<input type="hidden" name="item" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="redirect" value="<?php echo esc_url( $back ); ?>">
						<?php wp_nonce_field( 'hk_bid_' . $id, 'hk_nonce' ); ?>
						<label for="hk-max-<?php echo (int) $id; ?>"><?php esc_html_e( 'Your maximum', 'huutokauppa' ); ?></label>
						<div class="hk-input">
							<input type="text" inputmode="decimal" autocomplete="off" required id="hk-max-<?php echo (int) $id; ?>" name="max" value="<?php echo null !== $snap['my_max'] ? esc_attr( number_format_i18n( $snap['my_max'], 2 ) ) : ''; ?>">
							<span class="hk-input__unit"><?php echo esc_html( HK_Settings::get( 'currency_symbol' ) ); ?></span>
							<button type="submit" class="hk-button hk-button--secondary"><?php esc_html_e( 'Save', 'huutokauppa' ); ?></button>
						</div>
					</form>
					<div class="hk-hint hk-mymax" data-hk-mymax <?php echo null === $snap['my_max'] ? 'hidden' : ''; ?>>
						<?php esc_html_e( 'Your current maximum:', 'huutokauppa' ); ?> <strong data-hk-field="my_max_text"><?php echo esc_html( $snap['my_max_text'] ); ?></strong>
						<form class="hk-inline-form" method="post" action="<?php echo esc_url( $post_url ); ?>" data-hk-action="autobid-remove">
							<input type="hidden" name="action" value="hk_autobid_remove">
							<input type="hidden" name="item" value="<?php echo (int) $id; ?>">
							<input type="hidden" name="redirect" value="<?php echo esc_url( $back ); ?>">
							<?php wp_nonce_field( 'hk_bid_' . $id, 'hk_nonce' ); ?>
							<button type="submit" class="hk-link"><?php esc_html_e( 'Remove', 'huutokauppa' ); ?></button>
						</form>
					</div>
				</details>
			<?php elseif ( $live && is_wp_error( $can_bid ) && 'hk_login' === $can_bid->get_error_code() ) : ?>
				<?php echo HK_Frontend::login_box( __( 'Log in to bid on this item.', 'huutokauppa' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $live && is_wp_error( $can_bid ) ) : ?>
				<?php echo hk_notice( $can_bid->get_error_message(), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( HK_Item::STATE_SOLD === $state ) : ?>
				<p class="hk-result"><?php esc_html_e( 'The highest bid for this item has been accepted.', 'huutokauppa' ); ?></p>
			<?php elseif ( in_array( $state, array( HK_Item::STATE_ENDED, HK_Item::STATE_UNSOLD ), true ) ) : ?>
				<p class="hk-result"><?php esc_html_e( 'This auction has ended.', 'huutokauppa' ); ?></p>
			<?php endif; ?>

			<?php if ( $user_id ) : ?>
				<form class="hk-inline-form hk-watch" method="post" action="<?php echo esc_url( $post_url ); ?>" data-hk-action="watch">
					<input type="hidden" name="action" value="hk_watch">
					<input type="hidden" name="item" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="redirect" value="<?php echo esc_url( $back ); ?>">
					<?php wp_nonce_field( 'hk_bid_' . $id, 'hk_nonce' ); ?>
					<button type="submit" class="hk-link" aria-pressed="<?php echo $watching ? 'true' : 'false'; ?>">
						<?php echo $watching ? esc_html__( 'Remove from watch list', 'huutokauppa' ) : esc_html__( 'Add to watch list', 'huutokauppa' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<div class="hk-item__details">
		<section class="hk-section">
			<h2><?php esc_html_e( 'Item details', 'huutokauppa' ); ?></h2>
			<div class="hk-description"><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput -- filtered by the_content ?></div>
		</section>
		<?php if ( $location ) : ?>
			<section class="hk-section">
				<h2><?php esc_html_e( 'Location', 'huutokauppa' ); ?></h2>
				<p><?php echo esc_html( $location ); ?></p>
			</section>
		<?php endif; ?>
		<?php if ( $delivery ) : ?>
			<section class="hk-section">
				<h2><?php esc_html_e( 'Delivery', 'huutokauppa' ); ?></h2>
				<p><?php echo nl2br( esc_html( $delivery ) ); ?></p>
			</section>
		<?php endif; ?>
		<?php if ( $contact ) : ?>
			<section class="hk-section">
				<h2><?php esc_html_e( 'Contact person', 'huutokauppa' ); ?></h2>
				<p><?php echo nl2br( esc_html( $contact ) ); ?></p>
			</section>
		<?php endif; ?>

		<section class="hk-section">
			<h2><?php esc_html_e( 'Bid history', 'huutokauppa' ); ?> <small class="hk-hint"><?php esc_html_e( '(latest 10)', 'huutokauppa' ); ?></small></h2>
			<table class="hk-table hk-history">
				<tbody data-hk-history-body>
				<?php if ( ! $snap['history'] ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No bids yet.', 'huutokauppa' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $snap['history'] as $h ) : ?>
					<tr<?php echo $h['mine'] ? ' class="is-mine"' : ''; ?>>
						<td><?php echo esc_html( $h['bidder'] ); ?><?php echo $h['mine'] ? ' <em>(' . esc_html__( 'you', 'huutokauppa' ) . ')</em>' : ''; ?></td>
						<td class="hk-num"><?php echo esc_html( $h['amount'] ); ?><?php echo $h['auto'] ? ' <small>' . esc_html__( 'automatic', 'huutokauppa' ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $h['time'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	</div>
</div>
