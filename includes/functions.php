<?php
/**
 * Small helpers shared across the plugin.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current UNIX time. Filterable so tests can move the clock.
 */
function hk_now(): int {
	return (int) apply_filters( 'hk_now', time() );
}

/**
 * Format a money amount for display, e.g. "1 250 €" or "12,50 €".
 */
function hk_money( $amount ): string {
	$amount   = (float) $amount;
	$decimals = ( abs( $amount - round( $amount ) ) < 0.005 ) ? 0 : 2;
	$currency = HK_Settings::get( 'currency_symbol' );
	return number_format_i18n( $amount, $decimals ) . "\u{00A0}" . $currency;
}

/**
 * Parse a user-entered amount. Accepts "1 234,50", "1234.5", "1.234,50".
 * Returns null when the input is not a positive number.
 */
function hk_parse_amount( $raw ): ?float {
	if ( is_int( $raw ) || is_float( $raw ) ) {
		return $raw > 0 ? round( (float) $raw, 2 ) : null;
	}
	$s = trim( (string) $raw );
	$s = preg_replace( '/[\s\x{00A0}€]/u', '', $s );
	if ( '' === $s ) {
		return null;
	}
	// "1.234,50" -> thousands dot + decimal comma.
	if ( str_contains( $s, ',' ) && str_contains( $s, '.' ) ) {
		$s = str_replace( '.', '', $s );
	}
	$s = str_replace( ',', '.', $s );
	if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $s ) ) {
		return null;
	}
	$v = round( (float) $s, 2 );
	return $v > 0 ? $v : null;
}

/**
 * Format a UNIX timestamp in the site time zone, with a localized weekday.
 */
function hk_format_time( int $ts, bool $weekday = true ): string {
	/* translators: date format for auction end times, see https://www.php.net/date */
	$format = $weekday ? __( 'l j.n.Y H:i:s', 'huutokauppa' ) : __( 'j.n.Y H:i:s', 'huutokauppa' );
	return wp_date( $format, $ts );
}

/**
 * Convert a "Y-m-d\TH:i" value from a datetime-local input (site time zone) to a timestamp.
 */
function hk_local_input_to_ts( string $value ): ?int {
	$value = trim( $value );
	if ( '' === $value ) {
		return null;
	}
	$dt = date_create_immutable( str_replace( 'T', ' ', $value ), wp_timezone() );
	return $dt ? $dt->getTimestamp() : null;
}

/**
 * Timestamp to a datetime-local input value in the site time zone.
 */
function hk_ts_to_local_input( ?int $ts ): string {
	return $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
}

/**
 * Short locale code used for per-language item content, e.g. "fi", "sv", "en".
 */
function hk_lang_code( ?string $locale = null ): string {
	$locale = $locale ?: determine_locale();
	return strtolower( substr( $locale, 0, 2 ) );
}

/**
 * Display name for a user that is safe to show publicly in bid history.
 * Bidders are shown anonymised: "Huutaja 3".
 */
function hk_bidder_alias( int $item_id, int $user_id ): string {
	static $cache = array();
	if ( ! isset( $cache[ $item_id ] ) ) {
		$cache[ $item_id ] = HK_Bidding::bidder_order( $item_id );
	}
	$n = array_search( $user_id, $cache[ $item_id ], true );
	/* translators: %d: anonymous bidder number */
	return sprintf( __( 'Bidder %d', 'huutokauppa' ), false === $n ? 0 : $n + 1 );
}

/**
 * Render a template from /templates, overridable in the theme under /huutokauppa/.
 */
function hk_template( string $name, array $vars = array() ): string {
	$file = locate_template( 'huutokauppa/' . $name . '.php' );
	if ( ! $file ) {
		$file = HK_DIR . 'templates/' . $name . '.php';
	}
	ob_start();
	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
	extract( $vars, EXTR_SKIP );
	include $file;
	return (string) ob_get_clean();
}

/**
 * Notice box markup.
 */
function hk_notice( string $message, string $type = 'info' ): string {
	return '<div class="hk-notice hk-notice--' . esc_attr( $type ) . '" role="status">' . esc_html( $message ) . '</div>';
}
