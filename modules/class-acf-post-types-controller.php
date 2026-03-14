<?php
/**
 * ACF Post Types Controller — REST endpoints for post types.
 *
 * Supports source filtering: `all` returns every registered post type
 * with source attribution, `acf` returns only ACF-managed post types.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/classes/trait-acf-label-generator.php';
require_once dirname( __DIR__ ) . '/classes/trait-acf-post-type-settings.php';

/**
 * REST controller for post types.
 */
class RestlessWP_ACF_Post_Types_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_ACF_Label_Generator;
	use RestlessWP_ACF_Post_Type_Settings;

	/**
	 * Source detector instance.
	 *
	 * @var RestlessWP_Source_Detector
	 */
	private RestlessWP_Source_Detector $source_detector;

	/**
	 * Constructor.
	 *
	 * @param RestlessWP_Auth_Handler $auth Auth handler for permission callbacks.
	 */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		parent::__construct( $auth );
		$this->source_detector = new RestlessWP_Source_Detector();
	}

	/** {@inheritDoc} */
	protected function get_route_base(): string {
		return 'post-types';
	}

	/** {@inheritDoc} */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/** {@inheritDoc} */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/** {@inheritDoc} */
	protected function get_collection_params(): array {
		return array(
			'source' => array(
				'description'       => __( 'Filter by source: "acf" or "all".', 'restlesswp' ),
				'type'              => 'string',
				'default'           => 'all',
				'enum'              => array( 'acf', 'all' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/** {@inheritDoc} */
	protected function get_items( WP_REST_Request $request ) {
		$source = $request->get_param( 'source' ) ?? 'all';

		if ( 'acf' === $source ) {
			return $this->get_acf_post_types();
		}

		return $this->get_all_post_types();
	}

	/** {@inheritDoc} */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post_type_obj = get_post_type_object( $key );

		if ( ! $post_type_obj ) {
			return new WP_Error(
				'not_found',
				__( 'Post type not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$acf_keys = $this->get_acf_post_type_keys();

		return $this->format_wp_post_type( $post_type_obj, $acf_keys );
	}

	/** {@inheritDoc} */
	protected function create_item( array $data, WP_REST_Request $request ) {
		$version_error = $this->check_acf_version();
		if ( null !== $version_error ) {
			return $version_error;
		}

		$settings = $this->build_acf_settings( $data );
		$post     = acf_update_post_type( $settings );

		if ( ! $post || ! isset( $post['ID'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to create post type.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return $this->get_acf_post_type_by_id( $post['ID'] );
	}

	/** {@inheritDoc} */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$version_error = $this->check_acf_version();
		if ( null !== $version_error ) {
			return $version_error;
		}

		$existing_post = $this->find_acf_post_type_post( $key );
		if ( ! $existing_post ) {
			return new WP_Error(
				'not_found',
				__( 'ACF post type not found. Only ACF-managed post types can be updated.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$existing_config = $this->get_raw_post_config( $existing_post );
		$merged_data     = array_merge(
			array(
				'post_type' => $existing_config['post_type'] ?? $key,
				'label'     => $existing_post->post_title,
				'singular'  => $existing_config['singular_label'] ?? $existing_post->post_title,
			),
			$data
		);

		$settings        = $this->build_acf_settings( $merged_data );
		$settings['ID']  = $existing_post->ID;
		$settings['key'] = $existing_config['key'] ?? '';

		$post = acf_update_post_type( $settings );

		if ( ! $post || ! isset( $post['ID'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to update post type.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return $this->get_acf_post_type_by_id( $post['ID'] );
	}

	/** {@inheritDoc} */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$version_error = $this->check_acf_version();
		if ( null !== $version_error ) {
			return $version_error;
		}

		$existing_post = $this->find_acf_post_type_post( $key );
		if ( ! $existing_post ) {
			return new WP_Error(
				'not_found',
				__( 'ACF post type not found. Only ACF-managed post types can be deleted.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$deleted = acf_delete_post_type( $existing_post->ID );
		if ( ! $deleted ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to delete post type.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'post_type' => $key,
			'deleted'   => true,
		);
	}

	/** {@inheritDoc} */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		$key = $data['post_type'] ?? '';
		if ( empty( $key ) ) {
			return null;
		}

		$post_type_obj = get_post_type_object( $key );
		if ( $post_type_obj ) {
			$acf_keys = $this->get_acf_post_type_keys();
			return $this->format_wp_post_type( $post_type_obj, $acf_keys );
		}

		return null;
	}

	/** {@inheritDoc} */
	public function get_item_schema(): array {
		$core = array(
			'post_type'    => array(
				'description' => __( 'Post type slug/key.', 'restlesswp' ),
				'type'        => 'string',
			),
			'label'        => array(
				'description' => __( 'Plural label for the post type.', 'restlesswp' ),
				'type'        => 'string',
			),
			'singular'     => array(
				'description' => __( 'Singular label for the post type.', 'restlesswp' ),
				'type'        => 'string',
			),
			'description'  => array(
				'description' => __( 'Description of the post type.', 'restlesswp' ),
				'type'        => 'string',
			),
			'public'       => array(
				'description' => __( 'Whether the post type is public.', 'restlesswp' ),
				'type'        => 'boolean',
			),
			'hierarchical' => array(
				'description' => __( 'Whether the post type is hierarchical.', 'restlesswp' ),
				'type'        => 'boolean',
			),
			'labels'       => array(
				'description' => __( 'Custom label overrides.', 'restlesswp' ),
				'type'        => 'object',
			),
			'source'       => array(
				'description' => __( 'Source that registered the post type.', 'restlesswp' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'post-type',
			'type'       => 'object',
			'required'   => array( 'post_type', 'label' ),
			'properties' => array_merge( $core, $this->get_post_type_settings_schema() ),
		);
	}

	/**
	 * Returns all registered post types with source attribution.
	 *
	 * @return array Formatted post type data.
	 */
	private function get_all_post_types(): array {
		$post_types = get_post_types( array(), 'objects' );
		$acf_keys   = $this->get_acf_post_type_keys();
		$result     = array();

		foreach ( $post_types as $post_type_obj ) {
			$result[] = $this->format_wp_post_type( $post_type_obj, $acf_keys );
		}

		return $result;
	}

	/**
	 * Returns only ACF-managed post types.
	 *
	 * @return array Formatted ACF post type data.
	 */
	private function get_acf_post_types(): array {
		$posts  = $this->query_acf_post_type_posts();
		$result = array();

		foreach ( $posts as $post ) {
			$formatted = $this->format_acf_post_type( $post );
			if ( null !== $formatted ) {
				$result[] = $formatted;
			}
		}

		return $result;
	}

	/**
	 * Queries all acf-post-type custom posts.
	 *
	 * @return WP_Post[] Array of post objects.
	 */
	private function query_acf_post_type_posts(): array {
		$query = new WP_Query( array(
			'post_type'      => 'acf-post-type',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		) );

		return $query->posts;
	}

	/**
	 * Returns post type keys managed by ACF.
	 *
	 * @return string[] ACF post type slugs.
	 */
	private function get_acf_post_type_keys(): array {
		$posts = $this->query_acf_post_type_posts();
		$keys  = array();

		foreach ( $posts as $post ) {
			$config = unserialize( $post->post_content, array( 'allowed_classes' => false ) );
			if ( is_array( $config ) && ! empty( $config['post_type'] ) ) {
				$keys[] = $config['post_type'];
			}
		}

		return $keys;
	}

	/**
	 * Formats a WP_Post_Type object into the API response shape.
	 *
	 * @param WP_Post_Type $pt       WordPress post type object.
	 * @param string[]     $acf_keys ACF-managed post type slugs.
	 * @return array Formatted post type data.
	 */
	private function format_wp_post_type( WP_Post_Type $pt, array $acf_keys ): array {
		$core = array(
			'post_type'    => $pt->name,
			'label'        => $pt->label,
			'singular'     => $pt->labels->singular_name ?? $pt->label,
			'description'  => $pt->description,
			'public'       => (bool) $pt->public,
			'hierarchical' => (bool) $pt->hierarchical,
			'source'       => $this->source_detector->detect_post_type_source( $pt->name, $acf_keys ),
		);

		return array_merge( $core, $this->map_wp_post_type_to_response( $pt ) );
	}

	/**
	 * Formats an ACF post type WP_Post into the API response shape.
	 *
	 * @param WP_Post $post ACF post type post object.
	 * @return array|null Formatted data or null if config is invalid.
	 */
	private function format_acf_post_type( WP_Post $post ): ?array {
		$config = unserialize( $post->post_content, array( 'allowed_classes' => false ) );
		if ( ! is_array( $config ) || empty( $config['post_type'] ) ) {
			return null;
		}

		$core = array(
			'post_type'    => $config['post_type'],
			'label'        => $post->post_title,
			'singular'     => $config['singular_label'] ?? $post->post_title,
			'description'  => $config['description'] ?? '',
			'public'       => (bool) ( $config['public'] ?? true ),
			'hierarchical' => (bool) ( $config['hierarchical'] ?? false ),
			'source'       => 'acf',
		);

		if ( ! empty( $config['labels'] ) && is_array( $config['labels'] ) ) {
			$core['labels'] = $config['labels'];
		}

		return array_merge( $core, $this->map_acf_config_to_response( $config ) );
	}

	/**
	 * Checks that ACF version supports post type registration (6.1+).
	 *
	 * @return WP_Error|null Error if version is too low, null if OK.
	 */
	private function check_acf_version(): ?WP_Error {
		if ( ! defined( 'ACF_VERSION' ) ) {
			return new WP_Error(
				'module_inactive',
				__( 'The ACF plugin is not active on this site.', 'restlesswp' ),
				array( 'status' => 424 )
			);
		}

		if ( version_compare( ACF_VERSION, '6.1', '<' ) ) {
			return new WP_Error(
				'version_unsupported',
				__( 'Post type management requires ACF 6.1 or higher.', 'restlesswp' ),
				array( 'status' => 424 )
			);
		}

		return null;
	}

	/**
	 * Builds an ACF settings array for acf_update_post_type().
	 *
	 * @param array $data Incoming request data.
	 * @return array Settings array compatible with ACF's save pipeline.
	 */
	private function build_acf_settings( array $data ): array {
		$plural   = $data['label'] ?? '';
		$singular = $data['singular'] ?? $plural;

		$labels = $this->generate_post_type_labels(
			$plural,
			$singular,
			$data['labels'] ?? array()
		);

		$core = array(
			'ID'             => 0,
			'key'            => 'post_type_' . uniqid(),
			'title'          => $plural,
			'active'         => true,
			'post_type'      => $data['post_type'] ?? '',
			'singular_label' => $singular,
			'labels'         => $labels,
			'description'    => $data['description'] ?? '',
			'public'         => $data['public'] ?? true,
			'hierarchical'   => $data['hierarchical'] ?? false,
		);

		$extended = $this->map_request_to_acf_settings( $data );

		return array_merge( $core, $extended );
	}

	/**
	 * Extracts the serialized config from an ACF post type post.
	 *
	 * @param WP_Post $post ACF post type post object.
	 * @return array Config array or empty array on failure.
	 */
	private function get_raw_post_config( WP_Post $post ): array {
		$config = unserialize( $post->post_content, array( 'allowed_classes' => false ) );

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Finds the ACF post type WP_Post by post type slug.
	 *
	 * @param string $key Post type slug.
	 * @return WP_Post|null Post object or null if not found.
	 */
	private function find_acf_post_type_post( string $slug ): ?WP_Post {
		$posts = $this->query_acf_post_type_posts();

		foreach ( $posts as $post ) {
			$config = $this->get_raw_post_config( $post );
			if ( $slug === ( $config['post_type'] ?? '' ) ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Retrieves a formatted ACF post type by its WP post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|WP_Error Formatted post type data or WP_Error.
	 */
	private function get_acf_post_type_by_id( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Post type not found after save.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$formatted = $this->format_acf_post_type( $post );
		if ( null === $formatted ) {
			return new WP_Error(
				'not_found',
				__( 'Post type data is invalid.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		return $formatted;
	}
}
