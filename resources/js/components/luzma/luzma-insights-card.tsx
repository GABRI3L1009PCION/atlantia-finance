import { Link, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { askLuzma, luzmaRequest } from '@/components/luzma/client';
import type { LuzmaInsight } from '@/components/luzma/client';
import { LuzmaInsightItem } from '@/components/luzma/luzma-parts';

/**
 * "¿Qué requiere atención?" on a dashboard: the top insights Luzma detected for the user's companies. It loads
 * after the page, so a slow or offline Luzma never delays the dashboard.
 */
export function LuzmaInsightsCard({
    module,
    question = '¿Qué requiere atención hoy?',
}: {
    module?: string;
    question?: string;
}) {
    const navigation = usePage().props.workspaceNavigation as
        { luzma?: { canUse: boolean } } | null | undefined;
    const [insights, setInsights] = useState<LuzmaInsight[] | null>(null);
    const [failed, setFailed] = useState(false);
    const canUse = Boolean(navigation?.luzma?.canUse);

    useEffect(() => {
        if (!canUse) {
            return;
        }

        luzmaRequest<{ insights: LuzmaInsight[] }>(
            `/luzma/insights?limit=3${module ? `&module=${module}` : ''}`,
        )
            .then((data) => setInsights(data.insights))
            .catch(() => setFailed(true));
    }, [canUse, module]);

    if (!canUse) {
        return null;
    }

    return (
        <section className="rounded-xl border border-[#e7d9bf] bg-gradient-to-br from-[#fffaf0] to-white p-3 shadow-sm">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-black text-[#061633]">
                    <Sparkles className="size-4 text-[#b8893f]" /> Luzma · ¿Qué
                    requiere atención?
                </h2>
                <span className="flex gap-2 text-xs font-bold">
                    <button
                        type="button"
                        onClick={() => askLuzma(question)}
                        className="text-[#8a5b32] hover:underline"
                    >
                        Preguntar a Luzma
                    </button>
                    <Link
                        href="/luzma?tab=insights"
                        className="text-[#640000] hover:underline"
                    >
                        Ver todo
                    </Link>
                </span>
            </div>
            {insights === null && !failed && (
                <div className="grid gap-2 md:grid-cols-3">
                    {[0, 1, 2].map((index) => (
                        <div
                            key={index}
                            className="h-20 animate-pulse rounded-xl bg-slate-100"
                        />
                    ))}
                </div>
            )}
            {failed && (
                <p className="text-xs text-slate-500">
                    Luzma no está disponible temporalmente.
                </p>
            )}
            {insights !== null && insights.length === 0 && (
                <p className="text-xs text-slate-500">
                    Luzma no detectó alertas abiertas en tus empresas.
                </p>
            )}
            {insights !== null && insights.length > 0 && (
                <div className="grid gap-2 md:grid-cols-3">
                    {insights.map((insight) => (
                        <LuzmaInsightItem key={insight.id} insight={insight} />
                    ))}
                </div>
            )}
        </section>
    );
}
