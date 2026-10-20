/**
 * v2.129.0 -- THE SOUND, AND THE FOUR RULES AROUND IT.
 *
 * A notification that arrives while somebody is looking at a different part
 * of the screen is a notification they find later. A short tone is what makes
 * it arrive now -- the WhatsApp Web experience the brief asks for -- and it is
 * also the single fastest way to make people disable notifications
 * altogether. So it is bounded by four rules, each of which is a decision
 * rather than a default:
 *
 *   1. NOT WITHOUT A GESTURE. Every browser refuses audio until the page has
 *      been interacted with; attempting it anyway logs an uncaught rejection
 *      on every notification until somebody clicks. The AudioContext is
 *      created lazily, on the first real notification AFTER a gesture, and a
 *      refusal is swallowed.
 *   2. NOT TWICE IN A BREATH. Three permits verified in one batch is one
 *      sound, not three: a minimum gap, below which arrivals are silent.
 *   3. NOT FOR DATA. Only a NOTIFICATION rings. A badge recount, a status
 *      patch, a reconnect catching up -- none of those are events a person
 *      needs to hear, and the brief says so explicitly.
 *   4. NOT AGAINST THE PERSON'S WISHES. A preference, stored per browser, and
 *      off for a device that has asked for reduced motion-and-noise.
 *
 * =============================================================================
 * WHY THERE IS NO AUDIO FILE
 * =============================================================================
 *
 * The tone is synthesised: two short sine notes through a gain envelope. An
 * .mp3 would be a binary asset to commit, serve, cache-bust and licence, for
 * 120 milliseconds of sound that Web Audio can describe in a dozen lines --
 * and a missing or blocked file fails silently in a way that is very hard to
 * tell apart from the audio policy refusing to play it.
 *
 * Two notes rather than one because a single beep reads as an error; a rising
 * pair reads as an arrival. Quiet on purpose: this plays in a site office.
 */

const PREFERENCE_KEY = 'ioms-notification-sound';

/** Minimum milliseconds between two sounds. */
const MIN_GAP_MS = 4000;

let context = null;
let lastPlayedAt = 0;
let gestured = false;

if (typeof window !== 'undefined') {
    const remember = () => {
        gestured = true;
    };

    // `once` per event: the flag never goes back to false, and three idle
    // listeners on a long-lived page are three too many.
    ['pointerdown', 'keydown', 'touchstart'].forEach((event) => {
        window.addEventListener(event, remember, { once: true, passive: true });
    });
}

/**
 * Whether sound is wanted on THIS browser.
 *
 * localStorage, not the server: it is a property of the device somebody is
 * holding, not of their account. The same person wants a chime on their own
 * phone and silence on the shared terminal in the guard house, and a
 * server-side preference cannot express that. Reads are wrapped because
 * private windows and blocked site data both throw here.
 */
export function isSoundEnabled() {
    if (typeof window === 'undefined') return false;

    try {
        const stored = window.localStorage.getItem(PREFERENCE_KEY);

        // Default ON, because the point of the feature is to be noticed. A
        // person who does not want it turns it off once, per device.
        if (stored === null) {
            return !window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
        }

        return stored === 'on';
    } catch (error) {
        return false;
    }
}

export function setSoundEnabled(enabled) {
    try {
        window.localStorage.setItem(PREFERENCE_KEY, enabled ? 'on' : 'off');
    } catch (error) {
        // A browser that will not store the preference still honours it for
        // this session: the caller holds it in component state.
    }
}

/**
 * Play the arrival tone, if every rule above allows it.
 *
 * Returns whether a sound was actually started, which is what a test can
 * assert and what stops this being a function that claims to have played.
 */
export function playNotificationSound() {
    if (typeof window === 'undefined') return false;
    if (!gestured || !isSoundEnabled()) return false;

    const now = Date.now();
    if (now - lastPlayedAt < MIN_GAP_MS) return false;

    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return false;

        context = context || new AudioContextClass();

        // A context created before a gesture starts suspended; resuming it
        // after one is the documented way back.
        if (context.state === 'suspended') context.resume();

        const start = context.currentTime;

        // G5 then C6: a rising minor-ish pair, short, quiet.
        [
            { frequency: 784, at: 0, length: 0.09 },
            { frequency: 1047, at: 0.1, length: 0.13 },
        ].forEach(({ frequency, at, length }) => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;

            // An envelope, not a switch: a square-edged gain change is an
            // audible click at the start and end of the note.
            gain.gain.setValueAtTime(0.0001, start + at);
            gain.gain.exponentialRampToValueAtTime(0.09, start + at + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + at + length);

            oscillator.connect(gain).connect(context.destination);
            oscillator.start(start + at);
            oscillator.stop(start + at + length + 0.02);
        });

        lastPlayedAt = now;

        return true;
    } catch (error) {
        // Autoplay policy, a suspended context, no output device. None of
        // these are worth a word to the person using the product.
        return false;
    }
}

/** Test seam: lets a check assert the gate rather than wait for a real click. */
export function __setGesturedForTesting(value) {
    gestured = value;
    lastPlayedAt = 0;
}
