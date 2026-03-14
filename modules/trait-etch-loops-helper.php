<?php
/**
 * Etch Loops Helper Trait — centralizes option-blob I/O for Etch loops.
 *
 * Provides read/write access to the `etch_loops` option, which stores
 * loop presets (saved query configurations) as a keyed associative array.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for Etch loop option operations.
 */
trait RestlessWP_Etch_Loops_Helper {

	/**
	 * Reads the full etch_loops option.
	 *
	 * @return array All loop entries keyed by loop key.
	 */
	public function fetch_all_loops(): array {
		return get_option( 'etch_loops', array() );
	}

	/**
	 * Writes the full etch_loops option.
	 *
	 * @param array $loops Complete loops array to persist.
	 * @return bool True on success, false on failure.
	 */
	public function save_all_loops( array $loops ): bool {
		return update_option( 'etch_loops', $loops );
	}

	/**
	 * Reads a single loop entry by key.
	 *
	 * @param string $key The loop key to look up.
	 * @return array|null The loop entry, or null if not found.
	 */
	public function fetch_loop( string $key ): ?array {
		$loops = $this->fetch_all_loops();

		if ( ! isset( $loops[ $key ] ) ) {
			return null;
		}

		return $loops[ $key ];
	}
}
