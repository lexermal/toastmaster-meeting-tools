<?php
/**
 * Timings, filler word counts, feedback and the reports built from them.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Results repository.
 */
class TMMT_Results {

	/**
	 * Records the time for an agenda item. Re-timing the same item replaces the earlier time,
	 * so corrections after stopping simply send the corrected duration again.
	 *
	 * @param int $agenda_item_id Item.
	 * @param int $duration_sec   Seconds spoken.
	 * @return array|WP_Error Report row for the item.
	 */
	public static function record_timing( $agenda_item_id, $duration_sec ) {
		global $wpdb;
		$item = TMMT_Meetings::get_item( $agenda_item_id );
		if ( ! $item ) {
			return new WP_Error( 'tmmt_not_found', __( 'Agenda item not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$duration_sec = max( 0, min( 6 * HOUR_IN_SECONDS, (int) $duration_sec ) );
		$table        = tmmt_table( 'timings' );
		$row          = array(
			'meeting_id'     => $item['meeting_id'],
			'agenda_item_id' => $item['id'],
			'person_name'    => $item['person_name'],
			'role_label'     => $item['label'],
			'green_sec'      => $item['green_sec'],
			'yellow_sec'     => $item['yellow_sec'],
			'red_sec'        => $item['red_sec'],
			'duration_sec'   => $duration_sec,
			'recorded_at'    => current_time( 'mysql' ),
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE agenda_item_id = %d", $item['id'] ) );
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ), null, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $row );
		}
		// The first recorded time means the meeting has started.
		$meeting = TMMT_Meetings::get( $item['meeting_id'], false );
		if ( $meeting && 'planned' === $meeting['status'] ) {
			TMMT_Meetings::update( $meeting['id'], array( 'status' => 'in_progress' ) );
		}
		return self::timing_row( $item, self::timing_for_item( $item['id'] ) );
	}

	/**
	 * Deletes the time recorded for an agenda item.
	 *
	 * @param int $agenda_item_id Item.
	 * @return true
	 */
	public static function clear_timing( $agenda_item_id ) {
		global $wpdb;
		$wpdb->delete( tmmt_table( 'timings' ), array( 'agenda_item_id' => (int) $agenda_item_id ), array( '%d' ) );
		return true;
	}

	/**
	 * Raw timing row for an item.
	 *
	 * @param int $item_id Item.
	 * @return array|null
	 */
	private static function timing_for_item( $item_id ) {
		global $wpdb;
		$table = tmmt_table( 'timings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE agenda_item_id = %d", $item_id ), ARRAY_A );
	}

	/**
	 * One report row: an agenda item and how it went.
	 *
	 * @param array      $item   Agenda item (formatted) or null for orphaned timings.
	 * @param array|null $timing Timing DB row.
	 * @return array
	 */
	private static function timing_row( $item, $timing ) {
		// A recorded timing keeps the window that applied when it was recorded.
		$green  = $timing ? (int) $timing['green_sec'] : (int) $item['green_sec'];
		$yellow = $timing ? (int) $timing['yellow_sec'] : (int) $item['yellow_sec'];
		$red    = $timing ? (int) $timing['red_sec'] : (int) $item['red_sec'];
		$dur     = $timing ? (int) $timing['duration_sec'] : null;
		$verdict = null === $dur ? 'not_timed' : tmmt_timing_verdict( $dur, $green, $red );
		// Guests are timed so they learn their time, but not rated.
		if ( $item && $item['is_guest'] && null !== $dur ) {
			$verdict = 'guest';
		}
		return array(
			'agenda_item_id' => $item ? (int) $item['id'] : null,
			'person_name'    => $item ? $item['person_name'] : (string) $timing['person_name'],
			'role_label'     => $item ? $item['label'] : (string) $timing['role_label'],
			'category'       => $item ? $item['category'] : 'other',
			'speech_title'   => $item ? $item['speech_title'] : '',
			'green_sec'      => $green,
			'yellow_sec'     => $yellow,
			'red_sec'        => $red,
			'duration_sec'   => $dur,
			'verdict'        => $verdict,
		);
	}

	/**
	 * Timing report: every timed agenda item in order with its result.
	 *
	 * @param int $meeting_id Meeting.
	 * @return array|WP_Error
	 */
	public static function timing_report( $meeting_id ) {
		global $wpdb;
		$meeting = TMMT_Meetings::get( $meeting_id );
		if ( ! $meeting ) {
			return new WP_Error( 'tmmt_not_found', __( 'Meeting not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$table = tmmt_table( 'timings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$timings = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE meeting_id = %d ORDER BY id ASC", $meeting_id ), ARRAY_A );
		$by_item = array();
		$orphans = array();
		foreach ( $timings ? $timings : array() as $t ) {
			if ( $t['agenda_item_id'] ) {
				$by_item[ (int) $t['agenda_item_id'] ] = $t;
			} else {
				$orphans[] = $t;
			}
		}
		$rows = array();
		foreach ( $meeting['agenda'] as $item ) {
			$t = isset( $by_item[ $item['id'] ] ) ? $by_item[ $item['id'] ] : null;
			if ( $item['timed'] || $t ) {
				$rows[] = self::timing_row( $item, $t );
			}
		}
		foreach ( $orphans as $t ) {
			$rows[] = self::timing_row( null, $t );
		}
		$summary = array(
			'within'    => 0,
			'under'     => 0,
			'over'      => 0,
			'not_timed' => 0,
			'guest'     => 0,
		);
		foreach ( $rows as $r ) {
			++$summary[ $r['verdict'] ];
		}
		unset( $meeting['agenda'] );
		return array(
			'meeting'   => $meeting,
			'grace_sec' => TMMT_GRACE_SEC,
			'rows'      => $rows,
			'summary'   => $summary,
		);
	}

	/**
	 * Marks the meeting completed and returns the timing report.
	 *
	 * @param int $meeting_id Meeting.
	 * @return array|WP_Error
	 */
	public static function complete_meeting( $meeting_id ) {
		$res = TMMT_Meetings::update( $meeting_id, array( 'status' => 'completed' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::timing_report( $meeting_id );
	}

	/**
	 * Saves filler word counts for one speaker, replacing that speaker's earlier counts.
	 *
	 * @param array $data meeting_id, person_name, agenda_item_id (optional), counts (word => int), notes (optional).
	 * @return array|WP_Error
	 */
	public static function save_filler_counts( $data ) {
		global $wpdb;
		$meeting_id = (int) $data['meeting_id'];
		$meeting    = TMMT_Meetings::get( $meeting_id, false );
		if ( ! $meeting ) {
			return new WP_Error( 'tmmt_not_found', __( 'Meeting not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$name = tmmt_clean_line( isset( $data['person_name'] ) ? $data['person_name'] : '' );
		if ( '' === $name ) {
			return new WP_Error( 'tmmt_invalid', __( 'Please choose who was speaking.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		$item_id = self::item_in_meeting( isset( $data['agenda_item_id'] ) ? (int) $data['agenda_item_id'] : 0, $meeting_id );
		$counts  = array();
		$raw     = isset( $data['counts'] ) ? (array) $data['counts'] : array();
		$fillers = tmmt_filler_words();
		// The word of the day is counted too (using it is good), but it is not a filler word, so it stays out of the total.
		$words = array_unique( array_merge( $fillers, '' !== $meeting['word_of_day'] ? array( $meeting['word_of_day'] ) : array() ) );
		foreach ( $words as $word ) {
			$counts[ $word ] = isset( $raw[ $word ] ) ? max( 0, min( 999, (int) $raw[ $word ] ) ) : 0;
		}
		$total = 0;
		foreach ( $fillers as $word ) {
			$total += $counts[ $word ];
		}
		$table = tmmt_table( 'filler_counts' );
		// One record per speaker: the Ah-Counter saves after every tap, so overwrite the speaker's
		// earlier record (same agenda item, or same name when there is no item) instead of adding rows.
		$existing = $item_id
			? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE meeting_id = %d AND agenda_item_id = %d ORDER BY id DESC LIMIT 1", $meeting_id, $item_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE meeting_id = %d AND agenda_item_id IS NULL AND person_name = %s ORDER BY id DESC LIMIT 1", $meeting_id, $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row      = array(
			'meeting_id'     => $meeting_id,
			'agenda_item_id' => $item_id,
			'person_name'    => $name,
			'counts'         => wp_json_encode( $counts ),
			'total'          => $total,
		);
		// Notes are optional: a save without them keeps the notes already stored.
		if ( isset( $data['notes'] ) ) {
			$row['notes'] = mb_substr( sanitize_textarea_field( $data['notes'] ), 0, 5000 );
		}
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );
			return self::get_filler( (int) $existing );
		}
		$wpdb->insert( $table, $row + array( 'created_at' => current_time( 'mysql' ) ) );
		return self::get_filler( (int) $wpdb->insert_id );
	}

	/**
	 * Deletes one filler count record.
	 *
	 * @param int $id Record.
	 * @return true
	 */
	public static function delete_filler( $id ) {
		global $wpdb;
		$wpdb->delete( tmmt_table( 'filler_counts' ), array( 'id' => (int) $id ), array( '%d' ) );
		return true;
	}

	/**
	 * One filler record.
	 *
	 * @param int $id Record.
	 * @return array|null
	 */
	private static function get_filler( $id ) {
		$rows = self::query( 'filler_counts', array( 'id' => $id ) );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Filler counts, filtered.
	 *
	 * @param array $filter meeting_id and/or person_name.
	 * @return array[]
	 */
	public static function list_filler_counts( $filter ) {
		return self::query( 'filler_counts', $filter );
	}

	/**
	 * Saves feedback.
	 *
	 * @param array $data meeting_id, kind (audience|evaluation), person_name, agenda_item_id, giver_name, about, went_well, potential, body.
	 * @return array|WP_Error
	 */
	public static function submit_feedback( $data ) {
		global $wpdb;
		$meeting_id = (int) $data['meeting_id'];
		if ( ! TMMT_Meetings::get( $meeting_id, false ) ) {
			return new WP_Error( 'tmmt_not_found', __( 'Meeting not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$kind = isset( $data['kind'] ) && 'evaluation' === $data['kind'] ? 'evaluation' : 'audience';
		$item = self::item_in_meeting( isset( $data['agenda_item_id'] ) ? (int) $data['agenda_item_id'] : 0, $meeting_id );
		$name = tmmt_clean_line( isset( $data['person_name'] ) ? $data['person_name'] : '' );
		if ( '' === $name && $item ) {
			$name = TMMT_Meetings::get_item( $item )['person_name'];
		}
		$row = array(
			'meeting_id'     => $meeting_id,
			'agenda_item_id' => $item,
			'person_name'    => $name,
			'kind'           => $kind,
			// Audience feedback is anonymous by design: never store a giver name for it.
			'giver_name'     => 'evaluation' === $kind ? tmmt_clean_line( isset( $data['giver_name'] ) ? $data['giver_name'] : '' ) : '',
			'about'          => tmmt_clean_line( isset( $data['about'] ) ? $data['about'] : '' ),
			'went_well'      => tmmt_clean_text( isset( $data['went_well'] ) ? $data['went_well'] : '' ),
			'potential'      => tmmt_clean_text( isset( $data['potential'] ) ? $data['potential'] : '' ),
			'body'           => tmmt_clean_text( isset( $data['body'] ) ? $data['body'] : '' ),
			'created_at'     => current_time( 'mysql' ),
		);
		if ( '' === $row['person_name'] ) {
			return new WP_Error( 'tmmt_invalid', __( 'Please choose who the feedback is for.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $row['went_well'] . $row['potential'] . $row['body'] ) ) {
			return new WP_Error( 'tmmt_invalid', __( 'Please write some feedback first.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( tmmt_table( 'feedback' ), $row );
		$rows = self::query( 'feedback', array( 'id' => (int) $wpdb->insert_id ) );
		return $rows[0];
	}

	/**
	 * Saves the General Evaluator's notes on one topic (a role or an agenda section), replacing earlier
	 * notes on that topic for this meeting. Empty notes remove the record.
	 *
	 * @param array $data meeting_id, topic (key of tmmt_ge_topics()), person_name, body, agenda_item_id (evaluator only).
	 * @return array|null|WP_Error The saved record, or null when removed.
	 */
	public static function save_ge_notes( $data ) {
		global $wpdb;
		$meeting_id = (int) $data['meeting_id'];
		if ( ! TMMT_Meetings::get( $meeting_id, false ) ) {
			return new WP_Error( 'tmmt_not_found', __( 'Meeting not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$topics = tmmt_ge_topics();
		if ( ! isset( $topics[ $data['topic'] ] ) ) {
			return new WP_Error( 'tmmt_invalid', __( 'Unknown topic.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		$about = $topics[ $data['topic'] ];
		// There can be several evaluators: their notes are kept apart by agenda item.
		$item = 'evaluator' === $data['topic'] ? self::item_in_meeting( isset( $data['agenda_item_id'] ) ? (int) $data['agenda_item_id'] : 0, $meeting_id ) : null;
		if ( 'evaluator' === $data['topic'] && ! $item ) {
			return new WP_Error( 'tmmt_invalid', __( 'Please choose an evaluator from the agenda.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		$table = tmmt_table( 'feedback' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE meeting_id = %d AND kind = 'general' AND about = %s AND COALESCE(agenda_item_id, 0) = %d", $meeting_id, $about, (int) $item ) );
		$body = tmmt_clean_text( isset( $data['body'] ) ? $data['body'] : '' );
		if ( '' === trim( $body ) ) {
			if ( $id ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			}
			return null;
		}
		$row = array(
			'meeting_id'     => $meeting_id,
			'agenda_item_id' => $item,
			// Empty for a section (Speeches, Break, ...): the notes are not about one person.
			'person_name' => tmmt_clean_line( isset( $data['person_name'] ) ? $data['person_name'] : '' ),
			'kind'        => 'general',
			'about'       => $about,
			'body'        => $body,
		);
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $row + array( 'giver_name' => '', 'went_well' => '', 'potential' => '', 'created_at' => current_time( 'mysql' ) ) );
			$id = (int) $wpdb->insert_id;
		}
		$rows = self::query( 'feedback', array( 'id' => $id ) );
		return $rows[0];
	}

	/**
	 * Feedback, filtered.
	 *
	 * @param array $filter meeting_id, person_name, kind.
	 * @return array[]
	 */
	public static function list_feedback( $filter ) {
		return self::query( 'feedback', $filter );
	}

	/**
	 * Deletes audience and evaluator feedback 7 days after it was given: it is for the speaker to
	 * read after the meeting, not to keep. The General Evaluator's notes stay.
	 * Runs on page loads, at most once an hour.
	 */
	public static function purge_old_feedback() {
		global $wpdb;
		if ( get_transient( 'tmmt_purge_feedback' ) ) {
			return;
		}
		set_transient( 'tmmt_purge_feedback', 1, HOUR_IN_SECONDS );
		$table = tmmt_table( 'feedback' );
		// created_at is stored in site time (current_time( 'mysql' )), so compare in site time too.
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE kind IN ('audience', 'evaluation') AND created_at < %s", $cutoff ) );
	}

	/**
	 * Deletes one feedback entry.
	 *
	 * @param int $id Entry.
	 * @return true
	 */
	public static function delete_feedback( $id ) {
		global $wpdb;
		$wpdb->delete( tmmt_table( 'feedback' ), array( 'id' => (int) $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Everything recorded, for one meeting and/or one person: timings, filler counts, feedback.
	 *
	 * @param array $filter meeting_id, person_name.
	 * @return array
	 */
	public static function full_report( $filter ) {
		return array(
			'timings'       => self::query( 'timings', $filter ),
			'filler_counts' => self::query( 'filler_counts', $filter ),
			'feedback'      => self::query( 'feedback', $filter ),
		);
	}

	/**
	 * Distinct person names that appear in any results or agendas.
	 *
	 * @return string[]
	 */
	public static function people() {
		global $wpdb;
		$parts = array();
		foreach ( array( 'agenda_items', 'timings', 'filler_counts', 'feedback' ) as $t ) {
			$parts[] = 'SELECT person_name FROM ' . tmmt_table( $t ) . " WHERE person_name <> ''";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names only.
		return $wpdb->get_col( 'SELECT DISTINCT person_name FROM (' . implode( ' UNION ', $parts ) . ') p ORDER BY person_name ASC' );
	}

	/**
	 * Returns the item ID when it belongs to the meeting, otherwise null.
	 *
	 * @param int $item_id    Item.
	 * @param int $meeting_id Meeting.
	 * @return int|null
	 */
	private static function item_in_meeting( $item_id, $meeting_id ) {
		if ( ! $item_id ) {
			return null;
		}
		$item = TMMT_Meetings::get_item( $item_id );
		return $item && $item['meeting_id'] === $meeting_id ? $item_id : null;
	}

	/**
	 * Generic filtered select with formatting, joined with the meeting date.
	 *
	 * @param string $table  timings|filler_counts|feedback.
	 * @param array  $filter id, meeting_id, person_name, kind.
	 * @return array[]
	 */
	private static function query( $table, $filter ) {
		global $wpdb;
		$t     = tmmt_table( $table );
		$m     = tmmt_table( 'meetings' );
		$where = array( '1=1' );
		$vals  = array();
		foreach ( array( 'id', 'meeting_id' ) as $k ) {
			if ( ! empty( $filter[ $k ] ) ) {
				$where[] = "x.$k = %d";
				$vals[]  = (int) $filter[ $k ];
			}
		}
		if ( isset( $filter['person_name'] ) && '' !== $filter['person_name'] ) {
			$where[] = 'x.person_name = %s';
			$vals[]  = (string) $filter['person_name'];
		}
		if ( 'feedback' === $table && ! empty( $filter['kind'] ) ) {
			$where[] = 'x.kind = %s';
			$vals[]  = (string) $filter['kind'];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and fixed fragments only.
		$sql = "SELECT x.*, m.meeting_date FROM $t x LEFT JOIN $m m ON m.id = x.meeting_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY m.meeting_date DESC, x.id ASC LIMIT 1000';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $vals ? $wpdb->prepare( $sql, $vals ) : $sql, ARRAY_A );
		return array_map(
			static function ( $r ) use ( $table ) {
				return TMMT_Results::format( $table, $r );
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Casts a results row.
	 *
	 * @param string $table Table short name.
	 * @param array  $r     Row.
	 * @return array
	 */
	public static function format( $table, $r ) {
		$base = array(
			'id'             => (int) $r['id'],
			'meeting_id'     => (int) $r['meeting_id'],
			'meeting_date'   => (string) $r['meeting_date'],
			'agenda_item_id' => $r['agenda_item_id'] ? (int) $r['agenda_item_id'] : null,
			'person_name'    => (string) $r['person_name'],
		);
		if ( 'timings' === $table ) {
			return $base + array(
				'role_label'   => (string) $r['role_label'],
				'green_sec'    => (int) $r['green_sec'],
				'yellow_sec'   => (int) $r['yellow_sec'],
				'red_sec'      => (int) $r['red_sec'],
				'duration_sec' => (int) $r['duration_sec'],
				'verdict'      => tmmt_timing_verdict( (int) $r['duration_sec'], (int) $r['green_sec'], (int) $r['red_sec'] ),
				'recorded_at'  => (string) $r['recorded_at'],
			);
		}
		if ( 'filler_counts' === $table ) {
			$counts = json_decode( (string) $r['counts'], true );
			return $base + array(
				'counts'     => is_array( $counts ) ? array_map( 'intval', $counts ) : array(),
				'total'      => (int) $r['total'],
				'notes'      => (string) $r['notes'],
				'created_at' => (string) $r['created_at'],
			);
		}
		return $base + array(
			'kind'       => (string) $r['kind'],
			'giver_name' => (string) $r['giver_name'],
			'about'      => (string) $r['about'],
			'went_well'  => (string) $r['went_well'],
			'potential'  => (string) $r['potential'],
			'body'       => (string) $r['body'],
			'created_at' => (string) $r['created_at'],
		);
	}
}
