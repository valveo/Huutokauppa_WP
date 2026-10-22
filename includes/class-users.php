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
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failed_login' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_activation' ) );
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

	public static function handle_activation(): void {
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

	private static function failed_count( int $user_id ): int {
		global $wpdb;
		$t = HK_Install::table( 'login_attempts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND attempted_at > %d", $user_id, hk_now() - 2 * HOUR_IN_SECONDS ) );
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

	public static function record_failed_login( $username ): void {
		$u = self::user_by_login( (string) $username );
		if ( $u ) {
			global $wpdb;
			$wpdb->insert( HK_Install::table( 'login_attempts' ), array( 'user_id' => $u->ID, 'attempted_at' => hk_now() ), array( '%d', '%d' ) );
		}
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
