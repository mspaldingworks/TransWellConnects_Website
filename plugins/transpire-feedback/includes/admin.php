<?php
/**
 * WP Admin screens for Transpire Feedback: results, settings and exports.
 * Everything here needs the manage_options capability (site administrators).
 */

defined( 'ABSPATH' ) || exit;

const TRANSPIRE_FB_PLOT_WIDTH = 720;

function transpire_fb_admin_menu() {
	add_menu_page( 'Transpire Feedback', 'Transpire Feedback', 'manage_options', 'transpire-feedback', 'transpire_fb_results_page', 'dashicons-heart', 58 );
	add_submenu_page( 'transpire-feedback', 'Transpire Feedback results', 'Results', 'manage_options', 'transpire-feedback', 'transpire_fb_results_page' );
	add_submenu_page( 'transpire-feedback', 'Transpire Feedback settings', 'Settings', 'manage_options', 'transpire-feedback-settings', 'transpire_fb_settings_page' );
}
add_action( 'admin_menu', 'transpire_fb_admin_menu' );

function transpire_fb_admin_assets( $hook ) {
	if ( false === strpos( $hook, 'transpire-feedback' ) ) {
		return;
	}
	wp_enqueue_style( 'transpire-fb-admin', plugins_url( 'assets/admin.css', TRANSPIRE_FB_FILE ), array(), TRANSPIRE_FB_VERSION );
	wp_enqueue_script( 'transpire-fb-admin', plugins_url( 'assets/admin.js', TRANSPIRE_FB_FILE ), array(), TRANSPIRE_FB_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'transpire_fb_admin_assets' );

function transpire_fb_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=transpire-feedback-settings' ) ) . '">Settings</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( TRANSPIRE_FB_FILE ), 'transpire_fb_action_links' );

/** One sentence on what is open right now, shown at the top of both screens. */
function transpire_fb_state_sentence() {
	$options = transpire_fb_options();
	$window  = transpire_fb_window();
	$state   = transpire_fb_state();
	$when    = wp_date( 'l, F j, Y, g:i A', $window['start'], $window['tz'] ) . ' to ' . wp_date( 'g:i A T', $window['end'], $window['tz'] );

	if ( 'open' === $state['phase'] ) {
		$now = 'The session is under way and reactions are being recorded.';
	} elseif ( 'before' === $state['phase'] ) {
		$now = 'The session has not started yet.';
	} else {
		$now = 'The session has ended.';
	}
	if ( $state['is_test'] ) {
		$now .= ' Test mode is on, so the widgets are open and anything sent now is saved as test data.';
	} elseif ( ! empty( $options['test_mode'] ) ) {
		$now .= ' Test mode is on but paused: during the session everything is recorded as real.';
	}
	return $options['session_name'] . ' runs ' . $when . '. ' . $now;
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function transpire_fb_clean_datetime( $value, $fallback ) {
	if ( is_string( $value ) && preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $value, $match ) ) {
		return $match[1] . ' ' . $match[2];
	}
	return $fallback;
}

function transpire_fb_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to change these settings.' );
	}
	check_admin_referer( 'transpire_fb_save' );

	$input    = wp_unslash( $_POST );
	$defaults = transpire_fb_defaults();
	$text     = function ( $key ) use ( $input ) {
		return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : '';
	};

	$timezone = sanitize_text_field( $text( 'timezone' ) );
	if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
		$timezone = $defaults['timezone'];
	}

	$session_name = sanitize_text_field( $text( 'session_name' ) );
	$reactions    = sanitize_textarea_field( $text( 'reactions' ) );

	$options = array(
		'session_name'  => '' !== $session_name ? $session_name : $defaults['session_name'],
		'timezone'      => $timezone,
		'starts'        => transpire_fb_clean_datetime( $text( 'starts' ), $defaults['starts'] ),
		'ends'          => transpire_fb_clean_datetime( $text( 'ends' ), $defaults['ends'] ),
		'reactions'     => '' !== trim( $reactions ) ? $reactions : $defaults['reactions'],
		'cooldown'      => min( 60, max( 0, (int) $text( 'cooldown' ) ) ),
		'feedback_days' => min( 365, max( 0, (int) $text( 'feedback_days' ) ) ),
		'rating_label'  => sanitize_text_field( $text( 'rating_label' ) ),
		'questions'     => sanitize_textarea_field( $text( 'questions' ) ),
		'roles'         => sanitize_textarea_field( $text( 'roles' ) ),
		'ask_email'     => empty( $input['ask_email'] ) ? 0 : 1,
		'test_mode'     => empty( $input['test_mode'] ) ? 0 : 1,
	);

	// Emoji are stored as HTML entities so the settings save on any database charset.
	foreach ( transpire_fb_text_keys() as $key ) {
		$options[ $key ] = wp_encode_emoji( $options[ $key ] );
	}

	update_option( 'transpire_fb_options', $options, false );

	$window = transpire_fb_window();
	$end    = date_create_immutable( $options['ends'], $window['tz'] );
	$notice = ( $end && $end->getTimestamp() <= $window['start'] ) ? 'ends' : 'saved';

	wp_safe_redirect( admin_url( 'admin.php?page=transpire-feedback-settings&tspr=' . $notice ) );
	exit;
}
add_action( 'admin_post_transpire_fb_save', 'transpire_fb_save_settings' );

function transpire_fb_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$options = transpire_fb_options();
	$notice  = isset( $_GET['tspr'] ) ? sanitize_key( wp_unslash( $_GET['tspr'] ) ) : '';
	?>
	<div class="wrap tspr-admin">
		<h1>Transpire Feedback settings</h1>
		<?php if ( 'saved' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
		<?php elseif ( 'ends' === $notice ) : ?>
			<div class="notice notice-warning is-dismissible"><p>Settings saved, but the end time is not after the start time. Until that is fixed, the session is treated as 90 minutes long.</p></div>
		<?php endif; ?>

		<p class="tspr-lede"><?php echo esc_html( transpire_fb_state_sentence() ); ?></p>

		<div class="tspr-card">
			<h2>Putting the widgets on a page</h2>
			<p>Add a <strong>Shortcode</strong> block to any page and type one of these. Both can go on the same page.</p>
			<p><code>[transpire_reactions]</code> shows the reaction buttons. They open and close by themselves at the session times below.</p>
			<p><code>[transpire_feedback]</code> shows the written feedback form. It opens when the session starts.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="transpire_fb_save">
			<?php wp_nonce_field( 'transpire_fb_save' ); ?>

			<h2>The session</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tspr-session-name">Session name</label></th>
					<td>
						<input type="text" class="regular-text" id="tspr-session-name" name="session_name" value="<?php echo esc_attr( $options['session_name'] ); ?>">
						<p class="description">Shown above the widgets. Results are filed under this name, so changing it starts a new, empty set of results. Earlier results stay available on the Results screen.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-starts">Starts</label></th>
					<td><input type="datetime-local" id="tspr-starts" name="starts" value="<?php echo esc_attr( str_replace( ' ', 'T', $options['starts'] ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-ends">Ends</label></th>
					<td>
						<input type="datetime-local" id="tspr-ends" name="ends" value="<?php echo esc_attr( str_replace( ' ', 'T', $options['ends'] ) ); ?>">
						<p class="description">Reactions are accepted only between these two times.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-timezone">Timezone</label></th>
					<td>
						<select id="tspr-timezone" name="timezone"><?php echo wp_timezone_choice( $options['timezone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds and escapes these options. ?></select>
						<p class="description">The start and end times above are read in this timezone, whatever the site's own timezone is.</p>
					</td>
				</tr>
			</table>

			<h2>Reactions</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tspr-reactions">Reaction buttons</label></th>
					<td>
						<textarea id="tspr-reactions" name="reactions" rows="8" class="large-text code"><?php echo esc_textarea( $options['reactions'] ); ?></textarea>
						<p class="description">One per line, as <code>emoji | label</code>. Up to 8. Results are counted by label, so rewording a label after the session starts splits its count in two.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-cooldown">Pause between taps</label></th>
					<td>
						<input type="number" id="tspr-cooldown" name="cooldown" min="0" max="60" class="small-text" value="<?php echo esc_attr( (int) $options['cooldown'] ); ?>"> seconds
						<p class="description">How long one person's buttons rest after each tap. This keeps one device from flooding the record.</p>
					</td>
				</tr>
			</table>

			<h2>Feedback form</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tspr-rating">Rating question</label></th>
					<td>
						<input type="text" class="large-text" id="tspr-rating" name="rating_label" value="<?php echo esc_attr( $options['rating_label'] ); ?>">
						<p class="description">Answered on a scale of 1 (not at all) to 5 (very much). Leave blank to remove the rating.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-questions">Written questions</label></th>
					<td>
						<textarea id="tspr-questions" name="questions" rows="5" class="large-text"><?php echo esc_textarea( $options['questions'] ); ?></textarea>
						<p class="description">One per line, up to 5. Each gets a text box. All are optional for the person answering.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-roles">"I am here as" choices</label></th>
					<td>
						<textarea id="tspr-roles" name="roles" rows="6" class="regular-text"><?php echo esc_textarea( $options['roles'] ); ?></textarea>
						<p class="description">One per line. Leave blank to remove this question.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Follow-up email</th>
					<td><label><input type="checkbox" name="ask_email" value="1" <?php checked( ! empty( $options['ask_email'] ) ); ?>> Offer an optional email box for people who want a reply</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="tspr-days">Keep the form open</label></th>
					<td><input type="number" id="tspr-days" name="feedback_days" min="0" max="365" class="small-text" value="<?php echo esc_attr( (int) $options['feedback_days'] ); ?>"> days after the session ends</td>
				</tr>
			</table>

			<h2>Trying it out</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Test mode</th>
					<td>
						<label><input type="checkbox" name="test_mode" value="1" <?php checked( ! empty( $options['test_mode'] ) ); ?>> Open both widgets now so they can be tried before the session</label>
						<p class="description">Anything sent in test mode is saved separately as test data and can be deleted from the Results screen. During the real session times, test mode is ignored and everything is recorded as real.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save settings' ); ?>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Results: data
 * ---------------------------------------------------------------------- */

/** Every session that has data, plus the one in Settings. */
function transpire_fb_sessions() {
	global $wpdb;
	$reactions = transpire_fb_table( 'reactions' );
	$feedback  = transpire_fb_table( 'feedback' );
	$found     = $wpdb->get_col( "SELECT session_key FROM {$reactions} UNION SELECT session_key FROM {$feedback}" );
	$found[]   = transpire_fb_session_key();
	return array_values( array_unique( array_filter( (array) $found ) ) );
}

/**
 * Reaction rows to show: the configured buttons in their order, then any key
 * that has data but is no longer a button.
 */
function transpire_fb_result_rows( $totals ) {
	$rows = array();
	foreach ( transpire_fb_reactions() as $key => $reaction ) {
		$rows[ $key ] = $reaction;
	}
	foreach ( array_keys( $totals ) as $key ) {
		if ( ! isset( $rows[ $key ] ) ) {
			$rows[ $key ] = array(
				'key'   => $key,
				'emoji' => '',
				'label' => $key,
			);
		}
	}
	return $rows;
}

function transpire_fb_results_data( $session, $is_test ) {
	global $wpdb;
	$table  = transpire_fb_table( 'reactions' );
	$window = transpire_fb_window();

	$span = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, COUNT(DISTINCT client_hash) AS devices, MIN(created_ts) AS first_ts, MAX(created_ts) AS last_ts FROM {$table} WHERE session_key = %s AND is_test = %d", $session, $is_test ) );

	$totals = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT reaction_key, COUNT(*) AS total FROM {$table} WHERE session_key = %s AND is_test = %d GROUP BY reaction_key", $session, $is_test ) ) as $row ) {
		$totals[ $row->reaction_key ] = (int) $row->total;
	}

	// The chart covers the session as set in Settings, stretched to take in any reaction outside it.
	$total = $span ? (int) $span->total : 0;
	$first = $total ? (int) floor( $span->first_ts / 60 ) * 60 : 0;
	$last  = $total ? (int) ( floor( $span->last_ts / 60 ) + 1 ) * 60 : 0;
	if ( ! $is_test && transpire_fb_session_key() === $session ) {
		$start = $total ? min( $window['start'], $first ) : $window['start'];
		$end   = $total ? max( $window['end'], $last ) : $window['end'];
	} elseif ( $total ) {
		$start = $first;
		$end   = max( $last, $first + 30 * MINUTE_IN_SECONDS );
	} else {
		$start = $window['start'];
		$end   = $window['end'];
	}

	// One column per minute for a normal session; wider columns when the span is long.
	$bucket = 60;
	foreach ( array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600, 86400 ) as $size ) {
		$bucket = $size;
		if ( ( $end - $start ) / $size <= 120 ) {
			break;
		}
	}
	$buckets = max( 1, (int) ceil( ( $end - $start ) / $bucket ) );

	$rows   = transpire_fb_result_rows( $totals );
	$matrix = array();
	foreach ( array_keys( $rows ) as $key ) {
		$matrix[ $key ] = array_fill( 0, $buckets, 0 );
	}
	$all = array_fill( 0, $buckets, 0 );

	$counts = $wpdb->get_results( $wpdb->prepare( "SELECT reaction_key, FLOOR((created_ts - %d) / %d) AS bucket, COUNT(*) AS total FROM {$table} WHERE session_key = %s AND is_test = %d AND created_ts >= %d AND created_ts < %d GROUP BY reaction_key, bucket", $start, $bucket, $session, $is_test, $start, $end ) );
	foreach ( (array) $counts as $row ) {
		$index = (int) $row->bucket;
		if ( isset( $matrix[ $row->reaction_key ][ $index ] ) ) {
			$matrix[ $row->reaction_key ][ $index ] = (int) $row->total;
			$all[ $index ]                         += (int) $row->total;
		}
	}

	return array(
		'total'   => $total,
		'devices' => $span ? (int) $span->devices : 0,
		'totals'  => $totals,
		'rows'    => $rows,
		'matrix'  => $matrix,
		'all'     => $all,
		'start'   => $start,
		'bucket'  => $bucket,
		'buckets' => $buckets,
		'tz'      => $window['tz'],
	);
}

function transpire_fb_feedback_rows( $session, $is_test, $limit = 0 ) {
	global $wpdb;
	$table = transpire_fb_table( 'feedback' );
	$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s AND is_test = %d ORDER BY id DESC", $session, $is_test );
	if ( $limit ) {
		$sql .= ' LIMIT ' . (int) $limit;
	}
	return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
}

/* -------------------------------------------------------------------------
 * Results: charts
 * ---------------------------------------------------------------------- */

/** Round a scale up to a tidy top value: 1, 2, 3, 4, 5, 6, 8, 10, 20, 30... */
function transpire_fb_nice_max( $value ) {
	$value = max( 1, (int) $value );
	$power = pow( 10, floor( log10( $value ) ) );
	foreach ( array( 1, 2, 3, 4, 5, 6, 8, 10 ) as $multiple ) {
		if ( $multiple * $power >= $value ) {
			return (int) ( $multiple * $power );
		}
	}
	return (int) ( 10 * $power );
}

function transpire_fb_bucket_label( $data, $index ) {
	$from = $data['start'] + $index * $data['bucket'];
	if ( $data['bucket'] >= DAY_IN_SECONDS ) {
		return wp_date( 'M j', $from, $data['tz'] );
	}
	if ( $data['bucket'] <= 60 ) {
		return wp_date( 'g:i A', $from, $data['tz'] );
	}
	return wp_date( 'g:i', $from, $data['tz'] ) . ' to ' . wp_date( 'g:i A', $from + $data['bucket'], $data['tz'] );
}

/**
 * One row of thin columns over time, as inline SVG.
 *
 * $args: 'label' (accessible name), 'grid' (y values to rule and label),
 * 'peak' (print the tallest column's value on its cap).
 */
function transpire_fb_plot( $counts, $buckets, $scale_max, $height, $args ) {
	$width = TRANSPIRE_FB_PLOT_WIDTH;
	$top   = 16; // headroom for the peak label
	$base  = $top + $height;
	$step  = $width / $buckets;
	$bar   = max( 1, min( 24, $step - 2 ) ); // a 2px gap between neighbours

	$svg = sprintf( '<svg class="tspr-plot" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" focusable="false">', $width, $base + 1, esc_attr( $args['label'] ) );

	foreach ( isset( $args['grid'] ) ? $args['grid'] : array() as $tick ) {
		$y    = round( $base - ( $tick / $scale_max ) * $height, 1 );
		$svg .= sprintf( '<line class="tspr-plot-grid" x1="0" x2="%d" y1="%s" y2="%s"/>', $width, $y, $y );
		$svg .= sprintf( '<text class="tspr-plot-tick" x="2" y="%s">%s</text>', $y - 3, esc_html( number_format_i18n( $tick ) ) );
	}

	$path   = '';
	$peak   = 0;
	$peak_i = -1;
	foreach ( $counts as $index => $count ) {
		if ( $count <= 0 ) {
			continue;
		}
		if ( $count > $peak ) {
			$peak   = $count;
			$peak_i = $index;
		}
		$h = max( 2, ( $count / $scale_max ) * $height );
		$x = round( $index * $step + ( $step - $bar ) / 2, 2 );
		$y = round( $base - $h, 2 );
		$r = min( 4, $bar / 2, $h ); // rounded cap, square at the baseline
		$e = round( $x + $bar, 2 );

		$shoulder = round( $y + $r, 2 );
		$path    .= "M{$x},{$base}V{$shoulder}Q{$x},{$y} " . round( $x + $r, 2 ) . ",{$y}H" . round( $e - $r, 2 ) . "Q{$e},{$y} {$e},{$shoulder}V{$base}Z";
	}
	if ( '' !== $path ) {
		$svg .= '<path class="tspr-plot-bars" d="' . esc_attr( $path ) . '"/>';
	}

	$svg .= sprintf( '<line class="tspr-plot-base" x1="0" x2="%d" y1="%s" y2="%s"/>', $width, $base + 0.5, $base + 0.5 );

	if ( ! empty( $args['peak'] ) && $peak_i >= 0 ) {
		$x    = min( $width - 12, max( 12, $peak_i * $step + $step / 2 ) );
		$y    = $base - max( 2, ( $peak / $scale_max ) * $height ) - 4;
		$svg .= sprintf( '<text class="tspr-plot-peak" x="%s" y="%s" text-anchor="middle">%s</text>', round( $x, 1 ), round( $y, 1 ), esc_html( number_format_i18n( $peak ) ) );
	}

	return $svg . '</svg>';
}

/** The shared time axis under a stack of plots. */
function transpire_fb_axis( $data ) {
	$width = TRANSPIRE_FB_PLOT_WIDTH;
	$span  = $data['buckets'] * $data['bucket'];
	$every = DAY_IN_SECONDS;
	foreach ( array( 300, 600, 900, 1800, 3600, 7200, 14400, 28800, 86400 ) as $seconds ) {
		if ( $span / $seconds <= 8 ) {
			$every = $seconds;
			break;
		}
	}
	$format = $every >= DAY_IN_SECONDS ? 'M j' : 'g:i A';

	$svg = sprintf( '<svg class="tspr-plot tspr-plot-axis" width="%1$d" height="22" viewBox="0 0 %1$d 22" aria-hidden="true" focusable="false">', $width );
	for ( $offset = 0; $offset <= $span; $offset += $every ) {
		$x      = round( ( $offset / $span ) * $width, 1 );
		$anchor = $x < 30 ? 'start' : ( $x > $width - 30 ? 'end' : 'middle' );
		$svg   .= sprintf( '<line class="tspr-plot-base" x1="%1$s" x2="%1$s" y1="0" y2="4"/>', min( $width - 0.5, max( 0.5, $x ) ) );
		$svg   .= sprintf( '<text class="tspr-plot-tick" x="%s" y="17" text-anchor="%s">%s</text>', $x, $anchor, esc_html( wp_date( $format, $data['start'] + $offset, $data['tz'] ) ) );
	}
	return $svg . '</svg>';
}

function transpire_fb_render_timeline( $data ) {
	$labels = array();
	for ( $i = 0; $i < $data['buckets']; $i++ ) {
		$labels[] = transpire_fb_bucket_label( $data, $i );
	}

	$all_peak   = max( $data['all'] );
	$all_max    = transpire_fb_nice_max( $all_peak );
	$row_peak   = 0;
	$tip_series = array();
	foreach ( $data['rows'] as $key => $row ) {
		$row_peak     = max( $row_peak, max( $data['matrix'][ $key ] ) );
		$tip_series[] = array(
			'name'   => trim( $row['emoji'] . ' ' . $row['label'] ),
			'counts' => $data['matrix'][ $key ],
		);
	}
	$row_max = max( 1, $row_peak );
	$unit    = 60 === $data['bucket'] ? 'minute' : 'column';
	$grid    = array( $all_max );
	if ( 0 === $all_max % 2 ) {
		array_unshift( $grid, $all_max / 2 );
	}

	$tip = array(
		'labels' => $labels,
		'all'    => $data['all'],
		'series' => $tip_series,
	);
	?>
	<div class="tspr-chart" data-tspr-chart="<?php echo esc_attr( wp_json_encode( $tip ) ); ?>">
		<div class="tspr-chart-row tspr-chart-all">
			<div class="tspr-chart-name">
				<strong>All reactions</strong>
				<span class="tspr-chart-sub"><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?> in total, per <?php echo esc_html( $unit ); ?></span>
			</div>
			<?php
			echo transpire_fb_plot( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is assembled from numbers and escaped labels.
				$data['all'],
				$data['buckets'],
				$all_max,
				96,
				array(
					'label' => sprintf( 'All reactions over time: %d in total, busiest %s with %d.', $data['total'], $labels[ (int) array_search( $all_peak, $data['all'], true ) ], $all_peak ),
					'grid'  => $grid,
				)
			);
			?>
		</div>

		<p class="tspr-chart-caption">By reaction. Every row below is drawn to the same height scale (tallest column = <?php echo esc_html( number_format_i18n( $row_max ) ); ?>), and the number on each row marks its busiest <?php echo esc_html( $unit ); ?>.</p>

		<?php foreach ( $data['rows'] as $key => $row ) : ?>
			<?php $total = isset( $data['totals'][ $key ] ) ? $data['totals'][ $key ] : 0; ?>
			<div class="tspr-chart-row">
				<div class="tspr-chart-name">
					<span class="tspr-chart-emoji" aria-hidden="true"><?php echo esc_html( $row['emoji'] ); ?></span>
					<span class="tspr-chart-label"><?php echo esc_html( $row['label'] ); ?></span>
					<span class="tspr-chart-total"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</div>
				<?php
				echo transpire_fb_plot( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is assembled from numbers and escaped labels.
					$data['matrix'][ $key ],
					$data['buckets'],
					$row_max,
					40,
					array(
						'label' => sprintf( '%s over time: %d in total.', $row['label'], $total ),
						'peak'  => true,
					)
				);
				?>
			</div>
		<?php endforeach; ?>

		<div class="tspr-chart-row tspr-chart-axisrow">
			<div class="tspr-chart-name"></div>
			<?php echo transpire_fb_axis( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is assembled from numbers and escaped labels. ?>
		</div>

		<div class="tspr-chart-band" hidden></div>
		<div class="tspr-chart-tip" hidden></div>
	</div>
	<?php
}

/** The same numbers as the chart, for reading exactly and for screen readers. */
function transpire_fb_render_table( $data ) {
	// Group one-minute columns into five-minute rows so the table stays readable.
	$group = 60 === $data['bucket'] ? 5 : 1;
	?>
	<details class="tspr-details">
		<summary>Show these numbers as a table</summary>
		<table class="widefat striped tspr-table">
			<thead>
				<tr>
					<th scope="col">From</th>
					<?php foreach ( $data['rows'] as $row ) : ?>
						<th scope="col" class="tspr-num"><?php echo esc_html( trim( $row['emoji'] . ' ' . $row['label'] ) ); ?></th>
					<?php endforeach; ?>
					<th scope="col" class="tspr-num">All</th>
				</tr>
			</thead>
			<tbody>
				<?php for ( $i = 0; $i < $data['buckets']; $i += $group ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( wp_date( $data['bucket'] >= DAY_IN_SECONDS ? 'M j' : 'g:i A', $data['start'] + $i * $data['bucket'], $data['tz'] ) ); ?></th>
						<?php foreach ( array_keys( $data['rows'] ) as $key ) : ?>
							<td class="tspr-num"><?php echo esc_html( number_format_i18n( array_sum( array_slice( $data['matrix'][ $key ], $i, $group ) ) ) ); ?></td>
						<?php endforeach; ?>
						<td class="tspr-num"><strong><?php echo esc_html( number_format_i18n( array_sum( array_slice( $data['all'], $i, $group ) ) ) ); ?></strong></td>
					</tr>
				<?php endfor; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row">Total</th>
					<?php foreach ( array_keys( $data['rows'] ) as $key ) : ?>
						<td class="tspr-num"><?php echo esc_html( number_format_i18n( array_sum( $data['matrix'][ $key ] ) ) ); ?></td>
					<?php endforeach; ?>
					<td class="tspr-num"><strong><?php echo esc_html( number_format_i18n( array_sum( $data['all'] ) ) ); ?></strong></td>
				</tr>
			</tfoot>
		</table>
	</details>
	<?php
}

/* -------------------------------------------------------------------------
 * Results: screen
 * ---------------------------------------------------------------------- */

function transpire_fb_results_url( $args ) {
	return add_query_arg( array_merge( array( 'page' => 'transpire-feedback' ), $args ), admin_url( 'admin.php' ) );
}

function transpire_fb_results_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$sessions = transpire_fb_sessions();
	$session  = isset( $_GET['session'] ) ? sanitize_key( wp_unslash( $_GET['session'] ) ) : '';
	if ( ! in_array( $session, $sessions, true ) ) {
		$session = transpire_fb_session_key();
	}
	$is_test = empty( $_GET['test'] ) ? 0 : 1;
	$live    = ! empty( $_GET['live'] );
	$notice  = isset( $_GET['tspr'] ) ? sanitize_key( wp_unslash( $_GET['tspr'] ) ) : '';
	$view    = array(
		'session' => $session,
		'test'    => $is_test,
	);

	$data     = transpire_fb_results_data( $session, $is_test );
	$feedback = transpire_fb_feedback_rows( $session, $is_test, 500 );

	$rated  = array();
	$spread = array_fill( 1, 5, 0 );
	foreach ( $feedback as $entry ) {
		if ( $entry->rating >= 1 && $entry->rating <= 5 ) {
			$rated[] = (int) $entry->rating;
			$spread[ (int) $entry->rating ]++;
		}
	}
	$busiest = $data['total'] ? (int) array_search( max( $data['all'] ), $data['all'], true ) : -1;
	$export  = function ( $type ) use ( $view ) {
		return wp_nonce_url( add_query_arg( array_merge( $view, array( 'action' => 'transpire_fb_export', 'type' => $type ) ), admin_url( 'admin-post.php' ) ), 'transpire_fb_export' );
	};
	?>
	<div class="wrap tspr-admin" <?php echo $live ? 'data-tspr-live="15"' : ''; ?>>
		<h1>Transpire Feedback</h1>

		<?php if ( 'deleted' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>Test data deleted.</p></div>
		<?php endif; ?>

		<p class="tspr-lede"><?php echo esc_html( transpire_fb_state_sentence() ); ?></p>

		<form method="get" class="tspr-filters">
			<input type="hidden" name="page" value="transpire-feedback">
			<?php if ( count( $sessions ) > 1 ) : ?>
				<label>Session
					<select name="session">
						<?php foreach ( $sessions as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $session ); ?>><?php echo esc_html( $key ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<label>Show
				<select name="test">
					<option value="0" <?php selected( 0, $is_test ); ?>>Session data</option>
					<option value="1" <?php selected( 1, $is_test ); ?>>Test data</option>
				</select>
			</label>
			<label><input type="checkbox" name="live" value="1" <?php checked( $live ); ?>> Refresh every 15 seconds</label>
			<button type="submit" class="button">Apply</button>
		</form>

		<div class="tspr-tiles">
			<div class="tspr-tile">
				<span class="tspr-tile-label">Reactions</span>
				<span class="tspr-tile-value"><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></span>
			</div>
			<div class="tspr-tile">
				<span class="tspr-tile-label">Devices that reacted</span>
				<span class="tspr-tile-value"><?php echo esc_html( number_format_i18n( $data['devices'] ) ); ?></span>
			</div>
			<div class="tspr-tile">
				<span class="tspr-tile-label">Busiest moment</span>
				<span class="tspr-tile-value"><?php echo esc_html( $busiest >= 0 ? transpire_fb_bucket_label( $data, $busiest ) : 'None yet' ); ?></span>
				<?php if ( $busiest >= 0 ) : ?>
					<span class="tspr-tile-sub"><?php echo esc_html( number_format_i18n( $data['all'][ $busiest ] ) ); ?> reactions</span>
				<?php endif; ?>
			</div>
			<div class="tspr-tile">
				<span class="tspr-tile-label">Feedback forms</span>
				<span class="tspr-tile-value"><?php echo esc_html( number_format_i18n( count( $feedback ) ) ); ?></span>
			</div>
			<div class="tspr-tile">
				<span class="tspr-tile-label">Average rating</span>
				<span class="tspr-tile-value"><?php echo esc_html( $rated ? number_format_i18n( array_sum( $rated ) / count( $rated ), 1 ) . ' of 5' : 'None yet' ); ?></span>
				<?php if ( $rated ) : ?>
					<span class="tspr-tile-sub">from <?php echo esc_html( number_format_i18n( count( $rated ) ) ); ?> ratings</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="tspr-card">
			<div class="tspr-card-head">
				<h2>Reactions over time</h2>
				<a class="button" href="<?php echo esc_url( $export( 'reactions' ) ); ?>">Download reactions (CSV)</a>
			</div>
			<?php if ( $data['total'] ) : ?>
				<div class="tspr-scroll"><?php transpire_fb_render_timeline( $data ); ?></div>
				<?php transpire_fb_render_table( $data ); ?>
			<?php else : ?>
				<p class="tspr-empty"><?php echo esc_html( $is_test ? 'No test reactions yet. Turn on test mode in Settings, open the page with the reactions widget, and tap a few.' : 'No reactions recorded yet. They will appear here as the audience taps during the session.' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="tspr-card">
			<div class="tspr-card-head">
				<h2>Written feedback</h2>
				<a class="button" href="<?php echo esc_url( $export( 'feedback' ) ); ?>">Download feedback (CSV)</a>
			</div>
			<?php if ( $rated ) : ?>
				<table class="tspr-spread">
					<caption>Ratings given (1 is not at all, 5 is very much)</caption>
					<?php for ( $score = 5; $score >= 1; $score-- ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $score ); ?></th>
							<td class="tspr-spread-bar"><span style="width: <?php echo esc_attr( round( 100 * $spread[ $score ] / max( $spread ), 1 ) ); ?>%"></span></td>
							<td class="tspr-num"><?php echo esc_html( number_format_i18n( $spread[ $score ] ) ); ?></td>
						</tr>
					<?php endfor; ?>
				</table>
			<?php endif; ?>

			<?php if ( $feedback ) : ?>
				<table class="widefat striped tspr-table tspr-feedback">
					<thead>
						<tr>
							<th scope="col">Received</th>
							<th scope="col">Rating</th>
							<th scope="col">Here as</th>
							<th scope="col">Answers</th>
							<th scope="col">Email</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $feedback as $entry ) : ?>
							<?php $answers = json_decode( $entry->answers, true ); ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'M j, g:i A', (int) $entry->created_ts, $data['tz'] ) ); ?></td>
								<td><?php echo esc_html( $entry->rating ? $entry->rating . ' of 5' : '' ); ?></td>
								<td><?php echo esc_html( html_entity_decode( $entry->role, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?></td>
								<td>
									<?php foreach ( is_array( $answers ) ? $answers : array() as $pair ) : ?>
										<p class="tspr-answer"><strong><?php echo esc_html( isset( $pair['q'] ) ? $pair['q'] : '' ); ?></strong><br><?php echo nl2br( esc_html( isset( $pair['a'] ) ? $pair['a'] : '' ) ); ?></p>
									<?php endforeach; ?>
								</td>
								<td><?php echo esc_html( $entry->email ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="tspr-empty">No feedback forms received yet.</p>
			<?php endif; ?>
		</div>

		<?php if ( $is_test && ( $data['total'] || $feedback ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tspr-danger" data-tspr-confirm="Delete all test reactions and test feedback? Session data is not touched.">
				<input type="hidden" name="action" value="transpire_fb_delete_test">
				<?php wp_nonce_field( 'transpire_fb_delete_test' ); ?>
				<button type="submit" class="button button-link-delete">Delete all test data</button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Results: export and clean-up
 * ---------------------------------------------------------------------- */

/** Stops a spreadsheet from running a cell that starts like a formula. */
function transpire_fb_csv_cell( $value ) {
	$value = (string) $value;
	return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
}

function transpire_fb_export() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to export this.' );
	}
	check_admin_referer( 'transpire_fb_export' );

	$session = isset( $_GET['session'] ) ? sanitize_key( wp_unslash( $_GET['session'] ) ) : transpire_fb_session_key();
	$is_test = empty( $_GET['test'] ) ? 0 : 1;
	$type    = isset( $_GET['type'] ) && 'feedback' === $_GET['type'] ? 'feedback' : 'reactions';
	$window  = transpire_fb_window();
	$rows    = array();

	if ( 'reactions' === $type ) {
		$table   = transpire_fb_table( 'reactions' );
		$known   = transpire_fb_reactions();
		$rows[]  = array( 'time', 'minutes_from_start', 'reaction', 'emoji', 'device' );
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT reaction_key, client_hash, created_ts FROM {$table} WHERE session_key = %s AND is_test = %d ORDER BY id ASC", $session, $is_test ) );
		foreach ( (array) $results as $row ) {
			$reaction = isset( $known[ $row->reaction_key ] ) ? $known[ $row->reaction_key ] : null;
			$rows[]   = array(
				wp_date( 'Y-m-d H:i:s', (int) $row->created_ts, $window['tz'] ),
				number_format( ( $row->created_ts - $window['start'] ) / 60, 1, '.', '' ),
				$reaction ? $reaction['label'] : $row->reaction_key,
				$reaction ? $reaction['emoji'] : '',
				$row->client_hash,
			);
		}
	} else {
		$entries   = array_reverse( transpire_fb_feedback_rows( $session, $is_test ) );
		$questions = array();
		foreach ( $entries as $entry ) {
			$entry->pairs = json_decode( $entry->answers, true );
			foreach ( is_array( $entry->pairs ) ? $entry->pairs : array() as $pair ) {
				$questions[ $pair['q'] ] = true;
			}
		}
		$questions = array_keys( $questions );
		$rows[]    = array_merge( array( 'time', 'rating', 'here_as' ), $questions, array( 'email', 'device' ) );
		foreach ( $entries as $entry ) {
			$by_question = array();
			foreach ( is_array( $entry->pairs ) ? $entry->pairs : array() as $pair ) {
				$by_question[ $pair['q'] ] = $pair['a'];
			}
			$line = array(
				wp_date( 'Y-m-d H:i:s', (int) $entry->created_ts, $window['tz'] ),
				$entry->rating ? $entry->rating : '',
				html_entity_decode( $entry->role, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			);
			foreach ( $questions as $question ) {
				$line[] = isset( $by_question[ $question ] ) ? $by_question[ $question ] : '';
			}
			$line[] = $entry->email;
			$line[] = $entry->client_hash;
			$rows[] = $line;
		}
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="transpire-' . $type . '-' . $session . ( $is_test ? '-test' : '' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // lets Excel read the emoji
	foreach ( $rows as $row ) {
		fputcsv( $out, array_map( 'transpire_fb_csv_cell', $row ), ',', '"', '\\' );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_post_transpire_fb_export', 'transpire_fb_export' );

function transpire_fb_delete_test() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to delete this.' );
	}
	check_admin_referer( 'transpire_fb_delete_test' );

	$wpdb->delete( transpire_fb_table( 'reactions' ), array( 'is_test' => 1 ), array( '%d' ) );
	$wpdb->delete( transpire_fb_table( 'feedback' ), array( 'is_test' => 1 ), array( '%d' ) );

	wp_safe_redirect( transpire_fb_results_url( array( 'test' => 1, 'tspr' => 'deleted' ) ) );
	exit;
}
add_action( 'admin_post_transpire_fb_delete_test', 'transpire_fb_delete_test' );
