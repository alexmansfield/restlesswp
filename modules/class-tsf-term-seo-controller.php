<?php
/**
 * TSF Term SEO Controller — REST endpoints for per-term SEO data.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-tsf-data-bridge.php';

/**
 * REST controller for TSF term SEO data.
 */
class RestlessWP_TSF_Term_SEO_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_TSF_Data_Bridge;

	/**
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'tsf/term-seo';
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
	 * Lists terms with their SEO data.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_items( WP_REST_Request $request ) {
		$taxonomy = sanitize_text_field( $request->get_param( 'taxonomy' ) ?: 'category' );
		$per_page = absint( $request->get_param( 'per_page' ) ?: 20 );
		$page     = absint( $request->get_param( 'page' ) ?: 1 );

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$result = array();

		foreach ( $terms as $term ) {
			$seo              = $this->bridge_get_term_seo( $term->term_id );
			$seo['term_id']   = $term->term_id;
			$result[]         = $seo;
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected function get_collection_params(): array {
		return array(
			'taxonomy' => array(
				'description'       => __( 'Filter by taxonomy.', 'restlesswp' ),
				'type'              => 'string',
				'default'           => 'category',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description'       => __( 'Results per page.', 'restlesswp' ),
				'type'              => 'integer',
				'default'           => 20,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'description'       => __( 'Page number.', 'restlesswp' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Retrieves SEO data for a single term.
	 *
	 * @param string          $key     Term ID as string.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$term_id = absint( $key );
		$valid   = $this->validate_term_exists( $term_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$seo              = $this->bridge_get_term_seo( $term_id );
		$seo['term_id']   = $term_id;

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
			__( 'Term SEO data cannot be created. Use update instead.', 'restlesswp' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Updates SEO data for a term.
	 *
	 * @param string          $key     Term ID as string.
	 * @param array           $data    Merged SEO data with aliases.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$term_id = absint( $key );
		unset( $data['term_id'] );

		$this->bridge_save_term_seo( $term_id, $data );

		$seo            = $this->bridge_get_term_seo( $term_id );
		$seo['term_id'] = $term_id;

		return $seo;
	}

	/**
	 * Deletes all custom SEO meta for a term.
	 *
	 * @param string          $key     Term ID as string.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$term_id = absint( $key );
		$valid   = $this->validate_term_exists( $term_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$this->bridge_delete_term_seo( $term_id );

		return array(
			'deleted' => true,
			'term_id' => $term_id,
		);
	}

	/**
	 * @return array
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tsf-term-seo',
			'type'       => 'object',
			'properties' => $this->get_term_seo_properties(),
		);
	}

	/**
	 * Returns schema properties for term SEO data.
	 *
	 * @return array
	 */
	private function get_term_seo_properties(): array {
		return array(
			'term_id'             => array(
				'type'        => 'integer',
				'readonly'    => true,
				'description' => __( 'Term ID.', 'restlesswp' ),
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
			'tw_card_type'        => $this->string_prop( 'Twitter card type.' ),
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
