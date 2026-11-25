<?php
/**
 * Results view (timings, Ah-Counter, feedback) per meeting and per person.
 * Used by the wp-admin Feedback screen and the front-end report block.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report renderer.
 */
class TMMT_Report_View {

	/**
	 * Renders filters and results.
	 *
	 * @param array  $filter  meeting_id, person_name.
	 * @param string $context admin|front.
	 * @return string HTML.
	 */
	public static function render( $filter, $context ) {
		$meetings = TMMT_Meetings::list_meetings( array( 'limit' => 200 ) );
		$people   = TMMT_Results::people();
		$filter   = array_filter( $filter );
		ob_start();
		// In wp-admin the page is opened from the meetings list (click a date), so no filter form there.
		if ( 'admin' !== $context ) {
			self::filters( $filter, $meetings, $people, $context );
		}
		if ( ! $filter ) {
			if ( 'admin' === $context ) {
				echo '<p class="tmmt-muted">' . esc_html__( 'Open a meeting from the meetings list to see its results.', 'tm-meeting-tools' ) . '</p>';
			} else {
				echo '<p class="tmmt-muted">' . esc_html__( 'Choose a meeting or a person to see timings, Ah-Counter results and feedback.', 'tm-meeting-tools' ) . '</p>';
			}
			return ob_get_clean();
		}
		$data = TMMT_Results::full_report( $filter );
		// One meeting: lay the results out along its agenda. General Evaluator notes are shown there,
		// so the feedback list below keeps only audience and evaluator feedback.
		$meeting = isset( $filter['meeting_id'] ) && ! isset( $filter['person_name'] ) ? TMMT_Meetings::get( (int) $filter['meeting_id'] ) : null;
		if ( $meeting ) {
			TMMT_Meeting_Report::render( $meeting, $data, self::table_class( $context ) );
			self::feedback( array_values( array_filter( $data['feedback'], static fn( $r ) => 'general' !== $r['kind'] ) ), $context );
			return ob_get_clean();
		}
		self::timings( $data['timings'], $context );
		self::fillers( $data['filler_counts'], $context );
		self::feedback( $data['feedback'], $context );
		return ob_get_clean();
	}

	/**
	 * Filter form (GET).
	 *
	 * @param array  $filter   Current filter.
	 * @param array  $meetings Meetings.
	 * @param array  $people   Person names.
	 * @param string $context  admin|front.
	 */
	private static function filters( $filter, $meetings, $people, $context ) {
		$mid    = isset( $filter['meeting_id'] ) ? (int) $filter['meeting_id'] : 0;
		$person = isset( $filter['person_name'] ) ? $filter['person_name'] : '';
		?>
		<form method="get" class="tmmt-report-filter">
			<?php
			// A GET form drops the action's query string, so carry it over (page=..., or page_id=... without pretty permalinks).
			$keep = array();
			if ( 'admin' === $context ) {
				$keep['page'] = 'tmmt-feedback';
			} else {
				wp_parse_str( (string) wp_parse_url( (string) get_permalink(), PHP_URL_QUERY ), $keep );
			}
			foreach ( $keep as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			?>
			<label>
				<span><?php esc_html_e( 'Meeting', 'tm-meeting-tools' ); ?></span>
				<select name="tm_meeting">
					<option value=""><?php esc_html_e( 'All meetings', 'tm-meeting-tools' ); ?></option>
					<?php foreach ( $meetings as $m ) : ?>
						<option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $mid, $m['id'] ); ?>><?php echo esc_html( $m['date'] . ( $m['theme'] ? ' ' . $m['theme'] : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Person', 'tm-meeting-tools' ); ?></span>
				<select name="tm_person">
					<option value=""><?php esc_html_e( 'Everyone', 'tm-meeting-tools' ); ?></option>
					<?php foreach ( $people as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $person, $p ); ?>><?php echo esc_html( $p ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button tmmt-btn"><?php esc_html_e( 'Show', 'tm-meeting-tools' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Verdict label.
	 *
	 * @param string $verdict within|under|over.
	 * @return string
	 */
	public static function verdict_label( $verdict ) {
		$labels = array(
			'within'    => __( 'Within time', 'tm-meeting-tools' ),
			'under'     => __( 'Under time', 'tm-meeting-tools' ),
			'over'      => __( 'Over time', 'tm-meeting-tools' ),
			'not_timed' => __( 'Not timed', 'tm-meeting-tools' ),
			'guest'     => __( 'Guest', 'tm-meeting-tools' ),
		);
		return isset( $labels[ $verdict ] ) ? $labels[ $verdict ] : $verdict;
	}

	/**
	 * Table class for the context.
	 *
	 * @param string $context admin|front.
	 * @return string
	 */
	private static function table_class( $context ) {
		return 'admin' === $context ? 'widefat striped tmmt-table' : 'tmmt-table';
	}

	/**
	 * Timings table.
	 *
	 * @param array  $rows    Timings.
	 * @param string $context admin|front.
	 */
	private static function timings( $rows, $context ) {
		echo '<h2 class="tmmt-h">' . esc_html__( 'Timings', 'tm-meeting-tools' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="tmmt-muted">' . esc_html__( 'No timings recorded.', 'tm-meeting-tools' ) . '</p>';
			return;
		}
		echo '<div class="tmmt-table-wrap"><table class="' . esc_attr( self::table_class( $context ) ) . '"><thead><tr>';
		foreach ( array( __( 'Date', 'tm-meeting-tools' ), __( 'Person', 'tm-meeting-tools' ), __( 'Role', 'tm-meeting-tools' ), __( 'Time', 'tm-meeting-tools' ), __( 'Window', 'tm-meeting-tools' ), __( 'Result', 'tm-meeting-tools' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="tmmt-verdict tmmt-verdict--%s">%s</span></td></tr>',
				esc_html( $r['meeting_date'] ),
				esc_html( $r['person_name'] ),
				esc_html( $r['role_label'] ),
				esc_html( tmmt_format_time( $r['duration_sec'] ) ),
				esc_html( tmmt_format_time( $r['green_sec'] ) . ' / ' . tmmt_format_time( $r['yellow_sec'] ) . ' / ' . tmmt_format_time( $r['red_sec'] ) ),
				esc_attr( $r['verdict'] ),
				esc_html( self::verdict_label( $r['verdict'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Filler counts table.
	 *
	 * @param array  $rows    Records.
	 * @param string $context admin|front.
	 */
	private static function fillers( $rows, $context ) {
		echo '<h2 class="tmmt-h">' . esc_html__( 'Ah-Counter', 'tm-meeting-tools' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="tmmt-muted">' . esc_html__( 'No filler words counted.', 'tm-meeting-tools' ) . '</p>';
			return;
		}
		echo '<div class="tmmt-table-wrap"><table class="' . esc_attr( self::table_class( $context ) ) . '"><thead><tr>';
		foreach ( array( __( 'Date', 'tm-meeting-tools' ), __( 'Person', 'tm-meeting-tools' ), __( 'Total', 'tm-meeting-tools' ), __( 'Words', 'tm-meeting-tools' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$parts = array();
			foreach ( $r['counts'] as $word => $n ) {
				if ( $n > 0 ) {
					$parts[] = $word . ' x' . $n;
				}
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_html( $r['meeting_date'] ),
				esc_html( $r['person_name'] ),
				(int) $r['total'],
				esc_html( $parts ? implode( ', ', $parts ) : __( 'none', 'tm-meeting-tools' ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Feedback list.
	 *
	 * @param array  $rows    Feedback.
	 * @param string $context admin|front.
	 */
	private static function feedback( $rows, $context ) {
		echo '<h2 class="tmmt-h">' . esc_html__( 'Feedback', 'tm-meeting-tools' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="tmmt-muted">' . esc_html__( 'No feedback yet.', 'tm-meeting-tools' ) . '</p>';
			return;
		}
		// One card per person (and meeting): all their feedback merged into "Did great" / "Potential" / "Notes".
		$groups = array();
		foreach ( $rows as $r ) {
			$key = $r['meeting_date'] . "\0" . ( '' !== $r['person_name'] ? $r['person_name'] : $r['about'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'name'  => '' !== $r['person_name'] ? $r['person_name'] : $r['about'],
					'date'  => $r['meeting_date'],
					'great' => array(),
					'pot'   => array(),
					'notes' => array(),
					'purge' => null,
					'title' => '',
				);
			}
			// The speech the feedback is about, from its agenda item.
			if ( '' === $groups[ $key ]['title'] && $r['agenda_item_id'] ) {
				$item                     = TMMT_Meetings::get_item( (int) $r['agenda_item_id'] );
				$groups[ $key ]['title'] = $item ? $item['speech_title'] : '';
			}
			// Matches TMMT_Results::purge_old_feedback(): audience and evaluator feedback go 7 days after it was given.
			if ( in_array( $r['kind'], array( 'audience', 'evaluation' ), true ) ) {
				$at = strtotime( $r['created_at'] ) + 7 * DAY_IN_SECONDS;
				$groups[ $key ]['purge'] = null === $groups[ $key ]['purge'] ? $at : min( $groups[ $key ]['purge'], $at );
			}
			// Feedback from a named role holder says who gave it; audience feedback stays anonymous.
			$from = 'evaluation' === $r['kind'] && '' !== $r['giver_name'] ? $r['giver_name'] : ( 'general' === $r['kind'] ? __( 'General Evaluator', 'tm-meeting-tools' ) : '' );
			foreach ( array( 'went_well' => 'great', 'potential' => 'pot', 'body' => 'notes' ) as $field => $bucket ) {
				if ( '' !== trim( $r[ $field ] ) ) {
					$groups[ $key ][ $bucket ][] = array(
						'text' => trim( $r[ $field ] ),
						'from' => $from,
					);
				}
			}
		}
		$titles = array(
			'great' => __( 'Did great', 'tm-meeting-tools' ),
			'pot'   => __( 'Potential', 'tm-meeting-tools' ),
			'notes' => __( 'Notes', 'tm-meeting-tools' ),
		);
		echo '<div class="tmmt-feedback-list">';
		foreach ( $groups as $g ) {
			echo '<article class="tmmt-fb">';
			echo '<header><strong>' . esc_html( $g['name'] ) . '</strong>' . ( '' !== $g['title'] ? ': ' . esc_html( $g['title'] ) : '' ) . ' <span class="tmmt-muted">' . esc_html( $g['date'] ) . '</span>';
			if ( $g['purge'] ) {
				// created_at is site time, so format it as such (no timezone shift).
				/* translators: %s: date, e.g. 2 December 2026. */
				echo '<br><span class="tmmt-muted">' . esc_html( sprintf( __( 'Will automatically be deleted on %s', 'tm-meeting-tools' ), date_i18n( get_option( 'date_format' ), $g['purge'] ) ) ) . '</span>';
			}
			echo '</header>';
			foreach ( $titles as $bucket => $title ) {
				if ( ! $g[ $bucket ] ) {
					continue;
				}
				echo '<p><em>' . esc_html( $title ) . ':</em></p><ul>';
				foreach ( $g[ $bucket ] as $entry ) {
					echo '<li>' . nl2br( esc_html( $entry['text'] ) );
					if ( '' !== $entry['from'] ) {
						/* translators: %s: who gave the feedback. */
						echo ' <span class="tmmt-muted">' . esc_html( sprintf( __( '(from %s)', 'tm-meeting-tools' ), $entry['from'] ) ) . '</span>';
					}
					echo '</li>';
				}
				echo '</ul>';
			}
			echo '</article>';
		}
		echo '</div>';
	}
}
