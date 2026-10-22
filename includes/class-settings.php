<?php
/**
 * Plugin settings (stored in one option) and the settings screen.
 */

defined( 'ABSPATH' ) || exit;

class HK_Settings {

	const OPTION = 'hk_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			'currency_symbol'      => '€',
			'vat_rate'             => '25.5',
			'extend_seconds'       => 300,
			'default_min_raise'    => 10,
			'default_duration'     => 7,
			'per_page'             => 20,
			'poll_seconds'         => 10,
			'auto_accept'          => 0,
			'require_activation'   => 1,
			'allow_submissions'    => 1,
			'max_images'           => 6,
			'max_failed_logins'    => 5,
			'extra_languages'      => '',
			'notify_email'         => get_option( 'admin_email' ),
			'page_auctions'        => 0,
			'page_account'         => 0,
			'page_submit'          => 0,
			'page_register'        => 0,
			'page_profile'         => 0,
			'page_terms'           => 0,
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/** Language codes that get their own title/description fields on items, e.g. array( 'en', 'sv' ). */
	public static function extra_languages(): array {
		$codes = array_filter( array_map( 'trim', explode( ',', strtolower( (string) self::get( 'extra_languages' ) ) ) ) );
		return array_values( array_unique( array_filter( $codes, static fn( $c ) => (bool) preg_match( '/^[a-z]{2}$/', $c ) ) ) );
	}

	public static function page_url( string $key ): string {
		$id = (int) self::get( 'page_' . $key );
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	public static function init(): void {
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
		}
	}

	public static function register(): void {
		register_setting(
			'hk_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::all();
		$out['currency_symbol']    = sanitize_text_field( $input['currency_symbol'] ?? '€' );
		$out['vat_rate']           = (string) ( hk_parse_amount( $input['vat_rate'] ?? '' ) ?? 0 );
		$out['extend_seconds']     = max( 0, absint( $input['extend_seconds'] ?? 300 ) );
		$out['default_min_raise']  = hk_parse_amount( $input['default_min_raise'] ?? '' ) ?? 1;
		$out['default_duration']   = max( 1, absint( $input['default_duration'] ?? 7 ) );
		$out['per_page']           = min( 200, max( 1, absint( $input['per_page'] ?? 20 ) ) );
		$out['poll_seconds']       = min( 300, max( 3, absint( $input['poll_seconds'] ?? 10 ) ) );
		$out['max_images']         = min( 20, max( 1, absint( $input['max_images'] ?? 6 ) ) );
		$out['max_failed_logins']  = min( 100, absint( $input['max_failed_logins'] ?? 5 ) );
		$out['auto_accept']        = empty( $input['auto_accept'] ) ? 0 : 1;
		$out['require_activation'] = empty( $input['require_activation'] ) ? 0 : 1;
		$out['allow_submissions']  = empty( $input['allow_submissions'] ) ? 0 : 1;
		$out['extra_languages']    = sanitize_text_field( $input['extra_languages'] ?? '' );
		$out['notify_email']       = sanitize_email( $input['notify_email'] ?? '' );
		foreach ( array( 'auctions', 'account', 'submit', 'register', 'profile', 'terms' ) as $p ) {
			$out[ 'page_' . $p ] = absint( $input[ 'page_' . $p ] ?? 0 );
		}
		return $out;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::all();
		$name = self::OPTION;
		$num  = static function ( $key, $label, $help = '', $step = '1' ) use ( $s, $name ) {
			printf(
				'<tr><th scope="row"><label for="hk-%1$s">%2$s</label></th><td><input type="number" step="%5$s" min="0" id="hk-%1$s" name="%3$s[%1$s]" value="%4$s" class="small-text">%6$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( (string) $s[ $key ] ),
				esc_attr( $step ),
				$help ? ' <p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		$check = static function ( $key, $label, $help = '' ) use ( $s, $name ) {
			printf(
				'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="%3$s[%1$s]" value="1" %4$s> %5$s</label></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				checked( 1, (int) $s[ $key ], false ),
				esc_html( $help )
			);
		};
		$page = static function ( $key, $label ) use ( $s, $name ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			wp_dropdown_pages(
				array(
					'name'              => esc_attr( $name . '[page_' . $key . ']' ),
					'selected'          => (int) $s[ 'page_' . $key ],
					'show_option_none'  => esc_html__( '— None —', 'huutokauppa' ),
					'option_none_value' => '0',
				)
			);
			echo '</td></tr>';
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auction settings', 'huutokauppa' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'hk_settings_group' ); ?>
				<h2><?php esc_html_e( 'Bidding', 'huutokauppa' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$num( 'default_min_raise', __( 'Default minimum raise', 'huutokauppa' ), '', '0.01' );
					$num( 'default_duration', __( 'Default auction length (days)', 'huutokauppa' ) );
					$num( 'extend_seconds', __( 'Extension after late bid (seconds)', 'huutokauppa' ), __( 'A bid placed this close to the end moves the end time this far from the bid. 0 disables.', 'huutokauppa' ) );
					$check( 'auto_accept', __( 'Winner', 'huutokauppa' ), __( 'Accept the highest bid automatically when an auction ends (otherwise an administrator accepts or rejects it).', 'huutokauppa' ) );
					$num( 'vat_rate', __( 'VAT rate (%)', 'huutokauppa' ), __( 'Shown on items whose price excludes VAT.', 'huutokauppa' ), '0.1' );
					?>
					<tr><th scope="row"><label for="hk-currency"><?php esc_html_e( 'Currency symbol', 'huutokauppa' ); ?></label></th>
						<td><input type="text" id="hk-currency" class="small-text" name="<?php echo esc_attr( $name ); ?>[currency_symbol]" value="<?php echo esc_attr( $s['currency_symbol'] ); ?>"></td></tr>
				</table>

				<h2><?php esc_html_e( 'Users and submissions', 'huutokauppa' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$check( 'require_activation', __( 'Registration', 'huutokauppa' ), __( 'New users must confirm their e-mail address before they can bid.', 'huutokauppa' ) );
					$check( 'allow_submissions', __( 'Seller submissions', 'huutokauppa' ), __( 'Logged-in users can submit items for approval.', 'huutokauppa' ) );
					$num( 'max_images', __( 'Images per item', 'huutokauppa' ) );
					echo '<tr><th scope="row">' . esc_html__( 'Photo metadata', 'huutokauppa' ) . '</th><td>'
						. ( HK_Images::can_strip()
							? esc_html__( 'Removed: EXIF data such as camera details and GPS location is deleted from photos sellers upload.', 'huutokauppa' )
							: esc_html__( 'Kept: the Imagick PHP extension is not available, so photos are stored with their EXIF data, which may include the GPS location where they were taken.', 'huutokauppa' ) )
						. '</td></tr>';
					$num( 'max_failed_logins', __( 'Failed logins before lockout', 'huutokauppa' ), __( 'Counted per account over two hours. 0 disables.', 'huutokauppa' ) );
					?>
					<tr><th scope="row"><label for="hk-notify"><?php esc_html_e( 'Notification e-mail', 'huutokauppa' ); ?></label></th>
						<td><input type="email" id="hk-notify" class="regular-text" name="<?php echo esc_attr( $name ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Receives new submissions and ended auctions.', 'huutokauppa' ); ?></p></td></tr>
				</table>

				<h2><?php esc_html_e( 'Display', 'huutokauppa' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$num( 'per_page', __( 'Items per page', 'huutokauppa' ) );
					$num( 'poll_seconds', __( 'Live update interval (seconds)', 'huutokauppa' ) );
					?>
					<tr><th scope="row"><label for="hk-langs"><?php esc_html_e( 'Extra content languages', 'huutokauppa' ); ?></label></th>
						<td><input type="text" id="hk-langs" class="regular-text" placeholder="en, sv" name="<?php echo esc_attr( $name ); ?>[extra_languages]" value="<?php echo esc_attr( $s['extra_languages'] ); ?>">
						<p class="description"><?php esc_html_e( 'Two-letter codes. Items get a translated title, description and delivery text for each; visitors see the one matching the site language.', 'huutokauppa' ); ?></p></td></tr>
				</table>

				<h2><?php esc_html_e( 'Pages', 'huutokauppa' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$page( 'auctions', __( 'Auction list', 'huutokauppa' ) );
					$page( 'account', __( 'My bids', 'huutokauppa' ) );
					$page( 'submit', __( 'Submit an item', 'huutokauppa' ) );
					$page( 'register', __( 'Registration', 'huutokauppa' ) );
					$page( 'profile', __( 'My details', 'huutokauppa' ) );
					$page( 'terms', __( 'Terms of use', 'huutokauppa' ) );
					?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
