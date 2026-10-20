import { useCallback, useEffect, useRef, useState } from 'react';

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

/**
 * Notification sounds with per-device mute + volume (PRD 4.15).
 *
 * Browsers only allow audio after a user gesture, so the first tap, key press
 * or touch anywhere on the page unlocks it (no need to hit a special button);
 * `unlocked` drives a visible "tap to enable sound" hint until then.
 */
export function useSound() {
    const [prefs, setPrefs] = useState<SoundPrefs>(read);
    const [unlocked, setUnlocked] = useState(false);
    const ctx = useRef<AudioContext | null>(null);
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

        const ac = ctx.current;

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
        try {
            const Ctor =
                window.AudioContext ??
                (
                    window as unknown as {
                        webkitAudioContext?: typeof AudioContext;
                    }
                ).webkitAudioContext;

            if (Ctor) {
                ctx.current ??= new Ctor();
                void ctx.current.resume().then(() => {
                    if (ctx.current?.state === 'running') {
                        setUnlocked(true);
                    }
                });
            }
        } catch {
            // fall back to <audio>
        }

        // Also prime the <audio> path (iOS Safari): a silent-ish play inside the gesture.
        void new Audio(wavFor('order', 0.0001))
            .play()
            .then(() => setUnlocked(true))
            .catch(() => undefined);
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

    return { prefs, setPrefs, unlock, unlocked, beep };
}
