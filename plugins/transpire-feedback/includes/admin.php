<?php
/**
 * WP Admin screens for Transpire Feedback: the live Start / Stop screen,
 * results, settings, reports and exports.
 * Everything here needs the manage_options capability (site administrators).
 */

defined( 'ABSPATH' ) || exit;

const TRANSPIRE_FB_PLOT_WIDTH = 720;

function transpire_fb_admin_menu() {
	add_menu_page( 'Transpire Feedback', 'Transpire Feedback', 'manage_options', 'transpire-feedback', 'transpire_fb_live_page', 'dashicons-heart', 58 );
	add_submenu_page( 'transpire-feedback', 'Transpire Feedback live session', 'Live session', 'manage_options', 'transpire-feedback', 'transpire_fb_live_page' );
	add_submenu_page( 'transpire-feedback', 'Transpire Feedback results', 'Results', 'manage_options', 'transpire-feedback-results', 'transpire_fb_results_page' );
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

/** One sentence on what is open right now, shown at the top of every screen. */
function transpire_fb_state_sentence() {
	$options = transpire_fb_options();
	$window  = transpire_fb_window();
	$state   = transpire_fb_state();
	$when    = wp_date( 'l, F j, Y, g:i A', $window['start'], $window['tz'] ) . ' to ' . wp_date( 'g:i A T', $window['end'], $window['tz'] );

	if ( $state['run'] ) {
		$now = $state['is_test']
			? 'A rehearsal is being recorded: the reaction buttons are open, and everything sent is saved as practice data.'
			: 'The session is being recorded: the reaction buttons are open.';
	} elseif ( 'after' === $state['phase'] ) {
		$now = 'Reactions are closed.' . ( $state['feedback_open'] ? ' The feedback form stays open until ' . wp_date( 'F j', $state['feedback_until'], $window['tz'] ) . '.' : '' );
	} else {
		$now = 'Reactions are closed until someone presses Start' . ( empty( $options['auto_start'] ) ? '.' : ', or until the scheduled start, when recording begins by itself.' );
	}
	return $options['session_name'] . ' is scheduled for ' . $when . '. ' . $now;
}

/** A run's name as typed, with emoji restored. */
function transpire_fb_run_label( $run ) {
	$label = html_entity_decode( (string) $run->label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return '' !== $label ? $label : ( (int) $run->is_test ? 'Rehearsal' : 'Session' );
}

/** "Wed, Oct 14, 2:05 PM to 2:20 PM (15 min)", or "... (recording, 4 min so far)". */
function transpire_fb_run_when( $run, $tz ) {
	$from    = (int) $run->started_ts;
	$to      = (int) $run->ended_ts;
	$minutes = (int) floor( ( ( $to ? $to : time() ) - $from ) / 60 );
	$text    = wp_date( 'D, M j, g:i A', $from, $tz );
	if ( $to ) {
		$same = wp_date( 'Ymd', $from, $tz ) === wp_date( 'Ymd', $to, $tz );
		return $text . ' to ' . wp_date( $same ? 'g:i A' : 'M j, g:i A', $to, $tz ) . ' (' . ( $minutes ? $minutes . ' min' : 'under a minute' ) . ')';
	}
	return $text . ' (recording, ' . $minutes . ' min so far)';
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
		'auto_start'    => empty( $input['auto_start'] ) ? 0 : 1,
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
			<p><code>[transpire_reactions]</code> shows the reaction buttons. They open when someone presses Start on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=transpire-feedback' ) ); ?>">Live session</a> screen and close at Stop.</p>
			<p><code>[transpire_feedback]</code> shows the written feedback form. It opens with the first Start.</p>
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
						<p class="description">Shown above the widgets and used as the name of each real run. Results are filed under this name, so changing it starts a new, empty set of results. Earlier results stay available on the Results screen.</p>
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
						<p class="description">The audience sees the start time while reactions are closed. Recording itself follows the Start and Stop button.</p>
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

			<h2>Safety net</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Start by itself</th>
					<td>
						<label><input type="checkbox" name="auto_start" value="1" <?php checked( ! empty( $options['auto_start'] ) ); ?>> If nobody has pressed Start by the scheduled start time, start recording automatically</label>
						<p class="description">A rehearsal still running at that moment is stopped first, so practice data never mixes with the real record. Stopping is always by hand. Any run nobody stops closes by itself after <?php echo esc_html( TRANSPIRE_FB_MAX_RUN / HOUR_IN_SECONDS ); ?> hours.</p>
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

/** SQL condition picking one run's rows, or one session's real or test rows. */
function transpire_fb_where( $session, $is_test, $run = null ) {
	global $wpdb;
	return $run
		? $wpdb->prepare( 'run_id = %d', (int) $run->id )
		: $wpdb->prepare( 'session_key = %s AND is_test = %d', $session, $is_test );
}

/**
 * Everything the reaction charts need. With a run, the chart covers that run
 * from Start to Stop (or to now while it is recording), in columns as fine as
 * ten seconds for a short run. Without one, it covers the session as set in
 * Settings.
 */
function transpire_fb_results_data( $session, $is_test, $run = null ) {
	global $wpdb;
	$table  = transpire_fb_table( 'reactions' );
	$window = transpire_fb_window();
	$where  = transpire_fb_where( $session, $is_test, $run );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
	$span = $wpdb->get_row( "SELECT COUNT(*) AS total, COUNT(DISTINCT client_hash) AS devices, MIN(created_ts) AS first_ts, MAX(created_ts) AS last_ts FROM {$table} WHERE {$where}" );

	$totals = array();
	foreach ( (array) $wpdb->get_results( "SELECT reaction_key, COUNT(*) AS total FROM {$table} WHERE {$where} GROUP BY reaction_key" ) as $row ) {
		$totals[ $row->reaction_key ] = (int) $row->total;
	}
	// phpcs:enable

	$total = $span ? (int) $span->total : 0;
	$sizes = array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 21600, 86400 );
	if ( $run ) {
		// A recording run grows to the right as time passes.
		$start = (int) $run->started_ts;
		$end   = (int) $run->ended_ts ? (int) $run->ended_ts : time();
		$end   = max( $end, $total ? (int) $span->last_ts + 1 : 0, $start + ( (int) $run->ended_ts ? 2 : 5 ) * MINUTE_IN_SECONDS );
		$sizes = array_merge( array( 10, 15, 30 ), $sizes );
	} else {
		// The chart covers the session as set in Settings, stretched to take in any reaction outside it.
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
	}

	// The finest columns that keep the chart to 120 of them.
	$bucket = end( $sizes );
	foreach ( $sizes as $size ) {
		if ( ( $end - $start ) / $size <= 120 ) {
			$bucket = $size;
			break;
		}
	}
	if ( $run ) {
		$start = (int) floor( $start / $bucket ) * $bucket;
	}
	$buckets = max( 1, (int) ceil( ( $end - $start ) / $bucket ) );

	$rows   = transpire_fb_result_rows( $totals );
	$matrix = array();
	foreach ( array_keys( $rows ) as $key ) {
		$matrix[ $key ] = array_fill( 0, $buckets, 0 );
	}
	$all = array_fill( 0, $buckets, 0 );

	$counts = $wpdb->get_results( $wpdb->prepare( "SELECT reaction_key, FLOOR((created_ts - %d) / %d) AS bucket, COUNT(*) AS total FROM {$table} WHERE {$where} AND created_ts >= %d AND created_ts < %d GROUP BY reaction_key, bucket", $start, $bucket, $start, $start + $buckets * $bucket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
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
		'where'   => $where,
	);
}

function transpire_fb_feedback_rows( $session, $is_test, $limit = 0, $run = null ) {
	global $wpdb;
	$table = transpire_fb_table( 'feedback' );
	$sql   = "SELECT * FROM {$table} WHERE " . transpire_fb_where( $session, $is_test, $run ) . ' ORDER BY id DESC';
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
	if ( $data['bucket'] < 60 ) {
		return wp_date( 'g:i:s A', $from, $data['tz'] );
	}
	if ( 60 === $data['bucket'] ) {
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
	foreach ( array( 60, 120, 300, 600, 900, 1800, 3600, 7200, 14400, 28800, 86400 ) as $seconds ) {
		if ( $span / $seconds <= 8 ) {
			$every = $seconds;
			break;
		}
	}
	$format = $every >= DAY_IN_SECONDS ? 'M j' : 'g:i A';

	$svg = sprintf( '<svg class="tspr-plot tspr-plot-axis" width="%1$d" height="22" viewBox="0 0 %1$d 22" aria-hidden="true" focusable="false">', $width );
	// Ticks fall on whole clock times (9:17, not 9:16:10), counted in the session's timezone.
	$utc_offset = $data['tz']->getOffset( new DateTime( '@' . $data['start'] ) );
	$first      = (int) ( ceil( ( $data['start'] + $utc_offset ) / $every ) * $every - $utc_offset );
	for ( $offset = $first - $data['start']; $offset <= $span; $offset += $every ) {
		$x      = round( ( $offset / $span ) * $width, 1 );
		$anchor = $x < 30 ? 'start' : ( $x > $width - 30 ? 'end' : 'middle' );
		$svg   .= sprintf( '<line class="tspr-plot-base" x1="%1$s" x2="%1$s" y1="0" y2="4"/>', min( $width - 0.5, max( 0.5, $x ) ) );
		$svg   .= sprintf( '<text class="tspr-plot-tick" x="%s" y="17" text-anchor="%s">%s</text>', $x, $anchor, esc_html( wp_date( $format, $data['start'] + $offset, $data['tz'] ) ) );
	}
	return $svg . '</svg>';
}

/** "minute", "10 seconds", "5 minutes": the width of one chart column, in words. */
function transpire_fb_bucket_phrase( $bucket ) {
	if ( $bucket < 60 ) {
		return $bucket . ' seconds';
	}
	if ( $bucket < HOUR_IN_SECONDS ) {
		return 60 === $bucket ? 'minute' : ( $bucket / 60 ) . ' minutes';
	}
	if ( $bucket < DAY_IN_SECONDS ) {
		return HOUR_IN_SECONDS === $bucket ? 'hour' : ( $bucket / HOUR_IN_SECONDS ) . ' hours';
	}
	return 'day';
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
	$unit    = transpire_fb_bucket_phrase( $data['bucket'] );
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
function transpire_fb_render_table( $data, $open = false ) {
	// One row per minute for short columns, five-minute rows for one-minute columns, so the table stays readable.
	if ( $data['bucket'] < 60 ) {
		$group = (int) ( 60 / $data['bucket'] );
	} else {
		$group = 60 === $data['bucket'] ? 5 : 1;
	}
	?>
	<details class="tspr-details" <?php echo $open ? 'open' : ''; ?>>
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
	return add_query_arg( array_merge( array( 'page' => 'transpire-feedback-results' ), $args ), admin_url( 'admin.php' ) );
}

/** Ratings given in a set of feedback entries: the list and the count per score. */
function transpire_fb_ratings( $feedback ) {
	$rated  = array();
	$spread = array_fill( 1, 5, 0 );
	foreach ( $feedback as $entry ) {
		if ( $entry->rating >= 1 && $entry->rating <= 5 ) {
			$rated[] = (int) $entry->rating;
			$spread[ (int) $entry->rating ]++;
		}
	}
	return array( $rated, $spread );
}

/** The row of headline numbers, shared by the Results screen and the report. */
function transpire_fb_render_tiles( $data, $feedback ) {
	list( $rated ) = transpire_fb_ratings( $feedback );
	$busiest       = $data['total'] ? (int) array_search( max( $data['all'] ), $data['all'], true ) : -1;
	?>
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
	<?php
}

/** Report links for one run: open in the browser (to read or print) or download as a file. */
function transpire_fb_report_url( $run, $download = false ) {
	$args = array(
		'action' => 'transpire_fb_report',
		'run'    => (int) $run->id,
	);
	if ( $download ) {
		$args['download'] = 1;
	}
	return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'transpire_fb_report' );
}

function transpire_fb_export_url( $type, $view ) {
	return wp_nonce_url( add_query_arg( array_merge( $view, array( 'action' => 'transpire_fb_export', 'type' => $type ) ), admin_url( 'admin-post.php' ) ), 'transpire_fb_export' );
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
	$runs    = transpire_fb_runs();
	$run     = empty( $_GET['run'] ) ? null : transpire_fb_get_run( absint( $_GET['run'] ) );
	$view    = array(
		'session' => $session,
		'test'    => $is_test,
	);
	if ( $run ) {
		// One run on its own: the session and data-kind filters do not apply.
		$session = $run->session_key;
		$is_test = (int) $run->is_test;
		$view    = array( 'run' => (int) $run->id );
	}

	$data     = transpire_fb_results_data( $session, $is_test, $run );
	$feedback = transpire_fb_feedback_rows( $session, $is_test, 500, $run );
	$export   = function ( $type ) use ( $view ) {
		return transpire_fb_export_url( $type, $view );
	};

	list( $rated, $spread ) = transpire_fb_ratings( $feedback );
	?>
	<div class="wrap tspr-admin" <?php echo $live ? 'data-tspr-live="15"' : ''; ?>>
		<h1>Transpire Feedback results</h1>

		<?php if ( 'deleted' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>Test data deleted.</p></div>
		<?php endif; ?>

		<p class="tspr-lede"><?php echo esc_html( transpire_fb_state_sentence() ); ?></p>

		<form method="get" class="tspr-filters">
			<input type="hidden" name="page" value="transpire-feedback-results">
			<?php if ( $runs ) : ?>
				<label>Record
					<select name="run">
						<option value="0">Everything for the session chosen here</option>
						<?php foreach ( $runs as $item ) : ?>
							<option value="<?php echo esc_attr( $item->id ); ?>" <?php selected( $run ? (int) $run->id : 0, (int) $item->id ); ?>><?php echo esc_html( ( (int) $item->is_test ? 'Rehearsal: ' : '' ) . transpire_fb_run_label( $item ) . ', ' . transpire_fb_run_when( $item, $data['tz'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
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
					<option value="1" <?php selected( 1, $is_test ); ?>>Rehearsal and test data</option>
				</select>
			</label>
			<label><input type="checkbox" name="live" value="1" <?php checked( $live ); ?>> Refresh every 15 seconds</label>
			<button type="submit" class="button">Apply</button>
		</form>

		<?php if ( $run ) : ?>
			<div class="tspr-card tspr-run-head">
				<div class="tspr-card-head">
					<h2><?php echo esc_html( ( (int) $run->is_test ? 'Rehearsal: ' : '' ) . transpire_fb_run_label( $run ) ); ?></h2>
					<span>
						<a class="button button-primary" href="<?php echo esc_url( transpire_fb_report_url( $run ) ); ?>" target="_blank" rel="noopener">Open report</a>
						<a class="button" href="<?php echo esc_url( transpire_fb_report_url( $run, true ) ); ?>">Download report</a>
					</span>
				</div>
				<p class="tspr-empty"><?php echo esc_html( transpire_fb_run_when( $run, $data['tz'] ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php transpire_fb_render_tiles( $data, $feedback ); ?>

		<div class="tspr-card">
			<div class="tspr-card-head">
				<h2>Reactions over time</h2>
				<a class="button" href="<?php echo esc_url( $export( 'reactions' ) ); ?>">Download reactions (CSV)</a>
			</div>
			<?php if ( $data['total'] ) : ?>
				<div class="tspr-scroll"><?php transpire_fb_render_timeline( $data ); ?></div>
				<?php transpire_fb_render_table( $data ); ?>
			<?php else : ?>
				<p class="tspr-empty"><?php echo esc_html( $is_test ? 'No rehearsal reactions here yet. Press Start on the Live session screen with "Rehearsal" ticked, open the page with the reactions widget, and tap a few.' : 'No reactions recorded yet. They will appear here as the audience taps during the session.' ); ?></p>
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
	$run     = empty( $_GET['run'] ) ? null : transpire_fb_get_run( absint( $_GET['run'] ) );
	$type    = isset( $_GET['type'] ) && 'feedback' === $_GET['type'] ? 'feedback' : 'reactions';
	$window  = transpire_fb_window();
	$rows    = array();
	$name    = $session . ( $is_test ? '-test' : '' );
	// Minutes are counted from the press of Start for one run, else from the scheduled start.
	$zero = $window['start'];
	if ( $run ) {
		$session = $run->session_key;
		$is_test = (int) $run->is_test;
		$name    = $session . '-' . ( $is_test ? 'rehearsal-' : '' ) . 'run' . (int) $run->id;
		$zero    = (int) $run->started_ts;
	}
	$where = transpire_fb_where( $session, $is_test, $run );

	if ( 'reactions' === $type ) {
		$table   = transpire_fb_table( 'reactions' );
		$known   = transpire_fb_reactions();
		$rows[]  = array( 'time', 'minutes_from_start', 'reaction', 'emoji', 'device' );
		$results = $wpdb->get_results( "SELECT reaction_key, client_hash, created_ts FROM {$table} WHERE {$where} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
		foreach ( (array) $results as $row ) {
			$reaction = isset( $known[ $row->reaction_key ] ) ? $known[ $row->reaction_key ] : null;
			$rows[]   = array(
				wp_date( 'Y-m-d H:i:s', (int) $row->created_ts, $window['tz'] ),
				number_format( ( $row->created_ts - $zero ) / 60, 1, '.', '' ),
				$reaction ? $reaction['label'] : $row->reaction_key,
				$reaction ? $reaction['emoji'] : '',
				$row->client_hash,
			);
		}
	} else {
		$entries   = array_reverse( transpire_fb_feedback_rows( $session, $is_test, 0, $run ) );
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
	header( 'Content-Disposition: attachment; filename="transpire-' . $type . '-' . $name . '.csv"' );
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
	// A rehearsal still being recorded keeps its run, so recording carries on.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . transpire_fb_table( 'runs' ) . ' WHERE is_test = 1 AND ended_ts > %d', 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.

	wp_safe_redirect( transpire_fb_results_url( array( 'test' => 1, 'tspr' => 'deleted' ) ) );
	exit;
}
add_action( 'admin_post_transpire_fb_delete_test', 'transpire_fb_delete_test' );

/* -------------------------------------------------------------------------
 * Live session: the Start / Stop button
 * ---------------------------------------------------------------------- */

function transpire_fb_live_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'transpire-feedback' ), $args ), admin_url( 'admin.php' ) );
}

/** Start, Stop, or remove a finished rehearsal. */
function transpire_fb_run_action() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to start or stop the session.' );
	}
	check_admin_referer( 'transpire_fb_run' );

	$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
	$args = array();

	if ( 'start' === $op ) {
		$rehearsal = ! empty( $_POST['rehearsal'] );
		$label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( '' === $label ) {
			$options = transpire_fb_options();
			$label   = $rehearsal ? 'Rehearsal' : $options['session_name'];
		}
		transpire_fb_start_run( $label, $rehearsal, 'button' );
		$args = array( 'tspr' => 'started' );
	} elseif ( 'stop' === $op ) {
		$run = transpire_fb_active_run();
		transpire_fb_stop_run();
		$args = $run ? array(
			'tspr' => 'stopped',
			'run'  => (int) $run->id,
		) : array();
	} elseif ( 'delete' === $op ) {
		// Only finished rehearsals can be removed here. Real records are kept.
		$run = transpire_fb_get_run( isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0 );
		if ( $run && (int) $run->is_test && (int) $run->ended_ts ) {
			$wpdb->delete( transpire_fb_table( 'reactions' ), array( 'run_id' => (int) $run->id ), array( '%d' ) );
			$wpdb->delete( transpire_fb_table( 'feedback' ), array( 'run_id' => (int) $run->id ), array( '%d' ) );
			$wpdb->delete( transpire_fb_table( 'runs' ), array( 'id' => (int) $run->id ), array( '%d' ) );
			$args = array( 'tspr' => 'removed' );
		}
	}

	wp_safe_redirect( transpire_fb_live_url( $args ) );
	exit;
}
add_action( 'admin_post_transpire_fb_run', 'transpire_fb_run_action' );

/** Reactions, devices and feedback forms per run, keyed by run id. */
function transpire_fb_run_counts() {
	global $wpdb;
	$counts    = array();
	$reactions = transpire_fb_table( 'reactions' );
	$feedback  = transpire_fb_table( 'feedback' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
	foreach ( (array) $wpdb->get_results( "SELECT run_id, COUNT(*) AS total, COUNT(DISTINCT client_hash) AS devices FROM {$reactions} WHERE run_id > 0 GROUP BY run_id" ) as $row ) {
		$counts[ (int) $row->run_id ] = array(
			'reactions' => (int) $row->total,
			'devices'   => (int) $row->devices,
			'feedback'  => 0,
		);
	}
	foreach ( (array) $wpdb->get_results( "SELECT run_id, COUNT(*) AS total FROM {$feedback} WHERE run_id > 0 GROUP BY run_id" ) as $row ) {
		if ( ! isset( $counts[ (int) $row->run_id ] ) ) {
			$counts[ (int) $row->run_id ] = array(
				'reactions' => 0,
				'devices'   => 0,
				'feedback'  => 0,
			);
		}
		$counts[ (int) $row->run_id ]['feedback'] = (int) $row->total;
	}
	// phpcs:enable
	return $counts;
}

/** The chart on the Live session screen, also sent fresh every few seconds while recording. */
function transpire_fb_render_live_chart( $data ) {
	if ( $data['total'] ) {
		echo '<div class="tspr-scroll">';
		transpire_fb_render_timeline( $data );
		echo '</div>';
	} else {
		echo '<p class="tspr-empty">Waiting for the first reaction. Open the page with the reactions widget on a phone and tap one; it shows up here within a few seconds.</p>';
	}
}

function transpire_fb_live_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$window = transpire_fb_window();
	$state  = transpire_fb_state();
	$run    = $state['run'];
	$notice = isset( $_GET['tspr'] ) ? sanitize_key( wp_unslash( $_GET['tspr'] ) ) : '';
	$saved  = ( 'stopped' === $notice && ! empty( $_GET['run'] ) ) ? transpire_fb_get_run( absint( $_GET['run'] ) ) : null;
	$runs   = transpire_fb_runs();
	$counts = transpire_fb_run_counts();
	$data   = $run ? transpire_fb_results_data( $run->session_key, (int) $run->is_test, $run ) : null;
	// Before the session's own day, a press of Start is most likely a rehearsal.
	$before_day = wp_date( 'Ymd', time(), $window['tz'] ) < wp_date( 'Ymd', $window['start'], $window['tz'] );

	$attrs = '';
	if ( $run ) {
		$attrs = sprintf(
			' data-tspr-run="%d" data-tspr-ajax="%s" data-tspr-nonce="%s" data-tspr-since="%d" data-tspr-now="%d"',
			(int) $run->id,
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'transpire_fb_live' ) ),
			(int) $run->started_ts,
			time()
		);
	}
	?>
	<div class="wrap tspr-admin tspr-live"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
		<h1>Live session</h1>

		<?php if ( 'started' === $notice && $run ) : ?>
			<div class="notice notice-success is-dismissible"><p>Recording started. The reaction buttons are open for everyone on the page.</p></div>
		<?php elseif ( $saved ) : ?>
			<div class="notice notice-success">
				<p><strong>Saved: <?php echo esc_html( transpire_fb_run_label( $saved ) ); ?></strong>, <?php echo esc_html( transpire_fb_run_when( $saved, $window['tz'] ) ); ?>. The reaction buttons are closed.</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( transpire_fb_report_url( $saved ) ); ?>" target="_blank" rel="noopener">Open the report</a>
					<a class="button" href="<?php echo esc_url( transpire_fb_report_url( $saved, true ) ); ?>">Download the report</a>
					<a class="button" href="<?php echo esc_url( transpire_fb_results_url( array( 'run' => (int) $saved->id ) ) ); ?>">See it under Results</a>
				</p>
			</div>
		<?php elseif ( 'removed' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>Rehearsal removed, with its reactions and feedback.</p></div>
		<?php endif; ?>

		<p class="tspr-lede"><?php echo esc_html( transpire_fb_state_sentence() ); ?></p>

		<div class="tspr-card tspr-control<?php echo $run ? ' is-recording' : ''; ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo $run ? 'data-tspr-confirm="Stop recording? The reaction buttons close for everyone, and this run is saved with its report."' : ''; ?>>
				<input type="hidden" name="action" value="transpire_fb_run">
				<input type="hidden" name="op" value="<?php echo $run ? 'stop' : 'start'; ?>">
				<?php wp_nonce_field( 'transpire_fb_run' ); ?>

				<?php if ( $run ) : ?>
					<p class="tspr-rec">
						<span class="tspr-rec-dot" aria-hidden="true"></span>
						<?php echo esc_html( (int) $run->is_test ? 'Recording a rehearsal' : 'Recording' ); ?>: <strong><?php echo esc_html( transpire_fb_run_label( $run ) ); ?></strong>
					</p>
					<p class="tspr-rec-meta">
						Started <?php echo esc_html( wp_date( 'g:i A', (int) $run->started_ts, $window['tz'] ) ); ?><?php echo 'schedule' === $run->started_by ? ' automatically at the scheduled time' : ''; ?>
						· <span data-tspr-elapsed><?php echo esc_html( gmdate( 'G:i:s', time() - (int) $run->started_ts ) ); ?></span> elapsed
						· <strong data-tspr-total><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></strong> reactions
						from <strong data-tspr-devices><?php echo esc_html( number_format_i18n( $data['devices'] ) ); ?></strong> devices
					</p>
					<button type="submit" class="tspr-toggle is-stop"><span aria-hidden="true">■</span> Stop session</button>
				<?php else : ?>
					<div class="tspr-start-fields">
						<label for="tspr-run-label">Name for this record</label>
						<input type="text" id="tspr-run-label" name="label" class="regular-text" maxlength="190" placeholder="Blank uses &quot;Rehearsal&quot; or the session name">
						<label class="tspr-check"><input type="checkbox" name="rehearsal" value="1" <?php checked( $before_day ); ?>> Rehearsal: keep these taps apart from the real session's results</label>
					</div>
					<button type="submit" class="tspr-toggle is-start"><span aria-hidden="true">▶</span> Start session</button>
				<?php endif; ?>
			</form>
			<p class="description">
				<?php if ( $run ) : ?>
					Phones on the page close their buttons within about 45 seconds of Stop, or at once on their next tap. The run is then saved below with its report.
				<?php else : ?>
					Start opens the reaction buttons for everyone on the page; open phones pick it up within about 15 seconds. They stay open until you press Stop, which appears here in the same place. Each start-to-stop is saved below as its own record, with a report for synthesis and reflection.
				<?php endif; ?>
			</p>
		</div>

		<?php if ( $run ) : ?>
			<div class="tspr-card">
				<div class="tspr-card-head">
					<h2>Responses over time</h2>
					<span class="tspr-empty" data-tspr-updated>Updates every 5 seconds</span>
				</div>
				<div data-tspr-livechart><?php transpire_fb_render_live_chart( $data ); ?></div>
			</div>
		<?php endif; ?>

		<div class="tspr-card">
			<h2>Saved records</h2>
			<?php if ( $runs ) : ?>
				<table class="widefat striped tspr-table tspr-runs">
					<thead>
						<tr>
							<th scope="col">Record</th>
							<th scope="col">When</th>
							<th scope="col" class="tspr-num">Reactions</th>
							<th scope="col" class="tspr-num">Devices</th>
							<th scope="col" class="tspr-num">Feedback</th>
							<th scope="col">Output</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $runs as $item ) : ?>
							<?php
							$count = isset( $counts[ (int) $item->id ] ) ? $counts[ (int) $item->id ] : array(
								'reactions' => 0,
								'devices'   => 0,
								'feedback'  => 0,
							);
							$view  = array( 'run' => (int) $item->id );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( transpire_fb_run_label( $item ) ); ?></strong>
									<?php if ( (int) $item->is_test ) : ?>
										<span class="tspr-badge">Rehearsal</span>
									<?php endif; ?>
									<?php if ( ! (int) $item->ended_ts ) : ?>
										<span class="tspr-badge is-live">Recording</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( transpire_fb_run_when( $item, $window['tz'] ) ); ?></td>
								<td class="tspr-num"><?php echo esc_html( number_format_i18n( $count['reactions'] ) ); ?></td>
								<td class="tspr-num"><?php echo esc_html( number_format_i18n( $count['devices'] ) ); ?></td>
								<td class="tspr-num"><?php echo esc_html( number_format_i18n( $count['feedback'] ) ); ?></td>
								<td class="tspr-links">
									<a href="<?php echo esc_url( transpire_fb_report_url( $item ) ); ?>" target="_blank" rel="noopener">Report</a>
									· <a href="<?php echo esc_url( transpire_fb_report_url( $item, true ) ); ?>">Download</a>
									· <a href="<?php echo esc_url( transpire_fb_export_url( 'reactions', $view ) ); ?>">Reactions CSV</a>
									· <a href="<?php echo esc_url( transpire_fb_export_url( 'feedback', $view ) ); ?>">Feedback CSV</a>
									<?php if ( (int) $item->is_test && (int) $item->ended_ts ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tspr-inline" data-tspr-confirm="Remove this rehearsal and its reactions and feedback?">
											<input type="hidden" name="action" value="transpire_fb_run">
											<input type="hidden" name="op" value="delete">
											<input type="hidden" name="run" value="<?php echo esc_attr( $item->id ); ?>">
											<?php wp_nonce_field( 'transpire_fb_run' ); ?>
											· <button type="submit" class="button-link button-link-delete">Remove</button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="tspr-empty">Nothing recorded yet. Each press of Start, up to the press of Stop, is saved here as its own record.</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/** Fresh numbers and chart for the Live session screen while a run is recording. */
function transpire_fb_ajax_live() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}
	check_ajax_referer( 'transpire_fb_live' );

	transpire_fb_active_run(); // closes a run that has passed the maximum length
	$run = transpire_fb_get_run( isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0 );
	if ( ! $run ) {
		wp_send_json_error( null, 404 );
	}
	$data = transpire_fb_results_data( $run->session_key, (int) $run->is_test, $run );

	ob_start();
	transpire_fb_render_live_chart( $data );
	wp_send_json_success(
		array(
			'active'  => ! (int) $run->ended_ts,
			'total'   => number_format_i18n( $data['total'] ),
			'devices' => number_format_i18n( $data['devices'] ),
			'html'    => ob_get_clean(),
		)
	);
}
add_action( 'wp_ajax_transpire_fb_live', 'transpire_fb_ajax_live' );

/* -------------------------------------------------------------------------
 * Report: one run as a self-contained page for synthesis and reflection
 * ---------------------------------------------------------------------- */

/**
 * The busiest clock minutes, in time order, each with the reactions that
 * filled it, busiest first. Also each reaction's own busiest minute.
 */
function transpire_fb_moments( $data, $limit ) {
	global $wpdb;
	$table   = transpire_fb_table( 'reactions' );
	$rows    = (array) $wpdb->get_results( "SELECT FLOOR(created_ts / 60) AS minute, reaction_key, COUNT(*) AS total FROM {$table} WHERE {$data['where']} GROUP BY minute, reaction_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where is prepared.
	$minutes = array();
	$peaks   = array();
	foreach ( $rows as $row ) {
		$minute = (int) $row->minute;
		$count  = (int) $row->total;
		if ( ! isset( $minutes[ $minute ] ) ) {
			$minutes[ $minute ] = array(
				'ts'    => $minute * 60,
				'total' => 0,
				'mix'   => array(),
			);
		}
		$minutes[ $minute ]['total']                     += $count;
		$minutes[ $minute ]['mix'][ $row->reaction_key ] = $count;
		if ( ! isset( $peaks[ $row->reaction_key ] ) || $count > $peaks[ $row->reaction_key ]['total'] || ( $count === $peaks[ $row->reaction_key ]['total'] && $minute * 60 < $peaks[ $row->reaction_key ]['ts'] ) ) {
			$peaks[ $row->reaction_key ] = array(
				'ts'    => $minute * 60,
				'total' => $count,
			);
		}
	}
	uasort(
		$minutes,
		function ( $a, $b ) {
			return $b['total'] === $a['total'] ? $a['ts'] - $b['ts'] : $b['total'] - $a['total'];
		}
	);
	$top = array_slice( $minutes, 0, $limit );
	usort(
		$top,
		function ( $a, $b ) {
			return $a['ts'] - $b['ts'];
		}
	);
	foreach ( $top as &$moment ) {
		arsort( $moment['mix'] );
	}
	unset( $moment );
	return array( $top, $peaks );
}

function transpire_fb_report() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to see this report.' );
	}
	check_admin_referer( 'transpire_fb_report' );
	$run = transpire_fb_get_run( isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0 );
	if ( ! $run ) {
		wp_die( 'That record was not found.' );
	}

	$data     = transpire_fb_results_data( $run->session_key, (int) $run->is_test, $run );
	$feedback = array_reverse( transpire_fb_feedback_rows( $run->session_key, (int) $run->is_test, 0, $run ) );
	$label    = transpire_fb_run_label( $run );
	$tz       = $data['tz'];

	list( $rated, $spread ) = transpire_fb_ratings( $feedback );
	list( $moments, $peaks ) = transpire_fb_moments( $data, 6 );

	// Answers grouped by question, and how many people gave each role.
	$by_question = array();
	$roles       = array();
	foreach ( $feedback as $entry ) {
		$pairs = json_decode( $entry->answers, true );
		foreach ( is_array( $pairs ) ? $pairs : array() as $pair ) {
			if ( isset( $pair['q'], $pair['a'] ) ) {
				$by_question[ $pair['q'] ][] = $pair['a'];
			}
		}
		$role = html_entity_decode( (string) $entry->role, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' !== $role ) {
			$roles[ $role ] = isset( $roles[ $role ] ) ? $roles[ $role ] + 1 : 1;
		}
	}
	arsort( $roles );

	$name = function ( $key ) use ( $data ) {
		return isset( $data['rows'][ $key ] ) ? trim( $data['rows'][ $key ]['emoji'] . ' ' . $data['rows'][ $key ]['label'] ) : $key;
	};

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	if ( ! empty( $_GET['download'] ) ) {
		$file = 'transpire-report-' . $run->session_key . '-' . ( (int) $run->is_test ? 'rehearsal-' : '' ) . wp_date( 'Y-m-d-Hi', (int) $run->started_ts, $tz ) . '.html';
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
	}
	$css = (string) file_get_contents( dirname( TRANSPIRE_FB_FILE ) . '/assets/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
	?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $label ); ?>: listening session report</title>
<style>
<?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own stylesheet. ?>
body { margin: 0; background: #f6f7f7; color: #1d2327; font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.tspr-report { max-width: 1042px; margin: 0 auto; padding: 32px 20px 48px; }
.tspr-report h1 { margin: 4px 0 6px; font-size: 28px; line-height: 1.2; }
.tspr-report h3 { margin: 18px 0 6px; font-size: 14px; }
.tspr-report .tspr-eyebrow { margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #50575e; }
.tspr-report table.tspr-table { width: 100%; border-collapse: collapse; }
.tspr-report table.tspr-table th, .tspr-report table.tspr-table td { padding: 6px 10px; border-bottom: 1px solid #e1e0d9; text-align: left; }
.tspr-report table.tspr-table .tspr-num { text-align: right; }
.tspr-report .tspr-answers { margin: 0; padding-left: 20px; }
.tspr-report .tspr-answers li { margin: 0 0 8px; white-space: pre-line; }
.tspr-report footer { max-width: 900px; margin-top: 24px; font-size: 12px; color: #50575e; }
@media print {
	body { background: #fff; }
	.tspr-report { padding: 0; }
	.tspr-admin .tspr-card, .tspr-admin .tspr-tile { break-inside: avoid; }
	.tspr-admin .tspr-scroll { overflow: visible; }
	.tspr-admin .tspr-chart { zoom: 0.72; }
}
</style>
</head>
<body>
<main class="tspr-admin tspr-report">
	<p class="tspr-eyebrow">Transpire listening session · <?php echo esc_html( (int) $run->is_test ? 'Rehearsal record' : 'Session record' ); ?></p>
	<h1><?php echo esc_html( $label ); ?></h1>
	<p class="tspr-lede">
		<?php echo esc_html( transpire_fb_run_when( $run, $tz ) ); ?>.
		Reactions were open from the press of Start<?php echo 'schedule' === $run->started_by ? ' (automatic, at the scheduled time)' : ''; ?> to the press of Stop. Written feedback sent after Stop is included up to the moment this report was made.
	</p>

	<?php transpire_fb_render_tiles( $data, $feedback ); ?>

	<section class="tspr-card">
		<h2>Reactions over time</h2>
		<?php if ( $data['total'] ) : ?>
			<div class="tspr-scroll"><?php transpire_fb_render_timeline( $data ); ?></div>
			<?php transpire_fb_render_table( $data, true ); ?>
		<?php else : ?>
			<p class="tspr-empty">No reactions were recorded in this run.</p>
		<?php endif; ?>
	</section>

	<?php if ( $moments ) : ?>
		<section class="tspr-card">
			<h2>Moments to revisit</h2>
			<p class="tspr-empty">The busiest minutes, in the order they happened. Beside each one, note what was being said in the room at that time.</p>
			<ol class="tspr-moments">
				<?php foreach ( $moments as $moment ) : ?>
					<li>
						<strong><?php echo esc_html( wp_date( 'g:i A', $moment['ts'], $tz ) ); ?></strong>
						<span class="tspr-moment-total"><?php echo esc_html( number_format_i18n( $moment['total'] ) ); ?> reactions</span>
						<span class="tspr-moment-mix">
							<?php
							$parts = array();
							foreach ( $moment['mix'] as $key => $count ) {
								$parts[] = $name( $key ) . ' ' . number_format_i18n( $count );
							}
							echo esc_html( implode( ' · ', $parts ) );
							?>
						</span>
						<span class="tspr-note">What was happening:</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>

		<section class="tspr-card">
			<h2>Each reaction</h2>
			<table class="tspr-table">
				<thead>
					<tr>
						<th scope="col">Reaction</th>
						<th scope="col" class="tspr-num">Times tapped</th>
						<th scope="col" class="tspr-num">Share</th>
						<th scope="col">Its busiest minute</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['rows'] as $key => $row ) : ?>
						<?php $total = isset( $data['totals'][ $key ] ) ? $data['totals'][ $key ] : 0; ?>
						<tr>
							<td><?php echo esc_html( $name( $key ) ); ?></td>
							<td class="tspr-num"><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
							<td class="tspr-num"><?php echo esc_html( $data['total'] ? round( 100 * $total / $data['total'] ) . '%' : '' ); ?></td>
							<td><?php echo esc_html( isset( $peaks[ $key ] ) ? wp_date( 'g:i A', $peaks[ $key ]['ts'], $tz ) . ' (' . number_format_i18n( $peaks[ $key ]['total'] ) . ')' : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<section class="tspr-card">
		<h2>Written feedback</h2>
		<?php if ( $feedback ) : ?>
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
			<?php if ( $roles ) : ?>
				<p><strong>Here as:</strong>
					<?php
					$parts = array();
					foreach ( $roles as $role => $count ) {
						$parts[] = $role . ' ' . number_format_i18n( $count );
					}
					echo esc_html( implode( ' · ', $parts ) );
					?>
				</p>
			<?php endif; ?>
			<?php foreach ( $by_question as $question => $answers ) : ?>
				<h3><?php echo esc_html( $question ); ?> <span class="tspr-empty">(<?php echo esc_html( number_format_i18n( count( $answers ) ) ); ?>)</span></h3>
				<ul class="tspr-answers">
					<?php foreach ( $answers as $answer ) : ?>
						<li><?php echo esc_html( $answer ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="tspr-empty">No written feedback was filed with this record.</p>
		<?php endif; ?>
	</section>

	<section class="tspr-card">
		<h2>For reflection</h2>
		<ul class="tspr-answers">
			<li>Which moments drew the strongest response, and what was being said then?</li>
			<li>Where did the room go quiet? Quiet can mean close attention as easily as distance.</li>
			<li>What did people say they would take with them, and how will we follow up?</li>
			<li>What should change before the next session?</li>
		</ul>
	</section>

	<footer>
		Reactions and feedback are anonymous. Device codes and email addresses are left out of this report; the feedback download in WP Admin has the emails people chose to give.
		Report made <?php echo esc_html( wp_date( 'F j, Y, g:i A T', time(), $tz ) ); ?>.
	</footer>
</main>
</body>
</html>
	<?php
	exit;
}
add_action( 'admin_post_transpire_fb_report', 'transpire_fb_report' );
