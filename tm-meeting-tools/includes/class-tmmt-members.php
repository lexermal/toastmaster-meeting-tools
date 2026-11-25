<?php
/**
 * Club members: name and Toastmasters member ID. The agenda editor picks people from this list.
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
	 * @return array[] id, name, tm_id.
	 */
	public static function all() {
		global $wpdb;
		$table = tmmt_table( 'members' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC, id ASC", ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'id'    => (int) $r['id'],
				'name'  => (string) $r['name'],
				'tm_id' => (string) $r['tm_id'],
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
		$wpdb->insert( tmmt_table( 'members' ), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a member.
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
}
