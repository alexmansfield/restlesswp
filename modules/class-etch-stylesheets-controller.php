<?php
/**
 * Etch Stylesheets Controller — REST endpoints for Etch stylesheet management.
 *
 * Manages named CSS documents stored in the `etch_global_stylesheets` option.
 * Each entry is a simple { name, css } object keyed by a short unique ID.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-etch-stylesheets-helper.php';

/**
 * REST controller for Etch stylesheets.
 */
class RestlessWP_Etch_Stylesheets_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_Etch_Stylesheets_Helper;

	/**
	 * Returns the route base for stylesheet endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'etch/stylesheets';
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
	 * Returns the list of supported operations for ability registration.
	 *
	 * @return string[]
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'delete' );
	}

	/**
	 * Returns workflow-aware ability descriptions for agents.
	 *
	 * @return array<string, string> Operation name => description.
	 */
	public function get_ability_descriptions(): array {
		return array(
			'list'   => 'List all Etch global stylesheets. These are named CSS documents that apply site-wide, separate from element-level styles managed by the styles resource.',
			'get'    => 'Get a single Etch global stylesheet by key. Returns the stylesheet name and full CSS content.',
			'create' => 'Create a new Etch global stylesheet. The key is auto-generated. Use this for site-wide CSS that is not tied to specific elements or blocks.',
			'update' => 'Update an existing Etch global stylesheet by key. Can update the name, CSS content, or both.',
			'delete' => 'Delete an Etch global stylesheet by key.',
		);
	}

	/**
	 * Retrieves all Etch stylesheets.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array Array of stylesheet data.
	 */
	protected function get_items( WP_REST_Request $request ): array {
		$stylesheets = $this->fetch_all_stylesheets();
		$result      = array();

		foreach ( $stylesheets as $key => $entry ) {
			$result[] = $this->format_stylesheet( $key, $entry );
		}

		return $result;
	}

	/**
	 * Retrieves a single Etch stylesheet by key.
	 *
	 * @param string          $key     Stylesheet key identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Stylesheet data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$entry = $this->fetch_stylesheet( $key );

		if ( null === $entry ) {
			return new WP_Error(
				'not_found',
				__( 'Stylesheet not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_stylesheet( $key, $entry );
	}

	/**
	 * Creates a new Etch stylesheet.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created stylesheet data or WP_Error on failure.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		if ( empty( $data['name'] ) || ! is_string( $data['name'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The "name" field is required and must be a non-empty string.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$key         = substr( uniqid(), -7 );
		$stylesheets = $this->fetch_all_stylesheets();

		$stylesheets[ $key ] = array(
			'name' => sanitize_text_field( $data['name'] ),
			'css'  => wp_strip_all_tags( $data['css'] ?? '' ),
		);

		$this->save_all_stylesheets( $stylesheets );

		return $this->format_stylesheet( $key, $stylesheets[ $key ] );
	}

	/**
	 * Updates an existing Etch stylesheet.
	 *
	 * @param string          $key     Stylesheet key identifier.
	 * @param array           $data    Validated data (merged by base controller).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated stylesheet data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$stylesheets = $this->fetch_all_stylesheets();

		if ( ! isset( $stylesheets[ $key ] ) ) {
			return new WP_Error(
				'not_found',
				__( 'Stylesheet not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $data['name'] ) || ! is_string( $data['name'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The "name" field is required and must be a non-empty string.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$existing = $stylesheets[ $key ];
		$css      = isset( $data['css'] ) && is_string( $data['css'] )
			? wp_strip_all_tags( $data['css'] )
			: $existing['css'];

		$stylesheets[ $key ] = array(
			'name' => sanitize_text_field( $data['name'] ),
			'css'  => $css,
		);

		$this->save_all_stylesheets( $stylesheets );

		return $this->format_stylesheet( $key, $stylesheets[ $key ] );
	}

	/**
	 * Deletes an Etch stylesheet by key.
	 *
	 * @param string          $key     Stylesheet key identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Deletion result or WP_Error if not found.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$stylesheets = $this->fetch_all_stylesheets();

		if ( ! isset( $stylesheets[ $key ] ) ) {
			return new WP_Error(
				'not_found',
				__( 'Stylesheet not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		unset( $stylesheets[ $key ] );
		$this->save_all_stylesheets( $stylesheets );

		return array(
			'deleted' => true,
			'key'     => $key,
		);
	}

	/**
	 * Returns the JSON Schema for an Etch stylesheet resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-stylesheet',
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'key'  => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Stylesheet key identifier.', 'restlesswp' ),
				),
				'name' => array(
					'type'        => 'string',
					'description' => __( 'Display name for the stylesheet.', 'restlesswp' ),
				),
				'css'  => array(
					'type'              => 'string',
					'description'       => __( 'Full CSS content with selectors and rules (e.g. "body { font-family: sans-serif; }"). Unlike element styles which take declarations only, stylesheets contain complete CSS.', 'restlesswp' ),
					'sanitize_callback' => 'wp_strip_all_tags',
				),
			),
		);
	}

	/**
	 * Formats a stylesheet key and entry into the API response shape.
	 *
	 * @param string $key   Stylesheet key identifier.
	 * @param array  $entry Stylesheet entry data.
	 * @return array Formatted stylesheet data with key included.
	 */
	private function format_stylesheet( string $key, array $entry ): array {
		return array_merge( array( 'key' => $key ), $entry );
	}
}
