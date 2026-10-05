<?php
/**
 * Student attempts on the quiz edit screen.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuizService;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * "Student attempts" box on the quiz edit screen: who took the quiz, their best
 * score, and a reset link for students who need another try. Visible to
 * everyone who can edit the quiz (its instructor, LMS admins, administrators);
 * the reset itself re-checks permissions in QuizService::reset_attempts().
 */
final class QuizResults {

	/**
	 * Quiz service.
	 *
	 * @var QuizService
	 */
	private QuizService $quizzes;

	/**
	 * Constructor.
	 *
	 * @param QuizService $quizzes Quiz service.
	 */
	public function __construct( QuizService $quizzes ) {
		$this->quizzes = $quizzes;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::QUIZ, array( $this, 'add_meta_box' ) );
	}

	/**
	 * Adds the box.
	 */
	public function add_meta_box(): void {
		add_meta_box( 'dlms-quiz-results-box', __( 'Student attempts', 'deutschlms' ), array( $this, 'render' ), PostTypes::QUIZ, 'normal', 'low' );
	}

	/**
	 * Renders the summary table.
	 *
	 * @param WP_Post $post Quiz.
	 */
	public function render( WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$rows = $this->quizzes->student_summary( $post->ID );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No student has taken this quiz yet.', 'deutschlms' ) . '</p>';
			return;
		}

		$limit = $this->quizzes->settings( $post->ID )['attempts_limit'];
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Student', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attempts', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Best score', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'deutschlms' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'deutschlms' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $student = get_userdata( $row['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $student ? $student->display_name : '#' . $row['user_id'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								$limit
									/* translators: 1: attempts used, 2: attempts allowed. */
									? sprintf( __( '%1$d of %2$d', 'deutschlms' ), $row['attempts'], $limit )
									: (string) $row['attempts']
							);
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['best_percent'], 0 ) . '%' ); ?></td>
						<td><?php echo $row['passed'] ? esc_html__( 'Passed', 'deutschlms' ) : esc_html__( 'Not passed', 'deutschlms' ); ?></td>
						<td>
							<a href="<?php echo esc_url( UserQuizAttempts::reset_url( $row['user_id'], $post->ID ) ); ?>">
								<?php esc_html_e( 'Reset attempts', 'deutschlms' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( $student ? $student->display_name : '' ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Resetting lets a student take the quiz again; past attempts stay on record. A quiz that was passed stays passed.', 'deutschlms' ); ?></p>
		<?php
	}
}
