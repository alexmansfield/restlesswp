<?php
/**
 * Etch Style Repository — encapsulates option-blob I/O for Etch styles.
 *
 * Provides read/write access to the `etch_styles` option, a referenced-keys
 * scanner, selector-lookup utilities, and orphan detection.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-normalizer.php';

/**
 * Repository for Etch style option operations and selector-aware queries.
 */
class RestlessWP_Etch_Style_Repository {

	/** @var string[] Style keys that should never be considered orphaned or removed. */
	private static array $system_keys = array(
		'etch-section-style',
		'etch-global-variable-style',
	);

	/**
	 * Infers the Etch style type from a CSS selector string.
	 *
	 * Delegates to RestlessWP_Etch_Normalizer which holds the canonical
	 * implementation.
	 *
	 * @param string $selector CSS selector to analyze.
	 * @return string One of: 'class', 'id', 'tag', 'attribute', 'element', 'custom'.
	 */
	public static function infer_style_type( string $selector ): string {
		return RestlessWP_Etch_Normalizer::infer_style_type( $selector );
	}

	/**
	 * Reads the full etch_styles option.
	 *
	 * @return array All style entries keyed by style key.
	 */
	public function fetch_all(): array {
		return get_option( 'etch_styles', array() );
	}

	/**
	 * Writes the full etch_styles option.
	 *
	 * @param array $styles Complete styles array to persist.
	 * @return bool True on success, false on failure.
	 */
	public function save_all( array $styles ): bool {
		return update_option( 'etch_styles', $styles );
	}

	/**
	 * Reads a single style entry by key.
	 *
	 * @param string $key The style key to look up.
	 * @return array|null The style entry, or null if not found.
	 */
	public function fetch( string $key ): ?array {
		$styles = $this->fetch_all();

		if ( ! isset( $styles[ $key ] ) ) {
			return null;
		}

		return $styles[ $key ];
	}

	/**
	 * Checks whether a style key exists.
	 *
	 * @param string $key The style key to check.
	 * @return bool True if the key exists.
	 */
	public function key_exists( string $key ): bool {
		$styles = $this->fetch_all();

		return isset( $styles[ $key ] );
	}

	/**
	 * Finds an existing style by selector within a collection.
	 *
	 * Mirrors Etch's editor behavior: before creating a new style, check
	 * whether the selector already exists and reuse it if so.
	 *
	 * @param string $selector   CSS selector to look up.
	 * @param string $collection Collection name (default 'default').
	 * @return array{key: string, entry: array}|null Match or null.
	 */
	public function find_by_selector( string $selector, string $collection = 'default' ): ?array {
		$styles = $this->fetch_all();

		foreach ( $styles as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_collection = $entry['collection'] ?? 'default';
			$entry_selector   = $entry['selector'] ?? '';

			if ( $entry_selector === $selector && $entry_collection === $collection ) {
				return array(
					'key'   => $key,
					'entry' => $entry,
				);
			}
		}

		return null;
	}

	/**
	 * Checks if a selector is used by a different key in the same collection.
	 *
	 * Mirrors Etch's rename() guard: selectorAlreadyExits check prevents
	 * changing a key's selector to one owned by a different key.
	 *
	 * @param string $selector   CSS selector to check.
	 * @param string $collection Collection name.
	 * @param string $exclude    Key to exclude from the check (the key being updated).
	 * @return string|null The conflicting key, or null if no conflict.
	 */
	public function selector_conflicts_with( string $selector, string $collection, string $exclude ): ?string {
		$match = $this->find_by_selector( $selector, $collection );

		if ( null === $match ) {
			return null;
		}

		if ( $match['key'] === $exclude ) {
			return null;
		}

		return $match['key'];
	}

	/**
	 * Scans post_content across the database for style key references.
	 *
	 * Finds all style keys referenced by blocks via "styles":["key1","key2"]
	 * patterns in post content.
	 *
	 * Known limitation: The LIKE '%"styles"%' + regex scan is the only viable
	 * approach given WordPress's storage model, but it is fragile — false
	 * positives are possible if unrelated JSON uses the same key name. Flag
	 * for future improvement if Etch adds a style-reference index.
	 *
	 * @return array<string, true> Referenced keys as array keys for O(1) lookup.
	 */
	public function find_referenced_keys(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			WHERE post_content LIKE '%\"styles\"%'
			AND post_status IN ('publish', 'draft', 'private')
			AND post_type IN ('page', 'post', 'wp_block', 'wp_template', 'wp_template_part')"
		);

		return $this->extract_keys_from_content( $results );
	}

	/**
	 * Finds style keys that are not referenced by any block content.
	 *
	 * Returns details for each orphaned key. System keys, readonly entries,
	 * and :root selectors are never considered orphaned.
	 *
	 * @return array[] Array of orphan details with key, selector, and collection.
	 */
	public function find_orphaned_keys(): array {
		$styles         = $this->fetch_all();
		$referenced     = $this->find_referenced_keys();
		$orphan_details = array();

		foreach ( $styles as $key => $entry ) {
			if ( isset( $referenced[ $key ] ) ) {
				continue;
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( $this->is_protected_style( $key, $entry ) ) {
				continue;
			}

			$orphan_details[] = array(
				'key'        => $key,
				'selector'   => $entry['selector'] ?? '',
				'collection' => $entry['collection'] ?? '',
			);
		}

		return $orphan_details;
	}

	/**
	 * Extracts style keys from an array of post_content strings.
	 *
	 * @param array $contents Array of post_content values.
	 * @return array<string, true> Keys as array keys for O(1) lookup.
	 */
	private function extract_keys_from_content( array $contents ): array {
		$keys = array();

		foreach ( $contents as $content ) {
			if ( preg_match_all( '/"styles"\s*:\s*\[([^\]]*)\]/', $content, $matches ) ) {
				foreach ( $matches[1] as $inner ) {
					if ( preg_match_all( '/"([^"]+)"/', $inner, $key_matches ) ) {
						foreach ( $key_matches[1] as $key ) {
							$keys[ $key ] = true;
						}
					}
				}
			}
		}

		return $keys;
	}

	/**
	 * Determines whether a style entry is protected from removal.
	 *
	 * Protected styles include system keys, readonly entries, and entries
	 * whose selector starts with :root.
	 *
	 * @param string $key   The style key.
	 * @param array  $entry The style entry data.
	 * @return bool True if the style is protected.
	 */
	private function is_protected_style( string $key, array $entry ): bool {
		if ( in_array( $key, self::$system_keys, true ) ) {
			return true;
		}

		if ( ! empty( $entry['readonly'] ) ) {
			return true;
		}

		$selector = $entry['selector'] ?? '';

		if ( str_starts_with( $selector, ':root' ) ) {
			return true;
		}

		return false;
	}
}
