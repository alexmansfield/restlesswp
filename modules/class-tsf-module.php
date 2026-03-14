<?php
/**
 * TSF Module — declares The SEO Framework integration.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TSF module definition for RestlessWP.
 */
class RestlessWP_TSF_Module extends RestlessWP_Base_Module {

	/**
	 * Returns the target plugin file path for TSF.
	 *
	 * @return string Plugin file path relative to the plugins directory.
	 */
	public function get_plugin_file(): string {
		return 'autodescription/autodescription.php';
	}

	/**
	 * Returns the module slug used for route namespacing.
	 *
	 * @return string Module slug.
	 */
	public function get_module_slug(): string {
		return 'tsf';
	}

	/**
	 * Returns the minimum required TSF version.
	 *
	 * @return string Minimum version string.
	 */
	public function get_min_version(): string {
		return '5.0.0';
	}

	/**
	 * Returns the resource definitions for the TSF module.
	 *
	 * @return array<string, string> Resource slug => controller class name.
	 */
	public function get_resources(): array {
		return array(
			'post-seo' => 'RestlessWP_TSF_Post_SEO_Controller',
			'term-seo' => 'RestlessWP_TSF_Term_SEO_Controller',
			'settings' => 'RestlessWP_TSF_Settings_Controller',
			'pta-seo'  => 'RestlessWP_TSF_PTA_SEO_Controller',
			'user-seo' => 'RestlessWP_TSF_User_SEO_Controller',
		);
	}
}
