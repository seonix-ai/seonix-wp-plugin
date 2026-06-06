<?php
/**
 * Authentication for Seonix.
 *
 * Manages API key generation, storage, and request validation.
 * The API key is generated on plugin activation and stored in wp_options.
 * It is used by the Seonix backend to authenticate REST API requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Auth {

	const OPTION_API_KEY   = 'seonix_api_key';
	const OPTION_CONNECTED = 'seonix_connected';

	/**
	 * Generate a new API key and store it.
	 *
	 * Format: sx_ + 64 hex characters (32 random bytes).
	 * Legacy keys with the ce_ prefix from the previous "Content Engine Connector"
	 * plugin remain valid because validation compares the full opaque token, not the prefix.
	 *
	 * @return string The generated API key.
	 */
	public static function generate_key() {
		$key = 'sx_' . bin2hex( random_bytes( 32 ) );
		// autoload=false: the API key is only read on REST/admin requests, never
		// on every page load, so keep it out of the wp_load_alloptions() cache.
		update_option( self::OPTION_API_KEY, $key, false );
		return $key;
	}

	/**
	 * Get the current API key.
	 *
	 * @return string The API key, or empty string if not set.
	 */
	public static function get_key() {
		return get_option( self::OPTION_API_KEY, '' );
	}

	/**
	 * Validate an incoming REST API request.
	 *
	 * Accepts the API key via:
	 *   - X-Seonix-Key header
	 *   - X-CE-Key header (legacy, for callers from the Content Engine Connector era)
	 *   - Authorization: Bearer <key> header
	 *
	 * Used as a permission_callback for REST routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_request( WP_REST_Request $request ) {
		$stored_key = self::get_key();

		if ( empty( $stored_key ) ) {
			return new WP_Error(
				'no_api_key',
				'API key has not been configured. Reactivate the plugin.',
				array( 'status' => 500 )
			);
		}

		// Try X-Seonix-Key header first.
		$provided_key = $request->get_header( 'X-Seonix-Key' );

		// Legacy: X-CE-Key header from Content Engine Connector clients.
		if ( empty( $provided_key ) ) {
			$provided_key = $request->get_header( 'X-CE-Key' );
		}

		// Fall back to Authorization: Bearer header.
		if ( empty( $provided_key ) ) {
			$auth_header = $request->get_header( 'Authorization' );
			if ( ! empty( $auth_header ) && 0 === strpos( $auth_header, 'Bearer ' ) ) {
				$provided_key = substr( $auth_header, 7 );
			}
		}

		if ( empty( $provided_key ) ) {
			return new WP_Error(
				'missing_auth',
				'Authentication required. Provide an X-Seonix-Key or Authorization: Bearer header.',
				array( 'status' => 401 )
			);
		}

		// Timing-safe comparison.
		if ( ! hash_equals( $stored_key, $provided_key ) ) {
			return new WP_Error(
				'invalid_key',
				'Invalid API key.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Check if the site is currently connected to Seonix.
	 *
	 * A site is considered connected when the Seonix backend has successfully
	 * called the /verify endpoint and the admin has configured the engine URL.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return (bool) get_option( self::OPTION_CONNECTED, false );
	}

	/**
	 * Single source of truth for SSRF URL validation.
	 *
	 * Both Seonix_Sync::is_safe_url() and Seonix_REST_API::is_safe_url() delegate
	 * here so the loopback/private-range guard is never duplicated (and can never
	 * drift between the two callers again — which is exactly how the IPv6 gap crept
	 * into the sync path).
	 *
	 * Fail-closed: if DNS does not resolve, the URL is rejected.
	 *   - Only http/https schemes are allowed.
	 *   - `localhost` / `*.localhost` / `*.local` / `0.0.0.0` are blocked by name
	 *     in case DNS is poisoned to return a public address.
	 *   - Every A record (gethostbynamel) is checked so a multi-record host with
	 *     one private IPv4 cannot slip through.
	 *   - Every AAAA record (dns_get_record) is checked too, so a host that
	 *     resolves to a private/loopback IPv6 (`::1`, `fc00::/7`, `fe80::/10`)
	 *     cannot bypass the IPv4 guard. dns_get_record may be unavailable on some
	 *     hosts — when it is, the IPv4 result still applies (no regression).
	 *
	 * FILTER_FLAG_NO_PRIV_RANGE blocks 10.0.0.0/8, 172.16/12, 192.168/16, fd00::/8.
	 * FILTER_FLAG_NO_RES_RANGE blocks 0.0.0.0/8, 127/8, 169.254/16, ::1, multicast, etc.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if safe to fetch, false otherwise.
	 */
	public static function is_safe_url( $url ) {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = strtolower( $parsed['host'] );

		// Reject localhost / *.localhost / *.local explicitly.
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain', '0.0.0.0' ), true ) ) {
			return false;
		}
		if ( substr( $host, -10 ) === '.localhost' || substr( $host, -6 ) === '.local' ) {
			return false;
		}

		// Resolve all IPv4 A records (gethostbynamel returns array; gethostbyname
		// returns first or hostname-on-failure).
		$ips = gethostbynamel( $host );
		if ( $ips === false || empty( $ips ) ) {
			// DNS failure → fail-closed (do not trust).
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		// IPv6: gethostbynamel returns IPv4 only. Cover AAAA records too, otherwise
		// a host with `::1` / `fe80::*` / `fc00::/7` bypasses the guard above.
		// dns_get_record may not be available on all hosts — when it isn't, behave
		// as before (IPv4 was already validated).
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- silence DNS lookup warnings; failure is handled by fail-closed IPv4 check above.
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $rec ) {
					if ( empty( $rec['ipv6'] ) ) {
						continue;
					}
					if ( ! filter_var( $rec['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}
}
