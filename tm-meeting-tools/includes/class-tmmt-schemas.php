<?php
/**
 * JSON schemas shared by the abilities (input and output).
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema builders.
 */
class TMMT_Schemas {

	/**
	 * Integer property.
	 *
	 * @param string $desc Description.
	 * @param int    $min  Minimum.
	 * @return array
	 */
	public static function int( $desc, $min = 0 ) {
		return array(
			'type'        => 'integer',
			'minimum'     => $min,
			'description' => $desc,
		);
	}

	/**
	 * String property.
	 *
	 * @param string $desc Description.
	 * @param int    $max  Max length (0 = none).
	 * @return array
	 */
	public static function str( $desc, $max = 190 ) {
		$s = array(
			'type'        => 'string',
			'description' => $desc,
		);
		if ( $max ) {
			$s['maxLength'] = $max;
		}
		return $s;
	}

	/**
	 * Enum string property.
	 *
	 * @param string   $desc   Description.
	 * @param string[] $values Allowed values.
	 * @return array
	 */
	public static function enum( $desc, $values ) {
		return array(
			'type'        => 'string',
			'enum'        => array_values( $values ),
			'description' => $desc,
		);
	}

	/**
	 * Input object. Defaults to an empty object so the ability can be called with no input.
	 *
	 * @param array    $props    Properties.
	 * @param string[] $required Required keys.
	 * @return array
	 */
	public static function input( $props, $required = array() ) {
		$s = array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
		// An empty PHP array would be published as a JSON list, not an object.
		if ( $props ) {
			$s['properties'] = $props;
		}
		if ( $required ) {
			$s['required'] = $required;
		} else {
			$s['default'] = array();
		}
		return $s;
	}

	/**
	 * Object output.
	 *
	 * @param array $props Properties.
	 * @return array
	 */
	public static function obj( $props ) {
		return array(
			'type'       => 'object',
			'properties' => $props,
		);
	}

	/**
	 * Array of items.
	 *
	 * @param array $items Item schema.
	 * @return array
	 */
	public static function arr( $items ) {
		return array(
			'type'  => 'array',
			'items' => $items,
		);
	}

	/**
	 * Role type.
	 *
	 * @return array
	 */
	public static function role() {
		return self::obj(
			array(
				'id'         => array( 'type' => 'integer' ),
				'name'       => array( 'type' => 'string' ),
				'category'   => self::enum( 'Role category.', array_keys( tmmt_role_categories() ) ),
				'green_sec'  => array( 'type' => 'integer' ),
				'yellow_sec' => array( 'type' => 'integer' ),
				'red_sec'    => array( 'type' => 'integer' ),
				'sort_order' => array( 'type' => 'integer' ),
				'timed'      => array( 'type' => 'boolean' ),
			)
		);
	}

	/**
	 * Agenda item.
	 *
	 * @return array
	 */
	public static function item() {
		return self::obj(
			array(
				'id'              => array( 'type' => 'integer' ),
				'meeting_id'      => array( 'type' => 'integer' ),
				'position'        => array( 'type' => 'integer' ),
				'role_type_id'    => array( 'type' => 'integer' ),
				'role_name'       => array( 'type' => 'string' ),
				'label'           => self::str( 'Display label, numbered when a role appears more than once (Speaker 1).', 0 ),
				'category'        => array( 'type' => 'string' ),
				'person_name'     => array( 'type' => 'string' ),
				'speech_title'    => array( 'type' => 'string' ),
				'speech_category' => array( 'type' => 'string' ),
				'green_sec'       => array( 'type' => 'integer' ),
				'yellow_sec'      => array( 'type' => 'integer' ),
				'red_sec'         => array( 'type' => 'integer' ),
				'timing_source'   => self::enum( 'Where the timing comes from.', array( 'role', 'speech_category', 'custom' ) ),
				'timed'           => array( 'type' => 'boolean' ),
				'is_adhoc'        => array( 'type' => 'boolean' ),
				'is_guest'        => array( 'type' => 'boolean' ),
			)
		);
	}

	/**
	 * Meeting (agenda included when requested).
	 *
	 * @return array
	 */
	public static function meeting() {
		return self::obj(
			array(
				'id'           => array( 'type' => 'integer' ),
				'date'         => self::str( 'YYYY-MM-DD', 0 ),
				'time'         => self::str( 'HH:MM', 0 ),
				'theme'        => array( 'type' => 'string' ),
				'word_of_day'  => array( 'type' => 'string' ),
				'status'       => self::enum( 'Status.', TMMT_Meetings::STATUSES ),
				'completed_at' => array( 'type' => array( 'string', 'null' ) ),
				'agenda'       => self::arr( self::item() ),
			)
		);
	}

	/**
	 * Timing report row.
	 *
	 * @return array
	 */
	public static function timing_row() {
		return self::obj(
			array(
				'agenda_item_id' => array( 'type' => array( 'integer', 'null' ) ),
				'person_name'    => array( 'type' => 'string' ),
				'role_label'     => array( 'type' => 'string' ),
				'category'       => array( 'type' => 'string' ),
				'speech_title'   => array( 'type' => 'string' ),
				'green_sec'      => array( 'type' => 'integer' ),
				'yellow_sec'     => array( 'type' => 'integer' ),
				'red_sec'        => array( 'type' => 'integer' ),
				'duration_sec'   => array( 'type' => array( 'integer', 'null' ) ),
				'verdict'        => self::enum( 'within, under or over (30 s grace either side), not_timed, or guest (timed but not rated).', array( 'within', 'under', 'over', 'not_timed', 'guest' ) ),
			)
		);
	}

	/**
	 * Timing report.
	 *
	 * @return array
	 */
	public static function timing_report() {
		$count = array( 'type' => 'integer' );
		return self::obj(
			array(
				'meeting'   => self::meeting(),
				'grace_sec' => array( 'type' => 'integer' ),
				'rows'      => self::arr( self::timing_row() ),
				'summary'   => self::obj(
					array(
						'within'    => $count,
						'under'     => $count,
						'over'      => $count,
						'not_timed' => $count,
						'guest'     => $count,
					)
				),
			)
		);
	}

	/**
	 * Common fields of results records.
	 *
	 * @param array $extra Extra properties.
	 * @return array
	 */
	private static function record( $extra ) {
		return self::obj(
			array_merge(
				array(
					'id'             => array( 'type' => 'integer' ),
					'meeting_id'     => array( 'type' => 'integer' ),
					'meeting_date'   => array( 'type' => 'string' ),
					'agenda_item_id' => array( 'type' => array( 'integer', 'null' ) ),
					'person_name'    => array( 'type' => 'string' ),
				),
				$extra
			)
		);
	}

	/**
	 * Stored timing.
	 *
	 * @return array
	 */
	public static function timing() {
		return self::record(
			array(
				'role_label'   => array( 'type' => 'string' ),
				'green_sec'    => array( 'type' => 'integer' ),
				'yellow_sec'   => array( 'type' => 'integer' ),
				'red_sec'      => array( 'type' => 'integer' ),
				'duration_sec' => array( 'type' => 'integer' ),
				'verdict'      => array( 'type' => 'string' ),
				'recorded_at'  => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Filler counts record.
	 *
	 * @return array
	 */
	public static function filler() {
		return self::record(
			array(
				'counts'     => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'total'      => array( 'type' => 'integer' ),
				'notes'      => array( 'type' => 'string' ),
				'created_at' => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Feedback record.
	 *
	 * @return array
	 */
	public static function feedback() {
		return self::record(
			array(
				'kind'       => self::enum( 'audience (anonymous), evaluation (from a role holder) or general (General Evaluator notes on a role or agenda section).', array( 'audience', 'evaluation', 'general' ) ),
				'giver_name' => array( 'type' => 'string' ),
				'about'      => array( 'type' => 'string' ),
				'went_well'  => array( 'type' => 'string' ),
				'potential'  => array( 'type' => 'string' ),
				'body'       => array( 'type' => 'string' ),
				'created_at' => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Simple {deleted: true} output.
	 *
	 * @return array
	 */
	public static function deleted() {
		return self::obj( array( 'deleted' => array( 'type' => 'boolean' ) ) );
	}

	/**
	 * Optional timing override fields for agenda item input.
	 *
	 * @return array
	 */
	public static function timing_override_props() {
		$t = array(
			'type'        => array( 'integer', 'null' ),
			'minimum'     => 0,
			'description' => 'Custom seconds. Set all three to override the role or speech category timing, or all null to clear.',
		);
		return array(
			'green_sec'  => $t,
			'yellow_sec' => $t,
			'red_sec'    => $t,
		);
	}
}
