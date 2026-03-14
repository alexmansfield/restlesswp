<?php
/**
 * Abilities Registrar — registers controller operations as WordPress abilities.
 *
 * Handles two registration paths:
 * 1. Descriptor-based — modules return ability descriptors for third-party controllers.
 * 2. Controller introspection — standard modules with RestlessWP controllers.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-descriptor-abilities.php';

/**
 * Registers RestlessWP controller operations as WordPress abilities.
 */
class RestlessWP_Abilities_Registrar {

	use RestlessWP_Descriptor_Abilities;

	/**
	 * Standard CRUD operations with their annotations and capability type.
	 *
	 * @var array<string, array{label: string, annotation: string, capability: string}>
	 */
	private const OPERATIONS = array(
		'list'   => array(
			'label'      => 'List',
			'annotation' => 'readonly',
			'capability' => 'read',
		),
		'get'    => array(
			'label'      => 'Get',
			'annotation' => 'readonly',
			'capability' => 'read',
		),
		'create' => array(
			'label'      => 'Create',
			'annotation' => 'open_world',
			'capability' => 'write',
		),
		'update' => array(
			'label'      => 'Update',
			'annotation' => 'idempotent',
			'capability' => 'write',
		),
		'delete'        => array(
			'label'      => 'Delete',
			'annotation' => 'destructive',
			'capability' => 'delete',
		),
		'bulk-update'   => array(
			'label'      => 'Bulk Update',
			'annotation' => 'idempotent',
			'capability' => 'write',
		),
		'bulk-replace'  => array(
			'label'      => 'Bulk Replace',
			'annotation' => 'destructive',
			'capability' => 'write',
		),
		'orphan-detect' => array(
			'label'      => 'Detect Orphans',
			'annotation' => 'readonly',
			'capability' => 'read',
		),
		'convert'       => array(
			'label'      => 'Convert',
			'annotation' => 'idempotent',
			'capability' => 'write',
		),
		'import'        => array(
			'label'      => 'Import',
			'annotation' => 'open_world',
			'capability' => 'write',
		),
		'list-backups'  => array(
			'label'      => 'List Backups',
			'annotation' => 'readonly',
			'capability' => 'read',
		),
		'get-backup'    => array(
			'label'      => 'Get Backup',
			'annotation' => 'readonly',
			'capability' => 'read',
		),
	);

	/**
	 * Auth handler instance.
	 *
	 * @var RestlessWP_Auth_Handler
	 */
	private RestlessWP_Auth_Handler $auth;

	/**
	 * Schema builder instance.
	 *
	 * @var RestlessWP_Ability_Schema_Builder
	 */
	private RestlessWP_Ability_Schema_Builder $schema_builder;

	/**
	 * Constructor.
	 *
	 * @param RestlessWP_Auth_Handler $auth Auth handler for permission callbacks.
	 */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		$this->auth           = $auth;
		$this->schema_builder = new RestlessWP_Ability_Schema_Builder();
	}

	/**
	 * Registers the RestlessWP ability category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category( 'restlesswp', array(
			'label'       => __( 'RestlessWP', 'restlesswp' ),
			'description' => __( 'REST API endpoints for WordPress plugin configuration.', 'restlesswp' ),
		) );
	}

	/**
	 * Registers abilities for all active modules.
	 *
	 * Checks each module for descriptors first; falls back to controller
	 * introspection for standard modules.
	 *
	 * @param array<string, RestlessWP_Base_Module> $modules Active modules.
	 * @return void
	 */
	public function register( array $modules ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $modules as $module ) {
			$descriptors = $module->get_ability_descriptors();

			if ( ! empty( $descriptors ) ) {
				$this->register_from_descriptors( $descriptors );
			} else {
				$this->register_from_controllers( $module );
			}
		}
	}

	/**
	 * Registers abilities by introspecting a module's controllers.
	 *
	 * @param RestlessWP_Base_Module $module Module instance.
	 * @return void
	 */
	private function register_from_controllers( RestlessWP_Base_Module $module ): void {
		$slug      = $module->get_module_slug();
		$resources = $module->get_resources();

		foreach ( $resources as $resource_slug => $controller_class ) {
			$controller = $this->instantiate_controller( $controller_class );

			if ( null === $controller ) {
				continue;
			}

			$this->register_controller_abilities(
				$controller,
				$slug,
				$resource_slug
			);
		}
	}

	/**
	 * Instantiates a RestlessWP controller class, loading its file if needed.
	 *
	 * @param string $controller_class Fully qualified controller class name.
	 * @return RestlessWP_Base_Controller|null Controller instance or null.
	 */
	private function instantiate_controller( string $controller_class ): ?RestlessWP_Base_Controller {
		if ( ! class_exists( $controller_class ) ) {
			$file = $this->resolve_controller_file( $controller_class );

			if ( null === $file || ! file_exists( $file ) ) {
				return null;
			}

			require_once $file;
		}

		if ( ! class_exists( $controller_class ) ) {
			return null;
		}

		return new $controller_class( $this->auth );
	}

	/**
	 * Resolves a controller class name to its expected file path.
	 *
	 * @param string $class_name Controller class name.
	 * @return string|null Absolute file path or null if unresolvable.
	 */
	private function resolve_controller_file( string $class_name ): ?string {
		$name = str_replace( '_', '-', $class_name );
		$name = strtolower( $name );
		$file = 'class-' . str_replace( 'restlesswp-', '', $name ) . '.php';

		$paths = array(
			RESTLESSWP_PATH . 'modules/controllers/' . $file,
			RESTLESSWP_PATH . 'modules/' . $file,
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Registers abilities for all operations of a single controller.
	 *
	 * @param RestlessWP_Base_Controller $controller    Controller instance.
	 * @param string                     $module_slug   Module slug.
	 * @param string                     $resource_slug Resource slug.
	 * @return void
	 */
	private function register_controller_abilities(
		RestlessWP_Base_Controller $controller,
		string $module_slug,
		string $resource_slug
	): void {
		$schema       = $controller->get_item_schema();
		$supported    = $controller->get_supported_operations();
		$descriptions = $controller->get_ability_descriptions();

		foreach ( self::OPERATIONS as $action => $config ) {
			if ( ! in_array( $action, $supported, true ) ) {
				continue;
			}

			$this->register_single_ability(
				$controller,
				$module_slug,
				$resource_slug,
				$action,
				$config,
				$schema,
				$descriptions[ $action ] ?? null
			);
		}
	}

	/**
	 * Registers a single ability for one RestlessWP controller operation.
	 *
	 * @param RestlessWP_Base_Controller $controller         Controller instance.
	 * @param string                     $module_slug        Module slug.
	 * @param string                     $resource_slug      Resource slug.
	 * @param string                     $action             Operation name.
	 * @param array                      $config             Operation config.
	 * @param array                      $schema             Controller item schema.
	 * @param string|null                $custom_description Controller-provided description.
	 * @return void
	 */
	private function register_single_ability(
		RestlessWP_Base_Controller $controller,
		string $module_slug,
		string $resource_slug,
		string $action,
		array $config,
		array $schema,
		?string $custom_description = null
	): void {
		$ability_name  = "restlesswp/{$module_slug}-{$action}-{$resource_slug}";
		$capability    = $this->get_capability_for_operation( $controller, $config );
		$resource_name = str_replace( '-', ' ', $resource_slug );
		$description   = $custom_description ?? $this->build_description( $action, $resource_name );

		$input_schema = $controller->get_ability_input_schema( $action )
			?? $this->schema_builder->build_input_schema( $action, $schema );

		$this->do_register_ability(
			$ability_name,
			$config,
			$resource_slug,
			$description,
			$input_schema,
			$this->schema_builder->build_output_schema( $action, $schema ),
			$this->build_execute_callback( $controller, $action ),
			$this->auth->permission_callback( $capability )
		);
	}

	/** Calls wp_register_ability() — single registration call site for both paths. */
	private function do_register_ability(
		string $ability_name,
		array $config,
		string $resource_slug,
		string $description,
		array $input_schema,
		array $output_schema,
		\Closure $execute_callback,
		\Closure $permission_callback
	): void {
		wp_register_ability( $ability_name, array(
			'label'               => $this->build_label( $config['label'], $resource_slug ),
			'description'         => $description,
			'category'            => 'restlesswp',
			'input_schema'        => $input_schema,
			'output_schema'       => $output_schema,
			'execute_callback'    => $execute_callback,
			'permission_callback' => $permission_callback,
			'meta'                => array(
				'annotations'  => array( $config['annotation'] => true ),
				'show_in_rest' => true,
			),
		) );
	}

	/**
	 * Builds a human-readable label for an ability.
	 *
	 * @param string $label         Operation label (e.g. 'List').
	 * @param string $resource_slug Resource slug (e.g. 'field-groups').
	 * @return string Formatted label.
	 */
	private function build_label( string $label, string $resource_slug ): string {
		$resource_name = ucwords( str_replace( '-', ' ', $resource_slug ) );
		return "{$label} {$resource_name}";
	}

	/**
	 * Builds a description string for an ability.
	 *
	 * @param string $action        Operation name.
	 * @param string $resource_name Human-readable resource name.
	 * @return string Description string.
	 */
	private function build_description( string $action, string $resource_name ): string {
		$descriptions = array(
			'list'          => "List all {$resource_name}.",
			'get'           => "Get a single {$resource_name} item by ID.",
			'create'        => "Create a new {$resource_name} item.",
			'update'        => "Update an existing {$resource_name} item.",
			'delete'        => "Delete a {$resource_name} item.",
			'bulk-update'   => "Bulk update multiple {$resource_name} at once.",
			'bulk-replace'  => "Bulk replace all {$resource_name}.",
			'orphan-detect' => "Detect orphaned {$resource_name}.",
			'convert'       => "Convert {$resource_name} to a different format.",
			'import'        => "Import {$resource_name} with related resources in one call.",
		);

		return $descriptions[ $action ] ?? "Perform {$action} on {$resource_name}.";
	}

	/**
	 * Gets the capability string for an operation from a controller.
	 *
	 * @param RestlessWP_Base_Controller $controller Controller instance.
	 * @param array                      $config     Operation config.
	 * @return string WordPress capability string.
	 */
	private function get_capability_for_operation(
		RestlessWP_Base_Controller $controller,
		array $config
	): string {
		if ( 'read' === $config['capability'] ) {
			return $controller->get_read_capability_for_ability();
		}

		return $controller->get_write_capability_for_ability();
	}

	/**
	 * Builds the execute callback for a RestlessWP controller ability.
	 *
	 * @param RestlessWP_Base_Controller $controller Controller instance.
	 * @param string                     $action     Operation name.
	 * @return \Closure Execute callback closure.
	 */
	private function build_execute_callback(
		RestlessWP_Base_Controller $controller,
		string $action
	): \Closure {
		return function ( $input = array() ) use ( $controller, $action ) {
			return $this->execute_ability( $controller, $action, $input );
		};
	}

	/**
	 * Executes an ability by delegating to a RestlessWP controller handler.
	 *
	 * @param RestlessWP_Base_Controller $controller Controller instance.
	 * @param string                     $action     Operation name.
	 * @param array                      $input      Ability input data.
	 * @return mixed Response data or WP_Error.
	 */
	private function execute_ability(
		RestlessWP_Base_Controller $controller,
		string $action,
		array $input
	) {
		$request = $this->build_request( $action, $input );
		$handler = $this->get_handler_method( $action );

		$response = $controller->$handler( $request );
		$data     = $response->get_data();

		if ( ! empty( $data['success'] ) ) {
			return $data['data'] ?? $data;
		}

		return new \WP_Error(
			$data['code'] ?? 'ability_error',
			$data['message'] ?? __( 'Ability execution failed.', 'restlesswp' ),
			array( 'status' => $response->get_status() )
		);
	}

	/**
	 * Builds a WP_REST_Request from ability input data.
	 *
	 * @param string $action Operation name.
	 * @param array  $input  Ability input data.
	 * @return WP_REST_Request Populated request object.
	 */
	private function build_request( string $action, array $input ): WP_REST_Request {
		$method_map = array(
			'list'          => 'GET',
			'get'           => 'GET',
			'create'        => 'POST',
			'update'        => 'PUT',
			'delete'        => 'DELETE',
			'bulk-update'   => 'PATCH',
			'bulk-replace'  => 'PUT',
			'orphan-detect' => 'GET',
			'convert'       => 'POST',
			'import'        => 'POST',
			'list-backups'  => 'GET',
			'get-backup'    => 'GET',
		);

		$request = new WP_REST_Request( $method_map[ $action ] ?? 'GET' );

		if ( isset( $input['key'] ) ) {
			$request->set_url_params( array( 'key' => $input['key'] ) );
		}

		if ( isset( $input['index'] ) ) {
			$request->set_url_params( array( 'index' => $input['index'] ) );
		}

		if ( in_array( $action, array( 'bulk-update', 'bulk-replace', 'convert', 'import' ), true ) ) {
			$request->set_body( wp_json_encode( $input ) );
			$request->set_header( 'Content-Type', 'application/json' );
		} elseif ( in_array( $action, array( 'create', 'update' ), true ) ) {
			$body = $input;
			unset( $body['key'] );
			$request->set_body( wp_json_encode( $body ) );
			$request->set_header( 'Content-Type', 'application/json' );
		}

		return $request;
	}

	/**
	 * Maps an operation name to its RestlessWP controller handler method.
	 *
	 * @param string $action Operation name.
	 * @return string Handler method name.
	 */
	private function get_handler_method( string $action ): string {
		$map = RestlessWP_Ability_Descriptor::restlesswp_methods();
		return $map[ $action ] ?? 'handle_list';
	}
}
