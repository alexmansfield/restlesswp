<?php
/**
 * ACF Field CRUD Trait — field-level endpoint handlers.
 *
 * Provides handle_add_field, handle_update_field, and handle_delete_field
 * methods plus the route registration helper for field sub-resources.
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for field-level CRUD operations on ACF field groups.
 *
 * Must be used by a class that extends RestlessWP_Base_Controller and
 * implements format_field().
 */
trait RestlessWP_ACF_Field_CRUD {

	/**
	 * Registers field-level sub-resource routes.
	 *
	 * @return void
	 */
	private function register_field_routes(): void {
		$base       = $this->get_route_base();
		$fields_url = '/' . $base . '/(?P<key>[\\w\\-]+)/fields';
		$perm       = $this->auth->permission_callback( 'manage_options' );

		register_rest_route(
			self::NAMESPACE,
			$fields_url,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_add_field' ),
				'permission_callback' => $perm,
			)
		);

		$single_url = $fields_url . '/(?P<field_key>[\\w\\-]+)';

		register_rest_route(
			self::NAMESPACE,
			$single_url,
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'handle_update_field' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_field' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	/**
	 * Handles POST request to add a field to a group.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_add_field( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$group_key  = $url_params['key'];
		$group      = acf_get_field_group( $group_key );

		if ( ! $group ) {
			return $this->wp_error_to_response( new WP_Error(
				'not_found',
				__( 'Field group not found.', 'restlesswp' ),
				array( 'status' => 404 )
			) );
		}

		$data = $request->get_json_params();

		if ( empty( $data['label'] ) || empty( $data['type'] ) ) {
			return RestlessWP_Response_Formatter::error(
				'validation_error',
				__( 'Fields "label" and "type" are required.', 'restlesswp' )
			);
		}

		if ( empty( $data['key'] ) ) {
			$data['key'] = 'field_' . uniqid();
		}

		$data['parent'] = $group['ID'];

		$field = acf_update_field( $data );

		if ( ! $field ) {
			return $this->wp_error_to_response( new WP_Error(
				'restlesswp_create_failed',
				__( 'Failed to create field.', 'restlesswp' ),
				array( 'status' => 500 )
			) );
		}

		return RestlessWP_Response_Formatter::success( $this->format_field( $field ), 201 );
	}

	/**
	 * Handles PUT request to update a single field.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_update_field( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$field_key  = $url_params['field_key'];
		$existing   = acf_get_field( $field_key );

		if ( ! $existing ) {
			return $this->wp_error_to_response( new WP_Error(
				'not_found',
				__( 'Field not found.', 'restlesswp' ),
				array( 'status' => 404 )
			) );
		}

		$data          = $request->get_json_params();
		$merged        = array_merge( $existing, $data );
		$merged['key'] = $field_key;
		$merged['ID']  = $existing['ID'];

		$field = acf_update_field( $merged );

		if ( ! $field ) {
			return $this->wp_error_to_response( new WP_Error(
				'restlesswp_update_failed',
				__( 'Failed to update field.', 'restlesswp' ),
				array( 'status' => 500 )
			) );
		}

		return RestlessWP_Response_Formatter::success( $this->format_field( $field ) );
	}

	/**
	 * Handles DELETE request to remove a field.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function handle_delete_field( WP_REST_Request $request ): WP_REST_Response {
		$url_params = $request->get_url_params();
		$field_key  = $url_params['field_key'];
		$existing   = acf_get_field( $field_key );

		if ( ! $existing ) {
			return $this->wp_error_to_response( new WP_Error(
				'not_found',
				__( 'Field not found.', 'restlesswp' ),
				array( 'status' => 404 )
			) );
		}

		$deleted = acf_delete_field( $existing['ID'] );

		if ( ! $deleted ) {
			return $this->wp_error_to_response( new WP_Error(
				'restlesswp_delete_failed',
				__( 'Failed to delete field.', 'restlesswp' ),
				array( 'status' => 500 )
			) );
		}

		return RestlessWP_Response_Formatter::success(
			array( 'deleted' => true, 'field_key' => $field_key )
		);
	}
}
