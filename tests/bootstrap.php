<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads composer autoload (Brain Monkey, Mockery) and defines WordPress constants
 * the plugin expects (ABSPATH, plugin-path constants). WordPress functions themselves
 * are mocked per-test via Brain\Monkey\Functions::when()/expect().
 */

namespace Seonix\Tests {
    /** In-memory transient store for unit tests. Reset on PHPUnit boot. */
    final class TransientStub {
        /** @var array<string,mixed> */
        public static array $store = array();
    }
}

namespace {

// Composer autoload.
require_once __DIR__ . '/../vendor/autoload.php';

// Lightweight WP class stubs (WP_REST_Request, WP_REST_Response, WP_Error, is_wp_error).
require_once __DIR__ . '/stubs/wp-rest-stubs.php';

// Yoast SEO public-API test doubles (WPSEO_Options, WPSEO_Taxonomy_Meta) +
// the WPSEO_VERSION "Yoast active" constant. The Yoast-dependent fix methods
// and the title-template reader talk only to these public surfaces.
require_once __DIR__ . '/Fakes/FakeYoast.php';

// WordPress constants the plugin checks before bailing out.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Plugin constants normally set by the bootstrapper in seonix.php.
if ( ! defined( 'SEONIX_VERSION' ) ) {
    define( 'SEONIX_VERSION', '2.0.0-test' );
}
if ( ! defined( 'SEONIX_FILE' ) ) {
    define( 'SEONIX_FILE', dirname( __DIR__ ) . '/seonix.php' );
}
if ( ! defined( 'SEONIX_DIR' ) ) {
    define( 'SEONIX_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SEONIX_URL' ) ) {
    define( 'SEONIX_URL', 'https://example.test/wp-content/plugins/seonix/' );
}

// WP fetch-mode constants used by $wpdb::get_row / get_results.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
    define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

// wp_json_encode is just json_encode with sane defaults in production.
// Defining a global stub here keeps us from pulling in WordPress for unit tests.
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, $options = 0, $depth = 512 ) {
        return json_encode( $value, $options, $depth );
    }
}

// wp_parse_url is essentially parse_url with sane component handling.
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

// wp_unslash strips wp_slash()'s extra backslashes from a value or recursively
// from an array. Brain Monkey can override per-test, but the production code
// path falls back to this stub so tests that don't mock it still get the
// canonical behaviour.
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }
        if ( is_string( $value ) ) {
            return stripslashes( $value );
        }
        return $value;
    }
}

// wp_slash is the exact inverse of wp_unslash — WordPress core applies it via
// addslashes semantics. wp_insert_post()/update_post_meta()/update_term_meta()
// all run wp_unslash() on their input, so a value read from the DB (or an
// already-unslashed REST param) MUST be wp_slash()'d before write or literal
// backslashes are silently stripped. The stub mirrors the wp_unslash stub above
// so tests that don't override it exercise the real slash/unslash round-trip.
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_slash', $value );
        }
        if ( is_string( $value ) ) {
            return addslashes( $value );
        }
        return $value;
    }
}

// Lightweight in-process transient store. The controller's rate-limiter calls
// get_transient/set_transient to gate noisy clients; tests that hit /apply via
// the controller would otherwise blow up with "undefined function". A static
// keyed-store keeps the same semantics for the duration of a test (no TTL
// honoured — tests don't need it).
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        $store = \Seonix\Tests\TransientStub::$store;
        return array_key_exists( $key, $store ) ? $store[ $key ] : false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration = 0 ) {
        \Seonix\Tests\TransientStub::$store[ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( \Seonix\Tests\TransientStub::$store[ $key ] );
        return true;
    }
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

} // namespace {
