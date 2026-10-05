<?php
/**
 * WPML / Polylang compatibility.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Integrations;

use DeutschLMS\Content\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps enrollments and progress language-independent.
 *
 * Each translation of a course or step is a separate post. Enrollment and
 * progress are always stored against the default-language ("canonical") ID,
 * so a student enrolled in the German course is also enrolled in its English
 * translation, and completing a lesson in one language counts in all of them.
 */
final class Multilingual {

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'pll_copy_post_metas', array( $this, 'polylang_copy_metas' ) );
	}

	/**
	 * Default-language ID of a post (the ID itself without a translation plugin).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return int
	 */
	public static function canonical_id( int $post_id, string $post_type ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		$canonical = $post_id;

		if ( has_filter( 'wpml_object_id' ) ) {
			$default_language = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
			$canonical        = (int) apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
			$translated = (int) pll_get_post( $post_id, pll_default_language() );
			if ( $translated > 0 ) {
				$canonical = $translated;
			}
		}

		/**
		 * Filters the canonical (language-independent) ID of a post.
		 *
		 * @param int    $canonical Canonical ID.
		 * @param int    $post_id   Original ID.
		 * @param string $post_type Post type.
		 */
		return (int) apply_filters( 'dlms_canonical_post_id', $canonical > 0 ? $canonical : $post_id, $post_id, $post_type );
	}

	/**
	 * The post's translation in the current language (the post itself when
	 * there is none, or without a translation plugin). Used for bank
	 * questions, which quizzes store by their default-language ID.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return int
	 */
	public static function translated_id( int $post_id, string $post_type ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		$translated = $post_id;
		if ( has_filter( 'wpml_object_id' ) ) {
			$translated = (int) apply_filters( 'wpml_object_id', $post_id, $post_type, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $post_id );
		}

		return $translated > 0 ? $translated : $post_id;
	}

	/**
	 * Tells Polylang to copy the hierarchy meta to new translations.
	 *
	 * @param string[] $keys Meta keys Polylang copies.
	 * @return string[]
	 */
	public function polylang_copy_metas( $keys ) {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = Meta::COURSE_ID;
		$keys[] = Meta::LESSON_ID;
		$keys[] = Meta::PARENT_ID;
		$keys[] = Meta::LINEAR;
		$keys[] = Meta::DRIP_DAYS;
		$keys[] = Meta::PASS_MARK;
		$keys[] = Meta::ATTEMPTS_LIMIT;
		$keys[] = Meta::SHOW_ANSWERS;
		$keys[] = Meta::TIME_LIMIT;
		$keys[] = Meta::QUESTIONS;
		$keys[] = Meta::QUIZ_ITEMS;
		$keys[] = Meta::QUESTION;
		$keys[] = Meta::QUESTION_KEY;
		$keys[] = Meta::QUESTION_TYPE;
		$keys[] = Meta::QUESTION_READY;
		$keys[] = Meta::CERT_ENABLED;
		$keys[] = Meta::CERT_BACKGROUND;
		return array_values( array_unique( $keys ) );
	}
}
