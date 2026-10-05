<?php
/**
 * REST base.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the `dlms/v1` controllers.
 *
 * Authentication: cookie-authenticated requests must send the `wp_rest` nonce
 * (X-WP-Nonce header); WordPress core treats a cookie request without a valid
 * nonce as logged out, so every write below then fails its permission check.
 * Application passwords work as well for server-to-server use.
 */
abstract class RestController {

	public const REST_NAMESPACE = 'dlms/v1';

	/**
	 * Registers routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Schema for a positive integer path ID.
	 *
	 * @return array
	 */
	protected function id_arg(): array {
		return array(
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Error for unauthenticated (401) or unauthorized (403) requests.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	protected function denied( string $message = '' ): WP_Error {
		return new WP_Error(
			'dlms_rest_forbidden',
			'' !== $message ? $message : __( 'Sorry, you are not allowed to do that.', 'deutschlms' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
