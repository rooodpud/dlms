<?php
/**
 * Quiz rules.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Quiz;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Integrations\Multilingual;
use DeutschLMS\Progress\ProgressCalculator;
use DeutschLMS\Progress\ProgressService;
use DeutschLMS\Roles\Roles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Taking quizzes: settings, attempts, grading and results.
 *
 * - Grading happens only here, on the server; correct answers never reach the
 *   browser before an attempt is graded (and afterwards only when the quiz is
 *   set to show them).
 * - A quiz counts as complete once the student passes it; later attempts never
 *   undo that.
 * - When an attempts limit is set and used up without passing, the student
 *   can't continue until a manager resets their attempts.
 * - Time limit (minutes, per quiz): the clock starts when the student opens
 *   the quiz form (start_timer(); stored per user and quiz, so reloading the
 *   page doesn't restart it). When it runs out, the page locks the answers and
 *   submits them by itself after TIME_UP_SECONDS. An attempt that reaches the
 *   server later than limit + TIME_UP_SECONDS + GRACE_SECONDS after the start
 *   (or without a recorded start) is graded but stored as late and failed.
 * - Questions come from the question bank (QuestionBank). When a quiz draws
 *   random questions, each student's draw is kept (user meta DRAWS_META)
 *   from the moment they get the form until they submit, so a reload or the
 *   submission sees the same questions; the next attempt draws anew.
 */
final class QuizService {

	/**
	 * Time limit, in minutes, that new quizzes start with.
	 */
	public const DEFAULT_TIME_LIMIT = 8;

	/**
	 * Seconds between "time is up" and the automatic submission.
	 */
	public const TIME_UP_SECONDS = 15;

	/**
	 * Extra seconds allowed for slow connections before an attempt is late.
	 */
	public const GRACE_SECONDS = 30;

	/**
	 * User meta: canonical quiz ID => Unix time the current attempt started.
	 */
	public const TIMERS_META = '_dlms_quiz_timers';

	/**
	 * User meta: canonical quiz ID => the random questions drawn for the
	 * current attempt (see QuestionBank::resolve()).
	 */
	public const DRAWS_META = '_dlms_quiz_draws';

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Progress service.
	 *
	 * @var ProgressService
	 */
	private ProgressService $progress;

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
	 * Attempt storage.
	 *
	 * @var AttemptRepository
	 */
	private AttemptRepository $attempts;

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private QuestionBank $bank;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure    $structure  Structure reader.
	 * @param ProgressService    $progress   Progress service.
	 * @param ProgressCalculator $calculator Progress calculator.
	 * @param AccessControl      $access     Access control.
	 * @param AttemptRepository  $attempts   Attempt storage.
	 * @param QuestionBank       $bank       Question bank.
	 */
	public function __construct(
		CourseStructure $structure,
		ProgressService $progress,
		ProgressCalculator $calculator,
		AccessControl $access,
		AttemptRepository $attempts,
		QuestionBank $bank
	) {
		$this->structure  = $structure;
		$this->progress   = $progress;
		$this->calculator = $calculator;
		$this->access     = $access;
		$this->attempts   = $attempts;
		$this->bank       = $bank;
	}

	/**
	 * The quiz's linked questions (normalized, incl. incomplete ones; random
	 * rules are not included).
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array
	 */
	public function questions( int $quiz_id ): array {
		return $this->bank->fixed_questions( $quiz_id );
	}

	/**
	 * Questions students see and are graded on. For a user, random questions
	 * stay the same until they submit; without a user (previews) every call
	 * draws anew.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @param int $user_id User taking the quiz (0 = none).
	 * @return array
	 */
	public function playable_questions( int $quiz_id, int $user_id = 0 ): array {
		if ( $user_id <= 0 ) {
			return $this->bank->resolve( $quiz_id )['questions'];
		}
		$draws    = $this->draws( $user_id );
		$key      = $this->canonical( $quiz_id );
		$resolved = $this->bank->resolve( $quiz_id, $draws[ $key ] ?? null );
		if ( null !== $resolved['draw'] && ( $draws[ $key ] ?? null ) !== $resolved['draw'] ) {
			$draws[ $key ] = $resolved['draw'];
			update_user_meta( $user_id, self::DRAWS_META, $draws );
		}
		return $resolved['questions'];
	}

	/**
	 * How many questions students get.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function question_count( int $quiz_id ): int {
		return $this->bank->question_count( $quiz_id );
	}

	/**
	 * Quiz settings.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array{pass_mark: int, attempts_limit: int, show_answers: bool, time_limit: int}
	 */
	public function settings( int $quiz_id ): array {
		$pass_mark = get_post_meta( $quiz_id, Meta::PASS_MARK, true );
		return array(
			'pass_mark'      => '' === $pass_mark ? 80 : max( 0, min( 100, (int) $pass_mark ) ),
			'attempts_limit' => absint( get_post_meta( $quiz_id, Meta::ATTEMPTS_LIMIT, true ) ),
			'show_answers'   => (bool) get_post_meta( $quiz_id, Meta::SHOW_ANSWERS, true ),
			'time_limit'     => min( 600, absint( get_post_meta( $quiz_id, Meta::TIME_LIMIT, true ) ) ),
		);
	}

	/**
	 * Starts (or continues) the clock for the user's next attempt and returns
	 * the seconds left: negative while "time is up" is showing, null when the
	 * quiz has no time limit. A clock that ran out completely (past the
	 * automatic submission and the grace period) starts again, e.g. when the
	 * student left the page and comes back later.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int|null
	 */
	public function start_timer( int $user_id, int $quiz_id ): ?int {
		$limit = $this->settings( $quiz_id )['time_limit'] * MINUTE_IN_SECONDS;
		if ( $limit <= 0 || $user_id <= 0 ) {
			return null;
		}
		$now    = time();
		$timers = $this->timers( $user_id );
		$key    = $this->canonical( $quiz_id );
		$start  = $timers[ $key ] ?? 0;
		if ( ! $start || $now > $start + $limit + self::TIME_UP_SECONDS + self::GRACE_SECONDS ) {
			$start          = $now;
			$timers[ $key ] = $start;
			update_user_meta( $user_id, self::TIMERS_META, $timers );
		}
		return $start + $limit - $now;
	}

	/**
	 * When the user's current attempt started (0 = no clock running).
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int Unix time.
	 */
	public function timer_start( int $user_id, int $quiz_id ): int {
		return $this->timers( $user_id )[ $this->canonical( $quiz_id ) ] ?? 0;
	}

	/**
	 * A user's counted attempts, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID (any language).
	 * @return array[]
	 */
	public function attempts( int $user_id, int $quiz_id ): array {
		return $this->attempts->for_user_quiz( $user_id, $this->canonical( $quiz_id ) );
	}

	/**
	 * Attempts left (null = unlimited).
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int|null
	 */
	public function attempts_remaining( int $user_id, int $quiz_id ): ?int {
		$limit = $this->settings( $quiz_id )['attempts_limit'];
		if ( 0 === $limit ) {
			return null;
		}
		return max( 0, $limit - count( $this->attempts( $user_id, $quiz_id ) ) );
	}

	/**
	 * Best counted attempt (highest percent, then most recent).
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array|null
	 */
	public function best_attempt( int $user_id, int $quiz_id ): ?array {
		$best = null;
		foreach ( $this->attempts( $user_id, $quiz_id ) as $attempt ) {
			if ( null === $best || $attempt['percent'] > $best['percent'] ) {
				$best = $attempt;
			}
		}
		return $best;
	}

	/**
	 * Whether the user has passed the quiz.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return bool
	 */
	public function has_passed( int $user_id, int $quiz_id ): bool {
		return $this->calculator->has_record( $user_id, $quiz_id );
	}

	/**
	 * Whether the user may submit an attempt now.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return true|WP_Error
	 */
	public function can_attempt( int $user_id, int $quiz_id ) {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return new WP_Error( 'dlms_invalid_quiz', __( 'Invalid quiz.', 'deutschlms' ), array( 'status' => 404 ) );
		}

		$course_id = $this->progress->check_can_progress( $user_id, $quiz_id );
		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		if ( 0 === $this->question_count( $quiz_id ) ) {
			return new WP_Error( 'dlms_quiz_empty', __( 'This quiz has no questions yet.', 'deutschlms' ), array( 'status' => 409 ) );
		}

		if ( 0 === $this->attempts_remaining( $user_id, $quiz_id ) ) {
			return new WP_Error( 'dlms_no_attempts_left', __( 'You have used all attempts for this quiz. Please contact your instructor.', 'deutschlms' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Grades and stores an attempt; a pass completes the quiz (and any parent
	 * topic/lesson/course that is now complete).
	 *
	 * @param int   $user_id User ID.
	 * @param int   $quiz_id Quiz ID.
	 * @param array $answers Question ID => selected answer IDs.
	 * @return array|WP_Error
	 */
	public function submit( int $user_id, int $quiz_id, array $answers ) {
		$allowed = $this->can_attempt( $user_id, $quiz_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$questions = $this->playable_questions( $quiz_id, $user_id );
		$settings  = $this->settings( $quiz_id );
		$graded    = Grader::grade( $questions, self::clean_answers( $answers ) );
		$passed    = Grader::passes( $graded['score'], $graded['max_score'], $settings['pass_mark'] );
		$course_id = $this->structure->get_course_id( $quiz_id );

		$started = 0;
		$late    = false;
		if ( $settings['time_limit'] > 0 ) {
			$started = $this->timer_start( $user_id, $quiz_id );
			$late    = ! $started || time() > $started + $settings['time_limit'] * MINUTE_IN_SECONDS + self::TIME_UP_SECONDS + self::GRACE_SECONDS;
			$passed  = $passed && ! $late;
		}

		$attempt_id = $this->attempts->insert(
			array(
				'user_id'    => $user_id,
				'quiz_id'    => $this->canonical( $quiz_id ),
				'course_id'  => Multilingual::canonical_id( $course_id, PostTypes::COURSE ),
				'score'      => $graded['score'],
				'max_score'  => $graded['max_score'],
				'percent'    => $graded['percent'],
				'passed'     => $passed,
				'answers'    => $graded['results'],
				'started_at' => $started,
				'late'       => $late,
			)
		);
		if ( ! $attempt_id ) {
			return new WP_Error( 'dlms_attempt_failed', __( 'Your answers could not be saved. Please try again.', 'deutschlms' ), array( 'status' => 500 ) );
		}
		$this->stop_timer( $user_id, $quiz_id );
		$this->forget_draw( $user_id, $quiz_id );

		$progress = null;
		if ( $passed ) {
			$progress = $this->progress->record_quiz_passed( $user_id, $quiz_id );
		}

		/**
		 * Fires after a quiz attempt was graded and stored.
		 *
		 * @param int   $user_id    User ID.
		 * @param int   $quiz_id    Quiz ID.
		 * @param int   $attempt_id Attempt ID.
		 * @param bool  $passed     Whether the attempt passed.
		 * @param float $percent    Score in percent.
		 */
		do_action( 'dlms_quiz_attempted', $user_id, $quiz_id, $attempt_id, $passed, $graded['percent'] );

		return array(
			'attempt_id'         => $attempt_id,
			'quiz_id'            => $quiz_id,
			'course_id'          => $course_id,
			'score'              => $graded['score'],
			'max_score'          => $graded['max_score'],
			'percent'            => $graded['percent'],
			'pass_mark'          => $settings['pass_mark'],
			'passed'             => $passed,
			'late'               => $late,
			'attempts_remaining' => $this->attempts_remaining( $user_id, $quiz_id ),
			'course_completed'   => $progress ? $progress['course_completed'] : false,
			'next_step_id'       => $passed ? $this->structure->get_next_step( $course_id, $quiz_id ) : 0,
			'result_url'         => $this->result_url( $quiz_id, $attempt_id ),
		);
	}

	/**
	 * Grades a trial by a course manager: nothing is stored, no clock runs
	 * and no progress is recorded.
	 *
	 * @param int        $quiz_id Quiz ID.
	 * @param array      $answers Question ID => answer IDs or typed answers.
	 * @param array|null $draw    The random draw the form was built from (see QuestionBank::resolve()).
	 * @return array{questions: array, attempt: array, details: array}
	 */
	public function trial( int $quiz_id, array $answers, ?array $draw ): array {
		$questions = $this->bank->resolve( $quiz_id, $draw )['questions'];
		$graded    = Grader::grade( $questions, self::clean_answers( $answers ) );
		$attempt   = array(
			'id'        => 0,
			'quiz_id'   => $quiz_id,
			'score'     => $graded['score'],
			'max_score' => $graded['max_score'],
			'percent'   => $graded['percent'],
			'passed'    => Grader::passes( $graded['score'], $graded['max_score'], $this->settings( $quiz_id )['pass_mark'] ),
			'late'      => false,
			'answers'   => $graded['results'],
		);
		return array(
			'questions' => $questions,
			'attempt'   => $attempt,
			'details'   => $this->result_details( $quiz_id, $attempt ),
		);
	}

	/**
	 * A random draw as a form field value, so a trial is graded on the
	 * questions it showed ('' when the quiz has no random questions).
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array{questions: array, draw: string}
	 */
	public function trial_questions( int $quiz_id ): array {
		$resolved = $this->bank->resolve( $quiz_id );
		return array(
			'questions' => $resolved['questions'],
			'draw'      => null === $resolved['draw'] ? '' : (string) wp_json_encode( $resolved['draw'] ),
		);
	}

	/**
	 * The user's running quiz clocks.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int> Canonical quiz ID => start time.
	 */
	private function timers( int $user_id ): array {
		$timers = get_user_meta( $user_id, self::TIMERS_META, true );
		return is_array( $timers ) ? array_map( 'intval', $timers ) : array();
	}

	/**
	 * The user's current random draws.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array> Canonical quiz ID => draw.
	 */
	private function draws( int $user_id ): array {
		$draws = get_user_meta( $user_id, self::DRAWS_META, true );
		return is_array( $draws ) ? $draws : array();
	}

	/**
	 * Forgets the draw after an attempt, so the next attempt draws anew.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 */
	private function forget_draw( int $user_id, int $quiz_id ): void {
		$draws = $this->draws( $user_id );
		$key   = $this->canonical( $quiz_id );
		if ( isset( $draws[ $key ] ) ) {
			unset( $draws[ $key ] );
			update_user_meta( $user_id, self::DRAWS_META, $draws );
		}
	}

	/**
	 * Forgets the clock after an attempt, so the next attempt starts fresh.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 */
	private function stop_timer( int $user_id, int $quiz_id ): void {
		$timers = $this->timers( $user_id );
		$key    = $this->canonical( $quiz_id );
		if ( isset( $timers[ $key ] ) ) {
			unset( $timers[ $key ] );
			update_user_meta( $user_id, self::TIMERS_META, $timers );
		}
	}

	/**
	 * An attempt, if the viewer may see it (their own, or they manage the course).
	 *
	 * @param int $viewer_id  Viewing user.
	 * @param int $attempt_id Attempt ID.
	 * @return array|null
	 */
	public function get_attempt( int $viewer_id, int $attempt_id ): ?array {
		$attempt = $this->attempts->find( $attempt_id );
		if ( null === $attempt || $viewer_id <= 0 ) {
			return null;
		}
		if ( $attempt['user_id'] === $viewer_id || $this->access->can_manage_course( $viewer_id, $attempt['course_id'] ) ) {
			return $attempt;
		}
		return null;
	}

	/**
	 * Per-question result details for display, in the order the questions were
	 * answered. Questions are looked up in the quiz, then in the bank (random
	 * questions, or ones removed from the quiz since). Correct answers and
	 * explanations are included only when the quiz is set to show them.
	 *
	 * @param int   $quiz_id Quiz ID.
	 * @param array $attempt Attempt row.
	 * @return array[] List of { type, text, correct, points, awarded, answers, gaps, given, solution, explanation }:
	 *                 - choice: `answers` = [ { label, selected, is_correct|null } ];
	 *                 - gap-fill: `text` has the gaps as "…", `segments` the text around them,
	 *                   `gaps` = [ { given, is_correct, solution|'' } ];
	 *                 - word order: `given` = the student's sentence, `solution` = the
	 *                   correct sentence ('' unless shown).
	 */
	public function result_details( int $quiz_id, array $attempt ): array {
		$show    = $this->settings( $quiz_id )['show_answers'];
		$details = array();
		$known   = array_column( $this->questions( $quiz_id ), null, 'id' );

		foreach ( (array) ( $attempt['answers'] ?? array() ) as $key => $result ) {
			$question = $known[ $key ] ?? $this->bank->find_by_key( (string) $key );
			if ( null === $question || ! is_array( $result ) ) {
				continue;
			}
			$selected = array_map( 'strval', (array) ( $result['selected'] ?? array() ) );
			$detail   = array(
				'type'        => $question['type'],
				'text'        => $question['text'],
				'correct'     => ! empty( $result['correct'] ),
				'points'      => (int) ( $result['points'] ?? 0 ),
				'awarded'     => (int) ( $result['awarded'] ?? 0 ),
				'answers'     => array(),
				'segments'    => array(),
				'gaps'        => array(),
				'given'       => '',
				'solution'    => '',
				'picture'     => '',
				'article'     => '',
				'explanation' => $show ? $question['explanation'] : '',
			);

			if ( Questions::TYPE_FILL_BLANK === $question['type'] ) {
				$segments           = Questions::segments( $question['text'] );
				$detail['text']     = implode( '…', $segments );
				$detail['segments'] = $segments;
				$marks              = (array) ( $result['gaps'] ?? array() );
				foreach ( Questions::gaps( $question['text'] ) as $index => $accepted ) {
					$detail['gaps'][] = array(
						'given'      => $selected[ $index ] ?? '',
						'is_correct' => ! empty( $marks[ $index ] ),
						'solution'   => $show ? implode( ' / ', $accepted ) : '',
					);
				}
			} elseif ( Questions::TYPE_WORD_ORDER === $question['type'] ) {
				$texts  = array_column( $question['answers'], 'text', 'id' );
				$blocks = array();
				foreach ( $selected as $id ) {
					if ( isset( $texts[ $id ] ) ) {
						$blocks[] = $texts[ $id ];
					}
				}
				// Shown like the students build it: capital letter, end mark once complete.
				$ending             = Questions::sentence_end( $question );
				$given              = Questions::join_blocks( $blocks );
				$detail['given']    = count( $blocks ) === count( $question['answers'] ) ? Questions::finish( $given, $ending ) : $given;
				$detail['solution'] = $show ? Questions::finish( Questions::solution_sentence( $question ), $ending ) : '';
			} else {
				if ( Questions::TYPE_ARTICLE === $question['type'] ) {
					$detail['picture'] = Questions::picture( $question );
					// The noun's colour gives the answer away: only when answers are shown or it was right.
					$detail['article'] = $show || $detail['correct'] ? Questions::article_of( $question ) : '';
				}
				foreach ( $question['answers'] as $answer ) {
					$detail['answers'][] = array(
						'label'      => Questions::answer_label( $question, $answer ),
						'selected'   => in_array( $answer['id'], $selected, true ),
						'is_correct' => $show ? ! empty( $answer['correct'] ) : null,
					);
				}
			}//end if

			$details[] = $detail;
		}//end foreach

		return $details;
	}

	/**
	 * Resets a user's attempts on a quiz (they no longer count toward the
	 * limit). Allowed for LMS managers and whoever can edit the quiz's course.
	 *
	 * @param int $actor_id Acting user.
	 * @param int $user_id  Student.
	 * @param int $quiz_id  Quiz ID.
	 * @return int|WP_Error Number of attempts reset.
	 */
	public function reset_attempts( int $actor_id, int $user_id, int $quiz_id ) {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return new WP_Error( 'dlms_invalid_quiz', __( 'Invalid quiz.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		$course_id = $this->structure->get_course_id( $quiz_id );
		if ( ! user_can( $actor_id, Roles::CAP_MANAGE_ENROLLMENTS ) && ! $this->access->can_manage_course( $actor_id, $course_id ) ) {
			return new WP_Error( 'dlms_forbidden', __( 'Sorry, you are not allowed to reset quiz attempts.', 'deutschlms' ), array( 'status' => 403 ) );
		}

		$count = $this->attempts->reset( $user_id, $this->canonical( $quiz_id ) );

		/**
		 * Fires after a user's quiz attempts were reset.
		 *
		 * @param int $user_id  Student.
		 * @param int $quiz_id  Quiz ID.
		 * @param int $actor_id Acting user.
		 */
		do_action( 'dlms_quiz_attempts_reset', $user_id, $quiz_id, $actor_id );

		return $count;
	}

	/**
	 * Per-student attempt summary for a quiz (admin views).
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array[]
	 */
	public function student_summary( int $quiz_id ): array {
		return $this->attempts->summary_for_quiz( $this->canonical( $quiz_id ) );
	}

	/**
	 * Every attempt of a user (admin views).
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public function all_attempts_of( int $user_id ): array {
		return $this->attempts->for_user( $user_id );
	}

	/**
	 * Quiz page URL showing an attempt's result.
	 *
	 * @param int $quiz_id    Quiz ID.
	 * @param int $attempt_id Attempt ID.
	 * @return string
	 */
	public function result_url( int $quiz_id, int $attempt_id ): string {
		return add_query_arg( 'dlms_attempt', $attempt_id, (string) get_permalink( $quiz_id ) );
	}

	/**
	 * Normalizes a submitted answer map: question ID => list of values (answer
	 * or block IDs, or typed gap answers). Values keep their position, so an
	 * empty gap still lines up with its index; the grader checks every value
	 * against the question.
	 *
	 * @param array $answers Raw answers.
	 * @return array<string, string[]>
	 */
	public static function clean_answers( array $answers ): array {
		$clean = array();
		foreach ( array_slice( $answers, 0, 200, true ) as $question_id => $values ) {
			$question_id = is_string( $question_id ) ? strtolower( $question_id ) : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{1,39}$/', $question_id ) ) {
				continue;
			}
			$list = array();
			foreach ( array_slice( array_values( (array) $values ), 0, 20 ) as $value ) {
				$list[] = is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 100 ) : '';
			}
			$clean[ $question_id ] = $list;
		}
		return $clean;
	}

	/**
	 * Canonical (default-language) quiz ID.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	private function canonical( int $quiz_id ): int {
		return Multilingual::canonical_id( $quiz_id, PostTypes::QUIZ );
	}
}
