<?php
/**
 * Base Module — abstract class for all RestlessWP plugin modules.
 *
 * Each supported plugin (ACF, ACSS, etc.) extends this class to declare
 * its dependency, slug, and resource controllers. The module silently
 * skips loading when its target plugin is not active.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for plugin modules.
 *
 * Subclasses must implement three methods: get_plugin_file(),
 * get_module_slug(), and get_resources(). Optionally override
 * get_min_version() to enforce a version floor.
 */
abstract class RestlessWP_Base_Module {

	/**
	 * Returns the target plugin's main file path(s) relative to wp-content/plugins/.
	 *
	 * Return a single string for plugins with one variant, or an array of
	 * strings to handle free/pro variants (e.g., ACF free vs Pro).
	 *
	 * @return string|string[] Plugin file path(s), e.g. 'advanced-custom-fields/acf.php'.
	 */
	abstract public function get_plugin_file(): string|array;

	/**
	 * Returns the module slug used for route namespacing.
	 *
	 * @return string Module slug, e.g. 'acf'.
	 */
	abstract public function get_module_slug(): string;

	/**
	 * Returns an array of resource definitions (controller class names).
	 *
	 * Each entry maps a resource slug to its controller class name.
	 * Example: [ 'field-groups' => 'RestlessWP_ACF_Field_Groups_Controller' ]
	 *
	 * @return array<string, string> Resource slug => controller class name.
	 */
	abstract public function get_resources(): array;

	/**
	 * Returns the minimum required version of the target plugin.
	 *
	 * Override in subclass to enforce a version floor. Return an empty
	 * string to skip version checking (the default).
	 *
	 * @return string Minimum version string, or empty string to skip check.
	 */
	public function get_min_version(): string {
		return '';
	}

	/**
	 * Returns ability descriptors for this module.
	 *
	 * Override in abilities-only modules (e.g. Gravity Forms) to return
	 * descriptors that configure how the unified registrar should register
	 * abilities for third-party controllers. Standard modules with RestlessWP
	 * controllers leave this empty — the registrar introspects their controllers.
	 *
	 * @return RestlessWP_Ability_Descriptor[] Array of ability descriptors.
	 */
	public function get_ability_descriptors(): array {
		return array();
	}

	/**
	 * Checks whether the target plugin is installed and active.
	 *
	 * Handles both single plugin file paths and arrays of paths
	 * (for free/pro variants). Returns true if any variant is active.
	 *
	 * @return bool True if at least one variant of the target plugin is active.
	 */
	public function is_active(): bool {
		$plugin_files = $this->get_plugin_file();

		if ( is_string( $plugin_files ) ) {
			$plugin_files = array( $plugin_files );
		}

		foreach ( $plugin_files as $plugin_file ) {
			if ( $this->is_plugin_active( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether the target plugin meets the minimum version requirement.
	 *
	 * Returns true if no minimum version is set. When the plugin is active
	 * but below the required version, returns a WP_Error with details.
	 *
	 * @return true|\WP_Error True if version is acceptable, WP_Error otherwise.
	 */
	public function check_version() {
		$min_version = $this->get_min_version();

		if ( '' === $min_version ) {
			return true;
		}

		$current_version = $this->get_active_plugin_version();

		if ( '' === $current_version ) {
			return true;
		}

		if ( version_compare( $current_version, $min_version, '>=' ) ) {
			return true;
		}

		return $this->version_error( $current_version, $min_version );
	}

	/**
	 * Returns a WP_Error for version-unsupported situations.
	 *
	 * @param string $current_version The installed plugin version.
	 * @param string $min_version     The minimum required version.
	 * @return \WP_Error Version mismatch error.
	 */
	private function version_error( string $current_version, string $min_version ): \WP_Error {
		return new \WP_Error(
			'version_unsupported',
			sprintf(
				/* translators: 1: Module slug, 2: Minimum version, 3: Current version. */
				__( 'The %1$s module requires version %2$s or higher. Installed version: %3$s.', 'restlesswp' ),
				$this->get_module_slug(),
				$min_version,
				$current_version
			),
			array( 'status' => 424 )
		);
	}

	/**
	 * Checks whether a single plugin file is active.
	 *
	 * Loads the plugin.php admin include if the function is not yet available
	 * (can happen during early hooks).
	 *
	 * @param string $plugin_file Plugin file path relative to plugins directory.
	 * @return bool True if the plugin is active.
	 */
	private function is_plugin_active( string $plugin_file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}

	/**
	 * Retrieves the version of the currently active target plugin variant.
	 *
	 * Iterates plugin file paths and returns the version of the first active
	 * variant found. Returns an empty string if no variant is active or if
	 * no version header is present.
	 *
	 * @return string Plugin version string, or empty string.
	 */
	private function get_active_plugin_version(): string {
		$plugin_files = $this->get_plugin_file();

		if ( is_string( $plugin_files ) ) {
			$plugin_files = array( $plugin_files );
		}

		foreach ( $plugin_files as $plugin_file ) {
			if ( ! $this->is_plugin_active( $plugin_file ) ) {
				continue;
			}

			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

			if ( ! empty( $plugin_data['Version'] ) ) {
				return $plugin_data['Version'];
			}
		}

		return '';
	}
}
