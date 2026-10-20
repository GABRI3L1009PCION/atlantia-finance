import { useEffect, useRef, useState } from 'react';
import { luzmaRequest } from '@/components/luzma/client';
import type { LuzmaMessage, LuzmaScreen } from '@/components/luzma/client';

type ChatResponse = {
    conversation: {
        id: string;
        title: string;
        is_new: boolean;
        context_changed: boolean;
    };
    message: LuzmaMessage;
};

export const WORKING = [
    'Consultando datos oficiales...',
    'Analizando la información...',
    'Preparando la respuesta...',
];

/**
 * State of a conversation with Luzma: messages, the question being sent and the progress text. The screen context
 * travels with each question as a hint; changing company starts a new conversation.
 */
export function useLuzmaConversation({
    screen,
    initialQuestion,
    conversationId: initialConversation = null,
    initialMessages = [],
    onAnswer,
}: {
    screen: LuzmaScreen;
    initialQuestion?: string | null;
    conversationId?: string | null;
    initialMessages?: LuzmaMessage[];
    onAnswer?: (message: LuzmaMessage, viaVoice: boolean) => void;
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

    const ask = async (question: string, viaVoice = false) => {
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
            onAnswer?.(data.message, viaVoice);
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

    const reset = () => {
        setMessages([]);
        setConversationId(null);
        setNotice(null);
    };

    return {
        messages,
        input,
        setInput,
        busy,
        working,
        notice,
        ask,
        reset,
    };
}
