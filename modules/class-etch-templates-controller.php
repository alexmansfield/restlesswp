<?php
/**
 * Etch Templates Controller — REST endpoints for block templates.
 *
 * Provides CRUD on the wp_template CPT scoped to the active theme
 * via the wp_theme taxonomy. Uses get_block_templates() for list
 * to include both DB-stored and theme-file-only templates.
 *
 * Accepts either raw HTML via `content` or structured block arrays
 * via `blocks` (with auto-conversion from docs format via
 * RestlessWP_Etch_Block_Converter). The `blocks` field takes
 * precedence when both are provided.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-etch-templates-helper.php';
require_once __DIR__ . '/class-etch-normalizer.php';

/**
 * REST controller for Etch block templates.
 */
class RestlessWP_Etch_Templates_Controller extends RestlessWP_Base_Controller {

	use RestlessWP_Etch_Templates_Helper;

	/**
	 * Returns the route base for template endpoints.
	 *
	 * @return string
	 */
	protected function get_route_base(): string {
		return 'etch/templates';
	}

	/**
	 * Returns the capability required for read operations.
	 *
	 * @return string
	 */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Returns the capability required for write operations.
	 *
	 * @return string
	 */
	protected function get_write_capability(): string {
		return 'manage_options';
	}

	/**
	 * Returns the list of supported operations for ability registration.
	 *
	 * @return string[] Array of operation names.
	 */
	public function get_supported_operations(): array {
		return array( 'list', 'get', 'create', 'update', 'delete' );
	}

	/**
	 * Returns workflow-aware ability descriptions for agents.
	 *
	 * @return array<string, string> Operation name => description.
	 */
	public function get_ability_descriptions(): array {
		return array(
			'list'   => 'List all block templates for the active theme. Includes theme-file, plugin, and custom (DB-stored) templates. Use source filter to narrow results.',
			'get'    => 'Get a single block template by post ID. Returns content as both raw markup and parsed block tree.',
			'create' => 'Create a new block template scoped to the active theme. Accepts content as raw HTML string (content field) or structured block array (blocks field — auto-converts docs format). blocks takes precedence when both provided.',
			'update' => 'Update an existing block template by post ID. Accepts content as raw HTML string or block array.',
			'delete' => 'Delete a block template by post ID. Only works on custom (DB-stored) templates.',
		);
	}

	/**
	 * Returns additional query parameters for collection requests.
	 *
	 * @return array Array of query parameter definitions.
	 */
	protected function get_collection_params(): array {
		return array(
			'source' => array(
				'description'       => __( 'Filter by template source.', 'restlesswp' ),
				'type'              => 'string',
				'enum'              => array( 'theme', 'plugin', 'custom' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Retrieves all templates for the active theme.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return array Array of formatted template list items.
	 */
	protected function get_items( WP_REST_Request $request ) {
		$templates = $this->fetch_theme_templates();
		$source    = $request->get_param( 'source' );
		$result    = array();

		foreach ( $templates as $template ) {
			if ( $source && $template->source !== $source ) {
				continue;
			}

			$result[] = $this->format_list_item( $template );
		}

		return $result;
	}

	/**
	 * Retrieves a single template by post ID.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Template data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post = $this->fetch_template_post( (int) $key );

		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Template not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_full_item( $post );
	}

	/**
	 * Creates a new block template.
	 *
	 * @param array           $data    Validated item data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Created template data or WP_Error on failure.
	 */
	protected function create_item( array $data, WP_REST_Request $request ) {
		if ( empty( $data['title'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'The title field is required.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$slug    = ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $data['title'] );
		$content = $this->resolve_content( $data );

		$post_id = wp_insert_post( array(
			'post_type'    => 'wp_template',
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'publish',
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->ensure_theme_taxonomy( $post_id );

		return $this->format_full_item( get_post( $post_id ) );
	}

	/**
	 * Updates an existing block template.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param array           $data    Validated partial data (already merged).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated template data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$post = $this->fetch_template_post( (int) $key );

		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Template not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$incoming    = $request->get_json_params();
		$update_args = array( 'ID' => $post->ID );

		if ( isset( $data['title'] ) ) {
			$update_args['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $incoming['blocks'] ) || isset( $incoming['content'] ) ) {
			$update_args['post_content'] = $this->resolve_content( $incoming );
		}

		if ( isset( $data['slug'] ) ) {
			$update_args['post_name'] = sanitize_title( $data['slug'] );
		}

		$result = wp_update_post( $update_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_full_item( get_post( $post->ID ) );
	}

	/**
	 * Deletes a block template.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Deletion result or WP_Error on failure.
	 */
	protected function delete_item( string $key, WP_REST_Request $request ) {
		$post = $this->fetch_template_post( (int) $key );

		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Template not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		wp_delete_post( $post->ID, true );

		return array(
			'deleted' => true,
			'id'      => $post->ID,
		);
	}

	/**
	 * Checks for an existing template with the same slug in the active theme.
	 *
	 * @param array           $data    Incoming create data.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|null Existing template data if found, null otherwise.
	 */
	protected function find_existing( array $data, WP_REST_Request $request ): ?array {
		$slug = ! empty( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: ( ! empty( $data['title'] ) ? sanitize_title( $data['title'] ) : '' );

		if ( empty( $slug ) ) {
			return null;
		}

		$existing_id = $this->template_slug_exists_for_theme( $slug );

		if ( ! $existing_id ) {
			return null;
		}

		return $this->format_full_item( get_post( $existing_id ) );
	}

	/**
	 * Returns the JSON Schema for a block template resource.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-template',
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => array(
				'id'             => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => __( 'Template post ID.', 'restlesswp' ),
				),
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Template display title.', 'restlesswp' ),
				),
				'slug'           => array(
					'type'        => 'string',
					'description' => __( 'Template slug.', 'restlesswp' ),
				),
				'content'        => array(
					'type'              => 'string',
					'description'       => __( 'Raw block markup as HTML string. Provide this OR blocks (blocks takes precedence).', 'restlesswp' ),
					'sanitize_callback' => 'wp_kses_post',
				),
				'blocks'         => array(
					'type'        => 'array',
					'description' => __( 'Block tree in editor format (etch/*) or docs format (auto-converted on write). Each block must include innerHTML and innerContent. Takes precedence over content field.', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'source'         => array(
					'type'        => 'string',
					'readonly'    => true,
					'enum'        => array( 'theme', 'plugin', 'custom' ),
					'description' => __( 'Template origin: theme (from theme files), plugin (from plugin), custom (DB-stored, editable).', 'restlesswp' ),
				),
				'has_theme_file' => array(
					'type'        => 'boolean',
					'readonly'    => true,
					'description' => __( 'Whether a theme file exists for this template.', 'restlesswp' ),
				),
			),
		);
	}

	/**
	 * Formats a WP_Block_Template for the list response (no content).
	 *
	 * @param \WP_Block_Template $template Block template object.
	 * @return array Formatted list item.
	 */
	private function format_list_item( \WP_Block_Template $template ): array {
		return array(
			'id'             => $template->wp_id ?: null,
			'title'          => $template->title,
			'slug'           => $template->slug,
			'source'         => $template->source,
			'has_theme_file' => $template->has_theme_file,
		);
	}

	/**
	 * Formats a WP_Post into the full API response shape with content.
	 *
	 * @param WP_Post $post The wp_template post object.
	 * @return array Formatted template data.
	 */
	private function format_full_item( WP_Post $post ): array {
		$block_template = $this->find_block_template_by_post( $post );

		return array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'content'        => $post->post_content,
			'blocks'         => parse_blocks( $post->post_content ),
			'source'         => $block_template ? $block_template->source : 'custom',
			'has_theme_file' => $block_template ? $block_template->has_theme_file : false,
		);
	}

	/**
	 * Resolves content from blocks or content field.
	 *
	 * @param array $data Request data with optional blocks/content.
	 * @return string Serialized block markup or sanitized HTML.
	 */
	private function resolve_content( array $data ): string {
		return RestlessWP_Etch_Normalizer::content( $data );
	}

	/**
	 * Finds the WP_Block_Template object matching a post for source metadata.
	 *
	 * @param WP_Post $post The wp_template post.
	 * @return \WP_Block_Template|null Matching block template or null.
	 */
	private function find_block_template_by_post( WP_Post $post ): ?\WP_Block_Template {
		$templates = $this->fetch_theme_templates();

		foreach ( $templates as $template ) {
			if ( (int) $template->wp_id === $post->ID ) {
				return $template;
			}
		}

		return null;
	}
}
