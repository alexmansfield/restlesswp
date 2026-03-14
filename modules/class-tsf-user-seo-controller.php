<?php
/**
 * TSF User SEO Controller — REST endpoints for per-user SEO data.
 *
 * User SEO data has only 3 keys (counter_type, facebook_page,
 * twitter_page) and no key aliasing is needed.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-tsf-data-bridge.php';

/**
 * REST controller for TSF user SEO data.
 */
class RestlessWP_TSF_User_SEO_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_TSF_Data_Bridge;

	/**
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'tsf/user-seo';
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
		return 'edit_users';
	}

	/**
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'update', 'delete' );
	}

	/**
	 * Lists users with their SEO data.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array
	 */
	protected function get_items( WP_REST_Request $request ) {
		$args = array(
			'number' => absint( $request->get_param( 'per_page' ) ?: 20 ),
			'paged'  => absint( $request->get_param( 'page' ) ?: 1 ),
		);

		$role = $request->get_param( 'role' );

		if ( $role ) {
			$args['role'] = sanitize_text_field( $role );
		}

		$users  = get_users( $args );
		$result = array();

		foreach ( $users as $user ) {
			$seo              = $this->bridge_get_user_seo( $user->ID );
			$seo['user_id']   = $user->ID;
			$result[]         = $seo;
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected function get_collection_params(): array {
		return array(
			'role'     => array(
				'description'       => __( 'Filter by user role.', 'restlesswp' ),
				'type'              => 'string',
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
	 * Retrieves SEO data for a single user.
	 *
	 * @param string          $key     User ID as string.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$user_id = absint( $key );
		$valid   = $this->validate_user_exists( $user_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$seo              = $this->bridge_get_user_seo( $user_id );
		$seo['user_id']   = $user_id;

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
			__( 'User SEO data cannot be created. Use update instead.', 'restlesswp' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Updates SEO data for a user.
	 *
	 * @param string          $key     User ID as string.
	 * @param array           $data    Merged SEO data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$user_id = absint( $key );
		unset( $data['user_id'] );

		$this->bridge_save_user_seo( $user_id, $data );

		$seo            = $this->bridge_get_user_seo( $user_id );
		$seo['user_id'] = $user_id;

		return $seo;
	}

	/**
	 * Deletes all custom SEO meta for a user.
	 *
	 * @param string          $key     User ID as string.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$user_id = absint( $key );
		$valid   = $this->validate_user_exists( $user_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$this->bridge_delete_user_seo( $user_id );

		return array(
			'deleted' => true,
			'user_id' => $user_id,
		);
	}

	/**
	 * @return array
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tsf-user-seo',
			'type'       => 'object',
			'properties' => array(
				'user_id'       => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => __( 'User ID.', 'restlesswp' ),
				),
				'counter_type'  => array(
					'type'        => 'integer',
					'description' => __( 'Character counter type.', 'restlesswp' ),
				),
				'facebook_page' => array(
					'type'        => 'string',
					'description' => __( 'Facebook page URL.', 'restlesswp' ),
				),
				'twitter_page'  => array(
					'type'        => 'string',
					'description' => __( 'Twitter/X profile URL.', 'restlesswp' ),
				),
			),
		);
	}
}
