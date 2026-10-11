import { Link, usePage } from '@inertiajs/react';
import { History, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { luzmaRequest, useLuzmaScreen } from '@/components/luzma/client';
import type { LuzmaInsight } from '@/components/luzma/client';
import { LuzmaChat } from '@/components/luzma/luzma-chat';
import { LuzmaInsightItem } from '@/components/luzma/luzma-parts';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';

type ScreenContext = {
    company: string | null;
    period: string | null;
    module: string;
    suggestions: string[];
    insights: LuzmaInsight[];
};

const MODULE_LABELS: Record<string, string> = {
    global: 'General',
    payables: 'Cuentas por pagar',
    payroll: 'Nómina',
    audit_desk: 'Auditoría',
    client: 'Mi empresa',
    receivables: 'Cuentas por cobrar',
    treasury: 'Tesorería',
    tax: 'Fiscal',
    accountant: 'Contador',
    assistant: 'Auxiliar',
    supervisor: 'Supervisión',
    operations: 'Operación',
    executive: 'Dirección',
    office: 'Administración',
    ledger: 'Libro Mayor',
    journal: 'Libro Diario',
    closing: 'Cierre',
    purchases: 'Compras',
    sales: 'Ventas',
    banks: 'Bancos',
    accounting: 'Contabilidad',
    dashboard: 'Mi trabajo',
    audit: 'Auditoría',
    fel: 'FEL',
    library: 'Biblioteca Normativa',
};

/**
 * Luzma in the header: opens the side panel without leaving the current module, with the screen's company,
 * period and module as context. Any screen can open it with a question through askLuzma().
 */
export function LuzmaButton() {
    const navigation = usePage().props.workspaceNavigation as
        { luzma?: { canUse: boolean } } | null | undefined;
    const screen = useLuzmaScreen();
    const [open, setOpen] = useState(false);
    const [question, setQuestion] = useState<string | null>(null);
    const [context, setContext] = useState<ScreenContext | null>(null);
    const [tab, setTab] = useState<'chat' | 'insights'>('chat');

    useEffect(() => {
        const listener = (event: Event) => {
            const detail = (event as CustomEvent<{ question?: string }>).detail;
            setTab('chat');
            setQuestion(detail?.question ?? null);
            setOpen(true);
        };
        window.addEventListener('luzma:open', listener);

        return () => window.removeEventListener('luzma:open', listener);
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const query = new URLSearchParams(
            Object.entries(screen)
                .filter(([, value]) => value !== undefined)
                .map(([key, value]) => [key, String(value)]),
        );
        luzmaRequest<ScreenContext>(`/luzma/context?${query.toString()}`)
            .then(setContext)
            .catch(() => setContext(null));
    }, [open, screen]);

    if (!navigation?.luzma?.canUse) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => {
                    setQuestion(null);
                    setOpen(true);
                }}
                className="inline-flex h-9 items-center gap-1.5 rounded-xl bg-gradient-to-r from-[#640000] to-[#8a1c1c] px-3 text-xs font-bold text-white shadow-sm hover:brightness-110"
            >
                <Sparkles className="size-4" />
                <span className="hidden sm:inline">Luzma</span>
            </button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                    <div className="border-b border-[#e7e1dd] bg-gradient-to-r from-[#061633] to-[#1c2f55] px-4 py-3 text-white">
                        <SheetTitle className="flex items-center gap-2 text-white">
                            <Sparkles className="size-4 text-amber-300" /> Luzma
                        </SheetTitle>
                        <SheetDescription className="text-xs text-white/70">
                            Asistente inteligente de Atlantia Finance
                        </SheetDescription>
                        <div className="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                            <span className="rounded-full bg-white/15 px-2 py-0.5">
                                {context?.company ?? 'Todas mis empresas'}
                            </span>
                            {context?.period && (
                                <span className="rounded-full bg-white/15 px-2 py-0.5">
                                    {context.period}
                                </span>
                            )}
                            <span className="rounded-full bg-white/15 px-2 py-0.5">
                                {MODULE_LABELS[screen.module] ?? screen.module}
                            </span>
                        </div>
                        <div className="mt-3 flex gap-1 text-xs font-semibold">
                            {(
                                [
                                    ['chat', 'Conversación'],
                                    [
                                        'insights',
                                        `Alertas${context?.insights.length ? ` (${context.insights.length})` : ''}`,
                                    ],
                                ] as const
                            ).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    className={`rounded-lg px-3 py-1 ${tab === key ? 'bg-white text-[#061633]' : 'text-white/80 hover:bg-white/10'}`}
                                >
                                    {label}
                                </button>
                            ))}
                            <Link
                                href="/luzma?tab=history"
                                className="ml-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-white/80 hover:bg-white/10"
                            >
                                <History className="size-3.5" /> Historial
                            </Link>
                        </div>
                    </div>
                    {tab === 'chat' ? (
                        <LuzmaChat
                            screen={screen}
                            suggestions={context?.suggestions ?? []}
                            initialQuestion={question}
                            compact
                        />
                    ) : (
                        <div className="flex-1 space-y-2 overflow-y-auto p-4">
                            {(context?.insights ?? []).length === 0 && (
                                <p className="text-sm text-slate-500">
                                    Luzma no detectó alertas para este contexto.
                                </p>
                            )}
                            {(context?.insights ?? []).map((insight) => (
                                <LuzmaInsightItem
                                    key={insight.id}
                                    insight={insight}
                                />
                            ))}
                            <Link
                                href="/luzma?tab=insights"
                                className="inline-block text-xs font-bold text-[#640000]"
                            >
                                Ver todas las alertas
                            </Link>
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

/** Contextual "Preguntar a Luzma" button for a specific screen or record. */
export function AskLuzmaButton({
    question,
    label = 'Preguntar a Luzma',
}: {
    question: string;
    label?: string;
}) {
    const navigation = usePage().props.workspaceNavigation as
        { luzma?: { contextual: boolean } } | null | undefined;

    if (!navigation?.luzma?.contextual) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={() =>
                window.dispatchEvent(
                    new CustomEvent('luzma:open', { detail: { question } }),
                )
            }
            className="inline-flex items-center gap-1.5 rounded-lg border border-[#d9c7a3] bg-[#fffaf0] px-3 py-1.5 text-xs font-bold text-[#8a5b32] hover:bg-[#fff3dc]"
        >
            <Sparkles className="size-3.5" /> {label}
        </button>
    );
}
