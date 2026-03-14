<?php
/**
 * Bricks Color Palette Controller — REST endpoints for the color palette.
 *
 * Simplest Bricks controller: standard CRUD on an indexed array of
 * color objects stored in a single option.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/trait-bricks-option-blob.php';

/**
 * REST controller for Bricks color palette.
 */
class RestlessWP_Bricks_Color_Palette_Controller extends RestlessWP_Base_Controller {
	use RestlessWP_Bricks_Option_Blob;

	/** @var string Option name for color palette. */
	private const OPTION = 'bricks_color_palette';

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/color-palette';
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
			'list'   => 'List all colors in the Bricks color palette.',
			'get'    => 'Get a single color by ID.',
			'create' => 'Add a new color to the palette.',
			'update' => 'Update an existing color by ID.',
			'delete' => 'Remove a color from the palette.',
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
				__( 'Color not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $item;
	}

	/** @return array|WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		if ( empty( $data['id'] ) ) {
			$data['id'] = RestlessWP_Bricks_Normalizer::element_id();
		}

		$items      = $this->fetch_blob( self::OPTION );
		$normalized = RestlessWP_Bricks_Normalizer::color( $data );
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
				__( 'Color not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$merged          = array_merge( $items[ $index ], $data );
		$normalized      = RestlessWP_Bricks_Normalizer::color( $merged );
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
				__( 'Color not found.', 'restlesswp' ),
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
			'title'      => 'bricks-color',
			'type'       => 'object',
			'properties' => array(
				'id'   => array(
					'type'        => 'string',
					'description' => __( 'Unique color ID.', 'restlesswp' ),
				),
				'name' => array(
					'type'        => 'string',
					'description' => __( 'Color display name.', 'restlesswp' ),
				),
				'raw'  => array(
					'type'        => 'string',
					'description' => __( 'Raw color value (hex, rgb, etc).', 'restlesswp' ),
				),
			),
		);
	}
}
