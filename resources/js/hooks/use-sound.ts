import {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';

const STORE = 'pos.sound';

type SoundPrefs = { muted: boolean; volume: number };
type Kind = 'order' | 'ready' | 'alert';

const NOTES: Record<Kind, number[]> = {
    order: [880, 1175],
    ready: [660, 880, 990],
    alert: [988, 784, 988, 784],
};

function read(): SoundPrefs {
    try {
        return {
            muted: false,
            volume: 0.7,
            ...JSON.parse(localStorage.getItem(STORE) ?? '{}'),
        };
    } catch {
        return { muted: false, volume: 0.7 };
    }
}

/** Short WAV (sine beeps) as a data URI: fallback when WebAudio is unavailable or suspended. */
function wavFor(kind: Kind, volume: number): string {
    const rate = 22050;
    const per = 0.22;
    const samples: number[] = [];

    for (const f of NOTES[kind]) {
        for (let i = 0; i < rate * per; i++) {
            const env = Math.max(0, 1 - i / (rate * per));
            samples.push(Math.sin((2 * Math.PI * f * i) / rate) * env * volume);
        }
    }

    const buf = new ArrayBuffer(44 + samples.length * 2);
    const v = new DataView(buf);
    const w = (o: number, str: string) =>
        [...str].forEach((c, i) => v.setUint8(o + i, c.charCodeAt(0)));
    w(0, 'RIFF');
    v.setUint32(4, 36 + samples.length * 2, true);
    w(8, 'WAVEfmt ');
    v.setUint32(16, 16, true);
    v.setUint16(20, 1, true);
    v.setUint16(22, 1, true);
    v.setUint32(24, rate, true);
    v.setUint32(28, rate * 2, true);
    v.setUint16(32, 2, true);
    v.setUint16(34, 16, true);
    w(36, 'data');
    v.setUint32(40, samples.length * 2, true);
    samples.forEach((s, i) =>
        v.setInt16(44 + i * 2, Math.max(-1, Math.min(1, s)) * 0x7fff, true),
    );

    let bin = '';
    new Uint8Array(buf).forEach((b) => (bin += String.fromCharCode(b)));

    return `data:audio/wav;base64,${btoa(bin)}`;
}

type Shared = { ctx: AudioContext | null; unlocked: boolean; hintOff: boolean };
const shared: Shared = { ctx: null, unlocked: false, hintOff: false };
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((l) => l());
const HINT_KEY = 'pos.sound.hint-off';

try {
    shared.hintOff = sessionStorage.getItem(HINT_KEY) === '1';
} catch {
    // private mode: the hint may come back after a reload
}

/**
 * The audio engine and its "unlocked" flag live for the whole tab, not per screen: going from Kasir to Shift and back
 * must not ask for the sound again. Whenever the browser already allows sound (an earlier tap, or a site it trusts),
 * the context starts running by itself and no hint is shown at all.
 */
function engine(): AudioContext | null {
    if (shared.ctx) {
        return shared.ctx;
    }

    try {
        const Ctor =
            window.AudioContext ??
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;

        if (Ctor) {
            shared.ctx = new Ctor();
        }
    } catch {
        shared.ctx = null;
    }

    return shared.ctx;
}

function markUnlocked() {
    if (!shared.unlocked) {
        shared.unlocked = true;
        emit();
    }
}

function tryResume() {
    const ac = engine();

    if (!ac) {
        return;
    }

    if (ac.state === 'running') {
        markUnlocked();

        return;
    }

    void ac
        .resume()
        .then(() => {
            if (ac.state === 'running') {
                markUnlocked();
            }
        })
        .catch(() => undefined);
}

if (typeof window !== 'undefined') {
    // A page that already had a tap (or a trusted site) starts unlocked without any hint.
    tryResume();
}

/**
 * Notification sounds with per-device mute + volume (PRD 4.15).
 *
 * Browsers only allow audio after a user gesture, so the first tap, key press or touch anywhere unlocks it. Until then a
 * small hint is offered; it can be dismissed and then stays away for the rest of the session.
 */
export function useSound() {
    const [prefs, setPrefs] = useState<SoundPrefs>(read);
    const unlocked = useSyncExternalStore(
        (cb) => {
            listeners.add(cb);

            return () => listeners.delete(cb);
        },
        () => shared.unlocked,
        () => false,
    );
    const hintOff = useSyncExternalStore(
        (cb) => {
            listeners.add(cb);

            return () => listeners.delete(cb);
        },
        () => shared.hintOff,
        () => true,
    );
    const prefsRef = useRef(prefs);

    useEffect(() => {
        prefsRef.current = prefs;

        try {
            localStorage.setItem(STORE, JSON.stringify(prefs));
        } catch {
            // ignore
        }
    }, [prefs]);

    const play = useCallback((kind: Kind) => {
        const { muted, volume } = prefsRef.current;

        if (muted) {
            return;
        }

        const ac = shared.ctx;

        if (ac && ac.state === 'running') {
            let at = ac.currentTime;

            for (const f of NOTES[kind]) {
                const osc = ac.createOscillator();
                const gain = ac.createGain();
                osc.frequency.value = f;
                osc.type = 'sine';
                gain.gain.setValueAtTime(volume, at);
                gain.gain.exponentialRampToValueAtTime(0.001, at + 0.22);
                osc.connect(gain).connect(ac.destination);
                osc.start(at);
                osc.stop(at + 0.24);
                at += 0.25;
            }

            return;
        }

        void new Audio(wavFor(kind, volume)).play().catch(() => undefined);
    }, []);

    const unlock = useCallback(() => {
        tryResume();

        // Also prime the <audio> path (iOS Safari): a silent-ish play inside the gesture.
        void new Audio(wavFor('order', 0.0001))
            .play()
            .then(() => markUnlocked())
            .catch(() => undefined);
    }, []);

    const dismissHint = useCallback(() => {
        shared.hintOff = true;

        try {
            sessionStorage.setItem(HINT_KEY, '1');
        } catch {
            // ignore
        }

        emit();
    }, []);

    // First interaction anywhere unlocks audio.
    useEffect(() => {
        if (unlocked) {
            return;
        }

        const events = ['pointerdown', 'keydown', 'touchstart'] as const;
        const handler = () => unlock();
        events.forEach((e) =>
            window.addEventListener(e, handler, { passive: true }),
        );

        return () =>
            events.forEach((e) => window.removeEventListener(e, handler));
    }, [unlocked, unlock]);

    const beep = useCallback((kind: Kind = 'order') => play(kind), [play]);

    return {
        prefs,
        setPrefs,
        unlock,
        unlocked,
        hintVisible: !unlocked && !hintOff && !prefs.muted,
        dismissHint,
        beep,
    };
}
