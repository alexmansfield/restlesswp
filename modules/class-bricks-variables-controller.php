<?php
/**
 * Bricks Variables Controller — REST endpoints for CSS variables.
 *
 * Standard CRUD with name-change blocking. Variable rename is deferred
 * to v2 due to complex cross-DB reference updates.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/trait-bricks-option-blob.php';

/**
 * REST controller for Bricks CSS variables.
 */
class RestlessWP_Bricks_Variables_Controller extends RestlessWP_Base_Controller {
	use RestlessWP_Bricks_Option_Blob;

	/** @var string Option name for variables. */
	private const OPTION = 'bricks_global_variables';

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/variables';
	}

	/** @return string */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/** @return string */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/** @return string[] */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'delete' );
	}

	/** @return array<string, string> */
	public function get_ability_descriptions(): array {
		return array(
			'list'   => 'List all Bricks CSS variables.',
			'get'    => 'Get a single variable by ID.',
			'create' => 'Create a new CSS variable.',
			'update' => 'Update a variable. Name changes are blocked in v1.',
			'delete' => 'Delete a variable by ID.',
		);
	}

	/** @return array|WP_Error */
	protected function get_items( WP_REST_Request $request ) {
		return $this->fetch_blob( self::OPTION );
	}

	/** @return array|WP_Error */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$item  = $this->find_by_id( $items, $key );

		if ( null === $item ) {
			return new WP_Error(
				'not_found',
				__( 'Variable not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $item;
	}

	/** @return array|WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		if ( empty( $data['name'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The "name" field is required.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $data['id'] ) ) {
			$data['id'] = RestlessWP_Bricks_Normalizer::element_id();
		}

		$items      = $this->fetch_blob( self::OPTION );
		$normalized = RestlessWP_Bricks_Normalizer::variable( $data );
		$items[]    = $normalized;

		$this->save_blob( self::OPTION, $items );

		return $normalized;
	}

	/** @return array|WP_Error */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$index = $this->find_index_by_id( $items, $key );

		if ( null === $index ) {
			return new WP_Error(
				'not_found',
				__( 'Variable not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$existing = $items[ $index ];

		if ( isset( $data['name'] ) && $data['name'] !== ( $existing['name'] ?? '' ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Variable rename is not supported in v1. Renaming requires updating all cross-references in the database. This will be supported in a future version.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$merged          = array_merge( $existing, $data );
		$normalized      = RestlessWP_Bricks_Normalizer::variable( $merged );
		$items[ $index ] = $normalized;

		$this->save_blob( self::OPTION, $items );

		return $normalized;
	}

	/** @return array|WP_Error */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );

		if ( ! $this->remove_by_id( $items, $key ) ) {
			return new WP_Error(
				'not_found',
				__( 'Variable not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$this->save_blob( self::OPTION, $items );

		return array(
			'deleted' => true,
			'id'      => $key,
		);
	}

	/** @return array|null */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['id'] ) ) {
			return null;
		}

		$items = $this->fetch_blob( self::OPTION );

		return $this->find_by_id( $items, sanitize_text_field( $data['id'] ) );
	}

	/** @return array */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bricks-variable',
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'id'       => array(
					'type'        => 'string',
					'description' => __( 'Unique variable ID.', 'restlesswp' ),
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Variable name (cannot be renamed in v1).', 'restlesswp' ),
				),
				'value'    => array(
					'type'        => 'string',
					'description' => __( 'Variable value.', 'restlesswp' ),
				),
				'type'     => array(
					'type'        => 'string',
					'description' => __( 'Variable type (e.g. color, size).', 'restlesswp' ),
				),
				'category' => array(
					'type'        => 'string',
					'description' => __( 'Category name.', 'restlesswp' ),
				),
			),
		);
	}
}
