<?php
/**
 * ACF Post Type Settings Trait — centralizes extended post type properties.
 *
 * Defines the full set of register_post_type() arguments that ACF supports,
 * providing schema definitions, request-to-ACF mapping, and response
 * formatting from both ACF config arrays and WP_Post_Type objects.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for extended ACF post type settings.
 */
trait RestlessWP_ACF_Post_Type_Settings {

	/**
	 * Returns schema properties for all extended settings.
	 *
	 * @return array JSON Schema properties array.
	 */
	protected function get_post_type_settings_schema(): array {
		$schema = array();

		foreach ( $this->get_settings_map() as $key => $def ) {
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
	 * Maps request data to ACF settings for extended properties.
	 *
	 * @param array $data Incoming request data.
	 * @return array ACF settings key-value pairs.
	 */
	protected function map_request_to_acf_settings( array $data ): array {
		$settings = array();

		foreach ( $this->get_settings_map() as $key => $def ) {
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
	 * @param array $config ACF post type config array.
	 * @return array Response key-value pairs.
	 */
	protected function map_acf_config_to_response( array $config ): array {
		$result = array();

		foreach ( $this->get_settings_map() as $key => $def ) {
			$acf_key = $def['acf_key'] ?? $key;

			if ( ! array_key_exists( $acf_key, $config ) ) {
				continue;
			}

			if ( isset( $def['from_acf'] ) ) {
				$result[ $key ] = call_user_func( $def['from_acf'], $config[ $acf_key ], $config );
			} else {
				$result[ $key ] = $this->cast_value( $config[ $acf_key ], $def['type'] );
			}
		}

		return $result;
	}

	/**
	 * Maps a WP_Post_Type object to response fields for extended properties.
	 *
	 * @param WP_Post_Type $pt WordPress post type object.
	 * @return array Response key-value pairs.
	 */
	protected function map_wp_post_type_to_response( WP_Post_Type $pt ): array {
		$result = array();

		foreach ( $this->get_settings_map() as $key => $def ) {
			$wp_prop = $def['wp_prop'] ?? $key;

			if ( isset( $def['from_wp'] ) ) {
				$result[ $key ] = call_user_func( $def['from_wp'], $pt );
				continue;
			}

			if ( ! property_exists( $pt, $wp_prop ) ) {
				continue;
			}

			$result[ $key ] = $this->cast_value( $pt->$wp_prop, $def['type'] );
		}

		return $result;
	}

	/**
	 * Returns the central settings map.
	 *
	 * Each entry defines: type, description, acf_key (if different from
	 * response key), wp_prop (if different), and optional transform callbacks.
	 *
	 * @return array Settings definitions.
	 */
	private function get_settings_map(): array {
		return array(
			'menu_icon'             => array(
				'type'        => 'string',
				'description' => __( 'Dashicon class or image URL for admin menu.', 'restlesswp' ),
			),
			'menu_position'         => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'Position in the admin menu.', 'restlesswp' ),
			),
			'supports'              => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Editor features the post type supports.', 'restlesswp' ),
				'from_wp'     => array( $this, 'get_wp_supports' ),
			),
			'taxonomies'            => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Taxonomies associated with the post type.', 'restlesswp' ),
				'from_wp'     => array( $this, 'get_wp_taxonomies' ),
			),
			'has_archive'           => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the post type has an archive page.', 'restlesswp' ),
			),
			'rewrite'               => array(
				'type'        => 'object',
				'description' => __( 'Permalink rewrite rules.', 'restlesswp' ),
				'properties'  => array(
					'slug'       => array( 'type' => 'string' ),
					'feeds'      => array( 'type' => 'boolean' ),
					'pages'      => array( 'type' => 'boolean' ),
					'with_front' => array( 'type' => 'boolean' ),
				),
				'acf_key'    => 'permalink_rewrite',
				'flatten'    => true,
				'to_acf'     => array( $this, 'rewrite_to_acf' ),
				'from_acf'   => array( $this, 'rewrite_from_acf' ),
				'from_wp'    => array( $this, 'rewrite_from_wp' ),
			),
			'query_var'             => array(
				'type'        => 'string',
				'description' => __( 'Query var behavior.', 'restlesswp' ),
				'acf_key'     => 'query_var',
				'from_wp'     => array( $this, 'query_var_from_wp' ),
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
			'show_in_admin_bar'     => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to show in the admin bar.', 'restlesswp' ),
			),
			'show_in_nav_menus'     => array(
				'type'        => 'boolean',
				'description' => __( 'Whether available in navigation menus.', 'restlesswp' ),
			),
			'exclude_from_search'   => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to exclude from front-end search.', 'restlesswp' ),
			),
			'can_export'            => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the post type can be exported.', 'restlesswp' ),
			),
			'delete_with_user'      => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to delete posts when their author is deleted.', 'restlesswp' ),
			),
			'enter_title_here'      => array(
				'type'        => 'string',
				'description' => __( 'Placeholder text for the title field.', 'restlesswp' ),
				'wp_prop'     => '_edit_link',
				'from_wp'     => array( $this, 'enter_title_from_wp' ),
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
	private function cast_value( $value, $type ): mixed {
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
	 * Gets supported features for a WP post type.
	 *
	 * @param WP_Post_Type $pt Post type object.
	 * @return array Feature support keys.
	 */
	protected function get_wp_supports( WP_Post_Type $pt ): array {
		$all = get_all_post_type_supports( $pt->name );

		return array_keys( array_filter( $all ) );
	}

	/**
	 * Gets taxonomies registered for a WP post type.
	 *
	 * @param WP_Post_Type $pt Post type object.
	 * @return array Taxonomy slugs.
	 */
	protected function get_wp_taxonomies( WP_Post_Type $pt ): array {
		return array_values( get_object_taxonomies( $pt->name ) );
	}

	/**
	 * Converts rewrite request data to ACF format.
	 *
	 * @param array $rewrite Rewrite settings from request.
	 * @return array ACF rewrite settings.
	 */
	protected function rewrite_to_acf( array $rewrite ): array {
		$acf = array();

		if ( isset( $rewrite['slug'] ) ) {
			$acf['permalink_rewrite'] = 'custom';
			$acf['slug']              = $rewrite['slug'];
		}

		if ( isset( $rewrite['feeds'] ) ) {
			$acf['feeds'] = $rewrite['feeds'];
		}

		if ( isset( $rewrite['pages'] ) ) {
			$acf['pages'] = $rewrite['pages'];
		}

		if ( isset( $rewrite['with_front'] ) ) {
			$acf['with_front'] = $rewrite['with_front'];
		}

		return $acf;
	}

	/**
	 * Converts ACF rewrite config to response format.
	 *
	 * @param mixed $value  ACF rewrite value.
	 * @param array $config Full ACF config.
	 * @return array|null Rewrite response or null.
	 */
	protected function rewrite_from_acf( $value, array $config ): ?array {
		return array(
			'slug'       => $config['slug'] ?? '',
			'feeds'      => (bool) ( $config['feeds'] ?? false ),
			'pages'      => (bool) ( $config['pages'] ?? true ),
			'with_front' => (bool) ( $config['with_front'] ?? true ),
		);
	}

	/**
	 * Reads rewrite settings from a WP_Post_Type object.
	 *
	 * @param WP_Post_Type $pt Post type object.
	 * @return array|false Rewrite array or false.
	 */
	protected function rewrite_from_wp( WP_Post_Type $pt ): array|false {
		if ( false === $pt->rewrite ) {
			return false;
		}

		$rewrite = (array) $pt->rewrite;

		return array(
			'slug'       => $rewrite['slug'] ?? $pt->name,
			'feeds'      => (bool) ( $rewrite['feeds'] ?? false ),
			'pages'      => (bool) ( $rewrite['pages'] ?? true ),
			'with_front' => (bool) ( $rewrite['with_front'] ?? true ),
		);
	}

	/**
	 * Reads query_var from a WP_Post_Type object.
	 *
	 * @param WP_Post_Type $pt Post type object.
	 * @return string Query var string.
	 */
	protected function query_var_from_wp( WP_Post_Type $pt ): string {
		if ( false === $pt->query_var ) {
			return '';
		}

		return (string) $pt->query_var;
	}

	/**
	 * Reads enter_title_here — only available in ACF config, not WP_Post_Type.
	 *
	 * @param WP_Post_Type $pt Post type object.
	 * @return string Empty string (not available from WP object).
	 */
	protected function enter_title_from_wp( WP_Post_Type $pt ): string {
		return '';
	}
}
