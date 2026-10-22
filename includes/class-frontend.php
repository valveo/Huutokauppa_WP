<?php
/**
 * Public side: item pages, auction list and the shortcodes that show them.
 *
 * Shortcodes:
 *   [huutokauppa]             Auction list. Attributes: category (slug), show (live|upcoming|ended), per_page.
 *   [huutokauppa_kategoriat]  Category list with live item counts.
 *   [huutokauppa_omat]        The logged-in user's bids, automatic bids and watch list.
 *   [huutokauppa_ilmoita]     Seller submission form          (HK_Forms)
 *   [huutokauppa_rekisteroidy] Registration form              (HK_Forms)
 *   [huutokauppa_tiedot]      Edit own details                 (HK_Forms)
 *
 * Every template in /templates can be overridden by copying it to
 * your-theme/huutokauppa/<name>.php.
 */

defined( 'ABSPATH' ) || exit;

class HK_Frontend {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'item_content' ), 20 );
		add_filter( 'the_title', array( __CLASS__, 'item_title' ), 10, 2 );
		add_filter( 'single_post_title', array( __CLASS__, 'single_title' ), 10, 2 );
		add_shortcode( 'huutokauppa', array( __CLASS__, 'sc_list' ) );
		add_shortcode( 'huutokauppa_kategoriat', array( __CLASS__, 'sc_categories' ) );
		add_shortcode( 'huutokauppa_omat', array( __CLASS__, 'sc_account' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'hide_theme_thumbnail' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'hide_bidder_archives' ), 1 );
		add_filter( 'wp_sitemaps_users_query_args', array( __CLASS__, 'sitemap_users' ) );
		add_filter( 'oembed_response_data', array( __CLASS__, 'oembed_data' ), 10, 2 );
	}

	/**
	 * Bidders and sellers must stay anonymous, but WordPress shows an author archive
	 * (with the display name in the title) for any user id, e.g. /?author=14.
	 */
	public static function hide_bidder_archives(): void {
		if ( is_author() ) {
			$author = get_queried_object();
			if ( ! $author instanceof WP_User || HK_Users::is_bidder_only( (int) $author->ID ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
		}
	}

	/** Leave auction items out when choosing which users get a sitemap entry. */
	public static function sitemap_users( array $args ): array {
		$types = $args['has_published_posts'] ?? array();
		if ( is_array( $types ) ) {
			$args['has_published_posts'] = array_values( array_diff( $types, array( HK_Post_Types::ITEM ) ) );
			if ( ! $args['has_published_posts'] ) {
				$args['has_published_posts'] = array( 'post' );
			}
		}
		return $args;
	}

	public static function oembed_data( array $data, $post ): array {
		if ( $post instanceof WP_Post && HK_Post_Types::ITEM === $post->post_type ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	/** The item page has its own gallery, so the theme's featured image would show the same picture twice. */
	public static function hide_theme_thumbnail( $html, $post_id ) {
		if ( is_singular( HK_Post_Types::ITEM ) && (int) $post_id === get_queried_object_id() && apply_filters( 'hk_hide_theme_thumbnail', true ) ) {
			return '';
		}
		return $html;
	}

	public static function register_assets(): void {
		wp_register_style( 'huutokauppa', HK_URL . 'assets/css/frontend.css', array(), HK_VERSION );
		wp_register_script( 'huutokauppa', HK_URL . 'assets/js/frontend.js', array(), HK_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'huutokauppa',
			'HK',
			array(
				'rest'     => esc_url_raw( rest_url( HK_Rest::NS . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'now'      => hk_now(),
				'poll'     => (int) HK_Settings::get( 'poll_seconds' ),
				'loggedIn' => is_user_logged_in(),
				'i18n'     => array(
					'days'        => __( 'd', 'huutokauppa' ),
					'ended'       => __( 'Ended', 'huutokauppa' ),
					'startsIn'    => __( 'Starts in', 'huutokauppa' ),
					/* translators: %s: bid amount */
					'confirmBid'  => __( 'Place a bid of %s?', 'huutokauppa' ),
					/* translators: %s: maximum amount */
					'confirmAuto' => __( 'Bid automatically up to %s?', 'huutokauppa' ),
					'error'       => __( 'Something went wrong. Reload the page and try again.', 'huutokauppa' ),
					'leading'     => __( 'You are the highest bidder', 'huutokauppa' ),
					'outbid'      => __( 'You have been outbid', 'huutokauppa' ),
					'watch'       => __( 'Add to watch list', 'huutokauppa' ),
					'unwatch'     => __( 'Remove from watch list', 'huutokauppa' ),
					'auto'        => __( 'automatic', 'huutokauppa' ),
					'you'         => __( 'you', 'huutokauppa' ),
					'noBids'      => __( 'No bids yet.', 'huutokauppa' ),
				),
			)
		);
	}

	public static function enqueue(): void {
		if ( ! wp_script_is( 'huutokauppa', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'huutokauppa' );
		wp_enqueue_script( 'huutokauppa' );
	}

	public static function body_class( array $classes ): array {
		if ( is_singular( HK_Post_Types::ITEM ) ) {
			$classes[] = 'hk-item-page';
		}
		return $classes;
	}

	/* ------------------------------------------------------------------
	 * Item page
	 * ---------------------------------------------------------------- */

	public static function item_title( $title, $post_id = 0 ) {
		if ( is_admin() || ! $post_id || HK_Post_Types::ITEM !== get_post_type( $post_id ) ) {
			return $title;
		}
		$t = HK_Item::localized( (int) $post_id, 'title' );
		return '' !== $t ? $t : $title; // Stored sanitized (sanitize_text_field) on save.
	}

	public static function single_title( $title, $post ) {
		return ( $post && HK_Post_Types::ITEM === $post->post_type ) ? HK_Item::localized( $post->ID, 'title' ) : $title;
	}

	public static function item_content( $content ) {
		if ( is_admin() || ! is_singular( HK_Post_Types::ITEM ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		static $running = false;
		if ( $running ) {
			return $content;
		}
		$running = true;
		$id      = get_the_ID();
		self::enqueue();

		$description = HK_Item::localized( $id, 'content' );
		$html        = hk_template(
			'single-item',
			array(
				'id'          => $id,
				'user_id'     => get_current_user_id(),
				'snap'        => HK_Rest::public_snapshot( $id, true ),
				// Not the_content: that would run shortcodes and auto-embeds, which sellers
				// could use to pull other content into their item page.
				'description' => wpautop( wptexturize( wp_kses_post( $description ) ) ),
				'gallery'     => HK_Item::gallery( $id ),
				'can_bid'     => HK_Bidding::can_bid( $id, get_current_user_id() ),
				'watching'    => is_user_logged_in() && HK_Users::is_watching( $id, get_current_user_id() ),
				'flash'       => HK_Forms::take_flash(),
			)
		);
		$running = false;
		return $html;
	}

	/** VAT note under prices. */
	public static function vat_note( int $id ): string {
		switch ( HK_Item::vat_mode( $id ) ) {
			case 'plus':
				/* translators: %s: VAT rate */
				return sprintf( __( 'VAT %s %% is added to the price.', 'huutokauppa' ), number_format_i18n( (float) HK_Settings::get( 'vat_rate' ), 1 ) );
			case 'margin':
				return __( 'VAT 0 %, margin scheme applies.', 'huutokauppa' );
		}
		return '';
	}

	public static function state_label( string $state ): string {
		$labels = array(
			HK_Item::STATE_UPCOMING => __( 'Starting soon', 'huutokauppa' ),
			HK_Item::STATE_LIVE     => __( 'Open', 'huutokauppa' ),
			HK_Item::STATE_ENDED    => __( 'Ended', 'huutokauppa' ),
			HK_Item::STATE_SOLD     => __( 'Sold', 'huutokauppa' ),
			HK_Item::STATE_UNSOLD   => __( 'Ended, not sold', 'huutokauppa' ),
		);
		return $labels[ $state ] ?? '';
	}

	/** Login prompt with links to registration. */
	public static function login_box( string $message = '' ): string {
		$html  = '<div class="hk-login">';
		$html .= '<p class="hk-login__lead">' . esc_html( $message ?: __( 'Log in to bid on items.', 'huutokauppa' ) ) . '</p>';
		$html .= wp_login_form(
			array(
				'echo'     => false,
				// Back to the current page. Built from the queried object rather than the raw
				// request URI, which also keeps sub-directory installs working.
				'redirect' => esc_url_raw( is_singular() ? (string) get_permalink( get_queried_object_id() ) : home_url( '/' ) ),
				'form_id'  => 'hk-loginform-' . wp_rand(),
			)
		);
		$links = array();
		if ( HK_Settings::get( 'page_register' ) ) {
			$links[] = '<a href="' . esc_url( HK_Settings::page_url( 'register' ) ) . '">' . esc_html__( 'Create an account', 'huutokauppa' ) . '</a>';
		}
		$links[] = '<a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Forgot your password?', 'huutokauppa' ) . '</a>';
		$html   .= '<p class="hk-login__links">' . implode( ' · ', $links ) . '</p></div>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Lists
	 * ---------------------------------------------------------------- */

	/** Meta query fragment for a list view. */
	private static function state_query( string $show ): array {
		$now = hk_now();
		switch ( $show ) {
			case 'upcoming':
				return array(
					array( 'key' => '_hk_start', 'value' => $now, 'compare' => '>', 'type' => 'NUMERIC' ),
				);
			case 'ended':
				return array(
					array( 'key' => '_hk_end', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC' ),
				);
			default:
				return array(
					'relation' => 'AND',
					array( 'key' => '_hk_end', 'value' => $now, 'compare' => '>', 'type' => 'NUMERIC' ),
					array( 'key' => '_hk_start', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC' ),
					array( 'key' => '_hk_result', 'compare' => 'NOT EXISTS' ),
				);
		}
	}

	public static function count_live( ?int $term_id = null ): int {
		$args = array(
			'post_type'      => HK_Post_Types::ITEM,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => self::state_query( 'live' ),
		);
		if ( $term_id ) {
			$args['tax_query'] = array( array( 'taxonomy' => HK_Post_Types::CATEGORY, 'terms' => $term_id ) );
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	public static function sc_list( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'show'     => 'live',
				'per_page' => HK_Settings::get( 'per_page' ),
				'search'   => 'yes',
			),
			$atts,
			'huutokauppa'
		);
		self::enqueue();

		// phpcs:disable WordPress.Security.NonceVerification
		$cat    = sanitize_title( wp_unslash( $_GET['hk_cat'] ?? $atts['category'] ) );
		$search = sanitize_text_field( wp_unslash( $_GET['hk_s'] ?? '' ) );
		$page   = max( 1, absint( $_GET['hk_page'] ?? 1 ) );
		// phpcs:enable
		$show = in_array( $atts['show'], array( 'live', 'upcoming', 'ended' ), true ) ? $atts['show'] : 'live';

		$args = array(
			'post_type'      => HK_Post_Types::ITEM,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $atts['per_page'] ),
			'paged'          => $page,
			'meta_query'     => self::state_query( $show ),
			'meta_key'       => 'upcoming' === $show ? '_hk_start' : '_hk_end',
			'orderby'        => 'meta_value_num',
			'order'          => 'ended' === $show ? 'DESC' : 'ASC',
		);
		if ( $cat ) {
			$args['tax_query'] = array( array( 'taxonomy' => HK_Post_Types::CATEGORY, 'field' => 'slug', 'terms' => $cat ) );
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$q = new WP_Query( $args );

		return hk_template(
			'list',
			array(
				'query'      => $q,
				'show'       => $show,
				'category'   => $cat,
				'search'     => $search,
				'with_search'=> 'no' !== $atts['search'],
				'page'       => $page,
				'categories' => get_terms( array( 'taxonomy' => HK_Post_Types::CATEGORY, 'hide_empty' => false ) ),
			)
		);
	}

	public static function sc_categories(): string {
		self::enqueue();
		$terms = get_terms( array( 'taxonomy' => HK_Post_Types::CATEGORY, 'hide_empty' => false ) );
		$base  = HK_Settings::page_url( 'auctions' );
		$html  = '<ul class="hk-categories">';
		$html .= '<li><a href="' . esc_url( $base ) . '">' . esc_html__( 'All items', 'huutokauppa' ) . '</a> <span class="hk-count">(' . self::count_live() . ')</span></li>';
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$html .= '<li><a href="' . esc_url( add_query_arg( 'hk_cat', $t->slug, $base ) ) . '">' . esc_html( $t->name ) . '</a> <span class="hk-count">(' . self::count_live( $t->term_id ) . ')</span></li>';
		}
		return $html . '</ul>';
	}

	/* ------------------------------------------------------------------
	 * My bids
	 * ---------------------------------------------------------------- */

	public static function sc_account(): string {
		self::enqueue();
		$msg = self::activation_message();
		if ( ! is_user_logged_in() ) {
			return $msg . self::login_box( __( 'Log in to see your bids.', 'huutokauppa' ) );
		}
		global $wpdb;
		$uid   = get_current_user_id();
		$bids  = HK_Install::table( 'bids' );
		$autos = HK_Install::table( 'autobids' );
		$watch = HK_Install::table( 'watch' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, MAX(amount) AS my_high, MAX(created_at) AS last_at FROM $bids WHERE user_id = %d GROUP BY item_id ORDER BY last_at DESC", $uid ) );
		$auto = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, max_amount FROM $autos WHERE user_id = %d ORDER BY updated_at DESC", $uid ) );
		$w    = $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM $watch WHERE user_id = %d ORDER BY created_at DESC", $uid ) );
		// phpcs:enable

		$open   = array();
		$closed = array();
		foreach ( $rows as $r ) {
			$id = (int) $r->item_id;
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$state = HK_Item::state( $id );
			$high  = HK_Bidding::highest_bid( $id );
			$entry = array(
				'id'      => $id,
				'state'   => $state,
				'my_high' => (float) $r->my_high,
				'price'   => $high ? (float) $high->amount : 0.0,
				'leading' => $high && (int) $high->user_id === $uid,
				'won'     => HK_Item::STATE_SOLD === $state && HK_Item::winner( $id ) === $uid,
			);
			if ( in_array( $state, array( HK_Item::STATE_LIVE, HK_Item::STATE_UPCOMING ), true ) ) {
				$open[] = $entry;
			} else {
				$closed[] = $entry;
			}
		}
		$auto = array_values( array_filter( $auto, static fn( $a ) => HK_Item::is_live( (int) $a->item_id ) ) );
		$w    = array_values( array_filter( array_map( 'intval', $w ), static fn( $id ) => 'publish' === get_post_status( $id ) ) );

		return $msg . hk_template(
			'account',
			array(
				'open'   => $open,
				'closed' => $closed,
				'auto'   => $auto,
				'watch'  => $w,
				'flash'  => HK_Forms::take_flash(),
			)
		);
	}

	public static function activation_message(): string {
		$m = sanitize_key( wp_unslash( $_GET['hk_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		switch ( $m ) {
			case 'activated':
				return hk_notice( __( 'Your e-mail address is confirmed. You can now log in and bid.', 'huutokauppa' ), 'success' );
			case 'already':
				return hk_notice( __( 'This account is already activated.', 'huutokauppa' ) );
			case 'invalid':
				return hk_notice( __( 'The activation link is invalid or has already been used.', 'huutokauppa' ), 'error' );
			case 'email_changed':
				return hk_notice( __( 'Your e-mail address has been changed.', 'huutokauppa' ), 'success' );
			case 'taken':
				return hk_notice( __( 'The e-mail address could not be changed because another account now uses it.', 'huutokauppa' ), 'error' );
		}
		return '';
	}
}
