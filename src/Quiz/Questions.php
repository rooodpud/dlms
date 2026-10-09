<?php
/**
 * Quiz question normalization.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Quiz;

use DeutschLMS\Content\AudioClips;
use DeutschLMS\Content\NounPictures;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and validates the questions stored on a quiz.
 *
 * Stored shape (post meta `_dlms_questions`):
 *
 *     [
 *       [
 *         'id'           => 'q_ab12cd34',          // stable, unique in the quiz
 *         'type'         => 'single' | 'multiple' | 'true_false' | 'fill_blank' | 'word_order' | 'article',
 *         'text'         => 'Plain text question',
 *         'points'       => 1,
 *         'explanation'  => 'Optional, shown with results',
 *         'answers'      => [ [ 'id' => 'a_ef56gh78', 'text' => '…', 'correct' => bool ], … ],
 *         'alternatives' => [ 'Other accepted sentence', … ], // word_order only
 *         'display'      => 'drag' | 'select',                // word_order only
 *         'picture'      => 'icon:armchair',                  // article only ('' = the noun's own picture)
 *         'listen'       => 'Guten Morgen, Housekeeping!',     // optional: German text to listen to
 *         'audio'        => 123,                               // optional: its recording (audio attachment)
 *       ],
 *       …
 *     ]
 *
 * - True/false questions always have the answers `true` and `false`; their
 *   labels are translated when displayed.
 * - Gap-fill (`fill_blank`) questions keep their gaps inside the text, in curly
 *   braces, with accepted alternatives separated by "|":
 *   `Ich {bin} nach Berlin {gefahren}.` They have no answers.
 * - Word-order (`word_order`) questions store the words/blocks as answers in the
 *   correct order (no `correct` flags); students see them shuffled. Other
 *   accepted orders are listed as whole sentences in `alternatives`. `display`
 *   is how students answer: `drag` (drag or tap word tiles into the sentence,
 *   the default) or `select` (one dropdown per position; a word chosen in one
 *   dropdown is no longer offered in the others). Students see the sentence
 *   start with a capital letter and end with the mark from sentence_end().
 * - Article (`article`) questions ask for the article of a noun: the text is
 *   the noun without its article ("Tisch"), the answers are always `der`,
 *   `die` and `das` (in that order, one marked correct). Students see the noun
 *   with its picture and pick a colour-coded article (der blue, die red, das
 *   green). The picture comes from the noun picture library; `picture` can
 *   name another one (an icon, an image or another noun, see NounPictures).
 *
 * - Listening questions: any question can carry `listen` (a German text the
 *   student hears before answering) and/or `audio` (an audio file). The play
 *   button plays the question's own file, else the text's recording from the
 *   audio library, else the browser reads the text (see AudioClips). The
 *   text is shown with the results when the quiz shows correct answers. Both
 *   keys are only stored when set.
 *
 * Question and answer IDs survive edits, so stored attempts keep pointing at
 * the right answers.
 */
final class Questions {

	public const TYPE_SINGLE     = 'single';
	public const TYPE_MULTIPLE   = 'multiple';
	public const TYPE_TRUE_FALSE = 'true_false';
	public const TYPE_FILL_BLANK = 'fill_blank';
	public const TYPE_WORD_ORDER = 'word_order';
	public const TYPE_ARTICLE    = 'article';

	/**
	 * The answers of an article question, in display order.
	 */
	public const ARTICLES = array( 'der', 'die', 'das' );

	public const DISPLAY_DRAG   = 'drag';
	public const DISPLAY_SELECT = 'select';

	public const MAX_GAPS = 10;

	private const MAX_QUESTIONS    = 200;
	private const MAX_ANSWERS      = 20;
	private const MAX_ALTERNATIVES = 10;
	private const ID_PATTERN       = '/^[a-z][a-z0-9_]{1,39}$/';
	private const GAP_PATTERN      = '/\{([^{}]*)\}/u';

	/**
	 * Question types with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_SINGLE     => __( 'Multiple choice (one correct answer)', 'deutschlms' ),
			self::TYPE_MULTIPLE   => __( 'Multiple choice (several correct answers)', 'deutschlms' ),
			self::TYPE_TRUE_FALSE => __( 'True / false', 'deutschlms' ),
			self::TYPE_FILL_BLANK => __( 'Gap-fill (type the missing words)', 'deutschlms' ),
			self::TYPE_WORD_ORDER => __( 'Word order (put the words in order)', 'deutschlms' ),
			self::TYPE_ARTICLE    => __( 'Article (der, die or das)', 'deutschlms' ),
		);
	}

	/**
	 * Whether students pick from given answers (as opposed to typing or ordering).
	 *
	 * @param array $question Question.
	 * @return bool
	 */
	public static function is_choice( array $question ): bool {
		return in_array( $question['type'], array( self::TYPE_SINGLE, self::TYPE_MULTIPLE, self::TYPE_TRUE_FALSE, self::TYPE_ARTICLE ), true );
	}

	/**
	 * Sanitizes raw question data (from the editor or storage). Keeps every
	 * question that has text, so an author never loses work; use problems() and
	 * playable() to find incomplete ones.
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$questions = array();
		$used_ids  = array();

		foreach ( array_slice( array_values( $raw ), 0, self::MAX_QUESTIONS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$text = self::clean_text( $item['text'] ?? '', 2000, true );
			if ( '' === $text ) {
				continue;
			}

			$type = isset( $item['type'] ) && array_key_exists( $item['type'], self::types() ) ? $item['type'] : self::TYPE_SINGLE;

			$question = array(
				'id'          => self::unique_id( $item['id'] ?? '', 'q_', $used_ids ),
				'type'        => $type,
				'text'        => $text,
				'points'      => max( 1, min( 100, absint( $item['points'] ?? 1 ) ) ),
				'explanation' => self::clean_text( $item['explanation'] ?? '', 2000, true ),
				'answers'     => self::sanitize_answers( $type, $item['answers'] ?? array() ),
			);
			if ( self::TYPE_WORD_ORDER === $type ) {
				$question['alternatives'] = self::sanitize_alternatives( $item['alternatives'] ?? array() );
				$question['display']      = self::display( $item );
			}
			if ( self::TYPE_ARTICLE === $type ) {
				$question['picture'] = self::picture_override( $item, $text );
			}
			$listen = self::clean_text( $item['listen'] ?? '', AudioClips::MAX_TEXT, false );
			if ( '' !== $listen ) {
				$question['listen'] = $listen;
			}
			$audio = absint( $item['audio'] ?? 0 );
			if ( $audio && AudioClips::is_audio( $audio ) ) {
				$question['audio'] = $audio;
			}
			$questions[] = $question;
		}//end foreach

		return $questions;
	}

	/**
	 * Sanitizes one question (a bank question). Null when it has no text.
	 *
	 * @param mixed $raw Raw value.
	 * @return array|null
	 */
	public static function sanitize_one( $raw ): ?array {
		$questions = is_array( $raw ) ? self::sanitize( array( $raw ) ) : array();
		return $questions[0] ?? null;
	}

	/**
	 * Whether a question is complete enough to show to students and grade.
	 *
	 * @param array $question Sanitized question.
	 * @return bool
	 */
	public static function is_playable( array $question ): bool {
		return '' === self::error( $question );
	}

	/**
	 * Human-readable problems that keep questions out of the quiz.
	 *
	 * @param array $questions Sanitized questions.
	 * @return string[]
	 */
	public static function problems( array $questions ): array {
		$problems = array();
		foreach ( $questions as $index => $question ) {
			$error = self::error( $question );
			if ( '' !== $error ) {
				/* translators: 1: question number, 2: problem description. */
				$problems[] = sprintf( __( 'Question %1$d: %2$s', 'deutschlms' ), $index + 1, $error );
			}
		}
		return $problems;
	}

	/**
	 * Questions complete enough to show to students and grade.
	 *
	 * @param array $questions Sanitized questions.
	 * @return array
	 */
	public static function playable( array $questions ): array {
		return array_values( array_filter( $questions, static fn( $question ) => '' === self::error( $question ) ) );
	}

	/**
	 * Questions without anything that gives the solution away, for the quiz form:
	 * no correct-answer flags, explanations or alternatives; gap-fill text is
	 * split into `segments` around the gaps (the answers are removed); word-order
	 * blocks are shuffled.
	 *
	 * @param array $questions Playable questions.
	 * @return array
	 */
	public static function public_view( array $questions ): array {
		return array_map(
			static function ( array $question ): array {
				unset( $question['explanation'], $question['alternatives'] );

				if ( self::TYPE_FILL_BLANK === $question['type'] ) {
					$segments              = self::segments( $question['text'] );
					$question['segments']  = $segments;
					$question['gap_sizes'] = array_map( array( self::class, 'gap_size' ), self::gaps( $question['text'] ) );
					$question['text']      = implode( '…', $segments );
					$question['answers']   = array();
					return $question;
				}

				$answers = array_map(
					static fn( array $answer ): array => array(
						'id'   => $answer['id'],
						'text' => self::answer_label( $question, $answer ),
					),
					$question['answers']
				);
				if ( self::TYPE_WORD_ORDER === $question['type'] ) {
					$answers            = self::shuffled( $answers );
					$question['ending'] = self::sentence_end( $question );
				}
				$question['answers'] = $answers;
				return $question;
			},
			$questions
		);
	}

	/**
	 * Whether students listen to something before answering.
	 *
	 * @param array $question Question.
	 * @return bool
	 */
	public static function has_audio( array $question ): bool {
		return '' !== (string) ( $question['listen'] ?? '' ) || ! empty( $question['audio'] );
	}

	/**
	 * Play button of a listening question ('' for other questions). With a
	 * recording, the text stays out of the page.
	 *
	 * @param array $question Question.
	 * @return string Safe markup.
	 */
	public static function audio_button( array $question ): string {
		if ( ! self::has_audio( $question ) ) {
			return '';
		}
		return AudioClips::button(
			(string) ( $question['listen'] ?? '' ),
			absint( $question['audio'] ?? 0 ),
			array(
				'label'     => __( 'Play the audio for this question', 'deutschlms' ),
				'css_class' => 'dlms-play--question',
				'hide_text' => true,
			)
		);
	}

	/**
	 * How students answer a word-order question: `drag` (default) or `select`.
	 *
	 * @param array $question Question (raw or sanitized).
	 * @return string
	 */
	public static function display( array $question ): string {
		return self::DISPLAY_SELECT === ( $question['display'] ?? '' ) ? self::DISPLAY_SELECT : self::DISPLAY_DRAG;
	}

	/**
	 * Picture of an article question: its own choice, else its noun (a
	 * reference for NounPictures::render()).
	 *
	 * @param array $question Question.
	 * @return string
	 */
	public static function picture( array $question ): string {
		if ( self::TYPE_ARTICLE !== ( $question['type'] ?? '' ) ) {
			return '';
		}
		$own = NounPictures::sanitize_ref( (string) ( $question['picture'] ?? '' ) );
		return '' !== $own ? $own : NounPictures::key_for( (string) ( $question['text'] ?? '' ) );
	}

	/**
	 * The picture an article question names instead of its noun's ('' = the
	 * noun's own picture).
	 *
	 * @param array  $item Raw question.
	 * @param string $noun The question's noun.
	 * @return string
	 */
	private static function picture_override( array $item, string $noun ): string {
		$ref = NounPictures::sanitize_ref( (string) ( $item['picture'] ?? '' ) );
		return NounPictures::key_for( $noun ) === $ref ? '' : $ref;
	}

	/**
	 * The correct article of an article question ('' when none is marked).
	 *
	 * @param array $question Question.
	 * @return string
	 */
	public static function article_of( array $question ): string {
		if ( self::TYPE_ARTICLE !== $question['type'] ) {
			return '';
		}
		return self::correct_ids( $question )[0] ?? '';
	}

	/**
	 * Display label of an answer (true/false labels are translated).
	 *
	 * @param array $question Question.
	 * @param array $answer   Answer.
	 * @return string
	 */
	public static function answer_label( array $question, array $answer ): string {
		if ( self::TYPE_TRUE_FALSE === $question['type'] ) {
			return 'true' === $answer['id'] ? __( 'True', 'deutschlms' ) : __( 'False', 'deutschlms' );
		}
		if ( self::TYPE_ARTICLE === $question['type'] ) {
			return (string) $answer['id'];
		}
		return (string) $answer['text'];
	}

	/**
	 * IDs of a question's correct answers (choice questions).
	 *
	 * @param array $question Question.
	 * @return string[]
	 */
	public static function correct_ids( array $question ): array {
		if ( ! self::is_choice( $question ) ) {
			return array();
		}
		return array_values(
			array_map(
				static fn( $answer ) => $answer['id'],
				array_filter( $question['answers'], static fn( $answer ) => ! empty( $answer['correct'] ) )
			)
		);
	}

	/**
	 * Accepted answers of each gap in a gap-fill text.
	 *
	 * @param string $text Question text with {gaps}.
	 * @return array<int, string[]> One list of accepted answers per gap.
	 */
	public static function gaps( string $text ): array {
		preg_match_all( self::GAP_PATTERN, $text, $matches );
		$gaps = array();
		foreach ( $matches[1] as $inner ) {
			$accepted = array();
			foreach ( explode( '|', $inner ) as $option ) {
				$option = trim( preg_replace( '/\s+/u', ' ', $option ) );
				if ( '' !== $option && ! in_array( $option, $accepted, true ) ) {
					$accepted[] = $option;
				}
			}
			$gaps[] = $accepted;
		}
		return $gaps;
	}

	/**
	 * The text around the gaps (one more segment than there are gaps).
	 *
	 * @param string $text Question text with {gaps}.
	 * @return string[]
	 */
	public static function segments( string $text ): array {
		return preg_split( self::GAP_PATTERN, $text );
	}

	/**
	 * The words/blocks of a word-order question joined in the correct order.
	 *
	 * @param array $question Word-order question.
	 * @return string
	 */
	public static function solution_sentence( array $question ): string {
		return self::join_blocks( array_column( $question['answers'], 'text' ) );
	}

	/**
	 * Joins word-order blocks into a sentence, starting with a capital letter.
	 *
	 * @param string[] $blocks Block texts in order.
	 * @return string
	 */
	public static function join_blocks( array $blocks ): string {
		$sentence = trim( implode( ' ', array_map( 'trim', $blocks ) ) );
		if ( '' === $sentence ) {
			return '';
		}
		return mb_strtoupper( mb_substr( $sentence, 0, 1 ) ) . mb_substr( $sentence, 1 );
	}

	/**
	 * How a word-order sentence ends: ".", "?" or "!", from the question text
	 * (the sentence to translate; a trailing note in brackets is skipped), so
	 * "Did you visit your grandma?" gives "?" and "Brush your teeth!" gives
	 * "!". Anything else gives ".".
	 *
	 * @param array $question Word-order question.
	 * @return string
	 */
	public static function sentence_end( array $question ): string {
		$text = rtrim( (string) preg_replace( '/\s*\([^()]*\)\s*$/u', '', (string) ( $question['text'] ?? '' ) ) );
		$last = mb_substr( $text, -1 );
		return in_array( $last, array( '?', '!' ), true ) ? $last : '.';
	}

	/**
	 * A block shown at the start of the sentence: first letter upper case.
	 *
	 * @param string $text Block text.
	 * @return string
	 */
	public static function capitalize( string $text ): string {
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * A block (or sentence) shown at the end: a trailing comma, semicolon or
	 * colon is replaced by the end mark; one that already ends with ., ? or !
	 * is left alone.
	 *
	 * @param string $text   Block text or sentence.
	 * @param string $ending ".", "?" or "!".
	 * @return string
	 */
	public static function finish( string $text, string $ending ): string {
		$text = rtrim( (string) preg_replace( '/[,;:]\s*$/u', '', rtrim( $text ) ) );
		if ( '' === $text || in_array( mb_substr( $text, -1 ), array( '.', '?', '!' ), true ) ) {
			return $text;
		}
		return $text . $ending;
	}

	/**
	 * What's wrong with a question ('' when playable).
	 *
	 * @param array $question Question.
	 * @return string
	 */
	private static function error( array $question ): string {
		if ( self::TYPE_FILL_BLANK === $question['type'] ) {
			$gaps = self::gaps( $question['text'] );
			if ( array() === $gaps ) {
				return __( 'put at least one gap in curly braces, e.g. Ich {bin} müde.', 'deutschlms' );
			}
			if ( count( $gaps ) > self::MAX_GAPS ) {
				/* translators: %d: maximum number of gaps. */
				return sprintf( __( 'use at most %d gaps per question.', 'deutschlms' ), self::MAX_GAPS );
			}
			foreach ( $gaps as $accepted ) {
				if ( array() === $accepted ) {
					return __( 'every gap needs its answer inside the braces.', 'deutschlms' );
				}
			}
			return '';
		}

		if ( self::TYPE_WORD_ORDER === $question['type'] ) {
			if ( count( $question['answers'] ) < 2 ) {
				return __( 'add at least two words or blocks.', 'deutschlms' );
			}
			return '';
		}

		$answers = $question['answers'];
		$correct = count( self::correct_ids( $question ) );

		if ( count( $answers ) < 2 ) {
			return __( 'add at least two answers.', 'deutschlms' );
		}
		if ( 0 === $correct ) {
			return __( 'mark the correct answer.', 'deutschlms' );
		}
		if ( self::TYPE_MULTIPLE !== $question['type'] && $correct > 1 ) {
			return __( 'only one answer may be correct for this question type.', 'deutschlms' );
		}
		return '';
	}

	/**
	 * Sanitizes the answers of one question.
	 *
	 * @param string $type Question type.
	 * @param mixed  $raw  Raw answers.
	 * @return array
	 */
	private static function sanitize_answers( string $type, $raw ): array {
		$raw = is_array( $raw ) ? array_values( $raw ) : array();

		if ( self::TYPE_FILL_BLANK === $type ) {
			return array();
		}

		if ( self::TYPE_ARTICLE === $type ) {
			// Always der, die, das; the first one marked correct counts.
			$marked = '';
			foreach ( $raw as $answer ) {
				if ( is_array( $answer ) && ! empty( $answer['correct'] ) && 'false' !== $answer['correct'] && in_array( $answer['id'] ?? '', self::ARTICLES, true ) ) {
					$marked = $answer['id'];
					break;
				}
			}
			return array_map(
				static fn( string $article ): array => array(
					'id'      => $article,
					'text'    => $article,
					'correct' => $article === $marked,
				),
				self::ARTICLES
			);
		}

		if ( self::TYPE_TRUE_FALSE === $type ) {
			// "True" is correct unless only "False" is marked.
			$marked = array();
			foreach ( $raw as $answer ) {
				if ( is_array( $answer ) && ! empty( $answer['correct'] ) ) {
					$marked[] = $answer['id'] ?? '';
				}
			}
			$true_correct = ! ( in_array( 'false', $marked, true ) && ! in_array( 'true', $marked, true ) );
			return array(
				array(
					'id'      => 'true',
					'text'    => '',
					'correct' => $true_correct,
				),
				array(
					'id'      => 'false',
					'text'    => '',
					'correct' => ! $true_correct,
				),
			);
		}//end if

		$answers  = array();
		$used_ids = array();
		foreach ( array_slice( $raw, 0, self::MAX_ANSWERS ) as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			$text = self::clean_text( $answer['text'] ?? '', 500, false );
			if ( '' === $text ) {
				continue;
			}
			$clean = array(
				'id'   => self::unique_id( $answer['id'] ?? '', 'a_', $used_ids ),
				'text' => $text,
			);
			// Word-order blocks are correct by their position, not by a flag.
			if ( self::TYPE_WORD_ORDER !== $type ) {
				$clean['correct'] = ! empty( $answer['correct'] ) && 'false' !== $answer['correct'];
			}
			$answers[] = $clean;
		}
		return $answers;
	}

	/**
	 * Other accepted sentences of a word-order question.
	 *
	 * @param mixed $raw List of sentences (or one sentence per line).
	 * @return string[]
	 */
	private static function sanitize_alternatives( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\R/u', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$alternatives = array();
		foreach ( $raw as $sentence ) {
			$sentence = self::clean_text( $sentence, 500, false );
			if ( '' !== $sentence && ! in_array( $sentence, $alternatives, true ) ) {
				$alternatives[] = $sentence;
			}
			if ( count( $alternatives ) >= self::MAX_ALTERNATIVES ) {
				break;
			}
		}
		return $alternatives;
	}

	/**
	 * Input width (in characters) for a gap. Bucketed, so the field hints at a
	 * short ending vs. a word without giving away the exact length.
	 *
	 * @param string[] $accepted Accepted answers of the gap.
	 * @return int
	 */
	private static function gap_size( array $accepted ): int {
		$longest = 0;
		foreach ( $accepted as $option ) {
			$longest = max( $longest, mb_strlen( $option ) );
		}
		if ( $longest <= 3 ) {
			return 4;
		}
		return $longest <= 10 ? 12 : 22;
	}

	/**
	 * Shuffles word-order blocks so they never start out in the solved order
	 * (unless all blocks read the same).
	 *
	 * @param array $answers Blocks in the correct order.
	 * @return array
	 */
	private static function shuffled( array $answers ): array {
		$solution = array_column( $answers, 'text' );
		if ( count( array_unique( $solution ) ) < 2 ) {
			return $answers;
		}
		$shuffled = $answers;
		for ( $try = 0; $try < 10; $try++ ) {
			shuffle( $shuffled );
			if ( array_column( $shuffled, 'text' ) !== $solution ) {
				return $shuffled;
			}
		}
		return array_reverse( $answers );
	}

	/**
	 * Keeps a valid, unused ID or makes a new one.
	 *
	 * @param mixed    $id       Proposed ID.
	 * @param string   $prefix   Prefix for new IDs.
	 * @param string[] $used_ids IDs used so far (by reference).
	 * @return string
	 */
	private static function unique_id( $id, string $prefix, array &$used_ids ): string {
		$id = is_string( $id ) ? strtolower( $id ) : '';
		if ( ! preg_match( self::ID_PATTERN, $id ) || in_array( $id, $used_ids, true ) || in_array( $id, array( 'true', 'false' ), true ) ) {
			do {
				$id = $prefix . strtolower( wp_generate_password( 8, false ) );
			} while ( in_array( $id, $used_ids, true ) );
		}
		$used_ids[] = $id;
		return $id;
	}

	/**
	 * Plain-text cleanup with a length cap.
	 *
	 * @param mixed $value     Raw value.
	 * @param int   $max       Maximum length.
	 * @param bool  $multiline Keep line breaks.
	 * @return string
	 */
	private static function clean_text( $value, int $max, bool $multiline ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		return trim( mb_substr( $value, 0, $max ) );
	}
}
