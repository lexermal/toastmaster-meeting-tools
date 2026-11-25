<?php
/**
 * Role types (agenda roles with default timing).
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Role type repository.
 */
class TMMT_Roles {

	/**
	 * All role types, ordered.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		$table = tmmt_table( 'role_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY sort_order ASC, id ASC", ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), $rows ? $rows : array() );
	}

	/**
	 * One role type.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = tmmt_table( 'role_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Casts a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( $row ) {
		return array(
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'category'   => (string) $row['category'],
			'green_sec'  => (int) $row['green_sec'],
			'yellow_sec' => (int) $row['yellow_sec'],
			'red_sec'    => (int) $row['red_sec'],
			'sort_order' => (int) $row['sort_order'],
			'timed'      => (bool) $row['timed'],
		);
	}

	/**
	 * Validates and normalises role input.
	 *
	 * @param array      $data     Input.
	 * @param array|null $existing Existing row for partial updates.
	 * @return array|WP_Error
	 */
	private static function prepare( $data, $existing = null ) {
		$out = $existing ? $existing : array(
			'name'       => '',
			'category'   => 'role',
			'green_sec'  => 0,
			'yellow_sec' => 0,
			'red_sec'    => 0,
			'sort_order' => null,
			'timed'      => true,
		);
		if ( isset( $data['name'] ) ) {
			$out['name'] = tmmt_clean_line( $data['name'] );
		}
		if ( isset( $data['category'] ) ) {
			$out['category'] = (string) $data['category'];
		}
		foreach ( array( 'green_sec', 'yellow_sec', 'red_sec', 'sort_order' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$out[ $k ] = max( 0, (int) $data[ $k ] );
			}
		}
		if ( isset( $data['timed'] ) ) {
			$out['timed'] = (bool) $data['timed'];
		}
		$out['timed'] = $out['timed'] ? 1 : 0;
		if ( '' === $out['name'] ) {
			return new WP_Error( 'tmmt_invalid', __( 'The agenda point needs a name.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		if ( ! isset( tmmt_role_categories()[ $out['category'] ] ) ) {
			return new WP_Error( 'tmmt_invalid', __( 'Unknown role category.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		if ( $out['green_sec'] > $out['yellow_sec'] || $out['yellow_sec'] > $out['red_sec'] ) {
			return new WP_Error( 'tmmt_invalid', __( 'Times must go up: green, then yellow, then red.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		unset( $out['id'] );
		return $out;
	}

	/**
	 * Creates a role type.
	 *
	 * @param array $data name, category, green_sec, yellow_sec, red_sec, sort_order.
	 * @return array|WP_Error
	 */
	public static function create( $data ) {
		global $wpdb;
		$row = self::prepare( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( null === $row['sort_order'] ) {
			$table             = tmmt_table( 'role_types' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$row['sort_order'] = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order),0) FROM $table" ) + 10;
		}
		$wpdb->insert( tmmt_table( 'role_types' ), $row, array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' ) );
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Updates a role type.
	 *
	 * @param int   $id   ID.
	 * @param array $data Fields to change.
	 * @return array|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$existing = self::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'tmmt_not_found', __( 'Agenda point not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$row = self::prepare( $data, $existing );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->update( tmmt_table( 'role_types' ), $row, array( 'id' => $id ), array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' ), array( '%d' ) );
		return self::get( $id );
	}

	/**
	 * Deletes a role type unless an agenda item still uses it.
	 *
	 * @param int $id ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return new WP_Error( 'tmmt_not_found', __( 'Agenda point not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
		}
		$items = tmmt_table( 'agenda_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $items WHERE role_type_id = %d", $id ) );
		if ( $used > 0 ) {
			return new WP_Error(
				'tmmt_role_in_use',
				/* translators: %d: number of agenda items. */
				sprintf( __( 'This agenda point is used %d times in meeting agendas. Change or remove those first.', 'tm-meeting-tools' ), $used ),
				array( 'status' => 409 )
			);
		}
		$wpdb->delete( tmmt_table( 'role_types' ), array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Finds a role type by exact name (case-insensitive).
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public static function find_by_name( $name ) {
		foreach ( self::all() as $role ) {
			if ( 0 === strcasecmp( $role['name'], $name ) ) {
				return $role;
			}
		}
		return null;
	}
}
