import { Link, router } from '@inertiajs/react';
import {
    BookCheck,
    BookMarked,
    BookOpen,
    Calculator,
    Download,
    FileBarChart,
    FileChartColumn,
    FileSpreadsheet,
    FileUp,
    Landmark,
    ListTree,
    Scale,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Empty,
    exportUrl,
    Label,
    PageShell,
    Stat,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    field,
    money,
    primary,
    secondary,
    shortDate,
} from '@/components/accounting-ui';

type Statements = {
    income: number;
    expenses: number;
    profit: number;
    assets: number;
    liabilities: number;
    equity: number;
    accumulated: number;
    difference: number;
};

type RecentExport = {
    action: string;
    details: Record<string, string | number | null>;
    user: string;
    at: string;
};

const BOOKS: {
    kind: string;
    title: string;
    detail: string;
    icon: LucideIcon;
}[] = [
    {
        kind: 'journal',
        title: 'Libro Diario',
        detail: 'Cada asiento contabilizado del período con sus líneas.',
        icon: BookOpen,
    },
    {
        kind: 'ledger',
        title: 'Libro Mayor',
        detail: 'Movimientos y saldo de cada cuenta en el período.',
        icon: BookMarked,
    },
    {
        kind: 'trial',
        title: 'Balance de comprobación',
        detail: 'Sumas y saldos de todas las cuentas.',
        icon: Scale,
    },
    {
        kind: 'income',
        title: 'Estado de resultados',
        detail: 'Ingresos, costos y gastos del período.',
        icon: FileBarChart,
    },
    {
        kind: 'balance',
        title: 'Balance general',
        detail: 'Activo, pasivo y patrimonio a la fecha final.',
        icon: Landmark,
    },
    {
        kind: 'accounts',
        title: 'Catálogo de cuentas',
        detail: 'Plan de cuentas vigente de la empresa.',
        icon: ListTree,
    },
];

const KIND_LABELS: Record<string, string> = Object.fromEntries(
    BOOKS.map((book) => [book.kind, book.title]),
);

function exportLabel(row: RecentExport): string {
    const details = row.details;

    switch (row.action) {
        case 'accounting_report.exported':
            return `${KIND_LABELS[String(details.kind)] ?? 'Reporte'} · ${String(details.format ?? '').toUpperCase()}`;
        case 'vat_book.exported':
            return `Libro de ${details.book === 'sales' ? 'Ventas' : 'Compras'} IVA · ${details.period ?? ''}`;
        case 'fiscal.sat_file_exported':
            return `Archivo para la SAT (${details.file ?? ''}) · ${details.period ?? ''}`;
        default:
            return 'Reporte exportado';
    }
}

export default function Reports({
    company,
    filters,
    statements,
    recent,
    canExport,
}: {
    company: Company;
    filters: { from: string; to: string };
    statements: Statements;
    recent: RecentExport[];
    canExport: boolean;
}) {
    const base = `/accounting/companies/${company.public_id}`;
    const [range, setRange] = useState(filters);
    const download = (kind: string, format: 'pdf' | 'xlsx') =>
        exportUrl(`${base}/export`, { ...filters, kind, format });
    const shortcuts: {
        title: string;
        detail: string;
        href: string;
        icon: LucideIcon;
    }[] = [
        {
            title: 'Estados financieros',
            detail: 'NIIF con comparativos, notas y certificación.',
            href: `${base}/financial-statements`,
            icon: FileChartColumn,
        },
        {
            title: 'Libros de IVA',
            detail: 'Compras y ventas del mes, cierre y liquidación.',
            href: `${base}/vat-books`,
            icon: BookMarked,
        },
        {
            title: 'Archivos para la SAT',
            detail: 'Asiste Libros, RetenISR e Informe de Inventarios.',
            href: `${base}/sat-files`,
            icon: FileUp,
        },
        {
            title: 'Libros legales',
            detail: 'Diario, Mayor, Inventarios y Estados con folios.',
            href: `${base}/year-end`,
            icon: BookCheck,
        },
        {
            title: 'ISR y retenciones',
            detail: 'Renta imponible, pagos trimestrales y retenciones.',
            href: `${base}/tax-reports`,
            icon: Calculator,
        },
    ];

    return (
        <PageShell
            company={company}
            title="Reportes contables"
            subtitle={`Del ${shortDate(filters.from)} al ${shortDate(filters.to)} · cifras de asientos contabilizados`}
            icon={FileSpreadsheet}
        >
            <Card title="Período">
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(`${base}/reports`, range, {
                            preserveState: true,
                        });
                    }}
                >
                    <Label text="Desde">
                        <input
                            type="date"
                            className={field}
                            value={range.from}
                            onChange={(e) =>
                                setRange({ ...range, from: e.target.value })
                            }
                        />
                    </Label>
                    <Label text="Hasta">
                        <input
                            type="date"
                            className={field}
                            value={range.to}
                            onChange={(e) =>
                                setRange({ ...range, to: e.target.value })
                            }
                        />
                    </Label>
                    <button className={primary}>Consultar</button>
                </form>
            </Card>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Stat
                    label="Ingresos del período"
                    value={money(statements.income)}
                />
                <Stat
                    label="Costos y gastos del período"
                    value={money(statements.expenses)}
                />
                <Stat
                    label="Resultado del período"
                    value={money(statements.profit)}
                />
                <Stat
                    label="Activo a la fecha"
                    value={money(statements.assets)}
                />
                <Stat
                    label="Pasivo a la fecha"
                    value={money(statements.liabilities)}
                />
                <Stat
                    label="Patrimonio y resultados acumulados"
                    value={money(statements.equity + statements.accumulated)}
                />
            </div>
            {statements.difference !== 0 && (
                <p className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    El balance no cuadra por {money(statements.difference)}:
                    revisa los asientos del período antes de presentar reportes.
                </p>
            )}

            <Card title="Libros y reportes contables">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {BOOKS.map((book) => (
                        <article
                            key={book.kind}
                            className="flex flex-col justify-between gap-3 rounded-xl border border-slate-200 p-4"
                        >
                            <div className="flex items-start gap-3">
                                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#fbf4f4] text-[#640000]">
                                    <book.icon className="size-5" />
                                </span>
                                <div>
                                    <p className="font-semibold text-slate-900">
                                        {book.title}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {book.detail}
                                    </p>
                                </div>
                            </div>
                            {canExport && (
                                <div className="flex gap-2">
                                    <a
                                        className={secondary}
                                        href={download(book.kind, 'pdf')}
                                    >
                                        <Download className="size-4" /> PDF
                                    </a>
                                    <a
                                        className={secondary}
                                        href={download(book.kind, 'xlsx')}
                                    >
                                        <Download className="size-4" /> Excel
                                    </a>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            </Card>

            <div className="grid gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card title="Más reportes">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {shortcuts.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-[#640000]/40 hover:bg-[#fbf4f4]"
                            >
                                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-700">
                                    <item.icon className="size-5" />
                                </span>
                                <span>
                                    <span className="block font-semibold text-slate-900">
                                        {item.title}
                                    </span>
                                    <span className="text-sm text-slate-500">
                                        {item.detail}
                                    </span>
                                </span>
                            </Link>
                        ))}
                    </div>
                </Card>
                <Card title="Últimas descargas">
                    {recent.length === 0 ? (
                        <Empty>
                            Aún no se ha descargado ningún reporte de esta
                            empresa.
                        </Empty>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {recent.map((row, index) => (
                                <li
                                    key={`${row.at}-${index}`}
                                    className="py-2.5 text-sm"
                                >
                                    <p className="font-medium text-slate-900">
                                        {exportLabel(row)}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {row.user} ·{' '}
                                        {new Date(row.at).toLocaleString(
                                            'es-GT',
                                            {
                                                dateStyle: 'short',
                                                timeStyle: 'short',
                                            },
                                        )}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </PageShell>
    );
}
