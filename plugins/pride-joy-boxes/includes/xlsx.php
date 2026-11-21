<?php
/**
 * A small .xlsx writer (needs PHP's ZipArchive) and the Pride and Joy
 * workbook: one sheet per table, IDs that point between sheets, real dates and
 * money amounts, a frozen header row with filters, and a "Read me" sheet that
 * says what each column holds.
 *
 * Text goes in as inline strings, which Excel never runs as formulas.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Writer
 * ---------------------------------------------------------------------- */

/** Cell styles, by their index in styles.xml. */
const PJB_X_TEXT   = 0;
const PJB_X_HEAD   = 1;
const PJB_X_DATE   = 2;
const PJB_X_TIME   = 3;
const PJB_X_MONEY  = 4;
const PJB_X_WRAP   = 5;

/** A cell value with a style, for dates and money. */
function pjb_x_cell( $value, $style ) {
	return array(
		'v' => $value,
		's' => $style,
	);
}

/** A Y-m-d date as an Excel date cell (blank when empty). */
function pjb_x_date( $ymd ) {
	if ( ! $ymd || ! pjb_date( (string) $ymd ) ) {
		return '';
	}
	return pjb_x_cell( (float) ( gmmktime( 0, 0, 0, (int) substr( $ymd, 5, 2 ), (int) substr( $ymd, 8, 2 ), (int) substr( $ymd, 0, 4 ) ) / 86400 + 25569 ), PJB_X_DATE );
}

/** A Unix time as an Excel date-and-time cell in the site's timezone. */
function pjb_x_time( $ts ) {
	if ( ! $ts ) {
		return '';
	}
	$local = wp_date( 'Y-m-d H:i:s', (int) $ts );
	$utc   = strtotime( $local . ' UTC' );
	return pjb_x_cell( $utc / 86400 + 25569, PJB_X_TIME );
}

function pjb_x_money( $amount ) {
	return null === $amount || '' === $amount ? '' : pjb_x_cell( (float) $amount, PJB_X_MONEY );
}

function pjb_x_yes( $flag ) {
	return $flag ? 'Yes' : 'No';
}

/** Column letters for a 0-based index: 0 => A, 26 => AA. */
function pjb_x_col( $i ) {
	$s = '';
	for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
		$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
	}
	return $s;
}

function pjb_x_escape( $text ) {
	$text = pjb_plain( $text );
	$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text );
	return htmlspecialchars( mb_substr( (string) $text, 0, 32000 ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * One worksheet's XML.
 *
 * @param array $columns [ [header, width], ... ]
 * @param array $rows    Lists of cell values: string, int/float, or pjb_x_cell().
 */
function pjb_x_sheet_xml( array $columns, array $rows ) {
	$xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
	$xml  .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
	$xml  .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
	$xml  .= '<cols>';
	foreach ( $columns as $i => $column ) {
		$xml .= sprintf( '<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, $column[1] );
	}
	$xml .= '</cols><sheetData>';

	$all = array_merge(
		array(
			array_map(
				function ( $column ) {
					return pjb_x_cell( $column[0], PJB_X_HEAD );
				},
				$columns
			),
		),
		$rows
	);
	foreach ( $all as $r => $row ) {
		$xml .= '<row r="' . ( $r + 1 ) . '">';
		foreach ( array_values( $row ) as $c => $cell ) {
			$style = is_array( $cell ) ? $cell['s'] : PJB_X_TEXT;
			$value = is_array( $cell ) ? $cell['v'] : $cell;
			$ref   = pjb_x_col( $c ) . ( $r + 1 );
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( ( is_int( $value ) || is_float( $value ) ) && PJB_X_HEAD !== $style ) {
				$xml .= sprintf( '<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' ) );
			} else {
				if ( PJB_X_TEXT === $style && false !== strpos( (string) $value, "\n" ) ) {
					$style = PJB_X_WRAP;
				}
				$xml .= sprintf( '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $style, pjb_x_escape( $value ) );
			}
		}
		$xml .= '</row>';
	}
	$xml .= '</sheetData>';
	if ( count( $columns ) ) {
		$xml .= '<autoFilter ref="A1:' . pjb_x_col( count( $columns ) - 1 ) . max( 1, count( $all ) ) . '"/>';
	}
	return $xml . '</worksheet>';
}

function pjb_x_styles_xml() {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
		. '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd h:mm"/><numFmt numFmtId="165" formatCode="&quot;$&quot;#,##0.00"/></numFmts>'
		. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
		. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF3A5530"/><bgColor indexed="64"/></patternFill></fill></fills>'
		. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="6">'
		. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top"/></xf>'
		. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
		. '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="top"/></xf>'
		. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="top"/></xf>'
		. '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="top"/></xf>'
		. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
		. '</cellXfs>'
		. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
		. '</styleSheet>';
}

/**
 * Writes a workbook to $path.
 *
 * @param array $sheets [ name => [ 'columns' => ..., 'rows' => ... ], ... ] Names up to 31 characters.
 */
function pjb_x_write( $path, array $sheets ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return false;
	}

	$n         = count( $sheets );
	$types     = '';
	$rels      = '';
	$book      = '';
	$i         = 0;
	foreach ( $sheets as $name => $sheet ) {
		$i++;
		$zip->addFromString( "xl/worksheets/sheet{$i}.xml", pjb_x_sheet_xml( $sheet['columns'], $sheet['rows'] ) );
		$types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		$rels  .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		$book  .= '<sheet name="' . pjb_x_escape( $name ) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
	}

	$zip->addFromString(
		'[Content_Types].xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
		. $types . '</Types>'
	);
	$zip->addFromString(
		'_rels/.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'
	);
	$zip->addFromString(
		'xl/workbook.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<sheets>' . $book . '</sheets></workbook>'
	);
	$zip->addFromString(
		'xl/_rels/workbook.xml.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. $rels . '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'
	);
	$zip->addFromString( 'xl/styles.xml', pjb_x_styles_xml() );
	return $zip->close();
}

/* -------------------------------------------------------------------------
 * The Pride and Joy workbook
 * ---------------------------------------------------------------------- */

/** label from a value list, or the stored key when it is not in the list. */
function pjb_x_label( array $list, $key ) {
	return isset( $list[ $key ] ) ? $list[ $key ] : (string) $key;
}

function pjb_x_all( $name, $order = 'id ASC' ) {
	global $wpdb;
	return (array) $wpdb->get_results( 'SELECT * FROM ' . pjb_t( $name ) . ' ORDER BY ' . $order );
}

/** Every sheet, in order. */
function pjb_workbook_sheets() {
	$people    = pjb_x_all( 'people' );
	$requests  = pjb_x_all( 'requests' );
	$shipments = pjb_x_all( 'shipments' );
	$donations = pjb_x_all( 'donations' );
	$receipts  = pjb_x_all( 'receipts' );
	$thanks    = pjb_x_all( 'thanks' );
	$feedback  = pjb_x_all( 'feedback' );
	$activity  = pjb_x_all( 'activity' );

	$names = array();
	foreach ( $people as $p ) {
		$names[ (int) $p->id ] = pjb_plain( $p->name );
	}
	$name_of = function ( $id ) use ( $names ) {
		return isset( $names[ (int) $id ] ) ? $names[ (int) $id ] : '';
	};

	// Per-person and per-donation summaries for the People and Donations sheets.
	$sum = array();
	foreach ( $people as $p ) {
		$sum[ (int) $p->id ] = array(
			'requests' => 0,
			'boxes'    => 0,
			'gifts'    => 0,
			'money'    => 0.0,
			'thanked'  => '',
		);
	}
	foreach ( $requests as $r ) {
		if ( isset( $sum[ (int) $r->person_id ] ) ) {
			$sum[ (int) $r->person_id ]['requests']++;
		}
	}
	foreach ( $shipments as $s ) {
		if ( isset( $sum[ (int) $s->person_id ] ) ) {
			$sum[ (int) $s->person_id ]['boxes']++;
		}
	}
	foreach ( $donations as $d ) {
		if ( 'received' === $d->status && isset( $sum[ (int) $d->person_id ] ) ) {
			$sum[ (int) $d->person_id ]['gifts']++;
			if ( 'money' === $d->category ) {
				$sum[ (int) $d->person_id ]['money'] += (float) $d->amount;
			}
		}
	}
	$thanked_for = array();
	foreach ( $thanks as $t ) {
		if ( isset( $sum[ (int) $t->person_id ] ) && (string) $t->sent_on > $sum[ (int) $t->person_id ]['thanked'] ) {
			$sum[ (int) $t->person_id ]['thanked'] = (string) $t->sent_on;
		}
		if ( $t->donation_id && (string) $t->sent_on > ( isset( $thanked_for[ (int) $t->donation_id ] ) ? $thanked_for[ (int) $t->donation_id ] : '' ) ) {
			$thanked_for[ (int) $t->donation_id ] = (string) $t->sent_on;
		}
	}
	$receipt_for = array();
	foreach ( $receipts as $rc ) {
		$receipt_for[ (int) $rc->donation_id ] = $rc->number;
	}

	$roles    = pjb_roles();
	$sources  = pjb_sources();
	$methods  = pjb_methods();
	$sheets   = array();

	$sheets['Read me'] = array(
		'columns' => array( array( 'Sheet', 18 ), array( 'One row per', 22 ), array( 'What it holds', 90 ) ),
		'rows'    => array(
			array( 'Downloaded', '', wp_date( 'F j, Y g:i a' ) ),
			array( 'People', 'person (C-)', 'Everyone: people who asked for a box, donors, artists, note writers, volunteers. Every other sheet points here with person_id. Roles can hold several values.' ),
			array( 'Requests', 'box request (R-)', 'Box requests from the website and the TransWell app, with what they asked for. name_on_box and email are as the person typed them that time.' ),
			array( 'Shipments', 'box sent (S-)', 'Boxes that went out: when, what was packed, cost and tracking.' ),
			array( 'Donations', 'gift (D-)', 'Gifts and offers of money, art, items, notes or time. amount is money received; value_estimate is the donor\'s own estimate for an in-kind gift.' ),
			array( 'Receipts', 'receipt (PJB-year-n)', 'Gift receipts given, with the exact wording in body. tax_deductible stays No until there is a 501(c)(3) or fiscal sponsor.' ),
			array( 'Thank-yous', 'note sent (T-)', 'Thank-you notes sent, and for which gift or box.' ),
			array( 'Feedback', 'feedback (F-)', 'Answers to "How was your box?". Quote only rows with quote_ok Yes, and credit them as credit_as.' ),
			array( 'Activity', 'entry (A-)', 'Everything else: notes, emails, calls, meetings and status changes. A CRM imports this as interactions.' ),
			array( 'Moving to a CRM', '', 'Import People first (keep the C- id as an external id), then the other sheets, matching person_id to that external id.' ),
		),
	);

	$rows = array();
	foreach ( $people as $p ) {
		$s      = $sum[ (int) $p->id ];
		$rows[] = array(
			pjb_ref( 'C', $p->id ),
			pjb_plain( $p->name ),
			pjb_plain( $p->pronouns ),
			$p->email,
			$p->phone,
			pjb_plain( $p->address ),
			implode(
				', ',
				array_map(
					function ( $role ) use ( $roles ) {
						return pjb_x_label( $roles, $role );
					},
					pjb_person_roles( $p )
				)
			),
			pjb_x_yes( $p->contact_ok ),
			pjb_plain( $p->how_found ),
			$s['requests'],
			$s['boxes'],
			$s['gifts'],
			pjb_x_money( $s['money'] ),
			pjb_x_date( $s['thanked'] ),
			pjb_x_time( $p->created_ts ),
			pjb_x_time( $p->updated_ts ),
			pjb_plain( $p->notes ),
		);
	}
	$sheets['People'] = array(
		'columns' => array( array( 'person_id', 10 ), array( 'name', 22 ), array( 'pronouns', 12 ), array( 'email', 28 ), array( 'phone', 14 ), array( 'address', 30 ), array( 'roles', 24 ), array( 'contact_ok', 10 ), array( 'how_found', 18 ), array( 'requests', 9 ), array( 'boxes_sent', 10 ), array( 'gifts_received', 13 ), array( 'money_given', 12 ), array( 'last_thanked', 12 ), array( 'first_seen', 16 ), array( 'last_updated', 16 ), array( 'notes', 40 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $requests as $r ) {
		$items = array();
		foreach ( (array) json_decode( (string) $r->items, true ) as $item ) {
			if ( ! empty( $item['label'] ) ) {
				$items[] = $item['label'] . ( empty( $item['detail'] ) ? '' : ': ' . $item['detail'] );
			}
		}
		$rows[] = array(
			pjb_ref( 'R', $r->id ),
			pjb_ref( 'C', $r->person_id ),
			pjb_x_time( $r->created_ts ),
			pjb_x_label( $sources, $r->source ),
			pjb_x_label( pjb_statuses(), $r->status ),
			pjb_x_label( pjb_kinds(), $r->kind ),
			pjb_plain( $r->name ),
			pjb_plain( $r->pronouns ),
			$r->email,
			pjb_plain( $r->address ),
			implode( ', ', (array) json_decode( (string) $r->joy, true ) ),
			pjb_plain( $r->faves ),
			implode( "\n", $items ),
			pjb_x_yes( $r->plain ),
			pjb_plain( $r->notes ),
		);
	}
	$sheets['Requests'] = array(
		'columns' => array( array( 'request_id', 10 ), array( 'person_id', 10 ), array( 'received', 16 ), array( 'source', 14 ), array( 'status', 11 ), array( 'box', 12 ), array( 'name_on_box', 20 ), array( 'pronouns', 12 ), array( 'email', 28 ), array( 'address', 30 ), array( 'joy', 30 ), array( 'favorites', 26 ), array( 'affirming_items', 36 ), array( 'plain_box', 9 ), array( 'notes', 40 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $shipments as $s ) {
		$rows[] = array( pjb_ref( 'S', $s->id ), pjb_ref( 'R', $s->request_id ), pjb_ref( 'C', $s->person_id ), $name_of( $s->person_id ), pjb_x_date( $s->sent_on ), pjb_plain( $s->contents ), pjb_x_money( $s->cost ), pjb_plain( $s->carrier ), $s->tracking, pjb_plain( $s->packed_by ), pjb_plain( $s->notes ) );
	}
	$sheets['Shipments'] = array(
		'columns' => array( array( 'shipment_id', 11 ), array( 'request_id', 10 ), array( 'person_id', 10 ), array( 'name', 22 ), array( 'sent_on', 11 ), array( 'contents', 40 ), array( 'cost', 10 ), array( 'carrier', 12 ), array( 'tracking', 24 ), array( 'packed_by', 16 ), array( 'notes', 40 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $donations as $d ) {
		$rows[] = array(
			pjb_ref( 'D', $d->id ),
			pjb_ref( 'C', $d->person_id ),
			$name_of( $d->person_id ),
			pjb_x_label( pjb_categories(), $d->category ),
			pjb_x_label( pjb_donation_statuses(), $d->status ),
			pjb_x_date( $d->received_on ),
			pjb_plain( $d->description ),
			pjb_x_money( $d->amount ),
			pjb_x_money( $d->value_estimate ),
			'' === $d->method ? '' : pjb_x_label( $methods, $d->method ),
			pjb_plain( $d->campaign ),
			pjb_x_label( $sources, $d->source ),
			isset( $receipt_for[ (int) $d->id ] ) ? $receipt_for[ (int) $d->id ] : '',
			pjb_x_date( isset( $thanked_for[ (int) $d->id ] ) ? $thanked_for[ (int) $d->id ] : '' ),
			pjb_x_time( $d->created_ts ),
			pjb_plain( $d->notes ),
		);
	}
	$sheets['Donations'] = array(
		'columns' => array( array( 'donation_id', 11 ), array( 'person_id', 10 ), array( 'name', 22 ), array( 'category', 16 ), array( 'status', 11 ), array( 'received_on', 11 ), array( 'description', 40 ), array( 'amount', 10 ), array( 'value_estimate', 13 ), array( 'method', 12 ), array( 'campaign', 16 ), array( 'source', 14 ), array( 'receipt_number', 15 ), array( 'thanked_on', 11 ), array( 'logged', 16 ), array( 'notes', 40 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $receipts as $rc ) {
		$rows[] = array( $rc->number, pjb_ref( 'D', $rc->donation_id ), pjb_ref( 'C', $rc->person_id ), $name_of( $rc->person_id ), pjb_x_date( $rc->issued_on ), pjb_x_money( $rc->amount ), pjb_plain( $rc->description ), pjb_x_yes( $rc->tax_deductible ), (int) $rc->template_version, pjb_x_date( $rc->sent_on ), '' === $rc->sent_via ? '' : pjb_x_label( pjb_thanks_methods(), $rc->sent_via ), pjb_plain( $rc->body ) );
	}
	$sheets['Receipts'] = array(
		'columns' => array( array( 'receipt_number', 15 ), array( 'donation_id', 11 ), array( 'person_id', 10 ), array( 'name', 22 ), array( 'issued_on', 11 ), array( 'amount', 10 ), array( 'description', 36 ), array( 'tax_deductible', 13 ), array( 'template_version', 15 ), array( 'sent_on', 11 ), array( 'sent_via', 12 ), array( 'body', 60 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $thanks as $t ) {
		$rows[] = array( pjb_ref( 'T', $t->id ), pjb_ref( 'C', $t->person_id ), $name_of( $t->person_id ), pjb_ref( 'D', $t->donation_id ), pjb_ref( 'S', $t->shipment_id ), pjb_x_label( pjb_thanks_methods(), $t->method ), pjb_x_date( $t->sent_on ), pjb_plain( $t->sent_by ), pjb_plain( $t->notes ) );
	}
	$sheets['Thank-yous'] = array(
		'columns' => array( array( 'thanks_id', 10 ), array( 'person_id', 10 ), array( 'name', 22 ), array( 'donation_id', 11 ), array( 'shipment_id', 11 ), array( 'method', 14 ), array( 'sent_on', 11 ), array( 'sent_by', 16 ), array( 'notes', 40 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $feedback as $f ) {
		$rows[] = array( pjb_ref( 'F', $f->id ), pjb_ref( 'C', $f->person_id ), $name_of( $f->person_id ), pjb_ref( 'R', $f->request_id ), pjb_x_time( $f->created_ts ), pjb_x_label( $sources, $f->source ), null === $f->rating ? '' : (int) $f->rating, pjb_plain( $f->loved ), pjb_plain( $f->wish ), pjb_x_yes( $f->quote_ok ), pjb_plain( $f->credit_as ), pjb_x_yes( $f->reviewed ) );
	}
	$sheets['Feedback'] = array(
		'columns' => array( array( 'feedback_id', 11 ), array( 'person_id', 10 ), array( 'name', 22 ), array( 'request_id', 10 ), array( 'received', 16 ), array( 'source', 14 ), array( 'rating', 7 ), array( 'loved', 40 ), array( 'wish', 40 ), array( 'quote_ok', 9 ), array( 'credit_as', 18 ), array( 'reviewed', 9 ) ),
		'rows'    => $rows,
	);

	$rows = array();
	foreach ( $activity as $a ) {
		$rows[] = array( pjb_ref( 'A', $a->id ), pjb_ref( 'C', $a->person_id ), $name_of( $a->person_id ), pjb_x_date( $a->happened_on ), pjb_x_label( pjb_activity_types(), $a->type ), pjb_plain( $a->note ), pjb_plain( $a->by_user ) );
	}
	$sheets['Activity'] = array(
		'columns' => array( array( 'activity_id', 11 ), array( 'person_id', 10 ), array( 'name', 22 ), array( 'date', 11 ), array( 'type', 14 ), array( 'note', 60 ), array( 'by', 16 ) ),
		'rows'    => $rows,
	);

	return $sheets;
}
