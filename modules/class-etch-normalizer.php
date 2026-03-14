<?php
/**
 * Etch Normalizer — single source of truth for Etch data normalization.
 *
 * Centralizes sanitization and defaulting logic that must run on every
 * write path: standalone CRUD, interchange import, and PATCH updates.
 * Static utility class — no instance state, no database I/O.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-block-converter.php';

/**
 * Stateless utility that normalizes Etch styles, loops, blocks, and content.
 */
class RestlessWP_Etch_Normalizer {

	/**
	 * HTML element names recognized for style type inference.
	 *
	 * @var string[]
	 */
	private static array $html_elements = array(
		'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio',
		'b', 'base', 'bdi', 'bdo', 'blockquote', 'body', 'br', 'button',
		'canvas', 'caption', 'cite', 'code', 'col', 'colgroup',
		'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'div', 'dl', 'dt',
		'em', 'embed',
		'fieldset', 'figcaption', 'figure', 'footer', 'form',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html',
		'i', 'iframe', 'img', 'input', 'ins',
		'kbd',
		'label', 'legend', 'li', 'link',
		'main', 'map', 'mark', 'menu', 'meta', 'meter',
		'nav', 'noscript',
		'object', 'ol', 'optgroup', 'option', 'output',
		'p', 'picture', 'pre', 'progress',
		'q',
		'rp', 'rt', 'ruby',
		's', 'samp', 'script', 'search', 'section', 'select', 'slot', 'small',
		'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup',
		'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th',
		'thead', 'time', 'title', 'tr', 'track',
		'u', 'ul',
		'var', 'video',
		'wbr',
	);

	/**
	 * Normalizes a style entry: sanitize, infer type, force defaults.
	 *
	 * @param array $data Raw style data.
	 * @return array Normalized style entry (without key).
	 */
	public static function style( array $data ): array {
		$selector = sanitize_text_field( $data['selector'] ?? '' );
		$type     = ! empty( $data['type'] )
			? sanitize_text_field( $data['type'] )
			: self::infer_style_type( $selector );

		return array(
			'type'       => $type,
			'selector'   => $selector,
			'css'        => self::sanitize_css( $data['css'] ?? '' ),
			'collection' => 'default',
			'readonly'   => isset( $data['readonly'] ) ? (bool) $data['readonly'] : false,
		);
	}

	/**
	 * Normalizes a loop entry: sanitize name, default global, sanitize config.
	 *
	 * @param array $data Raw loop data.
	 * @return array Normalized loop entry (without key).
	 */
	public static function loop( array $data ): array {
		$normalized = array(
			'name'   => sanitize_text_field( $data['name'] ?? '' ),
			'global' => isset( $data['global'] ) ? (bool) $data['global'] : true,
			'config' => isset( $data['config'] ) && is_array( $data['config'] )
				? self::sanitize_config( $data['config'] )
				: array(),
		);

		if ( isset( $data['key'] ) ) {
			$normalized['key'] = sanitize_text_field( $data['key'] );
		}

		if ( isset( $data['_preset_hash'] ) ) {
			$normalized['_preset_hash'] = $data['_preset_hash'];
		}

		return $normalized;
	}

	/**
	 * Detects docs-format blocks and converts via the block converter.
	 *
	 * Also encodes any plain-JS script.code fields to base64 for Etch
	 * storage. Agents send plain JS; Etch stores base64.
	 *
	 * @param array $blocks Array of block data.
	 * @return array|\WP_Error The (possibly converted) blocks array, or WP_Error on failure.
	 */
	public static function blocks( array $blocks ) {
		$needs_conversion = false;

		foreach ( $blocks as $block ) {
			if ( self::block_needs_conversion( $block ) ) {
				$needs_conversion = true;
				break;
			}
		}

		if ( $needs_conversion ) {
			$blocks = RestlessWP_Etch_Block_Converter::convert( $blocks );

			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}
		}

		return self::encode_block_scripts( $blocks );
	}

	/**
	 * Resolves content from blocks or content field.
	 *
	 * Blocks take precedence over content. Docs-format blocks are
	 * auto-converted before serialization.
	 *
	 * @param array $data Request data with optional blocks/content.
	 * @return string|\WP_Error Serialized block markup, sanitized HTML, or WP_Error on failure.
	 */
	public static function content( array $data ) {
		if ( isset( $data['blocks'] ) ) {
			$blocks = self::blocks( $data['blocks'] );

			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}

			return wp_slash( serialize_blocks( $blocks ) );
		}

		if ( isset( $data['content'] ) ) {
			return wp_kses_post( $data['content'] );
		}

		return '';
	}

	/**
	 * Infers the Etch style type from a CSS selector string.
	 *
	 * Mirrors Etch's own `inferTypeFromSelector` logic in the editor
	 * frontend. The type controls Etch's style loading strategy.
	 *
	 * @param string $selector CSS selector to analyze.
	 * @return string One of: 'class', 'id', 'tag', 'attribute', 'element', 'custom'.
	 */
	public static function infer_style_type( string $selector ): string {
		$s = trim( $selector );

		if ( '' === $s ) {
			return 'custom';
		}

		if ( preg_match( '/^\.[a-zA-Z][a-zA-Z0-9_-]*$/', $s ) ) {
			return 'class';
		}

		if ( preg_match( '/^#[a-zA-Z][a-zA-Z0-9_-]*$/', $s ) ) {
			return 'id';
		}

		if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $s ) ) {
			return in_array( strtolower( $s ), self::$html_elements, true ) ? 'tag' : 'custom';
		}

		if ( preg_match( '/^\[[a-zA-Z][a-zA-Z0-9_-]*(?:(?:[~|^$*]?=(?:"[^"]*"|\'[^\']*\'|[a-zA-Z0-9_-]+))?\s*[iI]?)?\]$/', $s ) ) {
			return 'attribute';
		}

		if ( preg_match( '/:where\(\[data-etch-element="[^"]+"\]\)/', $s ) ) {
			return 'element';
		}

		return 'custom';
	}

	/**
	 * Sanitizes a loop config object, preserving nested structure.
	 *
	 * Known keys (type, args, data) receive explicit handling. Unknown
	 * keys are preserved and sanitized recursively so that future Etch
	 * config fields pass through without data loss.
	 *
	 * @param array $config Raw config data.
	 * @return array Sanitized config.
	 */
	public static function sanitize_config( array $config ): array {
		$sanitized = array(
			'type' => sanitize_text_field( $config['type'] ?? '' ),
		);

		if ( isset( $config['args'] ) && is_array( $config['args'] ) ) {
			$sanitized['args'] = self::sanitize_recursive( $config['args'] );
		}

		if ( isset( $config['data'] ) && is_array( $config['data'] ) ) {
			$sanitized['data'] = self::sanitize_recursive( $config['data'] );
		}

		$known_keys = array( 'type', 'args', 'data' );

		foreach ( $config as $key => $value ) {
			if ( in_array( $key, $known_keys, true ) ) {
				continue;
			}

			$safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

			if ( is_array( $value ) ) {
				$sanitized[ $safe_key ] = self::sanitize_recursive( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $safe_key ] = sanitize_text_field( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $safe_key ] = $value;
			} else {
				$sanitized[ $safe_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Recursively sanitizes an array of mixed values.
	 *
	 * @param array $data Data to sanitize.
	 * @return array Sanitized data.
	 */
	public static function sanitize_recursive( array $data ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			$safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

			if ( is_array( $value ) ) {
				$result[ $safe_key ] = self::sanitize_recursive( $value );
			} elseif ( is_string( $value ) ) {
				$result[ $safe_key ] = sanitize_text_field( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$result[ $safe_key ] = $value;
			} else {
				$result[ $safe_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $result;
	}

	/**
	 * Normalizes every entry in a styles blob and checks for intra-blob
	 * selector duplicates. Modifies $blob in place.
	 *
	 * @param array $blob Styles map keyed by style key, passed by reference.
	 * @return WP_Error|null WP_Error on duplicate selector, null on success.
	 */
	public static function styles_blob( array &$blob ): ?\WP_Error {
		$seen = array();

		foreach ( $blob as $key => &$entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry      = self::style( $entry );
			$selector   = $entry['selector'] ?? '';
			$collection = $entry['collection'] ?? '';
			$sig        = $collection . '::' . $selector;

			if ( '' !== $selector && isset( $seen[ $sig ] ) ) {
				return new \WP_Error(
					'conflict',
					sprintf(
						/* translators: 1: CSS selector, 2: first key, 3: duplicate key */
						__( 'Duplicate selector "%1$s" on keys "%2$s" and "%3$s".', 'restlesswp' ),
						$selector,
						$seen[ $sig ],
						$key
					),
					array( 'status' => 409 )
				);
			}

			if ( '' !== $selector ) {
				$seen[ $sig ] = $key;
			}
		}
		unset( $entry );

		return null;
	}

	/**
	 * Sanitizes a CSS string for safe storage.
	 *
	 * Unlike sanitize_text_field(), this preserves multi-line CSS and only
	 * strips closing style tags to prevent injection — matching Etch's own
	 * sanitization approach.
	 *
	 * @param string $css Raw CSS string.
	 * @return string Sanitized CSS.
	 */
	private static function sanitize_css( string $css ): string {
		return preg_replace( '#</style\s*>#i', '', $css );
	}

	/**
	 * Walks a block tree and base64-encodes script.code on etch/element blocks.
	 *
	 * Validates that script.id is present when script.code is provided.
	 * Agents send plain JavaScript; this encodes it for Etch storage.
	 *
	 * @param array $blocks Block tree.
	 * @return array|\WP_Error Blocks with encoded scripts, or WP_Error on validation failure.
	 */
	public static function encode_block_scripts( array $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( isset( $block['attrs']['script'] ) ) {
				$result = self::encode_script( $block['attrs']['script'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$block['attrs']['script'] = $result;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::encode_block_scripts( $block['innerBlocks'] );

				if ( is_wp_error( $block['innerBlocks'] ) ) {
					return $block['innerBlocks'];
				}
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Walks a block tree and base64-decodes script.code on etch/element blocks.
	 *
	 * Used on the read path so agents always receive plain JavaScript.
	 *
	 * @param array $blocks Block tree from parse_blocks().
	 * @return array Blocks with decoded scripts.
	 */
	public static function decode_block_scripts( array $blocks ): array {
		foreach ( $blocks as &$block ) {
			if ( isset( $block['attrs']['script']['code'] ) ) {
				$decoded = base64_decode( $block['attrs']['script']['code'], true );

				if ( false !== $decoded ) {
					$block['attrs']['script']['code'] = $decoded;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::decode_block_scripts( $block['innerBlocks'] );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Validates and encodes a single script object.
	 *
	 * @param array $script The script object with id and code.
	 * @return array|\WP_Error Encoded script or WP_Error on validation failure.
	 */
	private static function encode_script( array $script ) {
		if ( ! isset( $script['code'] ) ) {
			return $script;
		}

		if ( empty( $script['id'] ) ) {
			return new \WP_Error(
				'validation_error',
				__( 'script.id is required when script.code is present. Etch uses this ID to deduplicate scripts on the page — without it, the same script may run multiple times and cause bugs.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$script['code'] = base64_encode( $script['code'] );

		return $script;
	}

	/**
	 * Checks if a single block needs docs-to-Etch conversion.
	 *
	 * @param array $block Block data array.
	 * @return bool True if the block needs conversion.
	 */
	private static function block_needs_conversion( array $block ): bool {
		$block_name = $block['blockName'] ?? '';
		$attrs      = $block['attrs'] ?? array();

		if ( empty( $block_name ) ) {
			return false;
		}

		$is_non_etch   = 0 !== strpos( $block_name, 'etch/' );
		$has_etch_data = isset( $attrs['metadata']['etchData'] );

		return $is_non_etch && $has_etch_data;
	}
}
