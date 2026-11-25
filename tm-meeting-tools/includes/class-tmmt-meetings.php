<?php
/**
 * Meetings and agenda items.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meeting repository.
 */
class TMMT_Meetings {

	const STATUSES = array( 'planned', 'in_progress', 'completed' );

	/**
	 * Lists meetings.
	 *
	 * @param array $args status (optional), when: upcoming|past|all, limit.
	 * @return array[]
	 */
	public static function list_meetings( $args = array() ) {
		global $wpdb;
		$table = tmmt_table( 'meetings' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		$when  = isset( $args['when'] ) ? $args['when'] : 'all';
		$today = current_time( 'Y-m-d' );
		$order = 'DESC';
		if ( 'upcoming' === $when ) {
			$where[] = 'meeting_date >= %s';
			$vals[]  = $today;
			$order   = 'ASC';
		} elseif ( 'past' === $when ) {
			$where[] = 'meeting_date < %s';
			$vals[]  = $today;
		}
		$limit  = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$vals[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and fixed fragments only.
		$sql  = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY meeting_date $order, start_time $order, id $order LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'format' ), $rows ? $rows : array() );
	}

	/**
	 * Meeting shown when no ID is given: the next meeting from today, otherwise the latest one.
	 *
	 * @return int 0 when there are no meetings.
	 */
	public static function current_id() {
		$next = self::list_meetings(
			array(
				'when'  => 'upcoming',
				'limit' => 1,
			)
		);
		if ( $next ) {
			return $next[0]['id'];
		}
		$last = self::list_meetings( array( 'limit' => 1 ) );
		return $last ? $last[0]['id'] : 0;
	}

	/**
	 * Marks meetings as completed 30 minutes after their planned end (start time plus the red time
	 * of every agenda item, the same sum the agenda uses for its start times).
	 * Runs on page loads, at most every 5 minutes, so no cron job is needed.
	 */
	public static function complete_finished() {
		global $wpdb;
		if ( get_transient( 'tmmt_auto_complete' ) ) {
			return;
		}
		set_transient( 'tmmt_auto_complete', 1, 5 * MINUTE_IN_SECONDS );
		$table = tmmt_table( 'meetings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status <> 'completed' AND meeting_date <= %s", current_time( 'Y-m-d' ) ) );
		foreach ( $ids as $id ) {
			$m = self::get( (int) $id );
			if ( ! $m ) {
				continue;
			}
			try {
				$start = new DateTimeImmutable( $m['date'] . ' ' . ( $m['time'] ? $m['time'] : '00:00' ), wp_timezone() );
			} catch ( Exception $e ) {
				continue;
			}
			$end = $start->getTimestamp() + array_sum( wp_list_pluck( $m['agenda'], 'red_sec' ) );
			if ( time() >= $end + 30 * MINUTE_IN_SECONDS ) {
				self::update( $m['id'], array( 'status' => 'completed' ) );
			}
		}
	}

	/**
	 * One meeting, optionally with its agenda.
	 *
	 * @param int  $id          ID.
	 * @param bool $with_agenda Include agenda items.
	 * @return array|null
	 */
	public static function get( $id, $with_agenda = true ) {
		global $wpdb;
		$table = tmmt_table( 'meetings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$meeting = self::format( $row );
		if ( $with_agenda ) {
			$meeting['agenda'] = self::items( $meeting['id'] );
		}
		return $meeting;
	}

	/**
	 * Casts a meeting row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( $row ) {
		return array(
			'id'           => (int) $row['id'],
			'date'         => (string) $row['meeting_date'],
			'time'         => substr( (string) $row['start_time'], 0, 5 ),
			'theme'        => (string) $row['theme'],
			'word_of_day'  => (string) $row['word_of_day'],
			'status'       => (string) $row['status'],
			'completed_at' => $row['completed_at'] ? (string) $row['completed_at'] : null,
		);
	}

	/**
	 * Validates meeting fields.
	 *
	 * @param array      $data     Input.
	 * @param array|null $existing Existing meeting.
	 * @return array|WP_Error DB row.
	 */
	private static function prepare( $data, $existing = null ) {
		$row = $existing ? array(
			'meeting_date' => $existing['date'],
			'start_time'   => $existing['time'],
			'theme'        => $existing['theme'],
			'word_of_day'  => $existing['word_of_day'],
			'status'       => $existing['status'],
		) : array(
			'meeting_date' => '',
			'start_time'   => '18:00',
			'theme'        => '',
			'word_of_day'  => '',
			'status'       => 'planned',
		);
		if ( isset( $data['date'] ) ) {
			$row['meeting_date'] = (string) $data['date'];
		}
		if ( isset( $data['time'] ) && '' !== $data['time'] ) {
			$row['start_time'] = (string) $data['time'];
		}
		if ( isset( $data['theme'] ) ) {
			$row['theme'] = tmmt_clean_line( $data['theme'] );
		}
		if ( isset( $data['word_of_day'] ) ) {
			$row['word_of_day'] = tmmt_clean_line( $data['word_of_day'] );
		}
		if ( isset( $data['status'] ) ) {
			$row['status'] = (string) $data['status'];
		}
		$d = DateTime::createFromFormat( '!Y-m-d', $row['meeting_date'] );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $row['meeting_date'] ) {
			return new WP_Error( 'tmmt_invalid', __( 'Please give a valid date (YYYY-MM-DD).', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', substr( $row['start_time'], 0, 5 ) ) ) {
			return new WP_Error( 'tmmt_invalid', __( 'Please give a valid time (HH:MM).', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		$row['start_time'] = substr( $row['start_time'], 0, 5 ) . ':00';
		if ( ! in_array( $row['status'], self::STATUSES, true ) ) {
			return new WP_Error( 'tmmt_invalid', __( 'Unknown meeting status.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		return $row;
	}

	/**
	 * Creates a meeting.
	 *
	 * @param array $data date, time, theme, word_of_day, template (standard|empty).
	 * @return array|WP_Error
	 */
	public static function create( $data ) {
		global $wpdb;
		$row = self::prepare( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( tmmt_table( 'meetings' ), $row, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'tmmt_db', __( 'Could not save the meeting.', 'tm-meeting-tools' ), array( 'status' => 500 ) );
		}
		if ( isset( $data['template'] ) && 'standard' === $data['template'] ) {
			foreach ( TMMT_Install::standard_agenda() as $role_name ) {
				$role = TMMT_Roles::find_by_name( $role_name );
				if ( $role ) {
					self::add_item( $id, array( 'role_type_id' => $role['id'] ) );
				}
			}
		}
		return self::get( $id );
	}

	/**
	 * Updates a meeting.
	 *
	 * @param int   $id   ID.
	 * @param array $data Fields.
	 * @return array|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$existing = self::get( $id, false );
		if ( ! $existing ) {
			return self::not_found();
		}
		$row = self::prepare( $data, $existing );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'completed' === $row['status'] && 'completed' !== $existing['status'] ) {
			$row['completed_at'] = current_time( 'mysql' );
		} elseif ( 'completed' !== $row['status'] ) {
			$row['completed_at'] = null;
		}
		$wpdb->update( tmmt_table( 'meetings' ), $row, array( 'id' => $id ), null, array( '%d' ) );
		return self::get( $id );
	}

	/**
	 * Deletes a meeting with its agenda and all recorded results.
	 *
	 * @param int $id ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		if ( ! self::get( $id, false ) ) {
			return self::not_found();
		}
		foreach ( array( 'agenda_items', 'timings', 'filler_counts', 'feedback' ) as $t ) {
			$wpdb->delete( tmmt_table( $t ), array( 'meeting_id' => $id ), array( '%d' ) );
		}
		$wpdb->delete( tmmt_table( 'meetings' ), array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Agenda items of a meeting, in order, with resolved timing and display label.
	 *
	 * @param int $meeting_id Meeting.
	 * @return array[]
	 */
	public static function items( $meeting_id ) {
		global $wpdb;
		$items = tmmt_table( 'agenda_items' );
		$roles = tmmt_table( 'role_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$sql  = "SELECT i.*, r.name AS role_name, r.category AS role_category, r.green_sec AS r_green, r.yellow_sec AS r_yellow, r.red_sec AS r_red, r.timed AS r_timed
			FROM $items i LEFT JOIN $roles r ON r.id = i.role_type_id
			WHERE i.meeting_id = %d ORDER BY i.position ASC, i.id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $meeting_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $rows ? $rows : array();

		// "Speaker 1", "Speaker 2" when a role appears more than once.
		$totals = array_count_values( array_map( 'intval', wp_list_pluck( $rows, 'role_type_id' ) ) );
		$seen   = array();
		$out    = array();
		foreach ( $rows as $row ) {
			$rid          = (int) $row['role_type_id'];
			$seen[ $rid ] = isset( $seen[ $rid ] ) ? $seen[ $rid ] + 1 : 1;
			$name         = $row['role_name'] ? $row['role_name'] : __( 'Unknown role', 'tm-meeting-tools' );
			// Ad-hoc table topic speakers are numbered too, which matches how the agenda reads.
			$label = $totals[ $rid ] > 1 ? sprintf( '%s %d', $name, $seen[ $rid ] ) : $name;
			$out[] = self::format_item( $row, $label );
		}
		return $out;
	}

	/**
	 * Casts an agenda item row (joined with its role).
	 *
	 * @param array  $row   Row.
	 * @param string $label Display label.
	 * @return array
	 */
	private static function format_item( $row, $label ) {
		$cats     = tmmt_speech_categories();
		$category = $row['role_category'] ? (string) $row['role_category'] : 'other';
		$source   = 'role';
		$green    = (int) $row['r_green'];
		$yellow   = (int) $row['r_yellow'];
		$red      = (int) $row['r_red'];
		if ( null !== $row['green_sec'] && null !== $row['yellow_sec'] && null !== $row['red_sec'] ) {
			$source = 'custom';
			$green  = (int) $row['green_sec'];
			$yellow = (int) $row['yellow_sec'];
			$red    = (int) $row['red_sec'];
		} elseif ( 'speech' === $category && isset( $cats[ $row['speech_category'] ] ) ) {
			$source = 'speech_category';
			$green  = (int) $cats[ $row['speech_category'] ]['green'];
			$yellow = (int) $cats[ $row['speech_category'] ]['yellow'];
			$red    = (int) $cats[ $row['speech_category'] ]['red'];
		}
		return array(
			'id'              => (int) $row['id'],
			'meeting_id'      => (int) $row['meeting_id'],
			'position'        => (int) $row['position'],
			'role_type_id'    => (int) $row['role_type_id'],
			'role_name'       => $row['role_name'] ? (string) $row['role_name'] : '',
			'label'           => $label,
			'category'        => $category,
			'person_name'     => (string) $row['person_name'],
			'speech_title'    => (string) $row['speech_title'],
			'speech_category' => (string) $row['speech_category'],
			'green_sec'       => $green,
			'yellow_sec'      => $yellow,
			'red_sec'         => $red,
			'timing_source'   => $source,
			// Roles can opt out of timing (e.g. Break, Timekeeper) while keeping their planned duration.
			'timed'           => $red > 0 && ! empty( $row['r_timed'] ),
			'is_adhoc'        => (bool) $row['is_adhoc'],
			// Guests are timed but not rated: no time verdict, no Ah-Counter.
			'is_guest'        => ! empty( $row['is_guest'] ),
		);
	}

	/**
	 * One agenda item (formatted, label as in its meeting).
	 *
	 * @param int $id Item ID.
	 * @return array|null
	 */
	public static function get_item( $id ) {
		global $wpdb;
		$table = tmmt_table( 'agenda_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$meeting_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meeting_id FROM $table WHERE id = %d", $id ) );
		if ( ! $meeting_id ) {
			return null;
		}
		foreach ( self::items( $meeting_id ) as $item ) {
			if ( $item['id'] === (int) $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Validates item fields into a DB row (partial for updates).
	 *
	 * @param array $data   Input.
	 * @param bool  $is_new Creating.
	 * @return array|WP_Error
	 */
	private static function prepare_item( $data, $is_new ) {
		$row = array();
		if ( $is_new || isset( $data['role_type_id'] ) ) {
			$role = TMMT_Roles::get( isset( $data['role_type_id'] ) ? (int) $data['role_type_id'] : 0 );
			if ( ! $role ) {
				return new WP_Error( 'tmmt_invalid', __( 'Please choose a valid role.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
			}
			$row['role_type_id'] = $role['id'];
		}
		foreach ( array( 'person_name', 'speech_title' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$row[ $k ] = tmmt_clean_line( $data[ $k ] );
			}
		}
		if ( isset( $data['is_guest'] ) ) {
			$row['is_guest'] = $data['is_guest'] ? 1 : 0;
		}
		if ( isset( $data['speech_category'] ) ) {
			$cat = (string) $data['speech_category'];
			if ( '' !== $cat && ! isset( tmmt_speech_categories()[ $cat ] ) ) {
				return new WP_Error( 'tmmt_invalid', __( 'Unknown speech category.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
			}
			$row['speech_category'] = $cat;
		}
		$keys    = array( 'green_sec', 'yellow_sec', 'red_sec' );
		$touched = array_intersect( $keys, array_keys( $data ) );
		if ( $touched ) {
			$vals = array();
			foreach ( $keys as $k ) {
				$vals[ $k ] = isset( $data[ $k ] ) && '' !== $data[ $k ] ? max( 0, (int) $data[ $k ] ) : null;
			}
			$set = count( array_filter( $vals, 'is_int' ) );
			if ( 0 !== $set && 3 !== $set ) {
				return new WP_Error( 'tmmt_invalid', __( 'Custom timing needs all three times (green, yellow, red), or none.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
			}
			if ( 3 === $set && ( $vals['green_sec'] > $vals['yellow_sec'] || $vals['yellow_sec'] > $vals['red_sec'] ) ) {
				return new WP_Error( 'tmmt_invalid', __( 'Times must go up: green, then yellow, then red.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
			}
			$row = array_merge( $row, $vals );
		}
		return $row;
	}

	/**
	 * Adds an agenda item at the end, or right after another item.
	 *
	 * @param int   $meeting_id Meeting.
	 * @param array $data       role_type_id, person_name, speech_title, speech_category, *_sec, after_item_id, is_adhoc.
	 * @return array|WP_Error
	 */
	public static function add_item( $meeting_id, $data ) {
		global $wpdb;
		if ( ! self::get( $meeting_id, false ) ) {
			return self::not_found();
		}
		$row = self::prepare_item( $data, true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$table = tmmt_table( 'agenda_items' );
		$after = isset( $data['after_item_id'] ) ? (int) $data['after_item_id'] : 0;
		if ( $after ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$pos = $wpdb->get_var( $wpdb->prepare( "SELECT position FROM $table WHERE id = %d AND meeting_id = %d", $after, $meeting_id ) );
			if ( null === $pos ) {
				return new WP_Error( 'tmmt_invalid', __( 'The item to insert after is not on this agenda.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
			}
			$position = (int) $pos + 1;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET position = position + 1 WHERE meeting_id = %d AND position >= %d", $meeting_id, $position ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$position = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $table WHERE meeting_id = %d", $meeting_id ) ) + 1;
		}
		$row['meeting_id'] = $meeting_id;
		$row['position']   = $position;
		$row['is_adhoc']   = empty( $data['is_adhoc'] ) ? 0 : 1;
		$wpdb->insert( $table, $row );
		return self::get_item( (int) $wpdb->insert_id );
	}

	/**
	 * Updates an agenda item.
	 *
	 * @param int   $id   Item ID.
	 * @param array $data Fields.
	 * @return array|WP_Error
	 */
	public static function update_item( $id, $data ) {
		global $wpdb;
		if ( ! self::get_item( $id ) ) {
			return new WP_Error( 'tmmt_not_found', __( 'Agenda item not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$row = self::prepare_item( $data, false );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $row ) {
			$wpdb->update( tmmt_table( 'agenda_items' ), $row, array( 'id' => $id ), null, array( '%d' ) );
		}
		return self::get_item( $id );
	}

	/**
	 * Removes an agenda item. Results recorded for it keep the person's name.
	 *
	 * @param int $id Item ID.
	 * @return true|WP_Error
	 */
	public static function remove_item( $id ) {
		global $wpdb;
		if ( ! self::get_item( $id ) ) {
			return new WP_Error( 'tmmt_not_found', __( 'Agenda item not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$wpdb->delete( tmmt_table( 'agenda_items' ), array( 'id' => $id ), array( '%d' ) );
		foreach ( array( 'timings', 'filler_counts', 'feedback' ) as $t ) {
			$wpdb->update( tmmt_table( $t ), array( 'agenda_item_id' => null ), array( 'agenda_item_id' => $id ), null, array( '%d' ) );
		}
		return true;
	}

	/**
	 * Sets the agenda order. Must list every item of the meeting exactly once.
	 *
	 * @param int   $meeting_id Meeting.
	 * @param int[] $ids        Item IDs in the new order.
	 * @return array[]|WP_Error New agenda.
	 */
	public static function reorder( $meeting_id, $ids ) {
		global $wpdb;
		if ( ! self::get( $meeting_id, false ) ) {
			return self::not_found();
		}
		$ids      = array_map( 'intval', (array) $ids );
		$existing = wp_list_pluck( self::items( $meeting_id ), 'id' );
		$a        = $ids;
		$b        = $existing;
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			return new WP_Error( 'tmmt_invalid', __( 'The new order must contain every agenda item of the meeting exactly once.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		foreach ( $ids as $i => $id ) {
			$wpdb->update( tmmt_table( 'agenda_items' ), array( 'position' => $i + 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		}
		return self::items( $meeting_id );
	}

	/**
	 * Not found error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'tmmt_not_found', __( 'Meeting not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
	}
}
