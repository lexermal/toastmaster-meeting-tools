<?php
/**
 * Results of one meeting, laid out along its agenda: the agenda with the real timings,
 * the Ah-Counter per agenda point and the General Evaluator's notes per agenda point.
 * Used by TMMT_Report_View when a single meeting (and no person) is chosen.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meeting report renderer.
 */
class TMMT_Meeting_Report {

	/**
	 * Echoes the agenda with timings, Ah-Counter results and the General Evaluator's notes.
	 *
	 * @param array  $meeting     Meeting with agenda.
	 * @param array  $data        TMMT_Results::full_report() for the meeting.
	 * @param string $table_class Table classes for the context.
	 */
	public static function render( $meeting, $data, $table_class ) {
		self::agenda( $meeting, $data['timings'], $data['filler_counts'], array_values( wp_list_filter( $data['feedback'], array( 'kind' => 'general' ) ) ), $table_class );
	}

	/**
	 * Agenda with planned start, real time, timing window, the Ah-Counter results and the General
	 * Evaluator's notes per agenda point. Notes not tied to an agenda point (Setup, Closing, Overall
	 * impression) get their own rows: Setup before the agenda, the rest after it.
	 *
	 * @param array  $meeting     Meeting with agenda.
	 * @param array  $timings     Timing records.
	 * @param array  $fillers     Filler count records.
	 * @param array  $ge_notes    General Evaluator notes (feedback of kind "general").
	 * @param string $table_class Table classes.
	 */
	private static function agenda( $meeting, $timings, $fillers, $ge_notes, $table_class ) {
		$by_item = array();
		foreach ( $timings as $t ) {
			if ( $t['agenda_item_id'] ) {
				$by_item[ $t['agenda_item_id'] ] = $t;
			}
		}
		$ah = array();
		foreach ( $fillers as $f ) {
			if ( $f['agenda_item_id'] ) {
				$ah[ $f['agenda_item_id'] ] = $f;
			}
		}
		// General Evaluator notes: on their agenda row, or as an extra row before/after the agenda.
		$topics = tmmt_ge_topics();
		$keys   = array_flip( $topics );
		$ge     = array();
		$before = array();
		$after  = array();
		foreach ( array_keys( $topics ) as $topic ) {
			foreach ( $ge_notes as $n ) {
				if ( ! isset( $keys[ $n['about'] ] ) || $keys[ $n['about'] ] !== $topic ) {
					continue;
				}
				$item = self::ge_item( $meeting, $topic, $n['agenda_item_id'] );
				if ( $item ) {
					$ge[ $item['id'] ][] = $n;
				} elseif ( 'setup' === $topic ) {
					$before[] = $n;
				} else {
					$after[] = $n;
				}
			}
		}
		$wotd = $meeting['word_of_day'];
		echo '<h2 class="tmmt-h">' . esc_html__( 'Agenda and timings', 'tm-meeting-tools' ) . '</h2>';
		if ( ! $meeting['agenda'] ) {
			echo '<p class="tmmt-muted">' . esc_html__( 'This meeting has no agenda.', 'tm-meeting-tools' ) . '</p>';
			return;
		}
		// Each toggle only flips a class on the table wrapper, which hides that group of columns.
		$toggle = static function ( $class, $label, $on = true ) {
			return '<label class="tmmt-toggle"><input type="checkbox"' . ( $on ? ' checked' : '' ) . ' onchange="this.closest(\'p\').nextElementSibling.classList.toggle(\'' . $class . '\', ! this.checked)"> ' . esc_html( $label ) . '</label>';
		};
		echo '<p class="tmmt-toggles">' . $toggle( 'tmmt-hide-real', __( 'Real times', 'tm-meeting-tools' ) ) . ' ' . $toggle( 'tmmt-hide-ah', __( 'Ah-Counter feedback', 'tm-meeting-tools' ) ) . ( '' !== $wotd ? ' ' . $toggle( 'tmmt-hide-wotd', __( 'Word of the Day', 'tm-meeting-tools' ), false ) : '' ) . ' ' . $toggle( 'tmmt-hide-ge', __( 'General Evaluator feedback', 'tm-meeting-tools' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $toggle.
		// The Word of the Day column starts hidden (its toggle starts off).
		echo '<div class="tmmt-table-wrap tmmt-hide-wotd"><table class="' . esc_attr( $table_class . ' tmmt-agenda-result' ) . '"><thead><tr>';
		foreach ( array( __( 'Planned start', 'tm-meeting-tools' ), __( 'Agenda point', 'tm-meeting-tools' ), __( 'Person', 'tm-meeting-tools' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '<th scope="col" class="tmmt-col-real">' . esc_html__( 'Time', 'tm-meeting-tools' ) . '</th><th scope="col">' . esc_html__( 'Green / Yellow / Red', 'tm-meeting-tools' ) . '</th>';
		echo '<th scope="col" class="tmmt-col-ah">' . esc_html__( 'Filler words', 'tm-meeting-tools' ) . '</th>';
		if ( '' !== $wotd ) {
			echo '<th scope="col" class="tmmt-col-wotd">' . esc_html__( 'Word of the Day', 'tm-meeting-tools' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		// The General Evaluator's notes go on the next lines under the agenda point name.
		$ge_lines = static function ( $notes ) {
			$out = '';
			foreach ( $notes as $n ) {
				// Two notes on one row (Ah-Counter and Grammarian share the report): say which is which.
				$out .= '<span class="tmmt-col-ge tmmt-ge-note"><br>' . ( count( $notes ) > 1 ? esc_html( $n['about'] ) . ': ' : '' ) . nl2br( esc_html( $n['body'] ) ) . '</span>';
			}
			return $out;
		};
		// A row with only the agenda point name and the General Evaluator's note.
		$extra = static function ( $n ) use ( $wotd, $ge_lines ) {
			echo '<tr class="tmmt-col-ge"><td></td><td>' . esc_html( $n['about'] ) . $ge_lines( array( $n ) ) . '</td><td></td><td class="tmmt-col-real"></td><td></td><td class="tmmt-col-ah"></td>' . ( '' !== $wotd ? '<td class="tmmt-col-wotd"></td>' : '' ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
		};
		array_map( $extra, $before );
		// Same planning as the meeting page: start time plus the red time of everything before.
		$at = self::seconds_of_day( $meeting['time'] );
		foreach ( $meeting['agenda'] as $it ) {
			$t    = isset( $by_item[ $it['id'] ] ) ? $by_item[ $it['id'] ] : null;
			$time = '-';
			if ( $t ) {
				// The badge colour is the result; the label stays available to screen readers and on hover.
				$verdict = $it['is_guest'] ? 'guest' : $t['verdict'];
				$label   = TMMT_Report_View::verdict_label( $verdict );
				$time    = '<span class="tmmt-verdict tmmt-verdict--' . esc_attr( $verdict ) . '" title="' . esc_attr( $label ) . '">' . esc_html( tmmt_format_time( $t['duration_sec'] ) ) . '<span class="screen-reader-text"> (' . esc_html( $label ) . ')</span></span>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="tmmt-col-real">%s</td><td>%s</td>',
				esc_html( sprintf( '%02d:%02d', intdiv( $at, 3600 ) % 24, intdiv( $at % 3600, 60 ) ) ),
				// The speech title goes on its own line under the role.
				esc_html( $it['label'] ) . ( '' !== $it['speech_title'] ? '<br><span class="tmmt-muted">' . esc_html( sprintf( /* translators: %s: speech title. */ __( 'Speech: %s', 'tm-meeting-tools' ), $it['speech_title'] ) ) . '</span>' : '' ) . $ge_lines( isset( $ge[ $it['id'] ] ) ? $ge[ $it['id'] ] : array() ),
				esc_html( $it['person_name'] ),
				$time, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $it['timed'] ? tmmt_format_time( $it['green_sec'] ) . ' / ' . tmmt_format_time( $it['yellow_sec'] ) . ' / ' . tmmt_format_time( $it['red_sec'] ) : '-' )
			);
			$f      = isset( $ah[ $it['id'] ] ) ? $ah[ $it['id'] ] : null;
			$counts = $f ? $f['counts'] : array();
			arsort( $counts );
			$parts = array();
			foreach ( $counts as $word => $n ) {
				if ( $n > 0 && $word !== $wotd ) {
					$parts[] = $n . 'x ' . $word;
				}
			}
			// The Ah-Counter's notes go on the next line under the filler words.
			$notes = $f && '' !== trim( $f['notes'] ) ? '<br><span class="tmmt-muted">' . nl2br( esc_html( trim( $f['notes'] ) ) ) . '</span>' : '';
			echo '<td class="tmmt-col-ah">' . esc_html( $parts ? implode( ', ', $parts ) : '-' ) . $notes . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			if ( '' !== $wotd ) {
				echo '<td class="tmmt-col-wotd">' . esc_html( ! empty( $counts[ $wotd ] ) ? (string) $counts[ $wotd ] : '-' ) . '</td>';
			}
			echo '</tr>';
			$at += $it['red_sec'];
		}
		array_map( $extra, $after );
		echo '</tbody></table></div>';
	}

	/**
	 * The agenda item a General Evaluator topic is about. Mirrors the matching in general-evaluator.js.
	 *
	 * @param array    $meeting Meeting with agenda.
	 * @param string   $key     Topic key.
	 * @param int|null $item_id Agenda item (evaluators).
	 * @return array|null Null for topics without an agenda item (Setup, Closing, Overall impression).
	 */
	private static function ge_item( $meeting, $key, $item_id ) {
		$agenda = $meeting['agenda'];
		$hits   = array();
		$roles  = array(
			'toastmaster' => '/^toastmaster\b/i',
			'ah_counter'  => '/ah-counter/i',
			'grammarian'  => '/grammarian/i',
			'timer'       => '/timekeeper|timer/i',
			'meet_greet'  => '/meet\s*(and|&)\s*greet/i',
		);
		if ( 'evaluator' === $key ) {
			$hits = wp_list_filter( $agenda, array( 'id' => (int) $item_id ) );
		} elseif ( isset( $roles[ $key ] ) ) {
			$hits = array_slice( self::matching( $agenda, $roles[ $key ] ), -1 );
		} elseif ( 'opening' === $key ) {
			$hits = array_slice( self::matching( $agenda, '/president|opening/i' ), 0, 1 );
		} elseif ( 'tt_master' === $key ) {
			$hits = array_slice( array_filter( self::matching( $agenda, '/master/i' ), static fn( $i ) => 'table_topic' === $i['category'] ), 0, 1 );
		} elseif ( 'break' === $key ) {
			$hits = array_slice( wp_list_filter( $agenda, array( 'category' => 'break' ) ), 0, 1 );
		}
		$hit = reset( $hits );
		return $hit ? $hit : null;
	}

	/**
	 * Agenda items whose label matches a pattern, in agenda order.
	 *
	 * @param array  $agenda  Items.
	 * @param string $pattern Regex.
	 * @return array
	 */
	private static function matching( $agenda, $pattern ) {
		return array_values( array_filter( $agenda, static fn( $i ) => (bool) preg_match( $pattern, $i['label'] ) ) );
	}

	/**
	 * "18:00" => seconds since midnight.
	 *
	 * @param string $time H:i.
	 * @return int
	 */
	private static function seconds_of_day( $time ) {
		$p = array_map( 'intval', explode( ':', (string) $time ) );
		return ( isset( $p[0] ) ? $p[0] : 0 ) * 3600 + ( isset( $p[1] ) ? $p[1] : 0 ) * 60;
	}
}
