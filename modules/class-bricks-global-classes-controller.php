<?php
/**
 * Bricks Global Classes Controller — REST endpoints for global CSS classes.
 *
 * Most complex Bricks controller: CRUD + PATCH + bulk operations + backup
 * ring + soft-delete to trash + category management.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/trait-bricks-option-blob.php';
require_once __DIR__ . '/trait-bricks-global-classes-aux.php';

/**
 * REST controller for Bricks global classes.
 */
class RestlessWP_Bricks_Global_Classes_Controller extends RestlessWP_Base_Controller {
	use RestlessWP_Bricks_Option_Blob;
	use RestlessWP_Bricks_Global_Classes_Aux;

	/** @var string Option name for global classes. */
	private const OPTION = 'bricks_global_classes';

	/** @var string Option name for trash. */
	private const TRASH_OPTION = 'bricks_global_classes_trash';

	/** @var string Option name for categories. */
	private const CATEGORIES_OPTION = 'bricks_global_classes_categories';

	/** @var string Option name for timestamp. */
	private const TIMESTAMP_OPTION = 'bricks_global_classes_timestamp';

	/** @var string Option name for last user. */
	private const USER_OPTION = 'bricks_global_classes_user';

	/** @var RestlessWP_Backup_Ring */
	private RestlessWP_Backup_Ring $backups;

	/** @param RestlessWP_Auth_Handler $auth Auth handler instance. */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		parent::__construct( $auth );
		$this->backups = new RestlessWP_Backup_Ring( 'bricks_global_classes_backups' );
	}

	/** @return string */
	protected function get_route_base(): string {
		return 'bricks/global-classes';
	}

	/** @return string */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/** @return string */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/** @return void */
	public function register_routes(): void {
		$base = $this->get_route_base();

		$this->register_backup_routes( $base );
		$this->register_category_routes( $base );
		parent::register_routes();
		$this->register_extra_routes( $base );
	}

	/** @return array|WP_Error */
	protected function get_items( WP_REST_Request $request ) {
		$items    = $this->fetch_blob( self::OPTION );
		$category = $request->get_param( 'category' );

		if ( null === $category ) {
			return $items;
		}

		return array_values( array_filter( $items, function ( $item ) use ( $category ) {
			return ( $item['category'] ?? '' ) === $category;
		} ) );
	}

	/** @return array */
	protected function get_collection_params(): array {
		return array(
			'category' => array(
				'description'       => __( 'Filter by category name.', 'restlesswp' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/** @return array|WP_Error */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$item  = $this->find_by_id( $items, $key );

		if ( null === $item ) {
			return $this->not_found_error();
		}

		return $item;
	}

	/** @return array|WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$name  = sanitize_text_field( $data['name'] ?? '' );

		if ( '' === $name ) {
			return $this->validation_error( __( 'The "name" field is required.', 'restlesswp' ) );
		}

		if ( $this->name_exists( $items, $name ) ) {
			return new WP_Error( 'conflict', __( 'A global class with this name already exists.', 'restlesswp' ), array( 'status' => 409 ) );
		}

		if ( empty( $data['id'] ) ) {
			$data['id'] = RestlessWP_Bricks_Normalizer::element_id();
		}

		$normalized = RestlessWP_Bricks_Normalizer::global_class( $data );
		$items[]    = $normalized;

		$this->save_blob( self::OPTION, $items );
		$this->update_timestamp();

		return $normalized;
	}

	/** @return array|WP_Error */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$index = $this->find_index_by_id( $items, $key );

		if ( null === $index ) {
			return $this->not_found_error();
		}

		if ( isset( $data['name'] ) && $this->name_exists_excluding( $items, sanitize_text_field( $data['name'] ), $key ) ) {
			return new WP_Error( 'conflict', __( 'A global class with this name already exists.', 'restlesswp' ), array( 'status' => 409 ) );
		}

		$items[ $index ] = RestlessWP_Bricks_Normalizer::global_class( array_merge( $items[ $index ], $data ) );
		$this->save_blob( self::OPTION, $items );
		$this->update_timestamp();

		return $items[ $index ];
	}

	/** @return array|WP_Error */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$items = $this->fetch_blob( self::OPTION );
		$item  = $this->find_by_id( $items, $key );

		if ( null === $item ) {
			return $this->not_found_error();
		}

		$this->backups->record( 'deleted', $items );
		$this->move_to_trash( $item );
		$this->remove_by_id( $items, $key );
		$this->save_blob( self::OPTION, $items );
		$this->update_timestamp();

		return array(
			'deleted' => true,
			'id'      => $key,
			'_meta'   => array( 'action' => 'soft_deleted', 'remaining' => count( $items ) ),
		);
	}

	/** @return array|null */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['id'] ) ) {
			return null;
		}

		return $this->find_by_id( $this->fetch_blob( self::OPTION ), sanitize_text_field( $data['id'] ) );
	}

	/** @return WP_REST_Response */
	public function handle_patch( WP_REST_Request $request ): WP_REST_Response {
		$key   = $request->get_url_params()['key'];
		$items = $this->fetch_blob( self::OPTION );
		$index = $this->find_index_by_id( $items, $key );

		if ( null === $index ) {
			return $this->wp_error_to_response( $this->not_found_error() );
		}

		$incoming = $request->get_json_params();

		if ( isset( $incoming['name'] ) && $this->name_exists_excluding( $items, sanitize_text_field( $incoming['name'] ), $key ) ) {
			return $this->wp_error_to_response( new WP_Error( 'conflict', __( 'A global class with this name already exists.', 'restlesswp' ), array( 'status' => 409 ) ) );
		}

		$existing = $items[ $index ];

		if ( isset( $incoming['settings'] ) && is_array( $incoming['settings'] ) ) {
			$incoming['settings'] = array_merge( $existing['settings'] ?? array(), $incoming['settings'] );
		}

		$items[ $index ] = RestlessWP_Bricks_Normalizer::global_class( array_merge( $existing, $incoming ) );
		$this->save_blob( self::OPTION, $items );
		$this->update_timestamp();

		return RestlessWP_Response_Formatter::success( $items[ $index ] );
	}

	/** @return WP_REST_Response */
	public function handle_bulk_replace( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return $this->wp_error_to_response( $this->validation_error( __( 'Request body must be an array.', 'restlesswp' ) ) );
		}

		$normalized     = array_map( array( RestlessWP_Bricks_Normalizer::class, 'global_class' ), $body );
		$current        = $this->fetch_blob( self::OPTION );
		$previous_count = count( $current );

		$this->backups->record( 'bulk_replaced', $current );
		$this->save_blob( self::OPTION, $normalized );
		$this->update_timestamp();

		return RestlessWP_Response_Formatter::success( array(
			'items' => $normalized,
			'_meta' => array( 'action' => 'bulk_replaced', 'previous_count' => $previous_count, 'new_count' => count( $normalized ) ),
		) );
	}

	/** @return WP_REST_Response */
	public function handle_bulk_update( WP_REST_Request $request ): WP_REST_Response {
		$body  = $request->get_json_params();
		$items = $body['items'] ?? $body;

		if ( ! is_array( $items ) || empty( $items ) ) {
			return $this->wp_error_to_response( $this->validation_error( __( 'Items must be a non-empty array.', 'restlesswp' ) ) );
		}

		$current = $this->fetch_blob( self::OPTION );
		$result  = array();

		foreach ( $items as $incoming ) {
			$id    = sanitize_text_field( $incoming['id'] ?? '' );
			$index = $this->find_index_by_id( $current, $id );

			if ( null === $index ) {
				return $this->wp_error_to_response( new WP_Error(
					'not_found',
					sprintf( __( 'Global class "%s" not found.', 'restlesswp' ), $id ),
					array( 'status' => 404 )
				) );
			}

			$current[ $index ] = RestlessWP_Bricks_Normalizer::global_class( array_merge( $current[ $index ], $incoming ) );
			$result[]          = $current[ $index ];
		}

		$this->save_blob( self::OPTION, $current );
		$this->update_timestamp();

		return RestlessWP_Response_Formatter::success( $result );
	}

	/** @return string[] */
	public function get_supported_operations(): array {
		return array(
			'list', 'get', 'create', 'update', 'delete', 'patch',
			'bulk-replace', 'bulk-update',
			'list-categories', 'replace-categories',
			'list-backups', 'get-backup',
		);
	}

	/** @return array<string, string> */
	public function get_ability_descriptions(): array {
		return array(
			'list'               => 'List all Bricks global classes. Supports ?category= filter.',
			'get'                => 'Get a single global class by its 6-char ID.',
			'create'             => 'Create a new global class. Validates name uniqueness.',
			'update'             => 'Full replace of a global class by ID.',
			'delete'             => 'Soft-delete a global class to trash. Backup created automatically.',
			'patch'              => 'Partial update — merge settings, omitted fields survive.',
			'bulk-replace'       => 'Replace ALL global classes. DESTRUCTIVE: backup created first.',
			'bulk-update'        => 'Update multiple classes. All-or-nothing: rejects if any ID missing.',
			'list-categories'    => 'List global class categories.',
			'replace-categories' => 'Replace all global class categories.',
			'list-backups'       => 'List backup metadata (up to 5 rolling backups).',
			'get-backup'         => 'Get full backup by slot index (0-4).',
		);
	}

	/** @return array */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bricks-global-class',
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'id'       => array( 'type' => 'string', 'description' => __( '6-char unique ID.', 'restlesswp' ) ),
				'name'     => array( 'type' => 'string', 'description' => __( 'CSS class name. Must be unique.', 'restlesswp' ) ),
				'label'    => array( 'type' => 'string', 'description' => __( 'Display label.', 'restlesswp' ) ),
				'settings' => array( 'type' => 'object', 'description' => __( 'CSS settings object.', 'restlesswp' ) ),
				'category' => array( 'type' => 'string', 'description' => __( 'Category name.', 'restlesswp' ) ),
			),
		);
	}

	/** @return void */
	private function register_extra_routes( string $base ): void {
		register_rest_route( self::NAMESPACE, '/' . $base . '/(?P<key>[\\w\\-]+)', array(
			'methods'             => 'PATCH',
			'callback'            => array( $this, 'handle_patch' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
			'args'                => array(),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base, array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'handle_bulk_replace' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
			'args'                => array(),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base, array(
			'methods'             => 'PATCH',
			'callback'            => array( $this, 'handle_bulk_update' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
			'args'                => array(),
		) );
	}

	/** @return bool */
	private function name_exists( array $items, string $name ): bool {
		foreach ( $items as $item ) {
			if ( ( $item['name'] ?? '' ) === $name ) {
				return true;
			}
		}
		return false;
	}

	/** @return bool */
	private function name_exists_excluding( array $items, string $name, string $exclude_id ): bool {
		foreach ( $items as $item ) {
			if ( ( $item['id'] ?? '' ) !== $exclude_id && ( $item['name'] ?? '' ) === $name ) {
				return true;
			}
		}
		return false;
	}

	/** @return WP_Error */
	private function not_found_error(): WP_Error {
		return new WP_Error( 'not_found', __( 'Global class not found.', 'restlesswp' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	private function validation_error( string $message ): WP_Error {
		return new WP_Error( 'validation_error', $message, array( 'status' => 400 ) );
	}
}
