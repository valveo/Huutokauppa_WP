<?php
/**
 * One item in the auction list.
 *
 * Override: your-theme/huutokauppa/item-card.php
 *
 * @var int $id
 */

defined( 'ABSPATH' ) || exit;

$snap    = HK_Rest::public_snapshot( $id );
$gallery = HK_Item::gallery( $id );
$link    = get_permalink( $id );
?>
<li class="hk-card" data-hk-item="<?php echo (int) $id; ?>" data-hk-state="<?php echo esc_attr( $snap['state'] ); ?>">
	<a class="hk-card__image" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true">
		<?php
		if ( $gallery ) {
			echo wp_get_attachment_image( $gallery[0], 'medium', false, array( 'loading' => 'lazy' ) );
		}
		?>
	</a>
	<div class="hk-card__body">
		<h3 class="hk-card__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( HK_Item::localized( $id, 'title' ) ); ?></a></h3>
		<dl class="hk-card__facts">
			<dt><?php esc_html_e( 'Highest bid', 'huutokauppa' ); ?></dt>
			<dd class="hk-price" data-hk-field="price_text"><?php echo esc_html( $snap['price_text'] ); ?></dd>
			<dt><?php esc_html_e( 'Time left', 'huutokauppa' ); ?></dt>
			<dd class="hk-countdown" data-hk-countdown data-hk-end="<?php echo (int) $snap['end']; ?>" data-hk-start="<?php echo (int) $snap['start']; ?>">&nbsp;</dd>
		</dl>
		<p class="hk-leading" data-hk-leading <?php echo $snap['leading'] && HK_Item::STATE_LIVE === $snap['state'] ? '' : 'hidden'; ?>><?php esc_html_e( 'You are the highest bidder', 'huutokauppa' ); ?></p>
	</div>
</li>
