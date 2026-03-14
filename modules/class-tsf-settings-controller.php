<?php
/**
 * TSF Settings Controller — REST endpoints for global TSF settings.
 *
 * The key for single-item routes is the setting name (string).
 * PTA settings are excluded — those are handled by the PTA controller.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-tsf-data-bridge.php';

/**
 * REST controller for TSF global settings.
 */
class RestlessWP_TSF_Settings_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_TSF_Data_Bridge;

	/**
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'tsf/settings';
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
	 * Lists all TSF settings as key-value objects.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array
	 */
	protected function get_items( WP_REST_Request $request ) {
		$settings = $this->bridge_get_settings();
		$result   = array();

		foreach ( $settings as $key => $value ) {
			$result[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return $result;
	}

	/**
	 * Retrieves a single setting by key.
	 *
	 * @param string          $key     Setting key.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$settings = $this->bridge_get_settings();

		if ( ! array_key_exists( $key, $settings ) ) {
			return new WP_Error(
				'not_found',
				__( 'Setting not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'key'   => $key,
			'value' => $settings[ $key ],
		);
	}

	/**
	 * @param array           $data    Item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'restlesswp_not_implemented',
			__( 'Settings cannot be created. Use update instead.', 'restlesswp' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Updates a single setting.
	 *
	 * @param string          $key     Setting key.
	 * @param array           $data    Merged data containing 'value'.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		if ( 'pta' === $key ) {
			return new WP_Error(
				'validation_error',
				__( 'PTA settings must be updated via the pta-seo endpoint.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$settings = $this->bridge_get_settings();

		if ( ! array_key_exists( $key, $settings ) ) {
			return new WP_Error(
				'not_found',
				__( 'Setting not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$value = $data['value'] ?? null;
		$this->bridge_update_setting( $key, $value );

		return array(
			'key'   => $key,
			'value' => $this->bridge_get_setting( $key ),
		);
	}

	/**
	 * @return array
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tsf-setting',
			'type'       => 'object',
			'properties' => array(
				'key'   => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Setting key.', 'restlesswp' ),
				),
				'value' => array(
					'description' => __( 'Setting value.', 'restlesswp' ),
				),
			),
		);
	}
}
