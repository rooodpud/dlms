# DeutschLMS

A secure, lightweight learning management system (LMS) for WordPress.
Build courses out of lessons, topics and quizzes, enroll students, track
their progress and hand out PDF certificates. It was built for teaching
German, so its quizzes include gap-fill and word-order questions with
an ä/ö/ü/ß keyboard bar, but it works for any subject.

- **Version:** 0.2.0
- **Requires:** WordPress 6.5 or newer, PHP 8.1 or newer
- **License:** GPL-2.0-or-later (see [License](#license))
- **Languages:** English, plus a full German translation

> **Status: early but in production.** DeutschLMS runs a live German
> course with 30 lessons and about 2,400 questions. Version numbers stay
> below 1.0 while features are still being added, and paid enrollment is
> not built in yet (see [Roadmap](#roadmap)). Back up your site before you
> install or update any plugin.

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Download and install](#download-and-install)
- [Updating](#updating)
- [Quick start: your first course](#quick-start-your-first-course)
- [The question bank](#the-question-bank)
- [Question types](#question-types)
- [Course rules](#course-rules)
- [Roles and permissions](#roles-and-permissions)
- [Blocks and shortcodes](#blocks-and-shortcodes)
- [Customising the look](#customising-the-look)
- [Translations and multilingual sites](#translations-and-multilingual-sites)
- [Your data: what is stored, and uninstalling](#your-data-what-is-stored-and-uninstalling)
- [FAQ and troubleshooting](#faq-and-troubleshooting)
- [Developer reference](#developer-reference)
- [Development](#development)
- [Roadmap](#roadmap)
- [License](#license)

---

## Features

**Courses**
- Courses → lessons → topics → quizzes, in any combination. Quizzes can sit
  under a topic, a lesson or the whole course (a final test).
- A drag-and-drop **course builder** on the course screen: add, rename and
  reorder lessons, topics and quizzes, and add section headings ("Part 1:
  Grammar") between lessons.
- **Free enrollment** with one click. Course pages are public; lesson, topic
  and quiz content is only for enrolled students.
- **Linear progression** (optional, per course): students must finish each
  step before the next one opens.
- **Drip content:** open a lesson a set number of days after a student
  enrolls.
- **Progress tracking:** "Mark complete" buttons, automatic completion of
  lessons and topics when all their parts are done, progress bars.
- **Student dashboard** with enrolled courses, progress and certificates.

**Quizzes**
- Four question types: multiple choice, true/false, gap-fill and word order
  (drag-and-drop or dropdowns; works on phones).
- **Graded on the server.** The quiz page never contains the correct
  answers, so they can't be read from the page source.
- Pass mark, attempt limit, optional answer review, and an optional
  **time limit** that survives page reloads.
- Instructors can see every student's attempts and reset a student's
  attempts.

**Question bank** (new in 0.2.0)
- Every question lives in one central bank (Courses → Questions) and can be
  used in any number of quizzes. Fix a typo once and every quiz that uses
  the question is fixed.
- Organise questions by category, difficulty (Easy / Medium / Hard, German
  labels "Leicht / Mittel / Schwer") and CEFR level (A1–C2).
- **Random questions:** a quiz can say "5 random questions from category X,
  difficulty Y", and each student gets a different set.

**Certificates**
- A PDF certificate when a student completes a course, made on demand
  (nothing is stored in your uploads folder). You can set a title, a signer
  and a background image per course, or replace the template completely.

**For site builders and developers**
- Six blocks and matching shortcodes, overridable templates, a REST API,
  actions and filters.
- Four roles: Student, Instructor, LMS Admin, plus the Administrator.
- Works with WPML and Polylang: progress counts across translations.
- No external services, no tracking, no ads. The only bundled library is
  [Dompdf](https://github.com/dompdf/dompdf), for the certificates.

---

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 6.5 |
| PHP | 8.1 |
| PHP extensions | `dom` and `mbstring` (standard on almost every host). `gd` is needed for background images on certificates. |
| Database | MySQL 5.7+ or MariaDB 10.3+ (whatever your WordPress uses) |

Nothing has to be installed with Composer or npm to *use* the plugin: the
download already contains everything it needs to run (`vendor/` and
`build/`).

---

## Download and install

### Option A: download the ZIP (easiest)

1. On this GitHub page, click the green **Code** button, then **Download
   ZIP**. You get a file called `dlms-main.zip`.
2. **Rename the folder (recommended).** Unzip the file, rename the folder
   `dlms-main` to `deutschlms`, and zip it again. The plugin works under any
   folder name, but `deutschlms` keeps updates simple (see
   [Updating](#updating)).
3. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose
   the ZIP and click **Install Now**.
4. Click **Activate**.

### Option B: upload by FTP / file manager

1. Download and unzip as above.
2. Upload the folder to `wp-content/plugins/deutschlms/`.
3. In WordPress, go to **Plugins** and activate **DeutschLMS**.

### Option C: Git

```bash
cd wp-content/plugins
git clone https://github.com/rooodpud/dlms.git deutschlms
```

Then activate the plugin under **Plugins**.

### What happens on activation

- Three database tables are created: enrollments, progress and quiz
  attempts.
- The roles Student, Instructor and LMS Admin are added, and
  Administrators get all LMS permissions.
- Default difficulty levels and CEFR levels are added to the question bank
  (on the first page load after activation).
- The permalinks are refreshed so that `/courses/…` URLs work.

A new **Courses** menu appears in the admin sidebar.

> If course pages show "Page not found" after activation, go to
> **Settings → Permalinks** and click **Save Changes** once.

---

## Updating

1. **Back up** your site (files and database).
2. Download the new version as described above.
3. Replace the plugin folder: either upload the new ZIP under
   **Plugins → Add New Plugin → Upload Plugin** (WordPress offers to
   replace the installed version when the folder names match), or overwrite
   the files in `wp-content/plugins/deutschlms/` by FTP.

Database changes run automatically on the next page load. Your courses,
students, progress and quiz results are kept.

**Updating from 0.1.x to 0.2.0:** 0.2.0 adds the question bank. Existing
quizzes keep working: a quiz that has not been opened and saved in the new
quiz builder still uses the questions stored in it before. To move the
existing questions into the bank, open each quiz in the admin and click
**Update**: its questions are then added to the bank and linked to the quiz.
(Bulk migration scripts are not part of this repository.)

---

## Quick start: your first course

1. **Create the course.** Go to **Courses → Add New Course**, give it a title
   and a description (the description is the public course page), and click
   **Publish**.
2. **Build the outline.** Scroll down to the **Course builder** box on the
   same screen:
   - **Add lesson** for each lesson;
   - **Add topic** inside a lesson to split it into parts;
   - **Add quiz** under a topic, a lesson or the whole course;
   - **Add heading** to group lessons into sections;
   - drag items to reorder them, then click **Save order**.
3. **Write the content.** Click a lesson or topic in the builder (or use
   **Courses → Lessons / Topics**) and write it like any WordPress post:
   text, images, videos, downloads.
4. **Add questions.** Open a quiz (**Courses → Quizzes**). In the
   **Questions** box:
   - **Add new question** writes a new question (it is saved to the bank
     too);
   - **Add from question bank** searches the bank and adds existing
     questions;
   - **Add random questions** adds a rule such as "3 random gap-fill
     questions from category Perfekt".

   In **Quiz settings**, set the pass mark, the number of attempts, the
   time limit (0 = none) and whether students see the correct answers
   afterwards. Click **Update**.
5. **Course settings** (box on the course screen): turn on **Linear
   progression** or **Certificate on completion**, and set the certificate
   heading, the signer's name and an optional background image (landscape
   A4).
6. **Drip** (optional): on a lesson, set **Unlock after (days)**.
7. **Publish** the lessons, topics and quizzes. Drafts are hidden from
   students.
8. **Check it as a student.** Create a user with the role *Student* (or any
   role; Subscribers can take courses too), open the course page, click
   **Enroll** and work through it.

To list your courses on a page, add the **Course grid** block (or
`[dlms_course_grid]`). For a "My courses" page, add the **Student dashboard**
block (or `[dlms_student_dashboard]`).

---

## The question bank

**Courses → Questions** lists every question.

- **Columns:** the question, its type (with a marker when it is incomplete),
  category, difficulty, CEFR level and **Used in** (the quizzes that use
  it).
- **Filters:** type, category, difficulty, CEFR level, and course or lesson
  (shows the questions used in that course or lesson).
- **Categories, Difficulty levels, CEFR levels** have their own submenus,
  where you can rename, add or nest them. Categories can have
  sub-categories, for example *A2 › Lektion 3*.

**Linked editing.** A quiz links to bank questions; it doesn't copy them.
When you edit a question (in the bank or inside any quiz), the change
appears in every quiz that uses it, and the editor says how many quizzes
that is. **Remove from quiz** only unlinks the question: it stays in the
bank.

**Random questions** are drawn from published, complete questions that
match the rule, never twice in the same quiz and never one the quiz already
contains. The draw is made when the student opens the quiz and stays the
same until they submit, so a reload doesn't reshuffle the quiz.

**Incomplete questions** (for example without a correct answer) are saved
but never shown to students.

---

## Question types

All questions are all-or-nothing: a question earns its points only when it
is answered completely right.

| Type | What students do | How you write it |
| --- | --- | --- |
| **Multiple choice** | Pick one answer, or several if more than one is correct | Answers, with a checkbox for each correct one |
| **True / false** | Pick True or False | Choose the correct one |
| **Gap-fill** | Type the missing words into gaps in the text | Put each answer in curly braces, other accepted answers after a `\|`: `Ich {bin} nach Berlin {gefahren}.` or `Er {ist\|war} müde.` |
| **Word order** | Build the sentence from shuffled words or blocks | List the blocks in the correct order; add other correct orders as alternatives |

- **Gap-fill:** extra spaces and different quote characters are forgiven;
  capital letters and ä/ö/ü/ß count. A bar with ä, ö, ü, ß, Ä, Ö, Ü buttons
  helps students without a German keyboard.
- **Word order** has two display modes, chosen per question:
  - **drag** (default): drag or tap the tiles into place; works with touch;
  - **dropdowns:** one dropdown per position; a word chosen in one is not
    offered in the others.

  The built sentence starts with a capital letter and ends with the ".",
  "?" or "!" taken from the end of the question text. When comparing
  answers, capitalisation and punctuation are ignored. Without JavaScript,
  both modes fall back to plain dropdowns.

---

## Course rules

- **Access.** Course pages (overview and outline) are public. Lesson,
  topic and quiz content needs a login and an enrollment. Anyone who can
  edit a course (its author, LMS Admins, Administrators) can always view
  it, to preview.
- **Completion.**
  - A lesson or topic without quizzes or topics under it is completed with
    the **Mark complete** button.
  - A quiz is complete once the student passes it. Later attempts never undo
    that.
  - A lesson or topic with topics or quizzes under it completes
    automatically when all of them are complete.
  - The course is complete when every published step is complete, final
    quizzes included.
- **Linear progression.** Every earlier step must be complete. The
  exception is the step's own lesson or topic: a topic's quiz opens
  together with the topic, and passing it is what completes the topic.
- **Drip.** A lesson opens a number of days after the student enrolled.
  Its topics and quizzes follow it. Students see the opening date.
- **Time limit.** The clock starts when the student first opens the quiz
  and keeps running through reloads. At 0:00 the answers lock and the
  student is asked to submit; after 15 seconds the answers are submitted
  automatically. An attempt that arrives too late is still graded and
  stored, but marked *late* and not counted as passed.
- **Attempts.** When a student has used up the attempt limit without
  passing, the instructor or an LMS Admin can reset their attempts. This can
  be done on the quiz screen (**Student attempts**) or on the user's
  profile.
- **Certificates.** When the course is complete, students get a certificate
  link: in the enroll box, on the dashboard and in the completion notice.

---

## Roles and permissions

| Role | Can |
| --- | --- |
| **Student** | Enroll in free courses, take lessons and quizzes, see their own progress and certificates |
| **Instructor** | Create, edit and publish **their own** courses, lessons, topics, quizzes and questions; see and reset attempts on their own quizzes; take courses |
| **LMS Admin** | Manage all LMS content (including other people's), enrollments and reports |
| **Administrator** | Everything |
| Subscriber, Contributor, Author, Editor | Can take courses (change this with the `dlms_learner_roles` filter) |

Assign roles under **Users → Edit user → Role**.

---

## Blocks and shortcodes

Course, lesson, topic and quiz pages get the LMS parts added automatically:
the enroll box, the outline, "Mark complete", the quiz, and previous/next
links. On course, lesson and topic pages, if you place one of these blocks
or shortcodes yourself, the automatic output is skipped so that you control
the layout (filter: `dlms_auto_append_content`). Quiz pages always show the
quiz.

| Block | Shortcode | Shows |
| --- | --- | --- |
| Course outline | `[dlms_course_outline course_id="" show_topics="yes"]` | Lessons, topics and quizzes with completion ticks |
| Progress bar | `[dlms_progress_bar course_id="" show_label="yes"]` | The student's progress in a course |
| Enroll button | `[dlms_enroll_button course_id=""]` | Enroll / Continue / Certificate button |
| Mark complete | `[dlms_mark_complete step_id=""]` | The "Mark complete" button for a lesson or topic |
| Course grid | `[dlms_course_grid columns="3" per_page="12" orderby="date" show_progress="yes"]` | A grid of courses |
| Student dashboard | `[dlms_student_dashboard show_completed="yes"]` | The student's courses, progress and certificates |

An empty `course_id` / `step_id` means "the course or step of the current
page". The blocks are in the **DeutschLMS** category of the block inserter.

---

## Customising the look

**Templates.** Copy any file from the plugin's `templates/` folder to
`your-theme/deutschlms/` (keep the same sub-folder) and edit the copy. A
child theme's copy wins over the parent theme's. Your changes survive
plugin updates.

**Certificate template.** `templates/certificate/certificate.php` is turned
into a PDF by Dompdf, which supports only part of CSS:
- use simple CSS (no flexbox or grid) and the DejaVu fonts;
- use only local images from your uploads folder;
- position boxes with `top`, not `bottom`.

**URLs.** The defaults are `/courses/`, `/lessons/`, `/topics/` and
`/quizzes/`. Change them with the filters `dlms_course_slug`,
`dlms_course_archive_slug`, `dlms_lesson_slug`, `dlms_topic_slug` and
`dlms_quiz_slug`, then save the permalinks once.

**Styles.** The front-end CSS uses `dlms-` class names (BEM style, for
example `.dlms-outline__section`), so your theme can override it.

---

## Translations and multilingual sites

- Text domain `deutschlms`; the template is `languages/deutschlms.pot`.
- A complete **German** translation ships in `languages/`, using the formal
  "Sie". WordPress uses it automatically when the site language is
  German.
- To show the German texts to students on a site whose admin is in
  another language, load the file for front-end requests from your theme:

  ```php
  load_textdomain( 'deutschlms', WP_PLUGIN_DIR . '/deutschlms/languages/deutschlms-de_DE.mo', determine_locale() );
  ```

- Dates shown to students (opening dates, certificates) use the
  translation's date format and month names, for example "3. Oktober
  2026".
- **WPML / Polylang:** enrollments, progress and attempts are stored against
  the default-language course, so they count across all translations.
  `wpml-config.xml` copies the structure and settings to translations.

---

## Your data: what is stored, and uninstalling

- **Content** (courses, lessons, topics, quizzes, questions) is ordinary
  WordPress posts, with post meta and taxonomies.
- **Student data** is stored in three tables (`{prefix}dlms_enrollments`,
  `{prefix}dlms_progress`, `{prefix}dlms_quiz_attempts`) and in a few user
  meta fields: the running quiz clock and the current random draw. Both are
  removed after each attempt.
- Quiz attempts store a graded copy of the answers. A reset marks attempts
  as reset; it doesn't delete them.
- The plugin sends nothing to external services and sets no cookies of its
  own (it uses the normal WordPress login).

**Deactivating or deleting the plugin keeps all data.** There is no
automatic cleanup, so you can't lose student progress by accident. To
remove everything, delete the content in the admin first, then drop the
three tables and the `dlms_*` options with a database tool.

---

## FAQ and troubleshooting

**Course pages show "Page not found".**
Go to **Settings → Permalinks** and click **Save Changes**.

**Students don't see a quiz in the outline.**
A quiz only appears when it has at least one complete question and is
published. Incomplete questions are marked in the quiz builder.

**Can I sell courses?**
Not yet built in: enrollment is free. To limit enrollment (for example to
members of a membership plugin), use the `dlms_course_is_enrollable` filter
or `dlms_step_access`.

**Can students see the correct answers in the page source?**
No. Answers are checked on the server, and the quiz form never contains
them.

**The certificate download fails.**
Make sure the PHP extensions `dom` and `mbstring` are enabled (and `gd` if
you use a background image), and that the background image is in your own
uploads folder.

**Word order dragging doesn't work on a phone.**
Switch the question's display to **Dropdowns** in the question editor.

**I found a bug / have an idea.**
Please open an issue on GitHub.

---

## Developer reference

### Data model

| Thing | Stored as |
| --- | --- |
| Course / Lesson / Topic / Quiz | Post types `dlms_course`, `dlms_lesson`, `dlms_topic`, `dlms_quiz` |
| Hierarchy | Post meta `_dlms_course_id` (lessons, topics, quizzes), `_dlms_lesson_id` (topics), `_dlms_parent_id` (quizzes: lesson/topic ID, or 0 = whole course); order = `menu_order` |
| Course settings | `_dlms_linear_progression`, `_dlms_certificate_enabled`, `_dlms_certificate_title`, `_dlms_certificate_signer`, `_dlms_certificate_background` |
| Section headings | Course meta `_dlms_section_headings`: list of `{ id, title, before }`, `before` = the lesson the heading stands above (0 = after the last lesson). Not steps: no progress, no page. In the student view a heading above a draft lesson moves down to the next published lesson |
| Lesson setting | `_dlms_drip_days` (days after enrollment) |
| Question bank | Post type `dlms_question` (admin only): `_dlms_question` (the question incl. its stable ID and correct answers), `_dlms_question_key`, `_dlms_question_type`, `_dlms_question_ready` ('1' = complete). Taxonomies `dlms_question_category` (hierarchical), `dlms_question_difficulty`, `dlms_question_level` (one term each; defaults Leicht/Mittel/Schwer, A1–C2, option `dlms_question_terms_version`) |
| Quiz content | `_dlms_quiz_items`: ordered `{ kind: question, question: post ID }` and `{ kind: random, count, category, difficulty, level, type }`. Without it a quiz uses its own `_dlms_questions` (from before the bank; kept as a backup) |
| Random draws | User meta `_dlms_quiz_draws`: canonical quiz ID => the questions drawn for the current attempt (removed after each attempt) |
| Quiz settings | `_dlms_pass_mark`, `_dlms_attempts_limit`, `_dlms_show_answers`, `_dlms_time_limit` (minutes, 0 = none) |
| Running quiz clocks | User meta `_dlms_quiz_timers`: canonical quiz ID => Unix time the current attempt started (removed after each attempt) |
| Enrollments | Table `{prefix}dlms_enrollments` (unique per user + course) |
| Progress | Table `{prefix}dlms_progress` (one row per completed step, unique per user + step) |
| Quiz attempts | Table `{prefix}dlms_quiz_attempts` (one row per attempt, with a graded snapshot of the answers, `started_at` and `late` for timed quizzes; resets mark rows `reset` instead of deleting them) |
| Schema version | Option `dlms_db_version` (1.2.0); roles version `dlms_roles_version` (3) |

Course order:

    Lesson 1
      Topic 1.1
        Quiz (topic quiz)
      Quiz (lesson quiz)
    Lesson 2
    …
    Quiz (course-level / final quiz)

### Capabilities

Roles: `dlms_student`, `dlms_instructor`, `dlms_lms_admin`. Primitive
capabilities include `dlms_take_courses`, `dlms_manage_lms`,
`dlms_manage_enrollments`, `dlms_view_reports` and the post-type
capabilities (`edit_dlms_courses`, `edit_dlms_questions`, …). Meta
capabilities: `dlms_enroll_course` (course ID), `dlms_complete_step`
(step or quiz ID).

### REST API (`dlms/v1`)

| Method | Route | Permission |
| --- | --- | --- |
| POST | `/courses/{id}/enroll` | logged in + `dlms_enroll_course` |
| GET | `/me/enrollments` | logged in + `dlms_take_courses` |
| POST | `/steps/{id}/complete` | logged in + `dlms_complete_step` (+ enrollment, drip, linear rules) |
| GET | `/courses/{id}/progress` | logged in + enrolled |
| POST | `/quizzes/{id}/attempts` | logged in + `dlms_complete_step` (+ enrollment, drip, linear, attempts limit); body `{ "answers": { "<question id>": [ … ] } }`: selected answer IDs, word-order block IDs in order, or typed gap answers in gap order |
| GET | `/quizzes/{id}/attempts` | logged in + `dlms_complete_step`; own attempts (scores only) |
| GET/PUT | `/courses/{id}/structure` | `edit_post` on the course (+ each item). Also carries `headings` (`[ { id, title, before } ]`, max 50); PUT without `headings` keeps them, an empty list removes them; a heading above a lesson of another course is rejected (`dlms_invalid_heading`) |
| POST | `/courses/{id}/lessons` | `edit_post` on the course + create lessons |
| POST | `/lessons/{id}/topics` | `edit_post` on lesson and course + create topics |
| POST | `/courses/{id}/quizzes` | `edit_post` on the course (+ parent) + create quizzes; body `{ "title", "parent_id" }` |
| GET | `/questions` | `edit_dlms_questions`; params `search`, `type`, `category`, `difficulty`, `level`, `page`, `per_page` (max 50) |
| GET | `/questions/count` | `edit_dlms_questions`; same filters, returns the number of complete published matches |

Cookie-authenticated requests must send the `wp_rest` nonce (`X-WP-Nonce`);
application passwords work for server-to-server use. Certificates:
`admin-post.php?action=dlms_certificate&course_id=…` (holder, course
instructor or LMS Admin only).

### Hooks

Actions:
- `dlms_user_enrolled( $user_id, $course_id, $source )`
- `dlms_step_completed( $user_id, $step_id, $course_id, $step_type )`
- `dlms_course_completed( $user_id, $course_id )` (fires once)
- `dlms_quiz_attempted( $user_id, $quiz_id, $attempt_id, $passed, $percent )`
- `dlms_quiz_attempts_reset( $user_id, $quiz_id, $actor_id )`
- `dlms_course_structure_saved( $course_id, $user_id )`

Filters: `dlms_step_access`, `dlms_course_is_enrollable`,
`dlms_learner_roles`, `dlms_auto_append_content`, `dlms_locate_template`,
`dlms_template_args`, `dlms_certificate_data`, `dlms_canonical_post_id`,
`dlms_course_slug`, `dlms_course_archive_slug`, `dlms_lesson_slug`,
`dlms_topic_slug`, `dlms_quiz_slug`.

Example: send a welcome email when a student enrolls.

```php
add_action( 'dlms_user_enrolled', function ( $user_id, $course_id ) {
	$user = get_userdata( $user_id );
	wp_mail( $user->user_email, 'Welcome!', 'You are enrolled in ' . get_the_title( $course_id ) . '.' );
}, 10, 2 );
```

### Code layout

| Path | Contents |
| --- | --- |
| `deutschlms.php` | Plugin header, version and requirement checks, bootstrap |
| `src/` | PHP classes (namespace `DeutschLMS\`, PSR-4): `Access`, `Admin`, `Blocks`, `Certificates`, `Content`, `Database`, `Enrollment`, `Frontend`, `Integrations`, `Progress`, `Quiz`, `Rest`, `Roles` |
| `templates/` | Overridable front-end templates and the certificate |
| `assets/` | Front-end and admin JavaScript and CSS (no build step) |
| `blocks/` → `build/` | Block sources and their compiled output (committed) |
| `languages/` | `.pot`, German `.po`/`.mo`/`.l10n.php` |
| `vendor/` | Composer autoloader and Dompdf (runtime only, committed) |
| `tests/` | PHPUnit integration tests |
| `tools/dev/` | Development tooling (Composer project for PHPUnit, PHPCS, WP-CLI i18n) |

---

## Development

You only need this if you want to change the code. To run the plugin,
nothing needs to be installed.

`vendor/` holds Composer's autoloader and the one runtime library, Dompdf
(with php-font-lib, php-svg-lib, masterminds/html5 and
sabberworm/php-css-parser; licenses under [License](#license)). `build/` holds the compiled blocks. The dev
tools live separately in `tools/dev/`, so they never ship with the plugin.

Install [Composer](https://getcomposer.org/download/) as `tools/composer.phar`
(this file is not in the repository) and Node.js 20+. Then:

```bash
# PHP tooling (PHPUnit, PHPCS + WPCS, WP-CLI i18n) in tools/dev
php tools/composer.phar dev:install
# Blocks
npm install
npm run build        # or: npm start (watch mode)

# Tests: use their own database "deutschlms_tests", which is emptied on every run
php tools/composer.phar test
# Coding standards (WordPress Coding Standards)
php tools/composer.phar lint
npm run lint:js
npm run lint:css
# Translation template
php tools/composer.phar make-pot
# Translations: update the .po files, then compile .mo and .l10n.php
php tools/composer.phar make-translations
```

**Tests** need a WordPress install and a MySQL database called
`deutschlms_tests`. They refuse to run against any other database. The
defaults in `tests/wp-tests-config.php` use the WordPress install the
plugin sits in (`wp-content/plugins/deutschlms`) and database user `root`
with no password. Override them
with the environment variables `DLMS_TESTS_ABSPATH`, `DLMS_TESTS_DB_NAME`,
`DLMS_TESTS_DB_USER`, `DLMS_TESTS_DB_PASSWORD` and `DLMS_TESTS_DB_HOST`.

On Windows/XAMPP, use `C:\xampp\php\php.exe` and add `-d extension=zip` to
Composer commands.

**Contributing.** Issues and pull requests are welcome. Please keep PHPCS,
ESLint and stylelint clean, add or update tests, and describe the change in
the pull request.

---

## Roadmap

Planned, not built yet:
- Paid courses (WooCommerce) and groups
- Reports for instructors
- An automatic update notice in the WordPress admin

---

## License

DeutschLMS is free software, licensed under the **GNU General Public
License, version 2 or (at your option) any later version**
(GPL-2.0-or-later), like WordPress itself. The `LICENSE` file in this
repository contains the text of GPL version 3, which this license allows
you to choose.

Bundled libraries keep their own licenses: Dompdf (LGPL-2.1),
php-font-lib (LGPL-2.1-or-later), php-svg-lib (LGPL-3.0-or-later),
masterminds/html5 and sabberworm/php-css-parser (MIT).

Copyright © 2026 Pradeep Hingorani.
