import { usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    CalendarDays,
    Loader2,
    Mic,
    RotateCcw,
    Send,
    Square,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef } from 'react';
import type { LuzmaScreen } from '@/components/luzma/client';
import { LuzmaCosmos } from '@/components/luzma/luzma-cosmos';
import type { OrbState } from '@/components/luzma/luzma-orb';
import { LuzmaBubble } from '@/components/luzma/luzma-parts';
import { useLuzmaConversation } from '@/components/luzma/use-luzma-conversation';
import { WORKING } from '@/components/luzma/use-luzma-conversation';
import { useLuzmaVoice } from '@/components/luzma/use-luzma-voice';

const TILES = [
    {
        icon: CalendarDays,
        label: 'Organizar mi día',
        question:
            'Organiza mi día: ¿qué pendientes y vencimientos debo atender primero?',
    },
    {
        icon: Building2,
        label: 'Revisar mis empresas',
        question:
            'Dame un resumen del estado de mis empresas y qué requiere atención.',
    },
    {
        icon: BookOpen,
        label: 'Consultar una norma',
        question:
            'Quiero consultar una norma contable o fiscal. ¿Qué artículos aplican a mi caso?',
    },
];

/**
 * Luzma as an assistant you can talk to: a living sphere, voice input with the microphone and spoken answers in a
 * woman's voice. The answers keep their evidence links and confirmations, exactly as in the written chat.
 */
export function LuzmaOrbChat({
    screen,
    initialQuestion,
}: {
    screen: LuzmaScreen;
    suggestions?: string[];
    initialQuestion?: string | null;
}) {
    const user = (
        usePage().props.auth as { user?: { name?: string } } | undefined
    )?.user;
    const firstName = (user?.name ?? '').trim().split(/\s+/)[0] ?? '';
    const voice = useLuzmaVoice();
    const conversation = useLuzmaConversation({
        screen,
        initialQuestion,
        onAnswer: (message) => {
            if (voice.voiceReplies && message.status !== 'error') {
                voice.speak(message.content);
            }
        },
    });
    const { messages, input, setInput, busy, working, notice, ask, reset } =
        conversation;
    const end = useRef<HTMLDivElement>(null);
    const field = useRef<HTMLTextAreaElement>(null);
    const started = messages.length > 0;
    const state: OrbState = voice.listening
        ? 'listening'
        : busy
          ? 'thinking'
          : voice.speaking
            ? 'speaking'
            : 'idle';
    const mood = voice.listening
        ? 'ESCUCHANDO'
        : busy
          ? 'PENSANDO'
          : voice.speaking
            ? 'HABLANDO'
            : 'EN REPOSO';
    const status = voice.listening
        ? 'Escuchando…'
        : busy
          ? WORKING[working]
          : voice.speaking
            ? 'Hablando…'
            : 'Lista para ayudarte';

    // The field grows with what is written, so the whole message stays visible while typing.
    useLayoutEffect(() => {
        const element = field.current;

        if (element) {
            element.style.height = 'auto';
            element.style.height = `${Math.min(element.scrollHeight, 160)}px`;
        }
    }, [input]);

    useEffect(() => {
        end.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [messages, busy]);

    const toggleMic = () => {
        if (voice.listening) {
            voice.stopListening();

            return;
        }

        voice.startListening(setInput, (text) => {
            void ask(text, true);
        });
    };

    const send = (text: string) => {
        voice.stopSpeaking();
        void ask(text);
    };

    return (
        <div className="relative z-10 flex min-h-0 flex-1 flex-col">
            <div
                className={`flex min-h-0 flex-1 flex-col px-5 pb-2 sm:px-8 ${started ? 'overflow-y-auto' : 'overflow-hidden'}`}
            >
                {!started ? (
                    <div className="flex min-h-0 flex-1 flex-col items-center">
                        <div className="relative min-h-0 w-full flex-1">
                            <LuzmaCosmos state={state} />
                        </div>
                        <div className="shrink-0 pb-2 text-center">
                            <p className="text-lg font-light tracking-[0.7em] text-[#e8c98a] uppercase sm:text-xl">
                                Luzma
                            </p>
                            <p className="mt-3 flex items-center justify-center gap-2 text-[11px] tracking-[0.3em] text-[#e8c98a]/85 uppercase">
                                <span
                                    className={`size-1.5 rounded-full ${voice.listening ? 'animate-pulse bg-red-400' : 'bg-[#e8c98a]'}`}
                                />
                                En línea · {mood}
                            </p>
                            <p className="mt-2 text-sm text-white/65">
                                {firstName === ''
                                    ? 'Hola.'
                                    : `Hola, ${firstName}.`}{' '}
                                Estoy lista para ayudarte.
                            </p>
                            <div className="mt-4 flex flex-wrap justify-center gap-2">
                                {TILES.map(
                                    ({ icon: Icon, label, question }) => (
                                        <button
                                            key={label}
                                            type="button"
                                            onClick={() => send(question)}
                                            className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.03] px-4 py-2 text-sm text-white/85 transition hover:border-[#e8c98a]/60 hover:bg-white/[0.08] hover:text-white"
                                        >
                                            <Icon className="size-4 text-[#e8c98a]" />
                                            {label}
                                        </button>
                                    ),
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="mx-auto flex w-full max-w-3xl items-center gap-3 py-2">
                            <span className="relative block size-24 shrink-0">
                                <LuzmaCosmos state={state} />
                            </span>
                            <p className="flex items-center gap-2 text-sm text-white/80">
                                {busy && (
                                    <Loader2 className="size-4 animate-spin text-[#e8c98a]" />
                                )}
                                {status}
                            </p>
                        </div>
                        <div className="mx-auto w-full max-w-3xl space-y-3 rounded-2xl bg-[#fbf7ee] p-3 text-[#061633]">
                            {notice && (
                                <p className="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
                                    {notice}
                                </p>
                            )}
                            {messages.map((message, index) => (
                                <LuzmaBubble
                                    key={`${message.id}-${index}`}
                                    message={message}
                                    onSuggestion={(type) =>
                                        type === 'prepare_payment_proposal' &&
                                        send('Prepara una propuesta de pago')
                                    }
                                />
                            ))}
                            <div ref={end} />
                        </div>
                    </>
                )}
            </div>

            <div className="mx-auto w-full max-w-3xl space-y-2 px-5 pt-2 pb-4 sm:px-8">
                {voice.voiceError && (
                    <p className="text-center text-xs text-amber-300">
                        {voice.voiceError}
                    </p>
                )}
                <form
                    className="flex items-end gap-2 rounded-3xl border border-[#e8c98a]/40 bg-white/[0.06] p-1.5 pl-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        send(input);
                    }}
                >
                    {voice.canListen && (
                        <button
                            type="button"
                            onClick={toggleMic}
                            aria-label={
                                voice.listening
                                    ? 'Dejar de escuchar'
                                    : 'Hablar con Luzma'
                            }
                            aria-pressed={voice.listening}
                            className={`grid size-11 shrink-0 place-items-center rounded-full transition ${voice.listening ? 'animate-pulse bg-red-500 text-white' : 'bg-white/10 text-white/85 hover:bg-white/20'}`}
                        >
                            <Mic className="size-5" />
                        </button>
                    )}
                    <textarea
                        aria-label="Pregunta para Luzma"
                        rows={1}
                        value={input}
                        maxLength={2000}
                        onChange={(event) => setInput(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && !event.shiftKey) {
                                event.preventDefault();

                                if (!busy && input.trim().length >= 2) {
                                    send(input);
                                }
                            }
                        }}
                        ref={field}
                        placeholder={
                            voice.canListen
                                ? 'Escribe tu consulta o usa tu voz…'
                                : 'Escribe tu consulta…'
                        }
                        className="my-2.5 max-h-40 min-w-0 flex-1 resize-none bg-transparent px-2 text-base leading-snug text-white outline-none placeholder:text-white/45"
                    />
                    <button
                        type="submit"
                        disabled={busy || input.trim().length < 2}
                        aria-label="Enviar"
                        className="grid size-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-[#b3263c] to-[#7a0f24] text-white shadow-lg transition hover:brightness-110 disabled:opacity-50"
                    >
                        <Send className="size-5" />
                    </button>
                </form>
                <div className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-white/55">
                    <span>Las acciones requieren tu confirmación.</span>
                    {voice.canSpeak && (
                        <button
                            type="button"
                            onClick={() =>
                                voice.setVoiceReplies(!voice.voiceReplies)
                            }
                            className="inline-flex items-center gap-1.5 hover:text-white"
                            aria-pressed={voice.voiceReplies}
                        >
                            {voice.voiceReplies ? (
                                <Volume2 className="size-3.5" />
                            ) : (
                                <VolumeX className="size-3.5" />
                            )}
                            Voz de Luzma:{' '}
                            {voice.voiceReplies ? 'activada' : 'silenciada'}
                        </button>
                    )}
                    {voice.speaking && (
                        <button
                            type="button"
                            onClick={voice.stopSpeaking}
                            className="inline-flex items-center gap-1.5 text-[#e8c98a] hover:text-white"
                        >
                            <Square className="size-3.5" /> Detener
                        </button>
                    )}
                    {started && (
                        <button
                            type="button"
                            onClick={() => {
                                voice.stopSpeaking();
                                reset();
                            }}
                            className="inline-flex items-center gap-1.5 hover:text-white"
                        >
                            <RotateCcw className="size-3.5" /> Nueva
                            conversación
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
