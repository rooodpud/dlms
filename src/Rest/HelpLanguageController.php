<?php
/**
 * REST: the learner's help language.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Frontend\HelpLanguage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /dlms/v1/help-language { language: "en" } saves the logged-in
 * user's choice of the language switch, so it follows them to other devices.
 */
final class HelpLanguageController extends RestController {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/help-language',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'can_save' ),
				'args'                => array(
					'language' => array(
						'type'     => 'string',
						'enum'     => array_keys( HelpLanguage::languages() ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Logged-in users only.
	 *
	 * @return true|WP_Error
	 */
	public function can_save() {
		return is_user_logged_in() ? true : $this->denied();
	}

	/**
	 * Saves the language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$language = (string) $request->get_param( 'language' );
		if ( ! HelpLanguage::save_user_language( get_current_user_id(), $language ) ) {
			return new WP_Error( 'dlms_help_language', __( 'Unknown language.', 'deutschlms' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'language' => $language ), 200 );
	}
}
