<?php
/**
 * Etch Page Importer — handles interchange format imports.
 *
 * Orchestrates content resolution, style/loop merging, and component
 * creation for the pages import endpoint.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-etch-normalizer.php';
require_once __DIR__ . '/class-etch-style-repository.php';

/**
 * Importer for Etch page interchange format.
 */
class RestlessWP_Etch_Page_Importer {

	/** @var RestlessWP_Etch_Style_Repository */
	private RestlessWP_Etch_Style_Repository $styles;

	/**
	 * @param RestlessWP_Etch_Style_Repository $styles Style repository.
	 */
	public function __construct( RestlessWP_Etch_Style_Repository $styles ) {
		$this->styles = $styles;
	}

	/**
	 * Executes the full import: merge styles/loops, handle component, write content.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $data Import payload.
	 * @return array|WP_Error Import report or WP_Error on failure.
	 */
	public function run( WP_Post $post, array $data ) {
		$styles_report = $this->merge_styles( $data['styles'] ?? array() );
		$loops_report  = $this->merge_loops( $data['loops'] ?? array() );

		$component_id = $this->maybe_create_component( $data );
		$def          = $data['def'] ?? array();
		$blocks       = $def['blocks'] ?? array();

		if ( ! empty( $blocks ) ) {
			$result = $this->write_blocks( $post->ID, $blocks );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->build_import_report(
			$post->ID,
			$styles_report,
			$loops_report,
			$component_id
		);
	}

	/**
	 * Resolves content from blocks or content field.
	 *
	 * @param array $data Request data with optional blocks/content.
	 * @return string Serialized block markup or sanitized HTML.
	 */
	public function resolve_content( array $data ): string {
		return RestlessWP_Etch_Normalizer::content( $data );
	}

	/**
	 * Merges incoming styles into the etch_styles option.
	 *
	 * Additive merge: new keys are added, existing keys are preserved
	 * unchanged. Always normalizes each style; entries missing required
	 * fields are skipped with a warning.
	 *
	 * @param array $incoming Style ID => style object map.
	 * @return array{ added: string[], skipped: string[], warnings: string[] } Merge report.
	 */
	public function merge_styles( array $incoming ): array {
		$existing = $this->styles->fetch_all();
		$added    = array();
		$skipped  = array();
		$warnings = array();

		foreach ( $incoming as $key => $style ) {
			if ( isset( $existing[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}

			if ( ! isset( $style['selector'], $style['css'] ) ) {
				$warnings[] = $key;
				continue;
			}

			$existing[ $key ] = RestlessWP_Etch_Normalizer::style( $style );
			$added[]          = $key;
		}

		if ( ! empty( $added ) ) {
			$this->styles->save_all( $existing );
		}

		return array(
			'added'    => $added,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		);
	}

	/**
	 * Merges incoming loops into the etch_loops option.
	 *
	 * Same additive strategy as merge_styles: new keys added,
	 * existing keys preserved unchanged. Always normalizes each loop;
	 * entries missing required fields are skipped with a warning.
	 *
	 * @param array $incoming Loop ID => loop data map.
	 * @return array{ added: string[], skipped: string[], warnings: string[] } Merge report.
	 */
	public function merge_loops( array $incoming ): array {
		$existing = get_option( 'etch_loops', array() );
		$added    = array();
		$skipped  = array();
		$warnings = array();

		foreach ( $incoming as $key => $loop ) {
			if ( isset( $existing[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}

			if ( ! isset( $loop['name'], $loop['config'] ) ) {
				$warnings[] = $key;
				continue;
			}

			$existing[ $key ] = RestlessWP_Etch_Normalizer::loop( $loop );
			$added[]          = $key;
		}

		if ( ! empty( $added ) ) {
			update_option( 'etch_loops', $existing );
		}

		return array(
			'added'    => $added,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		);
	}

	/**
	 * Converts and writes blocks to a post.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $blocks  Block tree to write.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function write_blocks( int $post_id, array $blocks ) {
		$converted = RestlessWP_Etch_Normalizer::blocks( $blocks );

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$content = wp_slash( serialize_blocks( $converted ) );

		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $content,
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Creates or updates a wp_block component if the import type is 'component'.
	 *
	 * @param array $data Import payload.
	 * @return int|WP_Error|null Post ID on success, null if not component type, WP_Error on failure.
	 */
	private function maybe_create_component( array $data ) {
		$type = $data['type'] ?? '';
		$def  = $data['def'] ?? array();

		if ( 'component' !== $type ) {
			return null;
		}

		if ( empty( $def['name'] ) || empty( $def['key'] ) ) {
			return null;
		}

		$existing = $this->find_component_by_key( $def['key'] );
		$blocks   = RestlessWP_Etch_Normalizer::blocks( $def['blocks'] ?? array() );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$content = wp_slash( serialize_blocks( $blocks ) );

		if ( $existing ) {
			return $this->update_existing_component( $existing, $content, $def );
		}

		return $this->create_new_component( $content, $def );
	}

	/**
	 * Updates an existing wp_block component.
	 *
	 * @param WP_Post $existing Existing component post.
	 * @param string  $content  Serialized block content.
	 * @param array   $def      Component definition.
	 * @return int|WP_Error Component post ID on success, WP_Error on failure.
	 */
	private function update_existing_component( WP_Post $existing, string $content, array $def ) {
		$result = wp_update_post( array(
			'ID'           => $existing->ID,
			'post_content' => $content,
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_component_properties( $existing->ID, $def );

		return $existing->ID;
	}

	/**
	 * Creates a new wp_block component.
	 *
	 * @param string $content Serialized block content.
	 * @param array  $def     Component definition.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_new_component( string $content, array $def ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'wp_block',
			'post_title'   => sanitize_text_field( $def['name'] ),
			'post_content' => $content,
			'post_status'  => 'publish',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, 'etch_component_html_key', sanitize_text_field( $def['key'] ) );
		$this->save_component_properties( $post_id, $def );

		return $post_id;
	}

	/**
	 * Finds an existing wp_block component by its HTML key.
	 *
	 * @param string $key Component HTML key.
	 * @return WP_Post|null The component post or null.
	 */
	private function find_component_by_key( string $key ): ?WP_Post {
		$posts = get_posts( array(
			'post_type'   => 'wp_block',
			'meta_key'    => 'etch_component_html_key',
			'meta_value'  => sanitize_text_field( $key ),
			'numberposts' => 1,
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Saves component properties meta if present in the definition.
	 *
	 * @param int   $post_id Component post ID.
	 * @param array $def     Component definition.
	 * @return void
	 */
	private function save_component_properties( int $post_id, array $def ): void {
		if ( isset( $def['properties'] ) ) {
			update_post_meta( $post_id, 'etch_component_properties', $def['properties'] );
		}
	}

	/**
	 * Builds the import response report.
	 *
	 * @param int             $post_id          Target post ID.
	 * @param array           $styles_report    Styles merge report.
	 * @param array           $loops_report     Loops merge report.
	 * @param int|WP_Error|null $component_result Component result.
	 * @return array Report data.
	 */
	private function build_import_report(
		int $post_id,
		array $styles_report,
		array $loops_report,
		$component_result
	): array {
		$report = array(
			'post_id'        => $post_id,
			'styles_added'   => $styles_report['added'],
			'styles_skipped' => $styles_report['skipped'],
			'loops_added'    => $loops_report['added'],
			'loops_skipped'  => $loops_report['skipped'],
		);

		$this->add_warnings_to_report( $report, $styles_report, $loops_report );
		$this->add_component_to_report( $report, $component_result );

		return $report;
	}

	/**
	 * Adds merge warnings to the import report if present.
	 *
	 * @param array $report        Report array (passed by reference).
	 * @param array $styles_report Styles merge report.
	 * @param array $loops_report  Loops merge report.
	 * @return void
	 */
	private function add_warnings_to_report(
		array &$report,
		array $styles_report,
		array $loops_report
	): void {
		$warnings = array();

		if ( ! empty( $styles_report['warnings'] ) ) {
			$warnings['styles'] = $styles_report['warnings'];
		}

		if ( ! empty( $loops_report['warnings'] ) ) {
			$warnings['loops'] = $loops_report['warnings'];
		}

		if ( ! empty( $warnings ) ) {
			$report['warnings'] = $warnings;
		}
	}

	/**
	 * Adds component result to the import report.
	 *
	 * @param array             $report           Report array (passed by reference).
	 * @param int|WP_Error|null $component_result Component post ID, WP_Error, or null.
	 * @return void
	 */
	private function add_component_to_report( array &$report, $component_result ): void {
		if ( null === $component_result ) {
			return;
		}

		if ( is_wp_error( $component_result ) ) {
			$report['component_error'] = $component_result->get_error_message();
			return;
		}

		$report['component_id'] = $component_result;
	}
}
