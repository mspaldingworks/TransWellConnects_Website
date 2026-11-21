<?php
/**
 * Gift receipts.
 *
 * A receipt is written from a template the team can edit under Pride & Joy
 * Boxes > Receipt template. Each save of the template raises its version, and
 * each receipt keeps its own text and the version it was written from, so
 * changing the wording later (for example once there is a 501(c)(3) or a
 * fiscal sponsor) never changes a receipt already given.
 */

defined( 'ABSPATH' ) || exit;

function pjb_default_receipt_settings() {
	return array(
		'org_name'       => 'Transpire',
		'contact_email'  => 'transpire@transwellconnects.com',
		'tax_deductible' => 0,
		'version'        => 1,
		'template'       => "{org_name}\nPride and Joy Boxes\n\nReceipt {number}\nDate: {issued_on}\n\nThank you, {name}.\n\nWe received your gift on {received_on}:\n{gift}\n\n{org_name} is not a 501(c)(3) tax-exempt organization, so this gift is not tax-deductible as a charitable contribution. This receipt is for your records.\n\nWith love,\nThe Pride and Joy team\n{contact_email}",
	);
}

function pjb_receipt_settings() {
	$saved = get_option( 'pjb_receipt_settings' );
	return array_merge( pjb_default_receipt_settings(), is_array( $saved ) ? $saved : array() );
}

/** The words that stand for the gift on a receipt. An in-kind gift's value is the donor's own estimate, so it is said that way. */
function pjb_gift_words( $donation ) {
	$category = isset( pjb_categories()[ $donation->category ] ) ? pjb_categories()[ $donation->category ] : $donation->category;
	$methods  = pjb_methods();
	$method   = isset( $methods[ $donation->method ] ) && '' !== $donation->method ? $methods[ $donation->method ] : '';

	if ( 'money' === $donation->category && null !== $donation->amount ) {
		return '$' . number_format( (float) $donation->amount, 2 ) . ( $method ? ' (' . $method . ')' : '' );
	}
	$words = trim( pjb_plain( $donation->description ) );
	$words = '' === $words ? $category : $category . ': ' . $words;
	if ( null !== $donation->value_estimate ) {
		$words .= "\nYou estimated its value at $" . number_format( (float) $donation->value_estimate, 2 ) . '.';
	}
	return $words;
}

/** The next receipt number for this year, e.g. PJB-2026-0003. */
function pjb_next_receipt_number( $year ) {
	global $wpdb;
	$prefix = 'PJB-' . $year . '-';
	$last   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT number FROM ' . pjb_t( 'receipts' ) . ' WHERE number LIKE %s ORDER BY number DESC LIMIT 1', $wpdb->esc_like( $prefix ) . '%' ) );
	$n      = '' === $last ? 1 : 1 + (int) substr( $last, strlen( $prefix ) );
	return $prefix . sprintf( '%04d', $n );
}

/**
 * Writes and saves a receipt for a received donation.
 *
 * @return int|WP_Error The receipt id.
 */
function pjb_issue_receipt( $donation_id ) {
	global $wpdb;
	$donation = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'donations' ) . ' WHERE id = %d', $donation_id ) );
	if ( ! $donation ) {
		return new WP_Error( 'pjb_missing', 'That donation is gone.' );
	}
	if ( 'received' !== $donation->status || ! $donation->received_on ) {
		return new WP_Error( 'pjb_not_received', 'Mark the donation received, with the date, before writing a receipt.' );
	}

	$settings = pjb_receipt_settings();
	$person   = pjb_person( (int) $donation->person_id );
	$issued   = pjb_today();
	$number   = pjb_next_receipt_number( substr( $issued, 0, 4 ) );
	$fill     = array(
		'{org_name}'      => $settings['org_name'],
		'{contact_email}' => $settings['contact_email'],
		'{number}'        => $number,
		'{issued_on}'     => wp_date( 'F j, Y', strtotime( $issued . ' 12:00:00' ) ),
		'{received_on}'   => wp_date( 'F j, Y', strtotime( $donation->received_on . ' 12:00:00' ) ),
		'{name}'          => $person ? pjb_plain( $person->name ) : 'friend',
		'{gift}'          => pjb_gift_words( $donation ),
	);

	$saved = $wpdb->insert(
		pjb_t( 'receipts' ),
		array(
			'number'           => $number,
			'donation_id'      => (int) $donation->id,
			'person_id'        => (int) $donation->person_id,
			'issued_on'        => $issued,
			'amount'           => 'money' === $donation->category ? $donation->amount : $donation->value_estimate,
			'description'      => pjb_gift_words( $donation ),
			'tax_deductible'   => (int) $settings['tax_deductible'],
			'template_version' => (int) $settings['version'],
			'body'             => wp_encode_emoji( strtr( $settings['template'], $fill ) ),
			'created_ts'       => time(),
		)
	);
	if ( ! $saved ) {
		return new WP_Error( 'pjb_not_saved', 'The receipt could not be saved. Try again.' );
	}
	return (int) $wpdb->insert_id;
}
