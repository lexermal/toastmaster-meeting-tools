<?php
/**
 * wp-admin: member list (name and Toastmasters ID).
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Members admin.
 */
class TMMT_Admin_Members {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_tmmt_save_members', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Page: one editable table, a New row at the bottom, Remove per row.
	 */
	public static function page() {
		$members = TMMT_Members::all();
		?>
		<div class="wrap tmmt-admin">
			<h1><?php esc_html_e( 'Members', 'tm-meeting-tools' ); ?></h1>
			<p class="description"><?php /* translators: %d: number of days. */ echo esc_html( sprintf( __( 'Members who are not seen in a meeting for %d days are removed automatically (change it in Settings). The Toastmasters ID is the tmclub.eu user ID.', 'tm-meeting-tools' ), tmmt_member_inactive_days() ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tmmt_save_members">
				<?php wp_nonce_field( 'tmmt_save_members' ); ?>
				<?php // Pressing Enter submits the first submit button in the form; without this it would be a "Remove" button. ?>
				<button type="submit" class="tmmt-default-submit" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Save members', 'tm-meeting-tools' ); ?></button>
				<div class="tmmt-table-wrap">
				<table class="widefat striped tmmt-members">
					<thead><tr>
						<th><?php esc_html_e( 'Name', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Toastmasters ID', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Last synced', 'tm-meeting-tools' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tm-meeting-tools' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $members as $m ) : ?>
						<?php $n = 'members[' . $m['id'] . ']'; ?>
						<tr>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $m['name'] ); ?>" required aria-label="<?php esc_attr_e( 'Name', 'tm-meeting-tools' ); ?>"></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[tm_id]" value="<?php echo esc_attr( $m['tm_id'] ); ?>" maxlength="30" aria-label="<?php esc_attr_e( 'Toastmasters ID', 'tm-meeting-tools' ); ?>"></td>
							<td><?php echo esc_html( $m['last_synced_at'] ? mysql2date( get_option( 'date_format' ), $m['last_synced_at'] ) : '–' ); ?></td>
							<td><button type="submit" class="button-link tmmt-delete" name="remove" value="<?php echo esc_attr( $m['id'] ); ?>" onclick="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Remove this member? Meetings keep the name.', 'tm-meeting-tools' ); ?>"><?php esc_html_e( 'Remove', 'tm-meeting-tools' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
						<tr class="tmmt-new-row">
							<td><input type="text" class="regular-text" name="new[name]" placeholder="<?php esc_attr_e( 'New member', 'tm-meeting-tools' ); ?>" aria-label="<?php esc_attr_e( 'Name', 'tm-meeting-tools' ); ?>"></td>
							<td><input type="text" name="new[tm_id]" maxlength="30" aria-label="<?php esc_attr_e( 'Toastmasters ID', 'tm-meeting-tools' ); ?>"></td>
							<td></td>
							<td></td>
						</tr>
					</tbody>
				</table>
				</div>
				<?php submit_button( __( 'Save members', 'tm-meeting-tools' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Saves all rows, adds the New row, removes one member.
	 */
	public static function handle_save() {
		TMMT_Admin::guard( 'tmmt_save_members' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in TMMT_Members.
		$err  = null;
		$rows = isset( $post['members'] ) && is_array( $post['members'] ) ? $post['members'] : array();
		foreach ( $rows as $id => $raw ) {
			$res = TMMT_Members::update( (int) $id, (array) $raw );
			if ( is_wp_error( $res ) ) {
				$err = $res;
			}
		}
		if ( ! empty( $post['new']['name'] ) ) {
			$res = TMMT_Members::create( (array) $post['new'] );
			if ( is_wp_error( $res ) ) {
				$err = $res;
			}
		}
		if ( ! empty( $post['remove'] ) ) {
			TMMT_Members::delete( absint( $post['remove'] ) );
		}
		TMMT_Admin::back( admin_url( 'admin.php?page=tmmt-members' ), $err ? $err : __( 'Members saved.', 'tm-meeting-tools' ) );
	}
}
