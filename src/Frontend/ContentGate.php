<?php
/**
 * Content protection and automatic course UI.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Content\PostTypes;
use WP_Post;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Protects lesson/topic content everywhere it can leak (the_content, excerpts,
 * feeds, oEmbed, REST) and appends the course UI to course/lesson/topic pages.
 */
final class ContentGate {

	/**
	 * Access control.
	 *
	 * @var AccessControl
	 */
	private AccessControl $access;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param AccessControl $access   Access control.
	 * @param Renderer      $renderer Renderer.
	 */
	public function __construct( AccessControl $access, Renderer $renderer ) {
		$this->access   = $access;
		$this->renderer = $renderer;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// After blocks (9), wpautop (10) and shortcodes (11), so appended markup
		// isn't reformatted, and any page builder output is replaced when locked.
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 20, 2 );
		foreach ( PostTypes::step_types() as $post_type ) {
			add_filter( "rest_prepare_{$post_type}", array( $this, 'filter_rest_response' ), 10, 2 );
		}
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Replaces locked step content and appends course UI on single views.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		if ( PostTypes::COURSE === $post->post_type ) {
			if ( $this->should_append( $post ) ) {
				$content .= $this->renderer->course_overview( $post->ID );
			}
			return $this->page_top( $post ) . $content;
		}

		if ( ! PostTypes::is_step( $post ) ) {
			return $content;
		}

		$result = $this->access->check( get_current_user_id(), $post->ID );
		if ( ! $result->allowed ) {
			// Replace, never append: protected content must not reach the page.
			return $this->page_top( $post ) . $this->renderer->locked_message( $result, $post->ID );
		}
		$content = $this->page_top( $post ) . $content;

		// A quiz page always gets its form: without it the quiz can't be taken.
		$is_quiz = PostTypes::QUIZ === $post->post_type;
		if ( $is_quiz ? $this->is_main_view( $post ) : $this->should_append( $post ) ) {
			if ( $is_quiz ) {
				$extra = $this->renderer->notice() . $this->renderer->quiz_view( $post->ID );
			} else {
				$extra = $this->renderer->step_children( $post->ID ) . $this->renderer->mark_complete( $post->ID );
			}
			$extra   .= $this->renderer->step_navigation( $post->ID );
			$content .= '<div class="dlms dlms-step-footer">' . $extra . '</div>';
		}

		return $content;
	}

	/**
	 * Top of a course or step page in a course with help languages: the
	 * title's translation (under the page heading) and the language switch.
	 *
	 * @param WP_Post $post Course or step.
	 * @return string
	 */
	private function page_top( WP_Post $post ): string {
		if ( ! $this->is_main_view( $post ) || ! HelpLanguage::is_active() ) {
			return '';
		}
		return HelpLanguage::subtitle( $post->ID ) . HelpLanguage::switcher();
	}

	/**
	 * Hides excerpts of locked steps (search results, feeds, oEmbed, SEO tags).
	 *
	 * @param string           $excerpt Excerpt.
	 * @param WP_Post|int|null $post    Post.
	 * @return string
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( $post instanceof WP_Post && PostTypes::is_step( $post ) && ! $this->access->can_view( get_current_user_id(), $post->ID ) ) {
			return '';
		}
		return $excerpt;
	}

	/**
	 * Strips locked content from REST responses (view/embed contexts). The edit
	 * context already requires edit permissions.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @return WP_REST_Response
	 */
	public function filter_rest_response( $response, $post ) {
		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post ) {
			return $response;
		}
		if ( $this->access->can_view( get_current_user_id(), $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$data['content']['rendered']  = '';
			$data['content']['protected'] = true;
			unset( $data['content']['raw'] );
		}
		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
			$data['excerpt']['rendered']  = '';
			$data['excerpt']['protected'] = true;
			unset( $data['excerpt']['raw'] );
		}
		$data['dlms_locked'] = true;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Adds state classes to course/step pages for theming.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( is_singular( PostTypes::step_types() ) ) {
			$classes[] = 'dlms-step';
			if ( ! $this->access->can_view( get_current_user_id(), get_queried_object_id() ) ) {
				$classes[] = 'dlms-step-locked';
			}
		} elseif ( is_singular( PostTypes::COURSE ) ) {
			$classes[] = 'dlms-course';
		}
		return $classes;
	}

	/**
	 * Whether to append the course UI: only on the post's own single view, in
	 * the main loop, and not when the author placed DeutschLMS blocks or
	 * layout shortcodes in the content (they control the layout then). Content
	 * shortcodes such as [dlms_noun] don't count.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function should_append( WP_Post $post ): bool {
		if ( ! $this->is_main_view( $post ) ) {
			return false;
		}

		$has_own_layout = str_contains( $post->post_content, '<!-- wp:deutschlms/' )
			|| 1 === preg_match( '/\[dlms_(?:' . implode( '|', Shortcodes::LAYOUT ) . ')\b/', $post->post_content );

		/**
		 * Filters whether DeutschLMS appends its UI to a course/lesson/topic page.
		 *
		 * @param bool    $append Default: true unless the content has DeutschLMS blocks/shortcodes.
		 * @param WP_Post $post   Post.
		 */
		return (bool) apply_filters( 'dlms_auto_append_content', ! $has_own_layout, $post );
	}

	/**
	 * Whether the content is being rendered as the post's own single view, in
	 * the main loop (not a widget, excerpt or related-posts list).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function is_main_view( WP_Post $post ): bool {
		return is_singular( $post->post_type ) && in_the_loop() && is_main_query() && get_queried_object_id() === $post->ID;
	}
}
