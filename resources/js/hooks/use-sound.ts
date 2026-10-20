import { useCallback, useEffect, useRef, useState } from 'react';

const STORE = 'pos.sound';

type SoundPrefs = { muted: boolean; volume: number };

function read(): SoundPrefs {
    try {
        return {
            muted: false,
            volume: 0.6,
            ...JSON.parse(localStorage.getItem(STORE) ?? '{}'),
        };
    } catch {
        return { muted: false, volume: 0.6 };
    }
}

/**
 * Notification sounds. Per-device mute + volume (PRD 4.15). Browsers need one
 * tap after login to allow audio, so call `unlock()` from a user gesture.
 */
export function useSound() {
    const [prefs, setPrefs] = useState<SoundPrefs>(read);
    const ctx = useRef<AudioContext | null>(null);
    const [unlocked, setUnlocked] = useState(false);

    useEffect(() => {
        try {
            localStorage.setItem(STORE, JSON.stringify(prefs));
        } catch {
            // ignore
        }
    }, [prefs]);

    const unlock = useCallback(() => {
        ctx.current ??= new (
            window.AudioContext ??
            (window as unknown as { webkitAudioContext: typeof AudioContext })
                .webkitAudioContext
        )();
        void ctx.current.resume().then(() => setUnlocked(true));
    }, []);

    const beep = useCallback(
        (kind: 'order' | 'ready' | 'alert' = 'order') => {
            if (
                prefs.muted ||
                !ctx.current ||
                ctx.current.state !== 'running'
            ) {
                return;
            }

            const notes = {
                order: [880, 1175],
                ready: [660, 880, 990],
                alert: [988, 784, 988, 784],
            }[kind];
            let at = ctx.current.currentTime;

            for (const f of notes) {
                const osc = ctx.current.createOscillator();
                const gain = ctx.current.createGain();
                osc.frequency.value = f;
                osc.type = 'sine';
                gain.gain.setValueAtTime(prefs.volume, at);
                gain.gain.exponentialRampToValueAtTime(0.001, at + 0.22);
                osc.connect(gain).connect(ctx.current.destination);
                osc.start(at);
                osc.stop(at + 0.24);
                at += 0.25;
            }
        },
        [prefs],
    );

    return { prefs, setPrefs, unlock, unlocked, beep };
}
