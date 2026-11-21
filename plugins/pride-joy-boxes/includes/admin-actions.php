<?php
/**
 * What the WP Admin forms post to (admin-post.php?action=...). Every handler
 * checks pjb_capability() and the nonce pjb_form() wrote for its action and
 * row, then goes back to the page the form was on.
 */

defined( 'ABSPATH' ) || exit;

/** Stops unless the user may do this and the form is the one we wrote. Returns the row id. */
function pjb_guard( $action ) {
	if ( ! current_user_can( pjb_capability() ) ) {
		wp_die( 'You are not allowed to change this.' );
	}
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	check_admin_referer( $action . '_' . $id );
	return $id;
}

/** Back to the page the form came from, with a message. */
function pjb_back( $notice, $url = '' ) {
	$url = $url ? $url : wp_get_referer();
	$url = $url ? remove_query_arg( 'pjb', $url ) : pjb_url( 'pride-joy-boxes' );
	wp_safe_redirect( add_query_arg( 'pjb', $notice, $url ) );
	exit;
}

function pjb_post( $key ) {
	return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
}

function pjb_post_key( $key, array $allowed, $fallback ) {
	$value = sanitize_key( pjb_post( $key ) );
	return isset( $allowed[ $value ] ) ? $value : $fallback;
}

/* -------------------------------------------------------------------------
 * Requests and shipments
 * ---------------------------------------------------------------------- */

function pjb_set_status() {
	global $wpdb;
	$id     = pjb_guard( 'pjb_set_status' );
	$status = pjb_post_key( 'status', pjb_statuses(), '' );
	$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'requests' ) . ' WHERE id = %d', $id ) );
	if ( $row && $status && $status !== $row->status ) {
		$wpdb->update( pjb_t( 'requests' ), array( 'status' => $status ), array( 'id' => $id ) );
		pjb_log( (int) $row->person_id, 'status', sprintf( 'Request %s: %s → %s', pjb_ref( 'R', $id ), pjb_x_label( pjb_statuses(), $row->status ), pjb_statuses()[ $status ] ) );
	}
	pjb_back( 'saved' );
}
add_action( 'admin_post_pjb_set_status', 'pjb_set_status' );

function pjb_add_shipment() {
	global $wpdb;
	$id  = pjb_guard( 'pjb_add_shipment' );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'requests' ) . ' WHERE id = %d', $id ) );
	if ( $row ) {
		$wpdb->insert(
			pjb_t( 'shipments' ),
			array(
				'request_id' => $id,
				'person_id'  => (int) $row->person_id,
				'sent_on'    => pjb_date( pjb_post( 'sent_on' ) ) ?: pjb_today(),
				'contents'   => pjb_block( pjb_post( 'contents' ), 2000 ),
				'cost'       => pjb_money( pjb_post( 'cost' ) ),
				'carrier'    => pjb_line( pjb_post( 'carrier' ), 60 ),
				'tracking'   => pjb_line( pjb_post( 'tracking' ), 100 ),
				'packed_by'  => pjb_line( pjb_post( 'packed_by' ), 100 ),
				'notes'      => '',
				'created_ts' => time(),
			)
		);
		if ( 'sent' !== $row->status ) {
			$wpdb->update( pjb_t( 'requests' ), array( 'status' => 'sent' ), array( 'id' => $id ) );
			pjb_log( (int) $row->person_id, 'status', sprintf( 'Request %s: %s → %s', pjb_ref( 'R', $id ), pjb_x_label( pjb_statuses(), $row->status ), pjb_statuses()['sent'] ) );
		}
	}
	pjb_back( 'shipped' );
}
add_action( 'admin_post_pjb_add_shipment', 'pjb_add_shipment' );

/* -------------------------------------------------------------------------
 * People
 * ---------------------------------------------------------------------- */

function pjb_save_person() {
	global $wpdb;
	$id    = pjb_guard( 'pjb_save_person' );
	$email = sanitize_email( pjb_post( 'email' ) );
	$roles = array();
	foreach ( isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array() as $role ) {
		if ( is_string( $role ) && isset( pjb_roles()[ $role ] ) ) {
			$roles[] = $role;
		}
	}
	$fields = array(
		'name'       => pjb_line( pjb_post( 'name' ), 100 ),
		'pronouns'   => pjb_line( pjb_post( 'pronouns' ), 60 ),
		'email'      => is_email( $email ) ? strtolower( $email ) : '',
		'phone'      => pjb_line( pjb_post( 'phone' ), 40 ),
		'address'    => pjb_block( pjb_post( 'address' ), 500 ),
		'roles'      => implode( ',', $roles ),
		'contact_ok' => empty( $_POST['contact_ok'] ) ? 0 : 1,
		'how_found'  => pjb_line( pjb_post( 'how_found' ), 190 ),
		'notes'      => pjb_block( pjb_post( 'notes' ), 4000 ),
		'updated_ts' => time(),
	);
	if ( '' === $fields['name'] && '' === $fields['email'] ) {
		pjb_back( 'need_name' );
	}

	if ( $id ) {
		$wpdb->update( pjb_t( 'people' ), $fields, array( 'id' => $id ) );
		pjb_back( 'saved' );
	}
	$fields['created_ts'] = time();
	$wpdb->insert( pjb_t( 'people' ), $fields );
	pjb_back( 'saved', pjb_url( 'pjb-people', array( 'person' => (int) $wpdb->insert_id ) ) );
}
add_action( 'admin_post_pjb_save_person', 'pjb_save_person' );

function pjb_forget_person_action() {
	$id = pjb_guard( 'pjb_forget_person' );
	if ( $id ) {
		pjb_forget_person( $id );
	}
	pjb_back( 'forgotten', pjb_url( 'pjb-people' ) );
}
add_action( 'admin_post_pjb_forget_person', 'pjb_forget_person_action' );

function pjb_add_activity() {
	$id   = pjb_guard( 'pjb_add_activity' );
	$note = pjb_block( pjb_post( 'note' ), 4000 );
	if ( $id && '' !== $note ) {
		pjb_log( $id, pjb_post_key( 'type', pjb_activity_types(), 'note' ), $note, pjb_date( pjb_post( 'happened_on' ) ) );
	}
	pjb_back( 'noted' );
}
add_action( 'admin_post_pjb_add_activity', 'pjb_add_activity' );

function pjb_add_thanks() {
	global $wpdb;
	$id  = pjb_guard( 'pjb_add_thanks' );
	$for = sanitize_key( pjb_post( 'for' ) );
	if ( $id ) {
		$wpdb->insert(
			pjb_t( 'thanks' ),
			array(
				'person_id'   => $id,
				'donation_id' => 'd' === substr( $for, 0, 1 ) ? absint( substr( $for, 1 ) ) : 0,
				'shipment_id' => 's' === substr( $for, 0, 1 ) ? absint( substr( $for, 1 ) ) : 0,
				'method'      => pjb_post_key( 'method', pjb_thanks_methods(), 'email' ),
				'sent_on'     => pjb_date( pjb_post( 'sent_on' ) ) ?: pjb_today(),
				'sent_by'     => pjb_line( pjb_post( 'sent_by' ), 100 ),
				'notes'       => pjb_line( pjb_post( 'notes' ), 500 ),
				'created_ts'  => time(),
			)
		);
	}
	pjb_back( 'thanked' );
}
add_action( 'admin_post_pjb_add_thanks', 'pjb_add_thanks' );

/* -------------------------------------------------------------------------
 * Donations and receipts
 * ---------------------------------------------------------------------- */

/** The donation fields both donation forms send. */
function pjb_donation_fields() {
	$status = pjb_post_key( 'status', pjb_donation_statuses(), 'received' );
	$on     = pjb_date( pjb_post( 'received_on' ) );
	return array(
		'category'       => pjb_post_key( 'category', pjb_categories(), 'items' ),
		'status'         => $status,
		'received_on'    => 'received' === $status ? ( $on ?: pjb_today() ) : $on,
		'description'    => pjb_block( pjb_post( 'description' ), 2000 ),
		'amount'         => pjb_money( pjb_post( 'amount' ) ),
		'value_estimate' => pjb_money( pjb_post( 'value_estimate' ) ),
		'method'         => pjb_post_key( 'method', pjb_methods(), '' ),
		'campaign'       => pjb_line( pjb_post( 'campaign' ), 100 ),
		'notes'          => pjb_block( pjb_post( 'notes' ), 2000 ),
	);
}

function pjb_add_donation() {
	global $wpdb;
	pjb_guard( 'pjb_add_donation' );
	$fields = pjb_donation_fields();
	$role   = pjb_category_role( $fields['category'] );

	$person = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;
	if ( $person && pjb_person( $person ) ) {
		pjb_add_role( $person, $role );
	} else {
		$name  = pjb_line( pjb_post( 'new_name' ), 100 );
		$email = sanitize_email( pjb_post( 'new_email' ) );
		$email = is_email( $email ) ? $email : '';
		if ( '' === $name && '' === $email ) {
			pjb_back( 'need_name' );
		}
		$person = pjb_find_or_add_person(
			array(
				'name'  => $name,
				'email' => $email,
			),
			$role
		);
	}

	$wpdb->insert( pjb_t( 'donations' ), array_merge( $fields, array( 'person_id' => $person, 'source' => 'staff', 'created_ts' => time() ) ) );
	pjb_back( 'saved' );
}
add_action( 'admin_post_pjb_add_donation', 'pjb_add_donation' );

function pjb_save_donation() {
	global $wpdb;
	$id = pjb_guard( 'pjb_save_donation' );
	$d  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'donations' ) . ' WHERE id = %d', $id ) );
	if ( $d ) {
		$fields = pjb_donation_fields();
		$wpdb->update( pjb_t( 'donations' ), $fields, array( 'id' => $id ) );
		if ( $fields['status'] !== $d->status ) {
			pjb_log( (int) $d->person_id, 'status', sprintf( 'Donation %s: %s → %s', pjb_ref( 'D', $id ), pjb_x_label( pjb_donation_statuses(), $d->status ), pjb_donation_statuses()[ $fields['status'] ] ) );
		}
		pjb_add_role( (int) $d->person_id, pjb_category_role( $fields['category'] ) );
	}
	pjb_back( 'saved' );
}
add_action( 'admin_post_pjb_save_donation', 'pjb_save_donation' );

/** Adds a role to a person who may have no email. */
function pjb_add_role( $person_id, $role ) {
	global $wpdb;
	$person = pjb_person( $person_id );
	if ( ! $person ) {
		return;
	}
	$roles = pjb_person_roles( $person );
	if ( ! in_array( $role, $roles, true ) ) {
		$roles[] = $role;
		$wpdb->update( pjb_t( 'people' ), array( 'roles' => implode( ',', $roles ), 'updated_ts' => time() ), array( 'id' => (int) $person_id ) );
	}
}

function pjb_issue_receipt_action() {
	$id      = pjb_guard( 'pjb_issue_receipt' );
	$receipt = pjb_issue_receipt( $id );
	if ( is_wp_error( $receipt ) ) {
		pjb_back( 'no_receipt' );
	}
	pjb_back( 'receipt', pjb_url( 'pjb-receipts', array( 'receipt' => $receipt ) ) );
}
add_action( 'admin_post_pjb_issue_receipt', 'pjb_issue_receipt_action' );

function pjb_receipt_sent() {
	global $wpdb;
	$id = pjb_guard( 'pjb_receipt_sent' );
	$wpdb->update(
		pjb_t( 'receipts' ),
		array(
			'sent_on'  => pjb_date( pjb_post( 'sent_on' ) ) ?: pjb_today(),
			'sent_via' => pjb_post_key( 'sent_via', pjb_thanks_methods(), 'email' ),
		),
		array( 'id' => $id )
	);
	pjb_back( 'saved' );
}
add_action( 'admin_post_pjb_receipt_sent', 'pjb_receipt_sent' );

function pjb_save_template() {
	pjb_guard( 'pjb_save_template' );
	$old      = pjb_receipt_settings();
	$template = trim( sanitize_textarea_field( pjb_post( 'template' ) ) );
	$email    = sanitize_email( pjb_post( 'contact_email' ) );
	$new      = array(
		'org_name'       => sanitize_text_field( pjb_post( 'org_name' ) ),
		'contact_email'  => is_email( $email ) ? $email : '',
		'tax_deductible' => empty( $_POST['tax_deductible'] ) ? 0 : 1,
		'template'       => '' === $template ? pjb_default_receipt_settings()['template'] : $template,
		'version'        => (int) $old['version'],
	);
	foreach ( array( 'org_name', 'contact_email', 'tax_deductible', 'template' ) as $key ) {
		if ( (string) $new[ $key ] !== (string) $old[ $key ] ) {
			$new['version'] = (int) $old['version'] + 1;
			break;
		}
	}
	update_option( 'pjb_receipt_settings', $new, false );
	pjb_back( 'saved' );
}
add_action( 'admin_post_pjb_save_template', 'pjb_save_template' );

/* -------------------------------------------------------------------------
 * Feedback
 * ---------------------------------------------------------------------- */

function pjb_feedback_reviewed() {
	global $wpdb;
	$id = pjb_guard( 'pjb_feedback_reviewed' );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . pjb_t( 'feedback' ) . ' SET reviewed = 1 - reviewed WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	pjb_back( 'saved' );
}
add_action( 'admin_post_pjb_feedback_reviewed', 'pjb_feedback_reviewed' );

/* -------------------------------------------------------------------------
 * Deleting one row
 * ---------------------------------------------------------------------- */

/** type => table, for the Delete buttons. */
function pjb_deletable() {
	return array(
		'request'  => 'requests',
		'shipment' => 'shipments',
		'donation' => 'donations',
		'receipt'  => 'receipts',
		'thanks'   => 'thanks',
		'feedback' => 'feedback',
		'activity' => 'activity',
	);
}

function pjb_delete_row() {
	global $wpdb;
	$type = isset( $_POST['action'] ) ? substr( sanitize_key( wp_unslash( $_POST['action'] ) ), strlen( 'pjb_delete_' ) ) : '';
	if ( ! isset( pjb_deletable()[ $type ] ) ) {
		wp_die( 'Unknown kind of record.' );
	}
	$id = pjb_guard( 'pjb_delete_' . $type );

	if ( 'donation' === $type && $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . pjb_t( 'receipts' ) . ' WHERE donation_id = %d', $id ) ) ) {
		pjb_back( 'has_receipt' );
	}
	$wpdb->delete( pjb_t( pjb_deletable()[ $type ] ), array( 'id' => $id ), array( '%d' ) );
	if ( 'request' === $type ) {
		$wpdb->delete( pjb_t( 'shipments' ), array( 'request_id' => $id ), array( '%d' ) );
	}

	// The detail page of a deleted donation or receipt is gone, so go to its list.
	$lists = array(
		'donation' => 'pjb-donations',
		'receipt'  => 'pjb-receipts',
	);
	pjb_back( 'deleted', isset( $lists[ $type ] ) ? pjb_url( $lists[ $type ] ) : '' );
}
foreach ( array_keys( pjb_deletable() ) as $pjb_type ) {
	add_action( 'admin_post_pjb_delete_' . $pjb_type, 'pjb_delete_row' );
}
unset( $pjb_type );

/* -------------------------------------------------------------------------
 * Excel download
 * ---------------------------------------------------------------------- */

function pjb_export() {
	if ( ! current_user_can( pjb_capability() ) ) {
		wp_die( 'You are not allowed to export this.' );
	}
	check_admin_referer( 'pjb_export' );
	if ( ! class_exists( 'ZipArchive' ) ) {
		pjb_back( 'no_zip' );
	}

	$path = wp_tempnam( 'pride-joy.xlsx' );
	if ( ! $path || ! pjb_x_write( $path, pjb_workbook_sheets() ) ) {
		wp_die( 'The Excel file could not be built. Try again.' );
	}

	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename="pride-joy-boxes-' . wp_date( 'Y-m-d' ) . '.xlsx"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_pjb_export', 'pjb_export' );
