<?php
/**
 * Bidder accounts: extra profile fields, e-mail activation, blocking and a
 * simple failed-login limit.
 */

defined( 'ABSPATH' ) || exit;

class HK_Users {

	/** Profile fields kept in user meta (key => label is built lazily for translations). */
	const FIELDS = array( 'phone', 'address', 'postcode', 'city', 'business_id' );

	public static function field_labels(): array {
		return array(
			'phone'       => __( 'Phone', 'huutokauppa' ),
			'address'     => __( 'Street address', 'huutokauppa' ),
			'postcode'    => __( 'Postcode', 'huutokauppa' ),
			'city'        => __( 'City', 'huutokauppa' ),
			'business_id' => __( 'Business ID', 'huutokauppa' ),
		);
	}

	public static function init(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column' ), 10, 3 );
		add_filter( 'authenticate', array( __CLASS__, 'check_lockout' ), 30, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failed_login' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'clear_failed_logins' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'clear_all_failed_logins' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_activation' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_bidders_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_action( 'delete_user', array( __CLASS__, 'delete_user_data' ) );
	}

	public static function get( int $user_id, string $field ): string {
		return (string) get_user_meta( $user_id, 'hk_' . $field, true );
	}

	public static function is_blocked( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'hk_blocked', true );
	}

	public static function set_blocked( int $user_id, bool $blocked ): void {
		if ( $blocked ) {
			update_user_meta( $user_id, 'hk_blocked', 1 );
			// A blocked user's automatic bids stop. Bids already placed stay until an
			// administrator removes them on the item's edit screen.
			global $wpdb;
			$wpdb->delete( HK_Install::table( 'autobids' ), array( 'user_id' => $user_id ), array( '%d' ) );
		} else {
			delete_user_meta( $user_id, 'hk_blocked' );
		}
	}

	/** Users created before the plugin, or by an administrator, count as activated. */
	public static function is_activated( int $user_id ): bool {
		return '' === (string) get_user_meta( $user_id, 'hk_activation', true );
	}

	/** Create an activation key and return the activation URL. */
	public static function start_activation( int $user_id ): string {
		$key = wp_generate_password( 32, false );
		update_user_meta( $user_id, 'hk_activation', wp_hash_password( $key ) );
		return add_query_arg(
			array(
				'hk_activate' => $key,
				'hk_user'     => $user_id,
			),
			HK_Settings::page_url( 'account' )
		);
	}

	/* ------------------------------------------------------------------
	 * E-mail address change: the new address takes effect only after the
	 * link sent to it has been opened.
	 * ---------------------------------------------------------------- */

	const EMAIL_CHANGES_PER_DAY = 3;

	/** Pending new address, or ''. */
	public static function pending_email( int $user_id ): string {
		$p = get_user_meta( $user_id, 'hk_new_email', true );
		return is_array( $p ) && ! empty( $p['email'] ) && (int) $p['time'] > hk_now() - 2 * DAY_IN_SECONDS ? (string) $p['email'] : '';
	}

	/** @return true|WP_Error */
	public static function request_email_change( int $user_id, string $email ) {
		$limit_key = 'hk_emchg_' . $user_id;
		$count     = (int) get_transient( $limit_key );
		if ( $count >= (int) apply_filters( 'hk_email_changes_per_day', self::EMAIL_CHANGES_PER_DAY ) ) {
			return new WP_Error( 'hk_limit', __( 'You have changed your e-mail address too many times today. Please try again tomorrow.', 'huutokauppa' ) );
		}
		set_transient( $limit_key, $count + 1, DAY_IN_SECONDS );
		$key = wp_generate_password( 32, false );
		update_user_meta(
			$user_id,
			'hk_new_email',
			array(
				'email' => $email,
				'hash'  => wp_hash_password( $key ),
				'time'  => hk_now(),
			)
		);
		$url = add_query_arg(
			array(
				'hk_confirm_email' => $key,
				'hk_user'          => $user_id,
			),
			HK_Settings::page_url( 'profile' )
		);
		HK_Mailer::send( 'email_change', $email, array( 'url' => $url, 'user_id' => $user_id ) );
		return true;
	}

	public static function cancel_email_change( int $user_id ): void {
		delete_user_meta( $user_id, 'hk_new_email' );
	}

	private static function confirm_email_change( int $user_id, string $key ): string {
		$p = get_user_meta( $user_id, 'hk_new_email', true );
		if ( ! is_array( $p ) || '' === self::pending_email( $user_id ) || ! wp_check_password( $key, (string) $p['hash'], $user_id ) ) {
			return 'invalid';
		}
		$other = email_exists( $p['email'] );
		if ( $other && (int) $other !== $user_id ) {
			delete_user_meta( $user_id, 'hk_new_email' );
			return 'taken';
		}
		$r = wp_update_user( array( 'ID' => $user_id, 'user_email' => $p['email'] ) );
		delete_user_meta( $user_id, 'hk_new_email' );
		return is_wp_error( $r ) ? 'invalid' : 'email_changed';
	}

	public static function handle_activation(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- links from e-mail, protected by the one-time key.
		if ( ! empty( $_GET['hk_confirm_email'] ) && ! empty( $_GET['hk_user'] ) ) {
			$status = self::confirm_email_change( absint( $_GET['hk_user'] ), sanitize_text_field( wp_unslash( $_GET['hk_confirm_email'] ) ) );
			wp_safe_redirect( add_query_arg( 'hk_msg', $status, remove_query_arg( array( 'hk_confirm_email', 'hk_user' ) ) ) );
			exit;
		}
		// phpcs:enable
		if ( empty( $_GET['hk_activate'] ) || empty( $_GET['hk_user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$user_id = absint( $_GET['hk_user'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$key     = sanitize_text_field( wp_unslash( $_GET['hk_activate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$hash    = (string) get_user_meta( $user_id, 'hk_activation', true );
		$target  = remove_query_arg( array( 'hk_activate', 'hk_user' ) );
		if ( '' === $hash ) {
			$status = 'already';
		} elseif ( wp_check_password( $key, $hash, $user_id ) ) {
			delete_user_meta( $user_id, 'hk_activation' );
			$status = 'activated';
		} else {
			$status = 'invalid';
		}
		wp_safe_redirect( add_query_arg( 'hk_msg', $status, $target ) );
		exit;
	}

	/* ------------------------------------------------------------------ */

	public static function profile_fields( WP_User $user ): void {
		$labels = self::field_labels();
		?>
		<h2><?php esc_html_e( 'Auction details', 'huutokauppa' ); ?></h2>
		<?php wp_nonce_field( 'hk_profile_' . $user->ID, 'hk_profile_nonce' ); ?>
		<table class="form-table" role="presentation">
			<?php foreach ( self::FIELDS as $f ) : ?>
				<tr>
					<th><label for="hk_<?php echo esc_attr( $f ); ?>"><?php echo esc_html( $labels[ $f ] ); ?></label></th>
					<td><input type="text" class="regular-text" id="hk_<?php echo esc_attr( $f ); ?>" name="hk_<?php echo esc_attr( $f ); ?>" value="<?php echo esc_attr( self::get( $user->ID, $f ) ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><?php esc_html_e( 'Newsletter', 'huutokauppa' ); ?></th>
				<td><label><input type="checkbox" name="hk_newsletter" value="1" <?php checked( (bool) get_user_meta( $user->ID, 'hk_newsletter', true ) ); ?>> <?php esc_html_e( 'Subscribed', 'huutokauppa' ); ?></label></td>
			</tr>
			<?php if ( current_user_can( 'manage_auctions' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Bidding', 'huutokauppa' ); ?></th>
					<td>
						<label><input type="checkbox" name="hk_blocked" value="1" <?php checked( self::is_blocked( $user->ID ) ); ?>> <?php esc_html_e( 'Blocked from bidding', 'huutokauppa' ); ?></label><br>
						<?php if ( ! self::is_activated( $user->ID ) ) : ?>
							<label><input type="checkbox" name="hk_activate_now" value="1"> <?php esc_html_e( 'E-mail not confirmed yet — activate now', 'huutokauppa' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['hk_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['hk_profile_nonce'] ), 'hk_profile_' . $user_id ) ) {
			return;
		}
		foreach ( self::FIELDS as $f ) {
			update_user_meta( $user_id, 'hk_' . $f, sanitize_text_field( wp_unslash( $_POST[ 'hk_' . $f ] ?? '' ) ) );
		}
		update_user_meta( $user_id, 'hk_newsletter', empty( $_POST['hk_newsletter'] ) ? 0 : 1 );
		if ( current_user_can( 'manage_auctions' ) ) {
			self::set_blocked( $user_id, ! empty( $_POST['hk_blocked'] ) );
			if ( ! empty( $_POST['hk_activate_now'] ) ) {
				delete_user_meta( $user_id, 'hk_activation' );
			}
		}
	}

	public static function columns( array $cols ): array {
		$cols['hk_status'] = __( 'Auctions', 'huutokauppa' );
		return $cols;
	}

	public static function column( $out, string $col, int $user_id ) {
		if ( 'hk_status' !== $col ) {
			return $out;
		}
		$bits = array();
		if ( self::is_blocked( $user_id ) ) {
			$bits[] = '<strong style="color:#b32d2e">' . esc_html__( 'Blocked', 'huutokauppa' ) . '</strong>';
		}
		if ( ! self::is_activated( $user_id ) ) {
			$bits[] = esc_html__( 'Not activated', 'huutokauppa' );
		}
		if ( get_user_meta( $user_id, 'hk_newsletter', true ) ) {
			$bits[] = esc_html__( 'Newsletter', 'huutokauppa' );
		}
		return implode( '<br>', $bits );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Hash of the visitor's IP address. Failed logins are counted per account and
	 * address, so nobody can lock another person out from their own network.
	 */
	private static function ip_hash(): string {
		return md5( wp_salt( 'auth' ) . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function failed_count( int $user_id ): int {
		global $wpdb;
		$t = HK_Install::table( 'login_attempts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND ip_hash = %s AND attempted_at > %d", $user_id, self::ip_hash(), hk_now() - 2 * HOUR_IN_SECONDS ) );
	}

	private static function user_by_login( string $login ): ?WP_User {
		$u = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		return $u ?: null;
	}

	public static function check_lockout( $user, $username, $password ) {
		$max = (int) HK_Settings::get( 'max_failed_logins' );
		if ( $max <= 0 || ! $username ) {
			return $user;
		}
		$u = self::user_by_login( (string) $username );
		if ( $u && self::failed_count( $u->ID ) >= $max ) {
			return new WP_Error( 'hk_locked', __( 'Too many failed login attempts. Try again in two hours or reset your password.', 'huutokauppa' ) );
		}
		return $user;
	}

	public static function record_failed_login( $username, $error = null ): void {
		// A refusal caused by the lockout itself is not a new guess; counting it would
		// keep the lock alive for as long as someone keeps trying.
		if ( $error instanceof WP_Error && in_array( 'hk_locked', $error->get_error_codes(), true ) ) {
			return;
		}
		$u = self::user_by_login( (string) $username );
		if ( $u ) {
			global $wpdb;
			$wpdb->insert(
				HK_Install::table( 'login_attempts' ),
				array( 'user_id' => $u->ID, 'ip_hash' => self::ip_hash(), 'attempted_at' => hk_now() ),
				array( '%d', '%s', '%d' )
			);
		}
	}

	public static function clear_failed_logins( $login, $user = null ): void {
		if ( $user instanceof WP_User ) {
			global $wpdb;
			$wpdb->delete( HK_Install::table( 'login_attempts' ), array( 'user_id' => $user->ID, 'ip_hash' => self::ip_hash() ), array( '%d', '%s' ) );
		}
	}

	public static function clear_all_failed_logins( $user ): void {
		if ( $user instanceof WP_User ) {
			global $wpdb;
			$wpdb->delete( HK_Install::table( 'login_attempts' ), array( 'user_id' => $user->ID ), array( '%d' ) );
		}
	}

	/* ------------------------------------------------------------------ */

	/** Whether a user only bids/sells and should use the front-end pages instead of wp-admin. */
	public static function is_bidder_only( int $user_id ): bool {
		$only = $user_id && ! user_can( $user_id, 'edit_posts' ) && ! user_can( $user_id, 'manage_auctions' );
		return (bool) apply_filters( 'hk_keep_out_of_admin', $only, $user_id );
	}

	/**
	 * Bidders edit their details on the front-end "My details" page, which asks for the
	 * current password before changing the e-mail address or password. The core profile
	 * screen does not, so it is not offered to them.
	 */
	public static function keep_bidders_out_of_admin(): void {
		global $pagenow;
		if ( wp_doing_ajax() || in_array( $pagenow, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		if ( self::is_bidder_only( get_current_user_id() ) ) {
			wp_safe_redirect( HK_Settings::get( 'page_profile' ) ? HK_Settings::page_url( 'profile' ) : home_url( '/' ) );
			exit;
		}
	}

	public static function admin_bar( $show ) {
		return self::is_bidder_only( get_current_user_id() ) ? false : $show;
	}

	/** Remove a deleted user's automatic bids and watch list. Bid history is kept. */
	public static function delete_user_data( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( HK_Install::table( 'autobids' ), array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( HK_Install::table( 'watch' ), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/* ------------------------------------------------------------------ */

	public static function is_watching( int $item_id, int $user_id ): bool {
		global $wpdb;
		$t = HK_Install::table( 'watch' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $t WHERE item_id = %d AND user_id = %d", $item_id, $user_id ) );
	}

	/** Toggle; returns the new state. */
	public static function toggle_watch( int $item_id, int $user_id ): bool {
		global $wpdb;
		$t = HK_Install::table( 'watch' );
		if ( self::is_watching( $item_id, $user_id ) ) {
			$wpdb->delete( $t, array( 'item_id' => $item_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
			return false;
		}
		$wpdb->insert( $t, array( 'item_id' => $item_id, 'user_id' => $user_id, 'created_at' => gmdate( 'Y-m-d H:i:s', hk_now() ) ), array( '%d', '%d', '%s' ) );
		return true;
	}
}
