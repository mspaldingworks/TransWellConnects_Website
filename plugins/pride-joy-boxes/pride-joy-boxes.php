<?php
/**
 * Plugin Name:       Pride and Joy Boxes
 * Description:       Tracks Pride and Joy Boxes in WP Admin: box requests, offers to help and feedback from the website and the TransWell app, plus the people behind them, donations, receipts, thank-you notes and an Excel download of all of it.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Transpire
 * License:           GPL-2.0-or-later
 * Text Domain:       pride-joy-boxes
 *
 * The Pride and Joy Boxes page and the Pride & Joy tab in the TransWell app
 * both send to three public routes under /wp-json/pride-joy/v1/:
 *   requests  a box request
 *   offers    an offer to help fill boxes (art, items, notes, money, time)
 *   feedback  "How was your box?"
 * There is no shortcode: the page keeps its own forms.
 *
 * Everything is kept in the plugin's own tables, with one row per person in
 * {prefix}pjb_people that the other tables point to. The Excel download in WP
 * Admin writes one sheet per table with stable IDs (C-0001, R-0001 ...) so a
 * CRM can import it later.
 */

defined( 'ABSPATH' ) || exit;

define( 'PJB_VERSION', '1.1.0' );
define( 'PJB_DB_VERSION', '2' );
define( 'PJB_FILE', __FILE__ );

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/receipts.php';

register_activation_hook( __FILE__, 'pjb_install' );
add_action( 'plugins_loaded', 'pjb_maybe_upgrade' );

/* -------------------------------------------------------------------------
 * Values shared by the routes, the admin screens and the Excel download
 * ---------------------------------------------------------------------- */

function pjb_kinds() {
	return array(
		'free'         => 'Free box',
		'subscription' => 'Subscription',
	);
}

/** Request statuses. */
function pjb_statuses() {
	return array(
		'new'       => 'New',
		'confirmed' => 'Wrote back',
		'sent'      => 'Box sent',
		'closed'    => 'Closed',
	);
}

function pjb_sources() {
	return array(
		'web'   => 'Website',
		'app'   => 'TransWell app',
		'staff' => 'Entered by the team',
	);
}

/** What a person can give. An offer from the forms makes one donation per category. */
function pjb_categories() {
	return array(
		'art'   => 'Art',
		'items' => 'Items',
		'notes' => 'Handwritten notes',
		'money' => 'Money',
		'time'  => 'Time (volunteering)',
		'other' => 'Something else',
	);
}

function pjb_donation_statuses() {
	return array(
		'offered'  => 'Offered',
		'expected' => 'On its way',
		'received' => 'Received',
		'declined' => 'Declined',
	);
}

function pjb_methods() {
	return array(
		''         => '—',
		'cash'     => 'Cash',
		'check'    => 'Check',
		'paypal'   => 'PayPal',
		'venmo'    => 'Venmo',
		'cashapp'  => 'Cash App',
		'card'     => 'Card',
		'mail'     => 'Mailed to us',
		'drop_off' => 'Dropped off',
		'other'    => 'Other',
	);
}

/** A person can hold several roles; they are stored comma-separated. */
function pjb_roles() {
	return array(
		'requester' => 'Asked for a box',
		'donor'     => 'Donor',
		'artist'    => 'Artist',
		'writer'    => 'Note writer',
		'volunteer' => 'Volunteer',
	);
}

/** The role an offer of each category gives a person. */
function pjb_category_role( $category ) {
	$map = array(
		'art'   => 'artist',
		'notes' => 'writer',
		'time'  => 'volunteer',
	);
	return isset( $map[ $category ] ) ? $map[ $category ] : 'donor';
}

function pjb_thanks_methods() {
	return array(
		'email'     => 'Email',
		'card'      => 'Card or letter',
		'text'      => 'Text message',
		'in_person' => 'In person',
		'social'    => 'Social media',
	);
}

/** Kinds of entry in the Activity log (what a CRM calls interactions). */
function pjb_activity_types() {
	return array(
		'note'    => 'Note',
		'email'   => 'Email',
		'call'    => 'Call or text',
		'meeting' => 'Met in person',
		'status'  => 'Status change',
	);
}

/** Who may see and change the tracker. Filter it to open it to other roles. */
function pjb_capability() {
	return apply_filters( 'pjb_capability', 'manage_options' );
}

/** Where the "something came in" notices go. */
function pjb_notify_email() {
	return apply_filters( 'pjb_notify_email', 'transpire@transwellconnects.com' );
}

/** One line of text, trimmed to $max characters. Emoji are stored as entities so any database charset keeps them. */
function pjb_line( $value, $max ) {
	$value = is_string( $value ) ? $value : '';
	return wp_encode_emoji( trim( mb_substr( sanitize_text_field( $value ), 0, $max ) ) );
}

/** The same for text that may run over several lines. */
function pjb_block( $value, $max ) {
	$value = is_string( $value ) ? $value : '';
	return wp_encode_emoji( trim( mb_substr( sanitize_textarea_field( $value ), 0, $max ) ) );
}

/** Stored text back as characters (emoji are saved as entities). */
function pjb_plain( $value ) {
	return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** A Y-m-d date, or null when $value is not one. */
function pjb_date( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	$date  = DateTime::createFromFormat( '!Y-m-d', $value );
	return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
}

/** A money amount as a string with two decimals, or null when blank or not a number. */
function pjb_money( $value ) {
	$value = is_scalar( $value ) ? str_replace( array( '$', ',', ' ' ), '', (string) $value ) : '';
	if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
		return null;
	}
	return number_format( (float) $value, 2, '.', '' );
}

/** Today in the site's timezone. */
function pjb_today() {
	return wp_date( 'Y-m-d' );
}

/** The ID people see and the Excel file uses, e.g. C-0007. */
function pjb_ref( $prefix, $id ) {
	return $id ? sprintf( '%s-%04d', $prefix, (int) $id ) : '';
}

/* -------------------------------------------------------------------------
 * Abuse limits
 * ---------------------------------------------------------------------- */

/**
 * Flood guard: true once one network address passes $limit posts to one
 * route in a window. The address is only used, hashed, as a counter name; it
 * is never saved with anything.
 */
function pjb_throttled( $route, $limit, $seconds ) {
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$name = 'pjb_' . substr( $route, 0, 3 ) . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 ) . '_' . floor( time() / $seconds );
	$seen = (int) get_transient( $name );
	if ( $seen >= $limit ) {
		return true;
	}
	set_transient( $name, $seen + 1, 2 * $seconds );
	return false;
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/xlsx.php';
	require_once __DIR__ . '/includes/admin.php';
	require_once __DIR__ . '/includes/admin-actions.php';
}
