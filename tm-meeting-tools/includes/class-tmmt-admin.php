<?php
/**
 * wp-admin: menu, settings (tools password) and agenda points (role types).
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin base.
 */
class TMMT_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		foreach ( array( 'save_settings', 'save_roles', 'add_role', 'delete_role' ) as $action ) {
			add_action( 'admin_post_tmmt_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Menu pages.
	 */
	public static function menu() {
		$cap = tmmt_manage_cap();
		add_menu_page( __( 'Meeting Tools', 'tm-meeting-tools' ), __( 'Meeting Tools', 'tm-meeting-tools' ), $cap, 'tmmt-meetings', array( 'TMMT_Admin_Meetings', 'page' ), 'dashicons-clock', 30 );
		add_submenu_page( 'tmmt-meetings', __( 'Meetings', 'tm-meeting-tools' ), __( 'Meetings', 'tm-meeting-tools' ), $cap, 'tmmt-meetings', array( 'TMMT_Admin_Meetings', 'page' ) );
		// Results are reached from the meetings list (click a date), so the menu entry is removed again in
		// admin_head: by then WordPress has read the page title from it, and the menu is not drawn yet.
		add_submenu_page( 'tmmt-meetings', __( 'Feedback and results', 'tm-meeting-tools' ), __( 'Feedback and results', 'tm-meeting-tools' ), $cap, 'tmmt-feedback', array( 'TMMT_Admin_Reports', 'page' ) );
		add_action(
			'admin_head',
			static function () {
				remove_submenu_page( 'tmmt-meetings', 'tmmt-feedback' );
			}
		);
		add_submenu_page( 'tmmt-meetings', __( 'Members', 'tm-meeting-tools' ), __( 'Members', 'tm-meeting-tools' ), $cap, 'tmmt-members', array( 'TMMT_Admin_Members', 'page' ) );
		add_submenu_page( 'tmmt-meetings', __( 'Settings', 'tm-meeting-tools' ), __( 'Settings', 'tm-meeting-tools' ), $cap, 'tmmt-settings', array( __CLASS__, 'settings_page' ) );
	}

	/**
	 * Admin CSS on plugin pages only.
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'tmmt-' ) ) {
			wp_enqueue_style( 'tmmt-admin', TMMT_URL . 'assets/css/admin.css', array(), TMMT_VERSION . '.' . (int) filemtime( TMMT_DIR . 'assets/css/admin.css' ) );
		}
		if ( false !== strpos( $hook, 'tmmt-' ) ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
			// Drag to reorder: agenda points (settings) and a meeting's agenda. On settings, dragging rewrites
			// the hidden sort_order fields (10, 20, ...); a meeting's agenda is saved in on-screen row order.
			wp_add_inline_script(
				'jquery-ui-sortable',
				'jQuery(function($){$(".tmmt-roles tbody, .tmmt-agenda-admin tbody").sortable({handle:".tmmt-drag",axis:"y",items:"> tr:not(.tmmt-new-row)",helper:function(e,tr){var o=tr.children();return tr.clone().children().each(function(i){$(this).width(o.eq(i).width());}).end();},update:function(){$(this).find(".tmmt-sort").each(function(i){this.value=(i+1)*10;});}});});'
			);
		}
	}

	/**
	 * Dies unless allowed and the nonce is valid.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	public static function guard( $nonce_action ) {
		if ( ! current_user_can( tmmt_manage_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'tm-meeting-tools' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirects back with a notice.
	 *
	 * @param string               $url    Target admin URL.
	 * @param string|WP_Error|null $result Success message or error.
	 */
	public static function back( $url, $result ) {
		if ( $result ) {
			set_transient(
				'tmmt_notice_' . get_current_user_id(),
				array(
					'type' => is_wp_error( $result ) ? 'error' : 'success',
					'text' => is_wp_error( $result ) ? $result->get_error_message() : $result,
				),
				60
			);
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Shows the stored notice once.
	 */
	public static function notices() {
		$key    = 'tmmt_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
	}

	/**
	 * Settings and roles page.
	 */
	public static function settings_page() {
		$roles  = TMMT_Roles::all();
		$cats   = tmmt_role_categories();
		?>
		<div class="wrap tmmt-admin">
			<h1><?php esc_html_e( 'Settings', 'tm-meeting-tools' ); ?></h1>

			<h2><label for="tmmt-password"><?php esc_html_e( 'Change password for meeting tools', 'tm-meeting-tools' ); ?></label></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tmmt_save_settings">
				<?php wp_nonce_field( 'tmmt_save_settings' ); ?>
				<div class="tmmt-actions">
					<input type="password" id="tmmt-password" name="tools_password" class="regular-text" autocomplete="new-password" required>
					<?php submit_button( __( 'Change password', 'tm-meeting-tools' ), 'primary', 'submit', false ); ?>
				</div>
				<?php if ( ! TMMT_Auth::has_password() ) : ?>
					<p class="description"><?php esc_html_e( 'No password set yet: only logged-in admins can use the timer, Ah-Counter and evaluation tools.', 'tm-meeting-tools' ); ?></p>
				<?php endif; ?>
			</form>

			<h2><?php esc_html_e( 'Agenda points', 'tm-meeting-tools' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Untick "Timed" for agenda points the timekeeper does not time (they then do not show in the timer). Drag the handle on the left to change the order, then save.', 'tm-meeting-tools' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tmmt_save_roles">
				<?php wp_nonce_field( 'tmmt_save_roles' ); ?>
				<div class="tmmt-table-wrap">
				<table class="widefat striped tmmt-roles">
					<thead><tr>
						<th class="tmmt-drag-col"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'tm-meeting-tools' ); ?></span></th>
						<th><?php esc_html_e( 'Name', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Category', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Green (min)', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Yellow (min)', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Red (min)', 'tm-meeting-tools' ); ?></th>
						<th><?php esc_html_e( 'Timed', 'tm-meeting-tools' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tm-meeting-tools' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $roles as $r ) : ?>
						<?php $n = 'roles[' . (int) $r['id'] . ']'; ?>
						<tr>
							<td class="tmmt-drag-col">
								<span class="tmmt-drag dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to change the order', 'tm-meeting-tools' ); ?>" aria-hidden="true"></span>
								<?php // Filled in from the row position when rows are dragged (see the script below the table). ?>
								<input type="hidden" class="tmmt-sort" name="<?php echo esc_attr( $n ); ?>[sort_order]" value="<?php echo esc_attr( $r['sort_order'] ); ?>">
							</td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $r['name'] ); ?>" aria-label="<?php esc_attr_e( 'Name', 'tm-meeting-tools' ); ?>"></td>
							<td><?php self::category_select( $n . '[category]', $r['category'], $cats ); ?></td>
							<?php foreach ( array( 'green_sec', 'yellow_sec', 'red_sec' ) as $k ) : ?>
								<td><input type="text" class="tmmt-time" name="<?php echo esc_attr( $n . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( tmmt_format_time( $r[ $k ] ) ); ?>" inputmode="numeric" pattern="\d{1,3}(:[0-5]?\d)?" aria-label="<?php echo esc_attr( $k ); ?>"></td>
							<?php endforeach; ?>
							<td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[timed]" value="1" <?php checked( $r['timed'] ); ?> aria-label="<?php esc_attr_e( 'Timed by the timekeeper', 'tm-meeting-tools' ); ?>"></td>
							<td>
								<?php $del = wp_nonce_url( admin_url( 'admin-post.php?action=tmmt_delete_role&id=' . (int) $r['id'] ), 'tmmt_delete_role_' . (int) $r['id'] ); ?>
								<a class="tmmt-delete" href="<?php echo esc_url( $del ); ?>" onclick="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Delete this agenda point?', 'tm-meeting-tools' ); ?>"><?php esc_html_e( 'Delete', 'tm-meeting-tools' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="submit tmmt-actions">
					<button type="button" class="button" onclick="document.getElementById('tmmt-add-dialog').showModal()"><?php esc_html_e( 'Add agenda point', 'tm-meeting-tools' ); ?></button>
					<?php submit_button( __( 'Save agenda points', 'tm-meeting-tools' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

			<dialog id="tmmt-add-dialog" class="tmmt-dialog" aria-labelledby="tmmt-add-title">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tmmt_add_role">
					<?php wp_nonce_field( 'tmmt_add_role' ); ?>
					<h2 id="tmmt-add-title"><?php esc_html_e( 'Add an agenda point', 'tm-meeting-tools' ); ?></h2>
					<p><label><?php esc_html_e( 'Name', 'tm-meeting-tools' ); ?><br><input type="text" name="name" class="regular-text" required></label></p>
					<p><label><?php esc_html_e( 'Category', 'tm-meeting-tools' ); ?><br><?php self::category_select( 'category', 'role', $cats ); ?></label></p>
					<p class="description"><?php esc_html_e( 'The category tells the tools how to treat the agenda point:', 'tm-meeting-tools' ); ?></p>
					<ul class="tmmt-cat-help">
						<li><strong><?php esc_html_e( 'Speech', 'tm-meeting-tools' ); ?>:</strong> <?php esc_html_e( 'a prepared speech. Gets the "Give Feedback" button, shows in the audience feedback form and in the Speeches section, and its time can come from the speech type.', 'tm-meeting-tools' ); ?></li>
						<li><strong><?php esc_html_e( 'Role', 'tm-meeting-tools' ); ?>:</strong> <?php esc_html_e( 'a meeting role such as Toastmaster, Evaluator or Timekeeper. Shown in the Intro or Feedback section, depending on where it is on the agenda.', 'tm-meeting-tools' ); ?></li>
						<li><strong><?php esc_html_e( 'Table topic', 'tm-meeting-tools' ); ?>:</strong> <?php esc_html_e( 'the Table Topics Master and speakers. The timer adds new table topic speakers here.', 'tm-meeting-tools' ); ?></li>
						<li><strong><?php esc_html_e( 'Break', 'tm-meeting-tools' ); ?>:</strong> <?php esc_html_e( 'a pause. Gets its own section on the agenda and needs no person.', 'tm-meeting-tools' ); ?></li>
						<li><strong><?php esc_html_e( 'Other', 'tm-meeting-tools' ); ?>:</strong> <?php esc_html_e( 'anything else, such as Meet and Greet. Treated like a role.', 'tm-meeting-tools' ); ?></li>
					</ul>
					<fieldset class="tmmt-inline-form">
						<legend><?php esc_html_e( 'Timing', 'tm-meeting-tools' ); ?></legend>
						<label><?php esc_html_e( 'Green (min)', 'tm-meeting-tools' ); ?> <input type="text" class="tmmt-time" name="green_sec" value="1:00"></label>
						<label><?php esc_html_e( 'Yellow (min)', 'tm-meeting-tools' ); ?> <input type="text" class="tmmt-time" name="yellow_sec" value="1:30"></label>
						<label><?php esc_html_e( 'Red (min)', 'tm-meeting-tools' ); ?> <input type="text" class="tmmt-time" name="red_sec" value="2:00"></label>
						<label><input type="checkbox" name="timed" value="1" checked> <?php esc_html_e( 'Timed', 'tm-meeting-tools' ); ?></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'New agenda points go to the end of the list; drag them where they belong.', 'tm-meeting-tools' ); ?></p>
					<p class="submit tmmt-actions">
						<?php submit_button( __( 'Add agenda point', 'tm-meeting-tools' ), 'primary', 'submit', false ); ?>
						<button type="submit" class="button" formmethod="dialog" formnovalidate><?php esc_html_e( 'Cancel', 'tm-meeting-tools' ); ?></button>
					</p>
				</form>
			</dialog>
		</div>
		<?php
	}

	/**
	 * Category select.
	 *
	 * @param string $name    Field name.
	 * @param string $current Current value.
	 * @param array  $cats    Categories.
	 */
	private static function category_select( $name, $current, $cats ) {
		echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr__( 'Category', 'tm-meeting-tools' ) . '">';
		foreach ( $cats as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Role fields from a posted array.
	 *
	 * @param array $raw Posted (unslashed) fields.
	 * @return array|WP_Error
	 */
	private static function role_from_post( $raw ) {
		$out = array(
			'name'     => isset( $raw['name'] ) ? $raw['name'] : '',
			'category' => isset( $raw['category'] ) ? sanitize_key( $raw['category'] ) : 'role',
		);
		foreach ( array( 'green_sec', 'yellow_sec', 'red_sec' ) as $k ) {
			$sec = tmmt_parse_time( isset( $raw[ $k ] ) ? $raw[ $k ] : '' );
			if ( null === $sec ) {
				return new WP_Error( 'tmmt_invalid', __( 'Please enter times as minutes:seconds, for example 5:00.', 'tm-meeting-tools' ) );
			}
			$out[ $k ] = $sec;
		}
		if ( isset( $raw['sort_order'] ) ) {
			$out['sort_order'] = (int) $raw['sort_order'];
		}
		// Unticked checkboxes are not posted at all.
		$out['timed'] = ! empty( $raw['timed'] );
		return $out;
	}

	/**
	 * Saves the tools password.
	 */
	public static function handle_save_settings() {
		self::guard( 'tmmt_save_settings' );
		// Passwords are not sanitized as text: spaces and symbols are allowed.
		$password = isset( $_POST['tools_password'] ) ? (string) wp_unslash( $_POST['tools_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $password ) {
			TMMT_Auth::set_password( $password );
		}
		self::back( admin_url( 'admin.php?page=tmmt-settings' ), __( 'Password changed.', 'tm-meeting-tools' ) );
	}

	/**
	 * Saves all role rows.
	 */
	public static function handle_save_roles() {
		self::guard( 'tmmt_save_roles' );
		$rows = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in TMMT_Roles.
		$err  = null;
		foreach ( $rows as $id => $raw ) {
			$data = self::role_from_post( (array) $raw );
			$res  = is_wp_error( $data ) ? $data : TMMT_Roles::update( (int) $id, $data );
			if ( is_wp_error( $res ) ) {
				$err = $res;
			}
		}
		self::back( admin_url( 'admin.php?page=tmmt-settings' ), $err ? $err : __( 'Agenda points saved.', 'tm-meeting-tools' ) );
	}

	/**
	 * Adds a role.
	 */
	public static function handle_add_role() {
		self::guard( 'tmmt_add_role' );
		$data = self::role_from_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in TMMT_Roles.
		$res  = is_wp_error( $data ) ? $data : TMMT_Roles::create( $data );
		self::back( admin_url( 'admin.php?page=tmmt-settings' ), is_wp_error( $res ) ? $res : __( 'Agenda point added.', 'tm-meeting-tools' ) );
	}

	/**
	 * Deletes a role.
	 */
	public static function handle_delete_role() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::guard( 'tmmt_delete_role_' . $id );
		$res = TMMT_Roles::delete( $id );
		self::back( admin_url( 'admin.php?page=tmmt-settings' ), is_wp_error( $res ) ? $res : __( 'Agenda point deleted.', 'tm-meeting-tools' ) );
	}
}
