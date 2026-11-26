<?php
/**
 * Blocks, shortcodes and front-end assets.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tmmt-report-view.php';
require_once __DIR__ . '/class-tmmt-meeting-report.php';

/**
 * Front end.
 */
class TMMT_Frontend {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers assets, blocks and shortcodes.
	 */
	public static function register() {
		// Version = plugin version + file time, so phones don't keep a stale cached copy after an update.
		$v = fn( $file ) => TMMT_VERSION . '.' . (int) filemtime( TMMT_DIR . $file );
		wp_register_style( 'tmmt-front', TMMT_URL . 'assets/css/front.css', array(), $v( 'assets/css/front.css' ) );
		wp_register_script( 'tmmt-core', TMMT_URL . 'assets/js/core.js', array( 'wp-i18n' ), $v( 'assets/js/core.js' ), true );
		foreach ( array( 'timer', 'ah-counter', 'feedback', 'general-evaluator' ) as $tool ) {
			wp_register_script( 'tmmt-' . $tool, TMMT_URL . 'assets/js/' . $tool . '.js', array( 'tmmt-core' ), $v( 'assets/js/' . $tool . '.js' ), true );
		}
		wp_register_script( 'tmmt-app', TMMT_URL . 'assets/js/app.js', array( 'tmmt-timer', 'tmmt-ah-counter', 'tmmt-feedback', 'tmmt-general-evaluator' ), $v( 'assets/js/app.js' ), true );
		wp_set_script_translations( 'tmmt-core', 'tm-meeting-tools', TMMT_DIR . 'languages' );

		wp_register_script(
			'tmmt-blocks-editor',
			TMMT_URL . 'assets/js/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			$v( 'assets/js/editor.js' ),
			true
		);
		wp_set_script_translations( 'tmmt-blocks-editor', 'tm-meeting-tools', TMMT_DIR . 'languages' );

		register_block_type( TMMT_DIR . 'blocks/meeting', array( 'render_callback' => array( __CLASS__, 'render_meeting_block' ) ) );
		register_block_type( TMMT_DIR . 'blocks/audience-feedback', array( 'render_callback' => array( __CLASS__, 'render_audience_block' ) ) );
		register_block_type( TMMT_DIR . 'blocks/report', array( 'render_callback' => array( __CLASS__, 'render_report_block' ) ) );

		add_shortcode( 'tm_meeting', array( __CLASS__, 'shortcode_meeting' ) );
		add_shortcode( 'tm_feedback_form', array( __CLASS__, 'shortcode_audience' ) );
		add_shortcode( 'tm_meeting_report', array( __CLASS__, 'shortcode_report' ) );
		add_shortcode( 'tm_next_meeting', array( __CLASS__, 'shortcode_next_meeting' ) );
	}

	/**
	 * Enqueues the front-end app and its config.
	 */
	private static function enqueue() {
		static $done = false;
		wp_enqueue_style( 'tmmt-front' );
		wp_enqueue_script( 'tmmt-app' );
		if ( $done ) {
			return;
		}
		$done   = true;
		$config = array(
			'abilitiesUrl'  => esc_url_raw( rest_url( 'wp-abilities/v1/abilities/' ) ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'graceSec'      => TMMT_GRACE_SEC,
			'fillerWords'   => tmmt_filler_words(),
			'geTopics'      => tmmt_ge_topics(),
			// Admins always have tool access, so the "Lock" control is hidden for them.
			'isAdmin'       => TMMT_Auth::can_manage(),
		);
		wp_add_inline_script( 'tmmt-core', 'window.TMMT_CONFIG = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Resolves which meeting to show: ?tm_meeting=ID, then the attribute, then the current meeting.
	 *
	 * @param int $attr_id Meeting ID from block/shortcode (0 = current).
	 * @return array|null
	 */
	private static function resolve_meeting( $attr_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
		$id = isset( $_GET['tm_meeting'] ) ? absint( $_GET['tm_meeting'] ) : 0;
		if ( ! $id ) {
			$id = (int) $attr_id;
		}
		if ( ! $id ) {
			$id = TMMT_Meetings::current_id();
		}
		return $id ? TMMT_Meetings::get( $id ) : null;
	}

	/**
	 * Wrapper classes. Colours always follow the site theme.
	 *
	 * @param string $extra Extra class.
	 * @return string
	 */
	private static function root_class( $extra ) {
		return 'tmmt ' . $extra;
	}

	/**
	 * Block render: meeting overview with tool tabs.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_meeting_block( $attrs ) {
		$meeting = self::resolve_meeting( isset( $attrs['meetingId'] ) ? $attrs['meetingId'] : 0 );
		self::enqueue();
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes( array( 'class' => self::root_class( 'tmmt-meeting' ) ) )
			: 'class="' . esc_attr( self::root_class( 'tmmt-meeting' ) ) . '"';
		if ( ! $meeting ) {
			return '<div ' . $wrapper . '><p class="tmmt-empty">' . esc_html__( 'No meeting is planned yet.', 'tm-meeting-tools' ) . '</p></div>';
		}
		ob_start();
		include TMMT_DIR . 'includes/views/meeting.php';
		return ob_get_clean();
	}

	/**
	 * Block render: audience feedback form on its own.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_audience_block( $attrs ) {
		$meeting = self::resolve_meeting( isset( $attrs['meetingId'] ) ? $attrs['meetingId'] : 0 );
		self::enqueue();
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes( array( 'class' => self::root_class( 'tmmt-audience' ) ) )
			: 'class="' . esc_attr( self::root_class( 'tmmt-audience' ) ) . '"';
		if ( ! $meeting ) {
			return '<div ' . $wrapper . '><p class="tmmt-empty">' . esc_html__( 'No meeting is planned yet.', 'tm-meeting-tools' ) . '</p></div>';
		}
		return '<div ' . $wrapper . ' data-tmmt-meeting="' . esc_attr( $meeting['id'] ) . '">' . self::audience_form( $meeting ) . '</div>';
	}

	/**
	 * Block render: results report. Only shown to admins, or on a page protected with a WordPress page password.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_report_block( $attrs ) {
		unset( $attrs );
		$post    = get_post();
		$allowed = TMMT_Auth::can_manage() || ( $post && '' !== $post->post_password && ! post_password_required( $post ) );
		if ( ! $allowed ) {
			return '';
		}
		wp_enqueue_style( 'tmmt-front' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filter = array(
			'meeting_id'  => isset( $_GET['tm_meeting'] ) ? absint( $_GET['tm_meeting'] ) : 0,
			'person_name' => isset( $_GET['tm_person'] ) ? tmmt_clean_line( wp_unslash( $_GET['tm_person'] ) ) : '',
		);
		// phpcs:enable
		return '<div class="' . esc_attr( self::root_class( 'tmmt-report' ) ) . '">' . TMMT_Report_View::render( $filter, 'front' ) . '</div>';
	}

	/**
	 * Audience feedback form markup (also used inside the meeting overview).
	 *
	 * @param array $meeting Meeting with agenda.
	 * @param bool  $back    Show a "Back to the agenda" button next to Send (inside the meeting page only).
	 * @return string
	 */
	public static function audience_form( $meeting, $back = false ) {
		ob_start();
		include TMMT_DIR . 'includes/views/audience-form.php';
		return ob_get_clean();
	}

	/**
	 * Shortcode [tm_meeting id="0"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_meeting( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'tm_meeting' );
		return self::render_meeting_block( array( 'meetingId' => absint( $atts['id'] ) ) );
	}

	/**
	 * Shortcode [tm_feedback_form id="0"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_audience( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'tm_feedback_form' );
		return self::render_audience_block( array( 'meetingId' => absint( $atts['id'] ) ) );
	}

	/**
	 * Shortcode [tm_meeting_report].
	 *
	 * @return string
	 */
	public static function shortcode_report() {
		return self::render_report_block( array() );
	}

	/**
	 * Shortcode [tm_next_meeting format="l j F" empty=""]: the date (and time) of the next meeting as
	 * plain text, for use inside sentences such as a guest form's thank-you page.
	 *
	 * @param array|string $atts Attributes: format (PHP date format), empty (text when none is planned).
	 * @return string
	 */
	public static function shortcode_next_meeting( $atts ) {
		$atts = shortcode_atts(
			array(
				'format' => 'l j F',
				'empty'  => '',
			),
			$atts,
			'tm_next_meeting'
		);
		$upcoming = TMMT_Meetings::list_meetings(
			array(
				'when'  => 'upcoming',
				'limit' => 5,
			)
		);
		// "Upcoming" includes today's meeting; once it is over (marked completed) the next one is meant.
		$upcoming = array_values( array_filter( $upcoming, fn( $m ) => 'completed' !== $m['status'] ) );
		if ( ! $upcoming ) {
			return esc_html( $atts['empty'] );
		}
		$meeting = $upcoming[0];
		$moment  = date_create_immutable( $meeting['date'] . ' ' . ( $meeting['time'] ? $meeting['time'] : '00:00' ), wp_timezone() );
		return esc_html( wp_date( $atts['format'], $moment->getTimestamp() ) );
	}

	/**
	 * Tab labels.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'agenda'     => __( 'Agenda', 'tm-meeting-tools' ),
			'timer'      => __( 'Timer', 'tm-meeting-tools' ),
			'ah-counter' => __( 'Ah-Counter', 'tm-meeting-tools' ),
			'feedback'   => __( 'General Evaluator', 'tm-meeting-tools' ),
		);
	}
}
