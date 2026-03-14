<?php
/**
 * Bricks Theme Styles Controller — REST endpoints for theme style presets.
 *
 * Theme styles contain nested control values (typography, colors, spacing)
 * preserved via recursive sanitization.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/trait-bricks-option-blob.php';

/**
 * REST controller for Bricks theme styles.
 */
class RestlessWP_Bricks_Theme_Styles_Controller extends RestlessWP_Base_Controller {
	use RestlessWP_Bricks_Option_Blob;

	/** @var string Option name for theme styles. */
	private const OPTION = 'bricks_theme_styles';

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/theme-styles';
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
			'list'   => 'List all Bricks theme style presets.',
			'get'    => 'Get a single theme style by ID.',
			'create' => 'Create a new theme style preset.',
			'update' => 'Update a theme style. Nested control values are preserved.',
			'delete' => 'Delete a theme style preset.',
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
				__( 'Theme style not found.', 'restlesswp' ),
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
		$normalized = RestlessWP_Bricks_Normalizer::theme_style( $data );
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
				__( 'Theme style not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$merged          = array_merge( $items[ $index ], $data );
		$normalized      = RestlessWP_Bricks_Normalizer::theme_style( $merged );
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
				__( 'Theme style not found.', 'restlesswp' ),
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
			'title'      => 'bricks-theme-style',
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'id'         => array(
					'type'        => 'string',
					'description' => __( 'Unique theme style ID.', 'restlesswp' ),
				),
				'name'       => array(
					'type'        => 'string',
					'description' => __( 'Theme style preset name.', 'restlesswp' ),
				),
				'settings'   => array(
					'type'        => 'object',
					'description' => __( 'Nested control values (typography, colors, spacing, etc).', 'restlesswp' ),
				),
				'conditions' => array(
					'type'        => 'array',
					'description' => __( 'Conditions for when this style applies.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
			),
		);
	}
}
