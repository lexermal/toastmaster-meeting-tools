<?php
/**
 * Anonymous audience feedback form. Submitted by assets/js/feedback.js to the submit-feedback ability.
 *
 * Variables: $meeting (array with agenda), $back (show a Back button to the agenda).
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

$tmmt_speakers = array();
foreach ( $meeting['agenda'] as $tmmt_item ) {
	if ( '' !== $tmmt_item['person_name'] && 'speech' === $tmmt_item['category'] ) {
		$tmmt_speakers[] = $tmmt_item;
	}
}
$tmmt_fid = 'tmmt-af-' . (int) $meeting['id'] . '-' . wp_rand( 100, 999 );
?>
<form class="tmmt-form tmmt-audience-form" data-meeting="<?php echo esc_attr( $meeting['id'] ); ?>" novalidate>
	<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'tmmt_audience' ) ); ?>">
	<?php // Honeypot: hidden from people, bots fill it in. ?>
	<div class="tmmt-hp" aria-hidden="true">
		<label for="<?php echo esc_attr( $tmmt_fid ); ?>-website"><?php esc_html_e( 'Website', 'tm-meeting-tools' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $tmmt_fid ); ?>-website" name="website" tabindex="-1" autocomplete="off">
	</div>

	<label class="tmmt-label" for="<?php echo esc_attr( $tmmt_fid ); ?>-who"><?php esc_html_e( 'Speaker', 'tm-meeting-tools' ); ?></label>
	<select class="tmmt-input" id="<?php echo esc_attr( $tmmt_fid ); ?>-who" name="agenda_item_id" required>
		<option value="" disabled selected hidden><?php esc_html_e( 'Choose a speaker', 'tm-meeting-tools' ); ?></option>
		<?php foreach ( $tmmt_speakers as $tmmt_n => $tmmt_item ) : ?>
			<?php /* translators: %d: speaker number in tonight's order. */ ?>
			<option value="<?php echo esc_attr( $tmmt_item['id'] ); ?>"><?php
				// &#45; not "-": wptexturize would turn " - " into an en dash.
				echo esc_html( sprintf( __( 'Speaker %d', 'tm-meeting-tools' ), $tmmt_n + 1 ) ) . ' &#45; ' . esc_html( $tmmt_item['person_name'] . ( '' !== $tmmt_item['speech_title'] ? ' (' . $tmmt_item['speech_title'] . ')' : '' ) );
			?></option>
		<?php endforeach; ?>
	</select>

	<label class="tmmt-label" for="<?php echo esc_attr( $tmmt_fid ); ?>-great"><?php esc_html_e( 'What did they do great?', 'tm-meeting-tools' ); ?></label>
	<textarea class="tmmt-input" id="<?php echo esc_attr( $tmmt_fid ); ?>-great" name="went_well" rows="4" maxlength="5000"></textarea>

	<label class="tmmt-label" for="<?php echo esc_attr( $tmmt_fid ); ?>-potential"><?php esc_html_e( 'Where do they have potential?', 'tm-meeting-tools' ); ?></label>
	<textarea class="tmmt-input" id="<?php echo esc_attr( $tmmt_fid ); ?>-potential" name="potential" rows="4" maxlength="5000"></textarea>

	<p class="tmmt-msg" role="status" aria-live="polite"></p>
	<div class="tmmt-form-actions">
		<?php if ( ! empty( $back ) ) : ?>
			<a class="tmmt-btn" href="#agenda"><?php esc_html_e( 'Back', 'tm-meeting-tools' ); ?></a>
		<?php endif; ?>
		<button type="submit" class="tmmt-btn tmmt-btn--primary"><?php esc_html_e( 'Send feedback', 'tm-meeting-tools' ); ?></button>
	</div>
	<noscript><p><?php esc_html_e( 'This form needs JavaScript.', 'tm-meeting-tools' ); ?></p></noscript>
</form>
