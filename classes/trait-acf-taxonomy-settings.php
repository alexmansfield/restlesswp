<?php
/**
 * ACF Taxonomy Settings Trait — centralizes extended taxonomy properties.
 *
 * Defines the full set of register_taxonomy() arguments that ACF supports,
 * providing schema definitions, request-to-ACF mapping, and response
 * formatting from both ACF config arrays and WP_Taxonomy objects.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for extended ACF taxonomy settings.
 */
trait RestlessWP_ACF_Taxonomy_Settings {

	/**
	 * Returns schema properties for all extended taxonomy settings.
	 *
	 * @return array JSON Schema properties array.
	 */
	protected function get_taxonomy_settings_schema(): array {
		$schema = array();

		foreach ( $this->get_taxonomy_settings_map() as $key => $def ) {
			$prop = array(
				'description' => $def['description'],
				'type'        => $def['type'],
			);

			if ( isset( $def['items'] ) ) {
				$prop['items'] = $def['items'];
			}

			if ( isset( $def['properties'] ) ) {
				$prop['properties'] = $def['properties'];
			}

			$schema[ $key ] = $prop;
		}

		return $schema;
	}

	/**
	 * Maps request data to ACF settings for extended taxonomy properties.
	 *
	 * @param array $data Incoming request data.
	 * @return array ACF settings key-value pairs.
	 */
	protected function map_taxonomy_request_to_acf_settings( array $data ): array {
		$settings = array();

		foreach ( $this->get_taxonomy_settings_map() as $key => $def ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			if ( isset( $def['to_acf'] ) ) {
				$converted = call_user_func( $def['to_acf'], $data[ $key ] );
				if ( ! empty( $def['flatten'] ) && is_array( $converted ) ) {
					$settings = array_merge( $settings, $converted );
				} else {
					$acf_key              = $def['acf_key'] ?? $key;
					$settings[ $acf_key ] = $converted;
				}
			} else {
				$acf_key              = $def['acf_key'] ?? $key;
				$settings[ $acf_key ] = $data[ $key ];
			}
		}

		return $settings;
	}

	/**
	 * Maps ACF serialized config to response fields for extended properties.
	 *
	 * @param array $config ACF taxonomy config array.
	 * @return array Response key-value pairs.
	 */
	protected function map_taxonomy_acf_config_to_response( array $config ): array {
		$result = array();

		foreach ( $this->get_taxonomy_settings_map() as $key => $def ) {
			$acf_key = $def['acf_key'] ?? $key;

			if ( ! array_key_exists( $acf_key, $config ) ) {
				continue;
			}

			if ( isset( $def['from_acf'] ) ) {
				$result[ $key ] = call_user_func( $def['from_acf'], $config[ $acf_key ], $config );
			} else {
				$result[ $key ] = $this->cast_taxonomy_value( $config[ $acf_key ], $def['type'] );
			}
		}

		return $result;
	}

	/**
	 * Maps a WP_Taxonomy object to response fields for extended properties.
	 *
	 * @param WP_Taxonomy $tax WordPress taxonomy object.
	 * @return array Response key-value pairs.
	 */
	protected function map_wp_taxonomy_to_response( WP_Taxonomy $tax ): array {
		$result = array();

		foreach ( $this->get_taxonomy_settings_map() as $key => $def ) {
			$wp_prop = $def['wp_prop'] ?? $key;

			if ( isset( $def['from_wp'] ) ) {
				$result[ $key ] = call_user_func( $def['from_wp'], $tax );
				continue;
			}

			if ( ! property_exists( $tax, $wp_prop ) ) {
				continue;
			}

			$result[ $key ] = $this->cast_taxonomy_value( $tax->$wp_prop, $def['type'] );
		}

		return $result;
	}

	/**
	 * Returns the central taxonomy settings map.
	 *
	 * Each entry defines: type, description, acf_key (if different from
	 * response key), wp_prop (if different), and optional transform callbacks.
	 *
	 * @return array Settings definitions.
	 */
	private function get_taxonomy_settings_map(): array {
		return array(
			'publicly_queryable'    => array(
				'type'        => 'boolean',
				'description' => __( 'Whether queries can be performed on the front end.', 'restlesswp' ),
			),
			'show_ui'               => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to generate admin UI.', 'restlesswp' ),
			),
			'show_in_menu'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to show in the admin menu.', 'restlesswp' ),
			),
			'show_in_nav_menus'     => array(
				'type'        => 'boolean',
				'description' => __( 'Whether available in navigation menus.', 'restlesswp' ),
			),
			'show_tagcloud'         => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to show in the tag cloud widget.', 'restlesswp' ),
			),
			'show_in_quick_edit'    => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to show in quick edit.', 'restlesswp' ),
			),
			'show_admin_column'     => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to show an admin column.', 'restlesswp' ),
			),
			'show_in_rest'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to expose in the WP REST API.', 'restlesswp' ),
			),
			'rest_base'             => array(
				'type'        => 'string',
				'description' => __( 'Custom REST API base slug.', 'restlesswp' ),
			),
			'rest_namespace'        => array(
				'type'        => 'string',
				'description' => __( 'REST API namespace.', 'restlesswp' ),
			),
			'rest_controller_class' => array(
				'type'        => 'string',
				'description' => __( 'REST controller class name.', 'restlesswp' ),
			),
			'rewrite'               => array(
				'type'        => 'object',
				'description' => __( 'Permalink rewrite rules.', 'restlesswp' ),
				'properties'  => array(
					'slug'         => array( 'type' => 'string' ),
					'with_front'   => array( 'type' => 'boolean' ),
					'hierarchical' => array( 'type' => 'boolean' ),
				),
				'acf_key'     => 'permalink_rewrite',
				'flatten'     => true,
				'to_acf'      => array( $this, 'taxonomy_rewrite_to_acf' ),
				'from_acf'    => array( $this, 'taxonomy_rewrite_from_acf' ),
				'from_wp'     => array( $this, 'taxonomy_rewrite_from_wp' ),
			),
			'query_var'             => array(
				'type'        => 'string',
				'description' => __( 'Query var behavior.', 'restlesswp' ),
				'from_wp'     => array( $this, 'taxonomy_query_var_from_wp' ),
			),
			'default_term'          => array(
				'type'        => 'object',
				'description' => __( 'Default term settings.', 'restlesswp' ),
				'properties'  => array(
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
				'acf_key'     => 'default_term_enabled',
				'flatten'     => true,
				'to_acf'      => array( $this, 'taxonomy_default_term_to_acf' ),
				'from_acf'    => array( $this, 'taxonomy_default_term_from_acf' ),
				'from_wp'     => array( $this, 'taxonomy_default_term_from_wp' ),
			),
			'sort'                  => array(
				'type'        => 'boolean',
				'description' => __( 'Whether terms should be sorted.', 'restlesswp' ),
			),
			'capabilities'          => array(
				'type'        => 'object',
				'description' => __( 'Capability overrides.', 'restlesswp' ),
				'properties'  => array(
					'manage_terms' => array( 'type' => 'string' ),
					'edit_terms'   => array( 'type' => 'string' ),
					'delete_terms' => array( 'type' => 'string' ),
					'assign_terms' => array( 'type' => 'string' ),
				),
				'from_wp'     => array( $this, 'taxonomy_capabilities_from_wp' ),
			),
		);
	}

	/**
	 * Casts a value to the expected type.
	 *
	 * @param mixed        $value Raw value.
	 * @param string|array $type  Expected JSON Schema type.
	 * @return mixed Cast value.
	 */
	private function cast_taxonomy_value( $value, $type ): mixed {
		if ( is_array( $type ) ) {
			if ( null === $value && in_array( 'null', $type, true ) ) {
				return null;
			}
			$type = $type[0];
		}

		return match ( $type ) {
			'boolean' => (bool) $value,
			'integer' => null === $value ? null : (int) $value,
			'array'   => (array) $value,
			'object'  => (array) $value,
			default   => (string) $value,
		};
	}

	/**
	 * Converts rewrite request data to ACF format for taxonomies.
	 *
	 * @param array $rewrite Rewrite settings from request.
	 * @return array ACF rewrite settings.
	 */
	protected function taxonomy_rewrite_to_acf( array $rewrite ): array {
		$acf = array(
			'permalink_rewrite' => 'taxonomy_key',
		);

		if ( isset( $rewrite['slug'] ) ) {
			$acf['permalink_rewrite'] = 'custom_permalink';
			$acf['slug']              = $rewrite['slug'];
		}

		if ( isset( $rewrite['with_front'] ) ) {
			$acf['with_front'] = $rewrite['with_front'];
		}

		if ( isset( $rewrite['hierarchical'] ) ) {
			$acf['rewrite_hierarchical'] = $rewrite['hierarchical'];
		}

		return $acf;
	}

	/**
	 * Converts ACF rewrite config to response format for taxonomies.
	 *
	 * @param mixed $value  ACF permalink_rewrite value.
	 * @param array $config Full ACF config.
	 * @return array Rewrite response.
	 */
	protected function taxonomy_rewrite_from_acf( $value, array $config ): array {
		return array(
			'slug'         => $config['slug'] ?? '',
			'with_front'   => (bool) ( $config['with_front'] ?? true ),
			'hierarchical' => (bool) ( $config['rewrite_hierarchical'] ?? false ),
		);
	}

	/**
	 * Reads rewrite settings from a WP_Taxonomy object.
	 *
	 * @param WP_Taxonomy $tax Taxonomy object.
	 * @return array|false Rewrite array or false.
	 */
	protected function taxonomy_rewrite_from_wp( WP_Taxonomy $tax ): array|false {
		if ( false === $tax->rewrite ) {
			return false;
		}

		$rewrite = (array) $tax->rewrite;

		return array(
			'slug'         => $rewrite['slug'] ?? $tax->name,
			'with_front'   => (bool) ( $rewrite['with_front'] ?? true ),
			'hierarchical' => (bool) ( $rewrite['hierarchical'] ?? false ),
		);
	}

	/**
	 * Reads query_var from a WP_Taxonomy object.
	 *
	 * @param WP_Taxonomy $tax Taxonomy object.
	 * @return string Query var string.
	 */
	protected function taxonomy_query_var_from_wp( WP_Taxonomy $tax ): string {
		if ( false === $tax->query_var ) {
			return '';
		}

		return (string) $tax->query_var;
	}

	/**
	 * Reads capabilities from a WP_Taxonomy object.
	 *
	 * @param WP_Taxonomy $tax Taxonomy object.
	 * @return array Capabilities array.
	 */
	protected function taxonomy_capabilities_from_wp( WP_Taxonomy $tax ): array {
		$cap = (array) $tax->cap;

		return array(
			'manage_terms' => $cap['manage_terms'] ?? '',
			'edit_terms'   => $cap['edit_terms'] ?? '',
			'delete_terms' => $cap['delete_terms'] ?? '',
			'assign_terms' => $cap['assign_terms'] ?? '',
		);
	}

	/**
	 * Converts default_term request data to ACF format.
	 *
	 * @param array $term Default term settings from request.
	 * @return array ACF default term settings.
	 */
	protected function taxonomy_default_term_to_acf( ?array $term ): array {
		if ( empty( $term ) ) {
			return array( 'default_term_enabled' => false );
		}

		return array(
			'default_term_enabled'     => true,
			'default_term_name'        => $term['name'] ?? '',
			'default_term_slug'        => $term['slug'] ?? '',
			'default_term_description' => $term['description'] ?? '',
		);
	}

	/**
	 * Converts ACF default_term config to response format.
	 *
	 * @param mixed $value  ACF default_term_enabled value.
	 * @param array $config Full ACF config.
	 * @return array|null Default term response or null if disabled.
	 */
	protected function taxonomy_default_term_from_acf( $value, array $config ): ?array {
		if ( empty( $value ) ) {
			return null;
		}

		return array(
			'name'        => $config['default_term_name'] ?? '',
			'slug'        => $config['default_term_slug'] ?? '',
			'description' => $config['default_term_description'] ?? '',
		);
	}

	/**
	 * Reads default_term from a WP_Taxonomy object.
	 *
	 * @param WP_Taxonomy $tax Taxonomy object.
	 * @return array|null Default term settings or null.
	 */
	protected function taxonomy_default_term_from_wp( WP_Taxonomy $tax ): ?array {
		if ( empty( $tax->default_term ) ) {
			return null;
		}

		$term = (array) $tax->default_term;

		return array(
			'name'        => $term['name'] ?? '',
			'slug'        => $term['slug'] ?? '',
			'description' => $term['description'] ?? '',
		);
	}
}
