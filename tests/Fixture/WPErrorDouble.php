<?php
declare( strict_types=1 );

/**
 * The minimum of `WP_Error` the unit suite needs.
 *
 * Not in a namespace, because the real class is global and the code under test refers to
 * `\WP_Error`. Loaded from the bootstrap, which asserts WordPress is NOT present -- so this
 * cannot shadow the real one.
 *
 * Deliberately small: only the three methods the code actually calls. A fuller double would
 * invite tests to assert behaviour WordPress does not guarantee, and the point of the unit suite
 * is to prove the logic is standalone, not to re-implement WordPress.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/** @var array<string, array<int, string>> */
		private array $errors = array();

		/** @var array<string, mixed> */
		private array $error_data = array();

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;

				if ( '' !== $data && array() !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return $codes[0] ?? '';
		}

		public function get_error_message( string $code = '' ): string {
			$code = '' !== $code ? $code : $this->get_error_code();

			return $this->errors[ $code ][0] ?? '';
		}

		/** @return mixed */
		public function get_error_data( string $code = '' ) {
			$code = '' !== $code ? $code : $this->get_error_code();

			return $this->error_data[ $code ] ?? null;
		}
	}
}
