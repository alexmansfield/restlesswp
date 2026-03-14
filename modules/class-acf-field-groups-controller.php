<?php
/**
 * ACF Field Groups Controller — REST endpoints for ACF field groups.
 *
 * Provides list, get, create, and update endpoints for ACF field
 * groups with their fields and location rules.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-acf-field-crud.php';

/**
 * REST controller for ACF field groups.
 */
class RestlessWP_ACF_Field_Groups_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_ACF_Field_CRUD;

	/**
	 * Returns the route base for field group endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'acf/field-groups';
	}

	/**
	 * Registers standard routes plus field-level CRUD routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		parent::register_routes();
		$this->register_field_routes();
	}

	/**
	 * Returns the capability required for read operations.
	 *
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Returns the capability required for write operations.
	 *
	 * @return string
	 */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/**
	 * Retrieves all ACF field groups with their fields.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Array of field group data or WP_Error on failure.
	 */
	protected function get_items( WP_REST_Request $request ) {
		$groups = acf_get_field_groups();
		$result = array();

		foreach ( $groups as $group ) {
			$result[] = $this->format_field_group( $group );
		}

		return $result;
	}

	/**
	 * Retrieves a single ACF field group by key.
	 *
	 * @param string          $key     ACF field group key (e.g. group_abc123).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Field group data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$group = acf_get_field_group( $key );

		if ( ! $group ) {
			return new WP_Error(
				'not_found',
				__( 'Field group not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_field_group( $group );
	}

	/**
	 * Creates a new ACF field group.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created field group data or WP_Error on failure.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		$group_args = $this->build_group_args( $data );

		if ( empty( $group_args['key'] ) ) {
			$group_args['key'] = 'group_' . uniqid();
		}

		$group = acf_update_field_group( $group_args );

		if ( ! $group ) {
			return new WP_Error(
				'restlesswp_create_failed',
				__( 'Failed to create field group.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$this->save_fields( $data['fields'], $group );
		}

		return $this->format_field_group( $group );
	}

	/**
	 * Updates an existing ACF field group.
	 *
	 * @param string          $key     Item identifier.
	 * @param array           $data    Validated partial data (already merged by base).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated field group data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$existing = acf_get_field_group( $key );

		if ( ! $existing ) {
			return new WP_Error(
				'not_found',
				__( 'Field group not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$group_args        = $this->build_group_args( $data );
		$group_args['ID']  = $existing['ID'];
		$group_args['key'] = $key;

		$group = acf_update_field_group( $group_args );

		if ( ! $group ) {
			return new WP_Error(
				'restlesswp_update_failed',
				__( 'Failed to update field group.', 'restlesswp' ),
				array( 'status' => 500 )
			);
		}

		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$this->save_fields( $data['fields'], $group );
		}

		return $this->format_field_group( $group );
	}

	/**
	 * Deletes an ACF field group by key.
	 *
	 * @param string          $key     ACF field group key.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Result data or WP_Error on failure.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$group = acf_get_field_group( $key );

		if ( ! $group ) {
			return new WP_Error(
				'not_found',
				__( 'Field group not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$deleted = acf_delete_field_group( $group['ID'] );

		if ( ! $deleted ) {
			return new WP_Error(
				'validation_error',
				__( 'Failed to delete field group.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'key'     => $key,
			'deleted' => true,
		);
	}

	/**
	 * Checks if a field group with the same key already exists.
	 *
	 * Used by the base controller for 409 conflict detection on POST.
	 *
	 * @param array           $data    Incoming create data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|null Existing field group data if found, null otherwise.
	 */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['key'] ) ) {
			return null;
		}

		$group = acf_get_field_group( $data['key'] );

		if ( ! $group ) {
			return null;
		}

		return $this->format_field_group( $group );
	}

	/**
	 * Builds the field group arguments array for ACF functions.
	 *
	 * @param array $data Request data.
	 * @return array ACF field group arguments.
	 */
	private function build_group_args( array $data ): array {
		$args     = array();
		$mappings = array(
			'key'                   => 'key',
			'title'                 => 'title',
			'location'              => 'location',
			'menu_order'            => 'menu_order',
			'style'                 => 'style',
			'label_placement'       => 'label_placement',
			'instruction_placement' => 'instruction_placement',
			'description'           => 'description',
			'position'              => 'position',
			'hide_on_screen'        => 'hide_on_screen',
			'display_title'         => 'display_title',
			'active'                => 'active',
			'show_in_rest'          => 'show_in_rest',
		);

		foreach ( $mappings as $field => $acf_key ) {
			if ( array_key_exists( $field, $data ) ) {
				$args[ $acf_key ] = $data[ $field ];
			}
		}

		return $args;
	}

	/**
	 * Saves fields for a field group using acf_update_field().
	 *
	 * @param array $fields Array of field data.
	 * @param array $group  ACF field group array.
	 * @return void
	 */
	private function save_fields( array $fields, array $group ): void {
		$incoming_keys = array();

		foreach ( $fields as $index => $field_data ) {
			if ( empty( $field_data['key'] ) ) {
				$field_data['key'] = 'field_' . uniqid();
			}

			$incoming_keys[] = $field_data['key'];

			// Preserve existing field ID so ACF updates in place.
			$existing = acf_get_field( $field_data['key'] );
			if ( $existing ) {
				$field_data['ID'] = $existing['ID'];
			}

			$field_data['parent']     = $group['ID'];
			$field_data['menu_order'] = $field_data['menu_order'] ?? $index;

			acf_update_field( $field_data );
		}

		$this->delete_orphan_fields( $group, $incoming_keys );
	}

	/**
	 * Deletes fields that exist in the group but are not in the incoming set.
	 *
	 * @param array    $group         ACF field group array.
	 * @param string[] $incoming_keys Field keys from the incoming payload.
	 * @return void
	 */
	private function delete_orphan_fields( array $group, array $incoming_keys ): void {
		// Flush cache before reading so we see the current state.
		if ( ! empty( $group['ID'] ) ) {
			$cache_key = acf_cache_key( "acf_get_field_posts:{$group['ID']}" );
			wp_cache_delete( $cache_key, 'acf' );
		}

		$existing_fields = acf_get_fields( $group['key'] );

		if ( ! is_array( $existing_fields ) ) {
			return;
		}

		foreach ( $existing_fields as $existing_field ) {
			if ( ! in_array( $existing_field['key'], $incoming_keys, true ) ) {
				acf_delete_field( $existing_field['ID'] );
			}
		}
	}

	/**
	 * Returns the JSON Schema for an ACF field group resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'acf-field-group',
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => $this->get_schema_properties(),
		);
	}

	/**
	 * Returns the schema property definitions for a field group.
	 *
	 * @return array Schema properties.
	 */
	private function get_schema_properties(): array {
		return array(
			'key'            => array(
				'description' => __( 'Unique ACF key for the field group.', 'restlesswp' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'title'          => array(
				'description' => __( 'Human-readable title of the field group.', 'restlesswp' ),
				'type'        => 'string',
			),
			'fields'         => array(
				'description' => __( 'Array of fields belonging to this group.', 'restlesswp' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'object',
				),
			),
			'location'       => array(
				'description' => __( 'Location rules that determine where the field group appears.', 'restlesswp' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'array',
					'items' => array(
						'type' => 'object',
					),
				),
			),
			'menu_order'     => array(
				'description' => __( 'Order of the field group in the admin UI.', 'restlesswp' ),
				'type'        => 'integer',
			),
			'active'         => array(
				'description' => __( 'Whether the field group is active.', 'restlesswp' ),
				'type'        => 'boolean',
			),
			'style'          => array(
				'description' => __( 'Display style: default or seamless.', 'restlesswp' ),
				'type'        => 'string',
			),
			'label_placement' => array(
				'description' => __( 'Label placement: top or left.', 'restlesswp' ),
				'type'        => 'string',
			),
			'instruction_placement' => array(
				'description' => __( 'Instruction placement: label or field.', 'restlesswp' ),
				'type'        => 'string',
			),
			'description'    => array(
				'description' => __( 'Description of the field group.', 'restlesswp' ),
				'type'        => 'string',
			),
			'position'       => array(
				'description' => __( 'Metabox position: acf_after_title, normal, or side.', 'restlesswp' ),
				'type'        => 'string',
			),
			'hide_on_screen' => array(
				'description' => __( 'Screen elements to hide when this field group is active.', 'restlesswp' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
			),
			'display_title'  => array(
				'description' => __( 'Alternative display title for the field group (ACF 6.6+).', 'restlesswp' ),
				'type'        => 'string',
			),
			'show_in_rest'   => array(
				'description' => __( 'Whether the field group is exposed in the REST API.', 'restlesswp' ),
				'type'        => 'integer',
			),
		);
	}

	/**
	 * Formats a raw ACF field group array into the API response shape.
	 *
	 * @param array $group Raw ACF field group data.
	 * @return array Formatted field group data.
	 */
	private function format_field_group( array $group ): array {
		$fields     = acf_get_fields( $group['key'] );
		$formatted  = array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				$formatted[] = $this->format_field( $field );
			}
		}

		return array(
			'key'                   => $group['key'] ?? '',
			'title'                 => $group['title'] ?? '',
			'fields'                => $formatted,
			'location'              => $group['location'] ?? array(),
			'menu_order'            => (int) ( $group['menu_order'] ?? 0 ),
			'active'                => (bool) ( $group['active'] ?? true ),
			'style'                 => $group['style'] ?? 'default',
			'label_placement'       => $group['label_placement'] ?? 'top',
			'instruction_placement' => $group['instruction_placement'] ?? 'label',
			'description'           => $group['description'] ?? '',
			'position'              => $group['position'] ?? 'normal',
			'hide_on_screen'        => (array) ( $group['hide_on_screen'] ?? array() ),
			'display_title'         => $group['display_title'] ?? '',
			'show_in_rest'          => (int) ( $group['show_in_rest'] ?? 0 ),
		);
	}

	/**
	 * Formats a raw ACF field array into the API response shape.
	 *
	 * @param array $field Raw ACF field data.
	 * @return array Formatted field data.
	 */
	private function format_field( array $field ): array {
		return array(
			'key'           => $field['key'] ?? '',
			'label'         => $field['label'] ?? '',
			'name'          => $field['name'] ?? '',
			'type'          => $field['type'] ?? '',
			'instructions'  => $field['instructions'] ?? '',
			'required'      => (bool) ( $field['required'] ?? false ),
			'default_value' => $field['default_value'] ?? '',
			'placeholder'   => $field['placeholder'] ?? '',
			'menu_order'    => (int) ( $field['menu_order'] ?? 0 ),
			'parent'        => $field['parent'] ?? '',
		);
	}
}
