<?php
/**
 * Bricks Global Classes Auxiliary Routes — backup and category handlers.
 *
 * Extracted from the Global Classes controller to keep file length under
 * the 500-line limit. Provides backup ring and category CRUD endpoints.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup and category route handlers for Global Classes.
 */
trait RestlessWP_Bricks_Global_Classes_Aux {

	/**
	 * Registers backup routes under the global-classes base.
	 *
	 * @param string $base Route base path.
	 * @return void
	 */
	private function register_backup_routes( string $base ): void {
		register_rest_route( self::NAMESPACE, '/' . $base . '/backups', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_backups' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
			'args'                => array(),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base . '/backups/(?P<index>[0-4])', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_backup' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
			'args'                => array(),
		) );
	}

	/**
	 * Registers category routes under the global-classes base.
	 *
	 * @param string $base Route base path.
	 * @return void
	 */
	private function register_category_routes( string $base ): void {
		register_rest_route( self::NAMESPACE, '/' . $base . '/categories', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_categories' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
				'args'                => array(),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'handle_replace_categories' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => array(),
			),
		) );
	}

	/**
	 * Handles GET request for backup metadata listing.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_list_backups( WP_REST_Request $request ): WP_REST_Response {
		return RestlessWP_Response_Formatter::success( $this->backups->list_metadata() );
	}

	/**
	 * Handles GET request for a single backup slot.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_get_backup( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$index      = (int) $url_params['index'];
		$slot       = $this->backups->get_slot( $index );

		if ( null === $slot ) {
			return $this->wp_error_to_response( new WP_Error(
				'not_found',
				__( 'Backup slot is empty.', 'restlesswp' ),
				array( 'status' => 404 )
			) );
		}

		return RestlessWP_Response_Formatter::success( $slot );
	}

	/**
	 * Handles GET request for global class categories.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_list_categories( WP_REST_Request $request ): WP_REST_Response {
		$categories = $this->fetch_blob( self::CATEGORIES_OPTION );

		return RestlessWP_Response_Formatter::success( $categories );
	}

	/**
	 * Handles PUT request to replace all categories.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_replace_categories( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return $this->wp_error_to_response(
				$this->validation_error( __( 'Request body must be an array.', 'restlesswp' ) )
			);
		}

		$sanitized = RestlessWP_Bricks_Normalizer::sanitize_recursive( $body );
		$this->save_blob( self::CATEGORIES_OPTION, $sanitized );

		return RestlessWP_Response_Formatter::success( $sanitized );
	}

	/**
	 * Moves a global class to the trash option.
	 *
	 * @param array $item The class to trash.
	 * @return void
	 */
	private function move_to_trash( array $item ): void {
		$trash             = $this->fetch_blob( self::TRASH_OPTION );
		$item['deletedAt'] = time();
		$trash[]           = $item;
		$this->save_blob( self::TRASH_OPTION, $trash );
	}

	/**
	 * Updates the global classes timestamp and user options.
	 *
	 * @return void
	 */
	private function update_timestamp(): void {
		update_option( self::TIMESTAMP_OPTION, time() );
		update_option( self::USER_OPTION, get_current_user_id() );
	}
}
