<?php
/**
 * REST: noun picture library.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Content\NounPictures;
use DeutschLMS\Content\PostTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /dlms/v1/noun-pictures  Sets (or, with an empty picture, clears) the
 *                              picture of one noun: { noun, picture }.
 *
 * Used by Courses → Noun pictures and the question editor. Allowed for users
 * who can edit questions.
 */
final class NounPicturesController extends RestController {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/noun-pictures',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'noun'    => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => 100,
						),
						'picture' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 100,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission: may edit questions.
	 *
	 * @return true|WP_Error
	 */
	public function can_edit() {
		$type = get_post_type_object( PostTypes::QUESTION );
		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Sets or clears one noun's picture.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$noun = sanitize_text_field( (string) $request['noun'] );
		$key  = NounPictures::key_for( $noun );
		if ( '' === $key ) {
			return new WP_Error( 'dlms_invalid_noun', __( 'Please enter a noun.', 'deutschlms' ), array( 'status' => 400 ) );
		}

		$raw     = trim( (string) $request['picture'] );
		$picture = '' === $raw ? '' : NounPictures::sanitize_ref( $raw );
		if ( '' !== $raw && ! ( str_starts_with( $picture, NounPictures::ICON ) || str_starts_with( $picture, NounPictures::MEDIA ) ) ) {
			return new WP_Error( 'dlms_invalid_picture', __( 'Choose an icon or an image.', 'deutschlms' ), array( 'status' => 400 ) );
		}

		$library = NounPictures::stored();
		if ( '' === $picture ) {
			unset( $library[ $key ] );
		} else {
			$library[ $key ] = array(
				'noun'    => $noun,
				'picture' => $picture,
			);
		}
		NounPictures::save_library( $library );

		return new WP_REST_Response(
			array(
				'key'     => $key,
				'noun'    => $noun,
				'picture' => $picture,
				'url'     => NounPictures::preview_url( $picture ),
			)
		);
	}
}
