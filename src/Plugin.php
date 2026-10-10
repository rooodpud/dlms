<?php
/**
 * Plugin orchestrator.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Admin\AudioPage;
use DeutschLMS\Admin\CourseArrange;
use DeutschLMS\Admin\CourseBuilder;
use DeutschLMS\Admin\CourseSettings;
use DeutschLMS\Admin\ListTables;
use DeutschLMS\Admin\NounPicturesPage;
use DeutschLMS\Admin\QuestionEditor;
use DeutschLMS\Admin\QuestionList;
use DeutschLMS\Admin\QuizEditor;
use DeutschLMS\Admin\QuizResults;
use DeutschLMS\Admin\StepMetaBoxes;
use DeutschLMS\Admin\TitleTranslations;
use DeutschLMS\Admin\UserQuizAttempts;
use DeutschLMS\Blocks\Blocks;
use DeutschLMS\Certificates\CertificateController;
use DeutschLMS\Certificates\CertificateService;
use DeutschLMS\Content\AudioClips;
use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\NounPictures;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Content\StructureEditor;
use DeutschLMS\Database\Installer;
use DeutschLMS\Enrollment\EnrollmentRepository;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Frontend\Assets;
use DeutschLMS\Frontend\ContentGate;
use DeutschLMS\Frontend\FormHandler;
use DeutschLMS\Frontend\HelpLanguage;
use DeutschLMS\Frontend\Renderer;
use DeutschLMS\Frontend\Shortcodes;
use DeutschLMS\Integrations\Multilingual;
use DeutschLMS\Progress\ProgressCalculator;
use DeutschLMS\Progress\ProgressRepository;
use DeutschLMS\Progress\ProgressService;
use DeutschLMS\Quiz\AttemptRepository;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\QuizService;
use DeutschLMS\Rest\AudioClipsController;
use DeutschLMS\Rest\CourseBuilderController;
use DeutschLMS\Rest\EnrollmentController;
use DeutschLMS\Rest\HelpLanguageController;
use DeutschLMS\Rest\NounPicturesController;
use DeutschLMS\Rest\ProgressController;
use DeutschLMS\Rest\QuestionBankController;
use DeutschLMS\Rest\QuizController;
use DeutschLMS\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services together and registers hooks. Holds one shared instance of
 * every service so templates, blocks and REST controllers agree on state.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Course structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Structure writer used by the course builder.
	 *
	 * @var StructureEditor
	 */
	private StructureEditor $structure_editor;

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Progress repository.
	 *
	 * @var ProgressRepository
	 */
	private ProgressRepository $progress_repository;

	/**
	 * Progress calculator (read side).
	 *
	 * @var ProgressCalculator
	 */
	private ProgressCalculator $calculator;

	/**
	 * Progress service (write side).
	 *
	 * @var ProgressService
	 */
	private ProgressService $progress;

	/**
	 * Access control.
	 *
	 * @var AccessControl
	 */
	private AccessControl $access;

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private QuestionBank $bank;

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
	 * Front-end renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Builds the service graph.
	 */
	private function __construct() {
		global $wpdb;

		$this->structure           = new CourseStructure();
		$this->structure_editor    = new StructureEditor( $this->structure );
		$this->enrollments         = new EnrollmentService( new EnrollmentRepository( $wpdb ) );
		$this->progress_repository = new ProgressRepository( $wpdb );
		$this->calculator          = new ProgressCalculator( $this->structure, $this->progress_repository );
		$this->access              = new AccessControl( $this->structure, $this->enrollments, $this->calculator );
		$this->progress            = new ProgressService( $this->structure, $this->enrollments, $this->progress_repository, $this->calculator, $this->access );
		$this->bank                = new QuestionBank();
		$this->quizzes             = new QuizService( $this->structure, $this->progress, $this->calculator, $this->access, new AttemptRepository( $wpdb ), $this->bank );
		$this->certificates        = new CertificateService( $this->enrollments, $this->access );
		$this->renderer            = new Renderer( $this->structure, $this->enrollments, $this->calculator, $this->access, $this->quizzes, $this->certificates );
	}

	/**
	 * Registers all hooks. Runs on plugins_loaded.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		( new PostTypes() )->register_hooks();
		( new Capabilities() )->register_hooks();
		( new Multilingual() )->register_hooks();
		$this->bank->register_hooks();
		( new Assets() )->register_hooks();
		( new HelpLanguage() )->register_hooks();
		( new ContentGate( $this->access, $this->renderer ) )->register_hooks();
		( new FormHandler( $this->enrollments, $this->progress, $this->quizzes ) )->register_hooks();
		( new CertificateController( $this->certificates ) )->register_hooks();
		( new Shortcodes( $this->renderer ) )->register_hooks();
		( new Blocks() )->register_hooks();

		add_action( 'clean_post_cache', array( $this->structure, 'flush' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Admin screens. Every action inside them re-checks capabilities; is_admin()
		// only decides whether the UI code is loaded at all.
		if ( is_admin() ) {
			( new CourseBuilder() )->register_hooks();
			( new CourseSettings() )->register_hooks();
			( new CourseArrange() )->register_hooks();
			( new QuizEditor( $this->structure, $this->structure_editor, $this->bank ) )->register_hooks();
			( new QuestionEditor( $this->bank ) )->register_hooks();
			( new QuestionList( $this->structure, $this->bank ) )->register_hooks();
			( new NounPicturesPage( $this->bank ) )->register_hooks();
			( new AudioPage( $this->bank ) )->register_hooks();
			( new UserQuizAttempts( $this->quizzes ) )->register_hooks();
			( new QuizResults( $this->quizzes ) )->register_hooks();
			( new StepMetaBoxes( $this->structure, $this->structure_editor ) )->register_hooks();
			( new TitleTranslations() )->register_hooks();
			( new ListTables( $this->structure ) )->register_hooks();
		}
	}

	/**
	 * Loads translations shipped in the plugin's languages folder.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'deutschlms', false, dirname( plugin_basename( DLMS_FILE ) ) . '/languages' );
	}

	/**
	 * Registers REST controllers.
	 */
	public function register_rest_routes(): void {
		( new EnrollmentController( $this->enrollments, $this->calculator ) )->register_routes();
		( new ProgressController( $this->progress, $this->calculator, $this->enrollments ) )->register_routes();
		( new CourseBuilderController( $this->structure, $this->structure_editor, $this->bank ) )->register_routes();
		( new QuizController( $this->quizzes ) )->register_routes();
		( new QuestionBankController( $this->bank ) )->register_routes();
		( new NounPicturesController() )->register_routes();
		( new AudioClipsController() )->register_routes();
		( new HelpLanguageController() )->register_routes();
	}

	/**
	 * Course structure reader.
	 *
	 * @return CourseStructure
	 */
	public function structure(): CourseStructure {
		return $this->structure;
	}

	/**
	 * Course structure writer.
	 *
	 * @return StructureEditor
	 */
	public function structure_editor(): StructureEditor {
		return $this->structure_editor;
	}

	/**
	 * Enrollment service.
	 *
	 * @return EnrollmentService
	 */
	public function enrollments(): EnrollmentService {
		return $this->enrollments;
	}

	/**
	 * Progress calculator.
	 *
	 * @return ProgressCalculator
	 */
	public function calculator(): ProgressCalculator {
		return $this->calculator;
	}

	/**
	 * Progress service.
	 *
	 * @return ProgressService
	 */
	public function progress(): ProgressService {
		return $this->progress;
	}

	/**
	 * Access control.
	 *
	 * @return AccessControl
	 */
	public function access(): AccessControl {
		return $this->access;
	}

	/**
	 * Question bank.
	 *
	 * @return QuestionBank
	 */
	public function question_bank(): QuestionBank {
		return $this->bank;
	}

	/**
	 * Quiz service.
	 *
	 * @return QuizService
	 */
	public function quizzes(): QuizService {
		return $this->quizzes;
	}

	/**
	 * Certificate service.
	 *
	 * @return CertificateService
	 */
	public function certificates(): CertificateService {
		return $this->certificates;
	}

	/**
	 * Front-end renderer.
	 *
	 * @return Renderer
	 */
	public function renderer(): Renderer {
		return $this->renderer;
	}

	/**
	 * Clears per-request caches. Used by tests between scenarios.
	 */
	public function flush_runtime_caches(): void {
		$this->structure->flush();
		NounPictures::flush();
		AudioClips::flush();
	}
}
