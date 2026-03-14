<?php
/**
 * Ability Schema Builder — builds input/output schemas for abilities.
 *
 * Transforms controller JSON Schemas into input and output schemas
 * suitable for the WordPress Abilities API.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds JSON schemas for ability input and output.
 */
class RestlessWP_Ability_Schema_Builder {

	/**
	 * Builds the input schema for an ability based on the operation type.
	 *
	 * @param string $action   Operation name.
	 * @param array  $schema   Controller item schema.
	 * @param string $id_param ID parameter name. Default 'key'.
	 * @param string $id_type  JSON Schema type for the ID. Default 'string'.
	 * @return array JSON Schema for ability input.
	 */
	public function build_input_schema(
		string $action,
		array $schema,
		string $id_param = 'key',
		string $id_type = 'string'
	): array {
		if ( in_array( $action, array( 'list', 'orphan-detect', 'list-backups' ), true ) ) {
			return $this->build_list_input_schema();
		}

		if ( 'get-backup' === $action ) {
			return $this->build_id_input_schema( 'index', 'integer' );
		}

		if ( 'get' === $action || 'delete' === $action ) {
			return $this->build_id_input_schema( $id_param, $id_type );
		}

		if ( 'bulk-update' === $action ) {
			return $this->build_bulk_update_input_schema();
		}

		if ( 'bulk-replace' === $action ) {
			return $this->build_bulk_replace_input_schema();
		}

		if ( 'convert' === $action ) {
			return $this->build_convert_input_schema();
		}

		if ( 'import' === $action ) {
			return $this->build_import_input_schema();
		}

		return $this->build_write_input_schema( $action, $schema, $id_param, $id_type );
	}

	/**
	 * Builds the output schema for an ability based on the operation type.
	 *
	 * @param string $action Operation name.
	 * @param array  $schema Controller item schema.
	 * @return array JSON Schema for ability output.
	 */
	public function build_output_schema( string $action, array $schema ): array {
		$item_schema = $this->build_item_output_schema( $schema );

		if ( in_array( $action, array( 'list', 'bulk-update', 'bulk-replace' ), true ) ) {
			return array(
				'type'  => 'array',
				'items' => $item_schema,
			);
		}

		if ( 'delete' === $action ) {
			return $this->build_delete_output_schema();
		}

		if ( 'orphan-detect' === $action ) {
			return $this->build_orphan_detect_output_schema();
		}

		if ( 'list-backups' === $action ) {
			return $this->build_backup_list_output_schema();
		}

		if ( 'get-backup' === $action ) {
			return $this->build_backup_output_schema();
		}

		if ( 'convert' === $action ) {
			return $this->build_convert_output_schema();
		}

		if ( 'import' === $action ) {
			return $this->build_import_output_schema();
		}

		return $item_schema;
	}

	/**
	 * Builds input schema for list operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_list_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => true,
		);
	}

	/**
	 * Builds input schema for bulk-update operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_bulk_update_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'variables' ),
			'properties' => array(
				'variables' => array(
					'type'                 => 'object',
					'description'          => __( 'Map of variable keys to values.', 'restlesswp' ),
					'additionalProperties' => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * Builds input schema for operations requiring only an ID.
	 *
	 * @param string $id_param ID parameter name.
	 * @param string $id_type  JSON Schema type for the ID.
	 * @return array JSON Schema.
	 */
	private function build_id_input_schema( string $id_param = 'key', string $id_type = 'string' ): array {
		return array(
			'type'       => 'object',
			'required'   => array( $id_param ),
			'properties' => array(
				$id_param => array(
					'type'        => $id_type,
					'description' => __( 'Unique identifier for the resource.', 'restlesswp' ),
				),
			),
		);
	}

	/**
	 * Builds input schema for create/update operations.
	 *
	 * @param string $action   Operation name.
	 * @param array  $schema   Controller item schema.
	 * @param string $id_param ID parameter name.
	 * @param string $id_type  JSON Schema type for the ID.
	 * @return array JSON Schema.
	 */
	private function build_write_input_schema(
		string $action,
		array $schema,
		string $id_param = 'key',
		string $id_type = 'string'
	): array {
		$properties = $schema['properties'] ?? array();
		$required   = $schema['required'] ?? array();
		$input      = array();

		if ( 'update' === $action ) {
			$input[ $id_param ] = array(
				'type'        => $id_type,
				'description' => __( 'Unique identifier for the resource.', 'restlesswp' ),
			);
		}

		foreach ( $properties as $name => $prop ) {
			if ( ! empty( $prop['readonly'] ) ) {
				continue;
			}
			$input[ $name ] = $this->sanitize_schema_property( $prop );
		}

		$result = array(
			'type'       => 'object',
			'properties' => $input,
		);

		if ( 'create' === $action && ! empty( $required ) ) {
			$result['required'] = $required;
		}

		if ( 'update' === $action ) {
			$result['required'] = array( $id_param );
		}

		return $result;
	}

	/**
	 * Builds output schema for a single item from the controller schema.
	 *
	 * @param array $schema Controller item schema.
	 * @return array JSON Schema for a single item.
	 */
	private function build_item_output_schema( array $schema ): array {
		$properties = $schema['properties'] ?? array();
		$output     = array();

		foreach ( $properties as $name => $prop ) {
			$clean = $this->sanitize_schema_property( $prop );
			$output[ $name ] = $this->make_nullable( $clean );
		}

		return array(
			'type'       => 'object',
			'properties' => $output,
		);
	}

	/**
	 * Builds output schema for delete operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_delete_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Builds input schema for bulk-replace operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_bulk_replace_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'styles' ),
			'properties' => array(
				'styles' => array(
					'type'                 => 'object',
					'description'          => __( 'Map of style keys to replacement values.', 'restlesswp' ),
					'additionalProperties' => array(
						'type'       => 'array',
						'items'      => array(
							'type' => 'object',
						),
					),
				),
			),
		);
	}

	/**
	 * Builds input schema for convert operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_convert_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'blocks' ),
			'properties' => array(
				'blocks' => array(
					'type'        => 'array',
					'description' => __( 'Array of blocks to convert.', 'restlesswp' ),
					'items'       => array(
						'type' => 'object',
					),
				),
			),
		);
	}

	/**
	 * Builds output schema for orphan-detect operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_orphan_detect_output_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'key'        => array(
						'type' => 'string',
					),
					'selector'   => array(
						'type' => 'string',
					),
					'collection' => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * Builds output schema for convert operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_convert_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'blocks' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'object',
					),
				),
			),
		);
	}

	/**
	 * Builds input schema for import operations.
	 *
	 * Import payloads are controller-specific (e.g. pages import takes
	 * post_id, def, styles, loops). The schema is permissive; the
	 * ability description provides the detailed contract.
	 *
	 * @return array JSON Schema.
	 */
	private function build_import_input_schema(): array {
		return array(
			'type'                 => 'object',
			'description'         => __( 'Import payload. See the ability description for the expected format.', 'restlesswp' ),
			'additionalProperties' => true,
		);
	}

	/**
	 * Builds output schema for import operations.
	 *
	 * @return array JSON Schema.
	 */
	private function build_import_output_schema(): array {
		return array(
			'type'                 => 'object',
			'description'         => __( 'Import report with merge results and updated resource data.', 'restlesswp' ),
			'additionalProperties' => true,
		);
	}

	/** @return array JSON Schema for backup list output. */
	private function build_backup_list_output_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'slot'      => array( 'type' => 'integer' ),
					'timestamp' => array( 'type' => 'integer' ),
					'action'    => array( 'type' => 'string' ),
					'count'     => array( 'type' => 'integer' ),
				),
			),
		);
	}

	/** @return array JSON Schema for a single backup output. */
	private function build_backup_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'timestamp' => array( 'type' => 'integer' ),
				'action'    => array( 'type' => 'string' ),
				'count'     => array( 'type' => 'integer' ),
				'data'      => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
		);
	}

	/**
	 * Makes a schema property nullable by allowing null as a type.
	 *
	 * Third-party controllers often return null for optional fields even
	 * when their schema declares a single type. This prevents output
	 * validation failures from the Abilities API.
	 *
	 * @param array $prop Schema property definition.
	 * @return array Property with null added to type.
	 */
	private function make_nullable( array $prop ): array {
		if ( ! isset( $prop['type'] ) ) {
			return $prop;
		}

		$type = $prop['type'];

		if ( is_array( $type ) ) {
			if ( ! in_array( 'null', $type, true ) ) {
				$type[]       = 'null';
				$prop['type'] = $type;
			}
		} else {
			$prop['type'] = array( $type, 'null' );
		}

		return $prop;
	}

	private function sanitize_schema_property( array $prop ): array {
		$allowed = array( 'type', 'description', 'items', 'properties', 'enum' );
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( isset( $prop[ $key ] ) ) {
				$clean[ $key ] = $prop[ $key ];
			}
		}

		return $clean;
	}
}
