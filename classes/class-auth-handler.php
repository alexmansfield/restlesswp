<?php
/**
 * Auth Handler — capability-mirroring permission callbacks for REST routes.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides permission_callback closures that mirror WordPress capabilities.
 *
 * Each closure checks whether the current user is authenticated and holds
 * the capability declared by the module controller. No custom permission
 * filters — if the underlying plugin requires `manage_options` in wp-admin,
 * the REST endpoint requires the same.
 */
class RestlessWP_Auth_Handler {

	/**
	 * Returns a permission_callback closure for the given capability.
	 *
	 * @param string $capability WordPress capability string (e.g. 'manage_options').
	 * @return \Closure Closure suitable for use as a REST route permission_callback.
	 */
	public function permission_callback( string $capability ): \Closure {
		return function () use ( $capability ) {
			return $this->check_permission( $capability );
		};
	}

	/**
	 * Checks authentication and capability for the current request.
	 *
	 * @param string $capability WordPress capability to check.
	 * @return true|\WP_Error True if allowed, WP_Error otherwise.
	 */
	private function check_permission( string $capability ) {
		if ( ! is_user_logged_in() ) {
			return $this->unauthorized_error();
		}

		if ( ! current_user_can( $capability ) ) {
			return $this->forbidden_error();
		}

		return true;
	}

	/**
	 * Returns a 401 WP_Error for unauthenticated requests.
	 *
	 * @return \WP_Error
	 */
	private function unauthorized_error(): \WP_Error {
		return new \WP_Error(
			'restlesswp_not_authenticated',
			__( 'Authentication is required to access this endpoint.', 'restlesswp' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Returns a 403 WP_Error for users lacking the required capability.
	 *
	 * @return \WP_Error
	 */
	private function forbidden_error(): \WP_Error {
		return new \WP_Error(
			'restlesswp_forbidden',
			__( 'You do not have permission to perform this action.', 'restlesswp' ),
			array( 'status' => 403 )
		);
	}
}
