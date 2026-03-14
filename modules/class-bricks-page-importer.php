<?php
/**
 * Bricks Page Importer — handles interchange format imports.
 *
 * Orchestrates additive merging of global classes and components,
 * then writes elements to a page's post meta.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bricks-normalizer.php';
require_once __DIR__ . '/trait-bricks-option-blob.php';

/**
 * Importer for Bricks page interchange format.
 */
class RestlessWP_Bricks_Page_Importer {
	use RestlessWP_Bricks_Option_Blob;

	/** @var string Option name for global classes. */
	private const CLASSES_OPTION = 'bricks_global_classes';

	/** @var string Option name for components. */
	private const COMPONENTS_OPTION = 'bricks_global_elements';

	/**
	 * Executes the full import: merge classes, merge components, write elements.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $data    Import payload.
	 * @return array Import report.
	 */
	public function run( int $post_id, array $data ): array {
		$classes_report    = $this->merge_global_classes( $data['global_classes'] ?? array() );
		$components_report = $this->merge_components( $data['components'] ?? array() );

		if ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
			$this->write_elements( $post_id, $data['elements'] );
		}

		return $this->build_report( $post_id, $classes_report, $components_report );
	}

	/**
	 * Merges incoming global classes additively.
	 *
	 * Existing IDs are skipped; new IDs are normalized and appended.
	 *
	 * @param array $incoming Array of global class objects.
	 * @return array{ added: string[], skipped: string[] } Merge report.
	 */
	private function merge_global_classes( array $incoming ): array {
		$existing = $this->fetch_blob( self::CLASSES_OPTION );
		$added    = array();
		$skipped  = array();

		foreach ( $incoming as $class ) {
			$id = sanitize_text_field( $class['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			if ( null !== $this->find_by_id( $existing, $id ) ) {
				$skipped[] = $id;
				continue;
			}

			$existing[] = RestlessWP_Bricks_Normalizer::global_class( $class );
			$added[]    = $id;
		}

		if ( ! empty( $added ) ) {
			$this->save_blob( self::CLASSES_OPTION, $existing );
		}

		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Merges incoming components additively.
	 *
	 * Existing IDs are skipped; new IDs are normalized and appended.
	 *
	 * @param array $incoming Array of component objects.
	 * @return array{ added: string[], skipped: string[] } Merge report.
	 */
	private function merge_components( array $incoming ): array {
		$existing = $this->fetch_blob( self::COMPONENTS_OPTION );
		$added    = array();
		$skipped  = array();

		foreach ( $incoming as $component ) {
			$id = sanitize_text_field( $component['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			if ( null !== $this->find_by_id( $existing, $id ) ) {
				$skipped[] = $id;
				continue;
			}

			$existing[] = RestlessWP_Bricks_Normalizer::component( $component );
			$added[]    = $id;
		}

		if ( ! empty( $added ) ) {
			$this->save_blob( self::COMPONENTS_OPTION, $existing );
		}

		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Writes normalized elements to a page's post meta.
	 *
	 * @param int   $post_id  Target post ID.
	 * @param array $elements Raw elements array.
	 * @return void
	 */
	private function write_elements( int $post_id, array $elements ): void {
		$normalized = RestlessWP_Bricks_Normalizer::elements( $elements );

		if ( empty( $normalized ) ) {
			delete_post_meta( $post_id, '_bricks_page_content_2' );
			return;
		}

		update_post_meta( $post_id, '_bricks_page_content_2', wp_slash( $normalized ) );
	}

	/**
	 * Builds the import response report.
	 *
	 * @param int   $post_id           Target post ID.
	 * @param array $classes_report    Global classes merge report.
	 * @param array $components_report Components merge report.
	 * @return array Report data.
	 */
	private function build_report( int $post_id, array $classes_report, array $components_report ): array {
		return array(
			'post_id'             => $post_id,
			'classes_added'       => $classes_report['added'],
			'classes_skipped'     => $classes_report['skipped'],
			'components_added'    => $components_report['added'],
			'components_skipped'  => $components_report['skipped'],
		);
	}
}
