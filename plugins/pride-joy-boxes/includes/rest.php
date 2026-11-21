<?php
/**
 * The three public routes the page and the app post to.
 *
 * Each one answers 201 {ok:true} when saved, 400 {ok:false, code} when a
 * required field is missing, and 429 {ok:false, code:'limit'} after too many
 * posts from one network address. Answers never say whether an email is
 * already known.
 */

defined( 'ABSPATH' ) || exit;

function pjb_register_routes() {
	foreach ( array( 'requests', 'offers', 'feedback' ) as $route ) {
		register_rest_route(
			'pride-joy/v1',
			'/' . $route,
			array(
				'methods'             => 'POST',
				'callback'            => 'pjb_rest_' . $route,
				'permission_callback' => '__return_true',
			)
		);
	}
}
add_action( 'rest_api_init', 'pjb_register_routes' );

function pjb_response( $data, $status = 200 ) {
	do_action( 'litespeed_control_set_nocache', 'pride and joy forms are never cached' );
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	return $response;
}

function pjb_refuse( $code, $status = 400 ) {
	return pjb_response(
		array(
			'ok'   => false,
			'code' => $code,
		),
		$status
	);
}

/** True when the hidden field people never see was filled in, which marks a bot. */
function pjb_is_bot( WP_REST_Request $request ) {
	return '' !== trim( (string) $request->get_param( 'website' ) );
}

function pjb_source_of( WP_REST_Request $request ) {
	return 'app' === $request->get_param( 'source' ) ? 'app' : 'web';
}

function pjb_email_of( WP_REST_Request $request ) {
	$email = sanitize_email( mb_substr( (string) $request->get_param( 'email' ), 0, 190 ) );
	return is_email( $email ) ? strtolower( $email ) : '';
}

/* -------------------------------------------------------------------------
 * Box requests
 * ---------------------------------------------------------------------- */

function pjb_rest_requests( WP_REST_Request $request ) {
	global $wpdb;

	if ( pjb_is_bot( $request ) ) {
		return pjb_response( array( 'ok' => true ), 201 );
	}

	$name  = pjb_line( $request->get_param( 'name' ), 100 );
	$email = pjb_email_of( $request );
	if ( '' === $name ) {
		return pjb_refuse( 'name' );
	}
	if ( '' === $email ) {
		return pjb_refuse( 'email' );
	}

	$kind = (string) $request->get_param( 'kind' );
	if ( ! isset( pjb_kinds()[ $kind ] ) ) {
		$kind = 'free';
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

	if ( pjb_throttled( 'requests', 20, HOUR_IN_SECONDS ) ) {
		return pjb_refuse( 'limit', 429 );
	}

	$pronouns = pjb_line( $request->get_param( 'pronouns' ), 60 );
	$address  = pjb_block( $request->get_param( 'address' ), 500 );
	$source   = pjb_source_of( $request );
	$person   = pjb_find_or_add_person(
		array(
			'name'     => $name,
			'pronouns' => $pronouns,
			'email'    => $email,
			'address'  => $address,
		),
		'requester'
	);

	$saved = $wpdb->insert(
		pjb_t( 'requests' ),
		array(
			'person_id'  => $person,
			'kind'       => $kind,
			'name'       => $name,
			'pronouns'   => $pronouns,
			'email'      => $email,
			'address'    => $address,
			'joy'        => wp_json_encode( $joy ),
			'faves'      => pjb_line( $request->get_param( 'faves' ), 300 ),
			'items'      => wp_json_encode( $items ),
			'plain'      => rest_sanitize_boolean( $request->get_param( 'plain' ) ) ? 1 : 0,
			'notes'      => pjb_block( $request->get_param( 'notes' ), 2000 ),
			'source'     => $source,
			'status'     => 'new',
			'created_ts' => time(),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
	);
	if ( ! $saved ) {
		return pjb_refuse( 'not_saved', 500 );
	}

	$id = (int) $wpdb->insert_id;
	pjb_notify(
		sprintf( 'New Pride and Joy Box request (%s)', pjb_ref( 'R', $id ) ),
		sprintf( "A new Pride and Joy Box request came in.\n\nBox: %s\nSent from: %s", pjb_kinds()[ $kind ], pjb_sources()[ $source ] ),
		'pride-joy-boxes'
	);
	return pjb_response( array( 'ok' => true ), 201 );
}

/* -------------------------------------------------------------------------
 * Offers to help fill a box
 * ---------------------------------------------------------------------- */

function pjb_rest_offers( WP_REST_Request $request ) {
	global $wpdb;

	if ( pjb_is_bot( $request ) ) {
		return pjb_response( array( 'ok' => true ), 201 );
	}

	$name  = pjb_line( $request->get_param( 'name' ), 100 );
	$email = pjb_email_of( $request );
	if ( '' === $name ) {
		return pjb_refuse( 'name' );
	}
	if ( '' === $email ) {
		return pjb_refuse( 'email' );
	}

	$categories = array();
	$given      = $request->get_param( 'gives' );
	foreach ( is_array( $given ) ? $given : array() as $category ) {
		$category = is_string( $category ) ? $category : '';
		if ( isset( pjb_categories()[ $category ] ) && ! in_array( $category, $categories, true ) ) {
			$categories[] = $category;
		}
	}
	if ( ! $categories ) {
		return pjb_refuse( 'gives' );
	}

	if ( pjb_throttled( 'offers', 20, HOUR_IN_SECONDS ) ) {
		return pjb_refuse( 'limit', 429 );
	}

	$source  = pjb_source_of( $request );
	$details = pjb_block( $request->get_param( 'details' ), 2000 );
	$person  = 0;
	foreach ( $categories as $category ) {
		$person = pjb_find_or_add_person(
			array(
				'name'     => $name,
				'pronouns' => pjb_line( $request->get_param( 'pronouns' ), 60 ),
				'email'    => $email,
			),
			pjb_category_role( $category )
		);
		$wpdb->insert(
			pjb_t( 'donations' ),
			array(
				'person_id'   => $person,
				'category'    => $category,
				'status'      => 'offered',
				'description' => $details,
				'source'      => $source,
				'notes'       => '',
				'created_ts'  => time(),
			)
		);
	}

	$labels = array_map(
		function ( $category ) {
			return pjb_categories()[ $category ];
		},
		$categories
	);
	pjb_notify(
		'New offer to help fill Pride and Joy Boxes',
		sprintf( "Someone offered to help fill boxes.\n\nOffering: %s\nSent from: %s", implode( ', ', $labels ), pjb_sources()[ $source ] ),
		'pjb-donations'
	);
	return pjb_response( array( 'ok' => true ), 201 );
}

/* -------------------------------------------------------------------------
 * Feedback: "How was your box?"
 * ---------------------------------------------------------------------- */

function pjb_rest_feedback( WP_REST_Request $request ) {
	global $wpdb;

	if ( pjb_is_bot( $request ) ) {
		return pjb_response( array( 'ok' => true ), 201 );
	}

	$rating = absint( $request->get_param( 'rating' ) );
	$rating = $rating >= 1 && $rating <= 5 ? $rating : null;
	$loved  = pjb_block( $request->get_param( 'loved' ), 2000 );
	$wish   = pjb_block( $request->get_param( 'wish' ), 2000 );
	if ( null === $rating && '' === $loved && '' === $wish ) {
		return pjb_refuse( 'empty' );
	}

	if ( pjb_throttled( 'feedback', 20, HOUR_IN_SECONDS ) ) {
		return pjb_refuse( 'limit', 429 );
	}

	// Name and email are optional. With an email, the feedback joins that person's record and their latest request.
	$email      = pjb_email_of( $request );
	$person     = 0;
	$request_id = 0;
	if ( '' !== $email ) {
		$person     = pjb_find_or_add_person(
			array(
				'name'  => pjb_line( $request->get_param( 'name' ), 100 ),
				'email' => $email,
			)
		);
		$request_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . pjb_t( 'requests' ) . ' WHERE person_id = %d ORDER BY id DESC LIMIT 1', $person ) );
	}

	$quote_ok = rest_sanitize_boolean( $request->get_param( 'quote_ok' ) );
	$wpdb->insert(
		pjb_t( 'feedback' ),
		array(
			'person_id'  => $person,
			'request_id' => $request_id,
			'rating'     => $rating,
			'loved'      => $loved,
			'wish'       => $wish,
			'quote_ok'   => $quote_ok ? 1 : 0,
			'credit_as'  => $quote_ok ? pjb_line( $request->get_param( 'credit_as' ), 100 ) : '',
			'source'     => pjb_source_of( $request ),
			'reviewed'   => 0,
			'created_ts' => time(),
		)
	);

	pjb_notify(
		'New Pride and Joy Box feedback',
		null === $rating ? 'Someone sent feedback about their box.' : sprintf( 'Someone sent feedback about their box, rating it %d of 5.', $rating ),
		'pjb-feedback'
	);
	return pjb_response( array( 'ok' => true ), 201 );
}

/**
 * Tells the team something came in. The email carries no name, address or
 * sizes: those stay in WP Admin, behind the login.
 */
function pjb_notify( $subject, $text, $page ) {
	$to = pjb_notify_email();
	if ( ! $to ) {
		return;
	}
	wp_mail( $to, $subject, $text . "\n\nRead it in WP Admin:\n" . admin_url( 'admin.php?page=' . $page ) . "\n" );
}
