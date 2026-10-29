<?php
/**
 * Plugin Name:       Transpire Feedback
 * Description:       Live audience reactions and a feedback form for Transpire listening sessions. Organizers start and stop recording with one button; every reaction is saved with its time, so they can see how the room responded across the session.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Transpire
 * License:           GPL-2.0-or-later
 * Text Domain:       transpire-feedback
 *
 * Shortcodes:
 *   [transpire_reactions]  the emoji buttons the audience taps during the session,
 *                          with a chart of the room's reactions over time
 *   [transpire_feedback]   the written feedback form
 *
 * The Start / Stop button, results and settings live under "Transpire
 * Feedback" in WP Admin. Each start-to-stop is saved as its own record (a
 * "run"), with its own report and downloads.
 */

defined( 'ABSPATH' ) || exit;

define( 'TRANSPIRE_FB_VERSION', '1.2.0' );
define( 'TRANSPIRE_FB_DB_VERSION', '2' );
// A run nobody stops is closed this long after it started, so a forgotten session cannot leave the buttons open overnight.
define( 'TRANSPIRE_FB_MAX_RUN', 4 * HOUR_IN_SECONDS );
// Rehearsals may run all day for demos and testing; the real session's scheduled start still closes one.
define( 'TRANSPIRE_FB_MAX_REHEARSAL', DAY_IN_SECONDS );
define( 'TRANSPIRE_FB_FILE', __FILE__ );

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function transpire_fb_defaults() {
	return array(
		'session_name'  => 'Listening Session: 11/19/2026',
		'timezone'      => 'America/Kentucky/Louisville',
		'starts'        => '2026-11-19 10:30',
		'ends'          => '2026-11-19 12:00',
		'reactions'     => "❤️ | With you\n🙏 | Grateful\n💡 | Learned something\n😢 | This is heavy\n🤔 | Sitting with this\n✊ | Ready to act",
		'cooldown'      => 3,
		'feedback_days' => 14,
		'rating_label'  => 'How well did this session help you listen and understand?',
		'questions'     => "What is one thing you heard today that will stay with you?\nWhat is one change you will take back to your congregation or community?\nIs there anything else you want the organizers to know?",
		'roles'         => "Clergy\nLay leader\nCongregation member\nCommunity member\nSpeaker\nOther",
		'ask_email'     => 1,
		'auto_start'    => 1,
	);
}

/** Text settings that may hold emoji. Saved as HTML entities, read back as characters. */
function transpire_fb_text_keys() {
	return array( 'session_name', 'reactions', 'rating_label', 'questions', 'roles' );
}

function transpire_fb_options() {
	$saved = get_option( 'transpire_fb_options', array() );
	$opts  = wp_parse_args( is_array( $saved ) ? $saved : array(), transpire_fb_defaults() );
	foreach ( transpire_fb_text_keys() as $key ) {
		$opts[ $key ] = html_entity_decode( (string) $opts[ $key ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	return $opts;
}

/** Non-empty trimmed lines of a multi-line setting. */
function transpire_fb_lines( $text, $max ) {
	$lines = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		if ( count( $lines ) >= $max ) {
			break;
		}
	}
	return $lines;
}

/**
 * The reaction buttons, keyed by a stable ASCII key built from each label.
 * Rows in the database store the key, so a label keeps its history as long as
 * its wording stays the same.
 */
function transpire_fb_reactions() {
	$options   = transpire_fb_options();
	$reactions = array();
	foreach ( transpire_fb_lines( $options['reactions'], 8 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$emoji = $parts[0];
		if ( '' === $emoji ) {
			continue;
		}
		$label = ( isset( $parts[1] ) && '' !== $parts[1] ) ? $parts[1] : $emoji;
		$key   = substr( preg_replace( '/[^a-z0-9\-]/', '', sanitize_title( $label ) ), 0, 60 );
		if ( '' === $key || isset( $reactions[ $key ] ) ) {
			$key = 'reaction-' . ( count( $reactions ) + 1 );
		}
		$reactions[ $key ] = array(
			'key'   => $key,
			'emoji' => $emoji,
			'label' => $label,
		);
	}
	return $reactions;
}

/** Results are grouped by this key, so a new session name starts a new set of results. */
function transpire_fb_session_key() {
	$options = transpire_fb_options();
	$key     = substr( preg_replace( '/[^a-z0-9\-]/', '', sanitize_title( $options['session_name'] ) ), 0, 100 );
	return '' === $key ? 'session' : $key;
}

/** Session start and end as Unix timestamps, plus the session's own timezone. */
function transpire_fb_window() {
	$options = transpire_fb_options();
	try {
		$tz = new DateTimeZone( $options['timezone'] );
	} catch ( Exception $e ) {
		$tz = new DateTimeZone( 'UTC' );
	}
	$start    = date_create_immutable( $options['starts'], $tz );
	$end      = date_create_immutable( $options['ends'], $tz );
	$start_ts = $start ? $start->getTimestamp() : 0;
	$end_ts   = $end ? $end->getTimestamp() : 0;
	if ( $end_ts <= $start_ts ) {
		$end_ts = $start_ts + 90 * MINUTE_IN_SECONDS;
	}
	return array(
		'start' => $start_ts,
		'end'   => $end_ts,
		'tz'    => $tz,
	);
}

/* -------------------------------------------------------------------------
 * Runs: one row per press of Start, closed by Stop
 * ---------------------------------------------------------------------- */

/** The run being recorded now, or null. Closes a run that has passed the maximum length. */
function transpire_fb_active_run() {
	global $wpdb;
	$table = transpire_fb_table( 'runs' );
	$run   = $wpdb->get_row( "SELECT * FROM {$table} WHERE ended_ts = 0 ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	$max   = $run && (int) $run->is_test ? TRANSPIRE_FB_MAX_REHEARSAL : TRANSPIRE_FB_MAX_RUN;
	if ( $run && time() - (int) $run->started_ts >= $max ) {
		$wpdb->update( $table, array( 'ended_ts' => (int) $run->started_ts + $max ), array( 'id' => (int) $run->id ), array( '%d' ), array( '%d' ) );
		return null;
	}
	return $run;
}

function transpire_fb_get_run( $id ) {
	global $wpdb;
	$table = transpire_fb_table( 'runs' );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
}

/** Newest first. */
function transpire_fb_runs( $limit = 100 ) {
	global $wpdb;
	$table = transpire_fb_table( 'runs' );
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY started_ts DESC, id DESC LIMIT %d", (int) $limit ) );
}

/** Closes whatever is running, then opens a new run. Returns the new run's id. */
function transpire_fb_start_run( $label, $is_test, $started_by ) {
	global $wpdb;
	transpire_fb_stop_run();
	$wpdb->insert(
		transpire_fb_table( 'runs' ),
		array(
			'session_key' => transpire_fb_session_key(),
			'label'       => wp_encode_emoji( mb_substr( $label, 0, 190 ) ),
			'is_test'     => $is_test ? 1 : 0,
			'started_by'  => $started_by,
			'started_ts'  => time(),
			'ended_ts'    => 0,
		),
		array( '%s', '%s', '%d', '%s', '%d', '%d' )
	);
	return (int) $wpdb->insert_id;
}

function transpire_fb_stop_run() {
	global $wpdb;
	$table = transpire_fb_table( 'runs' );
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET ended_ts = %d WHERE ended_ts = 0", time() ) );
}

/**
 * The safety net for the real session: if nobody has pressed Start by the
 * scheduled start time, recording starts by itself. A rehearsal still running
 * at that moment is closed first, so practice data never mixes with the real
 * record. It fires at most once per scheduled start, and not at all when a
 * real run was already started for this session in the hours before.
 */
function transpire_fb_maybe_autostart( $now, $window ) {
	global $wpdb;
	$options = transpire_fb_options();
	if ( empty( $options['auto_start'] ) || $now < $window['start'] || $now >= $window['end'] ) {
		return;
	}
	$active = transpire_fb_active_run();
	if ( $active && ! (int) $active->is_test ) {
		return;
	}
	$runs = transpire_fb_table( 'runs' );
	$real = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs} WHERE is_test = 0 AND session_key = %s AND started_ts >= %d", transpire_fb_session_key(), $window['start'] - 3 * HOUR_IN_SECONDS ) );
	if ( $real ) {
		return;
	}
	// INSERT IGNORE on the unique option name lets exactly one of several simultaneous requests through.
	$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'transpire_fb_autostart_' . $window['start'], (string) $now ) );
	if ( 1 === (int) $claimed ) {
		transpire_fb_start_run( $options['session_name'], false, 'schedule' );
	}
}

/**
 * What is open right now. Reactions are open only while a run is being
 * recorded. The feedback form opens with the first run and stays open for the
 * set number of days after the last real (non-rehearsal) run ends.
 */
function transpire_fb_state() {
	global $wpdb;
	$now     = time();
	$options = transpire_fb_options();
	$window  = transpire_fb_window();

	transpire_fb_maybe_autostart( $now, $window );
	$run = transpire_fb_active_run();

	$runs     = transpire_fb_table( 'runs' );
	$last     = $wpdb->get_row( $wpdb->prepare( "SELECT id, ended_ts FROM {$runs} WHERE session_key = %s AND is_test = 0 AND ended_ts > 0 ORDER BY ended_ts DESC LIMIT 1", transpire_fb_session_key() ) );
	$last_end = $last ? (int) $last->ended_ts : 0;
	$until    = max( $last_end, $window['end'] ) + max( 0, (int) $options['feedback_days'] ) * DAY_IN_SECONDS;

	if ( $run ) {
		$phase = 'open';
	} elseif ( $last_end || $now >= $window['end'] ) {
		$phase = 'after';
	} else {
		$phase = 'before';
	}

	return array(
		'now'            => $now,
		'phase'          => $phase,
		'run'            => $run,
		// Feedback sent after a real run ends is filed with that run.
		'run_id'         => $run ? (int) $run->id : ( $last ? (int) $last->id : 0 ),
		'is_test'        => $run && (int) $run->is_test,
		'reactions_open' => (bool) $run,
		'feedback_open'  => $run || ( ( $last_end || $now >= $window['start'] ) && $now <= $until ),
		'feedback_until' => $until,
	);
}

/* -------------------------------------------------------------------------
 * Database
 * ---------------------------------------------------------------------- */

function transpire_fb_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'transpire_' . $name;
}

function transpire_fb_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	// created_ts is a Unix timestamp, so results do not depend on the server's timezone.
	// run_id is 0 for rows saved before version 1.1, when there were no runs.
	dbDelta(
		'CREATE TABLE ' . transpire_fb_table( 'reactions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key varchar(100) NOT NULL,
			run_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reaction_key varchar(60) NOT NULL,
			client_hash char(16) NOT NULL,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY session_time (session_key, created_ts),
			KEY run_time (run_id, created_ts),
			KEY client (client_hash, id)
		) $charset;"
	);

	// ended_ts is 0 while the run is being recorded.
	dbDelta(
		'CREATE TABLE ' . transpire_fb_table( 'runs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key varchar(100) NOT NULL,
			label varchar(190) NOT NULL DEFAULT '',
			is_test tinyint(1) NOT NULL DEFAULT 0,
			started_by varchar(20) NOT NULL DEFAULT '',
			started_ts int(10) unsigned NOT NULL,
			ended_ts int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ended (ended_ts)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . transpire_fb_table( 'feedback' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key varchar(100) NOT NULL,
			run_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rating tinyint(3) unsigned NOT NULL DEFAULT 0,
			role varchar(100) NOT NULL DEFAULT '',
			answers longtext NOT NULL,
			email varchar(190) NOT NULL DEFAULT '',
			client_hash char(16) NOT NULL,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY session_time (session_key, created_ts),
			KEY run_id (run_id),
			KEY client (client_hash, id)
		) $charset;"
	);

	update_option( 'transpire_fb_db_version', TRANSPIRE_FB_DB_VERSION, false );
}
register_activation_hook( __FILE__, 'transpire_fb_install' );

/** Covers the case where new plugin files are uploaded over old ones without reactivating. */
function transpire_fb_maybe_upgrade() {
	if ( get_option( 'transpire_fb_db_version' ) !== TRANSPIRE_FB_DB_VERSION ) {
		transpire_fb_install();
	}
}
add_action( 'plugins_loaded', 'transpire_fb_maybe_upgrade' );

/* -------------------------------------------------------------------------
 * Abuse limits
 * ---------------------------------------------------------------------- */

/**
 * A short anonymous id for the device. The browser sends a random id it keeps
 * in local storage; only a keyed hash of it is stored. No IP address or
 * browser details are saved with a reaction or a feedback entry.
 */
function transpire_fb_client_hash( $raw ) {
	$raw = is_string( $raw ) ? strtolower( $raw ) : '';
	if ( ! preg_match( '/^[a-f0-9]{16,64}$/', $raw ) ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$raw = $ip . '|' . $ua;
	}
	return substr( hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) ), 0, 16 );
}

/**
 * Flood guard: true once one network address passes $limit requests in a
 * window. A whole room can share one address on venue Wi-Fi, so the limits
 * passed here are generous. The address is only used, hashed, as a counter name.
 */
function transpire_fb_throttled( $bucket, $limit, $seconds ) {
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$name = 'tspr_fb_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 ) . '_' . floor( time() / $seconds );
	$seen = (int) get_transient( $name );
	if ( $seen >= $limit ) {
		return true;
	}
	set_transient( $name, $seen + 1, 2 * $seconds );
	return false;
}

/* -------------------------------------------------------------------------
 * REST API (public, anonymous)
 * ---------------------------------------------------------------------- */

function transpire_fb_register_routes() {
	register_rest_route(
		'transpire/v1',
		'/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'transpire_fb_rest_status',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'transpire/v1',
		'/timeline',
		array(
			'methods'             => 'GET',
			'callback'            => 'transpire_fb_rest_timeline',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'transpire/v1',
		'/react',
		array(
			'methods'             => 'POST',
			'callback'            => 'transpire_fb_rest_react',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'transpire/v1',
		'/feedback',
		array(
			'methods'             => 'POST',
			'callback'            => 'transpire_fb_rest_feedback',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'transpire_fb_register_routes' );

/** These answers change by the minute, so no page cache or CDN may keep them. */
function transpire_fb_response( $data, $status = 200 ) {
	do_action( 'litespeed_control_set_nocache', 'transpire feedback is live data' );
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	return $response;
}

function transpire_fb_rest_status() {
	$options = transpire_fb_options();
	$window  = transpire_fb_window();
	$state   = transpire_fb_state();

	return transpire_fb_response(
		array(
			'now'            => $state['now'],
			'phase'          => $state['phase'],
			'test'           => $state['is_test'],
			'reactions_open' => $state['reactions_open'],
			'feedback_open'  => $state['feedback_open'],
			'session'        => $options['session_name'],
			'start'          => $window['start'],
			'end'            => $window['end'],
			'start_label'    => wp_date( 'l, F j \a\t g:i A', $window['start'], $window['tz'] ),
			'end_label'      => wp_date( 'g:i A', $window['end'], $window['tz'] ),
			'cooldown'       => max( 0, (int) $options['cooldown'] ),
			'reactions'      => array_values( transpire_fb_reactions() ),
			'feedback'       => array(
				'rating_label' => $options['rating_label'],
				'questions'    => transpire_fb_lines( $options['questions'], 5 ),
				'roles'        => transpire_fb_lines( $options['roles'], 12 ),
				'ask_email'    => ! empty( $options['ask_email'] ),
			),
		)
	);
}

/**
 * The room's reactions over time, for the chart under the buttons: the run
 * being recorded, or once the session is over, the last real run. Totals per
 * reaction per column only; nothing about any one device.
 */
function transpire_fb_timeline() {
	global $wpdb;
	$state = transpire_fb_state();
	$run   = $state['run'];
	if ( ! $run && 'after' === $state['phase'] && $state['run_id'] ) {
		$run = transpire_fb_get_run( $state['run_id'] );
	}
	if ( ! $run ) {
		return array( 'run' => 0 );
	}

	// Every phone on the page asks every few seconds, so one answer serves them all for a moment.
	$cache = 'transpire_fb_timeline_' . (int) $run->id;
	$data  = get_transient( $cache );
	if ( is_array( $data ) ) {
		return $data;
	}

	$started = (int) $run->started_ts;
	$ended   = (int) $run->ended_ts;
	$end     = max( $ended ? $ended : time(), $started + 5 * MINUTE_IN_SECONDS );

	// The finest columns that keep the chart to 60 of them, so it still reads on a phone.
	$bucket = HOUR_IN_SECONDS;
	foreach ( array( 10, 15, 30, 60, 120, 300, 600, 900, 1800 ) as $size ) {
		if ( ( $end - $started ) / $size <= 60 ) {
			$bucket = $size;
			break;
		}
	}
	$start   = (int) floor( $started / $bucket ) * $bucket;
	$buckets = max( 1, (int) ceil( ( $end - $start ) / $bucket ) );

	$counts = array();
	foreach ( array_keys( transpire_fb_reactions() ) as $key ) {
		$counts[ $key ] = array_fill( 0, $buckets, 0 );
	}
	$table = transpire_fb_table( 'reactions' );
	$total = 0;
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT reaction_key, FLOOR((created_ts - %d) / %d) AS bucket, COUNT(*) AS total FROM {$table} WHERE run_id = %d GROUP BY reaction_key, bucket", $start, $bucket, (int) $run->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	foreach ( (array) $rows as $row ) {
		$index = (int) $row->bucket;
		if ( isset( $counts[ $row->reaction_key ][ $index ] ) ) {
			$counts[ $row->reaction_key ][ $index ] = (int) $row->total;
			$total                                 += (int) $row->total;
		}
	}

	$series = array();
	foreach ( $counts as $key => $values ) {
		$series[] = array(
			'key'    => $key,
			'counts' => $values,
		);
	}

	$window = transpire_fb_window();
	$data   = array(
		'run'     => (int) $run->id,
		'open'    => ! $ended,
		'start'   => $start,
		'end'     => $end,
		'bucket'  => $bucket,
		'buckets' => $buckets,
		'tz'      => $window['tz']->getName(),
		'total'   => $total,
		'series'  => $series,
	);
	set_transient( $cache, $data, $ended ? MINUTE_IN_SECONDS : 4 );
	return $data;
}

function transpire_fb_rest_timeline() {
	return transpire_fb_response( transpire_fb_timeline() );
}

function transpire_fb_rest_react( WP_REST_Request $request ) {
	global $wpdb;

	$state = transpire_fb_state();
	if ( ! $state['reactions_open'] ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'closed',
			),
			403
		);
	}

	$reactions = transpire_fb_reactions();
	$key       = sanitize_key( (string) $request->get_param( 'reaction' ) );
	if ( ! isset( $reactions[ $key ] ) ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'unknown_reaction',
			),
			400
		);
	}

	$options  = transpire_fb_options();
	$table    = transpire_fb_table( 'reactions' );
	$client   = transpire_fb_client_hash( $request->get_param( 'client' ) );
	$cooldown = max( 0, (int) $options['cooldown'] );

	if ( $cooldown > 0 ) {
		$last = (int) $wpdb->get_var( $wpdb->prepare( "SELECT created_ts FROM {$table} WHERE client_hash = %s ORDER BY id DESC LIMIT 1", $client ) );
		$wait = $cooldown - ( $state['now'] - $last );
		if ( $last && $wait > 0 ) {
			return transpire_fb_response(
				array(
					'ok'          => false,
					'code'        => 'cooldown',
					'retry_after' => $wait,
				),
				429
			);
		}
	}

	if ( transpire_fb_throttled( 'r', 1200, MINUTE_IN_SECONDS ) ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'busy',
			),
			429
		);
	}

	$saved = $wpdb->insert(
		$table,
		array(
			'session_key'  => transpire_fb_session_key(),
			'run_id'       => $state['run_id'],
			'reaction_key' => $key,
			'client_hash'  => $client,
			'is_test'      => $state['is_test'] ? 1 : 0,
			'created_ts'   => $state['now'],
		),
		array( '%s', '%d', '%s', '%s', '%d', '%d' )
	);

	if ( ! $saved ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'not_saved',
			),
			500
		);
	}

	return transpire_fb_response(
		array(
			'ok'       => true,
			'cooldown' => $cooldown,
		)
	);
}

function transpire_fb_rest_feedback( WP_REST_Request $request ) {
	global $wpdb;

	// Hidden field that people never see. A filled one is a bot; answer as if it worked.
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return transpire_fb_response( array( 'ok' => true ) );
	}

	$state = transpire_fb_state();
	if ( ! $state['feedback_open'] ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'closed',
			),
			403
		);
	}

	$options = transpire_fb_options();
	$table   = transpire_fb_table( 'feedback' );
	$client  = transpire_fb_client_hash( $request->get_param( 'client' ) );
	$session = transpire_fb_session_key();

	$rating = (int) $request->get_param( 'rating' );
	if ( $rating < 1 || $rating > 5 || '' === trim( $options['rating_label'] ) ) {
		$rating = 0;
	}

	$role = sanitize_text_field( (string) $request->get_param( 'role' ) );
	if ( ! in_array( $role, transpire_fb_lines( $options['roles'], 12 ), true ) ) {
		$role = '';
	}

	// Each answer is stored beside the wording of its question, so later edits to the questions do not relabel old answers.
	$given   = $request->get_param( 'answers' );
	$given   = is_array( $given ) ? array_values( $given ) : array();
	$answers = array();
	foreach ( transpire_fb_lines( $options['questions'], 5 ) as $index => $question ) {
		$answer = isset( $given[ $index ] ) && is_string( $given[ $index ] ) ? $given[ $index ] : '';
		$answer = trim( mb_substr( sanitize_textarea_field( $answer ), 0, 4000 ) );
		if ( '' !== $answer ) {
			$answers[] = array(
				'q' => $question,
				'a' => $answer,
			);
		}
	}

	$email = '';
	if ( ! empty( $options['ask_email'] ) ) {
		$email = sanitize_email( mb_substr( (string) $request->get_param( 'email' ), 0, 190 ) );
	}

	if ( 0 === $rating && empty( $answers ) ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'empty',
			),
			400
		);
	}

	// Counted separately for rehearsals, so trying the form out does not use up a device's real answers.
	$already = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE client_hash = %s AND session_key = %s AND is_test = %d", $client, $session, $state['is_test'] ? 1 : 0 ) );
	if ( $already >= 3 || transpire_fb_throttled( 'f', 60, HOUR_IN_SECONDS ) ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'limit',
			),
			429
		);
	}

	$saved = $wpdb->insert(
		$table,
		array(
			'session_key' => $session,
			'run_id'      => $state['run_id'],
			'rating'      => $rating,
			'role'        => wp_encode_emoji( $role ),
			// wp_json_encode escapes non-ASCII, so emoji in answers survive any database charset.
			'answers'     => wp_json_encode( $answers ),
			'email'       => $email,
			'client_hash' => $client,
			'is_test'     => $state['is_test'] ? 1 : 0,
			'created_ts'  => $state['now'],
		),
		array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
	);

	if ( ! $saved ) {
		return transpire_fb_response(
			array(
				'ok'   => false,
				'code' => 'not_saved',
			),
			500
		);
	}

	return transpire_fb_response( array( 'ok' => true ) );
}

/* -------------------------------------------------------------------------
 * Shortcodes
 * ---------------------------------------------------------------------- */

function transpire_fb_register_assets() {
	wp_register_style( 'transpire-fb', plugins_url( 'assets/widget.css', __FILE__ ), array(), TRANSPIRE_FB_VERSION );
	wp_register_script( 'transpire-fb', plugins_url( 'assets/widget.js', __FILE__ ), array(), TRANSPIRE_FB_VERSION, true );

	// Load the stylesheet in the page head when the page is known to use a widget.
	$post = is_singular() ? get_post() : null;
	if ( $post && ( has_shortcode( $post->post_content, 'transpire_reactions' ) || has_shortcode( $post->post_content, 'transpire_feedback' ) ) ) {
		wp_enqueue_style( 'transpire-fb' );
	}
}
add_action( 'wp_enqueue_scripts', 'transpire_fb_register_assets' );

/**
 * Both widgets are drawn in the browser from /status. The page itself may sit
 * in a page cache for days, so nothing that changes is printed into it.
 */
function transpire_fb_mount( $kind, $fallback ) {
	wp_enqueue_style( 'transpire-fb' );
	wp_enqueue_script( 'transpire-fb' );

	return sprintf(
		'<div class="tspr-fb tspr-fb-%1$s" id="transpire-%1$s" data-tspr-fb="%1$s" data-api="%2$s"><noscript><p>%3$s</p></noscript></div>',
		esc_attr( $kind ),
		esc_url( rest_url( 'transpire/v1/' ) ),
		esc_html( $fallback )
	);
}

function transpire_fb_shortcode_reactions() {
	return transpire_fb_mount( 'reactions', 'The live reactions need JavaScript. Please turn it on in your browser to take part.' );
}
add_shortcode( 'transpire_reactions', 'transpire_fb_shortcode_reactions' );

function transpire_fb_shortcode_feedback() {
	return transpire_fb_mount( 'feedback', 'The feedback form needs JavaScript. Please turn it on in your browser to share feedback.' );
}
add_shortcode( 'transpire_feedback', 'transpire_fb_shortcode_feedback' );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
