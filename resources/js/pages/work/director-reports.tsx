import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarCheck2,
    Clock3,
    Download,
    Eye,
    FileSpreadsheet,
    FileText,
    FilterX,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Category = 'financial' | 'operational' | 'commercial' | 'clients' | 'team' | 'compliance';

type Report = {
    key: string;
    title: string;
    description: string;
    category: Category;
    href: string;
    export: string | null;
    stats: [string, number | string][];
};

type Props = {
    kpis: { available: number; exports_month: number; exports_week: number; last_at: string | null; last_report: string | null };
    reports: Report[];
    filters: { office_id: number | null; period: string | null };
    options: { offices: { id: number; name: string }[]; periods: { key: string; name: string }[] };
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const field =
    'h-11 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const CATEGORY: Record<Category, { label: string; tab: string; style: string }> = {
    financial: { label: 'Financiero', tab: 'Financieros', style: 'bg-rose-50 text-rose-700' },
    operational: { label: 'Operativo', tab: 'Operativos', style: 'bg-amber-50 text-amber-700' },
    commercial: { label: 'Comercial', tab: 'Comerciales', style: 'bg-orange-50 text-orange-700' },
    clients: { label: 'Clientes', tab: 'Clientes', style: 'bg-red-50 text-red-700' },
    team: { label: 'Equipo', tab: 'Equipo', style: 'bg-violet-50 text-violet-700' },
    compliance: { label: 'Cumplimiento', tab: 'Cumplimiento', style: 'bg-blue-50 text-blue-700' },
};
const TABS: ('all' | Category)[] = ['all', 'financial', 'operational', 'commercial', 'clients', 'team', 'compliance'];

function normalize(value: string): string {
    return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function Kpi({ icon, tone, value, label, hint }: { icon: ReactNode; tone: string; value: string; label: string; hint: string }) {
    return (
        <div className={`${card} flex items-center gap-4 p-5`}>
            <span className={`grid size-16 shrink-0 place-items-center rounded-2xl ${tone}`}>{icon}</span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[#6b1d1d]">{label}</p>
                <p className="truncate text-3xl leading-tight font-black text-[#061633]">{value}</p>
                <p className="truncate text-xs font-semibold text-slate-500">{hint}</p>
            </div>
        </div>
    );
}

export default function DirectorReports({ kpis, reports, filters, options }: Props) {
    const [tab, setTab] = useState<'all' | Category>('all');
    const [search, setSearch] = useState('');
    const visible = useMemo(() => {
        const term = normalize(search.trim());

        return reports.filter((report) => (tab === 'all' || report.category === tab) && (term === '' || normalize(`${report.title} ${report.description}`).includes(term)));
    }, [reports, tab, search]);
    const apply = (next: { office_id?: string; period?: string }) => {
        const params: Record<string, string> = {};
        const office = next.office_id ?? (filters.office_id ? String(filters.office_id) : '');
        const period = next.period ?? filters.period ?? '';

        if (office) {
            params.office_id = office;
        }

        if (period) {
            params.period = period;
        }

        router.get('/office/reports', params, { preserveScroll: true, preserveState: false });
    };
    const exportUrl = (report: Report, format: 'pdf' | 'xlsx') => {
        const params = new URLSearchParams({ format });

        if (filters.office_id) {
            params.set('office_id', String(filters.office_id));
        }

        if (filters.period) {
            params.set('period', filters.period);
        }

        return `/office/executive-dashboard/export/${report.export}?${params.toString()}`;
    };
    const lastLabel = kpis.last_at ? new Date(kpis.last_at).toLocaleDateString('es-GT', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

    return (
        <>
            <Head title="Reportes ejecutivos" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]"><FileText className="size-7" /></span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">Reportes ejecutivos</h1>
                            <p className="text-sm text-slate-600">Información estratégica de la firma para abrir o descargar en PDF y Excel.</p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Kpi icon={<FileText className="size-8" />} tone="bg-[#f6e6e4] text-[#8a0b0b]" value={String(kpis.available)} label="Reportes disponibles" hint="Para tu rol" />
                        <Kpi icon={<Download className="size-8" />} tone="bg-blue-50 text-blue-600" value={String(kpis.exports_month)} label="Descargas este mes" hint="PDF y Excel" />
                        <Kpi icon={<CalendarCheck2 className="size-8" />} tone="bg-violet-50 text-violet-600" value={String(kpis.exports_week)} label="Descargas esta semana" hint="Últimos 7 días" />
                        <Kpi icon={<Clock3 className="size-8" />} tone="bg-orange-50 text-orange-500" value={lastLabel} label="Última descarga" hint={kpis.last_report ?? 'Aún no se ha descargado ninguno'} />
                    </section>

                    <section className={`${card} p-5`}>
                        <div className="grid items-end gap-4 md:grid-cols-[1fr_1fr_auto]">
                            <label className="text-sm font-bold">
                                Período
                                <select className={`${field} mt-1.5`} value={filters.period ?? ''} onChange={(e) => apply({ period: e.target.value })}>
                                    {options.periods.map((period) => <option key={period.key} value={period.key}>{period.name}</option>)}
                                </select>
                            </label>
                            <label className="text-sm font-bold">
                                Oficina
                                <select className={`${field} mt-1.5`} value={filters.office_id ? String(filters.office_id) : ''} onChange={(e) => apply({ office_id: e.target.value })}>
                                    <option value="">Todas las oficinas</option>
                                    {options.offices.map((office) => <option key={office.id} value={office.id}>{office.name}</option>)}
                                </select>
                            </label>
                            <button type="button" onClick={() => router.get('/office/reports')} className="inline-flex h-11 items-center gap-2 rounded-xl px-4 text-sm font-bold text-[#8a0b0b] hover:bg-red-50"><FilterX className="size-4" /> Limpiar filtros</button>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">El período y la oficina elegidos se aplican a las descargas.</p>
                    </section>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <nav className="flex flex-wrap gap-5 border-b border-slate-200 text-sm font-bold">
                            {TABS.map((key) => (
                                <button key={key} type="button" onClick={() => setTab(key)} className={`-mb-px border-b-2 pb-3 ${tab === key ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-600'}`}>
                                    {key === 'all' ? 'Todos los reportes' : CATEGORY[key].tab}
                                </button>
                            ))}
                        </nav>
                        <label className="relative">
                            <Search className="absolute top-3.5 left-3 size-4 text-slate-400" />
                            <input className={`${field} w-72 pl-9`} placeholder="Buscar reportes..." value={search} onChange={(e) => setSearch(e.target.value)} />
                        </label>
                    </div>

                    <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {visible.map((report) => (
                            <article key={report.key} className={`${card} flex flex-col p-5`}>
                                <div className="mb-3 flex items-center justify-between gap-2">
                                    <span className={`rounded-md px-2.5 py-1 text-xs font-bold ${CATEGORY[report.category].style}`}>{CATEGORY[report.category].label}</span>
                                    <span className="text-xs text-slate-400">Datos al momento de abrirlo</span>
                                </div>
                                <h2 className="text-lg font-black text-[#061633]">{report.title}</h2>
                                <p className="mt-1 text-sm text-slate-600">{report.description}</p>
                                {report.stats.length > 0 && (
                                    <dl className="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center">
                                        {report.stats.map(([label, value]) => (
                                            <div key={label}><dd className="text-xl font-black text-[#8a0b0b]">{value}</dd><dt className="text-[11px] text-slate-500">{label}</dt></div>
                                        ))}
                                    </dl>
                                )}
                                <div className="mt-auto flex flex-wrap gap-2 pt-4">
                                    <Link href={report.href} className="inline-flex items-center gap-1.5 rounded-lg border border-[#e4dbd6] px-3 py-2 text-xs font-bold text-[#8a0b0b]"><Eye className="size-4" /> Ver reporte</Link>
                                    {report.export && (
                                        <>
                                            <a href={exportUrl(report, 'pdf')} className="inline-flex items-center gap-1.5 rounded-lg border border-[#e4dbd6] px-3 py-2 text-xs font-bold text-[#8a0b0b]"><FileText className="size-4" /> Exportar PDF</a>
                                            <a href={exportUrl(report, 'xlsx')} className="inline-flex items-center gap-1.5 rounded-lg border border-[#e4dbd6] px-3 py-2 text-xs font-bold text-[#8a0b0b]"><FileSpreadsheet className="size-4" /> Exportar Excel</a>
                                        </>
                                    )}
                                </div>
                            </article>
                        ))}
                        {visible.length === 0 && <p className={`${card} col-span-full p-10 text-center text-slate-500`}>No hay reportes con esos criterios.</p>}
                    </section>
                </div>
            </main>
        </>
    );
}
