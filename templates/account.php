<?php
/**
 * "My bids" page ([huutokauppa_omat] shortcode).
 *
 * Override: your-theme/huutokauppa/account.php
 *
 * @var array      $open    Items still open where the user has bid.
 * @var array      $closed  Ended items where the user has bid.
 * @var object[]   $auto    Active automatic bids (item_id, max_amount).
 * @var int[]      $watch   Watched item ids.
 * @var array|null $flash
 */

defined( 'ABSPATH' ) || exit;

$title = static fn( int $id ) => '<a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( HK_Item::localized( $id, 'title' ) ) . '</a>';
?>
<div class="hk-account">
	<?php if ( $flash ) : ?>
		<?php echo hk_notice( $flash['message'], $flash['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>

	<p class="hk-account__links">
		<?php if ( HK_Settings::get( 'page_profile' ) ) : ?>
			<a href="<?php echo esc_url( HK_Settings::page_url( 'profile' ) ); ?>"><?php esc_html_e( 'My details', 'huutokauppa' ); ?></a> ·
		<?php endif; ?>
		<?php if ( HK_Settings::get( 'allow_submissions' ) && HK_Settings::get( 'page_submit' ) ) : ?>
			<a href="<?php echo esc_url( HK_Settings::page_url( 'submit' ) ); ?>"><?php esc_html_e( 'Submit an item', 'huutokauppa' ); ?></a> ·
		<?php endif; ?>
		<a href="<?php echo esc_url( wp_logout_url( HK_Settings::page_url( 'auctions' ) ) ); ?>"><?php esc_html_e( 'Log out', 'huutokauppa' ); ?></a>
	</p>

	<h2><?php esc_html_e( 'Open auctions', 'huutokauppa' ); ?></h2>
	<?php if ( ! $open ) : ?>
		<p><?php esc_html_e( 'You have no bids on open auctions.', 'huutokauppa' ); ?></p>
	<?php else : ?>
		<table class="hk-table">
			<thead><tr>
				<th><?php esc_html_e( 'Item', 'huutokauppa' ); ?></th>
				<th class="hk-num"><?php esc_html_e( 'My highest bid', 'huutokauppa' ); ?></th>
				<th class="hk-num"><?php esc_html_e( 'Highest bid', 'huutokauppa' ); ?></th>
				<th><?php esc_html_e( 'Time left', 'huutokauppa' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $open as $e ) : ?>
				<tr data-hk-item="<?php echo (int) $e['id']; ?>" data-hk-state="<?php echo esc_attr( $e['state'] ); ?>" class="<?php echo $e['leading'] ? 'is-leading' : 'is-outbid'; ?>">
					<td><?php echo $title( $e['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<br><small data-hk-leading <?php echo $e['leading'] ? '' : 'hidden'; ?>><?php esc_html_e( 'You are the highest bidder', 'huutokauppa' ); ?></small>
						<small data-hk-outbid <?php echo $e['leading'] ? 'hidden' : ''; ?>><?php esc_html_e( 'You have been outbid', 'huutokauppa' ); ?></small></td>
					<td class="hk-num"><?php echo esc_html( hk_money( $e['my_high'] ) ); ?></td>
					<td class="hk-num" data-hk-field="price_text"><?php echo esc_html( hk_money( $e['price'] ) ); ?></td>
					<td data-hk-countdown data-hk-end="<?php echo (int) HK_Item::end( $e['id'] ); ?>" data-hk-start="<?php echo (int) HK_Item::start( $e['id'] ); ?>">&nbsp;</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Automatic bids', 'huutokauppa' ); ?></h2>
	<?php if ( ! $auto ) : ?>
		<p><?php esc_html_e( 'You have no active automatic bids.', 'huutokauppa' ); ?></p>
	<?php else : ?>
		<table class="hk-table">
			<thead><tr>
				<th><?php esc_html_e( 'Item', 'huutokauppa' ); ?></th>
				<th class="hk-num"><?php esc_html_e( 'My maximum', 'huutokauppa' ); ?></th>
				<th class="hk-num"><?php esc_html_e( 'Highest bid', 'huutokauppa' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $auto as $a ) : ?>
				<?php $high = HK_Bidding::highest_bid( (int) $a->item_id ); ?>
				<tr data-hk-item="<?php echo (int) $a->item_id; ?>">
					<td><?php echo $title( (int) $a->item_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="hk-num"><?php echo esc_html( hk_money( $a->max_amount ) ); ?></td>
					<td class="hk-num" data-hk-field="price_text"><?php echo esc_html( $high ? hk_money( $high->amount ) : __( 'No bids', 'huutokauppa' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Watch list', 'huutokauppa' ); ?></h2>
	<?php if ( ! $watch ) : ?>
		<p><?php esc_html_e( 'Your watch list is empty. Use “Add to watch list” on an item page.', 'huutokauppa' ); ?></p>
	<?php else : ?>
		<ul class="hk-list">
			<?php
			foreach ( $watch as $id ) {
				echo hk_template( 'item-card', array( 'id' => $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</ul>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Ended auctions', 'huutokauppa' ); ?></h2>
	<?php if ( ! $closed ) : ?>
		<p><?php esc_html_e( 'No ended auctions yet.', 'huutokauppa' ); ?></p>
	<?php else : ?>
		<table class="hk-table">
			<thead><tr>
				<th><?php esc_html_e( 'Item', 'huutokauppa' ); ?></th>
				<th class="hk-num"><?php esc_html_e( 'My highest bid', 'huutokauppa' ); ?></th>
				<th class="hk-num"><?php esc_html_e( 'Final bid', 'huutokauppa' ); ?></th>
				<th><?php esc_html_e( 'Result', 'huutokauppa' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $closed as $e ) : ?>
				<tr>
					<td><?php echo $title( $e['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="hk-num"><?php echo esc_html( hk_money( $e['my_high'] ) ); ?></td>
					<td class="hk-num"><?php echo esc_html( hk_money( $e['price'] ) ); ?></td>
					<td>
						<?php
						if ( $e['won'] ) {
							echo '<strong>' . esc_html__( 'You won', 'huutokauppa' ) . '</strong>';
						} elseif ( HK_Item::STATE_ENDED === $e['state'] && $e['leading'] ) {
							esc_html_e( 'Highest bid, waiting for confirmation', 'huutokauppa' );
						} elseif ( HK_Item::STATE_ENDED === $e['state'] ) {
							esc_html_e( 'Ended', 'huutokauppa' );
						} elseif ( $e['leading'] ) {
							esc_html_e( 'Not sold', 'huutokauppa' );
						} else {
							esc_html_e( 'Outbid', 'huutokauppa' );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
