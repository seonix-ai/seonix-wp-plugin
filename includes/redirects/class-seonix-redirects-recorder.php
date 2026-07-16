<?php
/**
 * Records a 404 the moment WordPress decides to serve one.
 *
 * Runs on template_redirect at a very late priority — AFTER the redirect runner
 * (priority 1) has had its chance, so a request a rule already caught never
 * reaches here, and after the main query, so is_404() is settled. Only real
 * front-end GET page-misses are logged; the log store drops assets and junk.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Recorder {

	/** @var Seonix_Redirects_Log */
	private $log;

	public function __construct( Seonix_Redirects_Log $log ) {
		$this->log = $log;
	}

	public function register(): void {
		// 1000: after the runner (1) and after any theme/plugin redirect on
		// template_redirect, so we only record misses nobody handled.
		add_action( 'template_redirect', array( $this, 'capture' ), 1000 );
	}

	/**
	 * Log the current request if it is a genuine, followable page miss.
	 */
	public function capture(): void {
		if ( ! is_404() ) {
			return;
		}
		// Only GET: a visitor (or crawler) following a dead link. POST/HEAD 404s
		// are not links to redirect.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}
		// Feeds and other non-page requests are not redirect candidates.
		if ( is_feed() || is_trackback() || is_comment_feed() ) {
			return;
		}
		// A filter so a site can opt a request out of the log entirely.
		if ( ! apply_filters( 'seonix_log_404', true ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized (parsed, decoded, capped) inside Seonix_Redirects_Log::record().
		$this->log->record( (string) $uri );
	}
}
