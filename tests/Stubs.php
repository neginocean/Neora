<?php
/**
 * WordPress stubs/polyfills for isolated PHPUnit runs.
 *
 * Loaded after Patchwork so functions declared here remain redefinable.
 *
 * @package NeoraStudio\NeoraCore\Tests
 */

declare(strict_types=1);

$wp_error_file = dirname( __DIR__, 4 ) . '/wp-includes/class-wp-error.php';

if ( ! class_exists( 'WP_Error' ) ) {
	if ( is_file( $wp_error_file ) ) {
		require_once $wp_error_file;
	} else {
		/**
		 * Minimal WP_Error polyfill when WordPress core is absent.
		 */
		class WP_Error {
			private string $code;
			private string $message;
			private mixed $data;

			public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check whether variable is a WP_Error instance.
	 *
	 * @param mixed $thing Check target.
	 * @return bool
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request polyfill for isolated unit/integration tests.
	 */
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params = [];
		/** @var array<string, string> */
		private array $headers = [];
		private string $route = '';

		/**
		 * @param array<string, mixed> $params Request parameters.
		 */
		public function __construct( array $params = [], string $route = '', array $headers = [] ) {
			$this->params  = $params;
			$this->route   = $route;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_header( string $key ): ?string {
			$normalized = strtolower( $key );
			return $this->headers[ $normalized ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response polyfill for isolated unit/integration tests.
	 */
	class WP_REST_Response {
		private mixed $data;
		private int $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}