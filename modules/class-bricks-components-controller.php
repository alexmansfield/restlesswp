<?php
/**
 * Bricks Components Controller — REST endpoints for reusable element trees.
 *
 * Components are stored in bricks_global_elements option as indexed arrays
 * of element trees, each with a unique ID.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/trait-bricks-option-blob.php';

/**
 * REST controller for Bricks components (saved elements).
 */
class RestlessWP_Bricks_Components_Controller extends RestlessWP_Base_Controller {
	use RestlessWP_Bricks_Option_Blob;

	/** @var string Option name for components. */
	private const OPTION = 'bricks_global_elements';

	/** @var RestlessWP_Backup_Ring */
	private RestlessWP_Backup_Ring $backups;

	/** @param RestlessWP_Auth_Handler $auth Auth handler instance. */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		parent::__construct( $auth );
		$this->backups = new RestlessWP_Backup_Ring( 'bricks_global_elements_backups' );
	}

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/components';
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
			'list'   => 'List all Bricks saved components (global elements).',
			'get'    => 'Get a single component by ID.',
			'create' => 'Create a new reusable component with elements.',
			'update' => 'Update an existing component by ID.',
			'delete' => 'Delete a component by ID. Backup created automatically.',
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
				__( 'Component not found.', 'restlesswp' ),
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
		$normalized = RestlessWP_Bricks_Normalizer::component( $data );
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
				__( 'Component not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$merged          = array_merge( $items[ $index ], $data );
		$normalized      = RestlessWP_Bricks_Normalizer::component( $merged );
		$items[ $index ] = $normalized;

		$this->save_blob( self::OPTION, $items );

		return $normalized;
	}

	/** @return array|WP_Error */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$item  = $this->find_by_id( $items, $key );

		if ( null === $item ) {
			return new WP_Error(
				'not_found',
				__( 'Component not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$this->backups->record( 'deleted', $items );
		$this->remove_by_id( $items, $key );
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
			'title'      => 'bricks-component',
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'id'       => array(
					'type'        => 'string',
					'description' => __( 'Unique component ID.', 'restlesswp' ),
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Component display name.', 'restlesswp' ),
				),
				'label'    => array(
					'type'        => 'string',
					'description' => __( 'Optional display label.', 'restlesswp' ),
				),
				'elements' => array(
					'type'        => 'array',
					'description' => __( 'Array of Bricks element objects.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'category' => array(
					'type'        => 'string',
					'description' => __( 'Category name.', 'restlesswp' ),
				),
			),
		);
	}
}
