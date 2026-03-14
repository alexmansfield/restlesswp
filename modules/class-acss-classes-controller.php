<?php
/**
 * ACSS Classes Controller — REST endpoints for Automatic CSS utility classes.
 *
 * The class list is defined in ACSS's config/classes.json and compiled from
 * SCSS sources. It is read-only — classes cannot be created or updated via
 * the REST API.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for Automatic CSS utility classes (read-only).
 */
class RestlessWP_ACSS_Classes_Controller extends RestlessWP_Base_Controller {

	/**
	 * Returns the route base for class endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'acss/classes';
	}

	/**
	 * Returns the capability required for read operations.
	 *
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'manage_options';
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
	 * Retrieves all ACSS utility classes.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Array of class data or WP_Error on failure.
	 */
	protected function get_items( WP_REST_Request $request ) {
		$classes = $this->load_classes_json();

		if ( is_wp_error( $classes ) ) {
			return $classes;
		}

		$result = array();

		foreach ( $classes as $class_name ) {
			$result[] = $this->format_class( $class_name );
		}

		return $result;
	}

	/**
	 * Retrieves a single ACSS utility class by name.
	 *
	 * @param string          $key     Class name identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Class data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$classes = $this->load_classes_json();

		if ( is_wp_error( $classes ) ) {
			return $classes;
		}

		if ( ! in_array( $key, $classes, true ) ) {
			return new WP_Error(
				'not_found',
				__( 'Class not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_class( $key );
	}

	/**
	 * Returns an error because classes are read-only.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return $this->read_only_error();
	}

	/**
	 * Returns an error because classes are read-only.
	 *
	 * @param string          $key     Item identifier.
	 * @param array           $data    Validated partial data.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		return $this->read_only_error();
	}

	/**
	 * Returns an error because classes are read-only.
	 *
	 * @param string          $key     Item identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_Error Always returns an error.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		return $this->read_only_error();
	}

	/**
	 * Returns a WP_Error indicating this resource is read-only.
	 *
	 * @return WP_Error
	 */
	private function read_only_error(): WP_Error {
		return new WP_Error(
			'restlesswp_read_only',
			__( 'ACSS classes are read-only. They are defined in SCSS source and compiled by Automatic CSS.', 'restlesswp' ),
			array( 'status' => 405 )
		);
	}

	/**
	 * Loads the class list from ACSS's config/classes.json file.
	 *
	 * @return array|WP_Error Array of class name strings or WP_Error.
	 */
	private function load_classes_json() {
		$path = $this->get_classes_json_path();

		if ( ! file_exists( $path ) ) {
			return new WP_Error(
				'module_inactive',
				__( 'The Automatic CSS plugin is not active or its classes.json file is missing.', 'restlesswp' ),
				array( 'status' => 424 )
			);
		}

		return $this->parse_classes_file( $path );
	}

	/**
	 * Returns the absolute path to the ACSS classes.json file.
	 *
	 * @return string Absolute file path.
	 */
	private function get_classes_json_path(): string {
		return WP_PLUGIN_DIR . '/automaticcss-plugin/config/classes.json';
	}

	/**
	 * Parses the classes.json file and returns the decoded array.
	 *
	 * @param string $path Absolute path to the JSON file.
	 * @return array|WP_Error Array of class names or WP_Error on parse failure.
	 */
	private function parse_classes_file( string $path ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin config file.

		if ( false === $contents ) {
			return new WP_Error(
				'restlesswp_read_error',
				__( 'Failed to read ACSS classes.json file.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		$classes = json_decode( $contents, true );

		if ( ! is_array( $classes ) ) {
			return new WP_Error(
				'restlesswp_parse_error',
				__( 'Failed to parse ACSS classes.json file.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		return $classes;
	}

	/**
	 * Formats a class name into the API response shape.
	 *
	 * @param string $class_name CSS class name.
	 * @return array Formatted class data.
	 */
	private function format_class( string $class_name ): array {
		return array(
			'name' => $class_name,
		);
	}

	/**
	 * Returns the JSON Schema for an ACSS class resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'acss-class',
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'description' => __( 'CSS class name.', 'restlesswp' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}
}
