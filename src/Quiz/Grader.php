<?php
/**
 * Quiz grading.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Grades answers against questions. Pure logic, server-side only.
 *
 * A question earns its points only when it is answered completely right
 * (all-or-nothing):
 *
 * - Choice questions: the selected answers exactly match the correct ones.
 * - Gap-fill: every gap matches one of its accepted answers. Spacing and quote
 *   styles are forgiven; capitalization and spelling (ä, ö, ü, ß) count.
 * - Word order: all blocks are used and read like the solution or one of the
 *   alternatives (capitalization and punctuation are ignored, as the blocks
 *   themselves decide those).
 */
final class Grader {

	private const MAX_TYPED_LENGTH = 100;

	/**
	 * Grades a submission.
	 *
	 * @param array $questions Playable questions (see Questions).
	 * @param array $answers   Question ID => list of answer IDs (choice, word order, in order) or typed gap answers.
	 * @return array{score: int, max_score: int, percent: float, results: array<string, array>}
	 */
	public static function grade( array $questions, array $answers ): array {
		$score   = 0;
		$max     = 0;
		$results = array();

		foreach ( $questions as $question ) {
			$given = isset( $answers[ $question['id'] ] ) ? array_values( (array) $answers[ $question['id'] ] ) : array();

			switch ( $question['type'] ) {
				case Questions::TYPE_FILL_BLANK:
					$result = self::grade_fill_blank( $question, $given );
					break;
				case Questions::TYPE_WORD_ORDER:
					$result = self::grade_word_order( $question, $given );
					break;
				default:
					$result = self::grade_choice( $question, $given );
			}

			$points  = (int) $question['points'];
			$awarded = $result['correct'] ? $points : 0;
			$max    += $points;
			$score  += $awarded;

			$results[ $question['id'] ] = $result + array(
				'points'  => $points,
				'awarded' => $awarded,
			);
		}//end foreach

		return array(
			'score'     => $score,
			'max_score' => $max,
			'percent'   => $max > 0 ? round( $score * 100 / $max, 2 ) : 0.0,
			'results'   => $results,
		);
	}

	/**
	 * Whether a score passes. Compares in integers to avoid rounding errors at
	 * the boundary (e.g. 4 of 5 = 80% passes an 80% mark).
	 *
	 * @param int $score     Points scored.
	 * @param int $max_score Points possible.
	 * @param int $pass_mark Required percentage.
	 * @return bool
	 */
	public static function passes( int $score, int $max_score, int $pass_mark ): bool {
		return $max_score > 0 && $score * 100 >= $pass_mark * $max_score;
	}

	/**
	 * Normalizes a typed gap answer: trimmed, single spaces, straight quotes,
	 * composed Unicode (so "ü" typed either way compares equal).
	 *
	 * @param string $value Typed or accepted answer.
	 * @return string
	 */
	public static function normalize_typed( string $value ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			$value      = false === $normalized ? $value : $normalized;
		}
		$value = str_replace( array( '’', '‘', '‚', '`', '´' ), "'", $value );
		$value = str_replace( array( '„', '“', '”', '«', '»' ), '"', $value );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Normalizes a sentence for word-order comparison: lower case, no
	 * punctuation, single spaces.
	 *
	 * @param string $sentence Sentence.
	 * @return string
	 */
	public static function normalize_sentence( string $sentence ): string {
		$sentence = mb_strtolower( self::normalize_typed( $sentence ) );
		$sentence = (string) preg_replace( '/[.,!?;:"\'()–—-]+/u', ' ', $sentence );
		return trim( (string) preg_replace( '/\s+/u', ' ', $sentence ) );
	}

	/**
	 * Choice question (single, multiple, true/false).
	 *
	 * @param array $question Question.
	 * @param array $given    Selected answer IDs.
	 * @return array{selected: string[], correct: bool}
	 */
	private static function grade_choice( array $question, array $given ): array {
		$valid_ids = array_column( $question['answers'], 'id' );
		$selected  = array_map( static fn( $id ) => strtolower( (string) $id ), array_filter( $given, 'is_scalar' ) );
		$selected  = array_values( array_unique( array_intersect( $selected, $valid_ids ) ) );

		if ( Questions::TYPE_MULTIPLE !== $question['type'] ) {
			$selected = array_slice( $selected, 0, 1 );
		}

		$correct_ids = Questions::correct_ids( $question );
		sort( $correct_ids );
		$sorted = $selected;
		sort( $sorted );

		return array(
			'selected' => $selected,
			'correct'  => array() !== $sorted && $sorted === $correct_ids,
		);
	}

	/**
	 * Gap-fill question. `selected` keeps what was typed, one entry per gap.
	 *
	 * @param array $question Question.
	 * @param array $given    Typed answers in gap order.
	 * @return array{selected: string[], gaps: bool[], correct: bool}
	 */
	private static function grade_fill_blank( array $question, array $given ): array {
		$gaps     = Questions::gaps( $question['text'] );
		$selected = array();
		$marks    = array();

		foreach ( $gaps as $index => $accepted ) {
			$typed      = isset( $given[ $index ] ) && is_scalar( $given[ $index ] ) ? (string) $given[ $index ] : '';
			$typed      = self::normalize_typed( mb_substr( $typed, 0, self::MAX_TYPED_LENGTH ) );
			$selected[] = $typed;

			$right = false;
			if ( '' !== $typed ) {
				foreach ( $accepted as $option ) {
					if ( self::normalize_typed( $option ) === $typed ) {
						$right = true;
						break;
					}
				}
			}
			$marks[] = $right;
		}

		return array(
			'selected' => $selected,
			'gaps'     => $marks,
			'correct'  => array() !== $marks && ! in_array( false, $marks, true ),
		);
	}

	/**
	 * Word-order question. `selected` keeps the block IDs in the order given.
	 *
	 * @param array $question Question.
	 * @param array $given    Block IDs in order.
	 * @return array{selected: string[], correct: bool}
	 */
	private static function grade_word_order( array $question, array $given ): array {
		$texts = array_column( $question['answers'], 'text', 'id' );

		$selected = array();
		foreach ( $given as $id ) {
			$id = is_scalar( $id ) ? strtolower( (string) $id ) : '';
			if ( isset( $texts[ $id ] ) && ! in_array( $id, $selected, true ) ) {
				$selected[] = $id;
			}
		}

		$correct = false;
		if ( count( $selected ) === count( $texts ) ) {
			$sentence = self::normalize_sentence( implode( ' ', array_map( static fn( $id ) => $texts[ $id ], $selected ) ) );
			$accepted = array_merge( array( Questions::solution_sentence( $question ) ), $question['alternatives'] ?? array() );
			foreach ( $accepted as $option ) {
				if ( self::normalize_sentence( $option ) === $sentence ) {
					$correct = true;
					break;
				}
			}
		}

		return array(
			'selected' => $selected,
			'correct'  => $correct,
		);
	}
}
