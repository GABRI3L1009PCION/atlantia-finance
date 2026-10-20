import { useCallback, useEffect, useRef, useState } from 'react';

type RecognitionResult = { isFinal: boolean; 0: { transcript: string } };

type Recognition = {
    lang: string;
    interimResults: boolean;
    continuous: boolean;
    maxAlternatives: number;
    start: () => void;
    stop: () => void;
    abort: () => void;
    onresult:
        | ((event: {
              resultIndex: number;
              results: ArrayLike<RecognitionResult>;
          }) => void)
        | null;
    onerror: ((event: { error: string }) => void) | null;
    onend: (() => void) | null;
};

type RecognitionConstructor = new () => Recognition;

const STORAGE_KEY = 'atlantia.luzma.voice-replies';

const FEMALE =
    /(sabina|helena|paulina|m[oó]nica|laura|elvira|dalia|salom[eé]|lupe|camila|marisol|catalina|elena|esperanza|ximena|valentina|luc[ií]a|mar[ií]a|female|mujer|google espa)/i;
const MALE =
    /(pablo|jorge|ra[uú]l|diego|[aá]lvaro|juan|carlos|miguel|enrique|andr[eé]s|alberto|\bmale\b|hombre)/i;
const LATAM = /es[-_](mx|us|419|gt|co|ar|cl|pe|ve|uy|cr|pa)/i;

function recognitionClass(): RecognitionConstructor | null {
    const scope = window as unknown as {
        SpeechRecognition?: RecognitionConstructor;
        webkitSpeechRecognition?: RecognitionConstructor;
    };

    return scope.SpeechRecognition ?? scope.webkitSpeechRecognition ?? null;
}

/** Best Spanish voice for Luzma: a woman's voice, Latin American if possible, natural ones first. */
export function pickVoice(voices: SpeechSynthesisVoice[]): {
    voice: SpeechSynthesisVoice | null;
    feminine: boolean;
} {
    const spanish = voices.filter((voice) =>
        voice.lang.toLowerCase().startsWith('es'),
    );
    const score = (voice: SpeechSynthesisVoice): number =>
        (FEMALE.test(voice.name) ? 6 : 0) +
        (MALE.test(voice.name) ? -12 : 0) +
        (LATAM.test(voice.lang) ? 2 : 0) +
        (/natural|online/i.test(voice.name) ? 2 : 0);
    const best = [...spanish].sort((a, b) => score(b) - score(a))[0] ?? null;

    return { voice: best, feminine: best !== null && score(best) >= 4 };
}

/** Plain text that sounds natural when read aloud: no markdown, links or symbols. */
export function speakableText(content: string): string {
    const clean = content
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/https?:\/\/\S+/g, '')
        .replace(/[*_`#>|~]/g, '')
        .replace(/^\s*[-•]\s+/gm, '')
        .replace(/\s*\n+\s*/g, '. ')
        .replace(/\.\s*\./g, '.')
        .replace(/\s{2,}/g, ' ')
        .trim();

    if (clean.length <= 900) {
        return clean;
    }

    const cut = clean.slice(0, 900);

    return cut.slice(0, Math.max(cut.lastIndexOf('. '), 400) + 1);
}

function chunks(text: string): string[] {
    const sentences = text.match(/[^.!?]+[.!?]*\s*/g) ?? [text];
    const result: string[] = [];
    let current = '';

    for (const sentence of sentences) {
        if (current.length + sentence.length > 180 && current !== '') {
            result.push(current.trim());
            current = '';
        }

        current += sentence;
    }

    if (current.trim() !== '') {
        result.push(current.trim());
    }

    return result;
}

/** Speech input (browser recognition) and Luzma's spoken answers (browser synthesis, a woman's voice). */
export function useLuzmaVoice() {
    const [listening, setListening] = useState(false);
    const [speaking, setSpeaking] = useState(false);
    const [voiceError, setVoiceError] = useState<string | null>(null);
    const [voiceReplies, setVoiceRepliesState] = useState<boolean>(() => {
        try {
            return window.localStorage.getItem(STORAGE_KEY) !== 'off';
        } catch {
            return true;
        }
    });
    const recognition = useRef<Recognition | null>(null);
    const generation = useRef(0);
    const voices = useRef<SpeechSynthesisVoice[]>([]);
    const canListen =
        typeof window !== 'undefined' && recognitionClass() !== null;
    const canSpeak =
        typeof window !== 'undefined' && 'speechSynthesis' in window;

    useEffect(() => {
        if (!canSpeak) {
            return;
        }

        const load = () => {
            voices.current = window.speechSynthesis.getVoices();
        };
        load();
        window.speechSynthesis.addEventListener('voiceschanged', load);

        return () =>
            window.speechSynthesis.removeEventListener('voiceschanged', load);
    }, [canSpeak]);

    const stopSpeaking = useCallback(() => {
        generation.current += 1;

        if (canSpeak) {
            window.speechSynthesis.cancel();
        }

        setSpeaking(false);
    }, [canSpeak]);

    const speak = useCallback(
        (content: string) => {
            const text = speakableText(content);

            if (!canSpeak || text === '') {
                return;
            }

            window.speechSynthesis.cancel();
            generation.current += 1;
            const mine = generation.current;
            const { voice, feminine } = pickVoice(
                voices.current.length > 0
                    ? voices.current
                    : window.speechSynthesis.getVoices(),
            );
            const parts = chunks(text);

            const next = (index: number) => {
                if (mine !== generation.current || index >= parts.length) {
                    if (mine === generation.current) {
                        setSpeaking(false);
                    }

                    return;
                }

                const utterance = new SpeechSynthesisUtterance(parts[index]);
                utterance.lang = voice?.lang ?? 'es-MX';
                utterance.voice = voice;
                utterance.rate = 1;
                // Without a woman's voice installed, a higher pitch keeps Luzma sounding like herself.
                utterance.pitch = feminine ? 1.05 : 1.3;
                utterance.onstart = () => setSpeaking(true);
                utterance.onend = () => next(index + 1);
                utterance.onerror = () => {
                    if (mine === generation.current) {
                        setSpeaking(false);
                    }
                };
                window.speechSynthesis.speak(utterance);
            };

            next(0);
        },
        [canSpeak],
    );

    const stopListening = useCallback(() => {
        recognition.current?.stop();
    }, []);

    const startListening = useCallback(
        (
            onInterim: (text: string) => void,
            onFinal: (text: string) => void,
        ) => {
            const Recognizer = recognitionClass();

            if (!Recognizer) {
                setVoiceError(
                    'Tu navegador no permite dictar por voz. Usa Chrome o Edge.',
                );

                return;
            }

            stopSpeaking();
            setVoiceError(null);
            const instance = new Recognizer();
            instance.lang = 'es-GT';
            instance.interimResults = true;
            instance.continuous = false;
            instance.maxAlternatives = 1;
            let finalText = '';
            instance.onresult = (event) => {
                let interim = '';

                for (
                    let index = event.resultIndex;
                    index < event.results.length;
                    index += 1
                ) {
                    const result = event.results[index];

                    if (result.isFinal) {
                        finalText += result[0].transcript;
                    } else {
                        interim += result[0].transcript;
                    }
                }

                onInterim((finalText + interim).trim());
            };
            instance.onerror = (event) => {
                if (event.error === 'not-allowed') {
                    setVoiceError(
                        'Permite el micrófono en el navegador para hablar con Luzma.',
                    );
                } else if (event.error === 'no-speech') {
                    setVoiceError('No te escuché. Inténtalo de nuevo.');
                }
            };
            instance.onend = () => {
                setListening(false);
                recognition.current = null;

                if (finalText.trim() !== '') {
                    onFinal(finalText.trim());
                }
            };
            recognition.current = instance;
            setListening(true);
            instance.start();
        },
        [stopSpeaking],
    );

    const setVoiceReplies = useCallback(
        (enabled: boolean) => {
            setVoiceRepliesState(enabled);

            try {
                window.localStorage.setItem(
                    STORAGE_KEY,
                    enabled ? 'on' : 'off',
                );
            } catch {
                // The choice just is not remembered.
            }

            if (!enabled) {
                stopSpeaking();
            }
        },
        [stopSpeaking],
    );

    useEffect(
        () => () => {
            recognition.current?.abort();

            if (typeof window !== 'undefined' && 'speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        },
        [],
    );

    return {
        canListen,
        canSpeak,
        listening,
        speaking,
        voiceError,
        voiceReplies,
        setVoiceReplies,
        startListening,
        stopListening,
        speak,
        stopSpeaking,
    };
}
