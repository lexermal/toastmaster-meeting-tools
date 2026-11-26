<?php
/**
 * Abilities API registration: meetings, agenda, role types and members.
 * Tool abilities (timings, Ah-Counter, feedback, access) live in class-tmmt-abilities-tools.php.
 *
 * Every front-end tool calls these abilities through /wp-abilities/v1/abilities/{name}/run,
 * so the permission callbacks here are the single place access is decided.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tmmt-abilities-tools.php';

/**
 * Ability registration.
 */
class TMMT_Abilities {

	const NS       = 'tm-meeting-tools';
	const CATEGORY = 'tm-meeting-tools';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Toastmasters meeting tools', 'tm-meeting-tools' ),
				'description' => __( 'Meetings, agendas, timekeeping, Ah-Counter and feedback for a Toastmasters club.', 'tm-meeting-tools' ),
			)
		);
	}

	/**
	 * Registers one ability with the plugin defaults.
	 *
	 * @param string   $name        Short name (without namespace).
	 * @param string   $label       Label.
	 * @param string   $description Description.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback (receives input).
	 * @param callable $permission  Permission callback (receives input).
	 * @param string   $kind        read|write|delete (sets annotations, which set the REST method).
	 */
	public static function add( $name, $label, $description, $input, $output, $execute, $permission, $kind = 'write' ) {
		$annotations = array(
			'readonly'    => 'read' === $kind,
			'destructive' => 'delete' === $kind,
			'idempotent'  => 'read' === $kind || 'delete' === $kind,
		);
		wp_register_ability(
			self::NS . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => $annotations,
				),
			)
		);
	}

	/**
	 * Registers all abilities.
	 */
	public static function register() {
		self::register_meetings();
		self::register_agenda();
		self::register_roles();
		self::register_members();
		TMMT_Abilities_Tools::register();
	}

	/**
	 * Meeting abilities.
	 */
	private static function register_meetings() {
		$s      = 'TMMT_Schemas';
		$public = '__return_true';
		$admin  = array( 'TMMT_Auth', 'can_manage' );

		$meeting_fields = array(
			'date'        => array(
				'type'        => 'string',
				'pattern'     => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
				'description' => 'Meeting date, YYYY-MM-DD.',
			),
			'time'        => array(
				'type'        => 'string',
				'pattern'     => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
				'description' => 'Start time HH:MM. Defaults to 18:00.',
			),
			'theme'       => $s::str( 'Meeting theme.' ),
			'word_of_day' => $s::str( 'Word of the Day.' ),
		);

		self::add(
			'list-meetings',
			__( 'List meetings', 'tm-meeting-tools' ),
			__( 'Lists meetings, newest first (upcoming: soonest first).', 'tm-meeting-tools' ),
			$s::input(
				array(
					'when'   => $s::enum( 'upcoming, past or all (default).', array( 'upcoming', 'past', 'all' ) ),
					'status' => $s::enum( 'Only meetings with this status.', TMMT_Meetings::STATUSES ),
					'limit'  => $s::int( 'Max results (1 to 200, default 50).', 1 ),
				)
			),
			$s::obj( array( 'meetings' => $s::arr( $s::meeting() ) ) ),
			static function ( $input ) {
				return array( 'meetings' => TMMT_Meetings::list_meetings( $input ) );
			},
			$public,
			'read'
		);

		self::add(
			'get-meeting',
			__( 'Get meeting', 'tm-meeting-tools' ),
			__( 'Returns a meeting with its full agenda (roles, people, resolved timing). Without meeting_id: the next meeting, or the latest one.', 'tm-meeting-tools' ),
			$s::input( array( 'meeting_id' => $s::int( 'Meeting ID. 0 or omitted means the current meeting.' ) ) ),
			$s::meeting(),
			static function ( $input ) {
				$id      = empty( $input['meeting_id'] ) ? TMMT_Meetings::current_id() : (int) $input['meeting_id'];
				$meeting = $id ? TMMT_Meetings::get( $id ) : null;
				return $meeting ? $meeting : new WP_Error( 'tmmt_not_found', __( 'Meeting not found.', 'tm-meeting-tools' ), array( 'status' => 404 ) );
			},
			$public,
			'read'
		);

		self::add(
			'create-meeting',
			__( 'Create meeting', 'tm-meeting-tools' ),
			__( 'Creates a meeting. template "standard" fills the agenda with the club\'s standard role order (people left empty).', 'tm-meeting-tools' ),
			$s::input(
				$meeting_fields + array( 'template' => $s::enum( 'standard or empty (default).', array( 'standard', 'empty' ) ) ),
				array( 'date' )
			),
			$s::meeting(),
			static function ( $input ) {
				return TMMT_Meetings::create( $input );
			},
			$admin
		);

		self::add(
			'update-meeting',
			__( 'Update meeting', 'tm-meeting-tools' ),
			__( 'Changes date, time, theme, Word of the Day or status of a meeting. Only given fields change.', 'tm-meeting-tools' ),
			$s::input(
				array( 'meeting_id' => $s::int( 'Meeting ID.', 1 ) ) + $meeting_fields + array( 'status' => $s::enum( 'Status.', TMMT_Meetings::STATUSES ) ),
				array( 'meeting_id' )
			),
			$s::meeting(),
			static function ( $input ) {
				$id = (int) $input['meeting_id'];
				unset( $input['meeting_id'] );
				return TMMT_Meetings::update( $id, $input );
			},
			static function ( $input ) {
				if ( TMMT_Auth::can_manage() ) {
					return true;
				}
				// The Word of the Day is often only announced at the meeting, so the Ah-Counter may set it.
				$keys = array_keys( (array) $input );
				return TMMT_Auth::has_tools_access() && ! array_diff( $keys, array( 'meeting_id', 'word_of_day' ) ) && in_array( 'word_of_day', $keys, true );
			}
		);

		self::add(
			'delete-meeting',
			__( 'Delete meeting', 'tm-meeting-tools' ),
			__( 'Deletes a meeting with its agenda, timings, Ah-Counter results and feedback.', 'tm-meeting-tools' ),
			$s::input( array( 'meeting_id' => $s::int( 'Meeting ID.', 1 ) ), array( 'meeting_id' ) ),
			$s::deleted(),
			static function ( $input ) {
				$res = TMMT_Meetings::delete( (int) $input['meeting_id'] );
				return is_wp_error( $res ) ? $res : array( 'deleted' => true );
			},
			$admin,
			'delete'
		);
	}

	/**
	 * Agenda abilities.
	 */
	private static function register_agenda() {
		$s     = 'TMMT_Schemas';
		$admin = array( 'TMMT_Auth', 'can_manage' );

		$item_fields = array(
			'role_type_id'    => $s::int( 'Role type ID (see list-role-types).', 1 ),
			'person_name'     => $s::str( 'Who holds the role (free text, not a WP user).' ),
			'speech_title'    => $s::str( 'Speech title (speeches only).' ),
			'speech_category' => $s::enum( 'Speech category that sets the timing (speeches only). Empty to use the role default.', array_merge( array( '' ), array_keys( tmmt_speech_categories() ) ) ),
			'is_guest'        => array(
				'type'        => 'boolean',
				'description' => 'A guest: timed but not rated (no time verdict, not counted by the Ah-Counter).',
			),
		) + $s::timing_override_props();

		self::add(
			'add-agenda-item',
			__( 'Add agenda item', 'tm-meeting-tools' ),
			__( 'Adds a role to a meeting agenda, at the end or right after after_item_id. Role holders with the tools password may only add table topic speakers this way.', 'tm-meeting-tools' ),
			$s::input(
				array( 'meeting_id' => $s::int( 'Meeting ID.', 1 ) ) + $item_fields + array(
					'after_item_id' => $s::int( 'Insert right after this agenda item.' ),
				),
				array( 'meeting_id', 'role_type_id' )
			),
			$s::item(),
			static function ( $input ) {
				$id = (int) $input['meeting_id'];
				unset( $input['meeting_id'] );
				// Items added from the timer (not by an admin) are marked as added on the day.
				$input['is_adhoc'] = ! TMMT_Auth::can_manage();
				return TMMT_Meetings::add_item( $id, $input );
			},
			static function ( $input ) {
				if ( TMMT_Auth::can_manage() ) {
					return true;
				}
				$role = TMMT_Roles::get( isset( $input['role_type_id'] ) ? (int) $input['role_type_id'] : 0 );
				return TMMT_Auth::has_tools_access() && $role && 'table_topic' === $role['category'];
			}
		);

		self::add(
			'update-agenda-item',
			__( 'Update agenda item', 'tm-meeting-tools' ),
			__( 'Changes role, person, speech title, speech category or custom timing of an agenda item. Only given fields change.', 'tm-meeting-tools' ),
			$s::input( array( 'item_id' => $s::int( 'Agenda item ID.', 1 ) ) + $item_fields, array( 'item_id' ) ),
			$s::item(),
			static function ( $input ) {
				$id = (int) $input['item_id'];
				unset( $input['item_id'] );
				return TMMT_Meetings::update_item( $id, $input );
			},
			static function ( $input ) {
				if ( TMMT_Auth::can_manage() ) {
					return true;
				}
				// Timekeepers may mark any table topic speaker as a guest, and (re)name the ones added on the day.
				$input = (array) $input;
				$item  = TMMT_Meetings::get_item( isset( $input['item_id'] ) ? (int) $input['item_id'] : 0 );
				if ( ! TMMT_Auth::has_tools_access() || ! $item || 'table_topic' !== $item['category'] ) {
					return false;
				}
				$fields = array_diff( array_keys( $input ), array( 'item_id', 'is_guest' ) );
				return ! $fields || ( $item['is_adhoc'] && array( 'person_name' ) === array_values( $fields ) );
			}
		);

		self::add(
			'remove-agenda-item',
			__( 'Remove agenda item', 'tm-meeting-tools' ),
			__( 'Removes an item from the agenda. Results already recorded for it are kept under the person\'s name.', 'tm-meeting-tools' ),
			$s::input( array( 'item_id' => $s::int( 'Agenda item ID.', 1 ) ), array( 'item_id' ) ),
			$s::deleted(),
			static function ( $input ) {
				$res = TMMT_Meetings::remove_item( (int) $input['item_id'] );
				return is_wp_error( $res ) ? $res : array( 'deleted' => true );
			},
			$admin,
			'delete'
		);

		self::add(
			'reorder-agenda-items',
			__( 'Reorder agenda', 'tm-meeting-tools' ),
			__( 'Sets the agenda order. item_ids must list every item of the meeting exactly once.', 'tm-meeting-tools' ),
			$s::input(
				array(
					'meeting_id' => $s::int( 'Meeting ID.', 1 ),
					'item_ids'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Agenda item IDs in the new order.',
					),
				),
				array( 'meeting_id', 'item_ids' )
			),
			$s::obj( array( 'agenda' => $s::arr( $s::item() ) ) ),
			static function ( $input ) {
				$res = TMMT_Meetings::reorder( (int) $input['meeting_id'], $input['item_ids'] );
				return is_wp_error( $res ) ? $res : array( 'agenda' => $res );
			},
			$admin
		);
	}

	/**
	 * Role type abilities.
	 */
	private static function register_roles() {
		$s     = 'TMMT_Schemas';
		$admin = array( 'TMMT_Auth', 'can_manage' );

		$role_fields = array(
			'name'       => $s::str( 'Role name.' ),
			'category'   => $s::enum( 'Category.', array_keys( tmmt_role_categories() ) ),
			'green_sec'  => $s::int( 'Green signal (minimum) in seconds. 0 for untimed roles.' ),
			'yellow_sec' => $s::int( 'Yellow signal in seconds.' ),
			'red_sec'    => $s::int( 'Red signal (maximum) in seconds.' ),
			'sort_order' => $s::int( 'Sort order in lists.' ),
			'timed'      => array(
				'type'        => 'boolean',
				'description' => 'Whether the timekeeper times this role. Untimed roles keep their duration for the agenda start times.',
			),
		);

		$speech_cat = $s::obj(
			array(
				'key'    => array( 'type' => 'string' ),
				'label'  => array( 'type' => 'string' ),
				'green'  => array( 'type' => 'integer' ),
				'yellow' => array( 'type' => 'integer' ),
				'red'    => array( 'type' => 'integer' ),
			)
		);

		self::add(
			'list-role-types',
			__( 'List role types', 'tm-meeting-tools' ),
			__( 'Lists role types with default timing, plus the role categories, speech categories and Ah-Counter filler words.', 'tm-meeting-tools' ),
			$s::input( array() ),
			$s::obj(
				array(
					'role_types'        => $s::arr( $s::role() ),
					'categories'        => $s::arr( array( 'type' => 'string' ) ),
					'speech_categories' => $s::arr( $speech_cat ),
					'filler_words'      => $s::arr( array( 'type' => 'string' ) ),
				)
			),
			static function () {
				$cats = array();
				foreach ( tmmt_speech_categories() as $key => $c ) {
					$cats[] = array( 'key' => (string) $key ) + $c;
				}
				return array(
					'role_types'        => TMMT_Roles::all(),
					'categories'        => array_keys( tmmt_role_categories() ),
					'speech_categories' => $cats,
					'filler_words'      => tmmt_filler_words(),
				);
			},
			'__return_true',
			'read'
		);

		self::add(
			'create-role-type',
			__( 'Create role type', 'tm-meeting-tools' ),
			__( 'Adds a role type with default green, yellow and red times.', 'tm-meeting-tools' ),
			$s::input( $role_fields, array( 'name', 'category' ) ),
			$s::role(),
			static function ( $input ) {
				return TMMT_Roles::create( $input );
			},
			$admin
		);

		self::add(
			'update-role-type',
			__( 'Update role type', 'tm-meeting-tools' ),
			__( 'Changes a role type. Only given fields change.', 'tm-meeting-tools' ),
			$s::input( array( 'role_type_id' => $s::int( 'Role type ID.', 1 ) ) + $role_fields, array( 'role_type_id' ) ),
			$s::role(),
			static function ( $input ) {
				$id = (int) $input['role_type_id'];
				unset( $input['role_type_id'] );
				return TMMT_Roles::update( $id, $input );
			},
			$admin
		);

		self::add(
			'delete-role-type',
			__( 'Delete role type', 'tm-meeting-tools' ),
			__( 'Deletes a role type that no agenda item uses.', 'tm-meeting-tools' ),
			$s::input( array( 'role_type_id' => $s::int( 'Role type ID.', 1 ) ), array( 'role_type_id' ) ),
			$s::deleted(),
			static function ( $input ) {
				$res = TMMT_Roles::delete( (int) $input['role_type_id'] );
				return is_wp_error( $res ) ? $res : array( 'deleted' => true );
			},
			$admin,
			'delete'
		);
	}

	/**
	 * Member abilities, used by the club manager to sync members from tmclub.eu.
	 */
	private static function register_members() {
		$s     = 'TMMT_Schemas';
		$admin = array( 'TMMT_Auth', 'can_manage' );

		self::add(
			'list-members',
			__( 'List members', 'tm-meeting-tools' ),
			__( 'Lists the club members by name, with their Toastmasters ID, tmclub.eu user ID and when they were last synced.', 'tm-meeting-tools' ),
			$s::input( array() ),
			$s::obj( array( 'members' => $s::arr( $s::member() ) ) ),
			static function () {
				return array( 'members' => TMMT_Members::all() );
			},
			$admin,
			'read'
		);

		self::add(
			'sync-members',
			__( 'Sync members', 'tm-meeting-tools' ),
			__( 'Adds or updates members seen in a meeting on tmclub.eu and marks them as synced now. Matches by tm_id (the tmclub.eu user ID), else by name among members without one (which links them), else adds them. Members not synced for the days set in Settings are removed automatically.', 'tm-meeting-tools' ),
			$s::input(
				array(
					'members' => $s::arr(
						array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'tm_id' => $s::str( 'tmclub.eu (easy-Speak) user ID.', 30 ),
								'name'  => $s::str( 'Name as shown on the agenda.' ),
							),
							'required'             => array( 'tm_id', 'name' ),
						)
					),
				),
				array( 'members' )
			),
			$s::obj(
				array(
					'created' => array( 'type' => 'integer' ),
					'updated' => array( 'type' => 'integer' ),
					'members' => $s::arr( $s::member() ),
				)
			),
			static function ( $input ) {
				return TMMT_Members::sync( $input['members'] );
			},
			$admin
		);
	}
}
