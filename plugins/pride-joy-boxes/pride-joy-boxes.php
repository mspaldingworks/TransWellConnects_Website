<?php
/**
 * Plugin Name:       Pride and Joy Boxes
 * Description:       Saves Pride and Joy Box requests from the website's request form and from the TransWell app in one list, under "Pride & Joy Boxes" in WP Admin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Transpire
 * License:           GPL-2.0-or-later
 * Text Domain:       pride-joy-boxes
 *
 * The request form on the Pride and Joy Boxes page and the Pride & Joy tab in
 * the TransWell app both send to POST /wp-json/pride-joy/v1/requests. There is
 * no shortcode: the page keeps its own form.
 */

defined( 'ABSPATH' ) || exit;

define( 'PJB_VERSION', '1.0.0' );
define( 'PJB_DB_VERSION', '1' );
define( 'PJB_FILE', __FILE__ );

/* -------------------------------------------------------------------------
 * Database
 * ---------------------------------------------------------------------- */

function pjb_table() {
	global $wpdb;
	return $wpdb->prefix . 'pjb_requests';
}

function pjb_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	// created_ts is a Unix timestamp, so the list does not depend on the server's timezone.
	dbDelta(
		'CREATE TABLE ' . pjb_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(20) NOT NULL DEFAULT 'free',
			name varchar(190) NOT NULL DEFAULT '',
			pronouns varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			address text NOT NULL,
			joy text NOT NULL,
			faves text NOT NULL,
			items longtext NOT NULL,
			plain tinyint(1) NOT NULL DEFAULT 0,
			notes text NOT NULL,
			source varchar(10) NOT NULL DEFAULT 'web',
			status varchar(20) NOT NULL DEFAULT 'new',
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY status_time (status, created_ts)
		) $charset;"
	);

	update_option( 'pjb_db_version', PJB_DB_VERSION, false );
}
register_activation_hook( __FILE__, 'pjb_install' );

/** Covers the case where new plugin files are uploaded over old ones without reactivating. */
function pjb_maybe_upgrade() {
	if ( get_option( 'pjb_db_version' ) !== PJB_DB_VERSION ) {
		pjb_install();
	}
}
add_action( 'plugins_loaded', 'pjb_maybe_upgrade' );

/* -------------------------------------------------------------------------
 * Values shared by the endpoint and the admin list
 * ---------------------------------------------------------------------- */

function pjb_kinds() {
	return array(
		'free'         => 'Free box',
		'subscription' => 'Subscription',
	);
}

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
		'web' => 'Website',
		'app' => 'TransWell app',
	);
}

/** Where the "new request" notice goes. */
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

/* -------------------------------------------------------------------------
 * Abuse limits
 * ---------------------------------------------------------------------- */

/**
 * Flood guard: true once one network address passes $limit requests in a
 * window. The address is only used, hashed, as a counter name; it is never
 * saved with a request.
 */
function pjb_throttled( $limit, $seconds ) {
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$name = 'pjb_req_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 ) . '_' . floor( time() / $seconds );
	$seen = (int) get_transient( $name );
	if ( $seen >= $limit ) {
		return true;
	}
	set_transient( $name, $seen + 1, 2 * $seconds );
	return false;
}

/* -------------------------------------------------------------------------
 * REST API (public: the page form and the app both post here)
 * ---------------------------------------------------------------------- */

function pjb_register_routes() {
	register_rest_route(
		'pride-joy/v1',
		'/requests',
		array(
			'methods'             => 'POST',
			'callback'            => 'pjb_rest_create',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'pjb_register_routes' );

function pjb_response( $data, $status = 200 ) {
	do_action( 'litespeed_control_set_nocache', 'pride and joy requests are never cached' );
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	return $response;
}

function pjb_rest_create( WP_REST_Request $request ) {
	global $wpdb;

	// Hidden field that people never see. A filled one is a bot; answer as if it worked.
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return pjb_response( array( 'ok' => true ), 201 );
	}

	$name  = pjb_line( $request->get_param( 'name' ), 100 );
	$email = sanitize_email( mb_substr( (string) $request->get_param( 'email' ), 0, 190 ) );
	if ( '' === $name ) {
		return pjb_response(
			array(
				'ok'   => false,
				'code' => 'name',
			),
			400
		);
	}
	if ( '' === $email || ! is_email( $email ) ) {
		return pjb_response(
			array(
				'ok'   => false,
				'code' => 'email',
			),
			400
		);
	}

	$kind = (string) $request->get_param( 'kind' );
	if ( ! isset( pjb_kinds()[ $kind ] ) ) {
		$kind = 'free';
	}
	$source = (string) $request->get_param( 'source' );
	if ( ! isset( pjb_sources()[ $source ] ) ) {
		$source = 'web';
	}

	$joy   = array();
	$given = $request->get_param( 'joy' );
	foreach ( is_array( $given ) ? array_slice( array_values( $given ), 0, 12 ) : array() as $pick ) {
		$pick = pjb_line( $pick, 60 );
		if ( '' !== $pick ) {
			$joy[] = $pick;
		}
	}

	// Each item is kept with its label as the person saw it, so later edits to the form do not relabel old requests.
	$items = array();
	$given = $request->get_param( 'items' );
	foreach ( is_array( $given ) ? array_slice( array_values( $given ), 0, 12 ) : array() as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$label = pjb_line( isset( $item['label'] ) ? $item['label'] : '', 60 );
		if ( '' === $label ) {
			continue;
		}
		$items[] = array(
			'label'  => $label,
			'detail' => pjb_line( isset( $item['detail'] ) ? $item['detail'] : '', 200 ),
		);
	}

	if ( pjb_throttled( 20, HOUR_IN_SECONDS ) ) {
		return pjb_response(
			array(
				'ok'   => false,
				'code' => 'limit',
			),
			429
		);
	}

	$saved = $wpdb->insert(
		pjb_table(),
		array(
			'kind'       => $kind,
			'name'       => $name,
			'pronouns'   => pjb_line( $request->get_param( 'pronouns' ), 60 ),
			'email'      => $email,
			'address'    => pjb_block( $request->get_param( 'address' ), 500 ),
			'joy'        => wp_json_encode( $joy ),
			'faves'      => pjb_line( $request->get_param( 'faves' ), 300 ),
			'items'      => wp_json_encode( $items ),
			'plain'      => rest_sanitize_boolean( $request->get_param( 'plain' ) ) ? 1 : 0,
			'notes'      => pjb_block( $request->get_param( 'notes' ), 2000 ),
			'source'     => $source,
			'status'     => 'new',
			'created_ts' => time(),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
	);

	if ( ! $saved ) {
		return pjb_response(
			array(
				'ok'   => false,
				'code' => 'not_saved',
			),
			500
		);
	}

	pjb_notify( (int) $wpdb->insert_id, $kind, $source );

	return pjb_response( array( 'ok' => true ), 201 );
}

/**
 * Tells the team a request came in. The email carries no name, address or
 * sizes: those stay in WP Admin, behind the login.
 */
function pjb_notify( $id, $kind, $source ) {
	$to = pjb_notify_email();
	if ( ! $to ) {
		return;
	}
	$body = sprintf(
		"A new Pride and Joy Box request came in.\n\nBox: %s\nSent from: %s\n\nRead it and write back:\n%s\n",
		pjb_kinds()[ $kind ],
		pjb_sources()[ $source ],
		admin_url( 'admin.php?page=pride-joy-boxes' )
	);
	wp_mail( $to, sprintf( 'New Pride and Joy Box request (#%d)', $id ), $body );
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
