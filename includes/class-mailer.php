<?php
/**
 * E-mail notifications. Messages are plain text and sent in the recipient's locale.
 * Every message can be changed with the "hk_mail_{type}" filter.
 */

defined( 'ABSPATH' ) || exit;

class HK_Mailer {

	/**
	 * @param string   $type    Message type (activation, won, not_sold, submitted, approved, ended).
	 * @param int|string $to    User id or e-mail address.
	 * @param array    $args    Values used in the message.
	 */
	public static function send( string $type, $to, array $args = array() ): bool {
		$user = is_numeric( $to ) ? get_userdata( (int) $to ) : null;
		$addr = $user ? $user->user_email : (string) $to;
		if ( ! is_email( $addr ) ) {
			return false;
		}

		$locale_user = $user ? $user->ID : (int) ( $args['user_id'] ?? 0 );
		$switched    = $locale_user ? switch_to_user_locale( $locale_user ) : false;
		[ $subject, $body ] = self::compose( $type, $args, $user );
		if ( $switched ) {
			restore_previous_locale();
		}

		$mail = apply_filters(
			'hk_mail_' . $type,
			array(
				'to'      => $addr,
				'subject' => $subject,
				'body'    => $body,
				'headers' => array(),
			),
			$args,
			$user
		);
		if ( ! $mail || empty( $mail['subject'] ) ) {
			return false;
		}
		return wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] );
	}

	private static function compose( string $type, array $a, ?WP_User $user ): array {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		// Account e-mails do not repeat the user-entered name: it could be used to
		// put arbitrary text into mail sent to someone else's address.
		$hello = $user && $user->display_name && ! in_array( $type, array( 'activation', 'email_change' ), true )
			/* translators: %s: recipient name */
			? sprintf( __( 'Hello %s,', 'huutokauppa' ), $user->display_name )
			: __( 'Hello,', 'huutokauppa' );
		$title = isset( $a['item_id'] ) ? HK_Item::localized( (int) $a['item_id'], 'title' ) : '';
		$link  = isset( $a['item_id'] ) ? get_permalink( (int) $a['item_id'] ) : '';
		$price = isset( $a['amount'] ) ? hk_money( $a['amount'] ) : '';
		$sign  = "\n\n-- \n" . $site . "\n" . home_url( '/' );

		switch ( $type ) {
			case 'activation':
				return array(
					/* translators: %s: site name */
					sprintf( __( '[%s] Confirm your e-mail address', 'huutokauppa' ), $site ),
					$hello . "\n\n"
					/* translators: %s: site name */
					. sprintf( __( 'Thank you for registering at %s. Confirm your e-mail address by opening this link:', 'huutokauppa' ), $site )
					. "\n\n" . $a['url'] . "\n\n"
					. __( 'If you did not register, you can ignore this message.', 'huutokauppa' ) . $sign,
				);

			case 'email_change':
				return array(
					/* translators: %s: site name */
					sprintf( __( '[%s] Confirm your new e-mail address', 'huutokauppa' ), $site ),
					$hello . "\n\n"
					/* translators: %s: site name */
					. sprintf( __( 'Someone asked to use this e-mail address for an account at %s. To confirm the change, open this link within two days:', 'huutokauppa' ), $site )
					. "\n\n" . $a['url'] . "\n\n"
					. __( 'If you did not ask for this, ignore this message. The address will not be changed.', 'huutokauppa' ) . $sign,
				);

			case 'won':
				return array(
					/* translators: %s: item title */
					sprintf( __( 'You won the auction: %s', 'huutokauppa' ), $title ),
					$hello . "\n\n"
					/* translators: 1: item title, 2: winning bid */
					. sprintf( __( 'The auction for %1$s has ended and your bid of %2$s was the highest. Your bid has been accepted.', 'huutokauppa' ), $title, $price )
					. "\n\n" . __( 'We will contact you about payment and delivery within 24 hours.', 'huutokauppa' )
					. "\n\n" . $link . $sign,
				);

			case 'not_sold':
				return array(
					/* translators: %s: item title */
					sprintf( __( 'Auction ended: %s', 'huutokauppa' ), $title ),
					$hello . "\n\n"
					/* translators: 1: item title, 2: highest bid */
					. sprintf( __( 'The auction for %1$s has ended and your bid of %2$s was the highest.', 'huutokauppa' ), $title, $price )
					. "\n\n" . __( 'Unfortunately the bid did not reach the lowest price the seller accepts, so the item was not sold.', 'huutokauppa' )
					. "\n\n" . $link . $sign,
				);

			case 'submitted':
				return array(
					/* translators: %s: item title */
					sprintf( __( 'New item waiting for approval: %s', 'huutokauppa' ), $title ),
					/* translators: %s: seller name */
					sprintf( __( 'A new item has been submitted by %s.', 'huutokauppa' ), $a['seller'] ?? '' )
					. "\n\n" . admin_url( 'post.php?action=edit&post=' . (int) $a['item_id'] ) . $sign,
				);

			case 'approved':
				return array(
					/* translators: %s: item title */
					sprintf( __( 'Your item is now in the auction: %s', 'huutokauppa' ), $title ),
					$hello . "\n\n"
					/* translators: 1: item title, 2: end time */
					. sprintf( __( 'Your item %1$s has been approved. The auction ends %2$s.', 'huutokauppa' ), $title, hk_format_time( HK_Item::end( (int) $a['item_id'] ) ) )
					. "\n\n" . $link . $sign,
				);

			case 'ended':
				$lines = array();
				foreach ( (array) ( $a['items'] ?? array() ) as $id ) {
					$high    = HK_Bidding::highest_bid( (int) $id );
					$lines[] = '- ' . HK_Item::localized( (int) $id, 'title' ) . ': '
						. ( $high ? hk_money( $high->amount ) : __( 'no bids', 'huutokauppa' ) )
						. "\n  " . admin_url( 'post.php?action=edit&post=' . (int) $id );
				}
				return array(
					/* translators: %s: site name */
					sprintf( __( '[%s] Auctions waiting for a decision', 'huutokauppa' ), $site ),
					__( 'These auctions have ended. Accept or reject the highest bid:', 'huutokauppa' ) . "\n\n" . implode( "\n", $lines ) . $sign,
				);
		}
		return array( '', '' );
	}
}
