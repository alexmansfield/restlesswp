<?php
/**
 * Bricks Templates Controller — REST endpoints for Bricks templates.
 *
 * Templates are stored as bricks_template CPT posts with content in
 * _bricks_page_content_2 meta and settings in _bricks_template_settings.
 * All meta writes use wp_slash() to preserve backslashes.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';

/**
 * REST controller for Bricks templates.
 */
class RestlessWP_Bricks_Templates_Controller extends RestlessWP_Base_Controller {

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/templates';
	}

	/** @return string */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/** @return string */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/** @return string[] */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'delete' );
	}

	/** @return array<string, string> */
	public function get_ability_descriptions(): array {
		return array(
			'list'   => 'List all Bricks templates. Supports ?type= filter (header, footer, section, etc).',
			'get'    => 'Get a single template by post ID.',
			'create' => 'Create a new Bricks template with type, elements, and settings.',
			'update' => 'Update an existing template by post ID.',
			'delete' => 'Delete a Bricks template.',
		);
	}

	/** @return void */
	public function register_routes(): void {
		$base = $this->get_route_base();

		register_rest_route( self::NAMESPACE, '/' . $base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
				'args'                => $this->get_collection_params(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => array(),
			),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base . '/(?P<key>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
				'args'                => array(),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'handle_update' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => array(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => array(),
			),
			'args' => array(
				'key' => array(
					'description'       => __( 'Template post ID.', 'restlesswp' ),
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		) );
	}

	/** @return array */
	protected function get_collection_params(): array {
		return array(
			'type' => array(
				'description'       => __( 'Filter by template type (header, footer, archive, section, popup, etc).', 'restlesswp' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/** @return array|WP_Error */
	protected function get_items( WP_REST_Request $request ) {
		$args = array(
			'post_type'      => 'bricks_template',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		);

		$type = $request->get_param( 'type' );

		if ( $type ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_bricks_template_type',
					'value' => $type,
				),
			);
		}

		$posts  = get_posts( $args );
		$result = array();

		foreach ( $posts as $post ) {
			$result[] = $this->format_template( $post );
		}

		return $result;
	}

	/** @return array|WP_Error */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post = $this->validate_template( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->format_template( $post );
	}

	/** @return array|WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		if ( empty( $data['title'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The "title" field is required.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$post_id = wp_insert_post( array(
			'post_type'   => 'bricks_template',
			'post_title'  => sanitize_text_field( $data['title'] ),
			'post_status' => sanitize_text_field( $data['status'] ?? 'publish' ),
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_template_meta( $post_id, $data );

		return $this->format_template( get_post( $post_id ) );
	}

	/** @return array|WP_Error */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$post = $this->validate_template( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$update_args = array( 'ID' => $post->ID );

		if ( isset( $data['title'] ) ) {
			$update_args['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['status'] ) ) {
			$update_args['post_status'] = sanitize_text_field( $data['status'] );
		}

		$result = wp_update_post( $update_args, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_template_meta( $post->ID, $data );

		return $this->format_template( get_post( $post->ID ) );
	}

	/** @return array|WP_Error */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$post = $this->validate_template( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		wp_delete_post( $post->ID, true );

		return array(
			'deleted' => true,
			'id'      => $post->ID,
		);
	}

	/** @return array */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bricks-template',
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => array(
				'id'             => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => __( 'Template post ID.', 'restlesswp' ),
				),
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Template title.', 'restlesswp' ),
				),
				'status'         => array(
					'type'        => 'string',
					'description' => __( 'Post status.', 'restlesswp' ),
				),
				'template_type'  => array(
					'type'        => 'string',
					'description' => __( 'Template type (header, footer, archive, section, popup, etc).', 'restlesswp' ),
				),
				'elements'       => array(
					'type'        => 'array',
					'description' => __( 'Bricks elements array.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'settings'       => array(
					'type'        => 'object',
					'description' => __( 'Template settings.', 'restlesswp' ),
				),
				'template_tags'  => array(
					'type'        => 'array',
					'readonly'    => true,
					'description' => __( 'Template tag terms.', 'restlesswp' ),
					'items'       => array( 'type' => 'string' ),
				),
				'bundle'         => array(
					'type'        => 'array',
					'readonly'    => true,
					'description' => __( 'Template bundle terms.', 'restlesswp' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Validates that a post exists and is a bricks_template.
	 *
	 * @param int $post_id Post ID to validate.
	 * @return WP_Post|WP_Error The post if valid, WP_Error otherwise.
	 */
	private function validate_template( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Template not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Saves template-specific meta fields.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Request data.
	 * @return void
	 */
	private function save_template_meta( int $post_id, array $data ): void {
		if ( isset( $data['template_type'] ) ) {
			update_post_meta( $post_id, '_bricks_template_type', sanitize_text_field( $data['template_type'] ) );
		}

		if ( isset( $data['elements'] ) ) {
			$elements = RestlessWP_Bricks_Normalizer::elements( $data['elements'] );
			$this->save_meta_slashed( $post_id, '_bricks_page_content_2', $elements );
		}

		if ( isset( $data['settings'] ) ) {
			$settings = RestlessWP_Bricks_Normalizer::sanitize_recursive( $data['settings'] );
			$this->save_meta_slashed( $post_id, '_bricks_template_settings', $settings );
		}
	}

	/**
	 * Saves a post meta value with wp_slash() for proper backslash handling.
	 *
	 * Deletes the meta if the value is an empty array.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value to save.
	 * @return void
	 */
	private function save_meta_slashed( int $post_id, string $meta_key, $value ): void {
		if ( is_array( $value ) && empty( $value ) ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
	}

	/**
	 * Formats a template post into the API response shape.
	 *
	 * @param WP_Post $post The template post.
	 * @return array Formatted template data.
	 */
	private function format_template( WP_Post $post ): array {
		$elements = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		$settings = get_post_meta( $post->ID, '_bricks_template_settings', true );

		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'template_type' => get_post_meta( $post->ID, '_bricks_template_type', true ) ?: '',
			'elements'      => is_array( $elements ) ? $elements : array(),
			'settings'      => is_array( $settings ) ? $settings : array(),
			'template_tags' => $this->get_term_names( $post->ID, 'template_tag' ),
			'bundle'        => $this->get_term_names( $post->ID, 'template_bundle' ),
			'modified'      => $post->post_modified,
		);
	}

	/**
	 * Gets term names for a post in a given taxonomy.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[] Array of term names.
	 */
	private function get_term_names( int $post_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}
}
