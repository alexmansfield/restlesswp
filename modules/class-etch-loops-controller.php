<?php
/**
 * Etch Loops Controller — REST endpoints for Etch loop preset management.
 *
 * Manages loop presets stored as a single `etch_loops` option blob.
 * Uses the RestlessWP_Etch_Loops_Helper trait for option I/O.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-etch-loops-helper.php';
require_once __DIR__ . '/class-etch-normalizer.php';

/**
 * REST controller for Etch loop presets.
 */
class RestlessWP_Etch_Loops_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_Etch_Loops_Helper;

	/** @var string[] Allowed config type values. */
	private const ALLOWED_TYPES = array(
		'wp-query',
		'main-query',
		'wp-terms',
		'wp-users',
		'json',
	);

	/**
	 * Returns the route base for loop endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'etch/loops';
	}

	/**
	 * Returns the capability required for read operations.
	 *
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'edit_posts';
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
	 * Returns the list of supported operations for ability registration.
	 *
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'delete' );
	}

	/**
	 * Returns workflow-aware ability descriptions for agents.
	 *
	 * @return array<string, string> Operation name => description.
	 */
	public function get_ability_descriptions(): array {
		return array(
			'list'   => 'List all Etch loop presets. Loops define data sources (WP_Query, terms, users, or static JSON) that repeater blocks iterate over. Blocks reference loops by key in their attrs.',
			'get'    => 'Get a single Etch loop preset by key. Returns the loop name, config type, and query/data args.',
			'create' => 'Create a new Etch loop preset. The key field is required on create. Repeater blocks reference this key in their attrs to iterate over the loop data. To create loops alongside page content in one call, use the pages import ability instead.',
			'update' => 'Update an existing Etch loop preset by key. Changes affect all repeater blocks referencing this loop.',
			'delete' => 'Delete an Etch loop preset by key. WARNING: repeater blocks referencing this key will lose their data source.',
		);
	}

	/**
	 * Retrieves all Etch loop presets with optional type filtering.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Array of loop data or WP_Error on failure.
	 */
	protected function get_items( WP_REST_Request $request ) {
		$loops       = $this->fetch_all_loops();
		$type_filter = $request->get_param( 'type' );
		$result      = array();

		foreach ( $loops as $key => $entry ) {
			if ( null !== $type_filter && ( $entry['config']['type'] ?? '' ) !== $type_filter ) {
				continue;
			}
			$result[] = $this->format_loop( $key, $entry );
		}

		return $result;
	}

	/**
	 * Returns additional query parameters for collection requests.
	 *
	 * @return array Array of query parameter definitions.
	 */
	protected function get_collection_params(): array {
		return array(
			'type' => array(
				'description'       => __( 'Filter by config type.', 'restlesswp' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Retrieves a single Etch loop preset by key.
	 *
	 * @param string          $key     Loop key identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Loop data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$entry = $this->fetch_loop( $key );

		if ( null === $entry ) {
			return new WP_Error(
				'not_found',
				__( 'Loop preset not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_loop( $key, $entry );
	}

	/**
	 * Creates a new Etch loop preset.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created loop data or WP_Error on failure.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		$error = $this->validate_loop_data( $data, true );

		if ( null !== $error ) {
			return $error;
		}

		$key   = sanitize_text_field( $data['key'] );
		$loops = $this->fetch_all_loops();

		$loops[ $key ] = $this->extract_loop_data( $data );
		$this->save_all_loops( $loops );

		return $this->format_loop( $key, $loops[ $key ] );
	}

	/**
	 * Updates an existing Etch loop preset.
	 *
	 * @param string          $key     Loop key identifier.
	 * @param array           $data    Validated data to replace.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated loop data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$loops = $this->fetch_all_loops();

		if ( ! isset( $loops[ $key ] ) ) {
			return new WP_Error(
				'not_found',
				__( 'Loop preset not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$error = $this->validate_loop_data( $data, false );

		if ( null !== $error ) {
			return $error;
		}

		$loops[ $key ] = $this->extract_loop_data( $data );
		$this->save_all_loops( $loops );

		return $this->format_loop( $key, $loops[ $key ] );
	}

	/**
	 * Deletes an Etch loop preset by key.
	 *
	 * @param string          $key     Loop key identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Deletion result or WP_Error if not found.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$loops = $this->fetch_all_loops();

		if ( ! isset( $loops[ $key ] ) ) {
			return new WP_Error(
				'not_found',
				__( 'Loop preset not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		unset( $loops[ $key ] );
		$this->save_all_loops( $loops );

		return array(
			'deleted' => true,
			'key'     => $key,
		);
	}

	/**
	 * Checks if a loop with the given key already exists.
	 *
	 * @param array           $data    Incoming create data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|null Existing loop data if found, null otherwise.
	 */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['key'] ) ) {
			return null;
		}

		$key   = sanitize_text_field( $data['key'] );
		$entry = $this->fetch_loop( $key );

		if ( null === $entry ) {
			return null;
		}

		return $this->format_loop( $key, $entry );
	}

	/**
	 * Returns the JSON Schema for an Etch loop preset resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-loop',
			'type'       => 'object',
			'required'   => array( 'name', 'config' ),
			'properties' => array(
				'key'    => array(
					'type'        => 'string',
					'description' => __( 'Unique loop identifier. Required on create. Repeater blocks reference this key in their attrs to bind to this data source.', 'restlesswp' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'Human-readable display name shown in the Etch editor loop selector.', 'restlesswp' ),
				),
				'global' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the loop is globally available.', 'restlesswp' ),
				),
				'config' => array(
					'type'        => 'object',
					'description' => __( 'Data source configuration. Must include type (wp-query, main-query, wp-terms, wp-users, or json). For wp-query: { type: "wp-query", args: { post_type: "post", posts_per_page: 10 } }. For json: { type: "json", data: [...] }. For wp-terms: { type: "wp-terms", args: { taxonomy: "category" } }.', 'restlesswp' ),
				),
			),
		);
	}

	/**
	 * Validates loop data for create or update operations.
	 *
	 * @param array $data       Incoming data.
	 * @param bool  $is_create  Whether this is a create operation.
	 * @return WP_Error|null WP_Error if validation fails, null if valid.
	 */
	private function validate_loop_data( array $data, bool $is_create ): ?WP_Error {
		if ( empty( $data['name'] ) || ! is_string( $data['name'] ) ) {
			return $this->validation_error(
				__( 'The "name" field is required and must be a non-empty string.', 'restlesswp' )
			);
		}

		if ( $is_create && ( empty( $data['key'] ) || ! is_string( $data['key'] ) ) ) {
			return $this->validation_error(
				__( 'The "key" field is required on create and must be a non-empty string.', 'restlesswp' )
			);
		}

		if ( empty( $data['config'] ) || ! is_array( $data['config'] ) ) {
			return $this->validation_error(
				__( 'The "config" field is required and must be an object.', 'restlesswp' )
			);
		}

		$type = $data['config']['type'] ?? '';

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return $this->validation_error(
				sprintf(
					/* translators: %s: comma-separated list of allowed types */
					__( 'The "config.type" field must be one of: %s.', 'restlesswp' ),
					implode( ', ', self::ALLOWED_TYPES )
				)
			);
		}

		return $this->validate_config_for_type( $type, $data['config'] );
	}

	/**
	 * Validates type-specific config requirements.
	 *
	 * @param string $type   The config type value.
	 * @param array  $config The config object.
	 * @return WP_Error|null WP_Error if validation fails, null if valid.
	 */
	private function validate_config_for_type( string $type, array $config ): ?WP_Error {
		if ( 'json' === $type ) {
			if ( ! isset( $config['data'] ) || ! is_array( $config['data'] ) ) {
				return $this->validation_error(
					__( 'The "config.data" field is required and must be an array for json type.', 'restlesswp' )
				);
			}
			return null;
		}

		if ( 'main-query' === $type ) {
			if ( isset( $config['args'] ) && ! is_array( $config['args'] ) ) {
				return $this->validation_error(
					__( 'The "config.args" field must be an object when provided.', 'restlesswp' )
				);
			}
			return null;
		}

		// wp-query, wp-terms, wp-users all require args.
		if ( ! isset( $config['args'] ) || ! is_array( $config['args'] ) ) {
			return $this->validation_error(
				sprintf(
					/* translators: %s: the config type */
					__( 'The "config.args" field is required and must be an object for %s type.', 'restlesswp' ),
					$type
				)
			);
		}

		return null;
	}

	/**
	 * Creates a validation_error WP_Error.
	 *
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private function validation_error( string $message ): WP_Error {
		return new WP_Error(
			'validation_error',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * Extracts and sanitizes loop data from incoming request data.
	 *
	 * @param array $data Raw incoming data.
	 * @return array Sanitized loop entry data (without key).
	 */
	private function extract_loop_data( array $data ): array {
		return RestlessWP_Etch_Normalizer::loop( $data );
	}

	/**
	 * Formats a loop key and entry into the API response shape.
	 *
	 * @param string $key   Loop key identifier.
	 * @param array  $entry Loop entry data.
	 * @return array Formatted loop data with key included.
	 */
	private function format_loop( string $key, array $entry ): array {
		return array_merge( array( 'key' => $key ), $entry );
	}
}
