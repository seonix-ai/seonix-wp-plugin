<?php
/**
 * Minimal stand-ins for the WP REST classes our controllers depend on.
 *
 * Just enough surface for unit tests to wire request/response objects without
 * pulling in WordPress. Real WP loads its own definitions long before any of
 * our code runs in production, so these stubs are test-only.
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params;
        private array $headers;

        public function __construct( array $params = array(), array $headers = array() ) {
            $this->params  = $params;
            $this->headers = $headers;
        }

        public function get_param( string $key ) {
            return $this->params[ $key ] ?? null;
        }

        public function get_json_params(): array {
            return $this->params;
        }

        public function get_header( string $name ) {
            return $this->headers[ strtolower( $name ) ] ?? '';
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private $data;
        private int $status;

        public function __construct( $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public string $code;
        public string $message;
        public array $data;

        public function __construct( string $code = '', string $message = '', $data = array() ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = is_array( $data ) ? $data : array();
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

/**
 * WP_Post stand-in.
 *
 * Only the fields our code reads. Production code type-checks with
 * `instanceof WP_Post` (get_post() returns null for a missing id), so without
 * this class those checks are silently false in tests and every guarded branch
 * looks dead — a test would "pass" while exercising nothing.
 */
if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public int $ID = 0;
        public string $post_name = '';
        public string $post_title = '';
        public string $post_status = 'publish';
        public string $post_content = '';
        public string $post_modified_gmt = '';

        /**
         * @param array<string,mixed> $fields Field values to seed.
         */
        public function __construct( array $fields = array() ) {
            foreach ( $fields as $key => $value ) {
                if ( property_exists( $this, $key ) ) {
                    $this->$key = $value;
                }
            }
        }
    }
}
