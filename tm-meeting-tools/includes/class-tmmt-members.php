<?php
/**
 * Club members: name and Toastmasters member ID. The agenda editor picks people from this list.
 * The club manager syncs them from tmclub.eu (easy-Speak); tm_id is the tmclub.eu user ID. Members
 * it has not seen for the configured number of days (Settings) are removed.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Member repository.
 */
class TMMT_Members {

	/**
	 * All members, by name.
	 *
	 * @return array[] id, name, tm_id, last_synced_at (site time or null).
	 */
	public static function all() {
		global $wpdb;
		$table = tmmt_table( 'members' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC, id ASC", ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'id'             => (int) $r['id'],
				'name'           => (string) $r['name'],
				'tm_id'          => (string) $r['tm_id'],
				'last_synced_at' => null === $r['last_synced_at'] ? null : (string) $r['last_synced_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Cleaned fields, or an error when the name is missing.
	 *
	 * @param array $data name, tm_id.
	 * @return array|WP_Error
	 */
	private static function prepare( $data ) {
		$row = array(
			'name'  => tmmt_clean_line( isset( $data['name'] ) ? $data['name'] : '' ),
			'tm_id' => substr( tmmt_clean_line( isset( $data['tm_id'] ) ? $data['tm_id'] : '' ), 0, 30 ),
		);
		if ( '' === $row['name'] ) {
			return new WP_Error( 'tmmt_invalid', __( 'A member needs a name.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		return $row;
	}

	/**
	 * Adds a member.
	 *
	 * @param array $data name, tm_id.
	 * @return int|WP_Error New ID.
	 */
	public static function create( $data ) {
		global $wpdb;
		$row = self::prepare( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// Manually added members get the same 2 month grace period as synced ones.
		$row['last_synced_at'] = current_time( 'mysql' );
		$wpdb->insert( tmmt_table( 'members' ), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a member (name and Toastmasters ID only; the sync fields stay as they are).
	 *
	 * @param int   $id   Member.
	 * @param array $data name, tm_id.
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$row = self::prepare( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->update( tmmt_table( 'members' ), $row, array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Removes a member. Agenda items keep the name they were given.
	 *
	 * @param int $id Member.
	 * @return true
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( tmmt_table( 'members' ), array( 'id' => (int) $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Upserts members seen by the club manager in a meeting and stamps them as synced now.
	 * A member is matched by tm_id (the tmclub.eu user ID), else by name (case-insensitive) among
	 * members without a tm_id yet (which links them), else added.
	 *
	 * @param array[] $people List of { tm_id, name }. Entries missing either are skipped.
	 * @return array created, updated, members (all()).
	 */
	public static function sync( $people ) {
		global $wpdb;
		$table   = tmmt_table( 'members' );
		$now     = current_time( 'mysql' );
		$created = 0;
		$updated = 0;
		foreach ( (array) $people as $person ) {
			$person = (array) $person;
			$uid    = substr( tmmt_clean_line( isset( $person['tm_id'] ) ? $person['tm_id'] : '' ), 0, 30 );
			$name   = tmmt_clean_line( isset( $person['name'] ) ? $person['name'] : '' );
			if ( '' === $uid || '' === $name ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE tm_id = %s ORDER BY id LIMIT 1", $uid ) );
			if ( ! $id ) {
				// LOWER() on both sides so the match does not depend on the table collation.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE tm_id = '' AND LOWER(name) = LOWER(%s) ORDER BY id LIMIT 1", $name ) );
			}
			$row = array(
				'name'           => $name,
				'tm_id'          => $uid,
				'last_synced_at' => $now,
			);
			if ( $id ) {
				$wpdb->update( $table, $row, array( 'id' => (int) $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
				++$updated;
			} else {
				$wpdb->insert( $table, $row, array( '%s', '%s', '%s' ) );
				++$created;
			}
		}
		return array(
			'created' => $created,
			'updated' => $updated,
			'members' => self::all(),
		);
	}

	/**
	 * Deletes members not synced for the number of days set in Settings (default 60).
	 * Members with no last_synced_at are kept. Runs on init at most once a day, because WP-Cron
	 * is unreliable on the live host.
	 */
	public static function prune_inactive() {
		global $wpdb;
		if ( get_transient( 'tmmt_prune_members' ) ) {
			return;
		}
		set_transient( 'tmmt_prune_members', 1, DAY_IN_SECONDS );
		$after = tmmt_member_inactive_days() * DAY_IN_SECONDS;
		$table = tmmt_table( 'members' );
		// last_synced_at is stored in site time (current_time( 'mysql' )), so compare in site time too.
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - $after );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE last_synced_at IS NOT NULL AND last_synced_at < %s", $cutoff ) );
	}
}
