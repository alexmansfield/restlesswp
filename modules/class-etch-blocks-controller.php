<?php
/**
 * Etch Blocks Controller — REST endpoint for block format conversion.
 *
 * Non-standard controller that only exposes a POST convert endpoint.
 * Delegates conversion logic to RestlessWP_Etch_Block_Converter.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-block-converter.php';

/**
 * REST controller for converting Etch blocks between docs and editor formats.
 */
class RestlessWP_Etch_Blocks_Controller extends RestlessWP_Base_Controller {

	/**
	 * Returns the route base for block conversion endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'etch/blocks';
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
	 * Registers the convert route only; does not call parent routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/' . $this->get_route_base() . '/convert', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_convert' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
			'args'                => array(
				'blocks' => array(
					'required'    => true,
					'type'        => 'array',
					'description' => __( 'Array of blocks to convert from docs format to editor format.', 'restlesswp' ),
				),
			),
		) );
	}

	/**
	 * Returns the list of supported operations for ability registration.
	 *
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'convert' );
	}

	/**
	 * Returns workflow-aware ability descriptions for agents.
	 *
	 * @return array<string, string> Operation name => description.
	 */
	public function get_ability_descriptions(): array {
		return array(
			'convert' => 'Convert blocks from docs format (core/group with etchData metadata) to Etch editor format (etch/* block names). Useful for pre-processing blocks before manual content writes. Note: the pages import operation handles conversion automatically — you only need this for standalone conversion.',
		);
	}

	/**
	 * Handles POST convert requests.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_convert( WP_REST_Request $request ): WP_REST_Response {
		$blocks = $request->get_param( 'blocks' );

		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return RestlessWP_Response_Formatter::error(
				'validation_error',
				__( 'The blocks parameter must be a non-empty array.', 'restlesswp' ),
				400
			);
		}

		$result = RestlessWP_Etch_Block_Converter::convert( $blocks );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( array( 'blocks' => $result ) );
	}

	/**
	 * Stub — this controller does not support listing items.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function get_items( WP_REST_Request $request ) {
		return new WP_Error(
			'not_found',
			__( 'This endpoint only supports block conversion.', 'restlesswp' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Stub — this controller does not support getting a single item.
	 *
	 * @param string          $key     Item identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		return new WP_Error(
			'not_found',
			__( 'This endpoint only supports block conversion.', 'restlesswp' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Stub — this controller does not support creating items.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'not_found',
			__( 'This endpoint only supports block conversion.', 'restlesswp' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Stub — this controller does not support updating items.
	 *
	 * @param string          $key     Item identifier.
	 * @param array           $data    Validated partial data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'not_found',
			__( 'This endpoint only supports block conversion.', 'restlesswp' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Returns the JSON Schema for block conversion resources.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-block-conversion',
			'type'       => 'object',
			'properties' => array(
				'blocks' => array(
					'type'        => 'array',
					'description' => __( 'Array of blocks in docs or editor format.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
			),
		);
	}
}
