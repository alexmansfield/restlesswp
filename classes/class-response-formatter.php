<?php
/**
 * Response Formatter — wraps all REST responses in a consistent envelope.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats REST API responses into a standard envelope structure.
 *
 * Success: { "success": true, "data": { ... } }
 * Error:   { "success": false, "code": "...", "message": "..." }
 */
class RestlessWP_Response_Formatter {

	/**
	 * Standard error codes and their default HTTP statuses.
	 *
	 * @var array<string, int>
	 */
	private const ERROR_STATUS_MAP = array(
		'module_inactive'     => 424,
		'version_unsupported' => 424,
		'not_found'           => 404,
		'conflict'            => 409,
		'forbidden'           => 403,
		'validation_error'    => 400,
	);

	/**
	 * Default messages for standard error codes.
	 *
	 * @return array<string, string>
	 */
	private static function default_messages(): array {
		return array(
			'module_inactive'     => __( 'The target plugin is not active on this site.', 'restlesswp' ),
			'version_unsupported' => __( 'The target plugin version is not supported.', 'restlesswp' ),
			'not_found'           => __( 'The requested resource was not found.', 'restlesswp' ),
			'conflict'            => __( 'A conflicting resource already exists.', 'restlesswp' ),
			'forbidden'           => __( 'You do not have permission to perform this action.', 'restlesswp' ),
			'validation_error'    => __( 'The provided input failed validation.', 'restlesswp' ),
		);
	}

	/**
	 * Return a success response.
	 *
	 * @param array|object $data   Response payload.
	 * @param int          $status HTTP status code. Default 200.
	 *
	 * @return WP_REST_Response
	 */
	public static function success( $data = array(), int $status = 200 ): WP_REST_Response {
		$envelope = array(
			'success' => true,
			'data'    => $data,
		);

		return new WP_REST_Response( $envelope, $status );
	}

	/**
	 * Return an error response.
	 *
	 * @param string      $code    Error code (e.g. 'not_found', 'forbidden').
	 * @param string|null $message Human-readable message. Falls back to default for known codes.
	 * @param int|null     $status  HTTP status code. Falls back to map for known codes, else 400.
	 *
	 * @return WP_REST_Response
	 */
	public static function error( string $code, ?string $message = null, ?int $status = null ): WP_REST_Response {
		if ( null === $message ) {
			$defaults = self::default_messages();
			$message  = $defaults[ $code ] ?? __( 'An unexpected error occurred.', 'restlesswp' );
		}

		if ( null === $status ) {
			$status = self::ERROR_STATUS_MAP[ $code ] ?? 400;
		}

		$envelope = array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
		);

		return new WP_REST_Response( $envelope, $status );
	}
}
