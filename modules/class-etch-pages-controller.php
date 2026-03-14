<?php
/**
 * Etch Pages Controller — REST endpoints for page content operations.
 *
 * Provides GET (read blocks), PUT (write content), and POST import
 * (full interchange format with style/loop merging). No list or create —
 * agents use wp/v2/pages for post management.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-page-importer.php';

/**
 * REST controller for Etch page content operations.
 */
class RestlessWP_Etch_Pages_Controller extends RestlessWP_Base_Controller {

	/** @var RestlessWP_Etch_Page_Importer */
	private RestlessWP_Etch_Page_Importer $importer;

	/**
	 * @param RestlessWP_Auth_Handler $auth Auth handler instance.
	 */
	public function __construct( RestlessWP_Auth_Handler $auth ) {
		parent::__construct( $auth );
		$this->importer = new RestlessWP_Etch_Page_Importer(
			new RestlessWP_Etch_Style_Repository()
		);
	}

	/** @return string */
	protected function get_route_base(): string {
		return 'etch/pages';
	}

	/** @return string */
	protected function get_read_capability(): string {
		return 'edit_posts';
	}

	/** @return string */
	protected function get_write_capability(): string {
		return 'edit_posts';
	}

	/** @return string[] */
	public function get_supported_operations(): array {
		return array( 'get', 'update', 'import' );
	}

	/** @return array<string, string> */
	public function get_ability_descriptions(): array {
		return array(
			'get'    => 'Read a page\'s content as raw block markup and parsed block tree. Use this to inspect existing Etch page content before modifying it. The key is the WordPress post ID. Any element scripts are returned as plain JavaScript in attrs.script.code.',
			'update' => 'Write content to an existing page. Accepts a blocks array or a content HTML string (blocks takes precedence). For building new Etch pages with styles and loops, prefer the import operation — it handles style/loop creation and block wiring atomically. To attach JavaScript to an element, set attrs.script with { id: "unique-dedup-key", code: "your JS here" }. The id is REQUIRED — Etch uses it to load the script only once per page. Send code as plain JavaScript; base64 encoding is handled automatically.',
			'import' => 'Import a complete Etch design onto a page in one call. This is the RECOMMENDED way to build Etch pages. Accepts the full interchange format: { post_id, def: { blocks }, styles: { key: styleObj }, loops: { key: loopObj } }. Styles and loops are additively merged (new keys added, existing preserved). Block format is auto-converted from docs format if needed. Returns a merge report showing what was added/skipped plus the updated page data. Element blocks may include attrs.script with { id, code } for JavaScript behavior — send code as plain JS.',
		);
	}

	/** @return array|null */
	public function get_ability_input_schema( string $action ): ?array {
		if ( 'import' !== $action ) {
			return null;
		}

		return $this->build_import_ability_schema_def();
	}

	/** @return void */
	public function register_routes(): void {
		$base = $this->get_route_base();

		$this->register_single_item_routes( $base );
		$this->register_import_route( $base );
	}

	/** @return void */
	private function register_single_item_routes( string $base ): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/(?P<key>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'handle_update' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $this->get_update_args(),
				),
				'args' => array(
					'key' => array(
						'description'       => __( 'Post ID.', 'restlesswp' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/** @return void */
	private function register_import_route( string $base ): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => array( $this, 'check_import_permission' ),
				'args'                => $this->get_import_args(),
			)
		);
	}

	/** @return bool|WP_Error */
	public function check_read_permission( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'key' );

		return $this->check_post_permission( $post_id );
	}

	/** @return bool|WP_Error */
	public function check_write_permission( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'key' );

		return $this->check_post_permission( $post_id );
	}

	/** @return bool|WP_Error */
	public function check_import_permission( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $post_id ) {
			return current_user_can( 'edit_posts' );
		}

		return $this->check_post_permission( $post_id );
	}

	/** @return bool|WP_Error */
	private function check_post_permission( int $post_id ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'restlesswp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this post.', 'restlesswp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Retrieves a single page's content and parsed blocks.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Page data or WP_Error if not found.
	 */
	protected function get_item( string $key, WP_REST_Request $request ) {
		$post = $this->validate_post_exists( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->format_page( $post );
	}

	/**
	 * Writes content to an existing page.
	 *
	 * @param string          $key     Post ID as string from URL param.
	 * @param array           $data    Validated data (already merged by base).
	 * @param WP_REST_Request $request Full request object.
	 * @return array|WP_Error Updated page data or WP_Error on failure.
	 */
	protected function update_item( string $key, array $data, WP_REST_Request $request ) {
		$post = $this->validate_post_exists( (int) $key );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$incoming = $request->get_json_params();

		if ( ! isset( $incoming['blocks'] ) && ! isset( $incoming['content'] ) ) {
			return new WP_Error(
				'validation_error',
				__( 'Request body must include blocks or content.', 'restlesswp' ),
				array( 'status' => 400 )
			);
		}

		$content = $this->importer->resolve_content( $incoming );
		$result  = wp_update_post( array(
			'ID'           => $post->ID,
			'post_content' => $content,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_page( get_post( $post->ID ) );
	}

	/**
	 * Handles the full interchange format import.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_import( WP_REST_Request $request ): WP_REST_Response {
		$data    = $request->get_json_params();
		$post_id = (int) ( $data['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return RestlessWP_Response_Formatter::error(
				'validation_error',
				__( 'The post_id field is required.', 'restlesswp' )
			);
		}

		$post = $this->validate_post_exists( $post_id );

		if ( is_wp_error( $post ) ) {
			return $this->wp_error_to_response( $post );
		}

		$report = $this->run_import( $post, $data );

		if ( is_wp_error( $report ) ) {
			return $this->wp_error_to_response( $report );
		}

		return RestlessWP_Response_Formatter::success( $report );
	}

	/**
	 * Executes the import via the importer and appends page data.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $data Import payload.
	 * @return array|WP_Error Import report or WP_Error on failure.
	 */
	private function run_import( WP_Post $post, array $data ) {
		$report = $this->importer->run( $post, $data );

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$report['page'] = $this->format_page( get_post( $post->ID ) );

		return $report;
	}

	/**
	 * Validates that a post exists and has a public post type.
	 *
	 * @param int $post_id Post ID to validate.
	 * @return WP_Post|WP_Error The post if valid, WP_Error otherwise.
	 */
	private function validate_post_exists( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		$post_type_obj = get_post_type_object( $post->post_type );

		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'restlesswp' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Builds the import ability input schema.
	 *
	 * @return array JSON Schema for import input.
	 */
	private function build_import_ability_schema_def(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_id', 'def' ),
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Target post/page ID to write content to.', 'restlesswp' ),
				),
				'type'    => array(
					'type'        => 'string',
					'enum'        => array( 'component', 'element' ),
					'description' => __( 'Interchange format type. Use "component" to also create a reusable wp_block, "element" for page-only content.', 'restlesswp' ),
				),
				'def'     => array(
					'type'        => 'object',
					'description' => __( 'Definition containing blocks array. For components, also include name, key, and properties. Blocks may be in docs format (auto-converted) or editor format.', 'restlesswp' ),
				),
				'styles'  => array(
					'type'        => 'object',
					'description' => __( 'Map of style keys to style objects. Each style needs: { selector, css, type (optional) }. Keys must match the style keys referenced in block attrs.styles arrays. New keys are added to etch_styles; existing keys are preserved unchanged.', 'restlesswp' ),
				),
				'loops'   => array(
					'type'        => 'object',
					'description' => __( 'Map of loop keys to loop data objects. Each loop needs: { name, config: { type, args } }. Keys must match the loop keys referenced in repeater block attrs. Same additive merge: new keys added, existing preserved.', 'restlesswp' ),
				),
			),
		);
	}

	/**
	 * Formats a post into the page content response shape.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted page data.
	 */
	private function format_page( WP_Post $post ): array {
		$blocks = RestlessWP_Etch_Normalizer::decode_block_scripts(
			parse_blocks( $post->post_content )
		);

		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'content'   => $post->post_content,
			'blocks'    => $blocks,
			'modified'  => $post->post_modified,
		);
	}

	/** @return array */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'etch-page',
			'type'       => 'object',
			'properties' => array(
				'id'        => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => __( 'Post ID.', 'restlesswp' ),
				),
				'title'     => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Post title.', 'restlesswp' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Post type slug.', 'restlesswp' ),
				),
				'status'    => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Post status.', 'restlesswp' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Raw block markup as HTML string. Provide this OR blocks (blocks takes precedence). For styled Etch pages, use the import operation instead.', 'restlesswp' ),
				),
				'blocks'    => array(
					'type'        => 'array',
					'description' => __( 'Block tree in editor format (etch/*) or docs format (core/group with etchData — auto-converted). Each block MUST include innerHTML and innerContent. Blocks reference style keys in attrs.styles array. Takes precedence over content field. etch/element blocks may include attrs.script: { id: "unique-dedup-key", code: "plain JavaScript" }. The id ensures the script loads once per page; code is plain JS (base64 encoding is handled automatically).', 'restlesswp' ),
					'items'       => array( 'type' => 'object' ),
				),
				'modified'  => array(
					'type'        => 'string',
					'readonly'    => true,
					'description' => __( 'Last modified datetime.', 'restlesswp' ),
				),
			),
		);
	}

	/** @return array */
	private function get_update_args(): array {
		return array(
			'content' => array(
				'type'              => 'string',
				'description'       => __( 'Raw block markup.', 'restlesswp' ),
				'sanitize_callback' => 'wp_kses_post',
			),
			'blocks'  => array(
				'type'        => 'array',
				'description' => __( 'Parsed block tree.', 'restlesswp' ),
				'items'       => array( 'type' => 'object' ),
			),
		);
	}

	/** @return array */
	private function get_import_args(): array {
		return array(
			'post_id'           => array(
				'type'              => 'integer',
				'required'          => true,
				'description'       => __( 'Target post/page ID.', 'restlesswp' ),
				'sanitize_callback' => 'absint',
			),
			'type'              => array(
				'type'              => 'string',
				'enum'              => array( 'component', 'element' ),
				'description'       => __( 'Interchange format type.', 'restlesswp' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'def'               => array(
				'type'        => 'object',
				'required'    => true,
				'description' => __( 'Definition with blocks array. For components, also contains name, key, properties.', 'restlesswp' ),
			),
			'styles'            => array(
				'type'        => 'object',
				'description' => __( 'Style ID to style object map. New IDs merged into etch_styles; existing IDs preserved unchanged.', 'restlesswp' ),
			),
			'loops'             => array(
				'type'        => 'object',
				'description' => __( 'Loop ID to loop data map. Same merge strategy.', 'restlesswp' ),
			),
			);
	}

	/** @return array */
	protected function get_items( WP_REST_Request $request ) {
		return array();
	}

	/** @return WP_Error */
	protected function create_item( array $data, WP_REST_Request $request ) {
		return new WP_Error(
			'restlesswp_method_not_allowed',
			__( 'Create is not supported for pages. Use wp/v2/pages.', 'restlesswp' ),
			array( 'status' => 405 )
		);
	}
}
