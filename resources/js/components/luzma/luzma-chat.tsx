import { Loader2, RotateCcw, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { luzmaRequest } from '@/components/luzma/client';
import type { LuzmaMessage, LuzmaScreen } from '@/components/luzma/client';
import { LuzmaBubble } from '@/components/luzma/luzma-parts';

type ChatResponse = {
    conversation: {
        id: string;
        title: string;
        is_new: boolean;
        context_changed: boolean;
    };
    message: LuzmaMessage;
};

const WORKING = [
    'Consultando datos oficiales...',
    'Analizando la información...',
    'Preparando la respuesta...',
];

/**
 * Conversation with Luzma. The screen context travels with each question as a hint; the answer shows the
 * evidence links and any action to confirm. Changing company starts a new conversation.
 */
export function LuzmaChat({
    screen,
    suggestions,
    initialQuestion,
    conversationId: initialConversation = null,
    initialMessages = [],
    compact = false,
}: {
    screen: LuzmaScreen;
    suggestions: string[];
    initialQuestion?: string | null;
    conversationId?: string | null;
    initialMessages?: LuzmaMessage[];
    compact?: boolean;
}) {
    const [messages, setMessages] = useState<LuzmaMessage[]>(initialMessages);
    const [conversationId, setConversationId] = useState<string | null>(
        initialConversation,
    );
    const [input, setInput] = useState('');
    const [busy, setBusy] = useState(false);
    const [working, setWorking] = useState(0);
    const [notice, setNotice] = useState<string | null>(null);
    const scope = `${screen.company ?? ''}|${screen.company_id ?? ''}`;
    const [lastScope, setLastScope] = useState(scope);
    const end = useRef<HTMLDivElement>(null);
    const asked = useRef<string | null>(null);

    // Another company means another conversation: figures of one company never carry over.
    if (scope !== lastScope) {
        setLastScope(scope);
        setConversationId(null);
        setMessages([]);
        setNotice(
            'Cambiaste de empresa: empecé una conversación nueva con el nuevo contexto.',
        );
    }

    useEffect(() => {
        end.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [messages, busy]);

    useEffect(() => {
        if (!busy) {
            return;
        }

        const timer = window.setInterval(
            () =>
                setWorking((value) => Math.min(value + 1, WORKING.length - 1)),
            2500,
        );

        return () => window.clearInterval(timer);
    }, [busy]);

    const ask = async (question: string) => {
        const text = question.trim();

        if (text.length < 2 || busy) {
            return;
        }

        setInput('');
        setNotice(null);
        setWorking(0);
        setBusy(true);
        setMessages((current) => [
            ...current,
            {
                id: -Date.now(),
                role: 'user',
                content: text,
                status: 'ok',
                references: [],
                actions: [],
                tools: [],
                feedback: null,
                created_at: new Date().toISOString(),
            },
        ]);

        try {
            const data = await luzmaRequest<ChatResponse>(
                '/luzma/chat',
                'POST',
                {
                    message: text,
                    conversation_id: conversationId,
                    ...screen,
                },
            );

            if (data.conversation.context_changed) {
                setNotice(
                    'El contexto cambió: seguimos en una conversación nueva.',
                );
            }

            setConversationId(data.conversation.id);
            setMessages((current) => [...current, data.message]);
        } catch (error) {
            setMessages((current) => [
                ...current,
                {
                    id: 0,
                    role: 'assistant',
                    content: (error as Error).message,
                    status: 'error',
                    references: [],
                    actions: [],
                    tools: [],
                    feedback: null,
                    created_at: new Date().toISOString(),
                },
            ]);
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (initialQuestion && asked.current !== initialQuestion) {
            asked.current = initialQuestion;
            void ask(initialQuestion);
        }
        // Only a new question from outside triggers a send.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initialQuestion]);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div
                className={`flex-1 space-y-3 overflow-y-auto ${compact ? 'px-4 py-3' : 'p-4'}`}
            >
                {notice && (
                    <p className="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        {notice}
                    </p>
                )}
                {messages.length === 0 && (
                    <div className="space-y-2">
                        <p className="text-sm text-[#526b92]">
                            Pregúntame sobre lo que ves. Uso solo datos
                            oficiales de Atlantia y nunca ejecuto nada sin tu
                            confirmación.
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {suggestions.map((suggestion) => (
                                <button
                                    key={suggestion}
                                    type="button"
                                    onClick={() => ask(suggestion)}
                                    className="rounded-full border border-[#d8cfc9] bg-white px-3 py-1 text-xs font-semibold text-[#061633] hover:border-[#640000]/40"
                                >
                                    {suggestion}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                {messages.map((message, index) => (
                    <LuzmaBubble
                        key={`${message.id}-${index}`}
                        message={message}
                        onSuggestion={(type) =>
                            type === 'prepare_payment_proposal' &&
                            ask('Prepara una propuesta de pago')
                        }
                    />
                ))}
                {busy && (
                    <p className="inline-flex items-center gap-2 text-xs font-semibold text-[#8a5b32]">
                        <Loader2 className="size-3.5 animate-spin" />
                        {WORKING[working]}
                    </p>
                )}
                <div ref={end} />
            </div>
            <form
                className="flex items-end gap-2 border-t border-[#e7e1dd] p-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    void ask(input);
                }}
            >
                {messages.length > 0 && (
                    <button
                        type="button"
                        title="Nueva conversación"
                        onClick={() => {
                            setMessages([]);
                            setConversationId(null);
                            setNotice(null);
                        }}
                        className="grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-slate-500 hover:text-[#640000]"
                    >
                        <RotateCcw className="size-4" />
                    </button>
                )}
                <textarea
                    aria-label="Pregunta para Luzma"
                    value={input}
                    rows={1}
                    maxLength={2000}
                    onChange={(event) => setInput(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            void ask(input);
                        }
                    }}
                    placeholder="Pregúntale a Luzma..."
                    className="max-h-32 min-h-9 flex-1 resize-none rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#640000]/50"
                />
                <button
                    type="submit"
                    disabled={busy || input.trim().length < 2}
                    aria-label="Enviar"
                    className="grid size-9 shrink-0 place-items-center rounded-lg bg-[#640000] text-white disabled:opacity-50"
                >
                    <Send className="size-4" />
                </button>
            </form>
        </div>
    );
}
