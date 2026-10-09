# Changelog

All notable changes to DeutschLMS. Newest first. Versions stay below 1.0
while features are still being added (see the [README](README.md)).

## 0.5.0 – 2026-10-10

Word cards, flashcards and a help language switch for beginners who can't
read German instructions yet.

**Added**
- **Meanings on cards:** noun cards and word cards show a word's meaning in
  other languages (English, Tagalog, Cebuano, Hindi; one line each).
- **Word cards** `[dlms_word]` for any word or phrase (greetings, verbs,
  numbers), with a picture, a play button and meanings.
- **Flashcard decks** `[dlms_flashcards]`: one card at a time, turn over,
  next, back, shuffle; picture first or German first (`flashcards.js`).
- **"Slow speech" check box** (German: *Langsam sprechen*): plays
  recordings and the browser's voice more slowly; it shows a tick while it
  is on. Dialogues and decks have it too.
- **OpenMoji colour pictures** (CC BY-SA 4.0): a bundled subset in
  `assets/icons/openmoji/` (`emoji:1F44B`), next to the Tabler icons.
- **Speaker recordings in dialogues:** a dialogue line uses the recording
  saved as "Speaker: text" first, so a line keeps its voice even when the
  same text is also a word card.
- **Help language switch:** a course can let learners choose Deutsch,
  English or Tagalog for buttons, messages, headings, instructions, help
  texts, titles, question translations and explanations. German course
  content never changes and is marked `translate="no"`.
  - Course settings: **Help languages** and the start language (course
    meta `_dlms_help_languages`, `_dlms_help_default`).
  - The choice is kept in the browser and in the learner's account (user
    meta `dlms_help_language`, `POST /dlms/v1/help-language`); switching
    needs no reload, a quiz in progress keeps its answers.
  - The switch stays on screen: a panel at the right edge on wide
    screens, a 🌐 tab at the right edge on small screens (tap or swipe
    left to open).
  - **Title translations** box for courses, lessons, topics and quizzes
    (post meta `_dlms_title_help`); **Translations** in the question editor
    (question fields `help` and `explanation_help`).
  - Shortcodes `[dlms_t]` and `[dlms_lang]`; helpers `dlms_t()`,
    `dlms_e()`, `dlms_attr()`, `dlms_title()`; filter `dlms_help_languages`
    for more languages.
  - **Exercise instructions** (quiz hints, word-order hints, flashcard
    hint) show the German text with the translation below
    (`dlms_instruction()`); in Deutsch only German.
- Tagalog translation of the plugin (`languages/deutschlms-tl.*`).

**Changed**
- Every plugin CSS and JS URL carries the file's modification time
  (`dlms_asset_version()`), so browsers load a changed file at once
  instead of an old cached copy.
- All front-end templates print their texts through the help language
  helpers; courses without help languages look as before.

**Developer**
- New classes `Frontend\HelpLanguage`, `Rest\HelpLanguageController`,
  `Admin\TitleTranslations`; tests `FlashcardsTest`, `HelpLanguageTest`
  (192 tests).

## 0.4.0 – 2026-10-09

Audio.

- `[dlms_say]` play buttons on German sentences (optional
  `voice="female|male"`) and play buttons on noun cards.
- `[dlms_dialog]`: a button per line and "Ganzen Dialog anhören", with a
  female or male voice per speaker.
- Listening questions: any question type can carry a German text
  (`listen`) and/or a recording (`audio`).
- Audio library (Courses → Audio): one recording per German text, stored in
  the option `dlms_audio_clips`; REST route `POST /dlms/v1/audio-clips`.
  Without a recording the browser reads the text with a German voice.

## 0.3.0 – 2026-10-06

Article questions, noun pictures, quiz test mode.

- New question type **article**: pick der (blue), die (red) or das (green)
  for a noun shown with its picture.
- Noun picture library (Courses → Noun pictures): one picture per noun, a
  bundled Tabler icon (MIT) or a Media Library image; REST route
  `POST /dlms/v1/noun-pictures`.
- Shortcodes `[dlms_noun]`, `[dlms_nouns]` and `[dlms_article_legend]`.
- Course managers who are not enrolled can try quizzes (test mode): answers
  are graded but nothing is saved.

## 0.2.0 – 2026-10-05

First public release: courses, lessons, topics and quizzes with a
drag-and-drop course builder, free enrollment, linear progression, drip
content, progress tracking, PDF certificates (Dompdf), blocks, shortcodes,
REST API and roles. Quizzes with multiple choice, true/false, gap-fill and
word order, graded on the server, with pass mark, attempt limit and time
limit; a central question bank with categories, difficulty, CEFR level and
random-question rules. German translation included.
