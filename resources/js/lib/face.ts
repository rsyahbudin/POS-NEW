import { useCallback, useEffect, useRef, useState } from 'react';

type FaceApi = typeof import('@vladmandic/face-api');

let loading: Promise<FaceApi> | null = null;

/** Loads the face model once (a few MB, only on the attendance pages). */
export function loadFaceApi(): Promise<FaceApi> {
    loading ??= (async () => {
        const faceapi = await import('@vladmandic/face-api');
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri('/models/face'),
            faceapi.nets.faceLandmark68TinyNet.loadFromUri('/models/face'),
            faceapi.nets.faceRecognitionNet.loadFromUri('/models/face'),
        ]);

        return faceapi;
    })().catch((e) => {
        loading = null;
        throw e;
    });

    return loading;
}

export type Look = { descriptor: number[]; size: number };

/** The camera stream and the model, ready when `state` is 'ready'. `look()` returns the face seen right now, if any. */
export function useFaceCamera() {
    const video = useRef<HTMLVideoElement>(null);
    const api = useRef<FaceApi | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );
    const [error, setError] = useState('');

    useEffect(() => {
        let stream: MediaStream | null = null;
        let stopped = false;

        void (async () => {
            try {
                if (!navigator.mediaDevices?.getUserMedia) {
                    throw new Error('Kamera butuh koneksi aman (https).');
                }
                const [faceapi, media] = await Promise.all([
                    loadFaceApi(),
                    navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: 'user',
                            width: { ideal: 640 },
                            height: { ideal: 480 },
                        },
                        audio: false,
                    }),
                ]);
                stream = media;
                if (stopped) {
                    media.getTracks().forEach((t) => t.stop());

                    return;
                }
                api.current = faceapi;
                if (video.current) {
                    video.current.srcObject = media;
                    await video.current.play();
                }
                setState('ready');
            } catch (e) {
                setError(
                    e instanceof DOMException && e.name === 'NotAllowedError'
                        ? 'Izin kamera ditolak. Izinkan kamera di browser lalu muat ulang.'
                        : e instanceof Error
                          ? e.message
                          : 'Kamera atau model wajah gagal dimuat.',
                );
                setState('error');
            }
        })();

        return () => {
            stopped = true;
            stream?.getTracks().forEach((t) => t.stop());
        };
    }, []);

    const look = useCallback(async (): Promise<Look | null> => {
        const faceapi = api.current;
        const el = video.current;
        if (!faceapi || !el || el.readyState < 2) {
            return null;
        }
        const hit = await faceapi
            .detectSingleFace(
                el,
                new faceapi.TinyFaceDetectorOptions({
                    inputSize: 320,
                    scoreThreshold: 0.6,
                }),
            )
            .withFaceLandmarks(true)
            .withFaceDescriptor();
        if (!hit) {
            return null;
        }

        return {
            descriptor: Array.from(hit.descriptor),
            size: hit.detection.box.width / el.videoWidth,
        };
    }, []);

    return { video, state, error, look };
}

/** A face far from the camera gives a poor match: ask the person to come closer. */
export const MIN_FACE_SIZE = 0.22;
