<?php
/**
 * Seonix — live content scoring proxy.
 *
 * The editor panel needs an SEO + Readability score for the text currently in
 * the editor, which only the Seonix engine can produce (it owns the scoring
 * rules). This class is the server-side hop that makes that call: the browser
 * talks to our own REST route, and WordPress — not the browser — talks to the
 * engine with the sx_ Bearer key.
 *
 * That indirection is the whole point. Calling the engine straight from
 * editor-panel.js would ship the site's API key to every logged-in author's
 * browser, where a rogue extension or an XSS could lift it. The key stays on
 * the server, exactly like the outbound sync and task-pull paths.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side client for POST {engine}/api/plugin/score-content.
 */
class Seonix_Content_Score {

	/**
	 * Engine path. Auth: the plugin's own Bearer key (PluginAuth group on the
	 * backend, same as /sync, /tasks and /content-event).
	 */
	private const ENDPOINT = 'api/plugin/score-content';

	/**
	 * The engine bounds its own scoring pass at 5s; this leaves room for that
	 * plus transit without letting a stalled connection hold a PHP worker.
	 */
	private const TIMEOUT = 15;

	/**
	 * Mirrors maxScoreContentBytes in the backend handler (512 KiB). Enforced
	 * here too so an oversized body fails locally with a clear message instead
	 * of burning a round-trip to be rejected as a generic 400.
	 */
	public const MAX_CONTENT_BYTES = 524288;

	/**
	 * Score the submitted editor content against the Seonix engine.
	 *
	 * @param array<string,mixed> $input {
	 *     @type string $html_content     Required. Current editor body as HTML.
	 *     @type string $focus_keyphrase  Optional. Empty = keyphrase checks degrade to neutral.
	 *     @type string $title            Optional.
	 *     @type string $meta_description Optional.
	 *     @type string $slug             Optional.
	 *     @type string $language         Optional. Locale like "de_DE"; the engine normalizes it.
	 * }
	 * @return array<string,mixed>|WP_Error Decoded score payload, or WP_Error.
	 */
	public static function score( array $input ) {
		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();

		if ( empty( $engine_url ) ) {
			return new WP_Error( 'not_connected', __( 'Connect this site to Seonix first.', 'seonix' ), array( 'status' => 400 ) );
		}
		if ( ! Seonix_Sync::is_safe_url( $engine_url ) ) {
			return new WP_Error( 'invalid_engine_url', __( 'Configured engine URL is not allowed.', 'seonix' ), array( 'status' => 400 ) );
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'API key is not configured.', 'seonix' ), array( 'status' => 400 ) );
		}

		$html = isset( $input['html_content'] ) && is_string( $input['html_content'] ) ? $input['html_content'] : '';
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'empty_content', __( 'There is no content to score yet.', 'seonix' ), array( 'status' => 400 ) );
		}
		if ( strlen( $html ) > self::MAX_CONTENT_BYTES ) {
			return new WP_Error(
				'content_too_large',
				__( 'This content is too large for live scoring.', 'seonix' ),
				array( 'status' => 413 )
			);
		}

		$body = array(
			'html_content'     => $html,
			'focus_keyphrase'  => self::str( $input, 'focus_keyphrase' ),
			'title'            => self::str( $input, 'title' ),
			'meta_description' => self::str( $input, 'meta_description' ),
			'slug'             => self::str( $input, 'slug' ),
			'language'         => self::str( $input, 'language' ),
		);

		$response = wp_remote_post(
			trailingslashit( $engine_url ) . self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'score_failed',
				/* translators: %d: HTTP status code returned by the Seonix backend. */
				sprintf( __( 'Seonix returned HTTP %d.', 'seonix' ), $status ),
				array( 'status' => 502 )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		// The engine wraps success payloads as {"data": {...}} (pkg/response.JSON).
		// This differs from /api/plugin/tasks, which emits its TaskView verbatim —
		// unwrap here so the panel never has to know which convention applied.
		$payload = ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) )
			? $decoded['data']
			: $decoded;

		if ( ! is_array( $payload ) || ! isset( $payload['seo_score'] ) ) {
			return new WP_Error( 'bad_payload', __( 'Seonix returned an unreadable response.', 'seonix' ), array( 'status' => 502 ) );
		}

		return array(
			'seo_score'          => (int) $payload['seo_score'],
			// null, not 0, when the engine sends no readability score: the panel
			// renders a missing score as "—" but would render a literal 0 as a
			// red "your text is unreadable" gauge — inventing a verdict out of
			// an absent field.
			'readability_score'  => isset( $payload['readability_score'] ) ? (int) $payload['readability_score'] : null,
			'seo_checks'         => self::checks( $payload, 'seo_checks' ),
			'readability_checks' => self::checks( $payload, 'readability_checks' ),
			'summary'            => isset( $payload['summary'] ) && is_array( $payload['summary'] ) ? $payload['summary'] : array(),
		);
	}

	/**
	 * Read a string field from the input array, defaulting to ''.
	 *
	 * @param array<string,mixed> $input Input array.
	 * @param string              $key   Field name.
	 * @return string
	 */
	private static function str( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
	}

	/**
	 * Normalize one check list from the engine response.
	 *
	 * Only the fields the panel renders survive, so an engine-side addition can
	 * never inject unexpected keys into the payload handed to the browser.
	 *
	 * @param array<string,mixed> $payload Decoded engine payload.
	 * @param string              $key     Which list to read.
	 * @return array<int,array<string,mixed>>
	 */
	private static function checks( array $payload, string $key ): array {
		if ( ! isset( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $payload[ $key ] as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$out[] = array(
				'id'       => isset( $check['id'] ) ? (string) $check['id'] : '',
				'label'    => isset( $check['label'] ) ? (string) $check['label'] : '',
				'message'  => isset( $check['message'] ) ? (string) $check['message'] : '',
				'severity' => self::severity( $check ),
				'weight'   => isset( $check['weight'] ) ? (int) $check['weight'] : 0,
				'details'  => self::details( $check ),
			);
		}
		return $out;
	}

	/**
	 * Scalar values a check's message interpolates.
	 *
	 * The engine returns `message` as a TEMPLATE, with the numbers alongside it
	 * in `details` — "{count} words — too short", details.count = 148. The
	 * dashboard feeds details to its translator as variables; the panel does the
	 * same substitution in JS. Dropping details would print the braces to the
	 * author verbatim.
	 *
	 * Non-scalars are dropped: only scalars can be substituted into a string,
	 * and some checks attach large structures here (keywordNotUsed carries a
	 * list of conflicting articles) that the panel has no use for and no reason
	 * to ship to the browser.
	 *
	 * @param array<string,mixed> $check One check from the engine.
	 * @return array<string,scalar>
	 */
	private static function details( array $check ): array {
		if ( ! isset( $check['details'] ) || ! is_array( $check['details'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $check['details'] as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Bucket a check into error / warning / good.
	 *
	 * Prefers the engine's explicit `severity` and falls back to `status` (the
	 * two are equal today; the backend added `severity` precisely so this
	 * contract survives them diverging). Anything unrecognized degrades to
	 * "good" — matching the engine's own statusToSeverity — so an unknown value
	 * never lands in Problems and never reddens an eye spuriously.
	 *
	 * @param array<string,mixed> $check One check from the engine.
	 * @return string
	 */
	private static function severity( array $check ): string {
		$raw = '';
		if ( isset( $check['severity'] ) && is_string( $check['severity'] ) ) {
			$raw = $check['severity'];
		} elseif ( isset( $check['status'] ) && is_string( $check['status'] ) ) {
			$raw = $check['status'];
		}
		return in_array( $raw, array( 'error', 'warning', 'good' ), true ) ? $raw : 'good';
	}
}
