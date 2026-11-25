<?php
/**
 * Small shared helpers: table names, time formatting, timing verdicts, constants.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/** Toastmasters allows 30 s grace either side of the green to red window. */
define( 'TMMT_GRACE_SEC', 30 );

/**
 * Full table name for one of the plugin tables.
 *
 * @param string $name Short name: role_types, meetings, agenda_items, timings, filler_counts, feedback.
 * @return string
 */
function tmmt_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'tmmt_' . $name;
}

/**
 * Capability needed to manage meetings, roles and view feedback.
 *
 * @return string
 */
function tmmt_manage_cap() {
	return apply_filters( 'tmmt_manage_capability', 'manage_options' );
}

/**
 * Role categories.
 *
 * @return array<string,string> slug => label
 */
function tmmt_role_categories() {
	return array(
		'speech'      => __( 'Speech', 'tm-meeting-tools' ),
		'role'        => __( 'Role', 'tm-meeting-tools' ),
		'table_topic' => __( 'Table topic', 'tm-meeting-tools' ),
		'break'       => __( 'Break', 'tm-meeting-tools' ),
		'other'       => __( 'Other', 'tm-meeting-tools' ),
	);
}

/**
 * Speech categories. Picking one on a speech agenda item sets its timing.
 *
 * @return array<string,array{label:string,green:int,yellow:int,red:int}>
 */
function tmmt_speech_categories() {
	return apply_filters(
		'tmmt_speech_categories',
		array(
			'icebreaker' => array(
				'label'  => __( 'Ice Breaker (4 to 6 min)', 'tm-meeting-tools' ),
				'green'  => 240,
				'yellow' => 300,
				'red'    => 360,
			),
			'standard'   => array(
				'label'  => __( 'Standard speech (5 to 7 min)', 'tm-meeting-tools' ),
				'green'  => 300,
				'yellow' => 360,
				'red'    => 420,
			),
			'long'       => array(
				'label'  => __( 'Long speech (8 to 10 min)', 'tm-meeting-tools' ),
				'green'  => 480,
				'yellow' => 540,
				'red'    => 600,
			),
			'extended'   => array(
				'label'  => __( 'Extended speech (10 to 12 min)', 'tm-meeting-tools' ),
				'green'  => 600,
				'yellow' => 660,
				'red'    => 720,
			),
		)
	);
}

/**
 * Filler words for the Ah-Counter.
 *
 * @return string[]
 */
function tmmt_filler_words() {
	return apply_filters( 'tmmt_filler_words', array( 'ah', 'um', 'er', 'so', 'like', 'and', 'you know', 'well', 'repeat' ) );
}

/**
 * What the General Evaluator gives feedback on, in this order. Key => label.
 *
 * @return array<string,string>
 */
function tmmt_ge_topics() {
	return array(
		'setup'       => __( 'Setup', 'tm-meeting-tools' ),
		'opening'     => __( 'Opening', 'tm-meeting-tools' ),
		'toastmaster' => __( 'Toastmaster', 'tm-meeting-tools' ),
		'ah_counter'  => __( 'Ah-Counter', 'tm-meeting-tools' ),
		'grammarian'  => __( 'Grammarian', 'tm-meeting-tools' ),
		'timer'       => __( 'Timer', 'tm-meeting-tools' ),
		'meet_greet'  => __( 'Meet and Greet', 'tm-meeting-tools' ),
		'break'       => __( 'Break', 'tm-meeting-tools' ),
		'tt_master'   => __( 'Table Topics Master', 'tm-meeting-tools' ),
		// One entry per evaluator on the agenda, told apart by agenda_item_id.
		'evaluator'   => __( 'Evaluator', 'tm-meeting-tools' ),
		'closing'     => __( 'Closing and timing', 'tm-meeting-tools' ),
		'overall'     => __( 'Overall impression', 'tm-meeting-tools' ),
	);
}

/**
 * Timing verdict with grace period.
 *
 * @param int $duration Seconds spoken.
 * @param int $green    Green (minimum) seconds.
 * @param int $red      Red (maximum) seconds.
 * @return string within|under|over
 */
function tmmt_timing_verdict( $duration, $green, $red ) {
	if ( $duration < $green - TMMT_GRACE_SEC ) {
		return 'under';
	}
	if ( $duration > $red + TMMT_GRACE_SEC ) {
		return 'over';
	}
	return 'within';
}

/**
 * Seconds to m:ss.
 *
 * @param int|null $sec Seconds.
 * @return string
 */
function tmmt_format_time( $sec ) {
	if ( null === $sec ) {
		return '';
	}
	$sec = max( 0, (int) round( $sec ) );
	return sprintf( '%d:%02d', intdiv( $sec, 60 ), $sec % 60 );
}

/**
 * Parses "m:ss", "m" or seconds-as-int into seconds. Empty string returns null.
 *
 * @param mixed $value Raw value.
 * @return int|null
 */
function tmmt_parse_time( $value ) {
	if ( is_int( $value ) ) {
		return max( 0, $value );
	}
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return null;
	}
	if ( preg_match( '/^(\d{1,3}):([0-5]?\d)$/', $value, $m ) ) {
		return (int) $m[1] * 60 + (int) $m[2];
	}
	if ( preg_match( '/^\d{1,3}$/', $value ) ) {
		return (int) $value * 60;
	}
	return null;
}

/**
 * Trims and limits a single line of text.
 *
 * @param mixed $value Raw value.
 * @param int   $max   Max length.
 * @return string
 */
function tmmt_clean_line( $value, $max = 190 ) {
	return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
}

/**
 * Trims and limits multi-line text.
 *
 * @param mixed $value Raw value.
 * @param int   $max   Max length.
 * @return string
 */
function tmmt_clean_text( $value, $max = 5000 ) {
	return mb_substr( sanitize_textarea_field( (string) $value ), 0, $max );
}
