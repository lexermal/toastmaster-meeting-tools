<?php
/**
 * wp-admin: feedback and results per meeting and per person.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports admin.
 */
class TMMT_Admin_Reports {

	/**
	 * Hooks.
	 */
	public static function init() {
		// The page has no menu entry of its own: highlight "Meetings" instead.
		add_filter(
			'submenu_file',
			static function ( $file ) {
				global $plugin_page;
				return 'tmmt-feedback' === $plugin_page ? 'tmmt-meetings' : $file;
			}
		);
	}

	/**
	 * Page.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filter = array(
			'meeting_id'  => isset( $_GET['tm_meeting'] ) ? absint( $_GET['tm_meeting'] ) : 0,
			'person_name' => isset( $_GET['tm_person'] ) ? tmmt_clean_line( wp_unslash( $_GET['tm_person'] ) ) : '',
		);
		// phpcs:enable
		echo '<div class="wrap tmmt-admin"><p><a href="' . esc_url( admin_url( 'admin.php?page=tmmt-meetings' ) ) . '">' . esc_html__( 'Back to all meetings', 'tm-meeting-tools' ) . '</a></p>';
		// No meeting picker on this page any more, so the heading says which meeting it is.
		$meeting = $filter['meeting_id'] ? TMMT_Meetings::get( $filter['meeting_id'], false ) : null;
		if ( $meeting && '' !== $meeting['theme'] ) {
			/* translators: 1: meeting theme, 2: meeting date. */
			$heading = sprintf( __( 'Meeting result: %1$s (%2$s)', 'tm-meeting-tools' ), $meeting['theme'], $meeting['date'] );
		} elseif ( $meeting ) {
			/* translators: %s: meeting date. */
			$heading = sprintf( __( 'Meeting result (%s)', 'tm-meeting-tools' ), $meeting['date'] );
		} else {
			$heading = __( 'Feedback and results', 'tm-meeting-tools' );
		}
		echo '<h1>' . esc_html( $heading ) . '</h1>';
		echo TMMT_Report_View::render( $filter, 'admin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '</div>';
	}
}
