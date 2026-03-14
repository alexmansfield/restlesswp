<?php
/**
 * Source Detector — determines which plugin or system registered a post type or taxonomy.
 *
 * Shared logic used by both the post types and taxonomies controllers.
 * Detection priority:
 *   1. ACF record exists → "acf"
 *   2. Known plugin signature in active_plugins → plugin slug
 *   3. Core built-in type → "core"
 *   4. Otherwise → "unknown"
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the source of registered post types and taxonomies.
 */
class RestlessWP_Source_Detector {

	/**
	 * Core post types built into WordPress.
	 *
	 * @var string[]
	 */
	private const CORE_POST_TYPES = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Core taxonomies built into WordPress.
	 *
	 * @var string[]
	 */
	private const CORE_TAXONOMIES = array(
		'category',
		'post_tag',
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_template_part_area',
		'wp_pattern_category',
	);

	/**
	 * Known plugin file signatures mapped to post type slugs.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private const PLUGIN_POST_TYPE_MAP = array(
		'woocommerce/woocommerce.php'                       => array(
			'name'  => 'woocommerce',
			'types' => array(
				'product',
				'product_variation',
				'shop_order',
				'shop_order_refund',
				'shop_coupon',
			),
		),
		'buddypress/bp-loader.php'                          => array(
			'name'  => 'buddypress',
			'types' => array(
				'bp-email',
				'bp-activity',
			),
		),
		'bbpress/bbpress.php'                               => array(
			'name'  => 'bbpress',
			'types' => array(
				'forum',
				'topic',
				'reply',
			),
		),
		'developer-tools-for-wordpress/developer-tools.php' => array(
			'name'  => 'developer-tools',
			'types' => array(),
		),
	);

	/**
	 * Known plugin file signatures mapped to taxonomy slugs.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private const PLUGIN_TAXONOMY_MAP = array(
		'woocommerce/woocommerce.php'                       => array(
			'name'       => 'woocommerce',
			'taxonomies' => array(
				'product_cat',
				'product_tag',
				'product_type',
				'product_visibility',
				'product_shipping_class',
			),
		),
		'buddypress/bp-loader.php'                          => array(
			'name'       => 'buddypress',
			'taxonomies' => array(
				'bp_member_type',
				'bp_group_type',
			),
		),
		'bbpress/bbpress.php'                               => array(
			'name'       => 'bbpress',
			'taxonomies' => array(
				'topic-tag',
			),
		),
		'developer-tools-for-wordpress/developer-tools.php' => array(
			'name'       => 'developer-tools',
			'taxonomies' => array(),
		),
	);

	/**
	 * Cached list of active plugin file paths.
	 *
	 * @var string[]|null
	 */
	private ?array $active_plugins = null;

	/**
	 * Detects the source of a post type.
	 *
	 * @param string   $slug     Post type slug.
	 * @param string[] $acf_keys ACF-managed post type slugs.
	 * @return string Source identifier.
	 */
	public function detect_post_type_source( string $slug, array $acf_keys ): string {
		if ( in_array( $slug, $acf_keys, true ) ) {
			return 'acf';
		}

		$plugin_source = $this->find_plugin_source( $slug, self::PLUGIN_POST_TYPE_MAP, 'types' );
		if ( null !== $plugin_source ) {
			return $plugin_source;
		}

		if ( in_array( $slug, self::CORE_POST_TYPES, true ) ) {
			return 'core';
		}

		return 'unknown';
	}

	/**
	 * Detects the source of a taxonomy.
	 *
	 * @param string   $slug     Taxonomy slug.
	 * @param string[] $acf_keys ACF-managed taxonomy slugs.
	 * @return string Source identifier.
	 */
	public function detect_taxonomy_source( string $slug, array $acf_keys ): string {
		if ( in_array( $slug, $acf_keys, true ) ) {
			return 'acf';
		}

		$plugin_source = $this->find_plugin_source( $slug, self::PLUGIN_TAXONOMY_MAP, 'taxonomies' );
		if ( null !== $plugin_source ) {
			return $plugin_source;
		}

		if ( in_array( $slug, self::CORE_TAXONOMIES, true ) ) {
			return 'core';
		}

		return 'unknown';
	}

	/**
	 * Searches known plugin maps for a slug match, verifying the plugin is active.
	 *
	 * @param string $slug      The slug to search for.
	 * @param array  $plugin_map Plugin map constant (keyed by plugin file).
	 * @param string $list_key  Key within the map entry holding the slug list.
	 * @return string|null Plugin name or null if not found.
	 */
	private function find_plugin_source( string $slug, array $plugin_map, string $list_key ): ?string {
		$active = $this->get_active_plugins();

		foreach ( $plugin_map as $plugin_file => $info ) {
			if ( ! in_array( $slug, $info[ $list_key ], true ) ) {
				continue;
			}
			if ( in_array( $plugin_file, $active, true ) ) {
				return $info['name'];
			}
		}

		return null;
	}

	/**
	 * Returns the list of active plugin file paths, cached per request.
	 *
	 * @return string[] Active plugin file paths.
	 */
	private function get_active_plugins(): array {
		if ( null === $this->active_plugins ) {
			$this->active_plugins = (array) get_option( 'active_plugins', array() );
		}

		return $this->active_plugins;
	}

	/**
	 * Returns the list of core post type slugs.
	 *
	 * @return string[]
	 */
	public function get_core_post_types(): array {
		return self::CORE_POST_TYPES;
	}

	/**
	 * Returns the list of core taxonomy slugs.
	 *
	 * @return string[]
	 */
	public function get_core_taxonomies(): array {
		return self::CORE_TAXONOMIES;
	}
}
