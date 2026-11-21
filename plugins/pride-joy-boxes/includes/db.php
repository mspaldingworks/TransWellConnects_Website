<?php
/**
 * Tables, upgrades and the few writes the public routes and WP Admin share.
 *
 * Times a row was created are Unix timestamps (created_ts), so nothing depends
 * on the server's timezone. Dates the team types (a gift received, a box sent)
 * are plain DATE columns.
 */

defined( 'ABSPATH' ) || exit;

/** Table name for one of: people, requests, shipments, donations, receipts, thanks, feedback, activity. */
function pjb_t( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'pjb_' . $name;
}

/** Kept for 1.0 callers. */
function pjb_table() {
	return pjb_t( 'requests' );
}

function pjb_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$before  = get_option( 'pjb_db_version' );

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'people' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			pronouns varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			address text NOT NULL,
			roles varchar(190) NOT NULL DEFAULT '',
			contact_ok tinyint(1) NOT NULL DEFAULT 1,
			how_found varchar(190) NOT NULL DEFAULT '',
			notes text NOT NULL,
			created_ts int(10) unsigned NOT NULL,
			updated_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'requests' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
			KEY status_time (status, created_ts),
			KEY person_id (person_id)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'shipments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL DEFAULT 0,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_on date DEFAULT NULL,
			contents text NOT NULL,
			cost decimal(10,2) DEFAULT NULL,
			carrier varchar(60) NOT NULL DEFAULT '',
			tracking varchar(100) NOT NULL DEFAULT '',
			packed_by varchar(100) NOT NULL DEFAULT '',
			notes text NOT NULL,
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id),
			KEY request_id (request_id)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'donations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			category varchar(20) NOT NULL DEFAULT 'items',
			status varchar(20) NOT NULL DEFAULT 'offered',
			received_on date DEFAULT NULL,
			description text NOT NULL,
			amount decimal(10,2) DEFAULT NULL,
			value_estimate decimal(10,2) DEFAULT NULL,
			method varchar(20) NOT NULL DEFAULT '',
			campaign varchar(100) NOT NULL DEFAULT '',
			source varchar(10) NOT NULL DEFAULT 'staff',
			notes text NOT NULL,
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id),
			KEY status (status)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'receipts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			number varchar(30) NOT NULL DEFAULT '',
			donation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			issued_on date DEFAULT NULL,
			amount decimal(10,2) DEFAULT NULL,
			description text NOT NULL,
			tax_deductible tinyint(1) NOT NULL DEFAULT 0,
			template_version int(10) unsigned NOT NULL DEFAULT 1,
			body longtext NOT NULL,
			sent_on date DEFAULT NULL,
			sent_via varchar(20) NOT NULL DEFAULT '',
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY donation_id (donation_id)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'thanks' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			donation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			shipment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			method varchar(20) NOT NULL DEFAULT 'email',
			sent_on date DEFAULT NULL,
			sent_by varchar(100) NOT NULL DEFAULT '',
			notes text NOT NULL,
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'feedback' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			request_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rating tinyint(1) unsigned DEFAULT NULL,
			loved text NOT NULL,
			wish text NOT NULL,
			quote_ok tinyint(1) NOT NULL DEFAULT 0,
			credit_as varchar(100) NOT NULL DEFAULT '',
			source varchar(10) NOT NULL DEFAULT 'web',
			reviewed tinyint(1) NOT NULL DEFAULT 0,
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id)
		) $charset;"
	);

	dbDelta(
		'CREATE TABLE ' . pjb_t( 'activity' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			happened_on date DEFAULT NULL,
			type varchar(20) NOT NULL DEFAULT 'note',
			note text NOT NULL,
			by_user varchar(100) NOT NULL DEFAULT '',
			created_ts int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id)
		) $charset;"
	);

	// 1.0 kept only requests. Give each one a person, matched by email.
	if ( $before && version_compare( $before, '2', '<' ) ) {
		pjb_link_requests_to_people();
	}

	update_option( 'pjb_db_version', PJB_DB_VERSION, false );
}

/** Covers the case where new plugin files are uploaded over old ones without reactivating. */
function pjb_maybe_upgrade() {
	if ( get_option( 'pjb_db_version' ) !== PJB_DB_VERSION ) {
		pjb_install();
	}
}

function pjb_link_requests_to_people() {
	global $wpdb;
	$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . pjb_t( 'requests' ) . ' WHERE person_id = 0 ORDER BY id ASC' );
	foreach ( $rows as $row ) {
		$person = pjb_find_or_add_person(
			array(
				'name'     => $row->name,
				'pronouns' => $row->pronouns,
				'email'    => $row->email,
				'address'  => $row->address,
			),
			'requester',
			(int) $row->created_ts
		);
		$wpdb->update( pjb_t( 'requests' ), array( 'person_id' => $person ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
	}
}

/* -------------------------------------------------------------------------
 * People
 * ---------------------------------------------------------------------- */

function pjb_person( $id ) {
	global $wpdb;
	return $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'people' ) . ' WHERE id = %d', $id ) ) : null;
}

function pjb_person_roles( $person ) {
	return array_values( array_filter( explode( ',', (string) ( $person ? $person->roles : '' ) ) ) );
}

/**
 * The person with this email, made if there is none yet. $fields are already
 * cleaned. An existing person keeps the name the team has for them; empty
 * pronouns or address are filled in, and $role is added to their roles.
 * Without an email a new person is always made.
 *
 * @return int The person's id.
 */
function pjb_find_or_add_person( array $fields, $role = '', $when = 0 ) {
	global $wpdb;
	$table = pjb_t( 'people' );
	$now   = $when ? $when : time();
	$email = isset( $fields['email'] ) ? strtolower( (string) $fields['email'] ) : '';

	$person = '' === $email ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT 1", $email ) );

	if ( ! $person ) {
		$wpdb->insert(
			$table,
			array(
				'name'       => isset( $fields['name'] ) ? $fields['name'] : '',
				'pronouns'   => isset( $fields['pronouns'] ) ? $fields['pronouns'] : '',
				'email'      => $email,
				'phone'      => isset( $fields['phone'] ) ? $fields['phone'] : '',
				'address'    => isset( $fields['address'] ) ? $fields['address'] : '',
				'roles'      => $role,
				'contact_ok' => 1,
				'how_found'  => isset( $fields['how_found'] ) ? $fields['how_found'] : '',
				'notes'      => '',
				'created_ts' => $now,
				'updated_ts' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	$update = array( 'updated_ts' => max( $now, (int) $person->updated_ts ) );
	foreach ( array( 'pronouns', 'address', 'phone' ) as $key ) {
		if ( '' === (string) $person->$key && ! empty( $fields[ $key ] ) ) {
			$update[ $key ] = $fields[ $key ];
		}
	}
	$roles = pjb_person_roles( $person );
	if ( $role && ! in_array( $role, $roles, true ) ) {
		$roles[]         = $role;
		$update['roles'] = implode( ',', $roles );
	}
	$wpdb->update( $table, $update, array( 'id' => (int) $person->id ) );
	return (int) $person->id;
}

/** An Activity entry. */
function pjb_log( $person_id, $type, $note, $on = null ) {
	global $wpdb;
	$user = wp_get_current_user();
	$wpdb->insert(
		pjb_t( 'activity' ),
		array(
			'person_id'   => (int) $person_id,
			'happened_on' => $on ? $on : pjb_today(),
			'type'        => $type,
			'note'        => $note,
			'by_user'     => $user && $user->exists() ? $user->display_name : '',
			'created_ts'  => time(),
		)
	);
}

/**
 * Removes a person and everything that points to them. Used when someone
 * asks to be forgotten.
 */
function pjb_forget_person( $id ) {
	global $wpdb;
	foreach ( array( 'requests', 'shipments', 'donations', 'receipts', 'thanks', 'feedback', 'activity' ) as $name ) {
		$wpdb->delete( pjb_t( $name ), array( 'person_id' => (int) $id ), array( '%d' ) );
	}
	$wpdb->delete( pjb_t( 'people' ), array( 'id' => (int) $id ), array( '%d' ) );
}
