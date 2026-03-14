<?php
/**
 * TSF PTA SEO Controller — REST endpoints for post type archive SEO data.
 *
 * The key for single-item routes is the post type slug (string).
 * PTA meta uses the same key structure as term meta.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-tsf-data-bridge.php';

/**
 * REST controller for TSF post type archive SEO data.
 */
class RestlessWP_TSF_PTA_SEO_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_TSF_Data_Bridge;

	/**
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'tsf/pta-seo';
	}

	/**
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'manage_options';
	}

	/**
	 * @return string
	 */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/**
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'update' );
	}

	/**
	 * Lists all public post types with archive support and their SEO data.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array
	 */
	protected function get_items( WP_REST_Request $request ) {
		$post_types = get_post_types(
			array( 'public' => true, 'has_archive' => true ),
			'names'
		);

		$result = array();

		foreach ( $post_types as $post_type ) {
			$seo                = $this->bridge_get_pta_seo( $post_type );
			$seo['post_type']   = $post_type;
			$result[]           = $seo;
		}

		return $result;
	}

	/**
	 * Retrieves SEO data for a post type archive.
	 *
	 * @param string          $key     Post type slug.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$valid = $this->validate_post_type( $key );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$seo                = $this->bridge_get_pta_seo( $key );
		$seo['post_type']   = $key;

		return $seo;
	}

	/**
	 * @param array           $data    Item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'restlesswp_not_implemented',
			__( 'PTA SEO data cannot be created. Use update instead.', 'restlesswp' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Updates SEO data for a post type archive.
	 *
	 * @param string          $key     Post type slug.
	 * @param array           $data    Merged SEO data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		unset( $data['post_type'] );

		$this->bridge_save_pta_seo( $key, $data );

		$seo                = $this->bridge_get_pta_seo( $key );
		$seo['post_type']   = $key;

		return $seo;
	}

	/**
	 * @return array
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tsf-pta-seo',
			'type'       => 'object',
			'properties' => $this->get_pta_seo_properties(),
		);
	}

	/**
	 * Returns schema properties for PTA SEO data.
	 *
	 * @return array
	 */
	private function get_pta_seo_properties(): array {
		return array(
			'post_type'           => array(
				'type'        => 'string',
				'readonly'    => true,
				'description' => __( 'Post type slug.', 'restlesswp' ),
			),
			'title'               => $this->string_prop( 'Custom SEO title.' ),
			'title_no_blogname'   => $this->qubit_prop( 'Exclude blog name from title.' ),
			'description'         => $this->string_prop( 'Meta description.' ),
			'canonical'           => $this->string_prop( 'Custom canonical URL.' ),
			'redirect'            => $this->string_prop( 'Redirect URL.' ),
			'social_image_url'    => $this->string_prop( 'Social image URL.' ),
			'social_image_id'     => $this->int_prop( 'Social image attachment ID.' ),
			'noindex'             => $this->qubit_prop( 'Robots noindex directive.' ),
			'nofollow'            => $this->qubit_prop( 'Robots nofollow directive.' ),
			'noarchive'           => $this->qubit_prop( 'Robots noarchive directive.' ),
			'og_title'            => $this->string_prop( 'Open Graph title.' ),
			'og_description'      => $this->string_prop( 'Open Graph description.' ),
			'tw_title'            => $this->string_prop( 'Twitter card title.' ),
			'tw_description'      => $this->string_prop( 'Twitter card description.' ),
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
