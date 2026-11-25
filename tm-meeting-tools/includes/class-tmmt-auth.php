<?php
/**
 * Shared tools password (cookie based) and simple rate limiting.
 *
 * Role holders are not WordPress users, so the tools are unlocked with one
 * shared password. The cookie holds an HMAC of the stored password hash, so
 * changing the password logs out every phone at once.
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access checks.
 */
class TMMT_Auth {

	const COOKIE        = 'tmmt_tools';
	const PASSWORD_OPT  = 'tmmt_tools_password_hash';
	const COOKIE_DAYS   = 14;

	/**
	 * Admin (manage capability) check. Signature accepts the ability input.
	 *
	 * @param mixed $input Unused.
	 * @return bool
	 */
	public static function can_manage( $input = null ) {
		unset( $input );
		return current_user_can( tmmt_manage_cap() );
	}

	/**
	 * Tools access: admins always, otherwise a valid tools cookie.
	 *
	 * @param mixed $input Unused.
	 * @return bool
	 */
	public static function has_tools_access( $input = null ) {
		unset( $input );
		if ( self::can_manage() ) {
			return true;
		}
		$hash = get_option( self::PASSWORD_OPT, '' );
		if ( ! $hash ) {
			return false;
		}
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return '' !== $cookie && hash_equals( self::token( $hash ), $cookie );
	}

	/**
	 * Whether a tools password has been configured.
	 *
	 * @return bool
	 */
	public static function has_password() {
		return (bool) get_option( self::PASSWORD_OPT, '' );
	}

	/**
	 * Stores a new tools password (hashed).
	 *
	 * @param string $password Plain password.
	 */
	public static function set_password( $password ) {
		update_option( self::PASSWORD_OPT, wp_hash_password( $password ), false );
	}

	/**
	 * Cookie token for a password hash.
	 *
	 * @param string $hash Stored password hash.
	 * @return string
	 */
	private static function token( $hash ) {
		return hash_hmac( 'sha256', 'tmmt-tools|' . $hash, wp_salt( 'auth' ) );
	}

	/**
	 * Checks the password and sets the cookie.
	 *
	 * @param string $password Plain password.
	 * @return true|WP_Error
	 */
	public static function unlock( $password ) {
		// Only wrong passwords count: the whole club often shares the venue's Wi-Fi IP.
		if ( self::rate_limited( 'unlock', 10, 10 * MINUTE_IN_SECONDS, false ) ) {
			return new WP_Error( 'tmmt_rate_limited', __( 'Too many attempts. Please wait a few minutes.', 'tm-meeting-tools' ), array( 'status' => 429 ) );
		}
		$hash = get_option( self::PASSWORD_OPT, '' );
		if ( ! $hash ) {
			return new WP_Error( 'tmmt_no_password', __( 'No tools password is set yet. Ask an admin to set one in the plugin settings.', 'tm-meeting-tools' ), array( 'status' => 400 ) );
		}
		if ( ! wp_check_password( (string) $password, $hash ) ) {
			self::rate_limited( 'unlock', 10, 10 * MINUTE_IN_SECONDS );
			return new WP_Error( 'tmmt_wrong_password', __( 'Wrong password.', 'tm-meeting-tools' ), array( 'status' => 403 ) );
		}
		$token = self::token( $hash );
		self::send_cookie( $token, time() + self::COOKIE_DAYS * DAY_IN_SECONDS );
		// Make the rest of this request see the new access too.
		$_COOKIE[ self::COOKIE ] = $token;
		return true;
	}

	/**
	 * Removes the tools cookie.
	 */
	public static function lock() {
		self::send_cookie( '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Sends the cookie when headers are still open (not the case under WP-CLI).
	 *
	 * @param string $value   Value.
	 * @param int    $expires Expiry timestamp.
	 */
	private static function send_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Counts a hit for this client and tells whether the limit is exceeded.
	 * Stores only a salted hash of the IP, never the IP itself.
	 *
	 * @param string $bucket Name of the limit.
	 * @param int    $max    Allowed hits per window.
	 * @param int    $window Window in seconds.
	 * @param bool   $count  Count this call as a hit (false = only check).
	 * @return bool True when limited.
	 */
	public static function rate_limited( $bucket, $max, $window, $count = true ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'tmmt_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return true;
		}
		if ( $count ) {
			set_transient( $key, $hits + 1, $window );
		}
		return false;
	}
}
