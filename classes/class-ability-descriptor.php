<?php
/**
 * Ability Descriptor — value object describing how to register abilities for a resource.
 *
 * Captures the differences between RestlessWP controllers and third-party
 * controllers (method names, ID params, permissions, response handling)
 * so the unified registrar can handle both paths.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object for ability registration configuration.
 */
class RestlessWP_Ability_Descriptor {

	/**
	 * Fully qualified controller class name.
	 *
	 * @var string
	 */
	public readonly string $controller_class;

	/**
	 * Module slug (e.g. 'gf').
	 *
	 * @var string
	 */
	public readonly string $module_slug;

	/**
	 * Resource slug (e.g. 'forms').
	 *
	 * @var string
	 */
	public readonly string $resource_slug;

	/**
	 * Operations to register (e.g. ['list', 'get', 'create']).
	 *
	 * @var string[]
	 */
	public readonly array $operations;

	/**
	 * Maps operation names to controller method names.
	 *
	 * @var array<string, string>
	 */
	public readonly array $method_map;

	/**
	 * URL parameter name for single-item routes.
	 *
	 * @var string
	 */
	public readonly string $id_param;

	/**
	 * JSON Schema type for the ID parameter.
	 *
	 * @var string
	 */
	public readonly string $id_type;

	/**
	 * Capability strings keyed by type: read, write, delete.
	 *
	 * @var array<string, string>
	 */
	public readonly array $capabilities;

	/**
	 * Builds permission callbacks. Receives cap_type string, returns closure.
	 *
	 * @var callable|null
	 */
	public readonly mixed $permission_factory;

	/**
	 * Unwraps controller responses for the abilities API.
	 *
	 * @var callable|null
	 */
	public readonly mixed $response_unwrapper;

	/**
	 * Prefix for ability descriptions (e.g. 'Gravity Forms').
	 *
	 * @var string
	 */
	public readonly string $description_prefix;

	/**
	 * Absolute path to the controller file, loaded before instantiation.
	 *
	 * @var string
	 */
	public readonly string $controller_file;

	/**
	 * Constructor.
	 *
	 * @param array $args {
	 *     @type string        $controller_class   Required. FQCN of the controller.
	 *     @type string        $module_slug        Required. Module slug.
	 *     @type string        $resource_slug      Required. Resource slug.
	 *     @type string[]      $operations         Required. Operations to register.
	 *     @type array         $method_map         Operation => method name map.
	 *     @type string        $id_param           URL param name for IDs. Default 'id'.
	 *     @type string        $id_type            JSON Schema type for ID. Default 'integer'.
	 *     @type array         $capabilities       Capability strings by type.
	 *     @type callable|null $permission_factory  Custom permission callback builder.
	 *     @type callable|null $response_unwrapper  Custom response unwrapper.
	 *     @type string        $description_prefix  Prefix for descriptions.
	 *     @type string        $controller_file     Absolute path to controller file.
	 * }
	 */
	public function __construct( array $args ) {
		$this->controller_class   = $args['controller_class'];
		$this->module_slug        = $args['module_slug'];
		$this->resource_slug      = $args['resource_slug'];
		$this->operations         = $args['operations'];
		$this->method_map         = $args['method_map'] ?? self::wp_rest_methods();
		$this->id_param           = $args['id_param'] ?? 'id';
		$this->id_type            = $args['id_type'] ?? 'integer';
		$this->capabilities       = $args['capabilities'] ?? array();
		$this->permission_factory = $args['permission_factory'] ?? null;
		$this->response_unwrapper = $args['response_unwrapper'] ?? null;
		$this->description_prefix = $args['description_prefix'] ?? '';
		$this->controller_file    = $args['controller_file'] ?? '';
	}

	/**
	 * Returns the standard RestlessWP controller method map.
	 *
	 * @return array<string, string>
	 */
	public static function restlesswp_methods(): array {
		return array(
			'list'          => 'handle_list',
			'get'           => 'handle_get',
			'create'        => 'handle_create',
			'update'        => 'handle_update',
			'delete'        => 'handle_delete',
			'bulk-update'   => 'handle_bulk_update',
			'bulk-replace'  => 'handle_bulk_replace',
			'orphan-detect' => 'handle_orphans',
			'convert'       => 'handle_convert',
			'import'        => 'handle_import',
			'list-backups'  => 'handle_list_backups',
			'get-backup'    => 'handle_get_backup',
		);
	}

	/**
	 * Returns the standard WP REST API controller method map.
	 *
	 * @return array<string, string>
	 */
	public static function wp_rest_methods(): array {
		return array(
			'list'   => 'get_items',
			'get'    => 'get_item',
			'create' => 'create_item',
			'update' => 'update_item',
			'delete' => 'delete_item',
		);
	}
}
