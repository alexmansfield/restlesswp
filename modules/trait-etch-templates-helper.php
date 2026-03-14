<?php
/**
 * Etch Templates Helper Trait — centralizes wp_template CPT I/O
 * scoped to the active theme via the wp_theme taxonomy.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for Etch template operations.
 */
trait RestlessWP_Etch_Templates_Helper {

	/**
	 * Fetches all block templates for the active theme.
	 *
	 * Uses get_block_templates() which returns WP_Block_Template objects
	 * with source and has_theme_file metadata, covering both DB-stored
	 * and theme-file-only templates.
	 *
	 * @return \WP_Block_Template[] Array of block template objects.
	 */
	public function fetch_theme_templates(): array {
		$all = get_block_templates( array(), 'wp_template' );

		$theme = get_stylesheet();

		return array_filter(
			$all,
			function ( $template ) use ( $theme ) {
				return $template->theme === $theme;
			}
		);
	}

	/**
	 * Fetches a single wp_template post by ID, validating active-theme scope.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|null The post if valid and scoped to active theme, null otherwise.
	 */
	public function fetch_template_post( int $id ): ?\WP_Post {
		$post = get_post( $id );

		if ( ! $post || 'wp_template' !== $post->post_type ) {
			return null;
		}

		$theme_terms = wp_get_object_terms( $id, 'wp_theme', array( 'fields' => 'names' ) );

		if ( is_wp_error( $theme_terms ) || empty( $theme_terms ) ) {
			return null;
		}

		if ( get_stylesheet() !== $theme_terms[0] ) {
			return null;
		}

		return $post;
	}

	/**
	 * Sets the wp_theme taxonomy term for a template post to the active theme.
	 *
	 * @param int $post_id Post ID to tag.
	 * @return void
	 */
	public function ensure_theme_taxonomy( int $post_id ): void {
		wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme', false );
	}

	/**
	 * Checks if a template slug already exists for the active theme.
	 *
	 * @param string $slug Template slug to check.
	 * @return int|null Post ID if a duplicate exists, null otherwise.
	 */
	public function template_slug_exists_for_theme( string $slug ): ?int {
		$posts = get_posts( array(
			'post_type'   => 'wp_template',
			'post_status' => 'publish',
			'name'        => $slug,
			'numberposts' => 1,
			'tax_query'   => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				),
			),
		) );

		if ( empty( $posts ) ) {
			return null;
		}

		return $posts[0]->ID;
	}
}
