import { Link, router, usePage } from '@inertiajs/react';
import { Bell, History, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { luzmaRequest, useLuzmaScreen } from '@/components/luzma/client';
import type { LuzmaInsight } from '@/components/luzma/client';
import { LuzmaOrbChat } from '@/components/luzma/luzma-orb-chat';
import { LuzmaInsightItem } from '@/components/luzma/luzma-parts';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

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

    // A link inside an answer takes the user to that screen, so the panel gets out of the way.
    useEffect(() => router.on('navigate', () => setOpen(false)), []);

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
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="top-1/2 left-1/2 flex h-[min(92dvh,800px)] w-[min(96vw,900px)] max-w-none -translate-x-1/2 -translate-y-1/2 flex-col gap-0 overflow-visible rounded-none border-0 bg-transparent p-0 text-white shadow-none sm:max-w-none [&>button]:top-5 [&>button]:right-5 [&>button]:z-20 [&>button]:text-white [&>button]:opacity-70 [&>button]:hover:opacity-100">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -inset-[10%] bg-[radial-gradient(ellipse_at_center,#2b0b13_0%,#0c0407_58%,#000_100%)] [mask-image:radial-gradient(ellipse_at_center,#000_40%,transparent_70%)]"
                    />
                    <DialogTitle className="sr-only">Luzma</DialogTitle>
                    <DialogDescription className="sr-only">
                        Inteligencia de Atlantia Finance
                    </DialogDescription>
                    <div className="relative z-10 flex flex-wrap items-center gap-2 px-5 pt-4 pr-16 text-xs sm:px-8">
                        <span className="rounded-full border border-white/15 px-3 py-1.5 text-white/80">
                            {context?.company ?? 'Todas mis empresas'}
                        </span>
                        {context?.period && (
                            <span className="rounded-full border border-white/15 px-3 py-1.5 text-white/80">
                                {context.period}
                            </span>
                        )}
                        <span className="rounded-full border border-white/15 px-3 py-1.5 text-white/80">
                            {MODULE_LABELS[screen.module] ?? screen.module}
                        </span>
                        <span className="ml-auto flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() =>
                                    setTab(tab === 'chat' ? 'insights' : 'chat')
                                }
                                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm text-white/80 hover:bg-white/10"
                                aria-pressed={tab === 'insights'}
                            >
                                <Bell className="size-4" />
                                {tab === 'chat' ? 'Alertas' : 'Conversación'}
                                {tab === 'chat' &&
                                    (context?.insights.length ?? 0) > 0 && (
                                        <span className="rounded-full bg-[#e8c98a] px-1.5 text-[11px] font-bold text-[#2a0a14]">
                                            {context?.insights.length}
                                        </span>
                                    )}
                            </button>
                            <Link
                                href="/luzma?tab=history"
                                className="inline-flex items-center gap-1.5 rounded-lg border-l border-white/15 px-3 py-1.5 text-sm text-white/80 hover:bg-white/10"
                            >
                                <History className="size-4" /> Historial
                            </Link>
                        </span>
                    </div>
                    {tab === 'chat' ? (
                        <LuzmaOrbChat
                            screen={screen}
                            suggestions={context?.suggestions ?? []}
                            initialQuestion={question}
                        />
                    ) : (
                        <div className="relative z-10 mx-auto w-full max-w-3xl flex-1 space-y-2 overflow-y-auto px-5 pb-5 sm:px-8">
                            {(context?.insights ?? []).length === 0 && (
                                <p className="rounded-2xl bg-white/[0.06] p-4 text-sm text-white/75">
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
                                className="inline-block text-xs font-bold text-[#e8c98a]"
                            >
                                Ver todas las alertas
                            </Link>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
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
