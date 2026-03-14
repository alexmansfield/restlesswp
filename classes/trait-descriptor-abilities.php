<?php
/**
 * Descriptor Abilities Trait — registers abilities from ability descriptors.
 *
 * Extracted from the abilities registrar to keep file sizes under the
 * 500-line limit. Handles the descriptor-based registration path for
 * third-party controllers (e.g. Gravity Forms).
 *
 * @package RestlessWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for registering abilities from descriptor value objects.
 *
 * Requires the using class to provide:
 * - $this->auth (RestlessWP_Auth_Handler)
 * - $this->schema_builder (RestlessWP_Ability_Schema_Builder)
 * - $this->do_register_ability() method
 */
trait RestlessWP_Descriptor_Abilities {

	/**
	 * Registers abilities from an array of descriptors.
	 *
	 * @param RestlessWP_Ability_Descriptor[] $descriptors Ability descriptors.
	 * @return void
	 */
	private function register_from_descriptors( array $descriptors ): void {
		foreach ( $descriptors as $descriptor ) {
			$this->register_from_descriptor( $descriptor );
		}
	}

	/**
	 * Registers abilities for a single descriptor.
	 *
	 * @param RestlessWP_Ability_Descriptor $descriptor Ability descriptor.
	 * @return void
	 */
	private function register_from_descriptor( RestlessWP_Ability_Descriptor $descriptor ): void {
		$controller = $this->make_descriptor_controller( $descriptor );

		if ( null === $controller ) {
			return;
		}

		$schema = $this->get_controller_schema( $controller );

		foreach ( $descriptor->operations as $action ) {
			if ( ! isset( self::OPERATIONS[ $action ] ) ) {
				continue;
			}

			$this->register_descriptor_ability(
				$controller,
				$descriptor,
				$action,
				self::OPERATIONS[ $action ],
				$schema
			);
		}
	}

	/**
	 * Registers a single ability from a descriptor.
	 *
	 * @param object                        $controller Controller instance.
	 * @param RestlessWP_Ability_Descriptor $descriptor Ability descriptor.
	 * @param string                        $action     Operation name.
	 * @param array                         $config     Operation config.
	 * @param array                         $schema     Controller item schema.
	 * @return void
	 */
	private function register_descriptor_ability(
		object $controller,
		RestlessWP_Ability_Descriptor $descriptor,
		string $action,
		array $config,
		array $schema
	): void {
		$ability_name = "restlesswp/{$descriptor->module_slug}-{$action}-{$descriptor->resource_slug}";

		$permission = $this->build_descriptor_permission(
			$descriptor,
			$config['capability']
		);

		$execute = $this->build_descriptor_execute_callback(
			$controller,
			$descriptor,
			$action
		);

		$prefix        = $descriptor->description_prefix;
		$resource_name = str_replace( '-', ' ', $descriptor->resource_slug );
		$desc_resource = '' !== $prefix ? "{$prefix} {$resource_name}" : $resource_name;
		$description   = $this->build_description( $action, $desc_resource );

		$this->do_register_ability(
			$ability_name,
			$config,
			$descriptor->resource_slug,
			$description,
			$this->schema_builder->build_input_schema(
				$action,
				$schema,
				$descriptor->id_param,
				$descriptor->id_type
			),
			$this->schema_builder->build_output_schema( $action, $schema ),
			$execute,
			$permission
		);
	}

	/**
	 * Instantiates a controller from a descriptor.
	 *
	 * Loads the controller file if specified and the class doesn't exist yet.
	 *
	 * @param RestlessWP_Ability_Descriptor $descriptor Ability descriptor.
	 * @return object|null Controller instance or null.
	 */
	private function make_descriptor_controller( RestlessWP_Ability_Descriptor $descriptor ): ?object {
		if ( ! class_exists( $descriptor->controller_class ) ) {
			if ( '' !== $descriptor->controller_file && file_exists( $descriptor->controller_file ) ) {
				require_once $descriptor->controller_file;
			}
		}

		if ( ! class_exists( $descriptor->controller_class ) ) {
			return null;
		}

		return new ( $descriptor->controller_class )();
	}

	/**
	 * Gets the item schema from any controller.
	 *
	 * @param object $controller Controller instance.
	 * @return array JSON Schema array.
	 */
	private function get_controller_schema( object $controller ): array {
		if ( method_exists( $controller, 'get_item_schema' ) ) {
			return $controller->get_item_schema();
		}

		return array( 'type' => 'object', 'properties' => array() );
	}

	/**
	 * Builds the permission callback for a descriptor ability.
	 *
	 * @param RestlessWP_Ability_Descriptor $descriptor Ability descriptor.
	 * @param string                        $cap_type   Capability type.
	 * @return \Closure Permission callback.
	 */
	private function build_descriptor_permission(
		RestlessWP_Ability_Descriptor $descriptor,
		string $cap_type
	): \Closure {
		$cap_map    = $descriptor->capabilities;
		$capability = $cap_map[ $cap_type ] ?? $cap_map['read'] ?? 'manage_options';

		if ( null !== $descriptor->permission_factory ) {
			return ( $descriptor->permission_factory )( $capability );
		}

		return $this->auth->permission_callback( $capability );
	}

	/**
	 * Builds the execute callback for a descriptor-based ability.
	 *
	 * @param object                        $controller Controller instance.
	 * @param RestlessWP_Ability_Descriptor $descriptor Ability descriptor.
	 * @param string                        $action     Operation name.
	 * @return \Closure Execute callback.
	 */
	private function build_descriptor_execute_callback(
		object $controller,
		RestlessWP_Ability_Descriptor $descriptor,
		string $action
	): \Closure {
		$method   = $descriptor->method_map[ $action ] ?? 'get_items';
		$id_param = $descriptor->id_param;
		$unwrap   = $descriptor->response_unwrapper;

		return function ( $input = array() ) use ( $controller, $action, $method, $id_param, $unwrap ) {
			$request  = self::build_descriptor_request( $action, $input, $id_param );
			$response = $controller->$method( $request );

			if ( null !== $unwrap ) {
				return $unwrap( $response );
			}

			return self::unwrap_descriptor_response( $response );
		};
	}

	/**
	 * Builds a WP_REST_Request for a descriptor-based ability.
	 *
	 * @param string $action   Operation name.
	 * @param array  $input    Ability input data.
	 * @param string $id_param URL param name for the resource ID.
	 * @return WP_REST_Request
	 */
	private static function build_descriptor_request(
		string $action,
		array $input,
		string $id_param
	): WP_REST_Request {
		$method_map = array(
			'list'   => 'GET',
			'get'    => 'GET',
			'create' => 'POST',
			'update' => 'PUT',
			'delete' => 'DELETE',
		);

		$request = new WP_REST_Request( $method_map[ $action ] ?? 'GET' );

		if ( isset( $input[ $id_param ] ) ) {
			$id = $input[ $id_param ];
			$request->set_url_params( array( $id_param => $id ) );
			$request->set_param( $id_param, $id );
			$request->set_param( 'id', $id );
		}

		if ( in_array( $action, array( 'create', 'update' ), true ) ) {
			$body = $input;
			unset( $body[ $id_param ] );
			$request->set_body( wp_json_encode( $body ) );
			$request->set_header( 'Content-Type', 'application/json' );

			foreach ( $body as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		return $request;
	}

	/**
	 * Default response unwrapper for descriptor-based abilities.
	 *
	 * @param mixed $response Controller response.
	 * @return mixed Unwrapped data or WP_Error.
	 */
	private static function unwrap_descriptor_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			return $response->get_data();
		}

		return $response;
	}
}
