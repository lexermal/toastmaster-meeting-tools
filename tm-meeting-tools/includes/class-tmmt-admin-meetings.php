<?php
/**
 * wp-admin: meetings list, meeting editor and agenda editor.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meetings admin.
 */
class TMMT_Admin_Meetings {

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'create_meeting', 'save_meeting', 'delete_meeting', 'save_agenda' ) as $action ) {
			add_action( 'admin_post_tmmt_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Page router.
	 */
	public static function page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$id = isset( $_GET['meeting'] ) ? absint( $_GET['meeting'] ) : 0;
		$meeting = $id ? TMMT_Meetings::get( $id ) : null;
		echo '<div class="wrap tmmt-admin">';
		if ( $meeting ) {
			self::edit_page( $meeting );
		} else {
			self::list_page();
		}
		echo '</div>';
	}

	/**
	 * Suggested date for a new meeting: two weeks after the latest one, otherwise the next Monday.
	 *
	 * @return string Y-m-d.
	 */
	private static function suggested_date() {
		$last = TMMT_Meetings::list_meetings( array( 'limit' => 1 ) );
		$tz   = wp_timezone();
		if ( $last ) {
			$d = new DateTimeImmutable( $last[0]['date'], $tz );
			return $d->modify( '+14 days' )->format( 'Y-m-d' );
		}
		return ( new DateTimeImmutable( 'next monday', $tz ) )->format( 'Y-m-d' );
	}

	/**
	 * List of meetings plus the "new meeting" form.
	 */
	private static function list_page() {
		$meetings = TMMT_Meetings::list_meetings( array( 'limit' => 200 ) );
		$statuses = self::status_labels();
		?>
		<h1><?php esc_html_e( 'Meetings', 'tm-meeting-tools' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Show a meeting on the site with the "Meeting agenda and tools" block or the [tm_meeting] shortcode.', 'tm-meeting-tools' ); ?></p>

		<h2><?php esc_html_e( 'New meeting', 'tm-meeting-tools' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmmt-inline-form">
			<input type="hidden" name="action" value="tmmt_create_meeting">
			<?php wp_nonce_field( 'tmmt_create_meeting' ); ?>
			<label><?php esc_html_e( 'Date', 'tm-meeting-tools' ); ?> <input type="date" name="date" required value="<?php echo esc_attr( self::suggested_date() ); ?>"></label>
			<label><?php esc_html_e( 'Start time', 'tm-meeting-tools' ); ?> <input type="time" name="time" value="18:00"></label>
			<label><?php esc_html_e( 'Theme', 'tm-meeting-tools' ); ?> <input type="text" name="theme"></label>
			<?php submit_button( __( 'Create meeting', 'tm-meeting-tools' ), 'primary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'All meetings', 'tm-meeting-tools' ); ?></h2>
		<?php if ( ! $meetings ) : ?>
			<p><?php esc_html_e( 'No meetings yet.', 'tm-meeting-tools' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Date', 'tm-meeting-tools' ); ?></th>
					<th><?php esc_html_e( 'Theme', 'tm-meeting-tools' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tm-meeting-tools' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'tm-meeting-tools' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $meetings as $m ) : ?>
					<?php
					$edit = admin_url( 'admin.php?page=tmmt-meetings&meeting=' . $m['id'] );
					$res  = admin_url( 'admin.php?page=tmmt-feedback&tm_meeting=' . $m['id'] );
					$del  = wp_nonce_url( admin_url( 'admin-post.php?action=tmmt_delete_meeting&id=' . $m['id'] ), 'tmmt_delete_meeting_' . $m['id'] );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $res ); ?>"><strong><?php echo esc_html( $m['date'] . ' ' . $m['time'] ); ?></strong></a></td>
						<td><?php echo esc_html( $m['theme'] ); ?></td>
						<td><?php echo esc_html( $statuses[ $m['status'] ] ); ?></td>
						<td>
							<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'tm-meeting-tools' ); ?></a> |
							<a class="tmmt-delete" href="<?php echo esc_url( $del ); ?>" onclick="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Delete this meeting with its agenda, timings and feedback?', 'tm-meeting-tools' ); ?>"><?php esc_html_e( 'Delete', 'tm-meeting-tools' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Status labels.
	 *
	 * @return array<string,string>
	 */
	private static function status_labels() {
		return array(
			'planned'     => __( 'Planned', 'tm-meeting-tools' ),
			'in_progress' => __( 'In progress', 'tm-meeting-tools' ),
			'completed'   => __( 'Completed', 'tm-meeting-tools' ),
		);
	}

	/**
	 * Meeting and agenda editor.
	 *
	 * @param array $m Meeting with agenda.
	 */
	private static function edit_page( $m ) {
		$roles   = TMMT_Roles::all();
		$members = wp_list_pluck( TMMT_Members::all(), 'name' );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=tmmt-meetings' ) ); ?>"><?php esc_html_e( 'Back to all meetings', 'tm-meeting-tools' ); ?></a></p>
		<h1><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Meeting %s', 'tm-meeting-tools' ), $m['date'] ) ); ?></h1>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmmt-inline-form">
			<input type="hidden" name="action" value="tmmt_save_meeting">
			<input type="hidden" name="id" value="<?php echo esc_attr( $m['id'] ); ?>">
			<?php wp_nonce_field( 'tmmt_save_meeting_' . $m['id'] ); ?>
			<label><?php esc_html_e( 'Date', 'tm-meeting-tools' ); ?> <input type="date" name="date" required value="<?php echo esc_attr( $m['date'] ); ?>"></label>
			<label><?php esc_html_e( 'Start time', 'tm-meeting-tools' ); ?> <input type="time" name="time" value="<?php echo esc_attr( $m['time'] ); ?>"></label>
			<label><?php esc_html_e( 'Theme', 'tm-meeting-tools' ); ?> <input type="text" name="theme" value="<?php echo esc_attr( $m['theme'] ); ?>"></label>
			<label><?php esc_html_e( 'Status', 'tm-meeting-tools' ); ?>
				<select name="status">
					<?php foreach ( self::status_labels() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( __( 'Save meeting', 'tm-meeting-tools' ), 'primary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Agenda', 'tm-meeting-tools' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tmmt_save_agenda">
			<input type="hidden" name="meeting_id" value="<?php echo esc_attr( $m['id'] ); ?>">
			<?php wp_nonce_field( 'tmmt_save_agenda_' . $m['id'] ); ?>
			<?php // Pressing Enter submits the first submit button in the form; without this it would be a "Remove" button. ?>
			<button type="submit" class="tmmt-default-submit" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Save agenda', 'tm-meeting-tools' ); ?></button>
			<div class="tmmt-table-wrap">
			<table class="widefat striped tmmt-agenda-admin">
				<thead><tr>
					<th class="tmmt-drag-col"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'tm-meeting-tools' ); ?></span></th>
					<th><?php esc_html_e( 'Role', 'tm-meeting-tools' ); ?></th>
					<th><?php esc_html_e( 'Person', 'tm-meeting-tools' ); ?></th>
					<th><?php esc_html_e( 'Speech title', 'tm-meeting-tools' ); ?></th>
					<th><?php esc_html_e( 'Green / Yellow / Red (min)', 'tm-meeting-tools' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tm-meeting-tools' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $m['agenda'] as $it ) : ?>
					<?php
					$n      = 'items[' . $it['id'] . ']';
					$custom = 'custom' === $it['timing_source'];
					?>
					<tr>
						<td class="tmmt-drag-col">
							<span class="tmmt-drag dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to change the order', 'tm-meeting-tools' ); ?>" aria-hidden="true"></span>
							<?php // Rows are posted in their on-screen order, which becomes the agenda order. ?>
							<input type="hidden" name="order[]" value="<?php echo esc_attr( $it['id'] ); ?>">
						</td>
						<td><?php self::role_select( $n . '[role_type_id]', $it['role_type_id'], $roles ); ?></td>
						<td><?php self::person_select( $n . '[person_name]', $it['person_name'], $members ); ?></td>
						<td>
							<?php if ( 'speech' === $it['category'] ) : ?>
								<input type="text" name="<?php echo esc_attr( $n ); ?>[speech_title]" value="<?php echo esc_attr( $it['speech_title'] ); ?>" aria-label="<?php esc_attr_e( 'Speech title', 'tm-meeting-tools' ); ?>">
							<?php endif; ?>
						</td>
						<td class="tmmt-times">
							<?php foreach ( array( 'green_sec', 'yellow_sec', 'red_sec' ) as $k ) : ?>
								<?php // Empty field = the agenda point's time, shown as the placeholder. ?>
								<input type="text" class="tmmt-time" name="<?php echo esc_attr( $n . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( $custom ? tmmt_format_time( $it[ $k ] ) : '' ); ?>" placeholder="<?php echo esc_attr( $it['timed'] || $custom ? tmmt_format_time( $it[ $k ] ) : '-' ); ?>" aria-label="<?php echo esc_attr( $k ); ?>">
							<?php endforeach; ?>
						</td>
						<td><button type="submit" class="button-link tmmt-delete" name="remove" value="<?php echo esc_attr( $it['id'] ); ?>"><?php esc_html_e( 'Remove', 'tm-meeting-tools' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
					<tr class="tmmt-new-row">
						<td><strong><?php esc_html_e( 'New', 'tm-meeting-tools' ); ?></strong></td>
						<td><?php self::role_select( 'new[role_type_id]', 0, $roles, true ); ?></td>
						<td><?php self::person_select( 'new[person_name]', '', $members ); ?></td>
						<?php // Only speakers have a speech title; shown once a speech role is picked (script below). ?>
						<td><input type="text" name="new[speech_title]" class="tmmt-speech-only" hidden aria-label="<?php esc_attr_e( 'Speech title', 'tm-meeting-tools' ); ?>"></td>
						<?php // Times and Reset show once a role is picked; the placeholders are that role's times. ?>
						<td class="tmmt-times tmmt-role-only" hidden>
							<?php foreach ( array( 'green_sec', 'yellow_sec', 'red_sec' ) as $k ) : ?>
								<input type="text" class="tmmt-time" name="<?php echo esc_attr( 'new[' . $k . ']' ); ?>" data-time="<?php echo esc_attr( $k ); ?>" aria-label="<?php echo esc_attr( $k ); ?>">
							<?php endforeach; ?>
						</td>
						<td class="tmmt-role-only" hidden><button type="button" class="button-link tmmt-delete tmmt-new-reset"><?php esc_html_e( 'Reset', 'tm-meeting-tools' ); ?></button></td>
					</tr>
				</tbody>
			</table>
			</div>
			<?php submit_button( __( 'Save agenda', 'tm-meeting-tools' ) ); ?>
		</form>
		<script>
			( function () {
				const row = document.querySelector( '.tmmt-new-row' );
				const role = row.querySelector( 'select' );
				const sync = () => {
					const opt = role.selectedOptions[ 0 ];
					const picked = !! ( opt && opt.value );
					row.querySelector( '.tmmt-speech-only' ).hidden = ! picked || opt.dataset.category !== 'speech';
					row.querySelectorAll( '.tmmt-role-only' ).forEach( ( el ) => ( el.hidden = ! picked ) );
					row.querySelectorAll( '[data-time]' ).forEach( ( el ) => ( el.placeholder = picked ? opt.dataset[ el.dataset.time.replace( '_sec', '' ) ] : '' ) );
				};
				role.addEventListener( 'change', sync );
				row.querySelector( '.tmmt-new-reset' ).addEventListener( 'click', () => {
					row.querySelectorAll( 'input, select' ).forEach( ( el ) => ( el.value = '' ) );
					sync();
				} );
			} )();
		</script>
		<?php
	}

	/**
	 * Person select, filled from the member list. A name that is not (or no longer) a member, such as a
	 * guest added in the timer, stays selectable so saving does not drop it.
	 *
	 * @param string   $name    Field name.
	 * @param string   $current Current person.
	 * @param string[] $members Member names.
	 */
	private static function person_select( $name, $current, $members ) {
		echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr__( 'Person', 'tm-meeting-tools' ) . '">';
		echo '<option value="">' . esc_html__( 'Nobody yet', 'tm-meeting-tools' ) . '</option>';
		if ( '' !== $current && ! in_array( $current, $members, true ) ) {
			/* translators: %s: person name. */
			echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( sprintf( __( '%s (not a member)', 'tm-meeting-tools' ), $current ) ) . '</option>';
		}
		foreach ( $members as $m ) {
			echo '<option value="' . esc_attr( $m ) . '" ' . selected( $current, $m, false ) . '>' . esc_html( $m ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Role select.
	 *
	 * @param string $name    Field name.
	 * @param int    $current Selected role.
	 * @param array  $roles   Roles.
	 * @param bool   $empty   Add an empty first option.
	 */
	private static function role_select( $name, $current, $roles, $empty = false ) {
		echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr__( 'Role', 'tm-meeting-tools' ) . '">';
		if ( $empty ) {
			echo '<option value="">' . esc_html__( 'Add a role...', 'tm-meeting-tools' ) . '</option>';
		}
		foreach ( $roles as $r ) {
			$times = $r['timed'] ? array( tmmt_format_time( $r['green_sec'] ), tmmt_format_time( $r['yellow_sec'] ), tmmt_format_time( $r['red_sec'] ) ) : array( '-', '-', '-' );
			echo '<option value="' . esc_attr( $r['id'] ) . '" data-category="' . esc_attr( $r['category'] ) . '" data-green="' . esc_attr( $times[0] ) . '" data-yellow="' . esc_attr( $times[1] ) . '" data-red="' . esc_attr( $times[2] ) . '" ' . selected( (int) $current, $r['id'], false ) . '>' . esc_html( $r['name'] ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Agenda row from the form into ability-style fields.
	 *
	 * @param array      $raw     Posted row.
	 * @param array|null $current The stored item, to fill times left empty when only some were changed.
	 * @return array|WP_Error
	 */
	private static function item_from_post( $raw, $current = null ) {
		$out = array(
			'role_type_id' => isset( $raw['role_type_id'] ) ? (int) $raw['role_type_id'] : 0,
			'person_name'  => isset( $raw['person_name'] ) ? $raw['person_name'] : '',
		);
		// Guests are marked in the timer, not here: leave is_guest alone.
		// Only speakers have a title field; leave other rows' stored title alone.
		if ( isset( $raw['speech_title'] ) ) {
			$out['speech_title'] = $raw['speech_title'];
		}
		if ( array_key_exists( 'green_sec', $raw ) ) {
			foreach ( array( 'green_sec', 'yellow_sec', 'red_sec' ) as $k ) {
				$v = isset( $raw[ $k ] ) ? trim( (string) $raw[ $k ] ) : '';
				if ( '' === $v || '-' === $v ) {
					$out[ $k ] = null;
					continue;
				}
				$sec = tmmt_parse_time( $v );
				if ( null === $sec ) {
					return new WP_Error( 'tmmt_invalid', __( 'Please enter times as minutes:seconds, for example 5:00.', 'tm-meeting-tools' ) );
				}
				$out[ $k ] = $sec;
			}
			// The empty fields show the agenda point's times as placeholders; changing one time keeps the others.
			$set = array_filter( array( $out['green_sec'], $out['yellow_sec'], $out['red_sec'] ), 'is_int' );
			if ( $set && count( $set ) < 3 && $current ) {
				foreach ( array( 'green_sec', 'yellow_sec', 'red_sec' ) as $k ) {
					if ( null === $out[ $k ] ) {
						$out[ $k ] = (int) $current[ $k ];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Creates a meeting.
	 */
	public static function handle_create_meeting() {
		TMMT_Admin::guard( 'tmmt_create_meeting' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in TMMT_Meetings.
		$res  = TMMT_Meetings::create(
			array(
				'date'        => isset( $post['date'] ) ? sanitize_text_field( $post['date'] ) : '',
				'time'        => isset( $post['time'] ) ? sanitize_text_field( $post['time'] ) : '',
				'theme'       => isset( $post['theme'] ) ? $post['theme'] : '',
				// Every new meeting starts from the standard agenda. The Word of the Day is set by the Ah-Counter at the meeting.
				'template'    => 'standard',
			)
		);
		if ( is_wp_error( $res ) ) {
			TMMT_Admin::back( admin_url( 'admin.php?page=tmmt-meetings' ), $res );
		}
		// No notice: landing on the new meeting's page shows it worked.
		TMMT_Admin::back( admin_url( 'admin.php?page=tmmt-meetings&meeting=' . $res['id'] ), null );
	}

	/**
	 * Saves meeting fields.
	 */
	public static function handle_save_meeting() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		TMMT_Admin::guard( 'tmmt_save_meeting_' . $id );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in TMMT_Meetings.
		$res  = TMMT_Meetings::update(
			$id,
			array(
				'date'        => isset( $post['date'] ) ? sanitize_text_field( $post['date'] ) : '',
				'time'        => isset( $post['time'] ) ? sanitize_text_field( $post['time'] ) : '',
				'theme'       => isset( $post['theme'] ) ? $post['theme'] : '',
				'status'      => isset( $post['status'] ) ? sanitize_key( $post['status'] ) : 'planned',
			)
		);
		TMMT_Admin::back( admin_url( 'admin.php?page=tmmt-meetings&meeting=' . $id ), is_wp_error( $res ) ? $res : __( 'Meeting saved.', 'tm-meeting-tools' ) );
	}

	/**
	 * Deletes a meeting.
	 */
	public static function handle_delete_meeting() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		TMMT_Admin::guard( 'tmmt_delete_meeting_' . $id );
		$res = TMMT_Meetings::delete( $id );
		TMMT_Admin::back( admin_url( 'admin.php?page=tmmt-meetings' ), is_wp_error( $res ) ? $res : __( 'Meeting deleted.', 'tm-meeting-tools' ) );
	}

	/**
	 * Saves the agenda form: all rows, then add, move or remove.
	 */
	public static function handle_save_agenda() {
		$meeting_id = isset( $_POST['meeting_id'] ) ? absint( $_POST['meeting_id'] ) : 0;
		TMMT_Admin::guard( 'tmmt_save_agenda_' . $meeting_id );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in TMMT_Meetings.
		$url  = admin_url( 'admin.php?page=tmmt-meetings&meeting=' . $meeting_id );
		$err  = null;

		$rows = isset( $post['items'] ) && is_array( $post['items'] ) ? $post['items'] : array();
		foreach ( $rows as $id => $raw ) {
			$data = self::item_from_post( (array) $raw, TMMT_Meetings::get_item( (int) $id ) );
			$res  = is_wp_error( $data ) ? $data : TMMT_Meetings::update_item( (int) $id, $data );
			if ( is_wp_error( $res ) ) {
				$err = $res;
			}
		}

		// Before adding or removing, so the posted order still lists exactly the current items.
		if ( ! empty( $post['order'] ) && is_array( $post['order'] ) ) {
			$res = TMMT_Meetings::reorder( $meeting_id, $post['order'] );
			if ( is_wp_error( $res ) ) {
				$err = $res;
			}
		}

		if ( ! empty( $post['new']['role_type_id'] ) ) {
			// The role's times fill any of the three left empty.
			$data = self::item_from_post( (array) $post['new'], TMMT_Roles::get( (int) $post['new']['role_type_id'] ) );
			$res  = is_wp_error( $data ) ? $data : TMMT_Meetings::add_item( $meeting_id, $data );
			if ( is_wp_error( $res ) ) {
				$err = $res;
			}
		}

		if ( ! empty( $post['remove'] ) ) {
			TMMT_Meetings::remove_item( absint( $post['remove'] ) );
		}

		TMMT_Admin::back( $url, $err ? $err : __( 'Agenda saved.', 'tm-meeting-tools' ) );
	}
}
