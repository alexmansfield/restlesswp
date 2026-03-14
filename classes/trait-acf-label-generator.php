<?php
/**
 * ACF Label Generator Trait — generates WordPress labels for ACF post types
 * and taxonomies.
 *
 * Builds the full labels array that ACF normally generates during its save
 * pipeline. Template map matches ACF's advanced-settings.php data attributes.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for generating post type and taxonomy label arrays from plural/singular names.
 */
trait RestlessWP_ACF_Label_Generator {

	/**
	 * Generates a complete post type labels array.
	 *
	 * @param string $plural    Plural label (e.g. "People").
	 * @param string $singular  Singular label (e.g. "Person").
	 * @param array  $overrides Optional user-provided label overrides.
	 * @return array Full labels array keyed by WordPress label slug.
	 */
	protected function generate_post_type_labels( string $plural, string $singular, array $overrides = array() ): array {
		$lower_plural   = strtolower( $plural );
		$lower_singular = strtolower( $singular );

		$labels = array(
			'name'                     => $plural,
			'singular_name'            => $singular,
			'menu_name'                => $plural,
			'all_items'                => sprintf( 'All %s', $plural ),
			'edit_item'                => sprintf( 'Edit %s', $singular ),
			'view_item'                => sprintf( 'View %s', $singular ),
			'view_items'               => sprintf( 'View %s', $plural ),
			'add_new_item'             => sprintf( 'Add New %s', $singular ),
			'add_new'                  => sprintf( 'Add New %s', $singular ),
			'new_item'                 => sprintf( 'New %s', $singular ),
			'parent_item_colon'        => sprintf( 'Parent %s:', $singular ),
			'search_items'             => sprintf( 'Search %s', $plural ),
			'not_found'                => sprintf( 'No %s found', $lower_plural ),
			'not_found_in_trash'       => sprintf( 'No %s found in Trash', $lower_plural ),
			'archives'                 => sprintf( '%s Archives', $singular ),
			'attributes'               => sprintf( '%s Attributes', $singular ),
			'insert_into_item'         => sprintf( 'Insert into %s', $lower_singular ),
			'uploaded_to_this_item'    => sprintf( 'Uploaded to this %s', $lower_singular ),
			'filter_items_list'        => sprintf( 'Filter %s list', $lower_plural ),
			'filter_by_date'           => sprintf( 'Filter %s by date', $lower_plural ),
			'items_list_navigation'    => sprintf( '%s list navigation', $plural ),
			'items_list'               => sprintf( '%s list', $plural ),
			'item_published'           => sprintf( '%s published.', $singular ),
			'item_published_privately' => sprintf( '%s published privately.', $singular ),
			'item_reverted_to_draft'   => sprintf( '%s reverted to draft.', $singular ),
			'item_scheduled'           => sprintf( '%s scheduled.', $singular ),
			'item_updated'             => sprintf( '%s updated.', $singular ),
			'item_link'                => sprintf( '%s Link', $singular ),
			'item_link_description'    => sprintf( 'A link to a %s.', $lower_singular ),
		);

		return array_merge( $labels, $overrides );
	}

	/**
	 * Generates a complete taxonomy labels array.
	 *
	 * @param string $plural    Plural label (e.g. "Genres").
	 * @param string $singular  Singular label (e.g. "Genre").
	 * @param array  $overrides Optional user-provided label overrides.
	 * @return array Full labels array keyed by WordPress label slug.
	 */
	protected function generate_taxonomy_labels( string $plural, string $singular, array $overrides = array() ): array {
		$lower_plural   = strtolower( $plural );
		$lower_singular = strtolower( $singular );

		$labels = array(
			'name'                       => $plural,
			'singular_name'              => $singular,
			'menu_name'                  => $plural,
			'all_items'                  => sprintf( 'All %s', $plural ),
			'edit_item'                  => sprintf( 'Edit %s', $singular ),
			'view_item'                  => sprintf( 'View %s', $singular ),
			'update_item'                => sprintf( 'Update %s', $singular ),
			'add_new_item'               => sprintf( 'Add New %s', $singular ),
			'new_item_name'              => sprintf( 'New %s Name', $singular ),
			'parent_item'                => sprintf( 'Parent %s', $singular ),
			'parent_item_colon'          => sprintf( 'Parent %s:', $singular ),
			'search_items'               => sprintf( 'Search %s', $plural ),
			'popular_items'              => sprintf( 'Popular %s', $plural ),
			'separate_items_with_commas' => sprintf( 'Separate %s with commas', $lower_plural ),
			'add_or_remove_items'        => sprintf( 'Add or remove %s', $lower_plural ),
			'choose_from_most_used'      => sprintf( 'Choose from the most used %s', $lower_plural ),
			'not_found'                  => sprintf( 'No %s found', $lower_plural ),
			'no_terms'                   => sprintf( 'No %s', $lower_plural ),
			'filter_by_item'             => sprintf( 'Filter by %s', $lower_singular ),
			'items_list_navigation'      => sprintf( '%s list navigation', $plural ),
			'items_list'                 => sprintf( '%s list', $plural ),
			'most_used'                  => 'Most Used',
			'back_to_items'              => sprintf( '&larr; Go to %s', $plural ),
			'item_link'                  => sprintf( '%s Link', $singular ),
			'item_link_description'      => sprintf( 'A link to a %s.', $lower_singular ),
		);

		return array_merge( $labels, $overrides );
	}
}
