<?php
/**
 * TSF Post SEO Controller — REST endpoints for per-post SEO data.
 *
 * Exposes TSF's post meta through clean alias keys. Supports list
 * (with post_type filter and pagination), get, update, and delete
 * (which resets to TSF defaults).
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-tsf-data-bridge.php';

/**
 * REST controller for TSF post SEO data.
 */
class RestlessWP_TSF_Post_SEO_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_TSF_Data_Bridge;

	/**
	 * Returns the route base for post SEO endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'tsf/post-seo';
	}

	/**
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/**
	 * @return string
	 */
	protected function get_write_capability(): string {
		return 'edit_others_posts';
	}

	/**
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'update', 'delete' );
	}

	/**
	 * Lists posts with their SEO data.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_items( WP_REST_Request $request ) {
		$query = new WP_Query( array(
			'post_type'      => sanitize_text_field( $request->get_param( 'post_type' ) ?: 'post' ),
			'posts_per_page' => absint( $request->get_param( 'per_page' ) ?: 20 ),
			'paged'          => absint( $request->get_param( 'page' ) ?: 1 ),
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );

		$result = array();

		foreach ( $query->posts as $post_id ) {
			$seo              = $this->bridge_get_post_seo( (int) $post_id );
			$seo['post_id']   = (int) $post_id;
			$result[]         = $seo;
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected function get_collection_params(): array {
		return array(
			'post_type' => array(
				'description'       => __( 'Filter by post type.', 'restlesswp' ),
				'type'              => 'string',
				'default'           => 'post',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page'  => array(
				'description'       => __( 'Results per page.', 'restlesswp' ),
				'type'              => 'integer',
				'default'           => 20,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'      => array(
				'description'       => __( 'Page number.', 'restlesswp' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Retrieves SEO data for a single post.
	 *
	 * @param string          $key     Post ID as string.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post_id = absint( $key );
		$valid   = $this->validate_post_exists( $post_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$seo            = $this->bridge_get_post_seo( $post_id );
		$seo['post_id'] = $post_id;

		return $seo;
	}

	/**
	 * SEO data cannot be created — it exists implicitly for all posts.
	 *
	 * @param array           $data    Item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'restlesswp_not_implemented',
			__( 'Post SEO data cannot be created. Use update instead.', 'restlesswp' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Updates SEO data for a post.
	 *
	 * @param string          $key     Post ID as string.
	 * @param array           $data    Merged SEO data with aliases.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$post_id = absint( $key );
		unset( $data['post_id'] );

		$this->bridge_save_post_seo( $post_id, $data );

		$seo            = $this->bridge_get_post_seo( $post_id );
		$seo['post_id'] = $post_id;

		return $seo;
	}

	/**
	 * Deletes all custom SEO meta for a post, reverting to TSF defaults.
	 *
	 * @param string          $key     Post ID as string.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$post_id = absint( $key );
		$valid   = $this->validate_post_exists( $post_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$this->bridge_delete_post_seo( $post_id );

		return array(
			'deleted' => true,
			'post_id' => $post_id,
		);
	}

	/**
	 * @return array
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tsf-post-seo',
			'type'       => 'object',
			'properties' => $this->get_post_seo_properties(),
		);
	}

	/**
	 * Returns schema properties for post SEO data.
	 *
	 * @return array
	 */
	private function get_post_seo_properties(): array {
		return array(
			'post_id'             => array(
				'type'        => 'integer',
				'readonly'    => true,
				'description' => __( 'Post ID.', 'restlesswp' ),
			),
			'title'               => $this->string_prop( 'Custom SEO title.' ),
			'title_no_blogname'   => $this->qubit_prop( 'Exclude blog name from title.' ),
			'description'         => $this->string_prop( 'Meta description.' ),
			'canonical_url'       => $this->string_prop( 'Custom canonical URL.' ),
			'redirect_url'        => $this->string_prop( 'Redirect URL.' ),
			'social_image_url'    => $this->string_prop( 'Social image URL.' ),
			'social_image_id'     => $this->int_prop( 'Social image attachment ID.' ),
			'noindex'             => $this->qubit_prop( 'Robots noindex directive.' ),
			'nofollow'            => $this->qubit_prop( 'Robots nofollow directive.' ),
			'noarchive'           => $this->qubit_prop( 'Robots noarchive directive.' ),
			'exclude_search'      => $this->qubit_prop( 'Exclude from search results.' ),
			'exclude_archive'     => $this->qubit_prop( 'Exclude from archive listings.' ),
			'og_title'            => $this->string_prop( 'Open Graph title.' ),
			'og_description'      => $this->string_prop( 'Open Graph description.' ),
			'twitter_title'       => $this->string_prop( 'Twitter card title.' ),
			'twitter_description' => $this->string_prop( 'Twitter card description.' ),
			'twitter_card_type'   => $this->string_prop( 'Twitter card type.' ),
		);
	}

	/**
	 * @param string $desc Description.
	 * @return array
	 */
	private function string_prop( string $desc ): array {
		return array(
			'type'        => 'string',
			'description' => __( $desc, 'restlesswp' ),
		);
	}

	/**
	 * @param string $desc Description.
	 * @return array
	 */
	private function int_prop( string $desc ): array {
		return array(
			'type'        => 'integer',
			'description' => __( $desc, 'restlesswp' ),
		);
	}

	/**
	 * Qubit: -1 (force off), 0 (default), 1 (force on).
	 *
	 * @param string $desc Description.
	 * @return array
	 */
	private function qubit_prop( string $desc ): array {
		return array(
			'type'        => 'integer',
			'enum'        => array( -1, 0, 1 ),
			'description' => __( $desc, 'restlesswp' ),
		);
	}
}
