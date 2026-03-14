<?php
/**
 * Module Registry — discovers, validates, and registers plugin modules.
 *
 * Auto-discovers module files from the modules/ directory, checks each
 * module's target plugin dependency, and triggers REST route registration
 * for active modules on rest_api_init.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers and manages RestlessWP plugin modules.
 */
class RestlessWP_Module_Registry {

	/**
	 * Active module instances keyed by module slug.
	 *
	 * @var array<string, RestlessWP_Base_Module>
	 */
	private array $active_modules = array();

	/**
	 * Initializes the registry: discovers modules and hooks route registration.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->discover_modules();
		$this->hook_route_registration();
		$this->hook_ability_registration();
	}

	/**
	 * Returns all active module instances keyed by slug.
	 *
	 * @return array<string, RestlessWP_Base_Module>
	 */
	public function get_active_modules(): array {
		return $this->active_modules;
	}

	/**
	 * Discovers module files and filters for active modules.
	 *
	 * Scans the modules/ directory for class-*-module.php files, then
	 * applies the restlesswp_modules filter for third-party additions.
	 *
	 * @return void
	 */
	private function discover_modules(): void {
		$modules = $this->load_module_files();

		/**
		 * Filters the list of module instances before activation check.
		 *
		 * Third-party plugins can append their own RestlessWP_Base_Module
		 * instances to this array.
		 *
		 * @param RestlessWP_Base_Module[] $modules Array of module instances.
		 */
		$modules = apply_filters( 'restlesswp_modules', $modules );

		foreach ( $modules as $module ) {
			$this->maybe_register_module( $module );
		}
	}

	/**
	 * Loads module files from the modules/ directory.
	 *
	 * @return RestlessWP_Base_Module[] Array of instantiated module objects.
	 */
	private function load_module_files(): array {
		$modules_dir = RESTLESSWP_PATH . 'modules/';

		if ( ! is_dir( $modules_dir ) ) {
			return array();
		}

		$files = glob( $modules_dir . 'class-*-module.php' );

		if ( false === $files || empty( $files ) ) {
			return array();
		}

		$modules = array();

		foreach ( $files as $file ) {
			$module = $this->load_single_module( $file );

			if ( null !== $module ) {
				$modules[] = $module;
			}
		}

		return $modules;
	}

	/**
	 * Loads a single module file and returns its instance.
	 *
	 * Captures classes defined before and after the require to identify
	 * the new module class.
	 *
	 * @param string $file Absolute path to the module file.
	 * @return RestlessWP_Base_Module|null Module instance or null on failure.
	 */
	private function load_single_module( string $file ): ?RestlessWP_Base_Module {
		$before = get_declared_classes();

		require_once $file;

		$after       = get_declared_classes();
		$new_classes = array_diff( $after, $before );

		foreach ( $new_classes as $class_name ) {
			if ( is_subclass_of( $class_name, 'RestlessWP_Base_Module' ) ) {
				return new $class_name();
			}
		}

		return null;
	}

	/**
	 * Registers a module if it is active and passes version check.
	 *
	 * @param RestlessWP_Base_Module $module Module instance to evaluate.
	 * @return void
	 */
	private function maybe_register_module( RestlessWP_Base_Module $module ): void {
		if ( ! ( $module instanceof RestlessWP_Base_Module ) ) {
			return;
		}

		if ( ! $module->is_active() ) {
			return;
		}

		$version_check = $module->check_version();

		if ( is_wp_error( $version_check ) ) {
			return;
		}

		$slug = $module->get_module_slug();
		$this->active_modules[ $slug ] = $module;
	}

	/**
	 * Hooks REST route registration for active modules onto rest_api_init.
	 *
	 * @return void
	 */
	private function hook_route_registration(): void {
		if ( empty( $this->active_modules ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Hooks ability registration for active modules onto init.
	 *
	 * The unified registrar handles both descriptor-based modules
	 * (abilities-only) and controller-introspection modules (full).
	 *
	 * @return void
	 */
	private function hook_ability_registration(): void {
		if ( empty( $this->active_modules ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$auth      = new RestlessWP_Auth_Handler();
		$registrar = new RestlessWP_Abilities_Registrar( $auth );

		add_action( 'wp_abilities_api_categories_init', array( $registrar, 'register_category' ) );

		$modules = $this->active_modules;

		add_action(
			'wp_abilities_api_init',
			function () use ( $registrar, $modules ) {
				$registrar->register( $modules );
			}
		);
	}

	/**
	 * Registers REST routes for all active modules.
	 *
	 * Skips modules that return an empty resources array (abilities-only modules).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$auth = new RestlessWP_Auth_Handler();

		foreach ( $this->active_modules as $module ) {
			if ( ! empty( $module->get_resources() ) ) {
				$this->register_module_routes( $module, $auth );
			}
		}
	}

	/**
	 * Registers routes for a single module's controllers.
	 *
	 * @param RestlessWP_Base_Module $module Module instance.
	 * @param RestlessWP_Auth_Handler $auth  Auth handler instance.
	 * @return void
	 */
	private function register_module_routes( RestlessWP_Base_Module $module, RestlessWP_Auth_Handler $auth ): void {
		$resources = $module->get_resources();

		foreach ( $resources as $resource_slug => $controller_class ) {
			$this->register_controller_routes( $controller_class, $auth );
		}
	}

	/**
	 * Requires the controller file and registers its routes.
	 *
	 * @param string                  $controller_class Fully qualified controller class name.
	 * @param RestlessWP_Auth_Handler $auth             Auth handler instance.
	 * @return void
	 */
	private function register_controller_routes( string $controller_class, RestlessWP_Auth_Handler $auth ): void {
		if ( ! class_exists( $controller_class ) ) {
			$file = $this->resolve_controller_file( $controller_class );

			if ( null === $file || ! file_exists( $file ) ) {
				return;
			}

			require_once $file;
		}

		if ( ! class_exists( $controller_class ) ) {
			return;
		}

		$controller = new $controller_class( $auth );
		$controller->register_routes();
	}

	/**
	 * Resolves a controller class name to its expected file path.
	 *
	 * Converts the class name to a file path following WordPress naming
	 * conventions: RestlessWP_ACF_Field_Groups_Controller becomes
	 * class-acf-field-groups-controller.php in the modules/controllers/ directory.
	 *
	 * @param string $class_name Controller class name.
	 * @return string|null Absolute file path or null if unresolvable.
	 */
	private function resolve_controller_file( string $class_name ): ?string {
		$name = str_replace( '_', '-', $class_name );
		$name = strtolower( $name );
		$file = 'class-' . str_replace( 'restlesswp-', '', $name ) . '.php';

		$path = RESTLESSWP_PATH . 'modules/controllers/' . $file;

		if ( file_exists( $path ) ) {
			return $path;
		}

		$path = RESTLESSWP_PATH . 'modules/' . $file;

		if ( file_exists( $path ) ) {
			return $path;
		}

		return null;
	}
}
