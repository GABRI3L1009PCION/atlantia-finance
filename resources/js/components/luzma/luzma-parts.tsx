import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ExternalLink,
    ThumbsDown,
    ThumbsUp,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { luzmaRequest, severityStyle } from '@/components/luzma/client';
import type {
    LuzmaAction,
    LuzmaInsight,
    LuzmaMessage,
} from '@/components/luzma/client';

/** Inline **bold** inside a line of Luzma's answer. */
function inline(text: string): ReactNode[] {
    return text
        .split(/(\*\*[^*]+\*\*)/g)
        .map((part, index) =>
            part.startsWith('**') && part.endsWith('**') ? (
                <strong key={index}>{part.slice(2, -2)}</strong>
            ) : (
                <span key={index}>{part}</span>
            ),
        );
}

/**
 * Light markdown of Luzma's answers: headings, bullet and numbered lines and bold. Rendered as React elements,
 * never as HTML, so the model's text cannot inject markup.
 */
export function LuzmaText({ text }: { text: string }) {
    return (
        <div className="space-y-1 leading-relaxed">
            {text.split('\n').map((raw, index) => {
                const line = raw.trimEnd();

                if (line.trim() === '') {
                    return <div key={index} className="h-1" />;
                }

                const heading = line.match(/^#{1,4}\s+(.*)$/);

                if (heading) {
                    return (
                        <p
                            key={index}
                            className="pt-1 font-bold text-[#061633]"
                        >
                            {inline(heading[1])}
                        </p>
                    );
                }

                const bullet = line.match(/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/);

                if (bullet) {
                    return (
                        <p key={index} className="flex gap-1.5 pl-2">
                            <span className="text-[#8a5b32]">•</span>
                            <span>{inline(bullet[1])}</span>
                        </p>
                    );
                }

                return <p key={index}>{inline(line)}</p>;
            })}
        </div>
    );
}

/** Preview of an action Luzma prepared; nothing happens until the user confirms. */
export function LuzmaActionPreview({ action }: { action: LuzmaAction }) {
    const [state, setState] = useState<LuzmaAction>(action);
    const [busy, setBusy] = useState(false);
    const act = async (kind: 'confirm' | 'cancel') => {
        setBusy(true);

        try {
            if (kind === 'confirm') {
                const result = await luzmaRequest<{
                    message: string;
                    href: string | null;
                }>(`/luzma/actions/${state.id}/confirm`, 'POST');
                setState({ ...state, status: 'executed', result });
            } else {
                await luzmaRequest(`/luzma/actions/${state.id}/cancel`, 'POST');
                setState({ ...state, status: 'cancelled' });
            }
        } catch (error) {
            setState({ ...state, error: (error as Error).message });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mt-2 rounded-xl border border-[#d9c7a3] bg-[#fffaf0] p-3 text-xs">
            <p className="text-[10px] font-bold tracking-wide text-[#8a5b32] uppercase">
                {state.label} · vista previa
            </p>
            <p className="mt-1 text-[13px] text-[#172033]">
                {state.preview.summary}
            </p>
            {state.preview.lines && (
                <ul className="mt-2 max-h-36 space-y-0.5 overflow-y-auto text-[11px] text-slate-600">
                    {state.preview.lines.map((line) => (
                        <li key={line}>• {line}</li>
                    ))}
                </ul>
            )}
            {state.status === 'prepared' && (
                <div className="mt-3 flex gap-2">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => act('confirm')}
                        className="inline-flex items-center gap-1 rounded-lg bg-[#640000] px-3 py-1.5 font-semibold text-white disabled:opacity-60"
                    >
                        <Check className="size-3.5" /> {state.confirm_label}
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => act('cancel')}
                        className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-600"
                    >
                        <X className="size-3.5" /> Descartar
                    </button>
                </div>
            )}
            {state.status === 'executed' && state.result && (
                <p className="mt-2 font-semibold text-emerald-700">
                    {state.result.message}{' '}
                    {state.result.href && (
                        <Link href={state.result.href} className="underline">
                            Ver
                        </Link>
                    )}
                </p>
            )}
            {state.status === 'cancelled' && (
                <p className="mt-2 text-slate-500">Descartada.</p>
            )}
            {state.error && (
                <p className="mt-2 font-semibold text-red-700">{state.error}</p>
            )}
        </div>
    );
}

/** One chat bubble with its evidence links, prepared actions and feedback. */
export function LuzmaBubble({
    message,
    onSuggestion,
}: {
    message: LuzmaMessage;
    onSuggestion?: (type: string) => void;
}) {
    const [feedback, setFeedback] = useState(message.feedback);

    if (message.role === 'user') {
        return (
            <div className="ml-auto max-w-[85%] rounded-2xl rounded-br-sm bg-[#640000] px-3 py-2 text-[13px] text-white">
                {message.content}
            </div>
        );
    }

    const send = async (value: string) => {
        setFeedback(value);

        try {
            await luzmaRequest(
                `/luzma/messages/${message.id}/feedback`,
                'POST',
                {
                    value,
                },
            );
        } catch {
            setFeedback(null);
        }
    };

    return (
        <div className="max-w-[95%] rounded-2xl rounded-bl-sm border border-[#e7e1dd] bg-white px-3 py-2 text-[13px] text-[#172033] shadow-sm">
            {message.status !== 'ok' && message.status !== 'pending' && (
                <p className="mb-1 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-800">
                    <AlertTriangle className="size-3" />
                    {message.status === 'fallback'
                        ? 'Datos oficiales sin análisis'
                        : 'No pude completar la consulta'}
                </p>
            )}
            <LuzmaText text={message.content} />
            {message.references.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {message.references.map((reference) => (
                        <Link
                            key={reference.href + reference.label}
                            href={reference.href}
                            className="inline-flex items-center gap-1 rounded-full border border-[#d8cfc9] px-2 py-0.5 text-[11px] font-semibold text-[#640000] hover:bg-red-50"
                        >
                            <ExternalLink className="size-3" />{' '}
                            {reference.label}
                        </Link>
                    ))}
                </div>
            )}
            {message.actions.map((action) => (
                <LuzmaActionPreview key={action.id} action={action} />
            ))}
            {(message.suggestions ?? []).map((suggestion) => (
                <button
                    key={suggestion.type}
                    type="button"
                    onClick={() => onSuggestion?.(suggestion.type)}
                    className="mt-2 mr-1 rounded-lg border border-[#d9c7a3] bg-[#fffaf0] px-2 py-1 text-[11px] font-semibold text-[#8a5b32]"
                >
                    {suggestion.label}
                </button>
            ))}
            {message.id > 0 && (
                <div className="mt-2 flex items-center gap-1 text-slate-400">
                    <button
                        type="button"
                        aria-label="Útil"
                        onClick={() => send('useful')}
                        className={`rounded p-1 hover:text-emerald-700 ${feedback === 'useful' ? 'text-emerald-700' : ''}`}
                    >
                        <ThumbsUp className="size-3.5" />
                    </button>
                    <button
                        type="button"
                        aria-label="No útil"
                        onClick={() => send('not_useful')}
                        className={`rounded p-1 hover:text-red-700 ${feedback === 'not_useful' ? 'text-red-700' : ''}`}
                    >
                        <ThumbsDown className="size-3.5" />
                    </button>
                </div>
            )}
        </div>
    );
}

/** "Luzma detectó..." card with acknowledge and dismiss. */
export function LuzmaInsightItem({
    insight,
    onChange,
}: {
    insight: LuzmaInsight;
    onChange?: () => void;
}) {
    const [hidden, setHidden] = useState(false);
    const update = async (action: 'acknowledge' | 'dismiss') => {
        try {
            await luzmaRequest(`/luzma/insights/${insight.id}`, 'PATCH', {
                action,
            });
            setHidden(action === 'dismiss');
            onChange?.();
        } catch {
            // The card stays as it was.
        }
    };

    if (hidden) {
        return null;
    }

    return (
        <div
            className={`rounded-xl border p-3 text-xs ${severityStyle[insight.severity] ?? severityStyle.info}`}
        >
            <p className="text-[10px] font-black tracking-wide uppercase">
                {insight.label}
                {insight.company && ` · ${insight.company}`}
            </p>
            <p className="mt-1 text-[13px] font-bold">{insight.title}</p>
            <p className="mt-0.5 opacity-90">{insight.body}</p>
            <div className="mt-2 flex flex-wrap gap-2">
                {insight.destination && (
                    <Link
                        href={insight.destination}
                        className="font-bold underline"
                    >
                        Investigar
                    </Link>
                )}
                {insight.status === 'open' && (
                    <button
                        type="button"
                        onClick={() => update('acknowledge')}
                        className="font-semibold opacity-80 hover:opacity-100"
                    >
                        Enterado
                    </button>
                )}
                <button
                    type="button"
                    onClick={() => update('dismiss')}
                    className="font-semibold opacity-70 hover:opacity-100"
                >
                    Descartar
                </button>
            </div>
        </div>
    );
}
