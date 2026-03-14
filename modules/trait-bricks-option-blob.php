<?php
/**
 * Bricks Option Blob Trait — shared helpers for indexed-array option storage.
 *
 * Bricks stores option blobs as indexed arrays where each item has an 'id'
 * field, unlike Etch's keyed maps. This trait provides lookup, mutation,
 * and persistence helpers used by all Bricks option-based controllers.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared option blob I/O for Bricks controllers.
 */
trait RestlessWP_Bricks_Option_Blob {

	/**
	 * Fetches an option blob as an array.
	 *
	 * @param string $option WordPress option name.
	 * @return array The option value or empty array.
	 */
	protected function fetch_blob( string $option ): array {
		$data = get_option( $option, array() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Saves an option blob, deleting the option if the array is empty.
	 *
	 * Mirrors Bricks' own empty-is-delete pattern.
	 *
	 * @param string $option WordPress option name.
	 * @param array  $data   Data to persist.
	 * @return void
	 */
	protected function save_blob( string $option, array $data ): void {
		if ( empty( $data ) ) {
			delete_option( $option );
			return;
		}

		update_option( $option, $data );
	}

	/**
	 * Finds an item by its 'id' field in an indexed array.
	 *
	 * @param array  $items Array of items with 'id' fields.
	 * @param string $id    The ID to search for.
	 * @return array|null The matching item or null.
	 */
	protected function find_by_id( array $items, string $id ): ?array {
		foreach ( $items as $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Finds the numeric index of an item by its 'id' field.
	 *
	 * @param array  $items Array of items with 'id' fields.
	 * @param string $id    The ID to search for.
	 * @return int|null The array index or null.
	 */
	protected function find_index_by_id( array $items, string $id ): ?int {
		foreach ( $items as $index => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Removes an item by its 'id' field and re-indexes the array.
	 *
	 * @param array  $items Array of items, passed by reference.
	 * @param string $id    The ID to remove.
	 * @return bool True if an item was removed.
	 */
	protected function remove_by_id( array &$items, string $id ): bool {
		$index = $this->find_index_by_id( $items, $id );

		if ( null === $index ) {
			return false;
		}

		array_splice( $items, $index, 1 );

		return true;
	}
}
