<?php
/**
 * Dev seed: tools password, one meeting with people, and pages with the three blocks.
 * Run: docker compose run --rm -v $PWD/tests:/tests:ro cli wp eval-file /tests/seed.php
 */
wp_set_current_user( 1 );
TMMT_Auth::set_password( 'club2026' );
// Start clean so the seeded meeting is the current one.
foreach ( TMMT_Meetings::list_meetings( array( 'limit' => 200 ) ) as $old ) {
	TMMT_Meetings::delete( $old['id'] );
}
$run = function ( $n, $i = null ) {
	$r = wp_get_ability( 'tm-meeting-tools/' . $n )->execute( $i );
	if ( is_wp_error( $r ) ) {
		WP_CLI::error( $n . ': ' . $r->get_error_message() );
	}
	return $r;
};
$m = $run( 'create-meeting', array( 'date' => wp_date( 'Y-m-d', strtotime( 'next monday' ) ), 'theme' => 'New beginnings', 'word_of_day' => 'Momentum', 'template' => 'standard' ) );
$names = array( 'Lena', 'Lena', 'Omar', 'Sofia', 'Jonas', 'Priya', 'Priya', 'Anna', 'Erik', 'Maja', '', '', 'Karl', 'Eva', 'Nils', 'Ida', '', 'Priya', 'Jonas', 'Sofia' );
$titles = array( 7 => array( 'My first job', 'icebreaker' ), 8 => array( 'Why I run at 6 am', 'standard' ), 9 => array( 'The art of saying no', 'standard' ) );
foreach ( $m['agenda'] as $i => $item ) {
	$in = array( 'item_id' => $item['id'], 'person_name' => $names[ $i ] );
	if ( isset( $titles[ $i ] ) ) {
		$in['speech_title']    = $titles[ $i ][0];
		$in['speech_category'] = $titles[ $i ][1];
	}
	$run( 'update-agenda-item', $in );
}
$pages = array(
	'meeting'  => array( 'Meeting', '<!-- wp:tm-meeting-tools/meeting /-->', '' ),
	'feedback' => array( 'Speech feedback', '<!-- wp:tm-meeting-tools/audience-feedback /-->', '' ),
	'results'  => array( 'Results', '<!-- wp:tm-meeting-tools/report /-->', 'board2026' ),
	'shortcode' => array( 'Shortcode test', '<!-- wp:shortcode -->[tm_meeting]<!-- /wp:shortcode -->', '' ),
);
foreach ( $pages as $slug => $p ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) {
		wp_delete_post( $existing->ID, true );
	}
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $p[0], 'post_content' => $p[1], 'post_password' => $p[2], 'page_template' => 'meeting' === $slug ? 'page-no-title' : '' ) );
}
WP_CLI::success( 'Seeded meeting ' . $m['id'] );
