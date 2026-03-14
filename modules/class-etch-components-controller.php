<?php
/**
 * Etch Components Controller — REST endpoints for Etch components.
 *
 * Wraps the wp_block CPT with Etch meta for component CRUD.
 * Uses RestlessWP_Etch_Block_Converter for auto-converting
 * docs-format blocks on write.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-normalizer.php';

/**
 * REST controller for Etch components stored as wp_block posts.
 */
class RestlessWP_Etch_Components_Controller extends RestlessWP_Base_Controller {

	/**
	 * Returns the route base for Etch component endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'etch/components';
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
	 * @return string[] Array of operation names.
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
			'list'   => 'List all Etch components. Components are reusable block groups stored as wp_block posts with Etch metadata. Each has a unique HTML key for referencing.',
			'get'    => 'Get a single Etch component by post ID. Returns the parsed block tree, component properties, and HTML key. Any element scripts are returned as plain JavaScript in attrs.script.code.',
			'create' => 'Create a new Etch component. Requires name and key. Blocks in the component may reference style keys — ensure those styles exist first, or use the pages import ability to create everything atomically. To attach JavaScript to an element, set attrs.script with { id: "unique-dedup-key", code: "your JS here" }. The id is REQUIRED — Etch uses it to load the script only once per page even if multiple instances of the component exist. Send code as plain JavaScript; base64 encoding is handled automatically.',
			'update' => 'Update an existing Etch component by post ID. Can update name, description, blocks, or properties independently. Element scripts in attrs.script.code should be plain JavaScript.',
			'delete' => 'Delete an Etch component by post ID.',
		);
	}

	/**
	 * Retrieves all Etch components.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array Array of formatted component data.
	 */
	protected function get_items( WP_REST_Request $request ) {
		$args = array(
			'post_type'      => 'wp_block',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => 'etch_component_html_key',
					'compare' => 'EXISTS',
				),
			),
		);

		$posts  = get_posts( $args );
		$result = array();

		foreach ( $posts as $post ) {
			$result[] = $this->format_component( $post );
		}

		return $result;
	}

	/**
	 * Retrieves a single Etch component by post ID.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Component data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post = get_post( (int) $key );

		if ( ! $this->is_valid_etch_component( $post ) ) {
			return new WP_Error(
				'not_found',
				__( 'Etch component not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_component( $post );
	}

	/**
	 * Creates a new Etch component.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created component data or WP_Error on failure.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		$validation = $this->validate_required_fields( $data );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$blocks     = RestlessWP_Etch_Normalizer::blocks( $data['blocks'] ?? array() );
		$serialized = serialize_blocks( $blocks );

		$post_id = wp_insert_post( array(
			'post_type'    => 'wp_block',
			'post_title'   => sanitize_text_field( $data['name'] ),
			'post_content' => $serialized,
			'post_status'  => 'publish',
			'post_excerpt' => sanitize_text_field( $data['description'] ?? '' ),
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_component_meta( $post_id, $data );

		return $this->format_component( get_post( $post_id ) );
	}

	/**
	 * Updates an existing Etch component.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param array           $data    Validated partial data (already merged).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated component data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$post = get_post( (int) $key );

		if ( ! $this->is_valid_etch_component( $post ) ) {
			return new WP_Error(
				'not_found',
				__( 'Etch component not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$update_args = array( 'ID' => $post->ID );

		if ( isset( $data['name'] ) ) {
			$update_args['post_title'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['description'] ) ) {
			$update_args['post_excerpt'] = sanitize_text_field( $data['description'] );
		}

		if ( isset( $data['blocks'] ) ) {
			$blocks                      = RestlessWP_Etch_Normalizer::blocks( $data['blocks'] );
			$update_args['post_content'] = serialize_blocks( $blocks );
		}

		$result = wp_update_post( $update_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_component_meta( $post->ID, $data );

		return $this->format_component( get_post( $post->ID ) );
	}

	/**
	 * Deletes an Etch component.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Deletion result or WP_Error on failure.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$post = get_post( (int) $key );

		if ( ! $this->is_valid_etch_component( $post ) ) {
			return new WP_Error(
				'not_found',
				__( 'Etch component not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		wp_delete_post( (int) $key, true );

		return array(
			'deleted' => true,
			'id'      => (int) $key,
		);
	}

	/**
	 * Checks for an existing component with the same HTML key.
	 *
	 * @param array           $data    Incoming create data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|null Existing component data if found, null otherwise.
	 */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['key'] ) ) {
			return null;
		}

		$existing = get_posts( array(
			'post_type'   => 'wp_block',
			'meta_key'    => 'etch_component_html_key',
			'meta_value'  => sanitize_text_field( $data['key'] ),
			'numberposts' => 1,
		) );

		if ( empty( $existing ) ) {
			return null;
		}

		return $this->format_component( $existing[0] );
	}

	/**
	 * Returns the JSON Schema for an Etch component resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-component',
			'type'       => 'object',
			'required'   => array( 'name', 'key' ),
			'properties' => array(
				'id'          => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => __( 'wp_block post ID.', 'restlesswp' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Component display name.', 'restlesswp' ),
				),
				'key'         => array(
					'type'        => 'string',
					'description' => __( 'Unique HTML key for component references in the Etch editor. Must be unique across all components. Required on create.', 'restlesswp' ),
				),
				'blocks'      => array(
					'type'        => 'array',
					'description' => __( 'Block tree in editor format (etch/*) or docs format (auto-converted on write). Blocks reference style keys in attrs.styles. Each block must include innerHTML and innerContent. etch/element blocks may include attrs.script: { id: "unique-dedup-key", code: "plain JavaScript" }. The id ensures the script loads once per page; code is plain JS (base64 encoding is handled automatically).', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'properties'  => array(
					'type'        => 'object',
					'description' => __( 'Component property definitions. These define configurable inputs shown when a component instance is placed on a page.', 'restlesswp' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Component description.', 'restlesswp' ),
				),
			),
		);
	}

	/**
	 * Formats a wp_block post into the API response shape.
	 *
	 * @param WP_Post $post The wp_block post object.
	 * @return array Formatted component data.
	 */
	private function format_component( WP_Post $post ): array {
		$properties = get_post_meta( $post->ID, 'etch_component_properties', true );

		$blocks = RestlessWP_Etch_Normalizer::decode_block_scripts(
			parse_blocks( $post->post_content )
		);

		return array(
			'id'          => $post->ID,
			'name'        => $post->post_title,
			'key'         => get_post_meta( $post->ID, 'etch_component_html_key', true ),
			'blocks'      => $blocks,
			'properties'  => $properties ?: array(),
			'description' => $post->post_excerpt,
		);
	}

	/**
	 * Validates that required fields are present in the data.
	 *
	 * @param array $data Incoming request data.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_required_fields( array $data ): true|WP_Error {
		if ( empty( $data['name'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The name field is required.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $data['key'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The key field is required.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Saves Etch component meta for a post.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    Request data containing meta values.
	 * @return void
	 */
	private function save_component_meta( int $post_id, array $data ): void {
		if ( isset( $data['key'] ) ) {
			update_post_meta( $post_id, 'etch_component_html_key', sanitize_text_field( $data['key'] ) );
		}

		if ( isset( $data['properties'] ) ) {
			update_post_meta( $post_id, 'etch_component_properties', $data['properties'] );
		}
	}

	/**
	 * Checks if a post is a valid Etch component.
	 *
	 * @param WP_Post|null $post The post to validate.
	 * @return bool True if valid Etch component.
	 */
	private function is_valid_etch_component( ?WP_Post $post ): bool {
		if ( ! $post ) {
			return false;
		}

		if ( 'wp_block' !== $post->post_type ) {
			return false;
		}

		$html_key = get_post_meta( $post->ID, 'etch_component_html_key', true );

		return ! empty( $html_key );
	}
}
