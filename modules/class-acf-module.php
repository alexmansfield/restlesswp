<?php
/**
 * ACF Module — declares the Advanced Custom Fields integration.
 *
 * Supports both the free and Pro variants of ACF. Requires ACF 6.7.0+.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ACF module definition for RestlessWP.
 */
class RestlessWP_ACF_Module extends RestlessWP_Base_Module {

	/**
	 * Returns the target plugin file paths for ACF free and Pro.
	 *
	 * @return string[] Plugin file paths relative to the plugins directory.
	 */
	public function get_plugin_file(): array {
		return array(
			'advanced-custom-fields/acf.php',
			'advanced-custom-fields-pro/acf.php',
		);
	}

	/**
	 * Returns the module slug used for route namespacing.
	 *
	 * @return string Module slug.
	 */
	public function get_module_slug(): string {
		return 'acf';
	}

	/**
	 * Returns the minimum required ACF version.
	 *
	 * @return string Minimum version string.
	 */
	public function get_min_version(): string {
		return '6.7.0';
	}

	/**
	 * Minimum ACF version for post type and taxonomy UI support.
	 *
	 * @var string
	 */
	private const POST_TYPE_TAXONOMY_MIN_VERSION = '6.1';

	/**
	 * Returns the resource definitions for the ACF module.
	 *
	 * Field groups are available in all ACF 6.x versions. Post types and
	 * taxonomies require ACF 6.1+ (when ACF introduced its post type and
	 * taxonomy UI). Controllers for those resources are omitted when the
	 * installed ACF version is below 6.1.
	 *
	 * @return array<string, string> Resource slug => controller class name.
	 */
	public function get_resources(): array {
		$resources = array(
			'field-groups' => 'RestlessWP_ACF_Field_Groups_Controller',
		);

		if ( $this->supports_post_type_taxonomy_ui() ) {
			$resources['post-types'] = 'RestlessWP_ACF_Post_Types_Controller';
			$resources['taxonomies'] = 'RestlessWP_ACF_Taxonomies_Controller';
		}

		return $resources;
	}

	/**
	 * Checks whether the active ACF version supports post type and taxonomy UI.
	 *
	 * Uses the ACF_VERSION constant when available, which is defined by both
	 * ACF free and Pro variants.
	 *
	 * @return bool True if ACF version is 6.1 or higher.
	 */
	private function supports_post_type_taxonomy_ui(): bool {
		if ( ! defined( 'ACF_VERSION' ) ) {
			return false;
		}

		return version_compare( ACF_VERSION, self::POST_TYPE_TAXONOMY_MIN_VERSION, '>=' );
	}
}
