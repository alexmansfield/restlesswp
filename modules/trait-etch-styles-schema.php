<?php
/**
 * Etch Styles Schema — JSON Schema definition for an Etch style resource.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the item schema for Etch style endpoints.
 */
trait RestlessWP_Etch_Styles_Schema {

	/** @return array JSON Schema array. */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-style',
			'type'       => 'object',
			'required'   => array( 'selector', 'css' ),
			'properties' => array(
				'key'        => array(
					'type'        => 'string',
					'description' => __( 'Unique style identifier (e.g. etch-style-abc123). Auto-generated if omitted on create. Supply your own for deterministic key naming. Blocks reference this key in their attrs.styles array to apply this style.', 'restlesswp' ),
				),
				'type'       => array(
					'type'        => 'string',
					'enum'        => array( 'class', 'id', 'tag', 'attribute', 'element', 'custom' ),
					'description' => __( 'Style type. Inferred from selector if omitted.', 'restlesswp' ),
				),
				'selector'   => array(
					'type'        => 'string',
					'description' => __( 'CSS selector. Required on create. Must be unique per collection.', 'restlesswp' ),
				),
				'css'        => array(
					'type'        => 'string',
					'description' => __( 'CSS declarations only, no selector/braces. Required on create.', 'restlesswp' ),
				),
				'collection' => array(
					'type'        => 'string',
					'description' => __( 'Style collection. Always use "default".', 'restlesswp' ),
				),
				'readonly'   => array(
					'type'        => 'boolean',
					'description' => __( 'When true, protected from editing in the Etch UI.', 'restlesswp' ),
				),
			),
		);
	}
}
