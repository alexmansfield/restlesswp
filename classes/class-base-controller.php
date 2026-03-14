<?php
/**
 * Base Controller — abstract REST controller for all module endpoints.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base controller that handles route registration, validation,
 * permission delegation, and response formatting.
 *
 * Module controllers extend this class and implement the abstract methods.
 */
abstract class RestlessWP_Base_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected const NAMESPACE = 'restlesswp/v1';

	/**
	 * Auth handler instance.
	 *
	 * @var RestlessWP_Auth_Handler
	 */
	protected RestlessWP_Auth_Handler $auth;

	/**
	 * Constructor.
	 *
	 * @param RestlessWP_Auth_Handler $auth Auth handler for permission callbacks.
	 */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Returns the route base for this controller (e.g. 'acf/field-groups').
	 *
	 * @return string
	 */
	abstract protected function get_route_base(): string;

	/**
	 * Returns the capability required for read operations.
	 *
	 * @return string
	 */
	abstract protected function get_read_capability(): string;

	/**
	 * Returns the capability required for write operations.
	 *
	 * @return string
	 */
	abstract protected function get_write_capability(): string;

	/**
	 * Retrieves all items.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Array of items or WP_Error on failure.
	 */
	abstract protected function get_items( WP_REST_Request $request );

	/**
	 * Retrieves a single item by key.
	 *
	 * @param string          $key     Item identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Item data or WP_Error on failure.
	 */
	abstract protected function get_item( string $key, WP_REST_Request $request );

	/**
	 * Creates a new item.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created item data or WP_Error on failure.
	 */
	abstract protected function create_item( array $data, WP_REST_Request $request );

	/**
	 * Updates an existing item.
	 *
	 * @param string          $key     Item identifier.
	 * @param array           $data    Validated partial data to merge.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated item data or WP_Error on failure.
	 */
	abstract protected function update_item( string $key, array $data, WP_REST_Request $request );

	/**
	 * Returns the JSON Schema for this resource.
	 *
	 * @return array JSON Schema array.
	 */
	abstract public function get_item_schema(): array;

	/**
	 * Returns the read capability for ability registration.
	 *
	 * @return string WordPress capability string.
	 */
	public function get_read_capability_for_ability(): string {
		return $this->get_read_capability();
	}

	/**
	 * Returns the write capability for ability registration.
	 *
	 * @return string WordPress capability string.
	 */
	public function get_write_capability_for_ability(): string {
		return $this->get_write_capability();
	}

	/**
	 * Returns the list of supported operations for ability registration.
	 *
	 * Override in subclass to customize which abilities are registered.
	 * Default: list, get, create, update. Delete is excluded unless the
	 * subclass overrides delete_item().
	 *
	 * @return string[] Array of operation names.
	 */
	public function get_supported_operations(): array {
		$operations = array( 'list', 'get', 'create', 'update' );

		if ( $this->supports_delete() ) {
			$operations[] = 'delete';
		}

		return $operations;
	}

	/**
	 * Returns custom ability descriptions for this controller.
	 *
	 * Override in subclass to provide workflow-aware descriptions that
	 * help agents understand how and when to use each operation.
	 * Keys are operation names, values are description strings.
	 *
	 * @return array<string, string> Operation name => description.
	 */
	public function get_ability_descriptions(): array {
		return array();
	}

	/**
	 * Returns a custom ability input schema for a specific action.
	 *
	 * Override in subclass when the auto-generated schema from
	 * get_item_schema() doesn't match the ability's actual input
	 * (e.g. import endpoints with different parameters).
	 *
	 * @param string $action Operation name.
	 * @return array|null JSON Schema array, or null to use auto-generated.
	 */
	public function get_ability_input_schema( string $action ): ?array {
		return null;
	}

	/**
	 * Checks whether this controller overrides the default delete_item method.
	 *
	 * @return bool True if delete is supported.
	 */
	private function supports_delete(): bool {
		$method = new \ReflectionMethod( $this, 'delete_item' );

		return $method->getDeclaringClass()->getName() !== self::class;
	}

	/**
	 * Deletes an item. Override in subclass to enable DELETE route.
	 *
	 * @param string          $key     Item identifier.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Result data or WP_Error on failure.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		return new WP_Error(
			'restlesswp_not_implemented',
			__( 'Delete is not supported for this resource.', 'restlesswp' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Finds an existing item that would conflict with a create operation.
	 *
	 * Override in subclass to enable conflict detection on POST.
	 *
	 * @param array           $data    Incoming create data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|null Existing item data if found, null otherwise.
	 */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		return null;
	}

	/**
	 * Returns additional query parameters for collection requests.
	 *
	 * Override in subclass to add custom filter/sort params.
	 *
	 * @return array Array of query parameter definitions.
	 */
	protected function get_collection_params(): array {
		return array();
	}

	/**
	 * Registers all REST routes for this controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = $this->get_route_base();

		$this->register_collection_routes( $base );
		$this->register_single_routes( $base );
	}

	/**
	 * Registers the collection routes (list + create).
	 *
	 * @param string $base Route base path.
	 * @return void
	 */
	private function register_collection_routes( string $base ): void {
		$routes = array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
				'args'                => $this->get_collection_params(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => $this->get_schema_args_for_method( 'POST' ),
			),
		);

		register_rest_route( self::NAMESPACE, '/' . $base, $routes );
	}

	/**
	 * Registers the single-item routes (get, update, delete).
	 *
	 * @param string $base Route base path.
	 * @return void
	 */
	private function register_single_routes( string $base ): void {
		$key_arg = $this->get_key_arg();

		$routes = array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_read_capability() ),
				'args'                => array(),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'handle_update' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => $this->get_schema_args_for_method( 'PUT' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => $this->auth->permission_callback( $this->get_write_capability() ),
				'args'                => array(),
			),
		);

		$route_config         = $routes;
		$route_config['args'] = $key_arg['args'];

		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/(?P<key>[\\w\\-]+)',
			$route_config
		);
	}

	/**
	 * Returns the route argument definition for the item key parameter.
	 *
	 * @return array Key parameter definition.
	 */
	private function get_key_arg(): array {
		return array(
			'args' => array(
				'key' => array(
					'description'       => __( 'Unique identifier for the resource.', 'restlesswp' ),
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		);
	}

	/**
	 * Extracts schema properties as route args for a given HTTP method.
	 *
	 * @param string $method HTTP method (POST or PUT).
	 * @return array Route args derived from schema.
	 */
	private function get_schema_args_for_method( string $method ): array {
		$schema     = $this->get_item_schema();
		$properties = $schema['properties'] ?? array();
		$required   = $schema['required'] ?? array();
		$args       = array();

		foreach ( $properties as $name => $prop ) {
			if ( $this->is_readonly_property( $prop ) ) {
				continue;
			}

			$arg = $this->build_arg_from_property( $prop );

			if ( 'POST' === $method && in_array( $name, $required, true ) ) {
				$arg['required'] = true;
			}

			$args[ $name ] = $arg;
		}

		return $args;
	}

	/**
	 * Checks if a schema property is read-only.
	 *
	 * @param array $prop Schema property definition.
	 * @return bool True if read-only.
	 */
	private function is_readonly_property( array $prop ): bool {
		return ! empty( $prop['readonly'] );
	}

	/**
	 * Builds a route arg definition from a schema property.
	 *
	 * @param array $prop Schema property definition.
	 * @return array Route arg definition.
	 */
	private function build_arg_from_property( array $prop ): array {
		$arg = array(
			'required'          => false,
			'sanitize_callback' => 'rest_sanitize_request_arg',
		);

		if ( isset( $prop['description'] ) ) {
			$arg['description'] = $prop['description'];
		}

		if ( isset( $prop['type'] ) ) {
			$arg['type'] = $prop['type'];
		}

		if ( isset( $prop['validate_callback'] ) ) {
			$arg['validate_callback'] = $prop['validate_callback'];
		} elseif ( isset( $prop['type'] ) ) {
			$arg['validate_callback'] = 'rest_validate_request_arg';
		}

		if ( $this->needs_default_sanitizer( $prop ) ) {
			$arg['sanitize_callback'] = 'sanitize_text_field';
		}

		return $arg;
	}

	/**
	 * Determines if a property needs the default text sanitizer.
	 *
	 * @param array $prop Schema property definition.
	 * @return bool True if default sanitizer should be applied.
	 */
	private function needs_default_sanitizer( array $prop ): bool {
		$type = $prop['type'] ?? 'string';

		return 'string' === $type && ! isset( $prop['sanitize_callback'] );
	}

	/**
	 * Handles GET collection requests.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_list( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->get_items( $request );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( $result );
	}

	/**
	 * Handles GET single-item requests.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_get( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$key        = $url_params['key'];
		$result     = $this->get_item( $key, $request );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( $result );
	}

	/**
	 * Handles POST create requests with conflict detection.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_create( WP_REST_Request $request ): WP_REST_Response {
		$data     = $request->get_json_params();
		$existing = $this->find_existing( $data, $request );

		if ( null !== $existing ) {
			return RestlessWP_Response_Formatter::error( 'conflict' );
		}

		$result = $this->create_item( $data, $request );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( $result, 201 );
	}

	/**
	 * Handles PUT update requests with partial merge.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_update( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$key        = $url_params['key'];
		$existing   = $this->get_item( $key, $request );

		if ( is_wp_error( $existing ) ) {
			return $this->wp_error_to_response( $existing );
		}

		$incoming = $request->get_json_params();
		$merged   = array_merge( $existing, $incoming );
		$result   = $this->update_item( $key, $merged, $request );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( $result );
	}

	/**
	 * Handles DELETE requests.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_delete( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$key        = $url_params['key'];
		$result     = $this->delete_item( $key, $request );

		if ( is_wp_error( $result ) ) {
			return $this->wp_error_to_response( $result );
		}

		return RestlessWP_Response_Formatter::success( $result );
	}

	/**
	 * Converts a WP_Error into a formatted REST response.
	 *
	 * @param WP_Error $error WordPress error object.
	 * @return WP_REST_Response
	 */
	protected function wp_error_to_response( WP_Error $error ): WP_REST_Response {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;

		return RestlessWP_Response_Formatter::error( $code, $message, $status );
	}
}
