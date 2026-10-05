<?php
/**
 * Quiz attempts on the user profile screen.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Quiz\AttemptRepository;
use DeutschLMS\Quiz\QuizService;
use DeutschLMS\Roles\Roles;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a student's quiz attempts on their profile (for LMS admins) with a
 * "Reset attempts" action, so a student who used up their attempts can try
 * again. Resetting keeps the old attempts on record, marked as reset.
 */
final class UserQuizAttempts {

	public const ACTION = 'dlms_reset_quiz_attempts';

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
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'reset' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Attempts table.
	 *
	 * @param WP_User $user Profile user.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( Roles::CAP_MANAGE_ENROLLMENTS ) ) {
			return;
		}

		$attempts = $this->quizzes->all_attempts_of( $user->ID );
		?>
		<h2 id="dlms-quiz-attempts"><?php esc_html_e( 'DeutschLMS quiz attempts', 'deutschlms' ); ?></h2>
		<?php if ( ! $attempts ) : ?>
			<p><?php esc_html_e( 'No quiz attempts yet.', 'deutschlms' ); ?></p>
			<?php
			return;
		endif;

		$reset_links = array();
		?>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Quiz', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'deutschlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Counts toward limit', 'deutschlms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $attempts as $attempt ) : ?>
					<?php
					$counted = AttemptRepository::STATUS_COMPLETED === $attempt['status'];
					if ( $counted ) {
						$reset_links[ $attempt['quiz_id'] ] = true;
					}
					?>
					<tr>
						<td><?php echo esc_html( get_the_title( $attempt['quiz_id'] ) ); ?></td>
						<td><?php echo esc_html( (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( $attempt['created_at'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%d / %d (%s%%)', $attempt['score'], $attempt['max_score'], number_format_i18n( $attempt['percent'], 0 ) ) ); ?></td>
						<td>
							<?php echo $attempt['passed'] ? esc_html__( 'Passed', 'deutschlms' ) : esc_html__( 'Not passed', 'deutschlms' ); ?>
							<?php if ( $attempt['late'] ) : ?>
								<br /><span class="description"><?php esc_html_e( 'Time exceeded', 'deutschlms' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $counted ? esc_html__( 'Yes', 'deutschlms' ) : esc_html__( 'No (reset)', 'deutschlms' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $reset_links ) : ?>
			<p><?php esc_html_e( 'Reset attempts so the student can take a quiz again (past attempts stay listed):', 'deutschlms' ); ?></p>
			<ul>
				<?php foreach ( array_keys( $reset_links ) as $quiz_id ) : ?>
					<li>
						<a class="button" href="<?php echo esc_url( self::reset_url( $user->ID, (int) $quiz_id ) ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: quiz title. */
									__( 'Reset attempts: %s', 'deutschlms' ),
									wp_strip_all_tags( get_the_title( $quiz_id ) )
								)
							);
							?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handles the reset link (nonce + capability checked).
	 */
	public function reset(): void {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$quiz_id = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $user_id . '_' . $quiz_id );

		$result = $this->quizzes->reset_attempts( get_current_user_id(), $user_id, $quiz_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}

		// Back to where the link was clicked (quiz screen or profile).
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = get_current_user_id() === $user_id ? admin_url( 'profile.php' ) : add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) );
		}
		wp_safe_redirect( add_query_arg( 'dlms_reset', (int) $result, remove_query_arg( 'dlms_reset', $back ) ) );
		exit;
	}

	/**
	 * Confirmation after a reset.
	 */
	public function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['dlms_reset'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Quiz attempts were reset. The student can take the quiz again.', 'deutschlms' )
		);
	}

	/**
	 * Nonce-protected reset link.
	 *
	 * @param int $user_id Student.
	 * @param int $quiz_id Quiz ID.
	 * @return string
	 */
	public static function reset_url( int $user_id, int $quiz_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'user_id' => $user_id,
					'quiz_id' => $quiz_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $user_id . '_' . $quiz_id
		);
	}
}
