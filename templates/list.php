<?php
/**
 * Auction list ([huutokauppa] shortcode).
 *
 * Override: your-theme/huutokauppa/list.php
 *
 * @var WP_Query  $query
 * @var string    $show        live|upcoming|ended
 * @var string    $category    Selected category slug.
 * @var string    $search
 * @var bool      $with_search
 * @var int       $page
 * @var WP_Term[] $categories
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hk-list-wrap">
	<?php if ( $with_search ) : ?>
		<form class="hk-filter" method="get" action="">
			<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
				<label class="screen-reader-text" for="hk-cat"><?php esc_html_e( 'Category', 'huutokauppa' ); ?></label>
				<select name="hk_cat" id="hk-cat">
					<option value=""><?php esc_html_e( 'All categories', 'huutokauppa' ); ?></option>
					<?php foreach ( $categories as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $category, $t->slug ); ?>><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label class="screen-reader-text" for="hk-s"><?php esc_html_e( 'Search', 'huutokauppa' ); ?></label>
			<input type="search" id="hk-s" name="hk_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search items', 'huutokauppa' ); ?>">
			<button type="submit" class="hk-button"><?php esc_html_e( 'Search', 'huutokauppa' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( ! $query->have_posts() ) : ?>
		<p class="hk-empty">
			<?php
			if ( '' !== $search || $category ) {
				esc_html_e( 'No items match your search.', 'huutokauppa' );
			} elseif ( 'upcoming' === $show ) {
				esc_html_e( 'No upcoming auctions.', 'huutokauppa' );
			} elseif ( 'ended' === $show ) {
				esc_html_e( 'No ended auctions.', 'huutokauppa' );
			} else {
				esc_html_e( 'There are no open auctions right now.', 'huutokauppa' );
			}
			?>
		</p>
	<?php else : ?>
		<ul class="hk-list">
			<?php
			foreach ( $query->posts as $p ) {
				echo hk_template( 'item-card', array( 'id' => (int) $p->ID ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</ul>
		<?php
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'hk_page', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => __( '« Previous', 'huutokauppa' ),
				'next_text' => __( 'Next »', 'huutokauppa' ),
			)
		);
		if ( $links ) {
			echo '<nav class="hk-pagination">' . wp_kses_post( $links ) . '</nav>';
		}
		?>
	<?php endif; ?>
</div>
