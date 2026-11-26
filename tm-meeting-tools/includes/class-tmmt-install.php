<?php
/**
 * Table creation and default role seeding.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
class TMMT_Install {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_roles();
		update_option( 'tmmt_db_version', TMMT_DB_VERSION );
	}

	/**
	 * Re-runs dbDelta when the schema version changes (plugin updated by file upload, no activation).
	 */
	public static function maybe_upgrade() {
		$from = get_option( 'tmmt_db_version' );
		if ( $from !== TMMT_DB_VERSION ) {
			self::activate();
			if ( '1' === $from ) {
				self::untime_default_roles();
			}
			if ( $from && (int) $from < 6 ) {
				self::stamp_existing_members();
				self::time_meet_and_greet();
			}
		}
	}

	/**
	 * Version 2 added the "timed" flag (default on). Turn it off for the default roles that the
	 * timekeeper does not time, matched by their default names.
	 */
	private static function untime_default_roles() {
		global $wpdb;
		foreach ( self::default_roles() as $r ) {
			if ( ! $r[5] ) {
				$wpdb->update( tmmt_table( 'role_types' ), array( 'timed' => 0 ), array( 'name' => $r[0] ), array( '%d' ), array( '%s' ) );
			}
		}
	}

	/**
	 * Version 6 gave Meet and Greet its 15 minutes: the meeting start (from tmclub.eu) is when Meet
	 * and Greet begins, so with 0 seconds every later start time on the agenda was 15 minutes early.
	 * Only touches the untouched default (0 seconds).
	 */
	private static function time_meet_and_greet() {
		global $wpdb;
		$wpdb->update(
			tmmt_table( 'role_types' ),
			array(
				'green_sec'  => 900,
				'yellow_sec' => 900,
				'red_sec'    => 900,
			),
			array(
				'name'    => self::default_roles()[0][0],
				'red_sec' => 0,
			),
			array( '%d', '%d', '%d' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Version 6 added last_synced_at; members not synced within the configured period are pruned,
	 * rows with NULL never are. Stamp the existing members with "now" so they get the same grace
	 * period as everyone else and are removed if the club manager sync never sees them.
	 */
	private static function stamp_existing_members() {
		global $wpdb;
		$table = tmmt_table( 'members' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET last_synced_at = %s WHERE last_synced_at IS NULL", current_time( 'mysql' ) ) );
	}

	/**
	 * Creates or updates all tables.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		$sql = array();

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'role_types' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			category varchar(20) NOT NULL DEFAULT 'role',
			green_sec int(11) NOT NULL DEFAULT 0,
			yellow_sec int(11) NOT NULL DEFAULT 0,
			red_sec int(11) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			timed tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'members' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			tm_id varchar(30) NOT NULL DEFAULT '',
			last_synced_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY tm_id (tm_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'meetings' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			meeting_date date NOT NULL,
			start_time time NOT NULL DEFAULT '18:00:00',
			theme varchar(190) NOT NULL DEFAULT '',
			word_of_day varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'planned',
			completed_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY meeting_date (meeting_date)
		) $c;";

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'agenda_items' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			meeting_id bigint(20) unsigned NOT NULL,
			role_type_id bigint(20) unsigned NOT NULL,
			person_name varchar(190) NOT NULL DEFAULT '',
			speech_title varchar(190) NOT NULL DEFAULT '',
			speech_category varchar(30) NOT NULL DEFAULT '',
			green_sec int(11) NULL DEFAULT NULL,
			yellow_sec int(11) NULL DEFAULT NULL,
			red_sec int(11) NULL DEFAULT NULL,
			position int(11) NOT NULL DEFAULT 0,
			is_adhoc tinyint(1) NOT NULL DEFAULT 0,
			is_guest tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY meeting_id (meeting_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'timings' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			meeting_id bigint(20) unsigned NOT NULL,
			agenda_item_id bigint(20) unsigned NULL DEFAULT NULL,
			person_name varchar(190) NOT NULL DEFAULT '',
			role_label varchar(190) NOT NULL DEFAULT '',
			green_sec int(11) NOT NULL DEFAULT 0,
			yellow_sec int(11) NOT NULL DEFAULT 0,
			red_sec int(11) NOT NULL DEFAULT 0,
			duration_sec int(11) NOT NULL DEFAULT 0,
			recorded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY meeting_id (meeting_id),
			KEY agenda_item_id (agenda_item_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'filler_counts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			meeting_id bigint(20) unsigned NOT NULL,
			agenda_item_id bigint(20) unsigned NULL DEFAULT NULL,
			person_name varchar(190) NOT NULL DEFAULT '',
			counts text NOT NULL,
			total int(11) NOT NULL DEFAULT 0,
			notes text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY meeting_id (meeting_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . tmmt_table( 'feedback' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			meeting_id bigint(20) unsigned NOT NULL,
			agenda_item_id bigint(20) unsigned NULL DEFAULT NULL,
			person_name varchar(190) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'audience',
			giver_name varchar(190) NOT NULL DEFAULT '',
			about varchar(190) NOT NULL DEFAULT '',
			went_well text NOT NULL,
			potential text NOT NULL,
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY meeting_id (meeting_id),
			KEY person_name (person_name)
		) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Default role list from the club agenda. Times in seconds.
	 *
	 * @return array<int,array{0:string,1:string,2:int,3:int,4:int,5:bool}> 5 = timed by the timekeeper.
	 */
	public static function default_roles() {
		return array(
			array( __( 'Meet and Greet', 'tm-meeting-tools' ), 'other', 900, 900, 900, false ),
			array( __( "President's Introduction", 'tm-meeting-tools' ), 'role', 360, 420, 480, true ),
			array( __( 'Toastmaster', 'tm-meeting-tools' ), 'role', 180, 210, 240, true ),
			array( __( 'General Evaluator Introduction', 'tm-meeting-tools' ), 'role', 60, 90, 120, true ),
			array( __( 'Timekeeper Introduction', 'tm-meeting-tools' ), 'role', 60, 90, 120, false ),
			array( __( 'Grammarian and Ah-Counter Introduction', 'tm-meeting-tools' ), 'role', 60, 90, 120, true ),
			array( __( 'Speaker', 'tm-meeting-tools' ), 'speech', 300, 360, 420, true ),
			array( __( 'Break', 'tm-meeting-tools' ), 'break', 540, 570, 600, true ),
			array( __( 'Table Topics Master', 'tm-meeting-tools' ), 'table_topic', 120, 150, 180, false ),
			array( __( 'Table Topic Speaker', 'tm-meeting-tools' ), 'table_topic', 60, 90, 120, true ),
			array( __( 'Evaluator', 'tm-meeting-tools' ), 'role', 120, 150, 180, true ),
			array( __( 'Grammarian and Ah-Counter Report', 'tm-meeting-tools' ), 'role', 120, 150, 180, true ),
			array( __( 'Timekeeper Report', 'tm-meeting-tools' ), 'role', 60, 90, 120, false ),
			array( __( 'General Evaluator', 'tm-meeting-tools' ), 'role', 180, 210, 240, false ),
		);
	}

	/**
	 * Seeds the default roles once (only when the table is empty).
	 */
	public static function seed_roles() {
		global $wpdb;
		$table = tmmt_table( 'role_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count > 0 ) {
			return;
		}
		foreach ( self::default_roles() as $i => $r ) {
			$wpdb->insert(
				$table,
				array(
					'name'       => $r[0],
					'category'   => $r[1],
					'green_sec'  => $r[2],
					'yellow_sec' => $r[3],
					'red_sec'    => $r[4],
					'sort_order' => ( $i + 1 ) * 10,
					'timed'      => $r[5] ? 1 : 0,
				),
				array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
			);
		}
	}

	/**
	 * Standard agenda every new meeting starts from: role names in club order.
	 * Indexes point into default_roles() so translated names stay in sync: update them whenever
	 * a role is added to or removed from default_roles().
	 *
	 * @return string[]
	 */
	public static function standard_agenda() {
		$roles = self::default_roles();
		// 0-5 intro roles, 6 Speaker x4, 7 Break, 8 Table Topics Master, 10 Evaluator x4, 11-13 reports and General Evaluator.
		$order = array( 0, 1, 2, 3, 4, 5, 6, 6, 6, 6, 7, 8, 10, 10, 10, 10, 11, 12, 13 );
		return array_map(
			static function ( $i ) use ( $roles ) {
				return $roles[ $i ][0];
			},
			$order
		);
	}
}
