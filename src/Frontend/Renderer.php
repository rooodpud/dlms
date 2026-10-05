<?php
/**
 * Front-end view builder.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Access\AccessResult;
use DeutschLMS\Certificates\CertificateService;
use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Enrollment\EnrollmentRepository;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Integrations\Multilingual;
use DeutschLMS\Progress\ProgressCalculator;
use DeutschLMS\Quiz\Questions;
use DeutschLMS\Quiz\QuizService;
use DeutschLMS\Roles\Capabilities;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares data for templates and renders them. Shared by the automatic
 * course/lesson/topic/quiz output, the blocks and the shortcodes, so all of
 * them look and behave the same.
 */
final class Renderer {

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Progress calculator.
	 *
	 * @var ProgressCalculator
	 */
	private ProgressCalculator $calculator;

	/**
	 * Access control.
	 *
	 * @var AccessControl
	 */
	private AccessControl $access;

	/**
	 * Quiz service.
	 *
	 * @var QuizService
	 */
	private QuizService $quizzes;

	/**
	 * Certificate service.
	 *
	 * @var CertificateService
	 */
	private CertificateService $certificates;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure    $structure    Structure reader.
	 * @param EnrollmentService  $enrollments  Enrollment service.
	 * @param ProgressCalculator $calculator   Progress calculator.
	 * @param AccessControl      $access       Access control.
	 * @param QuizService        $quizzes      Quiz service.
	 * @param CertificateService $certificates Certificate service.
	 */
	public function __construct(
		CourseStructure $structure,
		EnrollmentService $enrollments,
		ProgressCalculator $calculator,
		AccessControl $access,
		QuizService $quizzes,
		CertificateService $certificates
	) {
		$this->structure    = $structure;
		$this->enrollments  = $enrollments;
		$this->calculator   = $calculator;
		$this->access       = $access;
		$this->quizzes      = $quizzes;
		$this->certificates = $certificates;
	}

	/**
	 * Course page additions: notice, enrollment box and outline.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function course_overview( int $course_id ): string {
		if ( ! $this->is_course( $course_id ) ) {
			return '';
		}
		$html = $this->notice() . $this->enroll_button( $course_id ) . $this->course_outline( $course_id, true );
		return '<div class="dlms dlms-course-overview">' . $html . '</div>';
	}

	/**
	 * Enrollment box: log in / enroll button, or progress and "continue".
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function enroll_button( int $course_id ): string {
		if ( ! $this->is_course( $course_id ) ) {
			return '';
		}
		Assets::enqueue();

		$user_id    = get_current_user_id();
		$course_url = (string) get_permalink( $course_id );
		$enrollment = $this->enrollments->get( $user_id, $course_id );
		$summary    = $enrollment ? $this->calculator->summary( $user_id, $course_id ) : null;

		if ( ! $user_id ) {
			$state = 'logged_out';
		} elseif ( $enrollment ) {
			$state = ( EnrollmentRepository::STATUS_COMPLETED === $enrollment['status'] || $summary['is_complete'] ) ? 'completed' : 'enrolled';
		} elseif ( current_user_can( Capabilities::ENROLL_COURSE, $course_id ) ) {
			$state = 'can_enroll';
		} elseif ( $this->access->can_manage_course( $user_id, $course_id ) ) {
			$state = 'manager';
		} else {
			$state = 'not_available';
		}

		$html = Templates::render(
			'course/enroll-button.php',
			array(
				'course_id'       => $course_id,
				'course_title'    => get_the_title( $course_id ),
				'state'           => $state,
				'login_url'       => wp_login_url( $course_url ),
				'register_url'    => get_option( 'users_can_register' ) ? wp_registration_url() : '',
				'action_url'      => admin_url( 'admin-post.php' ),
				'nonce_action'    => FormHandler::enroll_nonce_action( $course_id ),
				'continue_url'    => $summary ? $this->continue_url( $user_id, $course_id, $summary ) : '',
				'has_progress'    => $summary && $summary['completed'] > 0,
				'first_step'      => $this->first_step_url( $course_id ),
				'certificate_url' => $this->certificate_url( $user_id, $course_id ),
				'summary'         => $summary,
			)
		);

		if ( $summary ) {
			$html .= $this->progress_bar( $course_id, true );
		}

		return $html;
	}

	/**
	 * Course outline with completion and lock state per lesson/topic/quiz.
	 *
	 * @param int  $course_id   Course ID.
	 * @param bool $show_topics Whether to list topics and quizzes under lessons.
	 * @return string
	 */
	public function course_outline( int $course_id, bool $show_topics = true ): string {
		if ( ! $this->is_course( $course_id ) ) {
			return '';
		}
		Assets::enqueue();

		$user_id  = get_current_user_id();
		$current  = Context::current_step_id();
		$tree     = $this->structure->get_tree( $course_id );
		$headings = $this->structure->get_headings( $course_id );
		$lessons  = array();

		foreach ( $tree['lessons'] as $lesson ) {
			$item             = $this->step_item( $user_id, $lesson['id'], $current );
			$item['headings'] = array_column( $headings[ $lesson['id'] ] ?? array(), 'title' );
			$item['topics']   = array();
			$item['quizzes']  = array();
			if ( $show_topics ) {
				foreach ( $lesson['topics'] as $topic_id ) {
					$topic            = $this->step_item( $user_id, $topic_id, $current );
					$topic['quizzes'] = $this->step_items( $user_id, $tree['children'][ $topic_id ] ?? array(), $current );
					$item['topics'][] = $topic;
				}
				$item['quizzes'] = $this->step_items( $user_id, $lesson['quizzes'], $current );
			}
			$lessons[] = $item;
		}

		return Templates::render(
			'course/outline.php',
			array(
				'course_id'    => $course_id,
				'course_title' => get_the_title( $course_id ),
				'lessons'      => $lessons,
				'quizzes'      => $this->step_items( $user_id, $tree['quizzes'], $current ),
				'end_headings' => array_column( $headings[0] ?? array(), 'title' ),
				'show_topics'  => $show_topics,
			)
		);
	}

	/**
	 * Progress bar for the current user (empty when not enrolled).
	 *
	 * @param int  $course_id  Course ID.
	 * @param bool $show_label Whether to print "x of y steps".
	 * @return string
	 */
	public function progress_bar( int $course_id, bool $show_label = true ): string {
		$user_id = get_current_user_id();
		if ( ! $this->is_course( $course_id ) || ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return '';
		}
		Assets::enqueue();

		$summary = $this->calculator->summary( $user_id, $course_id );

		return Templates::render(
			'course/progress-bar.php',
			array(
				'course_id'    => $course_id,
				'course_title' => get_the_title( $course_id ),
				'percent'      => $summary['percent'],
				'completed'    => $summary['completed'],
				'total'        => $summary['total'],
				'show_label'   => $show_label,
			)
		);
	}

	/**
	 * "Mark complete" control for a lesson or topic (quizzes use quiz_view()).
	 *
	 * @param int $step_id Step ID.
	 * @return string
	 */
	public function mark_complete( int $step_id ): string {
		$type = get_post_type( $step_id );
		if ( PostTypes::LESSON !== $type && PostTypes::TOPIC !== $type ) {
			return '';
		}

		$user_id = get_current_user_id();
		$access  = $this->access->check( $user_id, $step_id );
		if ( ! $access->allowed ) {
			return '';
		}
		Assets::enqueue();

		if ( ! $this->enrollments->is_enrolled( $user_id, $access->course_id ) || ! $this->structure->contains( $access->course_id, $step_id ) ) {
			$state = 'preview';
		} elseif ( $this->calculator->is_step_complete( $user_id, $step_id ) ) {
			$state = 'completed';
		} elseif ( ! $this->calculator->is_manually_completable( $step_id ) ) {
			$state = 'auto';
		} else {
			$state = 'can_complete';
		}

		return Templates::render(
			'step/mark-complete.php',
			array(
				'step_id'      => $step_id,
				'step_type'    => $type,
				'state'        => $state,
				'has_topics'   => PostTypes::LESSON === $type && array() !== $this->structure->get_topics( $step_id ),
				'action_url'   => admin_url( 'admin-post.php' ),
				'nonce_action' => FormHandler::complete_nonce_action( $step_id ),
			)
		);
	}

	/**
	 * What's inside a lesson (topics with their quizzes, then lesson quizzes)
	 * or a topic (its quizzes), shown on the lesson/topic page.
	 *
	 * @param int $step_id Lesson or topic ID.
	 * @return string
	 */
	public function step_children( int $step_id ): string {
		$children = $this->structure->get_children( $step_id );
		if ( array() === $children ) {
			return '';
		}
		Assets::enqueue();

		$user_id = get_current_user_id();
		$tree    = $this->structure->get_tree( $this->structure->get_course_id( $step_id ) );
		$items   = array();
		foreach ( $children as $child_id ) {
			$item            = $this->step_item( $user_id, $child_id, 0 );
			$item['quizzes'] = $this->step_items( $user_id, $tree['children'][ $child_id ] ?? array(), 0 );
			$items[]         = $item;
		}

		return Templates::render(
			'step/topics.php',
			array(
				'step_id'   => $step_id,
				'step_type' => get_post_type( $step_id ),
				'topics'    => $items,
			)
		);
	}

	/**
	 * Quiz page: the form, or the result of an attempt, plus attempt info.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return string
	 */
	public function quiz_view( int $quiz_id ): string {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return '';
		}
		$user_id = get_current_user_id();
		$access  = $this->access->check( $user_id, $quiz_id );
		if ( ! $access->allowed ) {
			return '';
		}
		Assets::enqueue();

		$settings = $this->quizzes->settings( $quiz_id );
		$enrolled = $this->enrollments->is_enrolled( $user_id, $access->course_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; ownership is checked by get_attempt().
		$attempt_id = isset( $_GET['dlms_attempt'] ) ? absint( $_GET['dlms_attempt'] ) : 0;
		$attempt    = $attempt_id ? $this->quizzes->get_attempt( $user_id, $attempt_id ) : null;
		if ( $attempt && ! in_array( $attempt['quiz_id'], array( $quiz_id, Multilingual::canonical_id( $quiz_id, PostTypes::QUIZ ) ), true ) ) {
			$attempt = null;
		}

		$can_attempt = $enrolled ? $this->quizzes->can_attempt( $user_id, $quiz_id ) : null;
		$passed      = $enrolled && $this->quizzes->has_passed( $user_id, $quiz_id );
		$best        = $enrolled ? $this->quizzes->best_attempt( $user_id, $quiz_id ) : null;
		$next_step   = $this->structure->get_next_step( $access->course_id, $quiz_id );
		$mode        = ! $enrolled ? 'preview' : ( $attempt ? 'result' : ( true === $can_attempt ? 'form' : 'closed' ) );
		// The clock starts when an enrolled student gets the form; their random
		// questions are kept until they submit. Previews draw anew each time.
		$remaining = 'form' === $mode ? $this->quizzes->start_timer( $user_id, $quiz_id ) : null;
		$questions = $this->quizzes->playable_questions( $quiz_id, 'form' === $mode ? $user_id : 0 );

		return Templates::render(
			'quiz/quiz.php',
			array(
				'quiz_id'            => $quiz_id,
				'questions'          => Questions::public_view( $questions ),
				'pass_mark'          => $settings['pass_mark'],
				'attempts_limit'     => $settings['attempts_limit'],
				'attempts_used'      => $enrolled ? count( $this->quizzes->attempts( $user_id, $quiz_id ) ) : 0,
				'attempts_remaining' => $enrolled ? $this->quizzes->attempts_remaining( $user_id, $quiz_id ) : null,
				'mode'               => $mode,
				'time_limit'         => $settings['time_limit'],
				'time_remaining'     => $remaining,
				'time_up_seconds'    => QuizService::TIME_UP_SECONDS,
				'closed_message'     => is_wp_error( $can_attempt ) ? $can_attempt->get_error_message() : '',
				'passed'             => $passed,
				'best_percent'       => $best ? $best['percent'] : null,
				'attempt'            => $attempt,
				'details'            => $attempt ? $this->quizzes->result_details( $quiz_id, $attempt ) : array(),
				'can_retake'         => true === $can_attempt,
				'retake_url'         => (string) get_permalink( $quiz_id ),
				'next_url'           => ( $passed && $next_step ) ? (string) get_permalink( $next_step ) : '',
				'course_url'         => (string) get_permalink( $access->course_id ),
				'action_url'         => admin_url( 'admin-post.php' ),
				'nonce_action'       => FormHandler::quiz_nonce_action( $quiz_id ),
			)
		);
	}

	/**
	 * Previous / next / back-to-course navigation.
	 *
	 * @param int $step_id Step ID.
	 * @return string
	 */
	public function step_navigation( int $step_id ): string {
		$course_id = $this->structure->get_course_id( $step_id );
		if ( ! $this->is_course( $course_id ) ) {
			return '';
		}
		Assets::enqueue();

		$user_id  = get_current_user_id();
		$previous = $this->structure->get_previous_step( $course_id, $step_id );
		$next     = $this->structure->get_next_step( $course_id, $step_id );

		return Templates::render(
			'step/navigation.php',
			array(
				'course_title' => get_the_title( $course_id ),
				'course_url'   => get_permalink( $course_id ),
				'previous'     => $previous ? $this->nav_item( $user_id, $previous ) : null,
				'next'         => $next ? $this->nav_item( $user_id, $next ) : null,
			)
		);
	}

	/**
	 * Message shown instead of a step the user may not open.
	 *
	 * @param AccessResult $result  Access decision.
	 * @param int          $step_id Step ID.
	 * @return string
	 */
	public function locked_message( AccessResult $result, int $step_id ): string {
		Assets::enqueue();

		$course_id = $result->course_id;
		$blocking  = $result->blocking_step_id;

		$html = Templates::render(
			'step/locked.php',
			array(
				'reason'         => $result->reason,
				'step_id'        => $step_id,
				'course_title'   => $course_id ? get_the_title( $course_id ) : '',
				'course_url'     => $course_id ? get_permalink( $course_id ) : '',
				'login_url'      => wp_login_url( (string) get_permalink( $step_id ) ),
				'blocking_title' => $blocking ? get_the_title( $blocking ) : '',
				'blocking_url'   => $blocking ? get_permalink( $blocking ) : '',
				'available_on'   => $result->available_at ? $this->format_date( $result->available_at ) : '',
			)
		);

		if ( in_array( $result->reason, array( AccessControl::NOT_LOGGED_IN, AccessControl::NOT_ENROLLED ), true ) ) {
			$html .= $this->enroll_button( $course_id );
		}

		return '<div class="dlms dlms-locked-wrap">' . $html . '</div>';
	}

	/**
	 * Grid of published courses.
	 *
	 * @param array $args { columns: int, per_page: int, orderby: string, show_progress: bool }.
	 * @return string
	 */
	public function course_grid( array $args ): string {
		Assets::enqueue();

		$columns  = max( 1, min( 4, (int) ( $args['columns'] ?? 3 ) ) );
		$per_page = max( 1, min( 48, (int) ( $args['per_page'] ?? 12 ) ) );
		$orderby  = in_array( $args['orderby'] ?? 'date', array( 'date', 'title', 'menu_order' ), true ) ? $args['orderby'] : 'date';
		$user_id  = get_current_user_id();

		$query = new WP_Query(
			array(
				'post_type'           => PostTypes::COURSE,
				'post_status'         => 'publish',
				'posts_per_page'      => $per_page,
				'orderby'             => $orderby,
				'order'               => 'date' === $orderby ? 'DESC' : 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$courses = array();
		foreach ( $query->posts as $course ) {
			$enrolled  = $this->enrollments->is_enrolled( $user_id, $course->ID );
			$summary   = $enrolled && ! empty( $args['show_progress'] ) ? $this->calculator->summary( $user_id, $course->ID ) : null;
			$courses[] = array(
				'id'             => $course->ID,
				'title'          => get_the_title( $course ),
				'url'            => get_permalink( $course ),
				'excerpt'        => get_the_excerpt( $course ),
				'thumbnail_html' => get_the_post_thumbnail( $course, 'medium_large', array( 'loading' => 'lazy' ) ),
				'lesson_count'   => count( $this->structure->get_lessons( $course->ID ) ),
				'enrolled'       => $enrolled,
				'percent'        => $summary ? $summary['percent'] : null,
			);
		}

		return Templates::render(
			'course/grid.php',
			array(
				'courses' => $courses,
				'columns' => $columns,
			)
		);
	}

	/**
	 * Student dashboard: the current user's courses with progress.
	 *
	 * @param array $args { show_completed: bool }.
	 * @return string
	 */
	public function student_dashboard( array $args ): string {
		Assets::enqueue();

		$user_id = get_current_user_id();
		$courses = array();

		foreach ( $this->enrollments->for_user( $user_id ) as $course_id => $enrollment ) {
			$course = get_post( $course_id );
			if ( ! $course instanceof WP_Post || 'publish' !== $course->post_status ) {
				continue;
			}
			$summary = $this->calculator->summary( $user_id, $course_id );
			$done    = EnrollmentRepository::STATUS_COMPLETED === $enrollment['status'] || $summary['is_complete'];
			if ( $done && isset( $args['show_completed'] ) && ! $args['show_completed'] ) {
				continue;
			}
			$courses[] = array(
				'id'              => $course_id,
				'title'           => get_the_title( $course ),
				'url'             => get_permalink( $course ),
				'thumbnail_html'  => get_the_post_thumbnail( $course, 'thumbnail', array( 'loading' => 'lazy' ) ),
				'percent'         => $summary['percent'],
				'completed'       => $summary['completed'],
				'total'           => $summary['total'],
				'is_complete'     => $done,
				'continue_url'    => $this->continue_url( $user_id, $course_id, $summary ),
				'started'         => $summary['completed'] > 0,
				'certificate_url' => $this->certificate_url( $user_id, $course_id ),
			);
		}//end foreach

		return Templates::render(
			'dashboard/dashboard.php',
			array(
				'logged_in'   => $user_id > 0,
				'login_url'   => wp_login_url( (string) get_permalink() ),
				'courses'     => $courses,
				'archive_url' => (string) get_post_type_archive_link( PostTypes::COURSE ),
			)
		);
	}

	/**
	 * Flash notice after a form redirect (whitelisted codes only).
	 *
	 * @return string
	 */
	public function notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a whitelisted message code.
		$code     = isset( $_GET['dlms_notice'] ) ? sanitize_key( wp_unslash( $_GET['dlms_notice'] ) ) : '';
		$messages = array(
			'enrolled'         => array( 'success', __( 'You are enrolled. Have fun learning!', 'deutschlms' ) ),
			'course_completed' => array( 'success', __( 'Congratulations, you have completed this course!', 'deutschlms' ) ),
			'enroll_failed'    => array( 'error', __( 'Enrollment failed. Please try again.', 'deutschlms' ) ),
			'complete_failed'  => array( 'error', __( 'Your progress could not be saved. Please try again.', 'deutschlms' ) ),
			'quiz_failed'      => array( 'error', __( 'Your answers could not be submitted. Please try again.', 'deutschlms' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}

		$certificate_url = '';
		if ( 'course_completed' === $code ) {
			$course_id       = Context::current_course_id();
			$certificate_url = $course_id ? $this->certificate_url( get_current_user_id(), $course_id ) : '';
		}

		return Templates::render(
			'notice.php',
			array(
				'type'            => $messages[ $code ][0],
				'message'         => $messages[ $code ][1],
				'certificate_url' => $certificate_url,
			)
		);
	}

	/**
	 * Where "Continue" should lead: the first unfinished step the user can open,
	 * or the course page when finished.
	 *
	 * @param int   $user_id   User ID.
	 * @param int   $course_id Course ID.
	 * @param array $summary   Progress summary.
	 * @return string
	 */
	public function continue_url( int $user_id, int $course_id, array $summary ): string {
		$next = (int) $summary['next_step_id'];
		if ( $next ) {
			$blocking = $this->access->first_unmet_prerequisite( $user_id, $course_id, $next );
			$url      = get_permalink( $blocking ? $blocking : $next );
			if ( $url ) {
				return $url;
			}
		}
		return (string) get_permalink( $course_id );
	}

	/**
	 * Certificate link if the user has earned it ('' otherwise).
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return string
	 */
	private function certificate_url( int $user_id, int $course_id ): string {
		return ( $user_id && $this->certificates->is_earned( $user_id, $course_id ) ) ? $this->certificates->url( $course_id ) : '';
	}

	/**
	 * URL of a course's first step ('' when empty).
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	private function first_step_url( int $course_id ): string {
		$steps = $this->structure->get_steps( $course_id );
		return $steps ? (string) get_permalink( $steps[0] ) : '';
	}

	/**
	 * Outline items for a list of steps.
	 *
	 * @param int   $user_id    User ID.
	 * @param int[] $step_ids   Step IDs.
	 * @param int   $current_id Step being viewed.
	 * @return array[]
	 */
	private function step_items( int $user_id, array $step_ids, int $current_id ): array {
		return array_map( fn( $id ) => $this->step_item( $user_id, (int) $id, $current_id ), $step_ids );
	}

	/**
	 * Outline/topic list item.
	 *
	 * @param int $user_id    User ID.
	 * @param int $step_id    Step ID.
	 * @param int $current_id Step being viewed.
	 * @return array{id: int, type: string, title: string, url: string, status: string, current: bool, available_on: string}
	 */
	private function step_item( int $user_id, int $step_id, int $current_id ): array {
		$access = $this->access->check( $user_id, $step_id );
		if ( $this->calculator->is_step_complete( $user_id, $step_id ) ) {
			$status = 'complete';
		} elseif ( $access->allowed ) {
			$status = 'available';
		} elseif ( AccessControl::DRIP === $access->reason ) {
			$status = 'scheduled';
		} else {
			$status = 'locked';
		}

		return array(
			'id'           => $step_id,
			'type'         => (string) get_post_type( $step_id ),
			'title'        => get_the_title( $step_id ),
			'url'          => $access->allowed ? (string) get_permalink( $step_id ) : '',
			'status'       => $status,
			'current'      => $step_id === $current_id,
			'available_on' => $access->available_at ? $this->format_date( $access->available_at ) : '',
		);
	}

	/**
	 * Navigation link item.
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Step ID.
	 * @return array{title: string, url: string, locked: bool}
	 */
	private function nav_item( int $user_id, int $step_id ): array {
		$can_view = $this->access->can_view( $user_id, $step_id );
		return array(
			'title'  => get_the_title( $step_id ),
			'url'    => $can_view ? (string) get_permalink( $step_id ) : '',
			'locked' => ! $can_view,
		);
	}

	/**
	 * Date in the site's timezone, in the plugin's language.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function format_date( int $timestamp ): string {
		return Dates::format( $timestamp );
	}

	/**
	 * Whether an ID is a course.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	private function is_course( int $course_id ): bool {
		return $course_id > 0 && PostTypes::COURSE === get_post_type( $course_id );
	}
}
