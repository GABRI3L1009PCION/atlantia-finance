import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { useEffect, useState } from 'react';

const SUCCESS_MODAL_MS = 3500;

type Feedback = { message: string; id: string } | null;

/**
 * App-wide feedback: an error modal that lists what is missing (closed with
 * "Aceptar") and an animated success modal that dismisses itself.
 */
export function FeedbackModals() {
    const page = usePage<{ feedback?: Feedback }>();
    const feedback = page.props.feedback ?? null;
    const errors = page.url.startsWith('/settings')
        ? []
        : Object.values((page.props.errors ?? {}) as Record<string, string>);
    const [dismissedErrors, setDismissedErrors] = useState<unknown>(null);
    const [dismissedFeedback, setDismissedFeedback] = useState<string | null>(
        null,
    );
    const errorsOpen = errors.length > 0 && dismissedErrors !== page.props;
    const successOpen = feedback !== null && dismissedFeedback !== feedback.id;
    const feedbackId = feedback?.id ?? null;

    useEffect(() => {
        if (feedbackId === null) {
            return;
        }

        const timer = window.setTimeout(
            () => setDismissedFeedback(feedbackId),
            SUCCESS_MODAL_MS,
        );

        return () => window.clearTimeout(timer);
    }, [feedbackId]);

    return (
        <>
            {errorsOpen && (
                <div
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="feedback-error-title"
                    className="feedback-backdrop fixed inset-0 z-[100] grid place-items-center bg-[#071a38]/55 p-4 backdrop-blur-sm"
                >
                    <div className="feedback-pop w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-center gap-3">
                            <span className="grid size-11 place-items-center rounded-full bg-red-100 text-red-700">
                                <AlertTriangle className="size-6" />
                            </span>
                            <h2
                                id="feedback-error-title"
                                className="text-lg font-black text-[#071a38]"
                            >
                                Revisa la información
                            </h2>
                        </div>
                        <p className="mt-3 text-sm text-slate-600">
                            No se pudo guardar. Esto es lo que falta o debe
                            corregirse:
                        </p>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm font-semibold text-red-800">
                            {errors.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            autoFocus
                            onClick={() => setDismissedErrors(page.props)}
                            className="mt-6 w-full rounded-xl bg-[#8a0b0b] px-4 py-3 text-sm font-black text-white transition hover:bg-[#640000]"
                        >
                            Aceptar
                        </button>
                    </div>
                </div>
            )}
            {successOpen && feedback && (
                <div
                    role="status"
                    className="feedback-backdrop pointer-events-none fixed inset-0 z-[100] grid place-items-center bg-[#071a38]/30 p-4"
                >
                    <div className="feedback-pop w-full max-w-sm rounded-2xl bg-white p-8 text-center shadow-2xl">
                        <span className="feedback-check mx-auto grid size-16 place-items-center rounded-full bg-emerald-100 text-emerald-600">
                            <CheckCircle2 className="size-9" />
                        </span>
                        <p className="mt-4 text-lg font-black text-[#071a38]">
                            {feedback.message}
                        </p>
                        <div className="mt-5 h-1 overflow-hidden rounded-full bg-emerald-100">
                            <div
                                className="feedback-progress h-full bg-emerald-500"
                                style={{
                                    animationDuration: `${SUCCESS_MODAL_MS}ms`,
                                }}
                            />
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
