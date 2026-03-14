<?php
/**
 * Plugin Name: RestlessWP
 * Plugin URI:  https://github.com/sackclothlabs/restlesswp
 * Description: Exposes REST API endpoints for WordPress plugin configurations not natively accessible via the WP REST API.
 * Version:     0.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.9
 * Author:      Sackcloth Labs
 * Author URI:  https://sackclothlabs.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: restlesswp
 * Domain Path: /languages
 *
 * @package RestlessWP
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'RESTLESSWP_VERSION', '0.1.0' );
define( 'RESTLESSWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'RESTLESSWP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check minimum PHP version requirement.
 *
 * @return bool True if PHP version meets requirement.
 */
function restlesswp_check_php_version() {
	return version_compare( PHP_VERSION, '8.0', '>=' );
}

/**
 * Check minimum WordPress version requirement.
 *
 * @return bool True if WordPress version meets requirement.
 */
function restlesswp_check_wp_version() {
	global $wp_version;
	return version_compare( $wp_version, '6.9', '>=' );
}

/**
 * Display admin notice when requirements are not met.
 */
function restlesswp_requirements_notice() {
	$messages = array();

	if ( ! restlesswp_check_php_version() ) {
		$messages[] = sprintf(
			/* translators: 1: Required PHP version, 2: Current PHP version. */
			esc_html__( 'RestlessWP requires PHP %1$s or higher. You are running PHP %2$s.', 'restlesswp' ),
			'8.0',
			PHP_VERSION
		);
	}

	if ( ! restlesswp_check_wp_version() ) {
		global $wp_version;
		$messages[] = sprintf(
			/* translators: 1: Required WordPress version, 2: Current WordPress version. */
			esc_html__( 'RestlessWP requires WordPress %1$s or higher. You are running WordPress %2$s.', 'restlesswp' ),
			'6.9',
			$wp_version
		);
	}

	if ( empty( $messages ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo wp_kses(
		implode( '<br>', $messages ),
		array( 'br' => array() )
	);
	echo '</p></div>';
}

/**
 * Load core class files from the classes directory.
 */
function restlesswp_load_classes() {
	$classes = array(
		'class-response-formatter.php',
		'class-auth-handler.php',
		'class-backup-ring.php',
		'class-base-module.php',
		'class-base-controller.php',
		'class-ability-schema-builder.php',
		'class-ability-descriptor.php',
		'class-abilities-registrar.php',
		'class-module-registry.php',
		'class-source-detector.php',
	);

	foreach ( $classes as $class_file ) {
		$file_path = RESTLESSWP_PATH . 'classes/' . $class_file;
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}

/**
 * Initialize the plugin.
 */
function restlesswp_init() {
	restlesswp_load_classes();

	if ( class_exists( 'RestlessWP_Module_Registry' ) ) {
		$registry = new RestlessWP_Module_Registry();
		$registry->init();
	}
}

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function restlesswp_bootstrap() {
	if ( ! restlesswp_check_php_version() || ! restlesswp_check_wp_version() ) {
		add_action( 'admin_notices', 'restlesswp_requirements_notice' );
		return;
	}

	restlesswp_init();
}
add_action( 'plugins_loaded', 'restlesswp_bootstrap' );
