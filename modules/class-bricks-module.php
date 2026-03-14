<?php
/**
 * Bricks Module — declares the Bricks Builder theme integration.
 *
 * Bricks is a theme (not a plugin), so this module overrides the base
 * detection methods to use theme APIs instead of plugin APIs.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks module definition for RestlessWP.
 */
class RestlessWP_Bricks_Module extends RestlessWP_Base_Module {

	/**
	 * Returns an empty string — unused because is_active() is overridden.
	 *
	 * @return string Empty string.
	 */
	public function get_plugin_file(): string {
		return '';
	}

	/**
	 * Returns the module slug used for route namespacing.
	 *
	 * @return string Module slug.
	 */
	public function get_module_slug(): string {
		return 'bricks';
	}

	/**
	 * Returns the minimum required Bricks version.
	 *
	 * @return string Minimum version string.
	 */
	public function get_min_version(): string {
		return '2.0';
	}

	/**
	 * Checks whether Bricks is the active theme (or parent of a child theme).
	 *
	 * @return bool True if Bricks is the active theme template.
	 */
	public function is_active(): bool {
		return 'bricks' === wp_get_theme()->get_template();
	}

	/**
	 * Checks whether the installed Bricks version meets the minimum requirement.
	 *
	 * @return true|\WP_Error True if version is acceptable, WP_Error otherwise.
	 */
	public function check_version() {
		$min_version = $this->get_min_version();
		$theme       = wp_get_theme( 'bricks' );
		$version     = $theme->get( 'Version' );

		if ( ! $version ) {
			return true;
		}

		if ( version_compare( $version, $min_version, '>=' ) ) {
			return true;
		}

		return new \WP_Error(
			'version_unsupported',
			sprintf(
				/* translators: 1: Module slug, 2: Minimum version, 3: Current version. */
				__( 'The %1$s module requires version %2$s or higher. Installed version: %3$s.', 'restlesswp' ),
				$this->get_module_slug(),
				$min_version,
				$version
			),
			array( 'status' => 424 )
		);
	}

	/**
	 * Returns the resource definitions for the Bricks module.
	 *
	 * @return array<string, string> Resource slug => controller class name.
	 */
	public function get_resources(): array {
		return array(
			'global-classes' => 'RestlessWP_Bricks_Global_Classes_Controller',
			'components'     => 'RestlessWP_Bricks_Components_Controller',
			'templates'      => 'RestlessWP_Bricks_Templates_Controller',
			'pages'          => 'RestlessWP_Bricks_Pages_Controller',
			'variables'      => 'RestlessWP_Bricks_Variables_Controller',
			'color-palette'  => 'RestlessWP_Bricks_Color_Palette_Controller',
			'theme-styles'   => 'RestlessWP_Bricks_Theme_Styles_Controller',
		);
	}
}
