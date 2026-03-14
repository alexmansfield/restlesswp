<?php
/**
 * GF Module — registers Gravity Forms abilities via descriptors.
 *
 * Unlike other RestlessWP modules, this module does not create new REST
 * endpoints. Gravity Forms already has a capable REST API at /gf/v2/.
 * This module returns ability descriptors that tell the unified registrar
 * how to bridge GF's controllers to the Abilities API.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gravity Forms abilities-only module for RestlessWP.
 */
class RestlessWP_GF_Module extends RestlessWP_Base_Module {

	/**
	 * Returns the target plugin file path.
	 *
	 * @return string Plugin file path relative to the plugins directory.
	 */
	public function get_plugin_file(): string {
		return 'gravityforms/gravityforms.php';
	}

	/**
	 * Returns the module slug used for ability namespacing.
	 *
	 * @return string Module slug.
	 */
	public function get_module_slug(): string {
		return 'gf';
	}

	/**
	 * Returns the minimum required Gravity Forms version.
	 *
	 * REST API v2 was added in GF 2.4.
	 *
	 * @return string Minimum version string.
	 */
	public function get_min_version(): string {
		return '2.4';
	}

	/**
	 * Returns an empty resources array — no RestlessWP controllers needed.
	 *
	 * @return array Empty array.
	 */
	public function get_resources(): array {
		return array();
	}

	/**
	 * Returns ability descriptors for all Gravity Forms resources.
	 *
	 * @return RestlessWP_Ability_Descriptor[] Descriptors for forms, entries, notes, feeds.
	 */
	public function get_ability_descriptors(): array {
		$slug    = $this->get_module_slug();
		$methods = RestlessWP_Ability_Descriptor::wp_rest_methods();
		$dir     = self::gf_controllers_dir();

		self::load_gf_controllers( $dir );

		$shared = array(
			'method_map'         => $methods,
			'id_param'           => 'id',
			'id_type'            => 'integer',
			'permission_factory' => array( __CLASS__, 'gf_permission_factory' ),
			'response_unwrapper' => array( __CLASS__, 'gf_response_unwrapper' ),
			'description_prefix' => 'Gravity Forms',
		);

		return array(
			new RestlessWP_Ability_Descriptor( array_merge( $shared, array(
				'controller_class' => 'GF_REST_Forms_Controller',
				'module_slug'      => $slug,
				'resource_slug'    => 'forms',
				'operations'       => array( 'list', 'get', 'create', 'update', 'delete' ),
				'capabilities'     => array(
					'read'   => 'gravityforms_edit_forms',
					'write'  => 'gravityforms_edit_forms',
					'delete' => 'gravityforms_delete_forms',
				),
			) ) ),
			new RestlessWP_Ability_Descriptor( array_merge( $shared, array(
				'controller_class' => 'GF_REST_Entries_Controller',
				'module_slug'      => $slug,
				'resource_slug'    => 'entries',
				'operations'       => array( 'list', 'get', 'create', 'update', 'delete' ),
				'id_param'         => 'entry_id',
				'capabilities'     => array(
					'read'   => 'gravityforms_view_entries',
					'write'  => 'gravityforms_edit_entries',
					'delete' => 'gravityforms_delete_entries',
				),
			) ) ),
			new RestlessWP_Ability_Descriptor( array_merge( $shared, array(
				'controller_class' => 'GF_REST_Entry_Notes_Controller',
				'module_slug'      => $slug,
				'resource_slug'    => 'entry-notes',
				'operations'       => array( 'list', 'create' ),
				'id_param'         => 'entry_id',
				'capabilities'     => array(
					'read'  => 'gravityforms_view_entry_notes',
					'write' => 'gravityforms_edit_entry_notes',
				),
			) ) ),
			new RestlessWP_Ability_Descriptor( array_merge( $shared, array(
				'controller_class' => 'GF_REST_Feeds_Controller',
				'module_slug'      => $slug,
				'resource_slug'    => 'feeds',
				'operations'       => array( 'list', 'get', 'create', 'update', 'delete' ),
				'capabilities'     => array(
					'read'  => 'gravityforms_edit_forms',
					'write' => 'gravityforms_edit_forms',
				),
			) ) ),
		);
	}

	/**
	 * Returns the path to GF's REST API v2 controllers directory.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	private static function gf_controllers_dir(): string {
		return WP_PLUGIN_DIR . '/gravityforms/includes/webapi/v2/includes/controllers/';
	}

	/**
	 * Loads GF REST controller files in dependency order.
	 *
	 * GF controllers are lazily loaded (normally on rest_api_init), so we
	 * must require them before descriptor-based registration can instantiate them.
	 *
	 * @param string $dir Controllers directory path.
	 * @return void
	 */
	private static function load_gf_controllers( string $dir ): void {
		if ( class_exists( 'GF_REST_Forms_Controller' ) ) {
			return;
		}

		$files = array(
			'class-gf-rest-controller.php',
			'class-controller-form-entries.php',
			'class-controller-entries.php',
			'class-controller-entry-notes.php',
			'class-controller-form-feeds.php',
			'class-controller-feeds.php',
			'class-controller-forms.php',
		);

		foreach ( $files as $file ) {
			$path = $dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Builds a GF-specific permission callback.
	 *
	 * Uses GFCommon::current_user_can_any() instead of core current_user_can().
	 *
	 * @param string $capability GF capability string (e.g. 'gravityforms_edit_forms').
	 * @return \Closure Permission callback closure.
	 */
	public static function gf_permission_factory( string $capability ): \Closure {
		return function () use ( $capability ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'restlesswp_not_authenticated',
					__( 'Authentication required.', 'restlesswp' ),
					array( 'status' => 401 )
				);
			}

			if ( ! GFCommon::current_user_can_any( $capability ) ) {
				return new \WP_Error(
					'restlesswp_forbidden',
					__( 'Insufficient permissions.', 'restlesswp' ),
					array( 'status' => 403 )
				);
			}

			return true;
		};
	}

	/**
	 * Unwraps a GF controller response for the abilities API.
	 *
	 * GF controllers return raw data or WP_REST_Response — no RestlessWP
	 * envelope to strip.
	 *
	 * @param mixed $response Controller response.
	 * @return mixed Unwrapped data or WP_Error.
	 */
	public static function gf_response_unwrapper( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response instanceof \WP_REST_Response
			? $response->get_data()
			: $response;

		// GF entries list wraps results in { total_count, entries }.
		if ( is_array( $data ) && isset( $data['entries'] ) ) {
			return $data['entries'];
		}

		return $data;
	}
}
