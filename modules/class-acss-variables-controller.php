<?php
/**
 * ACSS Variables Controller — REST endpoints for Automatic CSS variables.
 *
 * Reads and writes CSS variable settings via the \Automatic_CSS\API class.
 * All settings are stored as a flat key-value map in a single wp_options row.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for Automatic CSS variables (settings).
 */
class RestlessWP_ACSS_Variables_Controller extends RestlessWP_Base_Controller {

	/**
	 * Returns the route base for variable endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'acss/variables';
	}

	/**
	 * Returns the capability required for read operations.
	 *
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'manage_options';
	}

	/**
	 * Returns the capability required for write operations.
	 *
	 * @return string
	 */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/**
	 * Retrieves all ACSS variable settings.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Array of variable data or WP_Error on failure.
	 */
	protected function get_items( WP_REST_Request $request ) {
		$settings = $this->fetch_all_settings();

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$result = array();

		foreach ( $settings as $key => $value ) {
			$result[] = $this->format_variable( $key, $value );
		}

		return $result;
	}

	/**
	 * Retrieves a single ACSS variable by key.
	 *
	 * @param string          $key     Variable key (e.g. 'base-font-size').
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Variable data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$settings = $this->fetch_all_settings();

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		if ( ! array_key_exists( $key, $settings ) ) {
			return new WP_Error(
				'not_found',
				__( 'Variable not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_variable( $key, $settings[ $key ] );
	}

	/**
	 * Creates a new ACSS variable.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created variable data or WP_Error on failure.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		if ( empty( $data['key'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Variable key is required.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$key   = sanitize_text_field( $data['key'] );
		$value = isset( $data['value'] ) ? sanitize_text_field( $data['value'] ) : '';

		return $this->save_variable( $key, $value );
	}

	/**
	 * Updates an existing ACSS variable.
	 *
	 * @param string          $key     Variable key.
	 * @param array           $data    Validated partial data (already merged by base).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated variable data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$value = isset( $data['value'] ) ? sanitize_text_field( $data['value'] ) : '';

		return $this->save_variable( $key, $value );
	}

	/**
	 * Checks if a variable with the given key already exists.
	 *
	 * @param array           $data    Incoming create data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|null Existing variable data if found, null otherwise.
	 */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['key'] ) ) {
			return null;
		}

		$settings = $this->fetch_all_settings();

		if ( is_wp_error( $settings ) ) {
			return null;
		}

		$key = sanitize_text_field( $data['key'] );

		if ( ! array_key_exists( $key, $settings ) ) {
			return null;
		}

		return $this->format_variable( $key, $settings[ $key ] );
	}

	/**
	 * Registers REST routes, adding a PATCH collection route for bulk updates.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		parent::register_routes();

		register_rest_route( self::NAMESPACE, '/' . $this->get_route_base(), array(
			'methods'             => 'PATCH',
			'callback'            => array( $this, 'handle_bulk_update' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
			'args'                => array(
				'variables' => array(
					'description' => __( 'Map of variable keys to values.', 'restlesswp' ),
					'type'        => 'object',
					'required'    => true,
				),
			),
		) );
	}

	/**
	 * Returns the list of supported operations for ability registration.
	 *
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'bulk-update' );
	}

	/**
	 * Handles PATCH bulk-update requests.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_bulk_update( WP_REST_Request $request ): WP_REST_Response {
		$variables = $request->get_param( 'variables' );

		if ( ! is_array( $variables ) || empty( $variables ) ) {
			return $this->wp_error_to_response( new WP_Error(
				'validation_error',
				__( 'The variables parameter must be a non-empty object.', 'restlesswp' ),
				array( 'status' => 400 )
			) );
		}

		$clean = $this->sanitize_bulk_variables( $variables );

		if ( is_wp_error( $clean ) ) {
			return $this->wp_error_to_response( $clean );
		}

		$result = $this->save_variables_bulk( $clean );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( $result );
	}

	/**
	 * Validates and sanitizes the bulk variables map.
	 *
	 * Uses strict validation: rejects the entire batch if any entry is invalid.
	 *
	 * @param array $variables Raw key-value map from the request.
	 * @return array|WP_Error Sanitized key-value map, or WP_Error on failure.
	 */
	private function sanitize_bulk_variables( array $variables ) {
		$clean = array();

		foreach ( $variables as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				return new WP_Error(
					'validation_error',
					sprintf(
						/* translators: %s: the invalid key */
						__( 'Invalid variable key: %s. Keys must be non-empty strings.', 'restlesswp' ),
						wp_json_encode( $key )
					),
					array( 'status' => 400 )
				);
			}

			if ( ! is_string( $value ) ) {
				return new WP_Error(
					'validation_error',
					sprintf(
						/* translators: %1$s: the variable key, %2$s: the actual type */
						__( 'Invalid value for "%1$s": expected string, got %2$s.', 'restlesswp' ),
						$key,
						gettype( $value )
					),
					array( 'status' => 400 )
				);
			}

			$clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
		}

		return $clean;
	}

	/**
	 * Saves multiple variables in a single ACSS API call.
	 *
	 * @param array $variables Sanitized key-value map.
	 * @return array|WP_Error Array of formatted variable data, or WP_Error.
	 */
	private function save_variables_bulk( array $variables ) {
		if ( ! class_exists( '\Automatic_CSS\API' ) ) {
			return $this->api_unavailable_error();
		}

		$result = \Automatic_CSS\API::update_settings( $variables );

		if ( false === $result ) {
			return new WP_Error(
				'restlesswp_update_failed',
				__( 'Failed to update ACSS variables.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		$output = array();

		foreach ( $variables as $key => $value ) {
			$saved_value = $result[ $key ] ?? $value;
			$output[]    = $this->format_variable( $key, $saved_value );
		}

		return $output;
	}

	/**
	 * Saves a variable via the ACSS API and returns the formatted result.
	 *
	 * @param string $key   Variable key.
	 * @param string $value Variable value.
	 * @return array|WP_Error Formatted variable data or WP_Error on failure.
	 */
	private function save_variable( string $key, string $value ) {
		if ( ! class_exists( '\Automatic_CSS\API' ) ) {
			return $this->api_unavailable_error();
		}

		$result = \Automatic_CSS\API::update_settings(
			array( $key => $value )
		);

		if ( false === $result ) {
			return new WP_Error(
				'restlesswp_update_failed',
				__( 'Failed to update ACSS variable.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		$saved_value = $result[ $key ] ?? $value;

		return $this->format_variable( $key, $saved_value );
	}

	/**
	 * Fetches all ACSS settings via the public API.
	 *
	 * @return array|WP_Error Settings array or WP_Error if API unavailable.
	 */
	private function fetch_all_settings() {
		if ( ! class_exists( '\Automatic_CSS\API' ) ) {
			return $this->api_unavailable_error();
		}

		return \Automatic_CSS\API::get_settings();
	}

	/**
	 * Returns a WP_Error for when the ACSS API class is not available.
	 *
	 * @return WP_Error
	 */
	private function api_unavailable_error(): WP_Error {
		return new WP_Error(
			'module_inactive',
			__( 'The Automatic CSS plugin is not active or its API class is not available.', 'restlesswp' ),
			array( 'status' => 424 )
		);
	}

	/**
	 * Formats a variable key-value pair into the API response shape.
	 *
	 * @param string $key   Variable key.
	 * @param mixed  $value Variable value.
	 * @return array Formatted variable data.
	 */
	private function format_variable( string $key, $value ): array {
		return array(
			'key'   => $key,
			'value' => $value,
		);
	}

	/**
	 * Returns the JSON Schema for an ACSS variable resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'acss-variable',
			'type'       => 'object',
			'required'   => array( 'key', 'value' ),
			'properties' => array(
				'key'   => array(
					'description' => __( 'Setting key for the CSS variable.', 'restlesswp' ),
					'type'        => 'string',
				),
				'value' => array(
					'description' => __( 'Setting value for the CSS variable.', 'restlesswp' ),
					'type'        => 'string',
				),
			),
		);
	}
}
