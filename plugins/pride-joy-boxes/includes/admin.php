<?php
/**
 * WP Admin screens for Pride and Joy Boxes: Requests, People, Donations,
 * Feedback, Receipts and the receipt template, each with the Excel download.
 * Everything here needs pjb_capability() (site administrators by default).
 * The forms post to the handlers in admin-actions.php.
 */

defined( 'ABSPATH' ) || exit;

function pjb_admin_menu() {
	$cap = pjb_capability();
	add_menu_page( 'Pride & Joy Boxes', 'Pride & Joy Boxes', $cap, 'pride-joy-boxes', 'pjb_requests_page', 'dashicons-heart', 59 );
	add_submenu_page( 'pride-joy-boxes', 'Requests', 'Requests', $cap, 'pride-joy-boxes', 'pjb_requests_page' );
	add_submenu_page( 'pride-joy-boxes', 'People', 'People', $cap, 'pjb-people', 'pjb_people_page' );
	add_submenu_page( 'pride-joy-boxes', 'Donations', 'Donations', $cap, 'pjb-donations', 'pjb_donations_page' );
	add_submenu_page( 'pride-joy-boxes', 'Feedback', 'Feedback', $cap, 'pjb-feedback', 'pjb_feedback_page' );
	add_submenu_page( 'pride-joy-boxes', 'Receipts', 'Receipts', $cap, 'pjb-receipts', 'pjb_receipts_page' );
	add_submenu_page( 'pride-joy-boxes', 'Receipt template', 'Receipt template', $cap, 'pjb-template', 'pjb_template_page' );
}
add_action( 'admin_menu', 'pjb_admin_menu' );

function pjb_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( pjb_url( 'pride-joy-boxes' ) ) . '">Requests</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( PJB_FILE ), 'pjb_action_links' );

/* -------------------------------------------------------------------------
 * Small pieces shared by the screens
 * ---------------------------------------------------------------------- */

function pjb_url( $page, $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
}

/** Kept for 1.0 callers. */
function pjb_requests_url( $args = array() ) {
	return pjb_url( 'pride-joy-boxes', $args );
}

function pjb_query_id( $key ) {
	return isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
}

function pjb_query_key( $key, array $allowed ) {
	$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	return isset( $allowed[ $value ] ) ? $value : '';
}

function pjb_notices() {
	return array(
		'saved'      => 'Saved.',
		'deleted'    => 'Deleted.',
		'shipped'    => 'Box recorded as sent.',
		'thanked'    => 'Thank-you recorded.',
		'noted'      => 'Added to the activity log.',
		'receipt'    => 'Receipt written. Send it, then mark it sent.',
		'forgotten'  => 'That person and everything about them are gone.',
		'no_receipt' => 'Mark the donation received, with the date, before writing a receipt.',
		'has_receipt' => 'This donation has a receipt. Delete the receipt first if you really mean to delete the gift.',
		'no_zip'     => 'This server cannot build Excel files (PHP ZipArchive is missing). Ask Hostinger support to turn on the PHP zip extension.',
		'need_name'  => 'Add a name or an email.',
	);
}

/** Page title, the Excel download, and the message from the last action. */
function pjb_admin_open( $title, $intro = '' ) {
	$notice = isset( $_GET['pjb'] ) ? sanitize_key( wp_unslash( $_GET['pjb'] ) ) : '';
	$all    = pjb_notices();
	$bad    = in_array( $notice, array( 'no_receipt', 'has_receipt', 'no_zip', 'need_name' ), true );
	?>
	<div class="wrap pjb-wrap">
		<style>
			.pjb-wrap .pjb-top { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin:8px 0 12px; }
			.pjb-wrap h2 { margin-top:28px; }
			.pjb-wrap details.pjb-add { background:#fff; border:1px solid #c3c4c7; padding:10px 14px; margin:12px 0; max-width:900px; }
			.pjb-wrap details.pjb-add summary { cursor:pointer; font-weight:600; }
			.pjb-wrap .pjb-fields { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px 16px; margin:12px 0; }
			.pjb-wrap .pjb-fields label { display:flex; flex-direction:column; gap:3px; font-weight:600; }
			.pjb-wrap .pjb-fields .wide { grid-column:1 / -1; }
			.pjb-wrap .pjb-fields input[type=text], .pjb-wrap .pjb-fields input[type=email], .pjb-wrap .pjb-fields input[type=date], .pjb-wrap .pjb-fields select, .pjb-wrap .pjb-fields textarea { width:100%; max-width:none; }
			.pjb-wrap .pjb-inline { display:inline; }
			.pjb-wrap td .description { display:block; }
			.pjb-wrap .pjb-receipt { white-space:pre-wrap; background:#fff; border:1px solid #c3c4c7; padding:24px 28px; max-width:640px; font:15px/1.6 Georgia, serif; }
			@media print { #adminmenumain, #wpadminbar, .pjb-noprint, .notice, #wpfooter { display:none !important; } #wpcontent { margin:0 !important; } .pjb-receipt { border:none; } }
		</style>
		<div class="pjb-top pjb-noprint">
			<h1 style="margin:0"><?php echo esc_html( $title ); ?></h1>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pjb_export' ), 'pjb_export' ) ); ?>">Download Excel</a>
		</div>
		<?php if ( $intro ) : ?>
			<p class="pjb-noprint" style="max-width:900px"><?php echo esc_html( $intro ); ?></p>
		<?php endif; ?>
		<?php if ( isset( $all[ $notice ] ) ) : ?>
			<div class="notice <?php echo $bad ? 'notice-error' : 'notice-success'; ?> is-dismissible pjb-noprint"><p><?php echo esc_html( $all[ $notice ] ); ?></p></div>
		<?php endif; ?>
	<?php
}

function pjb_admin_close() {
	echo '</div>';
}

/** Opens a form to an admin-post handler. Each handler checks the nonce named after its action (plus the row id). */
function pjb_form( $action, $id = 0, $attrs = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $attrs is fixed text from this file.
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	echo '<input type="hidden" name="id" value="' . (int) $id . '">';
	wp_nonce_field( $action . '_' . (int) $id );
}

function pjb_select( $name, array $options, $selected = '', $id = '' ) {
	echo '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
	foreach ( $options as $key => $label ) {
		echo '<option value="' . esc_attr( $key ) . '"' . selected( (string) $selected, (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
}

/** A small Delete button for one row of $type (see pjb_deletable()). */
function pjb_delete_button( $type, $id, $question ) {
	pjb_form( 'pjb_delete_' . $type, $id, 'class="pjb-inline" onsubmit="return confirm(' . esc_attr( wp_json_encode( $question ) ) . ');"' );
	echo '<button type="submit" class="button-link button-link-delete">Delete</button></form>';
}

function pjb_person_link( $id ) {
	$person = pjb_person( (int) $id );
	if ( ! $person ) {
		return '<span class="description">No one linked</span>';
	}
	$name = '' === $person->name ? $person->email : pjb_plain( $person->name );
	return '<a href="' . esc_url( pjb_url( 'pjb-people', array( 'person' => (int) $id ) ) ) . '">' . esc_html( '' === $name ? pjb_ref( 'C', $id ) : $name ) . '</a> <span class="description">' . esc_html( pjb_ref( 'C', $id ) ) . '</span>';
}

function pjb_show_date( $ymd ) {
	return $ymd ? wp_date( 'M j, Y', strtotime( $ymd . ' 12:00:00' ) ) : '';
}

function pjb_show_time( $ts ) {
	return wp_date( 'M j, Y g:i a', (int) $ts );
}

function pjb_show_money( $amount ) {
	return null === $amount ? '' : '$' . number_format( (float) $amount, 2 );
}

/** A request's affirming items as "Label: detail" lines. */
function pjb_item_lines( $row ) {
	$lines = array();
	$items = json_decode( (string) $row->items, true );
	foreach ( is_array( $items ) ? $items : array() as $item ) {
		$label = isset( $item['label'] ) ? pjb_plain( $item['label'] ) : '';
		if ( '' === $label ) {
			continue;
		}
		$detail  = isset( $item['detail'] ) ? pjb_plain( $item['detail'] ) : '';
		$lines[] = '' === $detail ? $label : $label . ': ' . $detail;
	}
	return $lines;
}

function pjb_joy_picks( $row ) {
	$joy = json_decode( (string) $row->joy, true );
	return array_map( 'pjb_plain', is_array( $joy ) ? $joy : array() );
}

function pjb_rows( $table, $where = '', $args = array(), $order = 'id DESC' ) {
	global $wpdb;
	$sql = 'SELECT * FROM ' . pjb_t( $table ) . ( $where ? ' WHERE ' . $where : '' ) . ' ORDER BY ' . $order;
	return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL -- table names and clauses come from this file.
}

function pjb_status_counts( $table, $column = 'status' ) {
	global $wpdb;
	$counts = array();
	foreach ( (array) $wpdb->get_results( "SELECT {$column} AS k, COUNT(*) AS n FROM " . pjb_t( $table ) . " GROUP BY {$column}" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$counts[ $row->k ] = (int) $row->n;
	}
	return $counts;
}

/** The All | New (3) | ... links above a list. */
function pjb_filter_links( $page, array $labels, array $counts, $current ) {
	echo '<ul class="subsubsub">';
	echo '<li><a href="' . esc_url( pjb_url( $page ) ) . '"' . ( '' === $current ? ' class="current"' : '' ) . '>All <span class="count">(' . (int) array_sum( $counts ) . ')</span></a></li>';
	foreach ( $labels as $key => $label ) {
		echo '<li> | <a href="' . esc_url( pjb_url( $page, array( 'status' => $key ) ) ) . '"' . ( $current === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . ( isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0 ) . ')</span></a></li>';
	}
	echo '</ul><div style="clear:both"></div>';
}

/** Choose someone already listed, or type a new person's name and email. */
function pjb_person_fields( $selected = 0 ) {
	$people = pjb_rows( 'people', '', array(), 'name ASC' );
	$opts   = array( '0' => '— someone new (fill in below) —' );
	foreach ( $people as $p ) {
		$opts[ (string) $p->id ] = trim( pjb_plain( $p->name ) . ( '' === $p->email ? '' : ' <' . $p->email . '>' ) ) . ' (' . pjb_ref( 'C', $p->id ) . ')';
	}
	if ( $selected ) {
		echo '<input type="hidden" name="person_id" value="' . (int) $selected . '">';
		return;
	}
	echo '<label class="wide">Who';
	pjb_select( 'person_id', $opts, '0' );
	echo '</label>';
	echo '<label>New person&#8217;s name<input type="text" name="new_name"></label>';
	echo '<label>New person&#8217;s email<input type="email" name="new_email"></label>';
}

/* -------------------------------------------------------------------------
 * Requests
 * ---------------------------------------------------------------------- */

function pjb_requests_page() {
	if ( ! current_user_can( pjb_capability() ) ) {
		return;
	}

	$statuses = pjb_statuses();
	$filter   = pjb_query_key( 'status', $statuses );
	$rows     = '' === $filter ? pjb_rows( 'requests' ) : pjb_rows( 'requests', 'status = %s', array( $filter ) );
	$kinds    = pjb_kinds();
	$sources  = pjb_sources();

	$shipped = array();
	foreach ( pjb_rows( 'shipments', '', array(), 'sent_on ASC, id ASC' ) as $s ) {
		$shipped[ (int) $s->request_id ][] = $s;
	}

	pjb_admin_open( 'Pride & Joy Boxes: requests', 'Box requests from the website form and from the TransWell app, newest first. Record a box as sent to keep the shipment, cost and tracking.' );
	pjb_filter_links( 'pride-joy-boxes', $statuses, pjb_status_counts( 'requests' ), $filter );
	?>
	<?php if ( empty( $rows ) ) : ?>
		<p><em>No requests here yet.</em></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col">Received</th>
				<th scope="col">Who</th>
				<th scope="col">Box</th>
				<th scope="col">Request</th>
				<th scope="col">Status</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$joy     = pjb_joy_picks( $row );
			$items   = pjb_item_lines( $row );
			$address = pjb_plain( $row->address );
			$notes   = pjb_plain( $row->notes );
			$faves   = pjb_plain( $row->faves );
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( pjb_ref( 'R', $row->id ) ); ?></strong><br>
					<?php echo esc_html( pjb_show_time( $row->created_ts ) ); ?>
					<span class="description"><?php echo esc_html( isset( $sources[ $row->source ] ) ? $sources[ $row->source ] : $row->source ); ?></span>
				</td>
				<td>
					<strong><?php echo esc_html( pjb_plain( $row->name ) ); ?></strong>
					<?php if ( '' !== $row->pronouns ) : ?>
						(<?php echo esc_html( pjb_plain( $row->pronouns ) ); ?>)
					<?php endif; ?>
					<br><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a>
					<?php if ( '' !== $address ) : ?>
						<br><?php echo nl2br( esc_html( $address ) ); ?>
					<?php else : ?>
						<span class="description">No address yet</span>
					<?php endif; ?>
					<?php if ( $row->person_id ) : ?>
						<br><a href="<?php echo esc_url( pjb_url( 'pjb-people', array( 'person' => (int) $row->person_id ) ) ); ?>">Their record (<?php echo esc_html( pjb_ref( 'C', $row->person_id ) ); ?>)</a>
					<?php endif; ?>
				</td>
				<td>
					<?php echo esc_html( isset( $kinds[ $row->kind ] ) ? $kinds[ $row->kind ] : $row->kind ); ?>
					<?php if ( $row->plain ) : ?>
						<br><strong>Plain outer box</strong>
					<?php endif; ?>
				</td>
				<td>
					<strong>Joy:</strong> <?php echo esc_html( $joy ? implode( ', ', $joy ) : 'Surprise me' ); ?>
					<?php if ( '' !== $faves ) : ?>
						<br><strong>Favorites:</strong> <?php echo esc_html( $faves ); ?>
					<?php endif; ?>
					<br><strong>Affirming items:</strong>
					<?php if ( $items ) : ?>
						<br><?php echo nl2br( esc_html( implode( "\n", $items ) ) ); ?>
					<?php else : ?>
						None this time
					<?php endif; ?>
					<?php if ( '' !== $notes ) : ?>
						<br><strong>Notes:</strong> <?php echo nl2br( esc_html( $notes ) ); ?>
					<?php endif; ?>
					<?php foreach ( isset( $shipped[ (int) $row->id ] ) ? $shipped[ (int) $row->id ] : array() as $s ) : ?>
						<br><strong>Sent <?php echo esc_html( pjb_show_date( $s->sent_on ) ); ?></strong>
						<?php echo esc_html( trim( pjb_plain( $s->carrier ) . ' ' . $s->tracking ) ); ?>
						<span class="description"><?php echo esc_html( pjb_ref( 'S', $s->id ) ); ?></span>
					<?php endforeach; ?>
				</td>
				<td>
					<?php pjb_form( 'pjb_set_status', $row->id ); ?>
						<label class="screen-reader-text" for="pjb-status-<?php echo (int) $row->id; ?>">Status</label>
						<?php pjb_select( 'status', $statuses, $row->status, 'pjb-status-' . (int) $row->id ); ?>
						<button type="submit" class="button button-small">Save</button>
					</form>
					<details style="margin-top:8px">
						<summary>Record box sent</summary>
						<?php pjb_form( 'pjb_add_shipment', $row->id ); ?>
							<div class="pjb-fields" style="grid-template-columns:1fr">
								<label>Sent on<input type="date" name="sent_on" value="<?php echo esc_attr( pjb_today() ); ?>"></label>
								<label>What went in<textarea name="contents" rows="2"></textarea></label>
								<label>Cost (postage and items)<input type="text" name="cost" inputmode="decimal"></label>
								<label>Carrier<input type="text" name="carrier" placeholder="USPS"></label>
								<label>Tracking number<input type="text" name="tracking"></label>
								<label>Packed by<input type="text" name="packed_by"></label>
							</div>
							<button type="submit" class="button button-small">Save shipment</button>
						</form>
					</details>
					<p><?php pjb_delete_button( 'request', $row->id, 'Delete this request for good?' ); ?></p>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<?php
	pjb_admin_close();
}

/* -------------------------------------------------------------------------
 * People
 * ---------------------------------------------------------------------- */

function pjb_people_page() {
	if ( ! current_user_can( pjb_capability() ) ) {
		return;
	}
	$id = pjb_query_id( 'person' );
	if ( $id ) {
		pjb_person_page( $id );
		return;
	}

	$roles  = pjb_roles();
	$role   = pjb_query_key( 'role', $roles );
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$people = pjb_rows( 'people', '', array(), 'name ASC, id ASC' );

	$people = array_filter(
		$people,
		function ( $p ) use ( $role, $search ) {
			if ( $role && ! in_array( $role, pjb_person_roles( $p ), true ) ) {
				return false;
			}
			return '' === $search || false !== stripos( pjb_plain( $p->name ) . ' ' . $p->email, $search );
		}
	);

	pjb_admin_open( 'Pride & Joy Boxes: people', 'Everyone in one list: people who asked for a box, donors, artists, note writers and volunteers. Open a person to see and add everything about them.' );
	?>
	<form method="get" class="pjb-top">
		<input type="hidden" name="page" value="pjb-people">
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Name or email">
		<?php pjb_select( 'role', array_merge( array( '' => 'Any role' ), $roles ), $role ); ?>
		<button class="button">Find</button>
	</form>

	<details class="pjb-add">
		<summary>Add a person</summary>
		<?php pjb_form( 'pjb_save_person' ); ?>
			<?php pjb_person_edit_fields( null ); ?>
			<button type="submit" class="button button-primary">Add person</button>
		</form>
	</details>

	<?php if ( empty( $people ) ) : ?>
		<p><em>No one here yet.</em></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead><tr><th>Person</th><th>Email</th><th>Roles</th><th>Contact OK</th><th>Since</th></tr></thead>
		<tbody>
		<?php foreach ( $people as $p ) : ?>
			<tr>
				<td><?php echo pjb_person_link( $p->id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
					<?php if ( '' !== $p->pronouns ) : ?>
						<span class="description"><?php echo esc_html( pjb_plain( $p->pronouns ) ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $p->email ); ?></td>
				<td><?php echo esc_html( implode( ', ', array_map( function ( $r ) use ( $roles ) { return isset( $roles[ $r ] ) ? $roles[ $r ] : $r; }, pjb_person_roles( $p ) ) ) ); ?></td>
				<td><?php echo $p->contact_ok ? 'Yes' : '<strong>No</strong>'; ?></td>
				<td><?php echo esc_html( wp_date( 'M j, Y', (int) $p->created_ts ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<?php
	pjb_admin_close();
}

function pjb_person_edit_fields( $p ) {
	$roles = pjb_roles();
	$has   = pjb_person_roles( $p );
	?>
	<div class="pjb-fields">
		<label>Name<input type="text" name="name" value="<?php echo esc_attr( $p ? pjb_plain( $p->name ) : '' ); ?>"></label>
		<label>Pronouns<input type="text" name="pronouns" value="<?php echo esc_attr( $p ? pjb_plain( $p->pronouns ) : '' ); ?>"></label>
		<label>Email<input type="email" name="email" value="<?php echo esc_attr( $p ? $p->email : '' ); ?>"></label>
		<label>Phone<input type="text" name="phone" value="<?php echo esc_attr( $p ? $p->phone : '' ); ?>"></label>
		<label class="wide">Mailing address<textarea name="address" rows="3"><?php echo esc_textarea( $p ? pjb_plain( $p->address ) : '' ); ?></textarea></label>
		<fieldset class="wide"><legend><strong>Roles</strong></legend>
			<?php foreach ( $roles as $key => $label ) : ?>
				<label style="display:inline-flex;flex-direction:row;gap:4px;margin-right:14px;font-weight:400"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( $key, $has, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<label>How they found us<input type="text" name="how_found" value="<?php echo esc_attr( $p ? pjb_plain( $p->how_found ) : '' ); ?>"></label>
		<label style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="contact_ok" value="1"<?php checked( ! $p || $p->contact_ok ); ?>> OK to contact them</label>
		<label class="wide">Notes<textarea name="notes" rows="3"><?php echo esc_textarea( $p ? pjb_plain( $p->notes ) : '' ); ?></textarea></label>
	</div>
	<?php
}

function pjb_person_page( $id ) {
	$p = pjb_person( $id );
	if ( ! $p ) {
		pjb_admin_open( 'Person not found' );
		echo '<p><a href="' . esc_url( pjb_url( 'pjb-people' ) ) . '">Back to people</a></p>';
		pjb_admin_close();
		return;
	}

	$requests  = pjb_rows( 'requests', 'person_id = %d', array( $id ) );
	$shipments = pjb_rows( 'shipments', 'person_id = %d', array( $id ) );
	$donations = pjb_rows( 'donations', 'person_id = %d', array( $id ) );
	$thanks    = pjb_rows( 'thanks', 'person_id = %d', array( $id ) );
	$feedback  = pjb_rows( 'feedback', 'person_id = %d', array( $id ) );
	$activity  = pjb_rows( 'activity', 'person_id = %d', array( $id ), 'happened_on DESC, id DESC' );
	$receipts  = array();
	foreach ( pjb_rows( 'receipts', 'person_id = %d', array( $id ) ) as $rc ) {
		$receipts[ (int) $rc->donation_id ] = $rc;
	}

	$title = '' === $p->name ? pjb_ref( 'C', $id ) : pjb_plain( $p->name ) . ' (' . pjb_ref( 'C', $id ) . ')';
	pjb_admin_open( $title );
	?>
	<p><a href="<?php echo esc_url( pjb_url( 'pjb-people' ) ); ?>">&larr; All people</a></p>

	<details class="pjb-add" open>
		<summary>Details</summary>
		<?php pjb_form( 'pjb_save_person', $id ); ?>
			<?php pjb_person_edit_fields( $p ); ?>
			<button type="submit" class="button button-primary">Save</button>
		</form>
	</details>

	<h2>Box requests</h2>
	<?php if ( ! $requests ) : ?>
		<p class="description">None.</p>
	<?php else : ?>
		<table class="widefat striped"><thead><tr><th>Request</th><th>Received</th><th>Box</th><th>Status</th></tr></thead><tbody>
		<?php foreach ( $requests as $r ) : ?>
			<tr><td><?php echo esc_html( pjb_ref( 'R', $r->id ) ); ?></td><td><?php echo esc_html( pjb_show_time( $r->created_ts ) ); ?></td><td><?php echo esc_html( pjb_x_label( pjb_kinds(), $r->kind ) ); ?></td><td><?php echo esc_html( pjb_x_label( pjb_statuses(), $r->status ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<p><a href="<?php echo esc_url( pjb_url( 'pride-joy-boxes' ) ); ?>">Open the requests list</a> to see what they asked for or record a box sent.</p>
	<?php endif; ?>

	<h2>Boxes sent</h2>
	<?php if ( ! $shipments ) : ?>
		<p class="description">None.</p>
	<?php else : ?>
		<table class="widefat striped"><thead><tr><th>Shipment</th><th>Sent</th><th>What went in</th><th>Cost</th><th>Tracking</th><th></th></tr></thead><tbody>
		<?php foreach ( $shipments as $s ) : ?>
			<tr><td><?php echo esc_html( pjb_ref( 'S', $s->id ) . ' for ' . pjb_ref( 'R', $s->request_id ) ); ?></td><td><?php echo esc_html( pjb_show_date( $s->sent_on ) ); ?></td><td><?php echo nl2br( esc_html( pjb_plain( $s->contents ) ) ); ?></td><td><?php echo esc_html( pjb_show_money( $s->cost ) ); ?></td><td><?php echo esc_html( trim( pjb_plain( $s->carrier ) . ' ' . $s->tracking ) ); ?></td><td><?php pjb_delete_button( 'shipment', $s->id, 'Delete this shipment record?' ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>

	<h2>Donations and offers</h2>
	<?php pjb_donation_table( $donations, $receipts, false ); ?>
	<details class="pjb-add">
		<summary>Add a donation from <?php echo esc_html( '' === $p->name ? 'this person' : pjb_plain( $p->name ) ); ?></summary>
		<?php pjb_donation_form( $id ); ?>
	</details>

	<h2>Thank-you notes</h2>
	<?php if ( ! $thanks ) : ?>
		<p class="description">None yet.</p>
	<?php else : ?>
		<table class="widefat striped"><thead><tr><th>Sent</th><th>How</th><th>For</th><th>By</th><th>Notes</th><th></th></tr></thead><tbody>
		<?php foreach ( $thanks as $t ) : ?>
			<tr><td><?php echo esc_html( pjb_show_date( $t->sent_on ) ); ?></td><td><?php echo esc_html( pjb_x_label( pjb_thanks_methods(), $t->method ) ); ?></td><td><?php echo esc_html( trim( pjb_ref( 'D', $t->donation_id ) . ' ' . pjb_ref( 'S', $t->shipment_id ) ) ); ?></td><td><?php echo esc_html( pjb_plain( $t->sent_by ) ); ?></td><td><?php echo esc_html( pjb_plain( $t->notes ) ); ?></td><td><?php pjb_delete_button( 'thanks', $t->id, 'Delete this thank-you record?' ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>
	<details class="pjb-add">
		<summary>Record a thank-you note</summary>
		<?php pjb_thanks_form( $id, $donations, $shipments ); ?>
	</details>

	<h2>Feedback</h2>
	<?php pjb_feedback_table( $feedback, false ); ?>

	<h2>Activity</h2>
	<?php pjb_form( 'pjb_add_activity', $id, 'style="max-width:900px"' ); ?>
		<div class="pjb-fields">
			<label>Date<input type="date" name="happened_on" value="<?php echo esc_attr( pjb_today() ); ?>"></label>
			<label>Type<?php pjb_select( 'type', array_diff_key( pjb_activity_types(), array( 'status' => '' ) ) ); ?></label>
			<label class="wide">Note<textarea name="note" rows="2" required></textarea></label>
		</div>
		<button type="submit" class="button">Add to activity</button>
	</form>
	<?php if ( $activity ) : ?>
		<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Date</th><th>Type</th><th>Note</th><th>By</th><th></th></tr></thead><tbody>
		<?php foreach ( $activity as $a ) : ?>
			<tr><td><?php echo esc_html( pjb_show_date( $a->happened_on ) ); ?></td><td><?php echo esc_html( pjb_x_label( pjb_activity_types(), $a->type ) ); ?></td><td><?php echo nl2br( esc_html( pjb_plain( $a->note ) ) ); ?></td><td><?php echo esc_html( pjb_plain( $a->by_user ) ); ?></td><td><?php pjb_delete_button( 'activity', $a->id, 'Delete this entry?' ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>

	<h2>Forget this person</h2>
	<p>If they ask to be removed, this deletes them and every request, shipment, donation, receipt, thank-you, feedback and activity entry linked to them. It cannot be undone, so download Excel first if you need a record.</p>
	<?php pjb_form( 'pjb_forget_person', $id, 'onsubmit="return confirm(\'Delete this person and everything linked to them, for good?\');"' ); ?>
		<button type="submit" class="button button-link-delete">Forget this person</button>
	</form>
	<?php
	pjb_admin_close();
}

function pjb_thanks_form( $person_id, array $donations, array $shipments ) {
	$for = array( '' => 'Nothing in particular' );
	foreach ( $donations as $d ) {
		$for[ 'd' . $d->id ] = pjb_ref( 'D', $d->id ) . ': ' . pjb_x_label( pjb_categories(), $d->category ) . ( $d->received_on ? ', ' . pjb_show_date( $d->received_on ) : '' );
	}
	foreach ( $shipments as $s ) {
		$for[ 's' . $s->id ] = pjb_ref( 'S', $s->id ) . ': box sent ' . pjb_show_date( $s->sent_on );
	}
	pjb_form( 'pjb_add_thanks', $person_id );
	?>
		<div class="pjb-fields">
			<label>Sent on<input type="date" name="sent_on" value="<?php echo esc_attr( pjb_today() ); ?>"></label>
			<label>How<?php pjb_select( 'method', pjb_thanks_methods(), 'email' ); ?></label>
			<label>For<?php pjb_select( 'for', $for ); ?></label>
			<label>Sent by<input type="text" name="sent_by" value="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>"></label>
			<label class="wide">Notes<input type="text" name="notes"></label>
		</div>
		<button type="submit" class="button">Save thank-you</button>
	</form>
	<?php
}

/* -------------------------------------------------------------------------
 * Donations
 * ---------------------------------------------------------------------- */

function pjb_donation_form( $person_id = 0, $d = null ) {
	pjb_form( $d ? 'pjb_save_donation' : 'pjb_add_donation', $d ? $d->id : 0 );
	?>
		<div class="pjb-fields">
			<?php
			if ( ! $d ) {
				pjb_person_fields( $person_id );
			}
			?>
			<label>What kind<?php pjb_select( 'category', pjb_categories(), $d ? $d->category : 'items' ); ?></label>
			<label>Status<?php pjb_select( 'status', pjb_donation_statuses(), $d ? $d->status : 'received' ); ?></label>
			<label>Received on<input type="date" name="received_on" value="<?php echo esc_attr( $d ? (string) $d->received_on : pjb_today() ); ?>"></label>
			<label>Amount received (money)<input type="text" name="amount" inputmode="decimal" value="<?php echo esc_attr( $d && null !== $d->amount ? $d->amount : '' ); ?>"></label>
			<label>Donor&#8217;s value estimate (items)<input type="text" name="value_estimate" inputmode="decimal" value="<?php echo esc_attr( $d && null !== $d->value_estimate ? $d->value_estimate : '' ); ?>"></label>
			<label>How it came<?php pjb_select( 'method', pjb_methods(), $d ? $d->method : '' ); ?></label>
			<label>Campaign or drive<input type="text" name="campaign" value="<?php echo esc_attr( $d ? pjb_plain( $d->campaign ) : '' ); ?>"></label>
			<label class="wide">Description<textarea name="description" rows="2"><?php echo esc_textarea( $d ? pjb_plain( $d->description ) : '' ); ?></textarea></label>
			<label class="wide">Notes for the team<textarea name="notes" rows="2"><?php echo esc_textarea( $d ? pjb_plain( $d->notes ) : '' ); ?></textarea></label>
		</div>
		<button type="submit" class="button button-primary"><?php echo $d ? 'Save donation' : 'Add donation'; ?></button>
	</form>
	<?php
}

/** Donations as a table, with receipt and thank-you state. */
function pjb_donation_table( array $donations, array $receipts, $show_person = true ) {
	if ( ! $donations ) {
		echo '<p class="description">None.</p>';
		return;
	}
	$thanked = array();
	foreach ( pjb_rows( 'thanks', 'donation_id > 0' ) as $t ) {
		$thanked[ (int) $t->donation_id ] = true;
	}
	?>
	<table class="widefat striped">
		<thead><tr><th>Gift</th><?php echo $show_person ? '<th>From</th>' : ''; ?><th>What</th><th>Amount</th><th>Status</th><th>Receipt</th><th>Thanked</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $donations as $d ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( pjb_url( 'pjb-donations', array( 'donation' => (int) $d->id ) ) ); ?>"><?php echo esc_html( pjb_ref( 'D', $d->id ) ); ?></a><span class="description"><?php echo esc_html( $d->received_on ? pjb_show_date( $d->received_on ) : 'logged ' . wp_date( 'M j, Y', (int) $d->created_ts ) ); ?></span></td>
				<?php if ( $show_person ) : ?>
					<td><?php echo pjb_person_link( $d->person_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?></td>
				<?php endif; ?>
				<td><strong><?php echo esc_html( pjb_x_label( pjb_categories(), $d->category ) ); ?></strong><?php echo '' === $d->description ? '' : '<br>' . nl2br( esc_html( pjb_plain( $d->description ) ) ); ?></td>
				<td><?php echo esc_html( null !== $d->amount ? pjb_show_money( $d->amount ) : ( null !== $d->value_estimate ? 'est. ' . pjb_show_money( $d->value_estimate ) : '' ) ); ?></td>
				<td><?php echo esc_html( pjb_x_label( pjb_donation_statuses(), $d->status ) ); ?></td>
				<td>
					<?php if ( isset( $receipts[ (int) $d->id ] ) ) : ?>
						<a href="<?php echo esc_url( pjb_url( 'pjb-receipts', array( 'receipt' => (int) $receipts[ (int) $d->id ]->id ) ) ); ?>"><?php echo esc_html( $receipts[ (int) $d->id ]->number ); ?></a>
						<span class="description"><?php echo $receipts[ (int) $d->id ]->sent_on ? 'sent' : 'not sent yet'; ?></span>
					<?php elseif ( 'received' === $d->status ) : ?>
						<?php pjb_form( 'pjb_issue_receipt', $d->id, 'class="pjb-inline"' ); ?><button class="button button-small">Write receipt</button></form>
					<?php endif; ?>
				</td>
				<td><?php echo isset( $thanked[ (int) $d->id ] ) ? 'Yes' : 'Not yet'; ?></td>
				<td><a href="<?php echo esc_url( pjb_url( 'pjb-donations', array( 'donation' => (int) $d->id ) ) ); ?>">Edit</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function pjb_donations_page() {
	global $wpdb;
	if ( ! current_user_can( pjb_capability() ) ) {
		return;
	}

	$receipts = array();
	foreach ( pjb_rows( 'receipts' ) as $rc ) {
		$receipts[ (int) $rc->donation_id ] = $rc;
	}

	$id = pjb_query_id( 'donation' );
	if ( $id ) {
		$d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'donations' ) . ' WHERE id = %d', $id ) );
		pjb_admin_open( $d ? 'Donation ' . pjb_ref( 'D', $id ) : 'Donation not found' );
		echo '<p><a href="' . esc_url( pjb_url( 'pjb-donations' ) ) . '">&larr; All donations</a></p>';
		if ( $d ) {
			echo '<p>From ' . pjb_person_link( $d->person_id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped.
			pjb_donation_table( array( $d ), $receipts, false );
			echo '<h2>Change it</h2>';
			pjb_donation_form( 0, $d );
			echo '<h2>Thank them</h2>';
			pjb_thanks_form( (int) $d->person_id, array( $d ), array() );
			echo '<h2>Delete</h2><p>';
			pjb_delete_button( 'donation', $d->id, 'Delete this donation for good?' );
			echo '</p>';
		}
		pjb_admin_close();
		return;
	}

	$statuses = pjb_donation_statuses();
	$filter   = pjb_query_key( 'status', $statuses );
	$rows     = '' === $filter ? pjb_rows( 'donations' ) : pjb_rows( 'donations', 'status = %s', array( $filter ) );

	pjb_admin_open( 'Pride & Joy Boxes: donations', 'Gifts of money, art, items, notes and time, and offers from the "Help fill a box" form. Mark a gift received to write its receipt, then record a thank-you.' );
	pjb_filter_links( 'pjb-donations', $statuses, pjb_status_counts( 'donations' ), $filter );
	?>
	<details class="pjb-add">
		<summary>Add a donation</summary>
		<?php pjb_donation_form(); ?>
	</details>
	<?php
	pjb_donation_table( $rows, $receipts );
	pjb_admin_close();
}

/* -------------------------------------------------------------------------
 * Feedback
 * ---------------------------------------------------------------------- */

function pjb_feedback_table( array $rows, $show_person = true ) {
	if ( ! $rows ) {
		echo '<p class="description">None yet.</p>';
		return;
	}
	?>
	<table class="widefat striped">
		<thead><tr><th>Received</th><?php echo $show_person ? '<th>From</th>' : ''; ?><th>Rating</th><th>Loved</th><th>Would make it better</th><th>Quote?</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $f ) : ?>
			<tr<?php echo $f->reviewed ? '' : ' style="font-weight:600"'; ?>>
				<td><?php echo esc_html( pjb_ref( 'F', $f->id ) ); ?><span class="description"><?php echo esc_html( pjb_show_time( $f->created_ts ) ); ?></span></td>
				<?php if ( $show_person ) : ?>
					<td><?php echo $f->person_id ? pjb_person_link( $f->person_id ) : '<span class="description">No name given</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?></td>
				<?php endif; ?>
				<td><?php echo null === $f->rating ? '' : esc_html( str_repeat( '♥', (int) $f->rating ) . ' ' . (int) $f->rating . '/5' ); ?></td>
				<td><?php echo nl2br( esc_html( pjb_plain( $f->loved ) ) ); ?></td>
				<td><?php echo nl2br( esc_html( pjb_plain( $f->wish ) ) ); ?></td>
				<td><?php echo $f->quote_ok ? esc_html( 'Yes, as ' . ( '' === $f->credit_as ? 'anonymous' : pjb_plain( $f->credit_as ) ) ) : 'No'; ?></td>
				<td>
					<?php pjb_form( 'pjb_feedback_reviewed', $f->id, 'class="pjb-inline"' ); ?>
						<button class="button button-small"><?php echo $f->reviewed ? 'Mark unread' : 'Mark read'; ?></button>
					</form>
					<?php pjb_delete_button( 'feedback', $f->id, 'Delete this feedback?' ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function pjb_feedback_page() {
	if ( ! current_user_can( pjb_capability() ) ) {
		return;
	}
	pjb_admin_open( 'Pride & Joy Boxes: feedback', 'Answers to "How was your box?" from the website and the app. Unread ones are in bold. Quote someone only when they said yes, and credit them the way they asked.' );
	pjb_feedback_table( pjb_rows( 'feedback' ) );
	pjb_admin_close();
}

/* -------------------------------------------------------------------------
 * Receipts and the template
 * ---------------------------------------------------------------------- */

function pjb_receipts_page() {
	global $wpdb;
	if ( ! current_user_can( pjb_capability() ) ) {
		return;
	}

	$id = pjb_query_id( 'receipt' );
	if ( $id ) {
		$rc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . pjb_t( 'receipts' ) . ' WHERE id = %d', $id ) );
		pjb_admin_open( $rc ? 'Receipt ' . $rc->number : 'Receipt not found' );
		if ( $rc ) {
			$person = pjb_person( (int) $rc->person_id );
			$body   = pjb_plain( $rc->body );
			$mail   = $person && '' !== $person->email
				? 'mailto:' . rawurlencode( $person->email ) . '?subject=' . rawurlencode( 'Your receipt ' . $rc->number ) . '&body=' . rawurlencode( $body )
				: '';
			?>
			<p class="pjb-noprint"><a href="<?php echo esc_url( pjb_url( 'pjb-receipts' ) ); ?>">&larr; All receipts</a> · For <?php echo pjb_person_link( $rc->person_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?> · gift <a href="<?php echo esc_url( pjb_url( 'pjb-donations', array( 'donation' => (int) $rc->donation_id ) ) ); ?>"><?php echo esc_html( pjb_ref( 'D', $rc->donation_id ) ); ?></a> · template version <?php echo (int) $rc->template_version; ?></p>
			<div class="pjb-receipt"><?php echo esc_html( $body ); ?></div>
			<p class="pjb-noprint" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
				<?php if ( $mail ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $mail, array( 'mailto' ) ); ?>">Email it</a>
				<?php endif; ?>
				<button type="button" class="button" onclick="window.print()">Print</button>
			</p>
			<div class="pjb-noprint">
				<?php if ( $rc->sent_on ) : ?>
					<p>Sent <?php echo esc_html( pjb_show_date( $rc->sent_on ) . ' by ' . strtolower( pjb_x_label( pjb_thanks_methods(), $rc->sent_via ) ) ); ?>.</p>
				<?php else : ?>
					<?php pjb_form( 'pjb_receipt_sent', $rc->id ); ?>
						<div class="pjb-fields" style="max-width:600px">
							<label>Sent on<input type="date" name="sent_on" value="<?php echo esc_attr( pjb_today() ); ?>"></label>
							<label>How<?php pjb_select( 'sent_via', pjb_thanks_methods(), 'email' ); ?></label>
						</div>
						<button type="submit" class="button">Mark sent</button>
					</form>
				<?php endif; ?>
				<p><?php pjb_delete_button( 'receipt', $rc->id, 'Delete this receipt for good? Keep it instead if it was already sent.' ); ?></p>
			</div>
			<?php
		}
		pjb_admin_close();
		return;
	}

	$rows = pjb_rows( 'receipts', '', array(), 'number DESC' );
	pjb_admin_open( 'Pride & Joy Boxes: receipts', 'Receipts written for received gifts, newest first. Write one from a donation, then email or print it and mark it sent. The wording comes from the receipt template.' );
	if ( ! $rows ) {
		echo '<p><em>No receipts yet.</em></p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Receipt</th><th>For</th><th>Gift</th><th>Issued</th><th>Sent</th></tr></thead><tbody>';
		foreach ( $rows as $rc ) {
			echo '<tr><td><a href="' . esc_url( pjb_url( 'pjb-receipts', array( 'receipt' => (int) $rc->id ) ) ) . '">' . esc_html( $rc->number ) . '</a></td>';
			echo '<td>' . pjb_person_link( $rc->person_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped.
			echo '<td>' . nl2br( esc_html( pjb_plain( $rc->description ) ) ) . '</td>';
			echo '<td>' . esc_html( pjb_show_date( $rc->issued_on ) ) . '</td>';
			echo '<td>' . ( $rc->sent_on ? esc_html( pjb_show_date( $rc->sent_on ) ) : '<strong>Not yet</strong>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	pjb_admin_close();
}

function pjb_template_page() {
	if ( ! current_user_can( pjb_capability() ) ) {
		return;
	}
	$s = pjb_receipt_settings();
	pjb_admin_open( 'Pride & Joy Boxes: receipt template', 'The wording for new receipts. Receipts already written keep the words they were written with; saving a change here starts a new version.' );
	pjb_form( 'pjb_save_template' );
	?>
		<div class="pjb-fields" style="max-width:900px">
			<label>Organization name<input type="text" name="org_name" value="<?php echo esc_attr( $s['org_name'] ); ?>"></label>
			<label>Contact email<input type="email" name="contact_email" value="<?php echo esc_attr( $s['contact_email'] ); ?>"></label>
			<label class="wide">Receipt text<textarea name="template" rows="18" style="font-family:monospace"><?php echo esc_textarea( $s['template'] ); ?></textarea></label>
			<label class="wide" style="flex-direction:row;align-items:center;gap:6px;font-weight:400"><input type="checkbox" name="tax_deductible" value="1"<?php checked( $s['tax_deductible'] ); ?>> Gifts are tax-deductible (tick only once there is 501(c)(3) status or a fiscal sponsor, and change the text above to match)</label>
		</div>
		<p>These words are filled in for each receipt: <code>{org_name}</code> <code>{contact_email}</code> <code>{number}</code> <code>{issued_on}</code> <code>{received_on}</code> <code>{name}</code> <code>{gift}</code>. This is version <?php echo (int) $s['version']; ?>.</p>
		<button type="submit" class="button button-primary">Save template</button>
	</form>
	<?php
	pjb_admin_close();
}
