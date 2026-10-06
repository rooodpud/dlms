<?php
/**
 * Quiz page: facts, the question form or an attempt's result.
 *
 * The form never contains correct answers; grading happens on the server.
 * The special-character bar (ä, ö, ü, ß) sits after the questions and sticks
 * to the bottom of the screen; it is hidden until the script shows it and types
 * into the gap that had focus last. On timed quizzes the remaining time sits in
 * the same bar; when it runs out, the "time is up" dialog locks the answers and
 * submits them after a short countdown (see frontend.js).
 *
 * Article questions show the noun with its picture and three colour-coded
 * choices (der blue, die red, das green; see the --dlms-der/-die/-das colours).
 *
 * Course managers who aren't enrolled get a trial (mode "trial", then a
 * "result" with $trial set): the same form, posted back to the quiz page and
 * graded without saving anything.
 *
 * Override by copying to yourtheme/deutschlms/quiz/quiz.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type int        $quiz_id            Quiz ID.
 *     @type array      $questions          Questions without solutions (see Questions::public_view()).
 *     @type int        $pass_mark          Percent needed to pass.
 *     @type int        $attempts_limit     0 = unlimited.
 *     @type int        $attempts_used      Counted attempts so far.
 *     @type int|null   $attempts_remaining null = unlimited.
 *     @type string     $mode               form|result|closed|preview|trial.
 *     @type bool       $trial              A course manager trying the quiz: answers are
 *                                          checked but not saved (form posts to the quiz page).
 *     @type string     $trial_draw         Random draw of a trial form (JSON, '' = none).
 *     @type int        $time_limit         Minutes per attempt (0 = no limit).
 *     @type int|null   $time_remaining     Seconds left in "form" mode (null = no limit).
 *     @type int        $time_up_seconds    Seconds between "time is up" and the automatic submission.
 *     @type string     $closed_message     Why no attempt is possible (mode "closed").
 *     @type bool       $passed             Whether the student has passed.
 *     @type float|null $best_percent       Best score so far.
 *     @type array|null $attempt            Attempt shown in "result" mode.
 *     @type array      $details            Per-question results (see QuizService::result_details()).
 *     @type bool       $can_retake         Whether another attempt is possible.
 *     @type string     $retake_url         Quiz URL for another attempt.
 *     @type string     $next_url           Next step ('' unless passed).
 *     @type string     $course_url         Course URL.
 *     @type string     $action_url         Form action (admin-post.php).
 *     @type string     $nonce_action       Nonce action.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_mode     = $args['mode'];
$dlms_disabled = 'preview' === $dlms_mode;
$dlms_trial    = ! empty( $args['trial'] );
?>
<div class="dlms-quiz dlms-quiz--<?php echo esc_attr( $dlms_mode ); ?>">
	<ul class="dlms-quiz__facts">
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of questions. */
					_n( '%d question', '%d questions', count( $args['questions'] ), 'deutschlms' ),
					count( $args['questions'] )
				)
			);
			?>
		</li>
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: percentage. */
					__( 'Pass mark: %d%%', 'deutschlms' ),
					$args['pass_mark']
				)
			);
			?>
		</li>
		<li>
			<?php
			if ( $args['attempts_limit'] > 0 ) {
				echo esc_html(
					sprintf(
						/* translators: 1: attempts used, 2: attempts allowed. */
						__( 'Attempts: %1$d of %2$d used', 'deutschlms' ),
						$args['attempts_used'],
						$args['attempts_limit']
					)
				);
			} else {
				esc_html_e( 'Unlimited attempts', 'deutschlms' );
			}
			?>
		</li>
		<?php if ( ! empty( $args['time_limit'] ) ) : ?>
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: minutes. */
						_n( 'Time limit: %d minute', 'Time limit: %d minutes', $args['time_limit'], 'deutschlms' ),
						$args['time_limit']
					)
				);
				?>
			</li>
		<?php endif; ?>
		<?php if ( null !== $args['best_percent'] ) : ?>
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: percentage. */
						__( 'Best result: %s%%', 'deutschlms' ),
						number_format_i18n( $args['best_percent'], 0 )
					)
				);
				?>
			</li>
		<?php endif; ?>
	</ul>

	<?php if ( $args['passed'] && 'result' !== $dlms_mode ) : ?>
		<p class="dlms-quiz__passed dlms-status--complete">
			<span class="dlms-status-icon" aria-hidden="true"></span>
			<?php esc_html_e( 'You have passed this quiz.', 'deutschlms' ); ?>
			<?php if ( '' !== $args['next_url'] ) : ?>
				<a class="dlms-link" href="<?php echo esc_url( $args['next_url'] ); ?>"><?php esc_html_e( 'Continue', 'deutschlms' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $dlms_trial ) : ?>
		<p class="dlms-complete__status dlms-complete__status--preview dlms-quiz__trial"><?php esc_html_e( 'Test mode: you manage this course, so you can try the quiz. Your answers are checked but not saved.', 'deutschlms' ); ?></p>
	<?php endif; ?>

	<?php if ( 'result' === $dlms_mode && $args['attempt'] ) : ?>
		<?php $dlms_attempt = $args['attempt']; ?>
		<section class="dlms-quiz-result <?php echo $dlms_attempt['passed'] ? 'is-passed' : 'is-failed'; ?>" aria-labelledby="dlms-quiz-result-heading" tabindex="-1">
			<h2 id="dlms-quiz-result-heading" class="dlms-quiz-result__heading">
				<?php echo $dlms_attempt['passed'] ? esc_html__( 'Passed!', 'deutschlms' ) : esc_html__( 'Not passed yet', 'deutschlms' ); ?>
			</h2>
			<p class="dlms-quiz-result__score">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: points scored, 2: points possible, 3: percentage, 4: pass mark. */
						__( 'You scored %1$d of %2$d points (%3$s%%). Pass mark: %4$d%%.', 'deutschlms' ),
						$dlms_attempt['score'],
						$dlms_attempt['max_score'],
						number_format_i18n( $dlms_attempt['percent'], 0 ),
						$args['pass_mark']
					)
				);
				?>
			</p>
			<?php if ( ! empty( $dlms_attempt['late'] ) ) : ?>
				<p class="dlms-quiz-result__late"><?php esc_html_e( 'Time exceeded: your answers arrived after the time limit, so this attempt counts as not passed.', 'deutschlms' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $args['details'] ) ) : ?>
				<ol class="dlms-quiz-result__questions">
					<?php foreach ( $args['details'] as $dlms_detail ) : ?>
						<?php $dlms_type = $dlms_detail['type'] ?? 'single'; ?>
						<li class="dlms-quiz-result__question <?php echo $dlms_detail['correct'] ? 'is-correct' : 'is-wrong'; ?>">
							<?php if ( 'fill_blank' === $dlms_type ) : ?>
								<p class="dlms-quiz-result__text dlms-quiz-result__cloze">
									<?php
									$dlms_last = count( $dlms_detail['segments'] ) - 1;
									foreach ( $dlms_detail['segments'] as $dlms_index => $dlms_segment ) {
										echo nl2br( esc_html( $dlms_segment ) );
										if ( $dlms_index >= $dlms_last || ! isset( $dlms_detail['gaps'][ $dlms_index ] ) ) {
											continue;
										}
										$dlms_gap = $dlms_detail['gaps'][ $dlms_index ];
										printf(
											'<span class="dlms-gap-result %1$s"><span class="dlms-sr">%2$s</span>%3$s</span>',
											$dlms_gap['is_correct'] ? 'is-right' : 'is-wrong',
											$dlms_gap['is_correct'] ? esc_html__( 'Your answer (correct):', 'deutschlms' ) : esc_html__( 'Your answer (incorrect):', 'deutschlms' ),
											esc_html( '' !== $dlms_gap['given'] ? $dlms_gap['given'] : '—' )
										);
										if ( ! $dlms_gap['is_correct'] && '' !== $dlms_gap['solution'] ) {
											printf(
												'<span class="dlms-gap-result__solution"><span class="dlms-sr">%1$s</span>%2$s</span>',
												esc_html__( 'Correct answer:', 'deutschlms' ),
												esc_html( $dlms_gap['solution'] )
											);
										}
									}
									?>
								</p>
							<?php elseif ( 'article' === $dlms_type ) : ?>
								<?php $dlms_article = $dlms_detail['article'] ?? ''; ?>
								<p class="dlms-quiz-result__text dlms-noun-result<?php echo '' !== $dlms_article ? ' dlms-article--' . esc_attr( $dlms_article ) : ''; ?>">
									<?php echo \DeutschLMS\Content\NounPictures::render( $dlms_detail['picture'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon filtered by wp_kses(), image from wp_get_attachment_image(). ?>
									<span class="dlms-noun-result__word">
										<?php if ( '' !== $dlms_article ) : ?>
											<strong><?php echo esc_html( $dlms_article ); ?></strong>
										<?php endif; ?>
										<?php echo esc_html( $dlms_detail['text'] ); ?>
									</span>
								</p>
							<?php else : ?>
								<p class="dlms-quiz-result__text"><?php echo nl2br( esc_html( $dlms_detail['text'] ) ); ?></p>
							<?php endif; ?>
							<p class="dlms-quiz-result__verdict">
								<?php echo $dlms_detail['correct'] ? esc_html__( 'Correct', 'deutschlms' ) : esc_html__( 'Incorrect', 'deutschlms' ); ?>
							</p>
							<?php if ( 'word_order' === $dlms_type ) : ?>
								<p class="dlms-quiz-result__sentence">
									<span class="dlms-quiz-result__tag"><?php esc_html_e( 'Your answer', 'deutschlms' ); ?></span>
									<?php echo esc_html( '' !== $dlms_detail['given'] ? $dlms_detail['given'] : '—' ); ?>
								</p>
								<?php if ( ! $dlms_detail['correct'] && '' !== $dlms_detail['solution'] ) : ?>
									<p class="dlms-quiz-result__sentence">
										<span class="dlms-quiz-result__tag dlms-quiz-result__tag--right"><?php esc_html_e( 'Correct answer', 'deutschlms' ); ?></span>
										<?php echo esc_html( $dlms_detail['solution'] ); ?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( 'article' === $dlms_type ) : ?>
								<?php
								// Only the student's article and, when it was wrong and answers are shown, the right one.
								$dlms_given = '';
								$dlms_right = '';
								foreach ( $dlms_detail['answers'] as $dlms_answer ) {
									if ( $dlms_answer['selected'] && '' === $dlms_given ) {
										$dlms_given = $dlms_answer['label'];
									}
									if ( true === $dlms_answer['is_correct'] ) {
										$dlms_right = $dlms_answer['label'];
									}
								}
								?>
								<dl class="dlms-article-answers">
									<div class="dlms-article-answers__row">
										<dt><?php esc_html_e( 'Your answer', 'deutschlms' ); ?>:</dt>
										<dd>
											<?php if ( '' === $dlms_given ) : ?>
												<span class="dlms-article-pill is-wrong"><?php esc_html_e( 'No answer', 'deutschlms' ); ?></span>
											<?php else : ?>
												<span class="dlms-article-pill <?php echo $dlms_detail['correct'] ? 'is-filled dlms-article--' . esc_attr( $dlms_given ) : 'is-wrong'; ?>"><?php echo esc_html( $dlms_given ); ?></span>
											<?php endif; ?>
										</dd>
									</div>
									<?php if ( ! $dlms_detail['correct'] && '' !== $dlms_right ) : ?>
										<div class="dlms-article-answers__row">
											<dt><?php esc_html_e( 'Correct answer', 'deutschlms' ); ?>:</dt>
											<dd><span class="dlms-article-pill is-filled dlms-article--<?php echo esc_attr( $dlms_right ); ?>"><?php echo esc_html( $dlms_right ); ?></span></dd>
										</div>
									<?php endif; ?>
								</dl>
							<?php elseif ( ! empty( $dlms_detail['answers'] ) ) : ?>
								<ul class="dlms-quiz-result__answers">
									<?php foreach ( $dlms_detail['answers'] as $dlms_answer ) : ?>
										<?php
										$dlms_classes = array( 'dlms-quiz-result__answer' );
										if ( $dlms_answer['selected'] ) {
											$dlms_classes[] = 'is-selected';
										}
										if ( true === $dlms_answer['is_correct'] ) {
											$dlms_classes[] = 'is-right-answer';
										}
										?>
										<li class="<?php echo esc_attr( implode( ' ', $dlms_classes ) ); ?>">
											<?php echo esc_html( $dlms_answer['label'] ); ?>
											<?php if ( $dlms_answer['selected'] ) : ?>
												<span class="dlms-quiz-result__tag"><?php esc_html_e( 'Your answer', 'deutschlms' ); ?></span>
											<?php endif; ?>
											<?php if ( true === $dlms_answer['is_correct'] ) : ?>
												<span class="dlms-quiz-result__tag dlms-quiz-result__tag--right"><?php esc_html_e( 'Correct answer', 'deutschlms' ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( '' !== $dlms_detail['explanation'] ) : ?>
								<p class="dlms-quiz-result__explanation"><?php echo nl2br( esc_html( $dlms_detail['explanation'] ) ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<p class="dlms-quiz-result__actions">
				<?php if ( '' !== $args['next_url'] ) : ?>
					<a class="dlms-button" href="<?php echo esc_url( $args['next_url'] ); ?>"><?php esc_html_e( 'Continue', 'deutschlms' ); ?></a>
				<?php endif; ?>
				<?php if ( $args['can_retake'] ) : ?>
					<a class="dlms-button<?php echo $dlms_attempt['passed'] ? ' dlms-button--secondary' : ''; ?>" href="<?php echo esc_url( $args['retake_url'] ); ?>"><?php esc_html_e( 'Try again', 'deutschlms' ); ?></a>
				<?php elseif ( ! $dlms_attempt['passed'] ) : ?>
					<span class="dlms-quiz__closed"><?php echo esc_html( $args['closed_message'] ); ?></span>
				<?php endif; ?>
				<a class="dlms-link" href="<?php echo esc_url( $args['course_url'] ); ?>"><?php esc_html_e( 'Back to the course', 'deutschlms' ); ?></a>
			</p>
		</section>

	<?php elseif ( 'closed' === $dlms_mode ) : ?>
		<p class="dlms-quiz__closed"><?php echo esc_html( $args['closed_message'] ); ?></p>

	<?php elseif ( empty( $args['questions'] ) ) : ?>
		<p class="dlms-empty"><?php esc_html_e( 'This quiz has no questions yet.', 'deutschlms' ); ?></p>

	<?php else : ?>
		<?php if ( $dlms_disabled ) : ?>
			<p class="dlms-complete__status dlms-complete__status--preview"><?php esc_html_e( 'Preview: you can see this because you manage the course. Only enrolled students can submit answers.', 'deutschlms' ); ?></p>
		<?php endif; ?>
		<?php $dlms_timed = isset( $args['time_remaining'] ) && null !== $args['time_remaining']; ?>
		<?php if ( $dlms_trial ) : ?>
			<form class="dlms-quiz__form dlms-quiz__form--trial" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>">
				<input type="hidden" name="dlms_trial_nonce" value="<?php echo esc_attr( wp_create_nonce( $args['nonce_action'] ) ); ?>" />
				<input type="hidden" name="dlms_trial_draw" value="<?php echo esc_attr( $args['trial_draw'] ?? '' ); ?>" />
		<?php else : ?>
			<form class="dlms-quiz__form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>" data-dlms-action="quiz" data-dlms-id="<?php echo esc_attr( (string) $args['quiz_id'] ); ?>"<?php echo $dlms_timed ? ' data-dlms-time-remaining="' . esc_attr( (string) (int) $args['time_remaining'] ) . '" data-dlms-time-up="' . esc_attr( (string) (int) $args['time_up_seconds'] ) . '"' : ''; ?>>
				<input type="hidden" name="action" value="dlms_submit_quiz" />
				<input type="hidden" name="quiz_id" value="<?php echo esc_attr( (string) $args['quiz_id'] ); ?>" />
				<input type="hidden" name="dlms_nonce" value="<?php echo esc_attr( wp_create_nonce( $args['nonce_action'] ) ); ?>" />
		<?php endif; ?>
			<ol class="dlms-quiz__questions">
				<?php foreach ( $args['questions'] as $dlms_index => $dlms_question ) : ?>
					<li class="dlms-quiz__question dlms-quiz__question--<?php echo esc_attr( $dlms_question['type'] ); ?>">
						<?php if ( 'fill_blank' === $dlms_question['type'] ) : ?>
							<fieldset>
								<legend class="dlms-sr">
									<?php
									// The question text has "…" for each gap.
									echo esc_html(
										sprintf(
											/* translators: %d: question number. */
											__( 'Question %d:', 'deutschlms' ),
											$dlms_index + 1
										) . ' ' . $dlms_question['text']
									);
									?>
								</legend>
								<p class="dlms-quiz__text dlms-quiz__cloze">
									<?php
									// Printed without whitespace, so a gap can sit right after a word stem (blau…).
									$dlms_gap_count = count( $dlms_question['segments'] ) - 1;
									foreach ( $dlms_question['segments'] as $dlms_segment_index => $dlms_segment ) {
										echo nl2br( esc_html( $dlms_segment ) );
										if ( $dlms_segment_index < $dlms_gap_count ) {
											printf(
												'<input type="text" class="dlms-gap" name="%1$s" size="%2$s" maxlength="100" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="%3$s"%4$s />',
												esc_attr( 'dlms_answers[' . $dlms_question['id'] . '][]' ),
												esc_attr( (string) ( $dlms_question['gap_sizes'][ $dlms_segment_index ] ?? 12 ) ),
												esc_attr(
													sprintf(
														/* translators: 1: gap number, 2: number of gaps. */
														__( 'Gap %1$d of %2$d', 'deutschlms' ),
														$dlms_segment_index + 1,
														$dlms_gap_count
													)
												),
												disabled( $dlms_disabled, true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string from core.
											);
										}
									}
									?>
								</p>
							</fieldset>
						<?php elseif ( 'word_order' === $dlms_question['type'] ) : ?>
							<?php $dlms_display = \DeutschLMS\Quiz\Questions::display( $dlms_question ); ?>
							<?php
							$dlms_ending = $dlms_question['ending'] ?? '.';
							$dlms_last   = count( $dlms_question['answers'] ) - 1;
							?>
							<fieldset class="dlms-order dlms-order--<?php echo esc_attr( $dlms_display ); ?>" data-dlms-order="<?php echo esc_attr( $dlms_display ); ?>" data-dlms-ending="<?php echo esc_attr( $dlms_ending ); ?>">
								<legend class="dlms-quiz__text">
									<span class="dlms-sr">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: question number. */
												__( 'Question %d:', 'deutschlms' ),
												$dlms_index + 1
											)
										);
										?>
									</span>
									<?php echo nl2br( esc_html( $dlms_question['text'] ) ); ?>
								</legend>
								<p class="dlms-quiz__hint" data-dlms-order-hint><?php esc_html_e( 'Choose the word for each position.', 'deutschlms' ); ?></p>
								<ol class="dlms-order__slots">
									<?php foreach ( array_keys( $dlms_question['answers'] ) as $dlms_position ) : ?>
										<li>
											<label>
												<span class="dlms-sr">
													<?php
													echo esc_html(
														sprintf(
															/* translators: %d: position in the sentence. */
															__( 'Position %d', 'deutschlms' ),
															$dlms_position + 1
														)
													);
													?>
												</span>
												<select name="dlms_answers[<?php echo esc_attr( $dlms_question['id'] ); ?>][]" <?php disabled( $dlms_disabled ); ?>>
													<option value="">—</option>
													<?php
													// The first position starts the sentence, the last one ends it.
													foreach ( $dlms_question['answers'] as $dlms_block ) :
														$dlms_label = $dlms_block['text'];
														if ( 0 === $dlms_position ) {
															$dlms_label = \DeutschLMS\Quiz\Questions::capitalize( $dlms_label );
														}
														if ( $dlms_last === $dlms_position ) {
															$dlms_label = \DeutschLMS\Quiz\Questions::finish( $dlms_label, $dlms_ending );
														}
														?>
														<option value="<?php echo esc_attr( $dlms_block['id'] ); ?>" data-text="<?php echo esc_attr( $dlms_block['text'] ); ?>"><?php echo esc_html( $dlms_label ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
										</li>
									<?php endforeach; ?>
								</ol>
							</fieldset>
						<?php elseif ( 'article' === $dlms_question['type'] ) : ?>
							<fieldset class="dlms-article-q">
								<legend class="dlms-quiz__text dlms-article-q__noun">
									<span class="dlms-sr">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: question number. */
												__( 'Question %d:', 'deutschlms' ),
												$dlms_index + 1
											) . ' ' . __( 'Which article?', 'deutschlms' )
										);
										?>
									</span>
									<?php echo \DeutschLMS\Content\NounPictures::render( \DeutschLMS\Quiz\Questions::picture( $dlms_question ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon filtered by wp_kses(), image from wp_get_attachment_image(). ?>
									<span class="dlms-article-q__word"><?php echo esc_html( $dlms_question['text'] ); ?></span>
								</legend>
								<p class="dlms-quiz__hint" aria-hidden="true"><?php esc_html_e( 'Which article?', 'deutschlms' ); ?></p>
								<div class="dlms-article-q__choices">
									<?php foreach ( $dlms_question['answers'] as $dlms_answer ) : ?>
										<label class="dlms-article-choice dlms-article--<?php echo esc_attr( $dlms_answer['id'] ); ?>">
											<input
												type="radio"
												class="dlms-article-choice__input"
												name="dlms_answers[<?php echo esc_attr( $dlms_question['id'] ); ?>][]"
												value="<?php echo esc_attr( $dlms_answer['id'] ); ?>"
												<?php disabled( $dlms_disabled ); ?>
											/>
											<span class="dlms-article-choice__label"><?php echo esc_html( $dlms_answer['text'] ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php else : ?>
							<fieldset>
								<legend class="dlms-quiz__text">
									<span class="dlms-sr">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: question number. */
												__( 'Question %d:', 'deutschlms' ),
												$dlms_index + 1
											)
										);
										?>
									</span>
									<?php echo nl2br( esc_html( $dlms_question['text'] ) ); ?>
								</legend>
								<?php if ( 'multiple' === $dlms_question['type'] ) : ?>
									<p class="dlms-quiz__hint"><?php esc_html_e( 'Select all correct answers.', 'deutschlms' ); ?></p>
								<?php endif; ?>
								<?php foreach ( $dlms_question['answers'] as $dlms_answer ) : ?>
									<label class="dlms-quiz__answer">
										<input
											type="<?php echo 'multiple' === $dlms_question['type'] ? 'checkbox' : 'radio'; ?>"
											name="dlms_answers[<?php echo esc_attr( $dlms_question['id'] ); ?>][]"
											value="<?php echo esc_attr( $dlms_answer['id'] ); ?>"
											<?php disabled( $dlms_disabled ); ?>
										/>
										<span><?php echo esc_html( $dlms_answer['text'] ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<div class="dlms-dock">
			<?php if ( $dlms_timed ) : ?>
				<p class="dlms-timer" data-dlms-timer hidden>
					<span class="dlms-timer__label"><?php esc_html_e( 'Time left:', 'deutschlms' ); ?></span>
					<span class="dlms-timer__clock" role="timer" data-dlms-timer-clock></span>
				</p>
			<?php endif; ?>
			<?php if ( in_array( 'fill_blank', array_column( $args['questions'], 'type' ), true ) ) : ?>
				<div class="dlms-chars" data-dlms-chars hidden>
					<span class="dlms-chars__label"><?php esc_html_e( 'Special characters:', 'deutschlms' ); ?></span>
					<?php foreach ( array( 'ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü' ) as $dlms_char ) : ?>
						<button type="button" class="dlms-chars__key" data-char="<?php echo esc_attr( $dlms_char ); ?>" <?php disabled( $dlms_disabled ); ?>>
							<span aria-hidden="true"><?php echo esc_html( $dlms_char ); ?></span>
							<span class="dlms-sr">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: a character such as ä. */
										__( 'Insert %s', 'deutschlms' ),
										$dlms_char
									)
								);
								?>
							</span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			</div>
			<?php if ( ! $dlms_disabled ) : ?>
				<button type="submit" class="dlms-button"><?php esc_html_e( 'Submit answers', 'deutschlms' ); ?></button>
				<p class="dlms-form-status" role="status" aria-live="polite"></p>
			<?php endif; ?>
			<?php if ( $dlms_timed ) : ?>
				<dialog class="dlms-timeup" data-dlms-timeup aria-labelledby="dlms-timeup-heading-<?php echo esc_attr( (string) $args['quiz_id'] ); ?>">
					<h2 class="dlms-timeup__heading" id="dlms-timeup-heading-<?php echo esc_attr( (string) $args['quiz_id'] ); ?>"><?php esc_html_e( 'Time is up', 'deutschlms' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: seconds. */
								__( 'Please click "Submit answers" so your answers are checked. If you do not submit within %d seconds, your answers are submitted automatically.', 'deutschlms' ),
								$args['time_up_seconds']
							)
						);
						?>
					</p>
					<?php /* translators: %d: seconds. Keep the %d: the page replaces it every second. */ ?>
					<p class="dlms-timeup__countdown" data-dlms-timeup-countdown data-template="<?php echo esc_attr( __( 'Automatic submission in %d seconds.', 'deutschlms' ) ); ?>"></p>
					<button type="submit" class="dlms-button"><?php esc_html_e( 'Submit answers', 'deutschlms' ); ?></button>
				</dialog>
			<?php endif; ?>
		</form>
	<?php endif; ?>
</div>
