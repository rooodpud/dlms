<?php
/**
 * Meta keys.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta keys. Underscore-prefixed so they stay out of the Custom Fields UI.
 */
final class Meta {

	/**
	 * Parent course of a lesson, topic or quiz.
	 */
	public const COURSE_ID = '_dlms_course_id';

	/**
	 * Parent lesson of a topic.
	 */
	public const LESSON_ID = '_dlms_lesson_id';

	/**
	 * What a quiz is attached to: a lesson or topic ID, or 0 for a course-level
	 * (final) quiz.
	 */
	public const PARENT_ID = '_dlms_parent_id';

	/**
	 * Course setting: steps must be completed in order.
	 */
	public const LINEAR = '_dlms_linear_progression';

	/**
	 * Course setting: section headings between lessons in the outline, as a
	 * list of [ 'id' => string, 'title' => string, 'before' => lesson ID ]
	 * (before = 0: after the last lesson). See CourseStructure::get_headings().
	 */
	public const SECTION_HEADINGS = '_dlms_section_headings';

	/**
	 * Lesson setting: days after enrollment before the lesson unlocks (0 = at once).
	 */
	public const DRIP_DAYS = '_dlms_drip_days';

	/**
	 * Quiz questions (structured array, see Quiz\Questions). Contains the
	 * correct answers, so it is never exposed through REST or page output.
	 */
	public const QUESTIONS = '_dlms_questions';

	/**
	 * Quiz content from the question bank: ordered list of items, either
	 * [ 'kind' => 'question', 'question' => question post ID ] or a random
	 * rule [ 'kind' => 'random', 'count', 'category', 'difficulty', 'level',
	 * 'type' ]. See Quiz\QuestionBank. A quiz without this meta still uses
	 * its own QUESTIONS (kept untouched as a backup once a quiz has items).
	 */
	public const QUIZ_ITEMS = '_dlms_quiz_items';

	/**
	 * Bank question: the question itself, in the same shape as one entry of
	 * QUESTIONS (incl. its stable `id`). Contains the correct answers.
	 */
	public const QUESTION = '_dlms_question';

	/**
	 * Bank question: its stable question ID (`q_…`), for lookups. Attempts
	 * store answers under this ID.
	 */
	public const QUESTION_KEY = '_dlms_question_key';

	/**
	 * Bank question: its type (for filtering).
	 */
	public const QUESTION_TYPE = '_dlms_question_type';

	/**
	 * Bank question: '1' when it is complete enough for students (random
	 * questions are drawn only from these), '0' otherwise.
	 */
	public const QUESTION_READY = '_dlms_question_ready';

	/**
	 * Quiz setting: percentage needed to pass (0–100).
	 */
	public const PASS_MARK = '_dlms_pass_mark';

	/**
	 * Quiz setting: maximum attempts (0 = unlimited).
	 */
	public const ATTEMPTS_LIMIT = '_dlms_attempts_limit';

	/**
	 * Quiz setting: show the correct answers after each attempt.
	 */
	public const SHOW_ANSWERS = '_dlms_show_answers';

	/**
	 * Quiz setting: minutes students have for one attempt (0 = no time limit).
	 */
	public const TIME_LIMIT = '_dlms_time_limit';

	/**
	 * Course setting: certificate on completion.
	 */
	public const CERT_ENABLED = '_dlms_certificate_enabled';

	/**
	 * Course setting: certificate heading.
	 */
	public const CERT_TITLE = '_dlms_certificate_title';

	/**
	 * Course setting: name printed above the signature line.
	 */
	public const CERT_SIGNER = '_dlms_certificate_signer';

	/**
	 * Course setting: background image attachment ID.
	 */
	public const CERT_BACKGROUND = '_dlms_certificate_background';

	/**
	 * Course setting: help languages offered besides German (codes, e.g.
	 * [ 'en', 'tl' ]; empty = no language switch). See HelpLanguage.
	 */
	public const HELP_LANGUAGES = '_dlms_help_languages';

	/**
	 * Course setting: the help language new learners start with ('de' default).
	 */
	public const HELP_DEFAULT = '_dlms_help_default';

	/**
	 * Course setting: CEFR level shown as the group heading in the course grid
	 * ('A1' … 'C2'; empty = no level). See CourseOrder.
	 */
	public const LEVEL = '_dlms_level';

	/**
	 * Course, lesson, topic or quiz: translations of the title shown with the
	 * language switch (language => text, e.g. [ 'en' => 'Words' ]).
	 */
	public const TITLE_HELP = '_dlms_title_help';
}
