<?php
/**
 * Etch Module — declares the Etch page builder integration.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Etch module definition for RestlessWP.
 */
class RestlessWP_Etch_Module extends RestlessWP_Base_Module {

	/**
	 * Returns the target plugin file path for Etch.
	 *
	 * @return string Plugin file path relative to the plugins directory.
	 */
	public function get_plugin_file(): string {
		return 'etch/etch.php';
	}

	/**
	 * Returns the module slug used for route namespacing.
	 *
	 * @return string Module slug.
	 */
	public function get_module_slug(): string {
		return 'etch';
	}

	/**
	 * Returns the minimum required Etch version.
	 *
	 * @return string Minimum version string.
	 */
	public function get_min_version(): string {
		return '1.0.0';
	}

	/**
	 * Returns the resource definitions for the Etch module.
	 *
	 * @return array<string, string> Resource slug => controller class name.
	 */
	public function get_resources(): array {
		return array(
			'styles'      => 'RestlessWP_Etch_Styles_Controller',
			'components'  => 'RestlessWP_Etch_Components_Controller',
			'blocks'      => 'RestlessWP_Etch_Blocks_Controller',
			'loops'       => 'RestlessWP_Etch_Loops_Controller',
			'stylesheets' => 'RestlessWP_Etch_Stylesheets_Controller',
			'templates'   => 'RestlessWP_Etch_Templates_Controller',
			'pages'       => 'RestlessWP_Etch_Pages_Controller',
		);
	}
}
