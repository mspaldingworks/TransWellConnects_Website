<?php
/**
 * WP Admin screen for Pride and Joy Boxes: the request list, status changes,
 * deletion and a CSV download.
 * Everything here needs the manage_options capability (site administrators).
 */

defined( 'ABSPATH' ) || exit;

function pjb_admin_menu() {
	add_menu_page( 'Pride & Joy Boxes', 'Pride & Joy Boxes', 'manage_options', 'pride-joy-boxes', 'pjb_requests_page', 'dashicons-heart', 59 );
}
add_action( 'admin_menu', 'pjb_admin_menu' );

function pjb_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pride-joy-boxes' ) ) . '">Requests</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( PJB_FILE ), 'pjb_action_links' );

function pjb_requests_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'pride-joy-boxes' ), $args ), admin_url( 'admin.php' ) );
}

/** Stored text back as characters (emoji are saved as entities). */
function pjb_plain( $value ) {
	return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** The status filter in the address bar, or '' for every request. */
function pjb_current_filter() {
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	return isset( pjb_statuses()[ $status ] ) ? $status : '';
}

function pjb_request_rows( $status ) {
	global $wpdb;
	$table = pjb_table();
	if ( '' === $status ) {
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
	}
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC", $status ) );
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

/* -------------------------------------------------------------------------
 * The list
 * ---------------------------------------------------------------------- */

function pjb_requests_page() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$table    = pjb_table();
	$filter   = pjb_current_filter();
	$rows     = pjb_request_rows( $filter );
	$statuses = pjb_statuses();
	$kinds    = pjb_kinds();
	$sources  = pjb_sources();
	$tz       = wp_timezone();

	$counts = array();
	foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" ) as $count ) {
		$counts[ $count->status ] = (int) $count->n;
	}

	$notices = array(
		'saved'   => 'Status saved.',
		'deleted' => 'Request deleted.',
	);
	$notice  = isset( $_GET['pjb'] ) ? sanitize_key( wp_unslash( $_GET['pjb'] ) ) : '';
	?>
	<div class="wrap">
		<h1>Pride &amp; Joy Boxes</h1>
		<p>Requests from the website form and from the TransWell app, newest first. A request stays here until you delete it.</p>

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
		<?php endif; ?>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( pjb_requests_url() ); ?>"<?php echo '' === $filter ? ' class="current"' : ''; ?>>All <span class="count">(<?php echo (int) array_sum( $counts ); ?>)</span></a> |</li>
			<?php $last = array_key_last( $statuses ); ?>
			<?php foreach ( $statuses as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( pjb_requests_url( array( 'status' => $key ) ) ); ?>"<?php echo $filter === $key ? ' class="current"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0; ?>)</span></a><?php echo $key === $last ? '' : ' |'; ?></li>
			<?php endforeach; ?>
		</ul>

		<p style="clear:both;padding-top:8px">
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pjb_export' . ( '' === $filter ? '' : '&status=' . $filter ) ), 'pjb_export' ) ); ?>">Download as CSV</a>
		</p>

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
						<?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $row->created_ts, $tz ) ); ?><br>
						<span class="description"><?php echo esc_html( isset( $sources[ $row->source ] ) ? $sources[ $row->source ] : $row->source ); ?></span>
					</td>
					<td>
						<strong><?php echo esc_html( pjb_plain( $row->name ) ); ?></strong>
						<?php if ( '' !== $row->pronouns ) : ?>
							<span class="description">(<?php echo esc_html( pjb_plain( $row->pronouns ) ); ?>)</span>
						<?php endif; ?>
						<br><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a>
						<?php if ( '' !== $address ) : ?>
							<br><?php echo nl2br( esc_html( $address ) ); ?>
						<?php else : ?>
							<br><span class="description">No address yet</span>
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
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pjb_set_status">
							<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
							<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
							<?php wp_nonce_field( 'pjb_set_status_' . (int) $row->id ); ?>
							<label class="screen-reader-text" for="pjb-status-<?php echo (int) $row->id; ?>">Status</label>
							<select name="status" id="pjb-status-<?php echo (int) $row->id; ?>">
								<?php foreach ( $statuses as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $row->status, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button button-small">Save</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px" onsubmit="return confirm('Delete this request for good?');">
							<input type="hidden" name="action" value="pjb_delete">
							<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
							<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
							<?php wp_nonce_field( 'pjb_delete_' . (int) $row->id ); ?>
							<button type="submit" class="button-link button-link-delete">Delete</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Status, deletion and export
 * ---------------------------------------------------------------------- */

/** Back to the list, on the filter the action came from. */
function pjb_redirect_back( $done ) {
	$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : '';
	$args   = array( 'pjb' => $done );
	if ( isset( pjb_statuses()[ $filter ] ) ) {
		$args['status'] = $filter;
	}
	wp_safe_redirect( pjb_requests_url( $args ) );
	exit;
}

function pjb_set_status() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to change this.' );
	}
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	check_admin_referer( 'pjb_set_status_' . $id );

	$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
	if ( $id && isset( pjb_statuses()[ $status ] ) ) {
		$wpdb->update( pjb_table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}
	pjb_redirect_back( 'saved' );
}
add_action( 'admin_post_pjb_set_status', 'pjb_set_status' );

function pjb_delete() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to delete this.' );
	}
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	check_admin_referer( 'pjb_delete_' . $id );

	if ( $id ) {
		$wpdb->delete( pjb_table(), array( 'id' => $id ), array( '%d' ) );
	}
	pjb_redirect_back( 'deleted' );
}
add_action( 'admin_post_pjb_delete', 'pjb_delete' );

/** Stops a spreadsheet from running a cell that starts like a formula. */
function pjb_csv_cell( $value ) {
	$value = (string) $value;
	return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
}

function pjb_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to export this.' );
	}
	check_admin_referer( 'pjb_export' );

	$filter   = pjb_current_filter();
	$statuses = pjb_statuses();
	$kinds    = pjb_kinds();
	$sources  = pjb_sources();
	$tz       = wp_timezone();
	$rows     = array( array( 'received', 'sent_from', 'status', 'box', 'name', 'pronouns', 'email', 'address', 'joy', 'favorites', 'affirming_items', 'plain_outer_box', 'notes' ) );

	foreach ( array_reverse( pjb_request_rows( $filter ) ) as $row ) {
		$rows[] = array(
			wp_date( 'Y-m-d H:i:s', (int) $row->created_ts, $tz ),
			isset( $sources[ $row->source ] ) ? $sources[ $row->source ] : $row->source,
			isset( $statuses[ $row->status ] ) ? $statuses[ $row->status ] : $row->status,
			isset( $kinds[ $row->kind ] ) ? $kinds[ $row->kind ] : $row->kind,
			pjb_plain( $row->name ),
			pjb_plain( $row->pronouns ),
			$row->email,
			pjb_plain( $row->address ),
			implode( ', ', pjb_joy_picks( $row ) ),
			pjb_plain( $row->faves ),
			implode( '; ', pjb_item_lines( $row ) ),
			$row->plain ? 'yes' : 'no',
			pjb_plain( $row->notes ),
		);
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="pride-joy-requests' . ( '' === $filter ? '' : '-' . $filter ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // lets Excel read the emoji
	foreach ( $rows as $row ) {
		fputcsv( $out, array_map( 'pjb_csv_cell', $row ), ',', '"', '\\' );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_post_pjb_export', 'pjb_export' );
