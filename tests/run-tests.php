<?php
/**
 * Integration tests for the bidding engine and auction lifecycle.
 *
 * Run against a disposable WordPress site with the plugin active:
 *     wp eval-file wp-content/plugins/huutokauppa/tests/run-tests.php
 *
 * The script creates its own users and items and deletes them afterwards.
 * Exit code is 1 when any assertion fails.
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/run-tests.php\n" );

$GLOBALS['hk_test_failures'] = 0;
$GLOBALS['hk_test_count']    = 0;
$GLOBALS['hk_test_clock']    = time();
$GLOBALS['hk_test_mail']     = array();

add_filter( 'hk_now', static fn() => $GLOBALS['hk_test_clock'] );
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['hk_test_mail'][] = $atts;
	return true;
}, 10, 2 );

function t_ok( bool $cond, string $msg ): void {
	$GLOBALS['hk_test_count']++;
	if ( $cond ) {
		echo "  ok   $msg\n";
	} else {
		$GLOBALS['hk_test_failures']++;
		echo "  FAIL $msg\n";
	}
}

function t_eq( $expected, $actual, string $msg ): void {
	t_ok( $expected == $actual, $msg . ( $expected == $actual ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

$created_users = array();
$created_items = array();

function t_user( string $name, array &$created ): int {
	$id = wp_insert_user(
		array(
			'user_login' => 'hktest_' . $name . '_' . wp_generate_password( 4, false ),
			'user_pass'  => wp_generate_password(),
			'user_email' => 'hktest_' . $name . '_' . wp_generate_password( 4, false ) . '@example.com',
			'role'       => 'subscriber',
			'display_name' => ucfirst( $name ),
		)
	);
	$created[] = $id;
	return $id;
}

function t_item( array $f, array &$created ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => HK_Post_Types::ITEM,
			'post_status' => 'publish',
			'post_title'  => 'Test item ' . wp_generate_password( 4, false ),
		)
	);
	HK_Item::save_fields(
		$id,
		array_merge(
			array(
				'start'       => $GLOBALS['hk_test_clock'] - 3600,
				'end'         => $GLOBALS['hk_test_clock'] + DAY_IN_SECONDS,
				'start_price' => 100,
				'min_raise'   => 10,
				'vat'         => 'margin',
			),
			$f
		)
	);
	$created[] = $id;
	return $id;
}

function t_high( int $item ): array {
	$h = HK_Bidding::highest_bid( $item );
	return $h ? array( (int) $h->user_id, (float) $h->amount ) : array( 0, 0.0 );
}

HK_Settings::update( array( 'extend_seconds' => 300, 'auto_accept' => 0 ) );

$a      = t_user( 'alice', $created_users );
$b      = t_user( 'bob', $created_users );
$c      = t_user( 'carol', $created_users );
$seller = t_user( 'seller', $created_users );

echo "Amount parsing\n";
t_eq( 1234.5, hk_parse_amount( '1 234,50' ), 'space and comma' );
t_eq( 1234.5, hk_parse_amount( '1.234,50 €' ), 'dot thousands, comma decimals, euro sign' );
t_eq( 150.0, hk_parse_amount( '150' ), 'integer' );
t_eq( null, hk_parse_amount( 'abc' ), 'text rejected' );
t_eq( null, hk_parse_amount( '-5' ), 'negative rejected' );
t_eq( null, hk_parse_amount( '0' ), 'zero rejected' );

echo "Manual bids\n";
$i = t_item( array( 'seller_user' => $seller ), $created_items );
t_eq( 110.0, HK_Bidding::next_minimum( $i ), 'first bid minimum is start price + raise' );
$r = HK_Bidding::place_bid( $i, $a, 105 );
t_ok( ! $r['ok'] && 'hk_too_low' === $r['code'], 'bid below minimum rejected' );
$r = HK_Bidding::place_bid( $i, $a, 110 );
t_ok( $r['ok'] && 'hk_leading' === $r['code'], 'valid first bid accepted' );
t_eq( array( $a, 110.0 ), t_high( $i ), 'alice leads at 110' );
t_eq( 120.0, HK_Bidding::next_minimum( $i ), 'next minimum is highest + raise' );
$r = HK_Bidding::place_bid( $i, $b, 119.99 );
t_ok( ! $r['ok'], 'bid one cent short rejected' );
$r = HK_Bidding::place_bid( $i, $b, 150 );
t_ok( $r['ok'], 'higher bid accepted' );
t_eq( array( $b, 150.0 ), t_high( $i ), 'bob leads at 150' );
t_eq( 150.0, (float) get_post_meta( $i, '_hk_price', true ), 'price cache updated' );
t_eq( 2, (int) get_post_meta( $i, '_hk_bid_count', true ), 'bid count cache updated' );

echo "Who may bid\n";
$r = HK_Bidding::place_bid( $i, $seller, 500 );
t_ok( ! $r['ok'] && 'hk_own' === $r['code'], 'seller cannot bid on own item' );
HK_Users::set_blocked( $c, true );
$r = HK_Bidding::place_bid( $i, $c, 500 );
t_ok( ! $r['ok'] && 'hk_blocked' === $r['code'], 'blocked user cannot bid' );
HK_Users::set_blocked( $c, false );
HK_Users::start_activation( $c );
$r = HK_Bidding::place_bid( $i, $c, 500 );
t_ok( ! $r['ok'] && 'hk_inactive' === $r['code'], 'unactivated user cannot bid' );
delete_user_meta( $c, 'hk_activation' );
$r = HK_Bidding::place_bid( $i, 0, 500 );
t_ok( ! $r['ok'] && 'hk_login' === $r['code'], 'guest cannot bid' );

$up = t_item( array( 'start' => $GLOBALS['hk_test_clock'] + 3600 ), $created_items );
t_eq( HK_Item::STATE_UPCOMING, HK_Item::state( $up ), 'future start is upcoming' );
$r = HK_Bidding::place_bid( $up, $a, 500 );
t_ok( ! $r['ok'] && 'hk_closed' === $r['code'], 'cannot bid before start' );
$ended = t_item( array( 'end' => $GLOBALS['hk_test_clock'] - 1 ), $created_items );
$r = HK_Bidding::place_bid( $ended, $a, 500 );
t_ok( ! $r['ok'] && 'hk_closed' === $r['code'], 'cannot bid after end' );
$draft = t_item( array(), $created_items );
wp_update_post( array( 'ID' => $draft, 'post_status' => 'pending' ) );
t_eq( HK_Item::STATE_DRAFT, HK_Item::state( $draft ), 'pending item is not live' );

echo "Automatic bids\n";
$i = t_item( array(), $created_items );
$r = HK_Bidding::set_autobid( $i, $a, 500 );
t_ok( $r['ok'] && 'hk_leading' === $r['code'], 'autobid on empty item leads' );
t_eq( array( $a, 110.0 ), t_high( $i ), 'autobid places the minimum bid, not the maximum' );
$r = HK_Bidding::place_bid( $i, $b, 200 );
t_ok( $r['ok'] && 'hk_outbid' === $r['code'], 'manual bid below a maximum is outbid' );
t_eq( array( $a, 210.0 ), t_high( $i ), 'autobid answers with one raise' );
$r = HK_Bidding::set_autobid( $i, $a, 800 );
t_eq( array( $a, 210.0 ), t_high( $i ), 'leader raising own maximum does not raise the price' );
$r = HK_Bidding::set_autobid( $i, $c, 400 );
t_ok( $r['ok'] && 'hk_outbid' === $r['code'], 'weaker autobid is told it is outbid' );
t_eq( array( $a, 410.0 ), t_high( $i ), 'stronger autobid ends one raise above weaker maximum' );
$bids = HK_Bidding::bids( $i, 2 );
t_eq( 400.0, (float) $bids[1]->amount, 'weaker maximum is recorded in history' );
$r = HK_Bidding::place_bid( $i, $b, 900 );
t_ok( $r['ok'] && 'hk_leading' === $r['code'], 'manual bid above every maximum leads' );
t_eq( array( $b, 900.0 ), t_high( $i ), 'bob leads at 900' );

$i = t_item( array(), $created_items );
HK_Bidding::set_autobid( $i, $a, 500 );
$GLOBALS['hk_test_clock'] += 5;
HK_Bidding::set_autobid( $i, $c, 500 );
t_eq( array( $a, 500.0 ), t_high( $i ), 'equal maximums: the earlier one wins at the maximum' );

$i = t_item( array(), $created_items );
HK_Bidding::set_autobid( $i, $a, 500 );
HK_Bidding::set_autobid( $i, $c, 495 );
t_eq( array( $a, 500.0 ), t_high( $i ), 'winning price is capped at the maximum' );

$i = t_item( array(), $created_items );
$r = HK_Bidding::set_autobid( $i, $a, 105 );
t_ok( ! $r['ok'] && 'hk_too_low' === $r['code'], 'maximum below next minimum rejected' );
HK_Bidding::place_bid( $i, $b, 110 );
HK_Bidding::set_autobid( $i, $a, 1000 );
HK_Bidding::remove_autobid( $i, $a );
HK_Bidding::place_bid( $i, $b, 300 );
t_eq( array( $b, 300.0 ), t_high( $i ), 'removed autobid no longer answers' );

echo "Anti-sniping\n";
$i = t_item( array( 'end' => $GLOBALS['hk_test_clock'] + 60 ), $created_items );
HK_Bidding::place_bid( $i, $a, 110 );
t_eq( $GLOBALS['hk_test_clock'] + 300, HK_Item::end( $i ), 'late bid extends end to five minutes after the bid' );
$i = t_item( array( 'end' => $GLOBALS['hk_test_clock'] + 3600 ), $created_items );
HK_Bidding::place_bid( $i, $a, 110 );
t_eq( $GLOBALS['hk_test_clock'] + 3600, HK_Item::end( $i ), 'early bid does not change end time' );

echo "Ending and winner\n";
$sold   = t_item( array( 'end' => $GLOBALS['hk_test_clock'] + 3600 ), $created_items );
$unsold = t_item( array( 'end' => $GLOBALS['hk_test_clock'] + 3600 ), $created_items );
$nobids = t_item( array( 'end' => $GLOBALS['hk_test_clock'] + 3600 ), $created_items );
HK_Bidding::place_bid( $sold, $a, 110 );
HK_Bidding::place_bid( $sold, $b, 200 );
HK_Bidding::place_bid( $unsold, $c, 110 );
t_ok( ! HK_Winner::accept( $sold ), 'cannot accept while live' );
$GLOBALS['hk_test_clock'] += 3601;
$GLOBALS['hk_test_mail'] = array();
$report = HK_Cron::process_ended();
t_ok( in_array( $sold, $report, true ) && in_array( $unsold, $report, true ), 'cron reports ended auctions with bids' );
t_eq( 'no_bids', HK_Item::result( $nobids ), 'cron closes auctions without bids' );
t_eq( HK_Item::STATE_UNSOLD, HK_Item::state( $nobids ), 'no-bid auction is unsold' );
t_ok( count( $GLOBALS['hk_test_mail'] ) >= 1, 'administrator is notified' );
t_eq( array(), array_intersect( array( $sold, $unsold ), HK_Cron::process_ended() ), 'auctions are reported only once' );

$GLOBALS['hk_test_mail'] = array();
t_ok( HK_Winner::accept( $sold ), 'accept ended auction' );
t_eq( HK_Item::STATE_SOLD, HK_Item::state( $sold ), 'accepted auction is sold' );
t_eq( $b, HK_Item::winner( $sold ), 'winner is the highest bidder' );
t_eq( get_userdata( $b )->user_email, $GLOBALS['hk_test_mail'][0]['to'] ?? '', 'winner gets an e-mail' );
t_ok( HK_Winner::reject( $unsold ), 'reject ended auction' );
t_eq( HK_Item::STATE_UNSOLD, HK_Item::state( $unsold ), 'rejected auction is unsold' );
t_eq( get_userdata( $c )->user_email, $GLOBALS['hk_test_mail'][1]['to'] ?? '', 'highest bidder of rejected auction gets an e-mail' );
HK_Winner::reset( $unsold );
t_eq( HK_Item::STATE_ENDED, HK_Item::state( $unsold ), 'decision can be undone' );

HK_Settings::update( array( 'auto_accept' => 1 ) );
$auto = t_item( array( 'end' => $GLOBALS['hk_test_clock'] + 10 ), $created_items );
HK_Bidding::place_bid( $auto, $a, 110 );
$GLOBALS['hk_test_clock'] += 400;
HK_Cron::process_ended();
t_eq( HK_Item::STATE_SOLD, HK_Item::state( $auto ), 'auto-accept setting accepts at end' );
HK_Settings::update( array( 'auto_accept' => 0 ) );

echo "Duplicate and watch list\n";
$copy = HK_Item::duplicate( $sold );
$created_items[] = $copy;
t_eq( 'draft', get_post_status( $copy ), 'duplicate is a draft' );
t_eq( '', HK_Item::result( $copy ), 'duplicate has no result' );
t_eq( 0, HK_Bidding::bid_count( $copy ), 'duplicate has no bids' );
t_eq( 100.0, HK_Item::start_price( $copy ), 'duplicate keeps auction fields' );
t_ok( HK_Users::toggle_watch( $sold, $a ), 'watch on' );
t_ok( HK_Users::is_watching( $sold, $a ), 'is watching' );
t_ok( ! HK_Users::toggle_watch( $sold, $a ), 'watch off' );

echo "Failed-login lockout\n";
HK_Settings::update( array( 'max_failed_logins' => 3 ) );
$login = get_userdata( $a )->user_login;
for ( $n = 0; $n < 3; $n++ ) {
	do_action( 'wp_login_failed', $login );
}
$res = wp_authenticate( $login, 'wrong' );
t_ok( is_wp_error( $res ) && 'hk_locked' === $res->get_error_code(), 'account locked after too many failures' );
HK_Settings::update( array( 'max_failed_logins' => 5 ) );

// Clean up.
global $wpdb;
foreach ( $created_items as $id ) {
	$wpdb->delete( HK_Install::table( 'bids' ), array( 'item_id' => $id ) );
	$wpdb->delete( HK_Install::table( 'autobids' ), array( 'item_id' => $id ) );
	$wpdb->delete( HK_Install::table( 'watch' ), array( 'item_id' => $id ) );
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $created_users as $id ) {
	$wpdb->delete( HK_Install::table( 'login_attempts' ), array( 'user_id' => $id ) );
	wp_delete_user( $id );
}

printf( "\n%d tests, %d failed\n", $GLOBALS['hk_test_count'], $GLOBALS['hk_test_failures'] );
if ( $GLOBALS['hk_test_failures'] ) {
	exit( 1 );
}
