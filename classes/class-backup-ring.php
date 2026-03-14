<?php
/**
 * Generic ring buffer for pre-destructive backups.
 *
 * Maintains a circular buffer in a single wp_options row.
 * Each slot captures an opaque data blob before a destructive operation.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ring buffer I/O for backup snapshots.
 */
class RestlessWP_Backup_Ring {

	/** @var string */
	private string $option_key;

	/** @var int */
	private int $max_slots;

	/**
	 * @param string $option_key WordPress option name for this ring.
	 * @param int    $max_slots  Number of backup slots (default 5).
	 */
	public function __construct( string $option_key, int $max_slots = 5 ) {
		$this->option_key = $option_key;
		$this->max_slots  = $max_slots;
	}

	/**
	 * Records a snapshot before a destructive operation.
	 *
	 * @param string $action Action label (e.g. 'deleted', 'bulk_replaced').
	 * @param array  $data   Full data array at time of snapshot.
	 * @return void
	 */
	public function record( string $action, array $data ): void {
		$ring    = $this->read_option();
		$pointer = ( $ring['pointer'] + 1 ) % $this->max_slots;

		$ring['slots'][ $pointer ] = array(
			'timestamp' => time(),
			'action'    => $action,
			'count'     => count( $data ),
			'data'      => $data,
		);

		$ring['pointer'] = $pointer;
		$this->write_option( $ring );
	}

	/**
	 * Lists backup metadata sorted by timestamp descending.
	 *
	 * @return array[] Each entry: slot, timestamp, action, count (no data field).
	 */
	public function list_metadata(): array {
		$ring    = $this->read_option();
		$entries = array();

		foreach ( $ring['slots'] as $index => $slot ) {
			if ( empty( $slot ) ) {
				continue;
			}
			$entries[] = array(
				'slot'      => $index,
				'timestamp' => $slot['timestamp'],
				'action'    => $slot['action'],
				'count'     => $slot['count'],
			);
		}

		usort( $entries, function ( $a, $b ) {
			return $b['timestamp'] <=> $a['timestamp'];
		} );

		return $entries;
	}

	/**
	 * Retrieves a full backup slot including the data field.
	 *
	 * @param int $index Slot index (0 to max_slots - 1).
	 * @return array|null Full slot or null if empty/invalid.
	 */
	public function get_slot( int $index ): ?array {
		if ( $index < 0 || $index >= $this->max_slots ) {
			return null;
		}

		$ring = $this->read_option();
		$slot = $ring['slots'][ $index ] ?? null;

		if ( empty( $slot ) ) {
			return null;
		}

		return $slot;
	}

	/**
	 * Reads the ring buffer option from the database.
	 *
	 * @return array Ring buffer structure with pointer and slots.
	 */
	private function read_option(): array {
		$data = get_option( $this->option_key, false );

		if ( ! is_array( $data ) ) {
			return array(
				'pointer' => -1,
				'slots'   => array_fill( 0, $this->max_slots, null ),
			);
		}

		return $data;
	}

	/**
	 * Persists the ring buffer to the database (non-autoloaded).
	 *
	 * @param array $data Ring buffer structure.
	 * @return void
	 */
	private function write_option( array $data ): void {
		if ( false === get_option( $this->option_key ) ) {
			add_option( $this->option_key, $data, '', false );
		} else {
			update_option( $this->option_key, $data, false );
		}
	}
}
