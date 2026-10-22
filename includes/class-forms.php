<?php
/**
 * Front-end forms: bidding fallbacks (without JavaScript), registration,
 * profile editing and seller submissions.
 *
 * Page forms post back to their own page and are handled on template_redirect.
 * On success the user is redirected with a one-time message (post/redirect/get);
 * on error the form is shown again with the entered values.
 */

defined( 'ABSPATH' ) || exit;

class HK_Forms {

	private static array $errors = array();
	private static array $old    = array();

	public static function init(): void {
		foreach ( array( 'hk_bid', 'hk_autobid', 'hk_autobid_remove', 'hk_watch' ) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle_bid_form' ) );
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, 'handle_guest_post' ) );
		}
		add_action( 'template_redirect', array( __CLASS__, 'handle_page_forms' ), 5 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_shortcode( 'huutokauppa_rekisteroidy', array( __CLASS__, 'sc_register' ) );
		add_shortcode( 'huutokauppa_tiedot', array( __CLASS__, 'sc_profile' ) );
		add_shortcode( 'huutokauppa_ilmoita', array( __CLASS__, 'sc_submit' ) );
	}

	/* ------------------------------------------------------------------
	 * One-time messages
	 * ---------------------------------------------------------------- */

	private static function flash_key(): string {
		$uid = get_current_user_id();
		if ( $uid ) {
			return 'hk_flash_u' . $uid;
		}
		$token = sanitize_key( wp_unslash( $_COOKIE['hk_flash'] ?? '' ) );
		if ( ! $token && ! headers_sent() ) {
			$token = wp_generate_password( 20, false );
			setcookie( 'hk_flash', $token, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['hk_flash'] = $token;
		}
		return 'hk_flash_g' . $token;
	}

	public static function set_flash( string $type, string $message ): void {
		set_transient( self::flash_key(), array( 'type' => $type, 'message' => $message ), 5 * MINUTE_IN_SECONDS );
	}

	public static function take_flash(): ?array {
		if ( ! get_current_user_id() && empty( $_COOKIE['hk_flash'] ) ) {
			return null;
		}
		$key = self::flash_key();
		$v   = get_transient( $key );
		if ( $v ) {
			delete_transient( $key );
		}
		return is_array( $v ) ? $v : null;
	}

	private static function redirect( string $url ): void {
		wp_safe_redirect( $url ?: home_url( '/' ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Bidding without JavaScript
	 * ---------------------------------------------------------------- */

	public static function handle_guest_post(): void {
		self::redirect( wp_login_url( esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function handle_bid_form(): void {
		$item     = absint( $_POST['item'] ?? 0 );
		$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) ), get_permalink( $item ) ?: home_url( '/' ) );
		if ( ! $item || ! wp_verify_nonce( sanitize_key( $_POST['hk_nonce'] ?? '' ), 'hk_bid_' . $item ) ) {
			self::set_flash( 'error', __( 'The form has expired. Please try again.', 'huutokauppa' ) );
			self::redirect( $redirect );
		}
		$uid    = get_current_user_id();
		$action = sanitize_key( $_POST['action'] ?? '' );

		switch ( $action ) {
			case 'hk_bid':
				$amount = hk_parse_amount( wp_unslash( $_POST['amount'] ?? '' ) );
				$r      = null === $amount
					? array( 'ok' => false, 'message' => __( 'Enter the bid as a number, for example 150.', 'huutokauppa' ) )
					: HK_Bidding::place_bid( $item, $uid, $amount );
				break;
			case 'hk_autobid':
				$max = hk_parse_amount( wp_unslash( $_POST['max'] ?? '' ) );
				$r   = null === $max
					? array( 'ok' => false, 'message' => __( 'Enter the maximum as a number, for example 500.', 'huutokauppa' ) )
					: HK_Bidding::set_autobid( $item, $uid, $max );
				break;
			case 'hk_autobid_remove':
				HK_Bidding::remove_autobid( $item, $uid );
				$r = array( 'ok' => true, 'message' => __( 'Automatic bid removed.', 'huutokauppa' ) );
				break;
			case 'hk_watch':
				$on = HK_Users::toggle_watch( $item, $uid );
				$r  = array( 'ok' => true, 'message' => $on ? __( 'Added to your watch list.', 'huutokauppa' ) : __( 'Removed from your watch list.', 'huutokauppa' ) );
				break;
			default:
				$r = array( 'ok' => false, 'message' => __( 'Unknown action.', 'huutokauppa' ) );
		}
		self::set_flash( $r['ok'] ? ( isset( $r['code'] ) && 'hk_outbid' === $r['code'] ? 'warning' : 'success' ) : 'error', $r['message'] );
		self::redirect( $redirect );
	}

	/* ------------------------------------------------------------------
	 * Page forms
	 * ---------------------------------------------------------------- */

	public static function handle_page_forms(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['hk_form'] ) ) {
			return;
		}
		$form = sanitize_key( $_POST['hk_form'] );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['hk_nonce'] ?? '' ), 'hk_form_' . $form ) ) {
			self::$errors[] = __( 'The form has expired. Please try again.', 'huutokauppa' );
			return;
		}
		// Fields are posted as hk_f_<name>: plain names like "name" are WordPress query
		// variables and would turn the request into a 404.
		foreach ( wp_unslash( $_POST ) as $k => $v ) {
			if ( str_starts_with( (string) $k, 'hk_f_' ) ) {
				self::$old[ substr( $k, 5 ) ] = $v;
			}
		}
		switch ( $form ) {
			case 'register':
				self::process_register();
				break;
			case 'profile':
				self::process_profile();
				break;
			case 'submit':
				self::process_submit();
				break;
		}
	}

	private static function old( string $key, string $default = '' ): string {
		return isset( self::$old[ $key ] ) && is_scalar( self::$old[ $key ] ) ? (string) self::$old[ $key ] : $default;
	}

	private static function errors_html(): string {
		if ( ! self::$errors ) {
			return '';
		}
		$html = '<div class="hk-notice hk-notice--error" role="alert"><ul>';
		foreach ( self::$errors as $e ) {
			$html .= '<li>' . esc_html( $e ) . '</li>';
		}
		return $html . '</ul></div>';
	}

	private static function field( string $name, string $label, array $o = array() ): string {
		$o     = array_merge( array( 'type' => 'text', 'required' => false, 'value' => null, 'help' => '', 'attrs' => '' ), $o );
		$value = $o['value'] ?? self::old( $name );
		$id    = 'hk-f-' . $name;
		$req   = $o['required'] ? ' required' : '';
		$star  = $o['required'] ? ' <span class="hk-req" aria-hidden="true">*</span>' : '';
		$html  = '<p class="hk-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $star . '</label>';
		if ( 'textarea' === $o['type'] ) {
			$html .= '<textarea id="' . esc_attr( $id ) . '" name="hk_f_' . esc_attr( $name ) . '" rows="6"' . $req . ' ' . $o['attrs'] . '>' . esc_textarea( $value ) . '</textarea>';
		} else {
			$html .= '<input type="' . esc_attr( $o['type'] ) . '" id="' . esc_attr( $id ) . '" name="hk_f_' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req . ' ' . $o['attrs'] . '>';
		}
		if ( $o['help'] ) {
			$html .= '<small class="hk-hint">' . esc_html( $o['help'] ) . '</small>';
		}
		return $html . '</p>';
	}

	private static function form_open( string $form, bool $files = false ): string {
		return '<form class="hk-form hk-page-form" method="post" action=""' . ( $files ? ' enctype="multipart/form-data"' : '' ) . '>'
			. '<input type="hidden" name="hk_form" value="' . esc_attr( $form ) . '">'
			. wp_nonce_field( 'hk_form_' . $form, 'hk_nonce', true, false );
	}

	/* ------------------------------------------------------------------
	 * Registration
	 * ---------------------------------------------------------------- */

	private static function process_register(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		if ( '' !== self::old( 'hk_website' ) ) { // Honeypot.
			self::redirect( home_url( '/' ) );
		}
		$name  = sanitize_text_field( self::old( 'name' ) );
		$email = sanitize_email( self::old( 'email' ) );
		$pass  = self::old( 'password' );
		$pass2 = self::old( 'password2' );

		if ( '' === $name ) {
			self::$errors[] = __( 'Enter your name.', 'huutokauppa' );
		}
		if ( ! is_email( $email ) ) {
			self::$errors[] = __( 'Enter a valid e-mail address.', 'huutokauppa' );
		} elseif ( email_exists( $email ) || username_exists( $email ) ) {
			self::$errors[] = __( 'An account with this e-mail address already exists. Log in or reset your password.', 'huutokauppa' );
		}
		if ( strlen( $pass ) < 8 ) {
			self::$errors[] = __( 'The password must be at least 8 characters.', 'huutokauppa' );
		} elseif ( $pass !== $pass2 ) {
			self::$errors[] = __( 'The passwords do not match.', 'huutokauppa' );
		}
		foreach ( array( 'phone', 'address', 'postcode', 'city' ) as $req ) {
			if ( '' === trim( self::old( $req ) ) ) {
				self::$errors[] = __( 'Fill in all required fields.', 'huutokauppa' );
				break;
			}
		}
		if ( HK_Settings::get( 'page_terms' ) && '' === self::old( 'agree' ) ) {
			self::$errors[] = __( 'You must accept the terms of use.', 'huutokauppa' );
		}
		if ( self::$errors ) {
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::$errors[] = $user_id->get_error_message();
			return;
		}
		foreach ( HK_Users::FIELDS as $f ) {
			update_user_meta( $user_id, 'hk_' . $f, sanitize_text_field( self::old( $f ) ) );
		}
		update_user_meta( $user_id, 'hk_newsletter', '' === self::old( 'newsletter' ) ? 0 : 1 );
		$locale = determine_locale(); // Send e-mails in the language the user registered in.
		if ( $locale !== get_locale() ) {
			update_user_meta( $user_id, 'locale', $locale );
		}

		if ( HK_Settings::get( 'require_activation' ) ) {
			$url = HK_Users::start_activation( $user_id );
			HK_Mailer::send( 'activation', $user_id, array( 'url' => $url ) );
			self::set_flash( 'success', __( 'Thank you for registering! We sent you an e-mail. Open the link in it to activate your account.', 'huutokauppa' ) );
		} else {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
			self::set_flash( 'success', __( 'Thank you for registering! You can now bid.', 'huutokauppa' ) );
		}
		do_action( 'hk_user_registered', $user_id );
		self::redirect( get_permalink() );
	}

	public static function sc_register(): string {
		HK_Frontend::enqueue();
		$flash = self::take_flash();
		if ( $flash ) {
			return hk_notice( $flash['message'], $flash['type'] );
		}
		if ( is_user_logged_in() ) {
			return hk_notice( __( 'You are already logged in.', 'huutokauppa' ) );
		}
		$l    = HK_Users::field_labels();
		$html = self::errors_html() . self::form_open( 'register' );
		$html .= '<fieldset><legend>' . esc_html__( 'Account', 'huutokauppa' ) . '</legend>';
		$html .= self::field( 'name', __( 'Name or company name', 'huutokauppa' ), array( 'required' => true, 'attrs' => 'autocomplete="name"' ) );
		$html .= self::field( 'email', __( 'E-mail', 'huutokauppa' ), array( 'type' => 'email', 'required' => true, 'attrs' => 'autocomplete="email"', 'help' => __( 'You log in with your e-mail address.', 'huutokauppa' ) ) );
		$html .= self::field( 'password', __( 'Password', 'huutokauppa' ), array( 'type' => 'password', 'required' => true, 'value' => '', 'attrs' => 'autocomplete="new-password" minlength="8"', 'help' => __( 'At least 8 characters.', 'huutokauppa' ) ) );
		$html .= self::field( 'password2', __( 'Password again', 'huutokauppa' ), array( 'type' => 'password', 'required' => true, 'value' => '', 'attrs' => 'autocomplete="new-password" minlength="8"' ) );
		$html .= '</fieldset><fieldset><legend>' . esc_html__( 'Contact details', 'huutokauppa' ) . '</legend>';
		$html .= self::field( 'phone', $l['phone'], array( 'type' => 'tel', 'required' => true, 'attrs' => 'autocomplete="tel"' ) );
		$html .= self::field( 'address', $l['address'], array( 'required' => true, 'attrs' => 'autocomplete="street-address"' ) );
		$html .= self::field( 'postcode', $l['postcode'], array( 'required' => true, 'attrs' => 'autocomplete="postal-code"' ) );
		$html .= self::field( 'city', $l['city'], array( 'required' => true, 'attrs' => 'autocomplete="address-level2"' ) );
		$html .= self::field( 'business_id', $l['business_id'], array( 'help' => __( 'Only for companies.', 'huutokauppa' ) ) );
		$html .= '</fieldset>';
		$html .= '<p class="hk-field hk-check"><label><input type="checkbox" name="hk_f_newsletter" value="1"' . checked( '' !== self::old( 'newsletter' ), true, false ) . '> ' . esc_html__( 'Send me the newsletter', 'huutokauppa' ) . '</label></p>';
		if ( HK_Settings::get( 'page_terms' ) ) {
			$html .= '<p class="hk-field hk-check"><label><input type="checkbox" name="hk_f_agree" value="1" required> '
				. sprintf(
					/* translators: %s: link to the terms of use */
					esc_html__( 'I accept the %s', 'huutokauppa' ),
					'<a href="' . esc_url( HK_Settings::page_url( 'terms' ) ) . '" target="_blank">' . esc_html__( 'terms of use', 'huutokauppa' ) . '</a>'
				) . '</label></p>';
		}
		$html .= '<p class="hk-hp" aria-hidden="true"><label>Website <input type="text" name="hk_f_hk_website" tabindex="-1" autocomplete="off"></label></p>';
		$html .= '<p><button type="submit" class="hk-button">' . esc_html__( 'Register', 'huutokauppa' ) . '</button></p></form>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Own details
	 * ---------------------------------------------------------------- */

	private static function process_profile(): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		$name  = sanitize_text_field( self::old( 'name' ) );
		$email = sanitize_email( self::old( 'email' ) );
		$pass  = self::old( 'password' );
		if ( '' === $name ) {
			self::$errors[] = __( 'Enter your name.', 'huutokauppa' );
		}
		if ( ! is_email( $email ) ) {
			self::$errors[] = __( 'Enter a valid e-mail address.', 'huutokauppa' );
		} elseif ( ( $other = email_exists( $email ) ) && (int) $other !== $uid ) {
			self::$errors[] = __( 'Another account already uses this e-mail address.', 'huutokauppa' );
		}
		if ( '' !== $pass ) {
			if ( strlen( $pass ) < 8 ) {
				self::$errors[] = __( 'The password must be at least 8 characters.', 'huutokauppa' );
			} elseif ( $pass !== self::old( 'password2' ) ) {
				self::$errors[] = __( 'The passwords do not match.', 'huutokauppa' );
			}
		}
		if ( self::$errors ) {
			return;
		}
		$data = array(
			'ID'           => $uid,
			'display_name' => $name,
			'first_name'   => $name,
			'user_email'   => $email,
		);
		if ( '' !== $pass ) {
			$data['user_pass'] = $pass;
		}
		$r = wp_update_user( $data );
		if ( is_wp_error( $r ) ) {
			self::$errors[] = $r->get_error_message();
			return;
		}
		foreach ( HK_Users::FIELDS as $f ) {
			update_user_meta( $uid, 'hk_' . $f, sanitize_text_field( self::old( $f ) ) );
		}
		update_user_meta( $uid, 'hk_newsletter', '' === self::old( 'newsletter' ) ? 0 : 1 );
		if ( '' !== $pass ) {
			wp_set_auth_cookie( $uid );
		}
		self::set_flash( 'success', __( 'Your details have been saved.', 'huutokauppa' ) );
		self::redirect( get_permalink() );
	}

	public static function sc_profile(): string {
		HK_Frontend::enqueue();
		if ( ! is_user_logged_in() ) {
			return HK_Frontend::login_box( __( 'Log in to edit your details.', 'huutokauppa' ) );
		}
		$u     = wp_get_current_user();
		$l     = HK_Users::field_labels();
		$flash = self::take_flash();
		$val   = static fn( $k, $current ) => self::$old ? self::old( $k ) : $current;
		$html  = ( $flash ? hk_notice( $flash['message'], $flash['type'] ) : '' ) . self::errors_html() . self::form_open( 'profile' );
		$html .= self::field( 'name', __( 'Name or company name', 'huutokauppa' ), array( 'required' => true, 'value' => $val( 'name', $u->display_name ) ) );
		$html .= self::field( 'email', __( 'E-mail', 'huutokauppa' ), array( 'type' => 'email', 'required' => true, 'value' => $val( 'email', $u->user_email ) ) );
		foreach ( HK_Users::FIELDS as $f ) {
			$html .= self::field( $f, $l[ $f ], array( 'value' => $val( $f, HK_Users::get( $u->ID, $f ) ) ) );
		}
		$news = self::$old ? '' !== self::old( 'newsletter' ) : (bool) get_user_meta( $u->ID, 'hk_newsletter', true );
		$html .= '<p class="hk-field hk-check"><label><input type="checkbox" name="hk_f_newsletter" value="1"' . checked( $news, true, false ) . '> ' . esc_html__( 'Send me the newsletter', 'huutokauppa' ) . '</label></p>';
		$html .= '<fieldset><legend>' . esc_html__( 'Change password', 'huutokauppa' ) . '</legend>';
		$html .= self::field( 'password', __( 'New password', 'huutokauppa' ), array( 'type' => 'password', 'value' => '', 'attrs' => 'autocomplete="new-password" minlength="8"', 'help' => __( 'Leave empty to keep the current password.', 'huutokauppa' ) ) );
		$html .= self::field( 'password2', __( 'New password again', 'huutokauppa' ), array( 'type' => 'password', 'value' => '', 'attrs' => 'autocomplete="new-password"' ) );
		$html .= '</fieldset><p><button type="submit" class="hk-button">' . esc_html__( 'Save', 'huutokauppa' ) . '</button></p></form>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Seller submission
	 * ---------------------------------------------------------------- */

	private static function can_submit( int $uid ) {
		if ( ! HK_Settings::get( 'allow_submissions' ) ) {
			return new WP_Error( 'off', __( 'Item submissions are closed.', 'huutokauppa' ) );
		}
		if ( ! $uid ) {
			return new WP_Error( 'login', __( 'Log in to submit an item.', 'huutokauppa' ) );
		}
		if ( HK_Users::is_blocked( $uid ) ) {
			return new WP_Error( 'blocked', __( 'Your account has been blocked.', 'huutokauppa' ) );
		}
		if ( ! HK_Users::is_activated( $uid ) ) {
			return new WP_Error( 'inactive', __( 'Confirm your e-mail address before submitting items.', 'huutokauppa' ) );
		}
		return true;
	}

	private static function process_submit(): void {
		$uid = get_current_user_id();
		if ( true !== self::can_submit( $uid ) ) {
			return;
		}
		$title       = sanitize_text_field( self::old( 'title' ) );
		$description = sanitize_textarea_field( self::old( 'description' ) );
		$price       = hk_parse_amount( self::old( 'start_price' ) );
		$raise       = '' === trim( self::old( 'min_raise' ) ) ? (float) HK_Settings::get( 'default_min_raise' ) : hk_parse_amount( self::old( 'min_raise' ) );
		$end         = hk_local_input_to_ts( self::old( 'end' ) );
		$vat         = in_array( self::old( 'vat' ), array( 'plus', 'margin' ), true ) ? self::old( 'vat' ) : 'margin';

		if ( '' === $title ) {
			self::$errors[] = __( 'Enter a title.', 'huutokauppa' );
		}
		if ( '' === $description ) {
			self::$errors[] = __( 'Enter a description.', 'huutokauppa' );
		}
		if ( null === $price ) {
			self::$errors[] = __( 'Enter the start price as a number.', 'huutokauppa' );
		}
		if ( null === $raise ) {
			self::$errors[] = __( 'Enter the minimum raise as a number.', 'huutokauppa' );
		}
		if ( $end && $end < hk_now() + HOUR_IN_SECONDS ) {
			self::$errors[] = __( 'The requested end time must be in the future.', 'huutokauppa' );
		}
		$seller = array();
		foreach ( HK_Item::SELLER_FIELDS as $f ) {
			$seller[ $f ] = 'email' === $f ? sanitize_email( self::old( 'seller_' . $f ) ) : sanitize_text_field( self::old( 'seller_' . $f ) );
		}
		if ( '' === $seller['name'] || '' === $seller['phone'] || ! is_email( $seller['email'] ) ) {
			self::$errors[] = __( 'Fill in the seller\'s name, phone and e-mail.', 'huutokauppa' );
		}

		$files = self::uploaded_images();
		if ( is_wp_error( $files ) ) {
			self::$errors[] = $files->get_error_message();
		}
		if ( self::$errors ) {
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => HK_Post_Types::ITEM,
				'post_status'  => 'pending',
				'post_title'   => $title,
				'post_content' => $description,
				'post_author'  => $uid,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			self::$errors[] = $post_id->get_error_message();
			return;
		}

		$gallery = array();
		if ( $files ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			foreach ( $files as $n => $file ) {
				$_FILES[ 'hk_image_' . $n ] = $file;
				$att = media_handle_upload( 'hk_image_' . $n, $post_id, array(), array( 'test_form' => false ) );
				if ( ! is_wp_error( $att ) ) {
					$gallery[] = (int) $att;
				}
			}
		}

		HK_Item::save_fields(
			$post_id,
			array(
				'start_price' => $price,
				'min_raise'   => $raise,
				'end'         => $end ?: 0,
				'vat'         => $vat,
				'location'    => sanitize_text_field( self::old( 'location' ) ),
				'delivery'    => sanitize_textarea_field( self::old( 'delivery' ) ),
				'contact'     => sanitize_textarea_field( self::old( 'contact' ) ),
				'seller'      => $seller,
				'seller_user' => $uid,
				'gallery'     => $gallery,
				'requested'   => array(
					'start_price' => $price,
					'min_raise'   => $raise,
					'end'         => $end ?: 0,
				),
			)
		);

		if ( HK_Settings::get( 'notify_email' ) ) {
			HK_Mailer::send( 'submitted', HK_Settings::get( 'notify_email' ), array( 'item_id' => $post_id, 'seller' => $seller['name'] ) );
		}
		do_action( 'hk_item_submitted', $post_id, $uid );
		self::set_flash( 'success', __( 'Thank you! Your item has been sent for approval. We will e-mail you when it is in the auction.', 'huutokauppa' ) );
		self::redirect( get_permalink() );
	}

	/** Validated $_FILES entries for the image field, or WP_Error. */
	private static function uploaded_images() {
		if ( empty( $_FILES['images'] ) || ! is_array( $_FILES['images']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return array();
		}
		$raw   = $_FILES['images']; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$max   = (int) HK_Settings::get( 'max_images' );
		$limit = wp_max_upload_size();
		$ok    = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		$out   = array();
		foreach ( $raw['name'] as $i => $name ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== (int) $raw['error'][ $i ] || $raw['size'][ $i ] > $limit ) {
				/* translators: %s: file name */
				return new WP_Error( 'upload', sprintf( __( 'The image %s could not be uploaded. It may be too large.', 'huutokauppa' ), sanitize_file_name( $name ) ) );
			}
			$check = wp_check_filetype_and_ext( $raw['tmp_name'][ $i ], $name );
			if ( ! in_array( $check['type'], $ok, true ) ) {
				/* translators: %s: file name */
				return new WP_Error( 'type', sprintf( __( '%s is not a JPEG, PNG, WebP or GIF image.', 'huutokauppa' ), sanitize_file_name( $name ) ) );
			}
			$out[] = array(
				'name'     => $name,
				'type'     => $check['type'],
				'tmp_name' => $raw['tmp_name'][ $i ],
				'error'    => 0,
				'size'     => $raw['size'][ $i ],
			);
		}
		if ( count( $out ) > $max ) {
			/* translators: %d: maximum number of images */
			return new WP_Error( 'count', sprintf( __( 'You can add at most %d images.', 'huutokauppa' ), $max ) );
		}
		return $out;
	}

	public static function sc_submit(): string {
		HK_Frontend::enqueue();
		$uid   = get_current_user_id();
		$flash = self::take_flash();
		$can   = self::can_submit( $uid );
		if ( is_wp_error( $can ) ) {
			$msg = $flash ? hk_notice( $flash['message'], $flash['type'] ) : '';
			return $msg . ( 'login' === $can->get_error_code() ? HK_Frontend::login_box( $can->get_error_message() ) : hk_notice( $can->get_error_message(), 'warning' ) );
		}
		$u   = wp_get_current_user();
		$pre = array(
			'seller_name'        => $u->display_name,
			'seller_email'       => $u->user_email,
			'seller_phone'       => HK_Users::get( $uid, 'phone' ),
			'seller_address'     => HK_Users::get( $uid, 'address' ),
			'seller_postcode'    => HK_Users::get( $uid, 'postcode' ),
			'seller_city'        => HK_Users::get( $uid, 'city' ),
			'seller_business_id' => HK_Users::get( $uid, 'business_id' ),
		);
		$v   = static fn( $k ) => self::$old ? self::old( $k ) : ( $pre[ $k ] ?? '' );
		$cur = esc_html( HK_Settings::get( 'currency_symbol' ) );
		$sl  = HK_Users::field_labels();

		$html  = ( $flash ? hk_notice( $flash['message'], $flash['type'] ) : '' ) . self::errors_html() . self::form_open( 'submit', true );
		$html .= '<fieldset><legend>' . esc_html__( 'Item', 'huutokauppa' ) . '</legend>';
		$html .= self::field( 'title', __( 'Title', 'huutokauppa' ), array( 'required' => true, 'attrs' => 'maxlength="200"' ) );
		$html .= self::field( 'description', __( 'Description', 'huutokauppa' ), array( 'type' => 'textarea', 'required' => true ) );
		$html .= '<p class="hk-field"><label for="hk-f-images">' . esc_html__( 'Images', 'huutokauppa' ) . '</label><input type="file" id="hk-f-images" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>'
			/* translators: %d: maximum number of images */
			. '<small class="hk-hint">' . esc_html( sprintf( __( 'Up to %d images. The first one is the main image.', 'huutokauppa' ), (int) HK_Settings::get( 'max_images' ) ) ) . '</small></p>';
		$html .= self::field( 'location', __( 'Location', 'huutokauppa' ) );
		$html .= self::field( 'delivery', __( 'Delivery', 'huutokauppa' ), array( 'type' => 'textarea', 'help' => __( 'For example: pick-up only, or shipping at the buyer\'s cost.', 'huutokauppa' ) ) );
		$html .= '</fieldset><fieldset><legend>' . esc_html__( 'Auction', 'huutokauppa' ) . '</legend>';
		/* translators: %s: currency symbol */
		$html .= self::field( 'start_price', sprintf( __( 'Start price (%s)', 'huutokauppa' ), $cur ), array( 'required' => true, 'attrs' => 'inputmode="decimal"' ) );
		/* translators: %s: currency symbol */
		$html .= self::field( 'min_raise', sprintf( __( 'Minimum raise (%s)', 'huutokauppa' ), $cur ), array( 'attrs' => 'inputmode="decimal" placeholder="' . esc_attr( number_format_i18n( (float) HK_Settings::get( 'default_min_raise' ) ) ) . '"' ) );
		$html .= self::field( 'end', __( 'Requested end time', 'huutokauppa' ), array( 'type' => 'datetime-local', 'help' => __( 'Optional. The administrator sets the final schedule.', 'huutokauppa' ) ) );
		$vat   = self::old( 'vat', 'margin' );
		$html .= '<p class="hk-field"><span class="hk-label">' . esc_html__( 'VAT', 'huutokauppa' ) . '</span>'
			. '<label class="hk-radio"><input type="radio" name="hk_f_vat" value="margin"' . checked( $vat, 'margin', false ) . '> ' . esc_html__( 'VAT 0 % (private seller or margin scheme)', 'huutokauppa' ) . '</label>'
			/* translators: %s: VAT rate */
			. '<label class="hk-radio"><input type="radio" name="hk_f_vat" value="plus"' . checked( $vat, 'plus', false ) . '> ' . esc_html( sprintf( __( 'VAT %s %% is added to the price', 'huutokauppa' ), number_format_i18n( (float) HK_Settings::get( 'vat_rate' ), 1 ) ) ) . '</label></p>';
		$html .= '</fieldset><fieldset><legend>' . esc_html__( 'Seller', 'huutokauppa' ) . '</legend>';
		$html .= self::field( 'seller_name', __( 'Name or company name', 'huutokauppa' ), array( 'required' => true, 'value' => $v( 'seller_name' ) ) );
		$html .= self::field( 'seller_business_id', $sl['business_id'], array( 'value' => $v( 'seller_business_id' ) ) );
		$html .= self::field( 'seller_address', $sl['address'], array( 'value' => $v( 'seller_address' ) ) );
		$html .= self::field( 'seller_postcode', $sl['postcode'], array( 'value' => $v( 'seller_postcode' ) ) );
		$html .= self::field( 'seller_city', $sl['city'], array( 'value' => $v( 'seller_city' ) ) );
		$html .= self::field( 'seller_phone', $sl['phone'], array( 'type' => 'tel', 'required' => true, 'value' => $v( 'seller_phone' ) ) );
		$html .= self::field( 'seller_email', __( 'E-mail', 'huutokauppa' ), array( 'type' => 'email', 'required' => true, 'value' => $v( 'seller_email' ) ) );
		$html .= self::field( 'contact', __( 'Contact person shown on the item page', 'huutokauppa' ), array( 'type' => 'textarea', 'help' => __( 'Optional. Leave empty to show no contact details.', 'huutokauppa' ) ) );
		$html .= '</fieldset><p><button type="submit" class="hk-button">' . esc_html__( 'Send for approval', 'huutokauppa' ) . '</button></p></form>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Publishing a submitted item
	 * ---------------------------------------------------------------- */

	/** Fill in a schedule if missing and tell the seller their item is live. */
	public static function on_publish( string $new, string $old, WP_Post $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || HK_Post_Types::ITEM !== $post->post_type ) {
			return;
		}
		$id = $post->ID;
		// Admin screen saves meta after the status change; run once that is done.
		add_action(
			'shutdown',
			static function () use ( $id, $old ) {
				if ( 'publish' !== get_post_status( $id ) ) {
					return;
				}
				if ( ! HK_Item::start( $id ) ) {
					update_post_meta( $id, '_hk_start', hk_now() );
				}
				if ( ! HK_Item::end( $id ) ) {
					update_post_meta( $id, '_hk_end', max( hk_now(), HK_Item::start( $id ) ) + DAY_IN_SECONDS * (int) HK_Settings::get( 'default_duration' ) );
				}
				HK_Bidding::refresh_cache( $id );
				$seller = HK_Item::seller_user( $id );
				if ( 'pending' === $old && $seller && ! get_post_meta( $id, '_hk_approved_sent', true ) ) {
					update_post_meta( $id, '_hk_approved_sent', 1 );
					HK_Mailer::send( 'approved', $seller, array( 'item_id' => $id ) );
				}
			}
		);
	}
}
