<?php
/**
 * Abilities for the role holder tools: tools access, timings, Ah-Counter, feedback, reports.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tool abilities.
 */
class TMMT_Abilities_Tools {

	/**
	 * Registers the tool abilities.
	 */
	public static function register() {
		self::register_access();
		self::register_timer();
		self::register_filler();
		self::register_feedback();
	}

	/**
	 * Tools password abilities.
	 */
	private static function register_access() {
		$s      = 'TMMT_Schemas';
		$status = $s::obj(
			array(
				'unlocked'     => array( 'type' => 'boolean' ),
				'password_set' => array( 'type' => 'boolean' ),
			)
		);
		$state  = static function () {
			return array(
				'unlocked'     => TMMT_Auth::has_tools_access(),
				'password_set' => TMMT_Auth::has_password(),
			);
		};

		TMMT_Abilities::add(
			'get-tools-status',
			__( 'Get tools access status', 'tm-meeting-tools' ),
			__( 'Tells whether the current visitor may use the role holder tools.', 'tm-meeting-tools' ),
			$s::input( array() ),
			$status,
			$state,
			'__return_true',
			'read'
		);

		TMMT_Abilities::add(
			'unlock-tools',
			__( 'Unlock tools', 'tm-meeting-tools' ),
			__( 'Checks the shared tools password and sets the tools cookie (14 days). Rate limited.', 'tm-meeting-tools' ),
			$s::input( array( 'password' => $s::str( 'Tools password.', 200 ) ), array( 'password' ) ),
			$status,
			static function ( $input ) use ( $state ) {
				$res = TMMT_Auth::unlock( $input['password'] );
				return is_wp_error( $res ) ? $res : $state();
			},
			'__return_true'
		);

		TMMT_Abilities::add(
			'lock-tools',
			__( 'Lock tools', 'tm-meeting-tools' ),
			__( 'Removes the tools cookie on this device.', 'tm-meeting-tools' ),
			$s::input( array() ),
			$status,
			static function () use ( $state ) {
				TMMT_Auth::lock();
				return $state();
			},
			'__return_true'
		);
	}

	/**
	 * Timekeeper abilities.
	 */
	private static function register_timer() {
		$s     = 'TMMT_Schemas';
		$tools = array( 'TMMT_Auth', 'has_tools_access' );

		TMMT_Abilities::add(
			'record-timing',
			__( 'Record timing', 'tm-meeting-tools' ),
			__( 'Saves how long an agenda item took. Recording again for the same item replaces the earlier time (for corrections).', 'tm-meeting-tools' ),
			$s::input(
				array(
					'agenda_item_id' => $s::int( 'Agenda item ID.', 1 ),
					'duration_sec'   => $s::int( 'Seconds spoken.' ),
				),
				array( 'agenda_item_id', 'duration_sec' )
			),
			$s::timing_row(),
			static function ( $input ) {
				return TMMT_Results::record_timing( (int) $input['agenda_item_id'], (int) $input['duration_sec'] );
			},
			$tools
		);

		TMMT_Abilities::add(
			'clear-timing',
			__( 'Clear timing', 'tm-meeting-tools' ),
			__( 'Deletes the time recorded for an agenda item.', 'tm-meeting-tools' ),
			$s::input( array( 'agenda_item_id' => $s::int( 'Agenda item ID.', 1 ) ), array( 'agenda_item_id' ) ),
			$s::deleted(),
			static function ( $input ) {
				TMMT_Results::clear_timing( (int) $input['agenda_item_id'] );
				return array( 'deleted' => true );
			},
			$tools,
			'delete'
		);

		$meeting_input = $s::input( array( 'meeting_id' => $s::int( 'Meeting ID.', 1 ) ), array( 'meeting_id' ) );

		TMMT_Abilities::add(
			'get-timing-report',
			__( 'Get timing report', 'tm-meeting-tools' ),
			__( 'Every timed agenda item with its time, window and verdict (within, under, over, with 30 s grace).', 'tm-meeting-tools' ),
			$meeting_input,
			$s::timing_report(),
			static function ( $input ) {
				return TMMT_Results::timing_report( (int) $input['meeting_id'] );
			},
			$tools,
			'read'
		);

		TMMT_Abilities::add(
			'complete-meeting',
			__( 'Complete meeting', 'tm-meeting-tools' ),
			__( 'Marks the meeting as completed and returns the timing report.', 'tm-meeting-tools' ),
			$meeting_input,
			$s::timing_report(),
			static function ( $input ) {
				return TMMT_Results::complete_meeting( (int) $input['meeting_id'] );
			},
			$tools
		);
	}

	/**
	 * Ah-Counter abilities.
	 */
	private static function register_filler() {
		$s     = 'TMMT_Schemas';
		$tools = array( 'TMMT_Auth', 'has_tools_access' );

		$count_props = array();
		foreach ( tmmt_filler_words() as $word ) {
			$count_props[ $word ] = $s::int( 'Count.' );
		}

		TMMT_Abilities::add(
			'save-filler-counts',
			__( 'Save Ah-Counter counts', 'tm-meeting-tools' ),
			__( 'Saves filler word counts for one speaker, replacing that speaker\'s earlier counts for this meeting. The meeting\'s word of the day is counted too but left out of the total. Unknown words are ignored; missing words count as 0.', 'tm-meeting-tools' ),
			$s::input(
				array(
					'meeting_id'     => $s::int( 'Meeting ID.', 1 ),
					'person_name'    => $s::str( 'Speaker name.' ),
					'agenda_item_id' => $s::int( 'Agenda item the counts belong to (optional).' ),
					'counts'         => array(
						'type'                 => 'object',
						'properties'           => $count_props,
						// Extra key allowed: the meeting's word of the day. Other unknown words are ignored.
						'additionalProperties' => array( 'type' => 'integer' ),
						'description'          => 'Filler word (or the word of the day) => count.',
					),
					'notes'          => $s::str( 'The Ah-Counter\'s notes on this speaker (optional; left unchanged when missing).', 5000 ),
				),
				array( 'meeting_id', 'person_name', 'counts' )
			),
			$s::filler(),
			static function ( $input ) {
				return TMMT_Results::save_filler_counts( $input );
			},
			$tools
		);

		TMMT_Abilities::add(
			'list-filler-counts',
			__( 'List Ah-Counter counts', 'tm-meeting-tools' ),
			__( 'Lists saved filler word counts for a meeting.', 'tm-meeting-tools' ),
			$s::input( array( 'meeting_id' => $s::int( 'Meeting ID.', 1 ) ), array( 'meeting_id' ) ),
			$s::obj( array( 'filler_counts' => $s::arr( $s::filler() ) ) ),
			static function ( $input ) {
				return array( 'filler_counts' => TMMT_Results::list_filler_counts( array( 'meeting_id' => (int) $input['meeting_id'] ) ) );
			},
			$tools,
			'read'
		);

		TMMT_Abilities::add(
			'delete-filler-counts',
			__( 'Delete Ah-Counter counts', 'tm-meeting-tools' ),
			__( 'Deletes one saved filler count record (to fix a mistake).', 'tm-meeting-tools' ),
			$s::input( array( 'id' => $s::int( 'Record ID.', 1 ) ), array( 'id' ) ),
			$s::deleted(),
			static function ( $input ) {
				TMMT_Results::delete_filler( (int) $input['id'] );
				return array( 'deleted' => true );
			},
			$tools,
			'delete'
		);
	}

	/**
	 * Feedback and report abilities.
	 */
	private static function register_feedback() {
		$s     = 'TMMT_Schemas';
		$admin = array( 'TMMT_Auth', 'can_manage' );

		TMMT_Abilities::add(
			'submit-feedback',
			__( 'Submit feedback', 'tm-meeting-tools' ),
			__( 'Saves typed feedback for a person on the agenda. kind "audience" is public and anonymous (needs the form nonce unless an admin calls it). kind "evaluation" is for role holders (tools password) and can name the giver.', 'tm-meeting-tools' ),
			$s::input(
				array(
					'meeting_id'     => $s::int( 'Meeting ID.', 1 ),
					'kind'           => $s::enum( 'audience (default) or evaluation.', array( 'audience', 'evaluation' ) ),
					'agenda_item_id' => $s::int( 'Agenda item the feedback is about (optional).' ),
					'person_name'    => $s::str( 'Who the feedback is for. Taken from the agenda item when empty.' ),
					'giver_name'     => $s::str( 'Who gives the feedback (evaluation only; ignored for audience).' ),
					'about'          => $s::str( 'What it is about, for example "Speech: My first job" or "Table topic".' ),
					'went_well'      => $s::str( 'What did they do great?', 5000 ),
					'potential'      => $s::str( 'Where do they have potential?', 5000 ),
					'body'           => $s::str( 'Free text feedback.', 5000 ),
					'nonce'          => $s::str( 'Audience form nonce.', 64 ),
					'website'        => $s::str( 'Honeypot. Leave empty.', 500 ),
				),
				array( 'meeting_id' )
			),
			$s::obj(
				array(
					'saved' => array( 'type' => 'boolean' ),
					'id'    => array( 'type' => array( 'integer', 'null' ) ),
				)
			),
			array( __CLASS__, 'execute_submit_feedback' ),
			static function ( $input ) {
				if ( isset( $input['kind'] ) && 'evaluation' === $input['kind'] ) {
					return TMMT_Auth::has_tools_access();
				}
				if ( TMMT_Auth::can_manage() ) {
					return true;
				}
				return isset( $input['nonce'] ) && false !== wp_verify_nonce( $input['nonce'], 'tmmt_audience' );
			}
		);

		$tools = array( 'TMMT_Auth', 'has_tools_access' );

		TMMT_Abilities::add(
			'save-general-evaluation',
			__( 'Save General Evaluator notes', 'tm-meeting-tools' ),
			__( 'Saves the General Evaluator\'s notes on one role, evaluator or agenda section, replacing earlier notes on it for this meeting. Empty notes remove them.', 'tm-meeting-tools' ),
			$s::input(
				array(
					'meeting_id'  => $s::int( 'Meeting ID.', 1 ),
					'topic'       => $s::enum( 'What the notes are about.', array_keys( tmmt_ge_topics() ) ),
					'person_name' => $s::str( 'Who holds the role (empty for a section).' ),
					'body'        => $s::str( 'The notes.', 5000 ),
					'agenda_item_id' => $s::int( 'The evaluator\'s agenda item (required for topic evaluator).' ),
				),
				array( 'meeting_id', 'topic' )
			),
			$s::obj( array( 'feedback' => array( 'oneOf' => array( $s::feedback(), array( 'type' => 'null' ) ) ) ) ),
			static function ( $input ) {
				$saved = TMMT_Results::save_ge_notes( $input );
				return is_wp_error( $saved ) ? $saved : array( 'feedback' => $saved );
			},
			$tools
		);

		TMMT_Abilities::add(
			'list-general-evaluation',
			__( 'List General Evaluator notes', 'tm-meeting-tools' ),
			__( 'Lists the General Evaluator\'s notes for a meeting.', 'tm-meeting-tools' ),
			$s::input( array( 'meeting_id' => $s::int( 'Meeting ID.', 1 ) ), array( 'meeting_id' ) ),
			$s::obj( array( 'feedback' => $s::arr( $s::feedback() ) ) ),
			static function ( $input ) {
				return array( 'feedback' => TMMT_Results::list_feedback( array( 'meeting_id' => (int) $input['meeting_id'], 'kind' => 'general' ) ) );
			},
			$tools,
			'read'
		);

		$filter = array(
			'meeting_id'  => $s::int( 'Only this meeting.' ),
			'person_name' => $s::str( 'Only this person (exact name).' ),
		);

		TMMT_Abilities::add(
			'list-feedback',
			__( 'List feedback', 'tm-meeting-tools' ),
			__( 'Lists audience and evaluation feedback, optionally for one meeting and/or person. Admins only.', 'tm-meeting-tools' ),
			$s::input( $filter + array( 'kind' => $s::enum( 'Only this kind.', array( 'audience', 'evaluation', 'general' ) ) ) ),
			$s::obj( array( 'feedback' => $s::arr( $s::feedback() ) ) ),
			static function ( $input ) {
				return array( 'feedback' => TMMT_Results::list_feedback( $input ) );
			},
			$admin,
			'read'
		);

		TMMT_Abilities::add(
			'delete-feedback',
			__( 'Delete feedback', 'tm-meeting-tools' ),
			__( 'Deletes one feedback entry (for example spam). Admins only.', 'tm-meeting-tools' ),
			$s::input( array( 'id' => $s::int( 'Feedback ID.', 1 ) ), array( 'id' ) ),
			$s::deleted(),
			static function ( $input ) {
				TMMT_Results::delete_feedback( (int) $input['id'] );
				return array( 'deleted' => true );
			},
			$admin,
			'delete'
		);

		TMMT_Abilities::add(
			'get-results',
			__( 'Get results', 'tm-meeting-tools' ),
			__( 'All timings, Ah-Counter counts and feedback for a meeting and/or a person, plus the list of known people. Admins only.', 'tm-meeting-tools' ),
			$s::input( $filter ),
			$s::obj(
				array(
					'timings'       => $s::arr( $s::timing() ),
					'filler_counts' => $s::arr( $s::filler() ),
					'feedback'      => $s::arr( $s::feedback() ),
					'people'        => $s::arr( array( 'type' => 'string' ) ),
				)
			),
			static function ( $input ) {
				return TMMT_Results::full_report( $input ) + array( 'people' => TMMT_Results::people() );
			},
			$admin,
			'read'
		);
	}

	/**
	 * Executes submit-feedback with honeypot and rate limiting.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_submit_feedback( $input ) {
		$kind = isset( $input['kind'] ) ? $input['kind'] : 'audience';
		if ( 'audience' === $kind && ! TMMT_Auth::can_manage() ) {
			// Bots fill every field. Pretend it worked so they do not retry.
			if ( ! empty( $input['website'] ) ) {
				return array(
					'saved' => true,
					'id'    => null,
				);
			}
			// Generous on purpose: the whole audience often shares the venue's single Wi-Fi IP.
			$limit = (int) apply_filters( 'tmmt_feedback_rate_limit', 40 );
			if ( TMMT_Auth::rate_limited( 'feedback', $limit, 5 * MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'tmmt_rate_limited', __( 'Too many submissions. Please wait a few minutes.', 'tm-meeting-tools' ), array( 'status' => 429 ) );
			}
		}
		$res = TMMT_Results::submit_feedback( $input );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'saved' => true,
			'id'    => $res['id'],
		);
	}
}
