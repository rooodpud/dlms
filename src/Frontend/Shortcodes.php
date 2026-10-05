<?php
/**
 * Shortcodes (fallback for the blocks).
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode equivalents of every block, for classic content and page builders.
 *
 * [dlms_course_outline course_id="" show_topics="yes"]
 * [dlms_progress_bar course_id="" show_label="yes"]
 * [dlms_enroll_button course_id=""]
 * [dlms_mark_complete step_id=""]
 * [dlms_course_grid columns="3" per_page="12" orderby="date" show_progress="yes"]
 * [dlms_student_dashboard show_completed="yes"]
 *
 * An empty course_id/step_id means "the current course/step".
 */
final class Shortcodes {

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'dlms_course_outline', array( $this, 'course_outline' ) );
		add_shortcode( 'dlms_progress_bar', array( $this, 'progress_bar' ) );
		add_shortcode( 'dlms_enroll_button', array( $this, 'enroll_button' ) );
		add_shortcode( 'dlms_mark_complete', array( $this, 'mark_complete' ) );
		add_shortcode( 'dlms_course_grid', array( $this, 'course_grid' ) );
		add_shortcode( 'dlms_student_dashboard', array( $this, 'student_dashboard' ) );
	}

	/**
	 * [dlms_course_outline].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function course_outline( $atts ): string {
		$atts = shortcode_atts(
			array(
				'course_id'   => 0,
				'show_topics' => 'yes',
			),
			$atts,
			'dlms_course_outline'
		);
		return $this->wrap( $this->renderer->course_outline( $this->course_id( $atts['course_id'] ), $this->flag( $atts['show_topics'] ) ) );
	}

	/**
	 * [dlms_progress_bar].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function progress_bar( $atts ): string {
		$atts = shortcode_atts(
			array(
				'course_id'  => 0,
				'show_label' => 'yes',
			),
			$atts,
			'dlms_progress_bar'
		);
		return $this->wrap( $this->renderer->progress_bar( $this->course_id( $atts['course_id'] ), $this->flag( $atts['show_label'] ) ) );
	}

	/**
	 * [dlms_enroll_button].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function enroll_button( $atts ): string {
		$atts = shortcode_atts( array( 'course_id' => 0 ), $atts, 'dlms_enroll_button' );
		return $this->wrap( $this->renderer->notice() . $this->renderer->enroll_button( $this->course_id( $atts['course_id'] ) ) );
	}

	/**
	 * [dlms_mark_complete].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function mark_complete( $atts ): string {
		$atts    = shortcode_atts( array( 'step_id' => 0 ), $atts, 'dlms_mark_complete' );
		$step_id = absint( $atts['step_id'] );
		return $this->wrap( $this->renderer->mark_complete( $step_id ? $step_id : Context::current_step_id() ) );
	}

	/**
	 * [dlms_course_grid].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function course_grid( $atts ): string {
		$atts = shortcode_atts(
			array(
				'columns'       => 3,
				'per_page'      => 12,
				'orderby'       => 'date',
				'show_progress' => 'yes',
			),
			$atts,
			'dlms_course_grid'
		);
		return $this->wrap(
			$this->renderer->course_grid(
				array(
					'columns'       => absint( $atts['columns'] ),
					'per_page'      => absint( $atts['per_page'] ),
					'orderby'       => sanitize_key( $atts['orderby'] ),
					'show_progress' => $this->flag( $atts['show_progress'] ),
				)
			)
		);
	}

	/**
	 * [dlms_student_dashboard].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function student_dashboard( $atts ): string {
		$atts = shortcode_atts( array( 'show_completed' => 'yes' ), $atts, 'dlms_student_dashboard' );
		return $this->wrap( $this->renderer->student_dashboard( array( 'show_completed' => $this->flag( $atts['show_completed'] ) ) ) );
	}

	/**
	 * Explicit course ID or the current context.
	 *
	 * @param mixed $value Attribute value.
	 * @return int
	 */
	private function course_id( $value ): int {
		$course_id = absint( $value );
		return $course_id ? $course_id : Context::current_course_id();
	}

	/**
	 * Parses yes/no style attributes.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private function flag( $value ): bool {
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Wraps shortcode output in the plugin's style scope.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function wrap( string $html ): string {
		return '' === $html ? '' : '<div class="dlms dlms-shortcode">' . $html . '</div>';
	}
}
