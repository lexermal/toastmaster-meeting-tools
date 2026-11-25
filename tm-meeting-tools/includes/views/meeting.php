<?php
/**
 * Meeting overview: header, tabs, server-rendered agenda, and mount points for the tools.
 *
 * Variables: $meeting (array with agenda), $wrapper (escaped wrapper attributes).
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

$tmmt_tabs = TMMT_Frontend::tabs();
$tmmt_date = date_i18n( 'l j F Y', strtotime( $meeting['date'] ) );
$tmmt_uid  = 'tmmt-' . (int) $meeting['id'];
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes / esc_attr. ?> data-tmmt-meeting="<?php echo esc_attr( $meeting['id'] ); ?>">
	<header class="tmmt-head">
		<?php // &#45; not "-": wptexturize would turn " - " into an en dash. ?>
		<h1 class="tmmt-theme"><?php echo esc_html__( 'TM Meeting', 'tm-meeting-tools' ) . ( '' !== $meeting['theme'] ? ' &#45; ' . esc_html( $meeting['theme'] ) : '' ); ?></h1>
		<p class="tmmt-date"><?php echo esc_html( $tmmt_date ); ?> <span aria-hidden="true">&middot;</span> <?php echo esc_html( $meeting['time'] ); ?></p>
	</header>

	<nav class="tmmt-tabs" role="tablist" hidden aria-label="<?php esc_attr_e( 'Meeting tools', 'tm-meeting-tools' ); ?>">
		<?php foreach ( $tmmt_tabs as $tmmt_key => $tmmt_label ) : ?>
			<a role="tab" class="tmmt-tab" id="<?php echo esc_attr( $tmmt_uid . '-tab-' . $tmmt_key ); ?>" href="#<?php echo esc_attr( $tmmt_key ); ?>" data-tab="<?php echo esc_attr( $tmmt_key ); ?>" aria-controls="<?php echo esc_attr( $tmmt_uid . '-' . $tmmt_key ); ?>" aria-selected="<?php echo 'agenda' === $tmmt_key ? 'true' : 'false'; ?>"><?php echo esc_html( $tmmt_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<section class="tmmt-panel" role="tabpanel" data-panel="agenda" id="<?php echo esc_attr( $tmmt_uid . '-agenda' ); ?>" aria-labelledby="<?php echo esc_attr( $tmmt_uid . '-tab-agenda' ); ?>">
		<?php if ( ! $meeting['agenda'] ) : ?>
			<p class="tmmt-empty"><?php esc_html_e( 'The agenda is not ready yet.', 'tm-meeting-tools' ); ?></p>
		<?php else : ?>
			<?php
			// Sections follow the agenda order: roles before any speech, break or table topic are
			// the intro, roles after them are the feedback part. Each section shows its planned
			// start: meeting start plus the max (red) time of everything before it.
			$tmmt_labels   = array(
				'intro'       => __( 'Intro', 'tm-meeting-tools' ),
				'speech'      => __( 'Speeches', 'tm-meeting-tools' ),
				'break'       => __( 'Break', 'tm-meeting-tools' ),
				'table_topic' => __( 'Table Topics', 'tm-meeting-tools' ),
				'feedback'    => __( 'Feedback', 'tm-meeting-tools' ),
				'end'         => __( 'Meeting ending', 'tm-meeting-tools' ),
			);
			list( $tmmt_h, $tmmt_m ) = array_map( 'intval', explode( ':', $meeting['time'] . ':0' ) );
			$tmmt_at       = $tmmt_h * 3600 + $tmmt_m * 60;
			$tmmt_sections = array();
			$tmmt_main     = false;
			foreach ( $meeting['agenda'] as $tmmt_item ) {
				// Meet and Greet happens before the meeting starts: not part of the public agenda.
				if ( preg_match( '/meet\s*(and|&)\s*greet/i', $tmmt_item['label'] ) ) {
					$tmmt_at += $tmmt_item['red_sec'];
					continue;
				}
				$tmmt_key  = isset( $tmmt_labels[ $tmmt_item['category'] ] ) ? $tmmt_item['category'] : ( $tmmt_main ? 'feedback' : 'intro' );
				$tmmt_main = $tmmt_main || ! in_array( $tmmt_key, array( 'intro', 'feedback' ), true );
				$tmmt_last = end( $tmmt_sections );
				if ( ! $tmmt_last || $tmmt_last['key'] !== $tmmt_key ) {
					$tmmt_sections[] = array(
						'key'   => $tmmt_key,
						'start' => sprintf( '%02d:%02d', intdiv( $tmmt_at, 3600 ) % 24, intdiv( $tmmt_at % 3600, 60 ) ),
						'items' => array(),
					);
				}
				$tmmt_sections[ count( $tmmt_sections ) - 1 ]['items'][] = $tmmt_item;
				$tmmt_at += $tmmt_item['red_sec'];
			}
			// Closing section: when everything above has run its max time. Heading only, no items.
			$tmmt_sections[] = array(
				'key'   => 'end',
				'start' => sprintf( '%02d:%02d', intdiv( $tmmt_at, 3600 ) % 24, intdiv( $tmmt_at % 3600, 60 ) ),
				'items' => array(),
			);
			?>
			<?php foreach ( $tmmt_sections as $tmmt_section ) : ?>
				<div class="tmmt-section tmmt-section--<?php echo esc_attr( $tmmt_section['key'] ); ?>">
					<h3 class="tmmt-section-head"><span class="tmmt-start"><?php echo esc_html( $tmmt_section['start'] ); ?></span> <?php echo esc_html( $tmmt_labels[ $tmmt_section['key'] ] ); ?></h3>
					<?php // The heading already says "Break"; an item list would only repeat it. ?>
					<?php if ( $tmmt_section['items'] && 'break' !== $tmmt_section['key'] ) : ?>
						<ol class="tmmt-agenda">
							<?php foreach ( $tmmt_section['items'] as $tmmt_item ) : ?>
								<li class="tmmt-agenda-item tmmt-cat-<?php echo esc_attr( $tmmt_item['category'] ); ?>">
									<div class="tmmt-agenda-main">
										<span class="tmmt-line">
											<span class="tmmt-role"><?php echo esc_html( $tmmt_item['label'] ); ?></span>
											<?php if ( '' !== $tmmt_item['person_name'] ) : ?>
												&#45; <span class="tmmt-person"><?php echo esc_html( $tmmt_item['person_name'] ); ?></span>
											<?php else : ?>
												&#45; <em class="tmmt-person tmmt-open"><?php esc_html_e( 'Open', 'tm-meeting-tools' ); ?></em>
											<?php endif; ?>
										</span>
										<?php if ( '' !== $tmmt_item['speech_title'] ) : ?>
											<span class="tmmt-title"><?php echo esc_html( $tmmt_item['speech_title'] ); ?></span>
										<?php endif; ?>
									</div>
									<?php if ( 'speech' === $tmmt_item['category'] && '' !== $tmmt_item['person_name'] ) : ?>
										<?php /* translators: %s: speaker name. */ ?>
										<a class="tmmt-btn tmmt-btn--small" href="#audience" data-feedback-for="<?php echo esc_attr( $tmmt_item['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Give feedback to %s', 'tm-meeting-tools' ), $tmmt_item['person_name'] ) ); ?>"><?php esc_html_e( 'Give Feedback', 'tm-meeting-tools' ); ?></a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>

	<?php foreach ( array( 'timer', 'ah-counter', 'feedback' ) as $tmmt_key ) : ?>
		<section class="tmmt-panel" role="tabpanel" data-panel="<?php echo esc_attr( $tmmt_key ); ?>" id="<?php echo esc_attr( $tmmt_uid . '-' . $tmmt_key ); ?>" aria-labelledby="<?php echo esc_attr( $tmmt_uid . '-tab-' . $tmmt_key ); ?>" hidden>
			<noscript><p><?php esc_html_e( 'This tool needs JavaScript.', 'tm-meeting-tools' ); ?></p></noscript>
		</section>
	<?php endforeach; ?>

	<section class="tmmt-panel" data-panel="audience" id="<?php echo esc_attr( $tmmt_uid . '-audience' ); ?>" hidden>
		<?php echo TMMT_Frontend::audience_form( $meeting, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the view. ?>
	</section>

	<?php // Password form for role holders; reached from the "Role tools" link below. ?>
	<section class="tmmt-panel" data-panel="unlock" id="<?php echo esc_attr( $tmmt_uid . '-unlock' ); ?>" hidden></section>

	<p class="tmmt-foot"><a class="tmmt-link tmmt-tools-link" href="#unlock"><?php esc_html_e( 'Role tools', 'tm-meeting-tools' ); ?></a></p>
</div>
