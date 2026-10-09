<?php
/**
 * REST: audio library.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Content\AudioClips;
use DeutschLMS\Content\PostTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /dlms/v1/audio-clips  Sets (or, with media 0, removes) the recording
 *                            of one German text: { text, media }.
 *
 * Used by Courses → Audio. Allowed for users who can edit questions.
 */
final class AudioClipsController extends RestController {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/audio-clips',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'text'  => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => AudioClips::MAX_TEXT,
						),
						'media' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
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
	 * Sets or removes one text's recording.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$text  = AudioClips::clean_text( (string) $request['text'] );
		$media = absint( $request['media'] );
		if ( '' === AudioClips::key_for( $text ) ) {
			return new WP_Error( 'dlms_invalid_text', __( 'Please enter a text.', 'deutschlms' ), array( 'status' => 400 ) );
		}
		if ( $media && ( ! AudioClips::is_audio( $media ) || ! current_user_can( 'read_post', $media ) ) ) {
			return new WP_Error( 'dlms_invalid_audio', __( 'Choose an audio file (MP3, M4A, OGG or WAV).', 'deutschlms' ), array( 'status' => 400 ) );
		}
		AudioClips::save( $text, $media );

		return new WP_REST_Response(
			array(
				'key'   => AudioClips::key_for( $text ),
				'text'  => $text,
				'media' => $media,
				'url'   => AudioClips::url( $media ),
				'file'  => $media ? wp_basename( (string) get_attached_file( $media ) ) : '',
			)
		);
	}
}
