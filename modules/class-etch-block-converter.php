<?php
/**
 * Etch block converter — transforms blocks from Etch docs format to editor format.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless utility that converts blocks from Etch docs format to editor format.
 *
 * All methods are static. No database I/O.
 */
class RestlessWP_Etch_Block_Converter {

	/**
	 * Convert an array of blocks from docs format to editor format.
	 *
	 * @param array $blocks Blocks in docs format.
	 * @return array|WP_Error Converted blocks, or WP_Error on validation failure.
	 */
	public static function convert( array $blocks ): array|WP_Error {
		$result = [];

		foreach ( $blocks as $block ) {
			$converted = self::convert_block( $block );

			if ( is_wp_error( $converted ) ) {
				return $converted;
			}

			$result[] = $converted;
		}

		return $result;
	}

	/**
	 * Convert a single block, dispatching by etchData type.
	 *
	 * @param mixed $block Block data.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function convert_block( $block ): array|WP_Error {
		if ( ! is_array( $block ) ) {
			return $block;
		}

		$block_name = $block['blockName'] ?? '';
		$attrs      = $block['attrs'] ?? [];
		$etch_data  = $attrs['metadata']['etchData'] ?? null;

		if ( str_starts_with( $block_name, 'etch/' ) ) {
			return self::recurse_inner_blocks( $block );
		}

		if ( ! $etch_data ) {
			return self::recurse_inner_blocks( $block );
		}

		$block_info = $etch_data['block'] ?? [];
		$block_type = $block_info['type'] ?? '';

		return match ( $block_type ) {
			'html'      => self::convert_html_block( $block, $etch_data ),
			'loop'      => self::convert_loop_block( $block, $etch_data ),
			'condition' => self::convert_condition_block( $block, $etch_data ),
			'text'      => self::convert_text_block( $etch_data ),
			default     => self::recurse_inner_blocks( $block ),
		};
	}

	/**
	 * Recursively convert inner blocks of a passthrough block.
	 *
	 * @param array $block Block with potential innerBlocks.
	 * @return array|WP_Error Block with converted inner blocks, or WP_Error.
	 */
	public static function recurse_inner_blocks( array $block ): array|WP_Error {
		if ( empty( $block['innerBlocks'] ) ) {
			return $block;
		}

		$converted = self::convert( $block['innerBlocks'] );

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$block['innerBlocks'] = $converted;

		return $block;
	}

	/**
	 * Convert core/group + etchData.type=html to etch/element.
	 *
	 * @param array $block    Original block.
	 * @param array $etch_data Etch data from metadata.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function convert_html_block( array $block, array $etch_data ): array|WP_Error {
		$error = self::validate_html_block( $etch_data );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$nested_data    = $etch_data['nestedData'] ?? null;
		$remove_wrapper = $etch_data['removeWrapper'] ?? false;
		$block_name     = $block['blockName'] ?? '';

		if ( $remove_wrapper && $nested_data && 'core/paragraph' === $block_name ) {
			return self::convert_paragraph_nested( $block, $etch_data );
		}

		return self::build_html_element( $block, $etch_data );
	}

	/**
	 * Validate etchData for an html-type block.
	 *
	 * @param array $etch_data Etch data to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_html_block( array $etch_data ): true|WP_Error {
		if ( ! isset( $etch_data['attributes'] ) || ! is_array( $etch_data['attributes'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'HTML block requires "attributes" as an array in etchData.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $etch_data['styles'] ) || ! is_array( $etch_data['styles'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'HTML block requires "styles" as an array in etchData.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Build etch/element from a validated html-type block.
	 *
	 * @param array $block     Original block.
	 * @param array $etch_data Etch data.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function build_html_element( array $block, array $etch_data ): array|WP_Error {
		$block_info = $etch_data['block'] ?? [];
		$new_attrs  = [
			'tag'        => $block_info['tag'] ?? 'div',
			'attributes' => $etch_data['attributes'] ?? [],
			'styles'     => $etch_data['styles'] ?? [],
		];

		$script = $etch_data['script'] ?? null;
		if ( $script ) {
			$new_attrs['script'] = $script;
		}

		$metadata_name = $block['attrs']['metadata']['name'] ?? null;
		if ( $metadata_name ) {
			$new_attrs['metadata'] = [ 'name' => $metadata_name ];
		}

		$inner_blocks = self::convert( $block['innerBlocks'] ?? [] );

		if ( is_wp_error( $inner_blocks ) ) {
			return $inner_blocks;
		}

		return [
			'blockName'    => 'etch/element',
			'attrs'        => $new_attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => "\n\n",
			'innerContent' => self::build_inner_content( $inner_blocks ),
		];
	}

	/**
	 * Convert core/paragraph + nestedData to etch/element wrapping etch/text.
	 *
	 * @param array $block     Original block.
	 * @param array $etch_data Etch data.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function convert_paragraph_nested( array $block, array $etch_data ): array|WP_Error {
		$nested_data = $etch_data['nestedData'] ?? [];
		$inner_html  = $block['innerHTML'] ?? '';

		if ( ! is_array( $nested_data ) || empty( $nested_data ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Paragraph nested conversion requires non-empty "nestedData" array.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		return self::build_nested_element( $nested_data, $inner_html );
	}

	/**
	 * Build the etch/element wrapping etch/text from nested data.
	 *
	 * @param array  $nested_data Nested data entries.
	 * @param string $inner_html  Original innerHTML.
	 * @return array The converted etch/element block.
	 */
	public static function build_nested_element( array $nested_data, string $inner_html ): array {
		$first_nested  = reset( $nested_data );
		$nested_tag    = $first_nested['block']['tag'] ?? 'span';
		$nested_attrs  = $first_nested['attributes'] ?? [];
		$nested_styles = $first_nested['styles'] ?? [];

		$text_content = self::extract_text_from_html( $inner_html );
		$text_block   = self::make_text_block( $text_content );

		return [
			'blockName'    => 'etch/element',
			'attrs'        => [
				'tag'        => $nested_tag,
				'attributes' => $nested_attrs,
				'styles'     => $nested_styles,
			],
			'innerBlocks'  => [ $text_block ],
			'innerHTML'    => "\n\n",
			'innerContent' => [ "\n", null, "\n" ],
		];
	}

	/**
	 * Convert core/group + etchData.type=loop to etch/loop.
	 *
	 * @param array $block     Original block.
	 * @param array $etch_data Etch data.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function convert_loop_block( array $block, array $etch_data ): array|WP_Error {
		$loop_info = $etch_data['block']['loop'] ?? [];

		$error = self::validate_loop_block( $loop_info );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return self::build_loop_element( $block, $loop_info );
	}

	/**
	 * Validate loop block data.
	 *
	 * @param array $loop_info Loop info from etchData.block.loop.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_loop_block( array $loop_info ): true|WP_Error {
		$has_target = ! empty( $loop_info['target'] )
			|| ! empty( $loop_info['targetPath'] )
			|| ! empty( $loop_info['targetItemId'] );

		if ( ! $has_target ) {
			return new WP_Error(
				'validation_error',
				__( 'Loop block requires "target", "targetPath", or "targetItemId" in loop data.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Build etch/loop from validated loop data.
	 *
	 * @param array $block     Original block.
	 * @param array $loop_info Loop info from etchData.block.loop.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function build_loop_element( array $block, array $loop_info ): array|WP_Error {
		$target   = self::resolve_loop_target( $loop_info );
		$new_attrs = [
			'target' => $target,
			'itemId' => $loop_info['itemId'] ?? 'item',
		];

		self::add_optional_loop_attrs( $new_attrs, $loop_info );

		$inner_blocks = self::convert( $block['innerBlocks'] ?? [] );

		if ( is_wp_error( $inner_blocks ) ) {
			return $inner_blocks;
		}

		return [
			'blockName'    => 'etch/loop',
			'attrs'        => $new_attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => "\n\n",
			'innerContent' => self::build_inner_content( $inner_blocks ),
		];
	}

	/**
	 * Resolve loop target from direct target or composite parts.
	 *
	 * @param array $loop_info Loop info.
	 * @return string Resolved target string.
	 */
	public static function resolve_loop_target( array $loop_info ): string {
		$direct_target  = $loop_info['target'] ?? null;
		$target_item_id = $loop_info['targetItemId'] ?? '';
		$target_path    = $loop_info['targetPath'] ?? '';

		if ( $direct_target ) {
			return $direct_target;
		}

		if ( $target_item_id && $target_path ) {
			return "{$target_item_id}.{$target_path}";
		}

		return $target_path;
	}

	/**
	 * Add optional loop attributes (indexId, loopId, loopParams).
	 *
	 * @param array $new_attrs  Attributes array (passed by reference).
	 * @param array $loop_info  Loop info.
	 * @return void
	 */
	public static function add_optional_loop_attrs( array &$new_attrs, array $loop_info ): void {
		$index_id    = $loop_info['indexId'] ?? '';
		$loop_id     = $loop_info['loopId'] ?? null;
		$loop_params = $loop_info['loopParams'] ?? null;

		if ( $index_id ) {
			$new_attrs['indexId'] = $index_id;
		}

		if ( $loop_id ) {
			$new_attrs['loopId'] = $loop_id;
		}

		if ( $loop_params ) {
			$new_attrs['loopParams'] = $loop_params;
		}
	}

	/**
	 * Convert core/group + etchData.type=condition to etch/condition.
	 *
	 * @param array $block     Original block.
	 * @param array $etch_data Etch data.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function convert_condition_block( array $block, array $etch_data ): array|WP_Error {
		$error = self::validate_condition_block( $etch_data );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$condition_info   = $etch_data['block']['condition'] ?? [];
		$condition_string = $etch_data['block']['conditionString'] ?? '';

		$inner_blocks = self::convert( $block['innerBlocks'] ?? [] );

		if ( is_wp_error( $inner_blocks ) ) {
			return $inner_blocks;
		}

		return [
			'blockName'    => 'etch/condition',
			'attrs'        => [
				'condition'       => $condition_info,
				'conditionString' => $condition_string,
			],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => "\n\n",
			'innerContent' => self::build_inner_content( $inner_blocks ),
		];
	}

	/**
	 * Validate condition block data.
	 *
	 * @param array $etch_data Etch data.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_condition_block( array $etch_data ): true|WP_Error {
		$block_info = $etch_data['block'] ?? [];

		if ( ! isset( $block_info['condition'] ) || ! is_array( $block_info['condition'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Condition block requires "condition" as an array.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $block_info['conditionString'] ) || ! is_string( $block_info['conditionString'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Condition block requires "conditionString" as a string.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Convert etchData.type=text to etch/text.
	 *
	 * @param array $etch_data Etch data.
	 * @return array|WP_Error Converted block or WP_Error.
	 */
	public static function convert_text_block( array $etch_data ): array|WP_Error {
		$block_info = $etch_data['block'] ?? [];

		if ( ! isset( $block_info['content'] ) || ! is_string( $block_info['content'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Text block requires "content" as a string.', 'restlesswp' ),
				[ 'status' => 400 ]
			);
		}

		return self::make_text_block( $block_info['content'] );
	}

	/**
	 * Create an etch/text block array.
	 *
	 * @param string $content Text content.
	 * @return array The etch/text block.
	 */
	public static function make_text_block( string $content ): array {
		return [
			'blockName'    => 'etch/text',
			'attrs'        => [ 'content' => $content ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * Build innerContent array: ["\n", null, "\n", ...] for N children, or ["\n\n"] for leaf.
	 *
	 * @param array $inner_blocks Child blocks.
	 * @return array The innerContent array.
	 */
	public static function build_inner_content( array $inner_blocks ): array {
		if ( empty( $inner_blocks ) ) {
			return [ "\n\n" ];
		}

		$content = [ "\n" ];

		$count = count( $inner_blocks );
		for ( $i = 0; $i < $count; $i++ ) {
			$content[] = null;
			$content[] = "\n";
		}

		return $content;
	}

	/**
	 * Extract text content from innerHTML.
	 *
	 * Handles patterns like '<p><a data-etch-ref="x">{item.label}</a></p>'.
	 *
	 * @param string $html The innerHTML string.
	 * @return string Extracted text content.
	 */
	public static function extract_text_from_html( string $html ): string {
		$html = preg_replace( '/^<p>/', '', $html );
		$html = preg_replace( '/<\/p>$/', '', $html );

		if ( preg_match( '/>([^<]+)</', $html, $matches ) ) {
			return $matches[1];
		}

		return $html;
	}
}
