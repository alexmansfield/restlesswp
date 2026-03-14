<?php
/**
 * ACSS Module — declares the Automatic CSS integration.
 *
 * Requires Automatic CSS 3.0.0+ for the stable public API class.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic CSS module definition for RestlessWP.
 */
class RestlessWP_ACSS_Module extends RestlessWP_Base_Module {

	/**
	 * Returns the target plugin file path for Automatic CSS.
	 *
	 * @return string Plugin file path relative to the plugins directory.
	 */
	public function get_plugin_file(): string {
		return 'automaticcss-plugin/automaticcss-plugin.php';
	}

	/**
	 * Returns the module slug used for route namespacing.
	 *
	 * @return string Module slug.
	 */
	public function get_module_slug(): string {
		return 'acss';
	}

	/**
	 * Returns the minimum required Automatic CSS version.
	 *
	 * The public \Automatic_CSS\API class is stable from v3.0.0 onward.
	 *
	 * @return string Minimum version string.
	 */
	public function get_min_version(): string {
		return '3.0.0';
	}

	/**
	 * Returns the resource definitions for the ACSS module.
	 *
	 * @return array<string, string> Resource slug => controller class name.
	 */
	public function get_resources(): array {
		return array(
			'variables' => 'RestlessWP_ACSS_Variables_Controller',
			'classes'   => 'RestlessWP_ACSS_Classes_Controller',
		);
	}
}
