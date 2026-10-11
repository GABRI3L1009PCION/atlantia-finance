import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    ChartNoAxesColumn,
    Clock3,
    FileText,
    ListChecks,
    Plus,
    Sparkles,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { luzmaRequest } from '@/components/luzma/client';
import type { LuzmaMessage } from '@/components/luzma/client';
import { LuzmaChat } from '@/components/luzma/luzma-chat';

type Conversation = { id: string; title: string; module: string; company: string | null; updated_at: string; archived: boolean };

type Props = {
    summary: {
        companies: number;
        new_this_quarter: number;
        deviations: number;
        at_risk: number;
        upcoming: number;
        critical_upcoming: number;
        mrr_cents: number | null;
        opportunities: number | null;
        average_load: number;
    };
    conversations: Conversation[];
    suggestions: string[];
    userName: string;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const money = (cents: number) => `Q${Math.round(cents / 100).toLocaleString('es-GT')}`;
const QUICK: { title: string; hint: string; question: string; icon: ReactNode }[] = [
    { title: 'Generar reporte ejecutivo', hint: 'Resumen completo de la firma', question: 'Genera un resumen ejecutivo de la firma con lo más importante de este mes.', icon: <FileText className="size-7" /> },
    { title: 'Revisar cumplimiento', hint: 'Estado fiscal y contable', question: '¿Cómo va el cumplimiento fiscal y contable de las empresas? ¿Qué obligaciones están vencidas?', icon: <ListChecks className="size-7" /> },
    { title: 'Analizar carga del equipo', hint: 'Capacidad y distribución', question: 'Analiza la carga de trabajo del equipo. ¿Quién está sobrecargado y quién tiene capacidad?', icon: <Users className="size-7" /> },
    { title: 'Ver cierres', hint: 'Avance de cierres por empresa', question: '¿Cómo van los cierres de período? ¿Qué empresas están retrasadas?', icon: <ChartNoAxesColumn className="size-7" /> },
];

function groupLabel(value: string): string {
    const days = Math.floor((Date.now() - new Date(value).getTime()) / 86_400_000);

    return days <= 0 ? 'Hoy' : days === 1 ? 'Ayer' : days <= 7 ? 'Esta semana' : 'Antes';
}

function Kpi({ icon, tone, label, value, caption, hint, bad }: { icon: ReactNode; tone: string; label: string; value: string; caption: string; hint: string; bad?: boolean }) {
    return (
        <div className={`${card} flex items-center gap-4 p-5`}>
            <span className={`grid size-16 shrink-0 place-items-center rounded-2xl ${tone}`}>{icon}</span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[#8a0b0b]">{label}</p>
                <p className="text-3xl leading-tight font-black text-[#061633]">{value}</p>
                <p className="truncate text-sm text-slate-700">{caption}</p>
                <p className={`truncate text-xs font-semibold ${bad ? 'text-red-600' : 'text-emerald-600'}`}>{hint}</p>
            </div>
        </div>
    );
}

export default function LuzmaDirector({ summary, conversations, suggestions, userName }: Props) {
    const [question, setQuestion] = useState<string | null>(null);
    const [opened, setOpened] = useState<{ id: string; messages: LuzmaMessage[] } | null>(null);
    const [version, setVersion] = useState(0);
    const first = userName.split(' ')[0];
    const grouped = useMemo(() => {
        const groups = new Map<string, Conversation[]>();

        conversations.filter((item) => !item.archived).forEach((item) => {
            const label = groupLabel(item.updated_at);

            groups.set(label, [...(groups.get(label) ?? []), item]);
        });

        return Array.from(groups.entries());
    }, [conversations]);
    const fresh = opened === null && question === null;
    const reset = () => {
        setOpened(null);
        setQuestion(null);
        setVersion((value) => value + 1);
    };
    const open = async (id: string) => {
        const result = await luzmaRequest<{ messages: LuzmaMessage[] }>(`/luzma/conversations/${id}`);

        setQuestion(null);
        setOpened({ id, messages: result.messages });
        setVersion((value) => value + 1);
    };

    return (
        <>
            <Head title="Luzma IA" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-14 place-items-center rounded-2xl bg-[#f6e6e4] text-[#8a0b0b]"><Sparkles className="size-8" /></span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">Luzma IA</h1>
                            <p className="text-sm text-slate-600">Tu asistente para una firma contable más productiva y segura. Responde con los datos de tu firma.</p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi icon={<Building2 className="size-8" />} tone="bg-[#f6e6e4] text-[#8a0b0b]" label="Resumen de la firma" value={String(summary.companies)} caption="empresas cliente" hint={`+${summary.new_this_quarter} este trimestre`} />
                        <Kpi icon={<ChartNoAxesColumn className="size-8" />} tone="bg-orange-50 text-orange-500" label="Desviaciones detectadas" value={String(summary.deviations)} caption="tareas vencidas" hint={summary.deviations > 0 ? 'Requieren seguimiento' : 'Sin desviaciones'} bad={summary.deviations > 0} />
                        <Kpi icon={<AlertTriangle className="size-8" />} tone="bg-red-50 text-red-600" label="Clientes en riesgo" value={String(summary.at_risk)} caption="requieren atención" hint={summary.at_risk > 0 ? 'Con atrasos' : 'Sin riesgos'} bad={summary.at_risk > 0} />
                        <Kpi icon={<CalendarDays className="size-8" />} tone="bg-indigo-50 text-indigo-600" label="Próximos vencimientos" value={String(summary.upcoming)} caption="en los próximos 30 días" hint={`${summary.critical_upcoming} críticos (7 días)`} bad={summary.critical_upcoming > 0} />
                        {summary.mrr_cents !== null ? (
                            <Kpi icon={<ChartNoAxesColumn className="size-8" />} tone="bg-violet-50 text-violet-600" label="Ingreso mensual (firma)" value={money(summary.mrr_cents)} caption="cuotas de contratos vigentes" hint={`${summary.opportunities ?? 0} oportunidades abiertas`} />
                        ) : (
                            <Kpi icon={<Users className="size-8" />} tone="bg-violet-50 text-violet-600" label="Carga del equipo" value={`${summary.average_load}%`} caption="utilización promedio" hint="Del equipo activo" bad={summary.average_load >= 85} />
                        )}
                    </section>

                    <div className="grid gap-4 xl:grid-cols-[1fr_360px]">
                        <div className="min-w-0 space-y-4">
                            <div>
                                <h2 className="mb-3 text-lg font-black">¿Qué te gustaría hacer hoy?</h2>
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    {QUICK.map((item) => (
                                        <button key={item.title} type="button" onClick={() => {
 setOpened(null); setQuestion(item.question); setVersion((value) => value + 1); 
}} className={`${card} flex items-center gap-3 p-4 text-left transition hover:border-[#c0454a]`}>
                                            <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-[#8a0b0b] text-white">{item.icon}</span>
                                            <span className="min-w-0"><span className="block font-black">{item.title}</span><span className="block text-xs text-slate-500">{item.hint}</span></span>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className={`${card} p-5`}>
                                <div className="mb-3 flex items-center gap-3">
                                    <span className="grid size-12 place-items-center rounded-full bg-[#f6e6e4] text-[#8a0b0b]"><Sparkles className="size-6" /></span>
                                    <div><p className="text-lg font-black">Luzma IA <span className="ml-1 rounded-md bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-600">BETA</span></p><p className="text-xs text-slate-500">Analiza, resume y recomienda con base en los datos de tu firma.</p></div>
                                </div>
                                {fresh && (
                                    <div className="mb-4 space-y-3">
                                        <p className="w-fit max-w-xl rounded-2xl bg-slate-50 p-4 text-sm">Hola, {first}. Aquí tienes un resumen de tu firma hoy; pregúntame lo que necesites.</p>
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <div className="rounded-xl border border-slate-100 p-4"><p className="font-black text-emerald-700">Cartera</p><p className="text-sm text-slate-700">La firma atiende <b>{summary.companies}</b> empresas cliente, <b>{summary.new_this_quarter}</b> nuevas este trimestre.</p></div>
                                            <div className="rounded-xl border border-slate-100 p-4"><p className="font-black text-red-600">Atención requerida</p><p className="text-sm text-slate-700"><b>{summary.at_risk}</b> cliente(s) con atrasos y <b>{summary.deviations}</b> tarea(s) vencida(s).</p></div>
                                            <div className="rounded-xl border border-slate-100 p-4"><p className="font-black text-amber-600">Vencimientos</p><p className="text-sm text-slate-700"><b>{summary.upcoming}</b> obligación(es) en los próximos 30 días, <b>{summary.critical_upcoming}</b> en 7 días.</p></div>
                                            <div className="rounded-xl border border-slate-100 p-4"><p className="font-black text-blue-600">Eficiencia del equipo</p><p className="text-sm text-slate-700">La carga de trabajo está en <b>{summary.average_load}%</b>.</p></div>
                                        </div>
                                    </div>
                                )}
                                <div className="flex h-[55vh] min-h-96 flex-col">
                                    <LuzmaChat key={`${opened?.id ?? 'new'}-${version}`} screen={{ module: 'global', route: '/luzma' }} suggestions={suggestions} initialQuestion={question} conversationId={opened?.id ?? null} initialMessages={opened?.messages ?? []} />
                                </div>
                            </div>
                        </div>

                        <aside className={`${card} h-fit p-5`}>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="font-black">Historial de conversaciones</h2>
                                <button type="button" onClick={reset} className="inline-flex items-center gap-1.5 rounded-lg border border-[#e4dbd6] px-3 py-1.5 text-xs font-bold text-[#8a0b0b]"><Plus className="size-3.5" /> Nueva</button>
                            </div>
                            <div className="space-y-4">
                                {grouped.map(([label, items]) => (
                                    <div key={label}>
                                        <p className="mb-1 text-xs font-bold text-slate-500">{label}</p>
                                        <ul className="space-y-1">
                                            {items.map((item) => (
                                                <li key={item.id}>
                                                    <button type="button" onClick={() => open(item.id)} className={`flex w-full items-center gap-3 rounded-xl p-2.5 text-left hover:bg-[#fbf5f4] ${opened?.id === item.id ? 'bg-[#fbeceb]' : ''}`}>
                                                        <Clock3 className="size-4 shrink-0 text-slate-400" />
                                                        <span className="min-w-0"><span className="block truncate text-sm font-bold">{item.title}</span><span className="block truncate text-xs text-slate-500">{item.company ?? 'Toda la firma'}</span></span>
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                                {grouped.length === 0 && <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">Aún no tienes conversaciones. Haz tu primera pregunta.</p>}
                            </div>
                        </aside>
                    </div>
                </div>
            </main>
        </>
    );
}
