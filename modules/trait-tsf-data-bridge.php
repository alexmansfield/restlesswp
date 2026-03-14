<?php
/**
 * TSF Data Bridge Trait — wraps TSF Data classes behind clean aliases.
 *
 * Isolates all coupling to TSF's namespaced Data classes. Controllers
 * use this trait to read/write SEO data via friendly alias keys instead
 * of TSF's internal `_genesis_*` prefixed meta keys.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for TSF data operations with key aliasing.
 */
trait RestlessWP_TSF_Data_Bridge {

	/**
	 * Post meta: alias → TSF internal key.
	 *
	 * @var array<string, string>
	 */
	private static array $post_key_map = array(
		'title'               => '_genesis_title',
		'title_no_blogname'   => '_tsf_title_no_blogname',
		'description'         => '_genesis_description',
		'canonical_url'       => '_genesis_canonical_uri',
		'redirect_url'        => 'redirect',
		'social_image_url'    => '_social_image_url',
		'social_image_id'     => '_social_image_id',
		'noindex'             => '_genesis_noindex',
		'nofollow'            => '_genesis_nofollow',
		'noarchive'           => '_genesis_noarchive',
		'exclude_search'      => 'exclude_local_search',
		'exclude_archive'     => 'exclude_from_archive',
		'og_title'            => '_open_graph_title',
		'og_description'      => '_open_graph_description',
		'twitter_title'       => '_twitter_title',
		'twitter_description' => '_twitter_description',
		'twitter_card_type'   => '_tsf_twitter_card_type',
	);

	/**
	 * Term/PTA meta: alias → TSF internal key (only renames).
	 *
	 * @var array<string, string>
	 */
	private static array $term_key_map = array(
		'title'             => 'doctitle',
		'title_no_blogname' => 'title_no_blog_name',
	);

	// ─── Post Bridge ────────────────────────────────────────────────

	/**
	 * Retrieves aliased SEO data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Aliased SEO data.
	 */
	public function bridge_get_post_seo( int $post_id ): array {
		$raw = \The_SEO_Framework\Data\Plugin\Post::get_meta( $post_id );

		return $this->map_from_tsf( $raw, 'post' );
	}

	/**
	 * Saves aliased SEO data for a post via TSF pipeline.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Aliased SEO data.
	 * @return void
	 */
	public function bridge_save_post_seo( int $post_id, array $data ): void {
		$tsf_data = $this->map_to_tsf( $data, 'post' );
		\The_SEO_Framework\Data\Plugin\Post::save_meta( $post_id, $tsf_data );
	}

	/**
	 * Deletes all custom SEO meta for a post, reverting to defaults.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function bridge_delete_post_seo( int $post_id ): void {
		foreach ( self::$post_key_map as $tsf_key ) {
			delete_post_meta( $post_id, $tsf_key );
		}
	}

	// ─── Term Bridge ────────────────────────────────────────────────

	/**
	 * Retrieves aliased SEO data for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array Aliased SEO data.
	 */
	public function bridge_get_term_seo( int $term_id ): array {
		$raw = \The_SEO_Framework\Data\Plugin\Term::get_meta( $term_id );

		return $this->map_from_tsf( $raw, 'term' );
	}

	/**
	 * Saves aliased SEO data for a term via TSF pipeline.
	 *
	 * @param int   $term_id Term ID.
	 * @param array $data    Aliased SEO data.
	 * @return void
	 */
	public function bridge_save_term_seo( int $term_id, array $data ): void {
		$tsf_data = $this->map_to_tsf( $data, 'term' );
		\The_SEO_Framework\Data\Plugin\Term::save_meta( $term_id, $tsf_data );
	}

	/**
	 * Deletes all custom SEO meta for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function bridge_delete_term_seo( int $term_id ): void {
		\The_SEO_Framework\Data\Plugin\Term::delete_meta( $term_id );
	}

	// ─── Settings Bridge ────────────────────────────────────────────

	/**
	 * Retrieves all TSF settings, excluding PTA nested data.
	 *
	 * @return array Flat key-value settings.
	 */
	public function bridge_get_settings(): array {
		$options = \The_SEO_Framework\Data\Plugin::get_options();
		unset( $options['pta'] );

		return $options;
	}

	/**
	 * Retrieves a single TSF setting by key.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null Setting value or null if not found.
	 */
	public function bridge_get_setting( string $key ) {
		return \The_SEO_Framework\Data\Plugin::get_option( $key );
	}

	/**
	 * Updates a single TSF setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return bool True on success.
	 */
	public function bridge_update_setting( string $key, $value ): bool {
		return \The_SEO_Framework\Data\Plugin::update_option( $key, $value );
	}

	// ─── PTA Bridge ─────────────────────────────────────────────────

	/**
	 * Retrieves aliased SEO data for a post type archive.
	 *
	 * @param string $post_type Post type slug.
	 * @return array Aliased SEO data.
	 */
	public function bridge_get_pta_seo( string $post_type ): array {
		$raw = \The_SEO_Framework\Data\Plugin\PTA::get_meta( $post_type );

		return $this->map_from_tsf( $raw, 'term' );
	}

	/**
	 * Saves aliased SEO data for a post type archive.
	 *
	 * @param string $post_type Post type slug.
	 * @param array  $data      Aliased SEO data.
	 * @return void
	 */
	public function bridge_save_pta_seo( string $post_type, array $data ): void {
		$tsf_data = $this->map_to_tsf( $data, 'term' );
		$all_pta  = \The_SEO_Framework\Data\Plugin::get_option( 'pta' ) ?: array();

		$existing                = $all_pta[ $post_type ] ?? array();
		$all_pta[ $post_type ]   = array_merge( $existing, $tsf_data );

		\The_SEO_Framework\Data\Plugin::update_option( 'pta', $all_pta );
	}

	// ─── User Bridge ────────────────────────────────────────────────

	/**
	 * Retrieves SEO data for a user (keys already clean).
	 *
	 * @param int $user_id User ID.
	 * @return array User SEO data.
	 */
	public function bridge_get_user_seo( int $user_id ): array {
		return \The_SEO_Framework\Data\Plugin\User::get_meta( $user_id );
	}

	/**
	 * Saves SEO data for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    User SEO data.
	 * @return void
	 */
	public function bridge_save_user_seo( int $user_id, array $data ): void {
		\The_SEO_Framework\Data\Plugin\User::save_meta( $user_id, $data );
	}

	/**
	 * Deletes all custom SEO meta for a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function bridge_delete_user_seo( int $user_id ): void {
		\The_SEO_Framework\Data\Plugin\User::delete_meta( $user_id );
	}

	// ─── Mapping Helpers ────────────────────────────────────────────

	/**
	 * Maps TSF internal keys to clean aliases.
	 *
	 * @param array  $raw    Raw TSF data.
	 * @param string $entity Entity type: 'post' or 'term'.
	 * @return array Aliased data.
	 */
	private function map_from_tsf( array $raw, string $entity ): array {
		$map = $this->get_key_map( $entity );

		if ( empty( $map ) ) {
			return $raw;
		}

		$flipped = array_flip( $map );
		$result  = array();

		foreach ( $raw as $tsf_key => $value ) {
			$alias            = $flipped[ $tsf_key ] ?? $tsf_key;
			$result[ $alias ] = $value;
		}

		return $result;
	}

	/**
	 * Maps clean aliases to TSF internal keys.
	 *
	 * @param array  $data   Aliased data.
	 * @param string $entity Entity type: 'post' or 'term'.
	 * @return array TSF-keyed data.
	 */
	private function map_to_tsf( array $data, string $entity ): array {
		$map = $this->get_key_map( $entity );

		if ( empty( $map ) ) {
			return $data;
		}

		$result = array();

		foreach ( $data as $alias => $value ) {
			$tsf_key            = $map[ $alias ] ?? $alias;
			$result[ $tsf_key ] = $value;
		}

		return $result;
	}

	/**
	 * Returns the key map for the given entity type.
	 *
	 * @param string $entity Entity type.
	 * @return array<string, string> Alias → TSF key map.
	 */
	private function get_key_map( string $entity ): array {
		if ( 'post' === $entity ) {
			return self::$post_key_map;
		}

		if ( 'term' === $entity ) {
			return self::$term_key_map;
		}

		return array();
	}

	// ─── Validation Helpers ─────────────────────────────────────────

	/**
	 * Validates that a post exists.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_post_exists( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Post not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Validates that a term exists.
	 *
	 * @param int $term_id Term ID.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_term_exists( int $term_id ) {
		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Term not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Validates that a user exists.
	 *
	 * @param int $user_id User ID.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_user_exists( int $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new \WP_Error(
				'not_found',
				__( 'User not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Validates that a post type exists and has an archive.
	 *
	 * @param string $post_type Post type slug.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_post_type( string $post_type ) {
		$obj = get_post_type_object( $post_type );

		if ( ! $obj || ! $obj->public ) {
			return new \WP_Error(
				'not_found',
				__( 'Post type not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}
}
