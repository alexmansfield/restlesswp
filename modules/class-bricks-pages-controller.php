<?php
/**
 * Bricks Pages Controller — REST endpoints for page element operations.
 *
 * Provides GET (read elements + settings), PUT (write elements), and
 * POST import (interchange format with class/component merging).
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/class-bricks-page-importer.php';

/**
 * REST controller for Bricks page content operations.
 */
class RestlessWP_Bricks_Pages_Controller extends RestlessWP_Base_Controller {

	/** @var RestlessWP_Bricks_Page_Importer */
	private RestlessWP_Bricks_Page_Importer $importer;

	/** @param RestlessWP_Auth_Handler $auth Auth handler instance. */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		parent::__construct( $auth );
		$this->importer = new RestlessWP_Bricks_Page_Importer();
	}

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/pages';
	}

	/** @return string */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/** @return string */
	protected function get_write_capability(): string {
		return 'edit_posts';
	}

	/** @return string[] */
	public function get_supported_operations(): array {
		return array( 'get', 'update', 'import' );
	}

	/** @return array<string, string> */
	public function get_ability_descriptions(): array {
		return array(
			'get'    => 'Read a page\'s Bricks elements and settings. The key is the WordPress post ID.',
			'update' => 'Write Bricks elements to an existing page. Accepts an elements array.',
			'import' => 'Import a complete Bricks design onto a page. Accepts interchange format with elements, global_classes, and components. Classes and components are additively merged.',
		);
	}

	/** @return array|null */
	public function get_ability_input_schema( string $action ): ?array {
		if ( 'import' !== $action ) {
			return null;
		}

		return array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Target post/page ID.', 'restlesswp' ),
				),
				'elements'       => array(
					'type'        => 'array',
					'description' => __( 'Bricks elements array for the page.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'global_classes'  => array(
					'type'        => 'array',
					'description' => __( 'Global classes to merge. Existing IDs preserved, new IDs added.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'components'      => array(
					'type'        => 'array',
					'description' => __( 'Components to merge. Same additive strategy.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
			),
		);
	}

	/** @return void */
	public function register_routes(): void {
		$base = $this->get_route_base();

		register_rest_route( self::NAMESPACE, '/' . $base . '/(?P<key>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( $this, 'check_post_permission' ),
				'args'                => array(),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'handle_update' ),
				'permission_callback' => array( $this, 'check_post_permission' ),
				'args'                => array(),
			),
			'args' => array(
				'key' => array(
					'description'       => __( 'Post ID.', 'restlesswp' ),
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base . '/import', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_import' ),
			'permission_callback' => array( $this, 'check_import_permission' ),
			'args'                => array(
				'post_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'description'       => __( 'Target post/page ID.', 'restlesswp' ),
					'sanitize_callback' => 'absint',
				),
			),
		) );
	}

	/** @return bool|WP_Error */
	public function check_post_permission( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'key' );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'restlesswp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this post.', 'restlesswp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/** @return bool|WP_Error */
	public function check_import_permission( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'restlesswp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $post_id ) {
			return current_user_can( 'edit_posts' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this post.', 'restlesswp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/** @return array|WP_Error */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post = $this->validate_post_exists( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->format_page( $post );
	}

	/** @return array|WP_Error */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$post = $this->validate_post_exists( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$incoming = $request->get_json_params();

		if ( ! isset( $incoming['elements'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Request body must include elements.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$elements = RestlessWP_Bricks_Normalizer::elements( $incoming['elements'] );

		if ( empty( $elements ) ) {
			delete_post_meta( $post->ID, '_bricks_page_content_2' );
		} else {
			update_post_meta( $post->ID, '_bricks_page_content_2', wp_slash( $elements ) );
		}

		if ( isset( $incoming['settings'] ) ) {
			$settings = RestlessWP_Bricks_Normalizer::sanitize_recursive( $incoming['settings'] );
			update_post_meta( $post->ID, '_bricks_page_settings', wp_slash( $settings ) );
		}

		return $this->format_page( get_post( $post->ID ) );
	}

	/** @return WP_REST_Response */
	public function handle_import( WP_REST_Request $request ): WP_REST_Response {
		$data    = $request->get_json_params();
		$post_id = (int) ( $data['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return RestlessWP_Response_Formatter::error(
				'validation_error',
				__( 'The post_id field is required.', 'restlesswp' )
			);
		}

		$post = $this->validate_post_exists( $post_id );

		if ( is_wp_error( $post ) ) {
			return $this->wp_error_to_response( $post );
		}

		$report         = $this->importer->run( $post_id, $data );
		$report['page'] = $this->format_page( get_post( $post_id ) );

		return RestlessWP_Response_Formatter::success( $report );
	}

	/**
	 * Validates that a post exists and has a public post type.
	 *
	 * @param int $post_id Post ID to validate.
	 * @return WP_Post|WP_Error The post if valid, WP_Error otherwise.
	 */
	private function validate_post_exists( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Formats a post into the page response shape.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted page data.
	 */
	private function format_page( WP_Post $post ): array {
		$elements = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		$settings = get_post_meta( $post->ID, '_bricks_page_settings', true );

		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'elements'  => is_array( $elements ) ? $elements : array(),
			'settings'  => is_array( $settings ) ? $settings : array(),
			'modified'  => $post->post_modified,
		);
	}

	/** @return array */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bricks-page',
			'type'       => 'object',
			'properties' => array(
				'id'        => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => __( 'Post ID.', 'restlesswp' ),
				),
				'title'     => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Post title.', 'restlesswp' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Post type slug.', 'restlesswp' ),
				),
				'status'    => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Post status.', 'restlesswp' ),
				),
				'elements'  => array(
					'type'        => 'array',
					'description' => __( 'Bricks elements array.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'settings'  => array(
					'type'        => 'object',
					'description' => __( 'Page-level Bricks settings.', 'restlesswp' ),
				),
				'modified'  => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Last modified datetime.', 'restlesswp' ),
				),
			),
		);
	}

	/** @return array */
	protected function get_items( WP_REST_Request $request ) {
		return array();
	}

	/** @return WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'restlesswp_method_not_allowed',
			__( 'Create is not supported for pages. Use wp/v2/pages.', 'restlesswp' ),
			array( 'status' => 405 )
		);
	}
}
