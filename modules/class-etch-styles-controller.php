<?php
/**
 * Etch Styles Controller — REST endpoints for Etch style management.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-style-repository.php';
require_once __DIR__ . '/trait-etch-styles-schema.php';

/** REST controller for Etch styles. */
class RestlessWP_Etch_Styles_Controller extends RestlessWP_Base_Controller {
	use RestlessWP_Etch_Styles_Schema;

	/** @var RestlessWP_Etch_Style_Repository */
	private RestlessWP_Etch_Style_Repository $styles;

	/** @var RestlessWP_Backup_Ring */
	private RestlessWP_Backup_Ring $backups;

	/** @param RestlessWP_Auth_Handler $auth Auth handler instance. */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		parent::__construct( $auth );
		$this->styles  = new RestlessWP_Etch_Style_Repository();
		$this->backups = new RestlessWP_Backup_Ring( 'etch_styles_backups' );
	}

	/** @return string */
	protected function get_route_base(): string {
		return 'etch/styles';
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

		register_rest_route( self::NAMESPACE, '/' . $base . '/backups', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_backups' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
			'args'                => array(),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base . '/backups/(?P<index>[0-4])', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_backup' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
			'args'                => array(),
		) );

		register_rest_route( self::NAMESPACE, '/' . $base . '/orphans', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_orphans' ),
			'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
			'args'                => array(),
		) );

		parent::register_routes();

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

	/** @return array|WP_Error */
	protected function get_items( WP_REST_Request $request ) {
		$styles     = $this->styles->fetch_all();
		$collection = $request->get_param( 'collection' );
		$type       = $request->get_param( 'type' );
		$result     = array();

		foreach ( $styles as $key => $entry ) {
			if ( ! $this->matches_filters( $entry, $collection, $type ) ) {
				continue;
			}
			$result[] = $this->format_style( $key, $entry );
		}

		return $result;
	}

	/** @return bool */
	private function matches_filters( array $entry, ?string $collection, ?string $type ): bool {
		if ( null !== $collection && ( $entry['collection'] ?? '' ) !== $collection ) {
			return false;
		}
		if ( null !== $type && ( $entry['type'] ?? '' ) !== $type ) {
			return false;
		}
		return true;
	}

	/** @return array */
	protected function get_collection_params(): array {
		return array(
			'collection' => array(
				'description'       => __( 'Filter by collection name.', 'restlesswp' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'       => array(
				'description'       => __( 'Filter by style type (element or custom).', 'restlesswp' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/** @return array|WP_Error */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$entry = $this->styles->fetch( $key );

		if ( null === $entry ) {
			return new WP_Error(
				'not_found',
				__( 'Style not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_style( $key, $entry );
	}

	/** @return array|WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		foreach ( array( 'selector', 'css' ) as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error(
					'validation_error',
					sprintf(
						/* translators: %s: the missing field name */
						__( 'The "%s" field is required.', 'restlesswp' ),
						$field
					),
					array( 'status' => 400 )
				);
			}
		}

		$normalized = RestlessWP_Etch_Normalizer::style( $data );
		$selector   = $normalized['selector'];
		$collection = $normalized['collection'];
		$existing = $this->styles->find_by_selector( $selector, $collection );

		if ( null !== $existing ) {
			return $this->backfill_and_reuse( $existing, $normalized );
		}

		$key    = ! empty( $data['key'] ) ? sanitize_text_field( $data['key'] ) : 'etch-style-' . uniqid();
		$styles = $this->styles->fetch_all();

		$styles[ $key ] = $normalized;
		$this->styles->save_all( $styles );

		$result          = $this->format_style( $key, $styles[ $key ] );
		$result['_meta'] = array(
			'action'       => 'created',
			'total_styles' => count( $styles ),
		);

		return $result;
	}

	/** @return array Formatted style data for the reused key. */
	private function backfill_and_reuse( array $existing, array $normalized ): array {
		$key        = $existing['key'];
		$entry      = $existing['entry'];
		$backfilled = false;
		$styles     = $this->styles->fetch_all();

		if ( '' === trim( $entry['css'] ?? '' ) && '' !== trim( $normalized['css'] ?? '' ) ) {
			$styles[ $key ]['css'] = $normalized['css'];
			$this->styles->save_all( $styles );
			$entry['css'] = $normalized['css'];
			$backfilled   = true;
		}

		$result          = $this->format_style( $key, $entry );
		$result['_meta'] = array(
			'action'       => 'reused_existing',
			'reason'       => $backfilled
				? 'Selector already exists; CSS was empty so incoming CSS was backfilled.'
				: 'Selector already exists; returned existing style unchanged.',
			'total_styles' => count( $styles ),
		);

		return $result;
	}

	/** @return WP_Error|null WP_Error if conflict found, null if clear. */
	private function check_selector_conflict( array $normalized, string $current ): ?WP_Error {
		$selector   = $normalized['selector'];
		$collection = $normalized['collection'];
		$conflict   = $this->styles->selector_conflicts_with( $selector, $collection, $current );

		if ( null === $conflict ) {
			return null;
		}

		return new WP_Error(
			'conflict',
			sprintf(
				/* translators: 1: CSS selector, 2: conflicting style key */
				__( 'Selector "%1$s" already exists on key "%2$s".', 'restlesswp' ),
				$selector,
				$conflict
			),
			array( 'status' => 409 )
		);
	}

	/** @return array|WP_Error */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$styles = $this->styles->fetch_all();

		if ( ! isset( $styles[ $key ] ) ) {
			return new WP_Error(
				'not_found',
				__( 'Style not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$normalized = RestlessWP_Etch_Normalizer::style( $data );
		$conflict   = $this->check_selector_conflict( $normalized, $key );

		if ( null !== $conflict ) {
			return $conflict;
		}

		$styles[ $key ] = $normalized;

		$this->styles->save_all( $styles );

		$result          = $this->format_style( $key, $styles[ $key ] );
		$result['_meta'] = array(
			'action'       => 'updated',
			'total_styles' => count( $styles ),
		);

		return $result;
	}

	/** @return array|WP_Error */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$styles = $this->styles->fetch_all();

		if ( ! isset( $styles[ $key ] ) ) {
			return new WP_Error(
				'not_found',
				__( 'Style not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$this->backups->record( 'deleted', $styles );
		unset( $styles[ $key ] );
		$this->styles->save_all( $styles );

		return array(
			'deleted' => true,
			'key'     => $key,
			'_meta'   => array(
				'action'           => 'deleted',
				'remaining_styles' => count( $styles ),
			),
		);
	}

	/** @return array|null Existing style data if found, null otherwise. */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		if ( empty( $data['key'] ) ) {
			return null;
		}

		$key   = sanitize_text_field( $data['key'] );
		$entry = $this->styles->fetch( $key );

		if ( null === $entry ) {
			return null;
		}

		return $this->format_style( $key, $entry );
	}

	/** @return WP_REST_Response */
	public function handle_orphans( WP_REST_Request $request ): WP_REST_Response {
		$orphans = $this->styles->find_orphaned_keys();

		return RestlessWP_Response_Formatter::success( $orphans );
	}

	/** @return WP_REST_Response */
	public function handle_patch( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$key        = $url_params['key'];
		$styles     = $this->styles->fetch_all();

		if ( ! isset( $styles[ $key ] ) ) {
			return $this->wp_error_to_response( new WP_Error(
				'not_found',
				__( 'Style not found.', 'restlesswp' ),
				array( 'status' => 404 )
			) );
		}

		$incoming   = $request->get_json_params();
		$merged     = array_merge( $styles[ $key ], $incoming );
		$normalized = RestlessWP_Etch_Normalizer::style( $merged );
		$conflict   = $this->check_selector_conflict( $normalized, $key );

		if ( null !== $conflict ) {
			return $this->wp_error_to_response( $conflict );
		}

		$styles[ $key ] = $normalized;

		$this->styles->save_all( $styles );

		$result          = $this->format_style( $key, $styles[ $key ] );
		$result['_meta'] = array(
			'action'       => 'patched',
			'total_styles' => count( $styles ),
		);

		return RestlessWP_Response_Formatter::success( $result );
	}

	/** @return WP_REST_Response */
	public function handle_bulk_replace( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return $this->wp_error_to_response( new WP_Error(
				'validation_error',
				__( 'Request body must be a valid styles array.', 'restlesswp' ),
				array( 'status' => 400 )
			) );
		}

		$error = RestlessWP_Etch_Normalizer::styles_blob( $body );

		if ( is_wp_error( $error ) ) {
			return $this->wp_error_to_response( $error );
		}

		$current        = $this->styles->fetch_all();
		$previous_count = count( $current );
		$this->backups->record( 'bulk_replaced', $current );
		$this->styles->save_all( $body );

		$result          = $body;
		$result['_meta'] = array(
			'action'         => 'bulk_replaced',
			'previous_count' => $previous_count,
			'new_count'      => count( $body ),
		);

		return RestlessWP_Response_Formatter::success( $result );
	}

	/** @return WP_REST_Response */
	public function handle_bulk_update( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$map  = $body['styles'] ?? $body;

		if ( ! is_array( $map ) || empty( $map ) ) {
			return $this->wp_error_to_response( new WP_Error(
				'validation_error',
				__( 'The styles parameter must be a non-empty object.', 'restlesswp' ),
				array( 'status' => 400 )
			) );
		}

		$styles = $this->styles->fetch_all();
		$result = array();

		foreach ( $map as $key => $incoming ) {
			if ( ! isset( $styles[ $key ] ) ) {
				return $this->wp_error_to_response( new WP_Error(
					'not_found',
					sprintf(
						/* translators: %s: style key */
						__( 'Style "%s" not found.', 'restlesswp' ),
						$key
					),
					array( 'status' => 404 )
				) );
			}

			$merged     = array_merge( $styles[ $key ], $incoming );
			$normalized = RestlessWP_Etch_Normalizer::style( $merged );
			$conflict   = $this->check_selector_conflict( $normalized, $key );

			if ( null !== $conflict ) {
				return $this->wp_error_to_response( $conflict );
			}

			$styles[ $key ] = $normalized;
			$result[]       = $this->format_style( $key, $normalized );
		}

		$this->styles->save_all( $styles );

		return RestlessWP_Response_Formatter::success( $result );
	}

	/** @return string[] */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'delete', 'bulk-update', 'bulk-replace', 'orphan-detect', 'list-backups', 'get-backup' );
	}

	/** @return array<string, string> */
	public function get_ability_descriptions(): array {
		return array(
			'list'          => 'List all Etch styles. Each style has a key that blocks reference in attrs.styles. Use to discover existing keys before modifying pages.',
			'get'           => 'Get a single Etch style by key. Returns selector, CSS, type, and collection.',
			'create'        => 'Create a new Etch style. Blocks must reference the returned key in attrs.styles. Omit key for auto-generation. Response _meta.action is "created" or "reused_existing" (if selector already exists). For styles + blocks together, use pages import.',
			'update'        => 'Update an existing Etch style by key. Other pages may reference the same key. Response includes _meta.action and _meta.total_styles.',
			'delete'        => 'Delete an Etch style by key. WARNING: blocks referencing this key lose their styling. Response includes _meta.remaining_styles. A backup is automatically created before deletion.',
			'bulk-update'   => 'Update multiple Etch styles at once. Non-destructive: only keys in the payload are modified, all others remain untouched. Send a map of style keys to partial data. Rejects the entire batch if any key is not found or a selector conflict is detected.',
			'bulk-replace'  => 'Replace ALL styles at once. DESTRUCTIVE: removes styles not in payload. A backup is automatically created before replacement. Response includes _meta.previous_count and _meta.new_count for verification.',
			'orphan-detect' => 'Find style keys not referenced by any block. Returns orphaned styles safe to delete.',
			'list-backups'  => 'List available style backups. Returns metadata (slot, timestamp, action, count) without the full data blob. Up to 5 rolling backups are kept.',
			'get-backup'    => 'Retrieve a full style backup by slot index (0-4). Returns the complete styles blob captured before a destructive operation.',
		);
	}

	/** @return array|null Custom input schema for bulk-update. */
	public function get_ability_input_schema( string $action ): ?array {
		if ( 'bulk-update' !== $action ) {
			return null;
		}

		return array(
			'type'       => 'object',
			'required'   => array( 'styles' ),
			'properties' => array(
				'styles' => array(
					'type'                 => 'object',
					'description'          => __( 'Map of style keys to partial style data to merge.', 'restlesswp' ),
					'additionalProperties' => array( 'type' => 'object' ),
				),
			),
		);
	}

	/** @return WP_REST_Response */
	public function handle_list_backups( WP_REST_Request $request ): WP_REST_Response {
		return RestlessWP_Response_Formatter::success( $this->backups->list_metadata() );
	}

	/** @return WP_REST_Response */
	public function handle_get_backup( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$index      = (int) $url_params['index'];
		$slot       = $this->backups->get_slot( $index );

		if ( null === $slot ) {
			return $this->wp_error_to_response( new WP_Error(
				'not_found',
				__( 'Backup slot is empty.', 'restlesswp' ),
				array( 'status' => 404 )
			) );
		}

		return RestlessWP_Response_Formatter::success( $slot );
	}

	/** @return array Formatted style data with key included. */
	private function format_style( string $key, array $entry ): array {
		return array_merge( array( 'key' => $key ), $entry );
	}
}
