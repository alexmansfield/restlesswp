<?php
/**
 * ACF Taxonomies Controller — REST endpoints for taxonomies.
 *
 * Supports source filtering: `all` returns every registered taxonomy
 * with source attribution, `acf` returns only ACF-managed taxonomies.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/classes/trait-acf-label-generator.php';
require_once dirname( __DIR__ ) . '/classes/trait-acf-taxonomy-settings.php';

/**
 * REST controller for taxonomies.
 */
class RestlessWP_ACF_Taxonomies_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_ACF_Label_Generator;
	use RestlessWP_ACF_Taxonomy_Settings;

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
		return 'taxonomies';
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
				'description'       => __( 'Filter taxonomies by source.', 'restlesswp' ),
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
			return $this->get_acf_taxonomies();
		}

		return $this->get_all_taxonomies();
	}

	/** {@inheritDoc} */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$taxonomy = get_taxonomy( $key );

		if ( ! $taxonomy ) {
			return new WP_Error(
				'not_found',
				__( 'Taxonomy not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$acf_keys = $this->get_acf_taxonomy_keys();
		$source   = $this->source_detector->detect_taxonomy_source( $key, $acf_keys );

		return $this->format_taxonomy( $taxonomy, $source );
	}

	/** {@inheritDoc} */
	protected function create_item( array $data, WP_REST_Request $request ) {
		$version_error = $this->check_acf_version();
		if ( null !== $version_error ) {
			return $version_error;
		}

		$settings = $this->build_acf_settings( $data );
		$post     = acf_update_taxonomy( $settings );

		if ( ! $post || ! isset( $post['ID'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to create taxonomy.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return $this->get_acf_taxonomy_by_post_id( $post['ID'] );
	}

	/** {@inheritDoc} */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$version_error = $this->check_acf_version();
		if ( null !== $version_error ) {
			return $version_error;
		}

		$post = $this->find_acf_taxonomy_post( $key );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'ACF-managed taxonomy not found. Only ACF-managed taxonomies can be updated.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$existing_config = $this->get_raw_post_config( $post );
		$merged_data     = array_merge(
			array(
				'taxonomy' => $existing_config['taxonomy'] ?? $key,
				'label'    => $post->post_title,
				'singular' => $existing_config['singular_label'] ?? $post->post_title,
			),
			$data
		);

		$settings        = $this->build_acf_settings( $merged_data );
		$settings['ID']  = $post->ID;
		$settings['key'] = $existing_config['key'] ?? '';

		$result = acf_update_taxonomy( $settings );

		if ( ! $result || ! isset( $result['ID'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to update taxonomy.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return $this->get_acf_taxonomy_by_post_id( $post->ID );
	}

	/** {@inheritDoc} */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$version_error = $this->check_acf_version();
		if ( null !== $version_error ) {
			return $version_error;
		}

		$post = $this->find_acf_taxonomy_post( $key );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'ACF-managed taxonomy not found. Only ACF-managed taxonomies can be deleted.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$deleted = acf_delete_taxonomy( $post->ID );
		if ( ! $deleted ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to delete taxonomy.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'taxonomy' => $key,
			'deleted'  => true,
		);
	}

	/** {@inheritDoc} */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		$key = $data['taxonomy'] ?? '';
		if ( '' === $key ) {
			return null;
		}

		$taxonomy = get_taxonomy( $key );
		if ( $taxonomy ) {
			$acf_keys = $this->get_acf_taxonomy_keys();
			$source   = $this->source_detector->detect_taxonomy_source( $key, $acf_keys );
			return $this->format_taxonomy( $taxonomy, $source );
		}

		return null;
	}

	/** {@inheritDoc} */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'taxonomy',
			'type'       => 'object',
			'required'   => array( 'taxonomy' ),
			'properties' => $this->get_schema_properties(),
		);
	}

	/** Returns schema property definitions for a taxonomy. */
	private function get_schema_properties(): array {
		$core = array(
			'taxonomy'     => array(
				'description' => __( 'Taxonomy slug/key.', 'restlesswp' ),
				'type'        => 'string',
			),
			'label'        => array(
				'description' => __( 'Human-readable label for the taxonomy.', 'restlesswp' ),
				'type'        => 'string',
			),
			'singular'     => array(
				'description' => __( 'Singular label for the taxonomy.', 'restlesswp' ),
				'type'        => 'string',
			),
			'description'  => array(
				'description' => __( 'Description of the taxonomy.', 'restlesswp' ),
				'type'        => 'string',
			),
			'public'       => array(
				'description' => __( 'Whether the taxonomy is publicly queryable.', 'restlesswp' ),
				'type'        => 'boolean',
			),
			'hierarchical' => array(
				'description' => __( 'Whether the taxonomy is hierarchical.', 'restlesswp' ),
				'type'        => 'boolean',
			),
			'object_type'  => array(
				'description' => __( 'Post types associated with this taxonomy.', 'restlesswp' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
			),
			'labels'       => array(
				'description' => __( 'Custom label overrides.', 'restlesswp' ),
				'type'        => 'object',
			),
			'source'       => array(
				'description' => __( 'Source that registered the taxonomy.', 'restlesswp' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);

		return array_merge( $core, $this->get_taxonomy_settings_schema() );
	}

	/** Returns all registered taxonomies with source attribution. */
	private function get_all_taxonomies(): array {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$acf_keys   = $this->get_acf_taxonomy_keys();
		$result     = array();

		foreach ( $taxonomies as $slug => $taxonomy ) {
			$source   = $this->source_detector->detect_taxonomy_source( $slug, $acf_keys );
			$result[] = $this->format_taxonomy( $taxonomy, $source );
		}

		return $result;
	}

	/** Returns only ACF-managed taxonomies. */
	private function get_acf_taxonomies(): array {
		$posts  = $this->query_acf_taxonomy_posts();
		$result = array();

		foreach ( $posts as $post ) {
			$result[] = $this->format_acf_taxonomy_from_post( $post );
		}

		return $result;
	}

	/** Queries all acf-taxonomy custom posts. */
	private function query_acf_taxonomy_posts(): array {
		$query = new WP_Query( array(
			'post_type'      => 'acf-taxonomy',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		) );

		return $query->posts;
	}

	/** Returns taxonomy keys managed by ACF. */
	private function get_acf_taxonomy_keys(): array {
		$posts = $this->query_acf_taxonomy_posts();
		$keys  = array();

		foreach ( $posts as $post ) {
			$config       = $this->get_raw_post_config( $post );
			$taxonomy_key = $config['taxonomy'] ?? $post->post_name;
			$keys[]       = $taxonomy_key;
		}

		return $keys;
	}

	/** Formats a WP_Taxonomy object into the API response shape. */
	private function format_taxonomy( WP_Taxonomy $taxonomy, string $source ): array {
		$core = array(
			'taxonomy'     => $taxonomy->name,
			'label'        => $taxonomy->label,
			'singular'     => $taxonomy->labels->singular_name ?? $taxonomy->label,
			'description'  => $taxonomy->description ?? '',
			'public'       => (bool) $taxonomy->public,
			'hierarchical' => (bool) $taxonomy->hierarchical,
			'object_type'  => (array) $taxonomy->object_type,
			'labels'       => (array) $taxonomy->labels,
			'source'       => $source,
		);

		return array_merge( $core, $this->map_wp_taxonomy_to_response( $taxonomy ) );
	}

	/** Formats an ACF taxonomy WP_Post into the API response shape. */
	private function format_acf_taxonomy_from_post( WP_Post $post ): array {
		$config   = $this->get_raw_post_config( $post );
		$slug     = $config['taxonomy'] ?? $post->post_name;
		$taxonomy = get_taxonomy( $slug );

		if ( ! $taxonomy ) {
			return $this->build_fallback_taxonomy_response( $slug, $config, $post );
		}

		$response    = $this->format_taxonomy( $taxonomy, 'acf' );
		$acf_overlay = $this->map_taxonomy_acf_config_to_response( $config );

		$acf_labels = is_array( $config['labels'] ?? null ) ? $config['labels'] : array();
		$acf_labels = array_filter( $acf_labels, fn( $v ) => '' !== $v );
		if ( ! empty( $acf_labels ) ) {
			$acf_overlay['labels'] = array_merge( $response['labels'] ?? array(), $acf_labels );
		}

		return array_merge( $response, $acf_overlay );
	}

	/** Finds the ACF taxonomy post for a given taxonomy slug. */
	private function find_acf_taxonomy_post( string $slug ): ?WP_Post {
		$posts = $this->query_acf_taxonomy_posts();

		foreach ( $posts as $post ) {
			$config       = $this->get_raw_post_config( $post );
			$taxonomy_key = $config['taxonomy'] ?? $post->post_name;

			if ( $slug === $taxonomy_key ) {
				return $post;
			}
		}

		return null;
	}

	/** Extracts the serialized config from an ACF taxonomy post. */
	private function get_raw_post_config( WP_Post $post ): array {
		$config = unserialize( $post->post_content, array( 'allowed_classes' => false ) );

		return is_array( $config ) ? $config : array();
	}

	/** Builds an ACF settings array for acf_update_taxonomy(). */
	private function build_acf_settings( array $data ): array {
		$plural   = $data['label'] ?? '';
		$singular = $data['singular'] ?? $plural;

		$labels = $this->generate_taxonomy_labels(
			$plural,
			$singular,
			$data['labels'] ?? array()
		);

		$core = array(
			'ID'             => 0,
			'key'            => 'taxonomy_' . uniqid(),
			'title'          => $plural,
			'active'         => true,
			'taxonomy'       => $data['taxonomy'] ?? '',
			'singular_label' => $singular,
			'labels'         => $labels,
			'description'    => $data['description'] ?? '',
			'public'         => $data['public'] ?? true,
			'hierarchical'   => $data['hierarchical'] ?? false,
			'object_type'    => $data['object_type'] ?? array( 'post' ),
		);

		$extended = $this->map_taxonomy_request_to_acf_settings( $data );

		return array_merge( $core, $extended );
	}

	/** Checks that ACF version supports taxonomy management (6.1+). */
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
				__( 'ACF 6.1+ is required for taxonomy management.', 'restlesswp' ),
				array( 'status' => 424 )
			);
		}

		return null;
	}

	/** Retrieves a formatted ACF taxonomy by its WP post ID. */
	private function get_acf_taxonomy_by_post_id( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Taxonomy not found after save.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_acf_taxonomy_from_post( $post );
	}

	/** Builds a fallback response when the taxonomy is not yet registered. */
	private function build_fallback_taxonomy_response( string $slug, array $settings, WP_Post $post ): array {
		$labels = is_array( $settings['labels'] ?? null ) ? $settings['labels'] : array();

		$core = array(
			'taxonomy'     => $slug,
			'label'        => $settings['label'] ?? $post->post_title,
			'singular'     => $settings['singular_label'] ?? $post->post_title,
			'description'  => $settings['description'] ?? '',
			'public'       => (bool) ( $settings['public'] ?? true ),
			'hierarchical' => (bool) ( $settings['hierarchical'] ?? false ),
			'object_type'  => (array) ( $settings['object_type'] ?? array() ),
			'labels'       => array_filter( $labels, fn( $v ) => '' !== $v ),
			'source'       => 'acf',
		);

		return array_merge( $core, $this->map_taxonomy_acf_config_to_response( $settings ) );
	}
}
