<?php
/**
 * Question bank.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Quiz;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Integrations\Multilingual;
use WP_Error;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Central store of questions that quizzes link to.
 *
 * - Each question is a `dlms_question` post. The question itself (same shape
 *   as one entry of Questions, incl. its stable `id`) lives in protected meta,
 *   so attempts keep pointing at it. Title and content are generated from it
 *   (the content is plain text for the admin search).
 * - A quiz holds an ordered list of items (Meta::QUIZ_ITEMS): linked
 *   questions and random rules ("5 random questions from category X").
 *   Editing a bank question changes every quiz that links it; removing it
 *   from a quiz only removes the link.
 * - Quizzes without items still use their own questions (Meta::QUESTIONS,
 *   from before the bank). Saving such a quiz in the editor, or
 *   import_quiz(), moves them into the bank with the same IDs; the old meta
 *   is left untouched.
 * - Random rules draw from complete, published questions, never repeating a
 *   question of the same quiz. QuizService remembers each student's draw
 *   until they submit.
 */
final class QuestionBank {

	public const KIND_QUESTION = 'question';
	public const KIND_RANDOM   = 'random';

	public const MAX_ITEMS        = 200;
	public const MAX_RANDOM_COUNT = 50;

	/**
	 * Option: version of the default terms that were added.
	 */
	public const TERMS_OPTION = 'dlms_question_terms_version';

	/**
	 * Bump when default terms are added.
	 */
	public const TERMS_VERSION = '1';

	/**
	 * Question ID => IDs of the quizzes linking it (per request).
	 *
	 * @var array<int, int[]>|null
	 */
	private ?array $usage = null;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_seed_terms' ), 20 );
	}

	/**
	 * Adds the default difficulty levels and CEFR levels once (never removes
	 * or renames terms, so the owner's changes stay).
	 */
	public function maybe_seed_terms(): void {
		if ( self::TERMS_VERSION === get_option( self::TERMS_OPTION ) ) {
			return;
		}
		$defaults = array(
			PostTypes::QUESTION_DIFFICULTY => array( 'Leicht', 'Mittel', 'Schwer' ),
			PostTypes::QUESTION_LEVEL      => array( 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' ),
		);
		foreach ( $defaults as $taxonomy => $names ) {
			foreach ( $names as $name ) {
				if ( ! term_exists( $name, $taxonomy ) ) {
					wp_insert_term( $name, $taxonomy );
				}
			}
		}
		update_option( self::TERMS_OPTION, self::TERMS_VERSION, true );
	}

	/**
	 * A bank question (null when the post is not a question, is in the trash,
	 * or has no text).
	 *
	 * @param int $post_id Question post ID.
	 * @return array|null
	 */
	public function get( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || PostTypes::QUESTION !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		return Questions::sanitize_one( get_post_meta( $post_id, Meta::QUESTION, true ) );
	}

	/**
	 * The bank question with a question ID (the oldest one, should two share
	 * it), in the current language.
	 *
	 * @param string $key Question ID (`q_…`).
	 * @return array|null
	 */
	public function find_by_key( string $key ): ?array {
		$post_id = $this->post_id_of_key( $key, false );
		return $post_id ? $this->get( Multilingual::translated_id( $post_id, PostTypes::QUESTION ) ) : null;
	}

	/**
	 * Creates a bank question.
	 *
	 * @param int    $user_id  Author.
	 * @param array  $question Question (see Questions).
	 * @param array  $terms    { category: int[], difficulty: int, level: int } (any may be left out).
	 * @param bool   $keep_key Keep the question ID even when another bank question has it (moving
	 *                         existing quiz questions, whose attempts use the ID); otherwise a used
	 *                         ID is replaced by a new one.
	 * @param string $status   Post status.
	 * @return int|WP_Error Question post ID.
	 */
	public function create( int $user_id, array $question, array $terms = array(), bool $keep_key = false, string $status = 'publish' ) {
		$question = Questions::sanitize_one( $question );
		if ( null === $question ) {
			return new WP_Error( 'dlms_question_empty', __( 'Please enter the question text.', 'deutschlms' ), array( 'status' => 400 ) );
		}
		if ( ! $keep_key && $this->post_id_of_key( $question['id'], true ) ) {
			$question['id'] = $this->new_key();
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => PostTypes::QUESTION,
				'post_status'  => $status,
				'post_author'  => $user_id,
				'post_title'   => self::title_for( $question ),
				'post_content' => self::search_text( $question ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$this->write_meta( $post_id, $question );
		$this->set_terms( $post_id, $terms );
		return $post_id;
	}

	/**
	 * Updates a bank question. Its question ID never changes.
	 *
	 * @param int        $post_id  Question post ID.
	 * @param array      $question Question.
	 * @param array|null $terms    Terms to set (null = leave them).
	 * @return true|WP_Error
	 */
	public function update( int $post_id, array $question, ?array $terms = null ) {
		$current = $this->get( $post_id );
		if ( null === $current ) {
			return new WP_Error( 'dlms_invalid_question', __( 'Invalid question.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		$question = Questions::sanitize_one( array_merge( $question, array( 'id' => $current['id'] ) ) );
		if ( null === $question ) {
			return new WP_Error( 'dlms_question_empty', __( 'Please enter the question text.', 'deutschlms' ), array( 'status' => 400 ) );
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => self::title_for( $question ),
				'post_content' => self::search_text( $question ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$this->write_meta( $post_id, $question );
		if ( null !== $terms ) {
			$this->set_terms( $post_id, $terms );
		}
		return true;
	}

	/**
	 * Stores a question saved on its own screen (the post itself is saved by
	 * WordPress). The question ID stays the one the post has; a new post keeps
	 * the editor's ID unless another bank question uses it.
	 *
	 * @param int   $post_id Question post ID.
	 * @param mixed $raw     Question from the editor.
	 * @return bool False when the question has no text (nothing stored).
	 */
	public function save_from_editor( int $post_id, $raw ): bool {
		$question = Questions::sanitize_one( $raw );
		if ( null === $question ) {
			return false;
		}
		$current = (string) get_post_meta( $post_id, Meta::QUESTION_KEY, true );
		if ( '' !== $current ) {
			$question['id'] = $current;
		} elseif ( $this->post_id_of_key( $question['id'], true ) ) {
			$question['id'] = $this->new_key();
		}
		$this->write_meta( $post_id, $question );
		return true;
	}

	/**
	 * Stores a question's data and the fields derived from it.
	 *
	 * @param int   $post_id  Question post ID.
	 * @param array $question Sanitized question.
	 */
	public function write_meta( int $post_id, array $question ): void {
		update_post_meta( $post_id, Meta::QUESTION, wp_slash( $question ) );
		update_post_meta( $post_id, Meta::QUESTION_KEY, $question['id'] );
		update_post_meta( $post_id, Meta::QUESTION_TYPE, $question['type'] );
		update_post_meta( $post_id, Meta::QUESTION_READY, Questions::is_playable( $question ) ? '1' : '0' );
	}

	/**
	 * Sets a question's categories, difficulty and level. Keys left out are
	 * not changed; difficulty and level take one term.
	 *
	 * @param int   $post_id Question post ID.
	 * @param array $terms   { category: int[], difficulty: int, level: int }.
	 */
	public function set_terms( int $post_id, array $terms ): void {
		foreach ( PostTypes::question_taxonomies() as $name => $taxonomy ) {
			if ( ! array_key_exists( $name, $terms ) ) {
				continue;
			}
			$ids = array();
			foreach ( (array) $terms[ $name ] as $term_id ) {
				$term = get_term( absint( $term_id ), $taxonomy );
				if ( $term instanceof WP_Term ) {
					$ids[] = $term->term_id;
				}
			}
			if ( 'category' !== $name ) {
				$ids = array_slice( $ids, 0, 1 );
			}
			wp_set_object_terms( $post_id, array_values( array_unique( $ids ) ), $taxonomy );
		}
	}

	/**
	 * A question's term IDs.
	 *
	 * @param int $post_id Question post ID.
	 * @return array{category: int[], difficulty: int, level: int}
	 */
	public function terms_of( int $post_id ): array {
		$out = array();
		foreach ( PostTypes::question_taxonomies() as $name => $taxonomy ) {
			$terms        = get_the_terms( $post_id, $taxonomy );
			$ids          = is_array( $terms ) ? array_map( static fn( WP_Term $term ) => $term->term_id, $terms ) : array();
			$out[ $name ] = 'category' === $name ? $ids : ( $ids[0] ?? 0 );
		}
		return $out;
	}

	/**
	 * Terms for the editors' selects: [ { id, name, depth } ] per taxonomy,
	 * categories as a tree (children under their parent, natural order, so
	 * "Lektion 2" comes before "Lektion 10").
	 *
	 * @return array<string, array[]>
	 */
	public static function term_options(): array {
		$out = array();
		foreach ( PostTypes::question_taxonomies() as $name => $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$terms = is_array( $terms ) ? $terms : array();
			usort( $terms, static fn( WP_Term $a, WP_Term $b ) => strnatcasecmp( $a->name, $b->name ) );

			$children = array();
			foreach ( $terms as $term ) {
				$children[ (int) $term->parent ][] = $term;
			}
			$list = array();
			$walk = static function ( int $parent_id, int $depth ) use ( &$walk, &$list, $children ): void {
				foreach ( $children[ $parent_id ] ?? array() as $term ) {
					$list[] = array(
						'id'    => $term->term_id,
						'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'depth' => $depth,
					);
					$walk( $term->term_id, $depth + 1 );
				}
			};
			$walk( 0, 0 );
			$out[ $name ] = $list;
		}//end foreach
		return $out;
	}

	/**
	 * List title: the question text in one line, gaps shown with their
	 * answers in brackets.
	 *
	 * @param array $question Sanitized question.
	 * @return string
	 */
	public static function title_for( array $question ): string {
		$text = (string) preg_replace( '/\{([^{}]*)\}/u', '[$1]', (string) $question['text'] );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		return mb_strlen( $text ) > 120 ? rtrim( mb_substr( $text, 0, 119 ) ) . '…' : $text;
	}

	/**
	 * Plain text the admin search looks through: question, answers, other
	 * accepted orders and explanation.
	 *
	 * @param array $question Sanitized question.
	 * @return string
	 */
	public static function search_text( array $question ): string {
		$lines = array_merge(
			array( $question['text'] ),
			array_column( $question['answers'], 'text' ),
			$question['alternatives'] ?? array(),
			array( $question['explanation'] )
		);
		return implode( "\n", array_filter( array_map( 'strval', $lines ), static fn( $line ) => '' !== trim( $line ) ) );
	}

	/**
	 * A quiz's items, or null when the quiz still uses its own questions.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array|null
	 */
	public function items( int $quiz_id ): ?array {
		if ( ! metadata_exists( 'post', $quiz_id, Meta::QUIZ_ITEMS ) ) {
			return null;
		}
		return self::sanitize_items( get_post_meta( $quiz_id, Meta::QUIZ_ITEMS, true ) );
	}

	/**
	 * Sanitizes quiz items. A question is linked at most once.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize_items( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$items = array();
		$seen  = array();
		foreach ( array_slice( array_values( $value ), 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::KIND_RANDOM === ( $item['kind'] ?? '' ) ) {
				$items[] = self::sanitize_rule( $item );
				continue;
			}
			$id = absint( $item['question'] ?? 0 );
			if ( $id && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$items[]     = array(
					'kind'     => self::KIND_QUESTION,
					'question' => $id,
				);
			}
		}
		return $items;
	}

	/**
	 * Sanitizes a random rule: how many questions, and optional category
	 * (incl. its subcategories), difficulty, level and type.
	 *
	 * @param array $rule Raw rule.
	 * @return array{kind: string, count: int, category: int, difficulty: int, level: int, type: string}
	 */
	public static function sanitize_rule( array $rule ): array {
		$type = is_string( $rule['type'] ?? null ) ? $rule['type'] : '';
		return array(
			'kind'       => self::KIND_RANDOM,
			'count'      => max( 1, min( self::MAX_RANDOM_COUNT, absint( $rule['count'] ?? 1 ) ) ),
			'category'   => absint( $rule['category'] ?? 0 ),
			'difficulty' => absint( $rule['difficulty'] ?? 0 ),
			'level'      => absint( $rule['level'] ?? 0 ),
			'type'       => in_array( $type, self::type_keys(), true ) ? $type : '',
		);
	}

	/**
	 * Linked questions of a quiz in order (incl. incomplete ones), or the
	 * quiz's own questions when it has no items.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array
	 */
	public function fixed_questions( int $quiz_id ): array {
		$items = $this->items( $quiz_id );
		if ( null === $items ) {
			return Questions::sanitize( get_post_meta( $quiz_id, Meta::QUESTIONS, true ) );
		}
		$questions = array();
		$keys      = array();
		foreach ( $this->linked_ids( $items ) as $post_id ) {
			$question = $this->get( $post_id );
			if ( null !== $question && ! isset( $keys[ $question['id'] ] ) ) {
				$keys[ $question['id'] ] = true;
				$questions[]             = $question;
			}
		}
		return $questions;
	}

	/**
	 * The questions of a quiz as students get them: complete linked questions
	 * in order, each random rule replaced by its draw.
	 *
	 * @param int        $quiz_id Quiz ID.
	 * @param array|null $draw    An earlier draw (from the return value) to reuse. Ignored
	 *                            when the quiz's items changed since; null draws anew.
	 * @return array{questions: array, draw: array|null} `draw` is null when the quiz has no random rules.
	 */
	public function resolve( int $quiz_id, ?array $draw = null ): array {
		$items = $this->items( $quiz_id );
		if ( null === $items ) {
			return array(
				'questions' => Questions::playable( $this->fixed_questions( $quiz_id ) ),
				'draw'      => null,
			);
		}

		$signature  = md5( (string) wp_json_encode( $items ) );
		$has_random = in_array( self::KIND_RANDOM, array_column( $items, 'kind' ), true );
		$reuse      = $has_random && is_array( $draw ) && ( $draw['sig'] ?? '' ) === $signature && is_array( $draw['picks'] ?? null );

		// Linked questions are taken first, so a draw never repeats them.
		$used   = array();
		$linked = array();
		$keys   = array();
		$this->prime( $this->linked_ids( $items ) );
		foreach ( $items as $index => $item ) {
			if ( self::KIND_QUESTION !== $item['kind'] ) {
				continue;
			}
			$post_id = Multilingual::translated_id( $item['question'], PostTypes::QUESTION );
			$used[]  = $item['question'];
			$used[]  = $post_id;
			$found   = $this->get( $post_id );
			if ( null !== $found && Questions::is_playable( $found ) && ! isset( $keys[ $found['id'] ] ) ) {
				$keys[ $found['id'] ] = true;
				$linked[ $index ]     = $found;
			}
		}

		$questions = array();
		$picks     = array();
		foreach ( $items as $index => $item ) {
			if ( isset( $linked[ $index ] ) ) {
				$questions[] = $linked[ $index ];
				continue;
			}
			if ( self::KIND_RANDOM !== $item['kind'] ) {
				continue;
			}
			$ids             = $reuse ? array_map( 'absint', (array) ( $draw['picks'][ $index ] ?? array() ) ) : $this->draw( $item, $used );
			$picks[ $index ] = $ids;
			$used            = array_merge( $used, $ids );
			$this->prime( $ids );
			foreach ( $ids as $post_id ) {
				$found = $this->get( Multilingual::translated_id( $post_id, PostTypes::QUESTION ) );
				if ( null !== $found && Questions::is_playable( $found ) && ! isset( $keys[ $found['id'] ] ) ) {
					$keys[ $found['id'] ] = true;
					$questions[]          = $found;
				}
			}
		}//end foreach

		return array(
			'questions' => array_slice( $questions, 0, self::MAX_ITEMS ),
			'draw'      => $has_random ? array(
				'sig'   => $signature,
				'picks' => $picks,
			) : null,
		);
	}

	/**
	 * How many questions students get: complete linked questions plus what
	 * the random rules can draw.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function question_count( int $quiz_id ): int {
		$count = count( Questions::playable( $this->fixed_questions( $quiz_id ) ) );
		$items = $this->items( $quiz_id );
		if ( null === $items ) {
			return $count;
		}
		$used = $this->linked_ids( $items );
		foreach ( $items as $item ) {
			if ( self::KIND_RANDOM === $item['kind'] ) {
				$ids    = array_slice( $this->matching_ids( $item, $used ), 0, $item['count'] );
				$count += count( $ids );
				$used   = array_merge( $used, $ids );
			}
		}
		return min( self::MAX_ITEMS, $count );
	}

	/**
	 * Complete, published questions matching a random rule.
	 *
	 * @param array $rule    Rule (see sanitize_rule()).
	 * @param int[] $exclude Question post IDs to leave out.
	 * @return int[]
	 */
	public function matching_ids( array $rule, array $exclude = array() ): array {
		$query = new WP_Query(
			array_merge(
				$this->filter_args( $rule ),
				array(
					'post_type'              => PostTypes::QUESTION,
					'post_status'            => 'publish',
					'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs only, to draw from.
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'post__not_in'           => array_values( array_filter( array_map( 'absint', $exclude ) ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Small list of the quiz's own questions.
				)
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Bank questions for the quiz builder's picker, newest first.
	 *
	 * @param array $filters { search, type, category, difficulty, level, page, per_page }.
	 * @return array{items: array[], total: int, pages: int}
	 */
	public function search( array $filters ): array {
		$args   = array_merge(
			$this->filter_args( $filters, false ),
			array(
				'post_type'      => PostTypes::QUESTION,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => max( 1, min( 50, absint( $filters['per_page'] ?? 20 ) ) ),
				'paged'          => max( 1, absint( $filters['page'] ?? 1 ) ),
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$item = $this->editor_item( $post->ID );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Saves the quiz builder's items: changed linked questions are updated in
	 * the bank (only when the user may edit them), new ones are created, and
	 * the quiz stores the resulting list.
	 *
	 * @param int   $user_id Acting user.
	 * @param int   $quiz_id Quiz ID.
	 * @param array $raw     Builder items: { kind: 'question', post_id, changed, question, terms } or a random rule.
	 * @return array Saved items.
	 */
	public function save_quiz_items( int $user_id, int $quiz_id, array $raw ): array {
		$create_cap = get_post_type_object( PostTypes::QUESTION )->cap->create_posts ?? 'do_not_allow';
		$items      = array();
		foreach ( array_slice( array_values( $raw ), 0, self::MAX_ITEMS ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( self::KIND_RANDOM === ( $entry['kind'] ?? '' ) ) {
				$items[] = self::sanitize_rule( $entry );
				continue;
			}

			$post_id  = absint( $entry['post_id'] ?? 0 );
			$question = is_array( $entry['question'] ?? null ) ? $entry['question'] : array();
			$terms    = self::clean_terms( $entry['terms'] ?? null );
			if ( $post_id && PostTypes::QUESTION === get_post_type( $post_id ) ) {
				// Errors (e.g. emptied text) leave the bank question as it was.
				if ( ! empty( $entry['changed'] ) && user_can( $user_id, 'edit_post', $post_id ) ) {
					$this->update( $post_id, $question, $terms );
				}
			} else {
				if ( ! user_can( $user_id, $create_cap ) ) {
					continue;
				}
				$created = $this->create( $user_id, $question, $terms ?? array() );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$post_id = $created;
			}
			$items[] = array(
				'kind'     => self::KIND_QUESTION,
				'question' => $post_id,
			);
		}//end foreach

		$items = self::sanitize_items( $items );
		update_post_meta( $quiz_id, Meta::QUIZ_ITEMS, $items );
		$this->usage = null;
		return $items;
	}

	/**
	 * Moves a quiz's own questions into the bank (same question IDs, so past
	 * attempts still match) and links them. Does nothing when the quiz already
	 * has items. A question already in the bank with the same ID and the same
	 * content is linked instead of added again, so an interrupted move can be
	 * run again. The quiz's old question meta is not changed.
	 *
	 * @param int   $user_id Author of the new questions.
	 * @param int   $quiz_id Quiz ID.
	 * @param array $terms   Terms for the new questions.
	 * @return int|WP_Error Number of questions moved.
	 */
	public function import_quiz( int $user_id, int $quiz_id, array $terms = array() ) {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return new WP_Error( 'dlms_invalid_quiz', __( 'Invalid quiz.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( null !== $this->items( $quiz_id ) ) {
			return 0;
		}
		$items = array();
		foreach ( $this->fixed_questions( $quiz_id ) as $question ) {
			$post_id = $this->post_id_of_key( $question['id'], false );
			if ( ! $post_id || $this->get( $post_id ) !== $question ) {
				$post_id = $this->create( $user_id, $question, $terms, true );
			}
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			$items[] = array(
				'kind'     => self::KIND_QUESTION,
				'question' => $post_id,
			);
		}
		update_post_meta( $quiz_id, Meta::QUIZ_ITEMS, $items );
		$this->usage = null;
		return count( $items );
	}

	/**
	 * A quiz's items for the quiz builder: linked questions with their data,
	 * or the quiz's own questions (not in the bank yet, post_id 0).
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array[]
	 */
	public function editor_items( int $quiz_id ): array {
		$items = $this->items( $quiz_id );
		if ( null === $items ) {
			return array_map(
				static fn( array $question ): array => array(
					'kind'      => self::KIND_QUESTION,
					'post_id'   => 0,
					'status'    => '',
					'editable'  => true,
					'edit_link' => '',
					'used_in'   => 0,
					'terms'     => array(
						'category'   => array(),
						'difficulty' => 0,
						'level'      => 0,
					),
					'question'  => $question,
				),
				$this->fixed_questions( $quiz_id )
			);
		}

		$this->prime( $this->linked_ids( $items ) );
		$out = array();
		foreach ( $items as $item ) {
			if ( self::KIND_RANDOM === $item['kind'] ) {
				$out[] = $item;
				continue;
			}
			$entry = $this->editor_item( $item['question'], $quiz_id );
			if ( null !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * A bank question for the editors: the question with its answers, terms,
	 * how many other quizzes link it, and whether the current user may
	 * change it.
	 *
	 * @param int $post_id Question post ID.
	 * @param int $quiz_id Quiz being edited (left out of the count), or 0.
	 * @return array|null
	 */
	public function editor_item( int $post_id, int $quiz_id = 0 ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || PostTypes::QUESTION !== $post->post_type ) {
			return null;
		}
		$question = Questions::sanitize_one( get_post_meta( $post_id, Meta::QUESTION, true ) );
		if ( null === $question ) {
			return null;
		}
		return array(
			'kind'      => self::KIND_QUESTION,
			'post_id'   => $post_id,
			'status'    => $post->post_status,
			'editable'  => 'trash' !== $post->post_status && current_user_can( 'edit_post', $post_id ),
			'edit_link' => (string) get_edit_post_link( $post_id, 'raw' ),
			'used_in'   => count( array_diff( $this->quizzes_using( $post_id ), array( $quiz_id ) ) ),
			'terms'     => $this->terms_of( $post_id ),
			'question'  => $question,
		);
	}

	/**
	 * IDs of the quizzes (not in the trash) that link a question.
	 *
	 * @param int $post_id Question post ID.
	 * @return int[]
	 */
	public function quizzes_using( int $post_id ): array {
		return $this->usage()[ $post_id ] ?? array();
	}

	/**
	 * Questions linked by any of the given quizzes.
	 *
	 * @param int[] $quiz_ids Quiz IDs.
	 * @return int[]
	 */
	public function questions_in( array $quiz_ids ): array {
		$quiz_ids = array_map( 'intval', $quiz_ids );
		$ids      = array();
		foreach ( $this->usage() as $post_id => $quizzes ) {
			if ( array_intersect( $quizzes, $quiz_ids ) ) {
				$ids[] = (int) $post_id;
			}
		}
		return $ids;
	}

	/**
	 * Question ID => linking quiz IDs, read from all quizzes' items.
	 *
	 * @return array<int, int[]>
	 */
	private function usage(): array {
		if ( null !== $this->usage ) {
			return $this->usage;
		}
		global $wpdb;
		// One small row per quiz; there is no cached API for "meta of all posts of a type".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				Meta::QUIZ_ITEMS,
				PostTypes::QUIZ
			)
		);

		$usage = array();
		foreach ( (array) $rows as $row ) {
			foreach ( self::sanitize_items( maybe_unserialize( $row->meta_value ) ) as $item ) {
				if ( self::KIND_QUESTION === $item['kind'] ) {
					$usage[ $item['question'] ][] = (int) $row->post_id;
				}
			}
		}
		$this->usage = $usage;
		return $usage;
	}

	/**
	 * WP_Query arguments for the type and term filters.
	 *
	 * @param array $filters    { type, category, difficulty, level }.
	 * @param bool  $ready_only Only complete questions.
	 * @return array
	 */
	private function filter_args( array $filters, bool $ready_only = true ): array {
		$meta = array();
		if ( $ready_only ) {
			$meta[] = array(
				'key'   => Meta::QUESTION_READY,
				'value' => '1',
			);
		}
		$type = is_string( $filters['type'] ?? null ) ? $filters['type'] : '';
		if ( in_array( $type, self::type_keys(), true ) ) {
			$meta[] = array(
				'key'   => Meta::QUESTION_TYPE,
				'value' => $type,
			);
		}

		$tax = array();
		foreach ( PostTypes::question_taxonomies() as $name => $taxonomy ) {
			$term_id = absint( $filters[ $name ] ?? 0 );
			if ( $term_id ) {
				$tax[] = array(
					'taxonomy'         => $taxonomy,
					'field'            => 'term_id',
					'terms'            => array( $term_id ),
					'include_children' => true,
				);
			}
		}

		$args = array();
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bank filters.
		}
		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bank filters.
		}
		return $args;
	}

	/**
	 * Random pick for a rule.
	 *
	 * @param array $rule    Rule.
	 * @param int[] $exclude Question post IDs already in the quiz.
	 * @return int[]
	 */
	private function draw( array $rule, array $exclude ): array {
		$ids = $this->matching_ids( $rule, $exclude );
		shuffle( $ids );
		return array_slice( $ids, 0, $rule['count'] );
	}

	/**
	 * Linked question post IDs of a quiz's items.
	 *
	 * @param array $items Items.
	 * @return int[]
	 */
	private function linked_ids( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			if ( self::KIND_QUESTION === $item['kind'] ) {
				$ids[] = $item['question'];
			}
		}
		return $ids;
	}

	/**
	 * Loads posts and their meta in one go.
	 *
	 * @param int[] $ids Post IDs.
	 */
	private function prime( array $ids ): void {
		if ( $ids ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, true );
		}
	}

	/**
	 * Builder terms as sent by the browser (null = not sent, leave them).
	 *
	 * @param mixed $raw Raw terms.
	 * @return array|null
	 */
	private static function clean_terms( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$terms = array();
		foreach ( array_keys( PostTypes::question_taxonomies() ) as $name ) {
			if ( array_key_exists( $name, $raw ) ) {
				$terms[ $name ] = array_map( 'absint', (array) $raw[ $name ] );
			}
		}
		return $terms;
	}

	/**
	 * The post with a question ID (0 = none).
	 *
	 * @param string $key       Question ID.
	 * @param bool   $any_state Include questions in the trash.
	 * @return int
	 */
	private function post_id_of_key( string $key, bool $any_state ): int {
		if ( '' === $key ) {
			return 0;
		}
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		if ( $any_state ) {
			$statuses[] = 'trash';
		}
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::QUESTION,
				'post_status'    => $statuses,
				'meta_key'       => Meta::QUESTION_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Lookup by question ID.
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Lookup by question ID.
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * A question ID no bank question has.
	 *
	 * @return string
	 */
	private function new_key(): string {
		do {
			$key = 'q_' . strtolower( wp_generate_password( 10, false ) );
		} while ( $this->post_id_of_key( $key, true ) );
		return $key;
	}

	/**
	 * Question type keys.
	 *
	 * @return string[]
	 */
	private static function type_keys(): array {
		return array(
			Questions::TYPE_SINGLE,
			Questions::TYPE_MULTIPLE,
			Questions::TYPE_TRUE_FALSE,
			Questions::TYPE_FILL_BLANK,
			Questions::TYPE_WORD_ORDER,
		);
	}
}
