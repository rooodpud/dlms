/**
 * DeutschLMS play buttons ([dlms_say], [dlms_dialog], noun cards, listening
 * questions).
 *
 * A button with data-dlms-src plays that recording. A button with only
 * data-dlms-say reads its text with a German voice of the browser (Web
 * Speech API); data-dlms-voice="female|male" asks for a woman's or a man's
 * voice. If a recording fails to load, the text is read instead (when the
 * button has it). The "whole dialogue" button of a [dlms_dialog] plays its
 * lines one after the other. One sound at a time; clicking the playing
 * button stops it.
 *
 * The "slow" switches (.dlms-speed) make recordings and the browser's voice
 * slower; the choice is kept in localStorage and applies to all switches.
 *
 * Messages follow the help language switch (help-language.js); works for
 * visitors who are not logged in.
 */
( function () {
	'use strict';

	const strings = window.dlmsAudio || {};
	const help = window.dlmsHelp;
	const FALLBACK = {
		noSpeech: 'Audio is not available.',
		noVoice: 'No German voice.',
		failed: 'The recording could not be played.',
	};
	const synth = window.speechSynthesis || null;

	// Pause between the lines of a dialogue (milliseconds).
	const PAUSE = 700;

	// Playback speed of recordings and of the browser's voice when "slow" is on.
	const SLOW_RATE = 0.75;
	const SPEECH_RATE = 0.9;
	const SLOW_SPEECH_RATE = 0.65;
	const SLOW_KEY = 'dlmsSlow';

	let slow = false;
	try {
		slow = '1' === window.localStorage.getItem( SLOW_KEY );
	} catch {
		slow = false;
	}

	// German voices whose names say whether they are a woman's or a man's
	// (Windows, Edge, Chrome, macOS/iOS). Other voices count as unknown.
	const FEMALE =
		/\b(katja|hedda|amala|seraphina|louisa|elke|gisela|klarissa|maja|tanja|ingrid|leni|anna|petra|helena|marlene|vicki|sabine|sandy|shelley|grandma|google deutsch|female|weiblich)\b/i;
	const MALE =
		/\b(conrad|killian|florian|stefan|bernd|christoph|kasper|klaus|ralf|jonas|jan|markus|yannick|martin|hans|eddy|flo|reed|rocko|grandpa|male|männlich)\b/i;

	let current = null; // { buttons: Element[], stop: Function }
	let voices = null; // German voices, best first.
	let utterance = null; // Kept, so the browser doesn't drop its events.

	/**
	 * The German voices of this browser, best first.
	 *
	 * @return {SpeechSynthesisVoice[]} Voices.
	 */
	function germanVoices() {
		if ( ! synth ) {
			return [];
		}
		if ( voices && voices.length ) {
			return voices;
		}
		const rank = function ( candidate ) {
			let score = 0;
			if ( /^de[-_]DE$/i.test( candidate.lang ) ) {
				score += 4;
			}
			if (
				/natural|online|neural|google|premium|enhanced/i.test(
					candidate.name
				)
			) {
				score += 2;
			}
			if ( candidate.localService ) {
				score += 1;
			}
			return score;
		};
		voices = synth
			.getVoices()
			.filter( function ( candidate ) {
				return /^de([-_]|$)/i.test( candidate.lang );
			} )
			.sort( function ( a, b ) {
				return rank( b ) - rank( a );
			} );
		return voices;
	}

	if ( synth && 'onvoiceschanged' in synth ) {
		synth.addEventListener( 'voiceschanged', function () {
			voices = null;
		} );
	}

	function genderOf( candidate ) {
		if ( FEMALE.test( candidate.name ) ) {
			return 'female';
		}
		return MALE.test( candidate.name ) ? 'male' : '';
	}

	/**
	 * The voice for a speaker: a German voice of the wanted kind or, when the
	 * browser has none, its best German voice made lower (for a man) or
	 * higher (for a woman, when the best voice is a man's).
	 *
	 * @param {string} want 'female', 'male' or '' (any).
	 * @return {{voice: SpeechSynthesisVoice, pitch: number}|null} Voice.
	 */
	function voiceFor( want ) {
		const german = germanVoices();
		if ( ! german.length ) {
			return null;
		}
		const fitting = want
			? german.find( function ( candidate ) {
					return genderOf( candidate ) === want;
				} )
			: null;
		if ( fitting ) {
			return { voice: fitting, pitch: 1 };
		}
		const best = german[ 0 ];
		let pitch = 1;
		if ( 'male' === want && 'male' !== genderOf( best ) ) {
			pitch = 0.7;
		} else if ( 'female' === want && 'male' === genderOf( best ) ) {
			pitch = 1.35;
		}
		return { voice: best, pitch };
	}

	function setPlaying( button, playing ) {
		button.classList.toggle( 'is-playing', playing );
		button.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
	}

	/**
	 * Shows why nothing plays, next to the button.
	 *
	 * @param {Element} button Play button.
	 * @param {string}  key    Message key (noSpeech, noVoice, failed).
	 */
	function notice( button, key ) {
		const fallback = FALLBACK[ key ] || key;
		button.classList.add( 'is-unavailable' );
		if ( help ) {
			help.attr( button, 'title', strings, key );
		} else {
			button.setAttribute(
				'title',
				( strings.i18n && strings.i18n[ key ] ) || fallback
			);
		}
		// In a sentence or a dialogue the message goes below the text.
		const box = button.closest(
			'.dlms-say, .dlms-dialog__line, .dlms-dialog__bar'
		);
		const parent = box || button.parentNode;
		if ( ! parent ) {
			return;
		}
		let note = parent.querySelector( '.dlms-play__note' );
		if ( ! note ) {
			note = document.createElement( 'span' );
			note.className = 'dlms-play__note';
			note.setAttribute( 'role', 'status' );
			parent.insertBefore( note, box ? null : button.nextSibling );
		}
		if ( help ) {
			help.put( note, strings, key );
		} else {
			note.textContent =
				( strings.i18n && strings.i18n[ key ] ) || fallback;
		}
	}

	/**
	 * Reads a text aloud.
	 *
	 * @param {string}                                  text German text.
	 * @param {string}                                  want 'female', 'male' or ''.
	 * @param {(ok: boolean, message?: string) => void} end  Called with (true) at the end, or (false, message key).
	 * @return {() => void} Stops the speech.
	 */
	function speak( text, want, end ) {
		if (
			! synth ||
			'undefined' === typeof window.SpeechSynthesisUtterance
		) {
			end( false, 'noSpeech' );
			return function () {};
		}
		const chosen = voiceFor( want );
		if ( ! chosen && synth.getVoices().length ) {
			end( false, 'noVoice' );
			return function () {};
		}
		utterance = new window.SpeechSynthesisUtterance( text );
		utterance.lang = chosen ? chosen.voice.lang : 'de-DE';
		if ( chosen ) {
			utterance.voice = chosen.voice;
			utterance.pitch = chosen.pitch;
		} else if ( 'male' === want ) {
			utterance.pitch = 0.7;
		}
		utterance.rate = slow ? SLOW_SPEECH_RATE : SPEECH_RATE;
		utterance.onend = function () {
			end( true );
		};
		utterance.onerror = function () {
			end( false, '' );
		};
		synth.cancel();
		synth.speak( utterance );
		return function () {
			synth.cancel();
		};
	}

	/**
	 * Plays what a button holds: its recording, else its text.
	 *
	 * @param {Element}                                 button Play button.
	 * @param {(ok: boolean, message?: string) => void} finish Called once with (true) at the end, or (false,
	 *                                                         message) when nothing could be played; not
	 *                                                         after the returned stop function was called.
	 * @return {() => void} Stops the sound.
	 */
	function sound( button, finish ) {
		const src = button.getAttribute( 'data-dlms-src' ) || '';
		const text = button.getAttribute( 'data-dlms-say' ) || '';
		const want = button.getAttribute( 'data-dlms-voice' ) || '';
		let active = true;
		let stopper = function () {};
		const end = function ( ok, message ) {
			if ( active ) {
				active = false;
				finish( ok, message );
			}
		};

		if ( ! src ) {
			if ( text ) {
				stopper = speak( text, want, end );
			} else {
				end( false, '' );
			}
			return function () {
				active = false;
				stopper();
			};
		}

		const audio = new window.Audio( src );
		audio.defaultPlaybackRate = slow ? SLOW_RATE : 1;
		audio.playbackRate = audio.defaultPlaybackRate;
		if ( 'preservesPitch' in audio ) {
			audio.preservesPitch = true;
		}
		let fellBack = false;
		const failed = function () {
			if ( ! active || fellBack ) {
				return;
			}
			fellBack = true;
			audio.pause();
			if ( text ) {
				stopper = speak( text, want, end );
			} else {
				end( false, 'failed' );
			}
		};
		stopper = function () {
			audio.pause();
		};
		audio.addEventListener( 'ended', function () {
			end( true );
		} );
		audio.addEventListener( 'error', failed );
		const started = audio.play();
		if ( started && 'function' === typeof started.catch ) {
			started.catch( failed );
		}
		return function () {
			active = false;
			stopper();
		};
	}

	function release( entry ) {
		if ( current === entry ) {
			current = null;
		}
		entry.buttons.forEach( function ( button ) {
			setPlaying( button, false );
		} );
	}

	function stop() {
		if ( ! current ) {
			return;
		}
		const was = current;
		current = null;
		was.stop();
		release( was );
	}

	function playOne( button ) {
		const entry = { buttons: [ button ], stop() {} };
		current = entry;
		setPlaying( button, true );
		entry.stop = sound( button, function ( ok, message ) {
			release( entry );
			if ( ! ok && message ) {
				notice( button, message );
			}
		} );
	}

	/**
	 * Plays the lines of a dialogue one after the other.
	 *
	 * @param {Element} button The dialogue's "whole dialogue" button.
	 */
	function playDialogue( button ) {
		const dialogue = button.closest( '.dlms-dialog' );
		const lines = dialogue
			? Array.from(
					dialogue.querySelectorAll( '.dlms-dialog__line .dlms-play' )
				)
			: [];
		if ( ! lines.length ) {
			return;
		}
		const entry = { buttons: [ button ], stop() {} };
		let index = 0;
		let timer = 0;
		let line = null;
		let stopLine = null;
		const mark = function ( on ) {
			if ( line ) {
				setPlaying( line, on );
				line.closest( '.dlms-dialog__line' ).classList.toggle(
					'is-speaking',
					on
				);
			}
		};
		const next = function () {
			if ( current !== entry ) {
				return;
			}
			if ( index >= lines.length ) {
				release( entry );
				return;
			}
			line = lines[ index ];
			index += 1;
			mark( true );
			stopLine = sound( line, function ( ok, message ) {
				mark( false );
				if ( ! ok ) {
					release( entry );
					if ( message ) {
						notice( button, message );
					}
					return;
				}
				timer = window.setTimeout( next, PAUSE );
			} );
		};
		entry.stop = function () {
			window.clearTimeout( timer );
			if ( stopLine ) {
				stopLine();
			}
			mark( false );
		};
		current = entry;
		setPlaying( button, true );
		next();
	}

	function showSpeed() {
		document.querySelectorAll( '.dlms-speed' ).forEach( function ( item ) {
			item.setAttribute( 'aria-pressed', slow ? 'true' : 'false' );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		const toggle = event.target.closest
			? event.target.closest( '.dlms-speed' )
			: null;
		if ( ! toggle ) {
			return;
		}
		event.preventDefault();
		slow = ! slow;
		try {
			window.localStorage.setItem( SLOW_KEY, slow ? '1' : '0' );
		} catch {
			// Private mode: the choice lasts for this page only.
		}
		showSpeed();
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', showSpeed );
	} else {
		showSpeed();
	}

	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest
			? event.target.closest( '.dlms-play, .dlms-play-all' )
			: null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		const wasPlaying = button.classList.contains( 'is-playing' );
		stop();
		if ( wasPlaying ) {
			return;
		}
		if ( button.classList.contains( 'dlms-play-all' ) ) {
			playDialogue( button );
		} else {
			playOne( button );
		}
	} );

	// Load the voice list early (Chrome fills it asynchronously).
	if ( synth ) {
		synth.getVoices();
	}
} )();
