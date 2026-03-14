<?php
/**
 * Bricks Normalizer — single source of truth for Bricks data normalization.
 *
 * Centralizes sanitization and defaulting logic for all Bricks write paths.
 * Static utility class — no instance state, no database I/O.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless utility that normalizes Bricks elements, classes, components,
 * variables, colors, and theme styles.
 */
class RestlessWP_Bricks_Normalizer {

	/**
	 * Normalizes a single Bricks element.
	 *
	 * Sanitizes known fields (id, name, label, parent, children) and
	 * preserves unknown settings keys via recursive sanitization.
	 *
	 * @param array $data Raw element data.
	 * @return array Normalized element.
	 */
	public static function element( array $data ): array {
		$normalized = array(
			'id'   => self::ensure_element_id( $data['id'] ?? '' ),
			'name' => sanitize_text_field( $data['name'] ?? 'div' ),
		);

		if ( isset( $data['label'] ) ) {
			$normalized['label'] = sanitize_text_field( $data['label'] );
		}

		if ( isset( $data['parent'] ) ) {
			$normalized['parent'] = sanitize_text_field( $data['parent'] );
		}

		if ( isset( $data['children'] ) && is_array( $data['children'] ) ) {
			$normalized['children'] = array_map( 'sanitize_text_field', $data['children'] );
		}

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$normalized['settings'] = self::sanitize_recursive( $data['settings'] );
		}

		return self::merge_unknown_keys( $data, $normalized, array(
			'id', 'name', 'label', 'parent', 'children', 'settings',
		) );
	}

	/**
	 * Normalizes an array of elements.
	 *
	 * @param array $elements Raw elements array.
	 * @return array Normalized elements.
	 */
	public static function elements( array $elements ): array {
		return array_map( array( self::class, 'element' ), $elements );
	}

	/**
	 * Generates a 6-character element ID matching Bricks' pattern.
	 *
	 * @return string 6-char lowercase alphanumeric hash.
	 */
	public static function element_id(): string {
		return substr( md5( uniqid( wp_rand(), true ) ), 0, 6 );
	}

	/**
	 * Validates a Bricks element ID format.
	 *
	 * @param string $id The ID to validate.
	 * @return bool True if the ID matches Bricks' 6-char format.
	 */
	public static function validate_element_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]{6}$/', $id );
	}

	/**
	 * Normalizes a global class entry.
	 *
	 * Sanitizes known fields, preserves the settings blob via recursive
	 * sanitization, and updates modified timestamp and user ID.
	 *
	 * @param array $data Raw global class data.
	 * @return array Normalized global class.
	 */
	public static function global_class( array $data ): array {
		$normalized = array(
			'id'       => sanitize_text_field( $data['id'] ?? '' ),
			'name'     => sanitize_text_field( $data['name'] ?? '' ),
			'settings' => isset( $data['settings'] ) && is_array( $data['settings'] )
				? self::sanitize_recursive( $data['settings'] )
				: array(),
			'modified' => time(),
			'user_id'  => get_current_user_id(),
		);

		if ( isset( $data['label'] ) ) {
			$normalized['label'] = sanitize_text_field( $data['label'] );
		}

		if ( isset( $data['category'] ) ) {
			$normalized['category'] = sanitize_text_field( $data['category'] );
		}

		return self::merge_unknown_keys( $data, $normalized, array(
			'id', 'name', 'label', 'settings', 'modified', 'user_id', 'category',
		) );
	}

	/**
	 * Normalizes a component entry.
	 *
	 * Sanitizes the component wrapper and normalizes nested elements.
	 *
	 * @param array $data Raw component data.
	 * @return array Normalized component.
	 */
	public static function component( array $data ): array {
		$normalized = array(
			'id'   => sanitize_text_field( $data['id'] ?? '' ),
			'name' => sanitize_text_field( $data['name'] ?? '' ),
		);

		if ( isset( $data['label'] ) ) {
			$normalized['label'] = sanitize_text_field( $data['label'] );
		}

		if ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
			$normalized['elements'] = self::elements( $data['elements'] );
		}

		if ( isset( $data['category'] ) ) {
			$normalized['category'] = sanitize_text_field( $data['category'] );
		}

		return self::merge_unknown_keys( $data, $normalized, array(
			'id', 'name', 'label', 'elements', 'category',
		) );
	}

	/**
	 * Normalizes a variable entry.
	 *
	 * @param array $data Raw variable data.
	 * @return array Normalized variable.
	 */
	public static function variable( array $data ): array {
		$normalized = array(
			'id'   => sanitize_text_field( $data['id'] ?? '' ),
			'name' => sanitize_text_field( $data['name'] ?? '' ),
		);

		if ( isset( $data['value'] ) ) {
			$normalized['value'] = sanitize_text_field( $data['value'] );
		}

		if ( isset( $data['type'] ) ) {
			$normalized['type'] = sanitize_text_field( $data['type'] );
		}

		if ( isset( $data['category'] ) ) {
			$normalized['category'] = sanitize_text_field( $data['category'] );
		}

		return self::merge_unknown_keys( $data, $normalized, array(
			'id', 'name', 'value', 'type', 'category',
		) );
	}

	/**
	 * Normalizes a color palette entry.
	 *
	 * @param array $data Raw color data.
	 * @return array Normalized color entry.
	 */
	public static function color( array $data ): array {
		$normalized = array(
			'id' => sanitize_text_field( $data['id'] ?? '' ),
		);

		if ( isset( $data['name'] ) ) {
			$normalized['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['raw'] ) ) {
			$normalized['raw'] = sanitize_text_field( $data['raw'] );
		}

		return self::merge_unknown_keys( $data, $normalized, array(
			'id', 'name', 'raw',
		) );
	}

	/**
	 * Normalizes a theme style preset.
	 *
	 * Preserves nested control values via recursive sanitization.
	 *
	 * @param array $data Raw theme style data.
	 * @return array Normalized theme style.
	 */
	public static function theme_style( array $data ): array {
		$normalized = array(
			'id'   => sanitize_text_field( $data['id'] ?? '' ),
			'name' => sanitize_text_field( $data['name'] ?? '' ),
		);

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$normalized['settings'] = self::sanitize_recursive( $data['settings'] );
		}

		if ( isset( $data['conditions'] ) && is_array( $data['conditions'] ) ) {
			$normalized['conditions'] = self::sanitize_recursive( $data['conditions'] );
		}

		return self::merge_unknown_keys( $data, $normalized, array(
			'id', 'name', 'settings', 'conditions',
		) );
	}

	/**
	 * Recursively sanitizes an array of mixed values.
	 *
	 * Preserves unknown keys so that future Bricks fields pass through
	 * without data loss.
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
	 * Applies wp_slash() for post meta writes.
	 *
	 * @param mixed $data Data to slash.
	 * @return mixed Slashed data.
	 */
	public static function slash_for_meta( $data ) {
		return wp_slash( $data );
	}

	/**
	 * Ensures an element has a valid 6-char ID, generating one if needed.
	 *
	 * @param string $id Existing ID or empty string.
	 * @return string Valid element ID.
	 */
	private static function ensure_element_id( string $id ): string {
		if ( self::validate_element_id( $id ) ) {
			return $id;
		}

		return self::element_id();
	}

	/**
	 * Merges unknown keys from source into normalized data.
	 *
	 * @param array    $source     Original data with potential unknown keys.
	 * @param array    $normalized Already-normalized data.
	 * @param string[] $known_keys Keys already handled by normalization.
	 * @return array Merged result.
	 */
	private static function merge_unknown_keys( array $source, array $normalized, array $known_keys ): array {
		foreach ( $source as $key => $value ) {
			if ( in_array( $key, $known_keys, true ) ) {
				continue;
			}

			$safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

			if ( is_array( $value ) ) {
				$normalized[ $safe_key ] = self::sanitize_recursive( $value );
			} elseif ( is_string( $value ) ) {
				$normalized[ $safe_key ] = sanitize_text_field( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$normalized[ $safe_key ] = $value;
			} else {
				$normalized[ $safe_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $normalized;
	}
}
