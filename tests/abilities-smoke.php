<?php
/**
 * Smoke test for the abilities, run with:
 *   docker compose run --rm cli wp eval-file wp-content/plugins/tm-meeting-tools/../../../tests/abilities-smoke.php
 * (see README). Exercises every ability through wp_get_ability()->execute(), as admin and as a visitor.
 */

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		++$fails;
	}
};
$run = function ( $name, $input = null ) {
	$a = wp_get_ability( 'tm-meeting-tools/' . $name );
	if ( ! $a ) {
		return new WP_Error( 'missing', "ability $name missing" );
	}
	return $a->execute( $input );
};
$err = function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : '';
};

wp_set_current_user( 1 );

$roles = $run( 'list-role-types' );
$ok( ! is_wp_error( $roles ) && count( $roles['role_types'] ) >= 14, 'list-role-types returns seeded roles ' . $err( $roles ) );
$by_name = array();
foreach ( $roles['role_types'] as $r ) {
	$by_name[ $r['name'] ] = $r;
}
$ok( 360 === $by_name["President's Introduction"]['green_sec'] && 480 === $by_name["President's Introduction"]['red_sec'], "President's Introduction 6:00/7:00/8:00" );
$ok( 540 === $by_name['Break']['green_sec'], 'Break 9:00/9:30/10:00' );

$m = $run(
	'create-meeting',
	array(
		'date'        => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
		'theme'       => 'Smoke test',
		'word_of_day' => 'Serendipity',
		'template'    => 'standard',
	)
);
$ok( ! is_wp_error( $m ) && 19 === count( $m['agenda'] ), 'create-meeting with standard template has 19 items ' . $err( $m ) );
$mid = $m['id'];
$ok( 'Speaker 1' === $m['agenda'][6]['label'] && 'Speaker 4' === $m['agenda'][9]['label'], 'numbered labels Speaker 1..4' );

$bad = $run( 'create-meeting', array( 'date' => '2026-02-31' ) );
$ok( is_wp_error( $bad ), 'invalid date rejected' );

$up = $run(
	'update-meeting',
	array(
		'meeting_id' => $mid,
		'time'       => '18:30',
	)
);
$ok( ! is_wp_error( $up ) && '18:30' === $up['time'] && 'Smoke test' === $up['theme'], 'update-meeting partial update ' . $err( $up ) );

$speaker = $m['agenda'][7];
$u       = $run(
	'update-agenda-item',
	array(
		'item_id'         => $speaker['id'],
		'person_name'     => 'Anna',
		'speech_title'    => 'My first job',
		'speech_category' => 'icebreaker',
	)
);
$ok( ! is_wp_error( $u ) && 240 === $u['green_sec'] && 'speech_category' === $u['timing_source'], 'speech category Ice Breaker sets 4:00 ' . $err( $u ) );

$c = $run(
	'update-agenda-item',
	array(
		'item_id'    => $speaker['id'],
		'green_sec'  => 100,
		'yellow_sec' => 200,
		'red_sec'    => 300,
	)
);
$ok( ! is_wp_error( $c ) && 'custom' === $c['timing_source'] && 300 === $c['red_sec'], 'custom timing override ' . $err( $c ) );
$c = $run(
	'update-agenda-item',
	array(
		'item_id'    => $speaker['id'],
		'green_sec'  => null,
		'yellow_sec' => null,
		'red_sec'    => null,
	)
);
$ok( ! is_wp_error( $c ) && 'speech_category' === $c['timing_source'], 'custom timing cleared ' . $err( $c ) );
$partial = $run(
	'update-agenda-item',
	array(
		'item_id'   => $speaker['id'],
		'green_sec' => 10,
	)
);
$ok( is_wp_error( $partial ), 'partial custom timing rejected' );

$ttm   = $m['agenda'][12];
$added = $run(
	'add-agenda-item',
	array(
		'meeting_id'    => $mid,
		'role_type_id'  => $by_name['Table Topic Speaker']['id'],
		'person_name'   => 'Guest Bo',
		'after_item_id' => $ttm['id'],
	)
);
$ok( ! is_wp_error( $added ) && $added['position'] === $ttm['position'] + 1, 'add-agenda-item after TTM ' . $err( $added ) );

$ids = wp_list_pluck( $run( 'get-meeting', array( 'meeting_id' => $mid ) )['agenda'], 'id' );
$rev = array_reverse( $ids );
$ro  = $run(
	'reorder-agenda-items',
	array(
		'meeting_id' => $mid,
		'item_ids'   => $rev,
	)
);
$ok( ! is_wp_error( $ro ) && $ro['agenda'][0]['id'] === $rev[0], 'reorder-agenda-items ' . $err( $ro ) );
$run(
	'reorder-agenda-items',
	array(
		'meeting_id' => $mid,
		'item_ids'   => $ids,
	)
);
$ro = $run(
	'reorder-agenda-items',
	array(
		'meeting_id' => $mid,
		'item_ids'   => array( $ids[0] ),
	)
);
$ok( is_wp_error( $ro ), 'reorder with missing items rejected' );

$t = $run(
	'record-timing',
	array(
		'agenda_item_id' => $speaker['id'],
		'duration_sec'   => 290,
	)
);
$ok( ! is_wp_error( $t ) && 'within' === $t['verdict'], 'record-timing 4:50 for Ice Breaker is within ' . $err( $t ) );
$t = $run(
	'record-timing',
	array(
		'agenda_item_id' => $speaker['id'],
		'duration_sec'   => 400,
	)
);
$ok( ! is_wp_error( $t ) && 'over' === $t['verdict'], 're-record 6:40 replaces and is over (6:00 + 30 s grace)' );
$t2 = $run(
	'record-timing',
	array(
		'agenda_item_id' => $added['id'],
		'duration_sec'   => 25,
	)
);
$ok( 'under' === $t2['verdict'], 'table topic 0:25 is under (1:00 - 30 s grace)' );

$rep = $run( 'get-timing-report', array( 'meeting_id' => $mid ) );
$ok( ! is_wp_error( $rep ) && 1 === $rep['summary']['over'] && 1 === $rep['summary']['under'], 'get-timing-report summary ' . $err( $rep ) );
$ok( 'in_progress' === $rep['meeting']['status'], 'meeting moved to in_progress after first timing' );

$f = $run(
	'save-filler-counts',
	array(
		'meeting_id'  => $mid,
		'person_name' => 'Anna',
		'counts'      => array(
			'ah'       => 3,
			'you know' => 2,
		),
	)
);
$ok( ! is_wp_error( $f ) && 5 === $f['total'] && 2 === $f['counts']['you know'], 'save-filler-counts ' . $err( $f ) );
$fl = $run( 'list-filler-counts', array( 'meeting_id' => $mid ) );
$ok( 1 === count( $fl['filler_counts'] ), 'list-filler-counts' );

$fb = $run(
	'submit-feedback',
	array(
		'meeting_id'     => $mid,
		'kind'           => 'evaluation',
		'agenda_item_id' => $speaker['id'],
		'giver_name'     => 'Eva',
		'about'          => 'Speech: My first job',
		'body'           => 'Clear structure.',
	)
);
$ok( ! is_wp_error( $fb ) && $fb['saved'], 'submit-feedback evaluation ' . $err( $fb ) );
$lf = $run(
	'list-feedback',
	array(
		'meeting_id'  => $mid,
		'person_name' => 'Anna',
	)
);
$ok( 1 === count( $lf['feedback'] ) && 'Eva' === $lf['feedback'][0]['giver_name'], 'list-feedback by person' );

$res = $run( 'get-results', array( 'meeting_id' => $mid ) );
$ok( ! is_wp_error( $res ) && 2 === count( $res['timings'] ) && in_array( 'Anna', $res['people'], true ), 'get-results ' . $err( $res ) );

$role = $run(
	'create-role-type',
	array(
		'name'       => 'Joke Master',
		'category'   => 'role',
		'green_sec'  => 60,
		'yellow_sec' => 90,
		'red_sec'    => 120,
	)
);
$ok( ! is_wp_error( $role ), 'create-role-type ' . $err( $role ) );
$role = $run(
	'update-role-type',
	array(
		'role_type_id' => $role['id'],
		'red_sec'      => 150,
	)
);
$ok( 150 === $role['red_sec'], 'update-role-type' );
$inuse = $run( 'delete-role-type', array( 'role_type_id' => $by_name['Speaker']['id'] ) );
$ok( is_wp_error( $inuse ) && 'tmmt_role_in_use' === $inuse->get_error_code(), 'delete-role-type blocked while used' );
$del = $run( 'delete-role-type', array( 'role_type_id' => $role['id'] ) );
$ok( ! is_wp_error( $del ) && $del['deleted'], 'delete-role-type' );

$done = $run( 'complete-meeting', array( 'meeting_id' => $mid ) );
$ok( ! is_wp_error( $done ) && 'completed' === $done['meeting']['status'], 'complete-meeting ' . $err( $done ) );

$rm = $run( 'remove-agenda-item', array( 'item_id' => $added['id'] ) );
$ok( ! is_wp_error( $rm ), 'remove-agenda-item' );
$rep = $run( 'get-timing-report', array( 'meeting_id' => $mid ) );
$ok( 'Guest Bo' === end( $rep['rows'] )['person_name'], 'timing of removed item kept under the person name' );

// Visitor (not logged in, no tools cookie).
wp_set_current_user( 0 );
$ok( ! is_wp_error( $run( 'get-meeting', array( 'meeting_id' => $mid ) ) ), 'visitor can read the agenda' );
$ok( is_wp_error( $run( 'create-meeting', array( 'date' => '2030-01-01' ) ) ), 'visitor cannot create a meeting' );
$ok(
	is_wp_error(
		$run(
			'record-timing',
			array(
				'agenda_item_id' => $speaker['id'],
				'duration_sec'   => 1,
			)
		)
	),
	'visitor cannot record timings'
);
$ok( is_wp_error( $run( 'list-feedback' ) ), 'visitor cannot list feedback' );
$ok(
	is_wp_error(
		$run(
			'submit-feedback',
			array(
				'meeting_id' => $mid,
				'person_name' => 'Anna',
				'went_well'  => 'x',
			)
		)
	),
	'audience feedback without nonce refused'
);
$aud = $run(
	'submit-feedback',
	array(
		'meeting_id'     => $mid,
		'agenda_item_id' => $speaker['id'],
		'went_well'      => 'Great eye contact',
		'potential'      => 'Slow down a bit',
		'giver_name'     => 'Should be dropped',
		'nonce'          => wp_create_nonce( 'tmmt_audience' ),
	)
);
$ok( ! is_wp_error( $aud ) && $aud['id'], 'audience feedback with nonce saved ' . $err( $aud ) );
$hp = $run(
	'submit-feedback',
	array(
		'meeting_id'  => $mid,
		'person_name' => 'Anna',
		'went_well'   => 'spam',
		'website'     => 'http://spam.example',
		'nonce'       => wp_create_nonce( 'tmmt_audience' ),
	)
);
$ok( ! is_wp_error( $hp ) && null === $hp['id'], 'honeypot submission pretends success, not saved' );
$ok(
	is_wp_error(
		$run(
			'add-agenda-item',
			array(
				'meeting_id'   => $mid,
				'role_type_id' => $by_name['Table Topic Speaker']['id'],
			)
		)
	),
	'visitor cannot add table topic speakers'
);

wp_set_current_user( 1 );
$lf = $run(
	'list-feedback',
	array(
		'meeting_id' => $mid,
		'kind'       => 'audience',
	)
);
$ok( 1 === count( $lf['feedback'] ) && '' === $lf['feedback'][0]['giver_name'] && 'Anna' === $lf['feedback'][0]['person_name'], 'audience feedback anonymous, person from agenda item' );

// Members: sync from the club manager, list, prune.
global $wpdb;
$mt = tmmt_table( 'members' );
$wpdb->query( "DELETE FROM $mt WHERE name LIKE 'Smoke %'" ); // phpcs:ignore
$manual = TMMT_Members::create( array( 'name' => 'Smoke Linked' ) );
$wpdb->update( $mt, array( 'last_synced_at' => '2020-01-01 00:00:00' ), array( 'id' => $manual ) );
$sy = $run(
	'sync-members',
	array(
		'members' => array(
			array(
				'tm_id'          => 'smoke-1',
				'name'           => 'Smoke New',
			),
			array(
				'tm_id'          => 'smoke-2',
				'name'           => 'smoke linked',
			),
			array(
				'tm_id'          => '',
				'name'           => 'Smoke Skipped',
			),
		),
	)
);
$ok( ! is_wp_error( $sy ) && 1 === $sy['created'] && 1 === $sy['updated'], 'sync-members creates one, links one by name, skips one ' . $err( $sy ) );
$by_uid = array();
foreach ( is_wp_error( $sy ) ? array() : $sy['members'] as $mm ) {
	$by_uid[ $mm['tm_id'] ] = $mm;
}
$ok( isset( $by_uid['smoke-2'] ) && $manual === $by_uid['smoke-2']['id'] && 'smoke linked' === $by_uid['smoke-2']['name'], 'sync-members linked the manual member by name' );
$ok( isset( $by_uid['smoke-2'] ) && $by_uid['smoke-2']['last_synced_at'] > '2020-01-01 00:00:00', 'sync-members bumped last_synced_at' );
$new_id = isset( $by_uid['smoke-1'] ) ? $by_uid['smoke-1']['id'] : 0;
$wpdb->update( $mt, array( 'last_synced_at' => '2020-01-01 00:00:00' ), array( 'id' => $new_id ) );
$sy = $run(
	'sync-members',
	array(
		'members' => array(
			array(
				'tm_id'          => 'smoke-1',
				'name'           => 'Smoke Renamed',
			),
		),
	)
);
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $mt WHERE id = %d", $new_id ), ARRAY_A ); // phpcs:ignore
$ok( ! is_wp_error( $sy ) && 0 === $sy['created'] && 1 === $sy['updated'] && 'Smoke Renamed' === $row['name'] && $row['last_synced_at'] > '2020-01-01 00:00:00', 'sync-members updates by tm_id and bumps last_synced_at ' . $err( $sy ) );
$ok( is_wp_error( $run( 'sync-members', array() ) ), 'sync-members needs members' );

$lm    = $run( 'list-members' );
$names = is_wp_error( $lm ) ? array() : wp_list_pluck( $lm['members'], 'name' );
$ok( in_array( 'Smoke Renamed', $names, true ) && array_key_exists( 'last_synced_at', $lm['members'][0] ) && array_key_exists( 'tm_id', $lm['members'][0] ), 'list-members ' . $err( $lm ) );

$old  = TMMT_Members::create( array( 'name' => 'Smoke Old' ) );
$mid1 = TMMT_Members::create( array( 'name' => 'Smoke Recent' ) );
$nul  = TMMT_Members::create( array( 'name' => 'Smoke Null' ) );
$wpdb->update( $mt, array( 'last_synced_at' => wp_date( 'Y-m-d H:i:s', time() - 3 * MONTH_IN_SECONDS ) ), array( 'id' => $old ) );
$wpdb->update( $mt, array( 'last_synced_at' => wp_date( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS ) ), array( 'id' => $mid1 ) );
$wpdb->query( $wpdb->prepare( "UPDATE $mt SET last_synced_at = NULL WHERE id = %d", $nul ) ); // phpcs:ignore
delete_transient( 'tmmt_prune_members' );
TMMT_Members::prune_inactive();
$left = $wpdb->get_col( "SELECT id FROM $mt" ); // phpcs:ignore
$ok( ! in_array( (string) $old, $left, true ), 'prune_inactive deletes a member synced 3 months ago' );
$ok( in_array( (string) $mid1, $left, true ) && in_array( (string) $nul, $left, true ), 'prune_inactive keeps 1 month ago and NULL' );

// The deletion period comes from Settings.
update_option( 'tmmt_member_inactive_days', 10 );
$wpdb->update( $mt, array( 'last_synced_at' => wp_date( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ), array( 'id' => $mid1 ) );
delete_transient( 'tmmt_prune_members' );
TMMT_Members::prune_inactive();
$left = $wpdb->get_col( "SELECT id FROM $mt" ); // phpcs:ignore
$ok( ! in_array( (string) $mid1, $left, true ), 'prune_inactive uses the days from Settings' );
delete_option( 'tmmt_member_inactive_days' );
$ok( 60 === tmmt_member_inactive_days() && 7 === tmmt_feedback_keep_days(), 'default deletion periods 60 and 7 days' );

$mg = TMMT_Roles::find_by_name( 'Meet and Greet' );
$ok( $mg && 900 === (int) $mg['red_sec'], 'Meet and Greet takes 15 minutes' );

wp_set_current_user( 0 );
$ok( is_wp_error( $run( 'list-members' ) ) && is_wp_error( $run( 'sync-members', array( 'members' => array() ) ) ), 'visitor cannot list or sync members' );
wp_set_current_user( 1 );
$wpdb->query( "DELETE FROM $mt WHERE name LIKE 'Smoke %' OR name = 'smoke linked'" ); // phpcs:ignore

$d = $run( 'delete-meeting', array( 'meeting_id' => $mid ) );
$ok( ! is_wp_error( $d ) && null === TMMT_Meetings::get( $mid ), 'delete-meeting' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
