<?php
/**
 * Etch Stylesheets Helper Trait — centralizes option-blob I/O for Etch stylesheets.
 *
 * Provides read/write access to the `etch_global_stylesheets` option, which stores
 * named CSS documents as a keyed associative array of { name, css } entries.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for Etch stylesheet option operations.
 */
trait RestlessWP_Etch_Stylesheets_Helper {

	/**
	 * Reads the full etch_global_stylesheets option.
	 *
	 * @return array All stylesheet entries keyed by stylesheet key.
	 */
	public function fetch_all_stylesheets(): array {
		return get_option( 'etch_global_stylesheets', array() );
	}

	/**
	 * Writes the full etch_global_stylesheets option.
	 *
	 * @param array $stylesheets Complete stylesheets array to persist.
	 * @return bool True on success, false on failure.
	 */
	public function save_all_stylesheets( array $stylesheets ): bool {
		return update_option( 'etch_global_stylesheets', $stylesheets );
	}

	/**
	 * Reads a single stylesheet entry by key.
	 *
	 * @param string $key The stylesheet key to look up.
	 * @return array|null The stylesheet entry, or null if not found.
	 */
	public function fetch_stylesheet( string $key ): ?array {
		$stylesheets = $this->fetch_all_stylesheets();

		if ( ! isset( $stylesheets[ $key ] ) ) {
			return null;
		}

		return $stylesheets[ $key ];
	}
}
