import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Download,
    FileSpreadsheet,
    FileText,
    Landmark,
    Mail,
    MoreHorizontal,
    RefreshCcw,
    Search,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type AccountTab = 'Clientes' | 'Proveedores' | 'Bancos' | 'Cuentas contables';
type MovementStatus = 'Vigente' | 'Vencido';
type Movement = {
    id: number;
    document: string;
    date: string;
    due: string;
    concept: string;
    debit: number;
    credit: number;
    balance: number;
    status: MovementStatus;
};

const movements: Movement[] = [
    {
        id: 1,
        document: 'FAC-0001258',
        date: '01 Sep 2026',
        due: '01 Oct 2026',
        concept: 'Factura por venta de mercadería',
        debit: 25430,
        credit: 0,
        balance: 25430,
        status: 'Vigente',
    },
    {
        id: 2,
        document: 'FAC-0001261',
        date: '05 Sep 2026',
        due: '05 Oct 2026',
        concept: 'Factura por servicios profesionales',
        debit: 18750,
        credit: 0,
        balance: 44180,
        status: 'Vigente',
    },
    {
        id: 3,
        document: 'NC-0000032',
        date: '08 Sep 2026',
        due: '08 Sep 2026',
        concept: 'Nota de crédito por devolución',
        debit: 0,
        credit: 2500,
        balance: 41680,
        status: 'Vigente',
    },
    {
        id: 4,
        document: 'REC-0004587',
        date: '12 Sep 2026',
        due: '12 Sep 2026',
        concept: 'Pago recibido',
        debit: 0,
        credit: 15000,
        balance: 26680,
        status: 'Vigente',
    },
    {
        id: 5,
        document: 'FAC-0001274',
        date: '15 Sep 2026',
        due: '15 Sep 2026',
        concept: 'Factura por venta de mercadería',
        debit: 32100,
        credit: 0,
        balance: 58780,
        status: 'Vencido',
    },
    {
        id: 6,
        document: 'FAC-0001278',
        date: '20 Sep 2026',
        due: '20 Oct 2026',
        concept: 'Factura por servicios profesionales',
        debit: 12600,
        credit: 0,
        balance: 71380,
        status: 'Vigente',
    },
    {
        id: 7,
        document: 'REC-0004621',
        date: '22 Sep 2026',
        due: '22 Sep 2026',
        concept: 'Pago recibido',
        debit: 0,
        credit: 20000,
        balance: 51380,
        status: 'Vigente',
    },
    {
        id: 8,
        document: 'FAC-0001285',
        date: '25 Sep 2026',
        due: '25 Oct 2026',
        concept: 'Factura por consultoría',
        debit: 45800,
        credit: 0,
        balance: 97180,
        status: 'Vigente',
    },
    {
        id: 9,
        document: 'FAC-0001289',
        date: '28 Ago 2026',
        due: '27 Sep 2026',
        concept: 'Factura por mantenimiento',
        debit: 18940,
        credit: 0,
        balance: 116120,
        status: 'Vencido',
    },
    {
        id: 10,
        document: 'NC-0000035',
        date: '29 Sep 2026',
        due: '29 Sep 2026',
        concept: 'Nota de crédito por ajuste',
        debit: 0,
        credit: 3200,
        balance: 112920,
        status: 'Vigente',
    },
    {
        id: 11,
        document: 'FAC-0001293',
        date: '30 Sep 2026',
        due: '30 Oct 2026',
        concept: 'Factura por venta de mercadería',
        debit: 22510,
        credit: 0,
        balance: 135430,
        status: 'Vigente',
    },
];

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const money = (amount: number) =>
    `Q ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Metric({
    icon: Icon,
    label,
    value,
    change,
    tone,
    negative = false,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    change: string;
    tone: string;
    negative?: boolean;
}) {
    const arrow = label === 'Por pagar' ? '↓' : '↑';

    return (
        <article
            className={`${panel} flex min-h-[108px] min-w-0 items-center gap-4 overflow-hidden p-5`}
        >
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div className="min-w-0">
                <p className="text-xs font-semibold text-slate-600">{label}</p>
                <strong className="mt-1 block truncate text-2xl font-black text-slate-950">
                    {value}
                </strong>
                <p
                    className={`mt-1 text-[11px] font-semibold ${negative ? 'text-red-600' : 'text-emerald-600'}`}
                >
                    {arrow} {change}{' '}
                    <span className="ml-2 font-normal text-slate-500">
                        vs. período anterior
                    </span>
                </p>
            </div>
        </article>
    );
}

export default function AccountStatementsWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [tab, setTab] = useState<AccountTab>('Clientes');
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('Todos');
    const [notice, setNotice] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const tabs: { name: AccountTab; icon: LucideIcon }[] = [
        { name: 'Clientes', icon: Users },
        { name: 'Proveedores', icon: Building2 },
        { name: 'Bancos', icon: Landmark },
        { name: 'Cuentas contables', icon: FileText },
    ];

    const filtered = useMemo(
        () =>
            movements.filter((row) => {
                const matchesStatus =
                    status === 'Todos' || row.status === status;

                return (
                    matchesStatus &&
                    `${row.document} ${row.concept}`
                        .toLowerCase()
                        .includes(query.toLowerCase())
                );
            }),
        [query, status],
    );

    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3400);
    };
    const toggleSelected = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((item) => item !== id)
                : [...current, id],
        );

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] px-4 py-5 text-[#172033] sm:px-6">
            <Head title={`Estados de Cuenta · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Estados de
                            Cuenta
                        </p>
                        <h1 className="text-[2rem] leading-none font-black tracking-tight text-[#74000a]">
                            Estados de Cuenta
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Saldos, movimientos y vencimientos por cliente,
                            proveedor, banco o cuenta
                        </p>
                    </div>
                    <blockquote className="max-w-md pt-6 text-right text-sm text-slate-500 italic">
                        “Información que impulsa relaciones más sólidas.”
                        <span className="block text-xs">
                            — Atlantia Finance
                        </span>
                    </blockquote>
                </header>

                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}

                <section className="grid grid-cols-4 gap-4">
                    <Metric
                        icon={Users}
                        label="Por cobrar"
                        value="Q 1,285,430.25"
                        change="12%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Building2}
                        label="Por pagar"
                        value="Q 732,890.10"
                        change="8%"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={AlertTriangle}
                        label="Vencidos"
                        value="Q 386,120.75"
                        change="28%"
                        tone="bg-red-50 text-red-700"
                        negative
                    />
                    <Metric
                        icon={CheckCircle2}
                        label="Conciliados"
                        value="Q 2,156,340.50"
                        change="6%"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                </section>

                <section
                    className={`${panel} grid gap-3 p-4 xl:grid-cols-[1fr_1fr_1.15fr_1fr_1fr_1fr_1fr_auto_auto]`}
                >
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Empresa</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center justify-between rounded-lg border border-[#dfe5ef] bg-white px-3 text-left text-xs font-normal text-[#26344d]"
                        >
                            {company.name}
                            <ChevronDown className="size-4" />
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Tipo de estado de cuenta</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center justify-between rounded-lg border border-[#dfe5ef] bg-white px-3 text-left text-xs font-normal text-[#26344d]"
                        >
                            {tab}
                            <ChevronDown className="size-4" />
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Tercero</span>
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={`Todos los ${tab.toLowerCase()}`}
                            className="h-10 w-full rounded-lg border border-[#dfe5ef] bg-white px-3 text-xs font-normal text-[#26344d]"
                        />
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Moneda</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center justify-between rounded-lg border border-[#dfe5ef] bg-white px-3 text-left text-xs font-normal text-[#26344d]"
                        >
                            Quetzales (GTQ)
                            <ChevronDown className="size-4" />
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Fecha desde</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center gap-2 rounded-lg border border-[#dfe5ef] bg-white px-3 text-xs font-normal text-[#26344d]"
                        >
                            <CalendarDays className="size-4" />
                            01/09/2026
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Fecha hasta</span>
                        <button
                            type="button"
                            className="flex h-10 w-full items-center gap-2 rounded-lg border border-[#dfe5ef] bg-white px-3 text-xs font-normal text-[#26344d]"
                        >
                            <CalendarDays className="size-4" />
                            30/09/2026
                        </button>
                    </label>
                    <label className="space-y-1 text-xs font-semibold text-slate-600">
                        <span>Estado</span>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="h-10 w-full rounded-lg border border-[#dfe5ef] bg-white px-3 text-xs font-normal text-[#26344d]"
                        >
                            <option>Todos</option>
                            <option>Vigente</option>
                            <option>Vencido</option>
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={() => {
                                setQuery('');
                                setStatus('Todos');
                            }}
                            className="h-10 rounded-lg border border-[#dfe5ef] bg-slate-50 px-6 text-xs font-semibold text-slate-600"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={() =>
                                action(
                                    `${filtered.length} movimientos encontrados para ${tab.toLowerCase()}.`,
                                )
                            }
                            className="flex h-10 items-center justify-center gap-2 rounded-lg bg-[#74000a] px-7 text-xs font-semibold text-white"
                        >
                            <Search className="size-4" />
                            Buscar
                        </button>
                    </div>
                </section>

                <section className="grid items-start gap-4 2xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="space-y-3">
                        <nav
                            className={`${panel} flex overflow-x-auto border-b-0 px-1`}
                        >
                            {tabs.map(({ name, icon: Icon }) => (
                                <button
                                    key={name}
                                    type="button"
                                    onClick={() => {
                                        setTab(name);
                                        setQuery('');
                                    }}
                                    className={`flex min-w-max items-center gap-2 border-b-2 px-7 py-4 text-sm font-semibold ${tab === name ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                                >
                                    <Icon className="size-5" />
                                    {name}
                                </button>
                            ))}
                        </nav>
                        <article className={`${panel} overflow-hidden`}>
                            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                                <div>
                                    <h2 className="flex items-center gap-2 font-bold">
                                        <CalendarDays className="size-5 text-[#74000a]" />
                                        Movimientos del estado de cuenta
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Documentos, pagos y saldos de{' '}
                                        {tab.toLowerCase()}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        action(
                                            `Tabla de ${tab.toLowerCase()} preparada para exportar.`,
                                        )
                                    }
                                    className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                                >
                                    <Download className="size-4" />
                                    Exportar tabla
                                    <ChevronDown className="size-4" />
                                </button>
                            </header>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[920px] text-left text-xs">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-3">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Seleccionar todos"
                                                    checked={
                                                        selected.length ===
                                                            filtered.length &&
                                                        filtered.length > 0
                                                    }
                                                    onChange={() =>
                                                        setSelected(
                                                            selected.length ===
                                                                filtered.length
                                                                ? []
                                                                : filtered.map(
                                                                      (row) =>
                                                                          row.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                            </th>
                                            <th>Documento</th>
                                            <th>Fecha</th>
                                            <th>Vence</th>
                                            <th>Concepto</th>
                                            <th className="text-right">
                                                Cargo (Q)
                                            </th>
                                            <th className="text-right">
                                                Abono (Q)
                                            </th>
                                            <th className="text-right">
                                                Saldo (Q)
                                            </th>
                                            <th>Estado</th>
                                            <th className="pr-4 text-center">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {filtered.map((row) => (
                                            <tr
                                                key={row.id}
                                                className={`${selected.includes(row.id) ? 'bg-rose-50' : 'hover:bg-slate-50'}`}
                                            >
                                                <td className="px-4 py-2.5">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Seleccionar ${row.document}`}
                                                        checked={selected.includes(
                                                            row.id,
                                                        )}
                                                        onChange={() =>
                                                            toggleSelected(
                                                                row.id,
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="font-semibold">
                                                    {row.document}
                                                </td>
                                                <td>{row.date}</td>
                                                <td
                                                    className={
                                                        row.status === 'Vencido'
                                                            ? 'font-semibold text-red-600'
                                                            : ''
                                                    }
                                                >
                                                    {row.due}
                                                </td>
                                                <td>{row.concept}</td>
                                                <td className="text-right">
                                                    {row.debit
                                                        ? money(row.debit)
                                                        : '—'}
                                                </td>
                                                <td className="text-right">
                                                    {row.credit
                                                        ? money(row.credit)
                                                        : '—'}
                                                </td>
                                                <td className="text-right font-semibold">
                                                    {money(row.balance)}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`inline-flex items-center gap-1 rounded-full px-3 py-1 font-semibold ${row.status === 'Vigente' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}
                                                    >
                                                        <CheckCircle2 className="size-3" />
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="pr-4 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            action(
                                                                `Detalle abierto: ${row.document}.`,
                                                            )
                                                        }
                                                        aria-label={`Abrir ${row.document}`}
                                                    >
                                                        <MoreHorizontal className="size-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {!filtered.length && (
                                    <div className="p-10 text-center text-sm text-slate-400">
                                        No se encontraron movimientos.
                                    </div>
                                )}
                            </div>
                            <footer className="flex flex-wrap items-center justify-between gap-3 border-t p-4 text-xs text-slate-500">
                                <span>
                                    Mostrando {filtered.length} de 11
                                    movimientos{' '}
                                    {selected.length
                                        ? `· ${selected.length} seleccionados`
                                        : ''}
                                </span>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        className="grid size-8 place-items-center rounded border"
                                    >
                                        <ChevronLeft className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        className="grid size-8 place-items-center rounded bg-[#74000a] text-white"
                                    >
                                        1
                                    </button>
                                    <button
                                        type="button"
                                        className="grid size-8 place-items-center rounded border"
                                    >
                                        <ChevronRight className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        className="ml-2 flex items-center gap-3 rounded border px-3 py-2"
                                    >
                                        50 por página
                                        <ChevronDown className="size-4" />
                                    </button>
                                </div>
                            </footer>
                        </article>
                    </div>

                    <aside className="space-y-4">
                        <article className={`${panel} overflow-hidden`}>
                            <header className="border-b px-5 py-4">
                                <h2 className="flex items-center gap-2 font-bold">
                                    <FileText className="size-5 text-[#74000a]" />
                                    Resumen del{' '}
                                    {tab === 'Clientes' ? 'cliente' : 'estado'}
                                </h2>
                            </header>
                            <div className="p-5">
                                <div className="flex justify-between gap-3">
                                    <div>
                                        <p className="text-xs text-slate-500">
                                            {tab === 'Clientes'
                                                ? 'Cliente'
                                                : tab.slice(0, -1)}
                                        </p>
                                        <strong className="text-lg">
                                            {tab === 'Clientes'
                                                ? 'Comercial Los Pinos, S.A.'
                                                : `${tab} Atlantia`}
                                        </strong>
                                    </div>
                                    <button
                                        type="button"
                                        className="self-start text-xs font-semibold text-blue-700 underline"
                                    >
                                        CLI-0000452
                                    </button>
                                </div>
                                <dl className="mt-3 space-y-2 text-xs">
                                    <div className="flex justify-between">
                                        <dt>NIT</dt>
                                        <dd>1234567-8</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Tipo</dt>
                                        <dd>
                                            {tab === 'Clientes'
                                                ? 'Cliente'
                                                : tab}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Ejecutivo</dt>
                                        <dd>Ana López</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt>Límite de crédito</dt>
                                        <dd>Q 200,000.00</dd>
                                    </div>
                                </dl>
                                <div className="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-gradient-to-r from-emerald-50 to-rose-50 p-4">
                                    <div>
                                        <p className="flex items-center gap-2 text-xs text-emerald-700">
                                            <Banknote className="size-4" />
                                            Saldo total
                                        </p>
                                        <strong className="mt-1 block text-lg">
                                            Q 135,430.00
                                        </strong>
                                    </div>
                                    <div>
                                        <p className="flex items-center gap-2 text-xs text-red-600">
                                            <AlertTriangle className="size-4" />
                                            Saldo vencido
                                        </p>
                                        <strong className="mt-1 block text-lg text-red-600">
                                            Q 36,940.00
                                        </strong>
                                    </div>
                                    <div className="col-span-2">
                                        <p className="flex items-center gap-2 text-xs">
                                            <CircleDollarSign className="size-4" />
                                            Promedio de mora
                                        </p>
                                        <strong className="text-lg">
                                            42 días
                                        </strong>
                                    </div>
                                </div>
                                <div className="mt-3 space-y-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            action(
                                                'Estado de cuenta PDF generado.',
                                            )
                                        }
                                        className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#74000a] px-4 py-2.5 text-xs font-semibold text-white"
                                    >
                                        <FileText className="size-4" />
                                        Exportar PDF
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            action(
                                                'Estado de cuenta preparado para enviar al cliente.',
                                            )
                                        }
                                        className="flex w-full items-center justify-center gap-2 rounded-lg border border-[#9b1923] px-4 py-2.5 text-xs font-semibold text-[#74000a]"
                                    >
                                        <Mail className="size-4" />
                                        Enviar al cliente
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            action(
                                                'Archivo Excel preparado para descargar.',
                                            )
                                        }
                                        className="flex w-full items-center justify-center gap-2 rounded-lg border border-[#9b1923] px-4 py-2.5 text-xs font-semibold text-[#74000a]"
                                    >
                                        <FileSpreadsheet className="size-4" />
                                        Descargar Excel
                                    </button>
                                </div>
                            </div>
                        </article>
                        <article className={`${panel} p-5`}>
                            <h2 className="mb-4 flex items-center gap-2 font-bold">
                                <CalendarDays className="size-5 text-[#74000a]" />
                                Antigüedad de saldos
                            </h2>
                            <div className="space-y-3">
                                {[
                                    [
                                        '0 - 30 días',
                                        'Q 98,490.00',
                                        '73%',
                                        'bg-emerald-500',
                                    ],
                                    [
                                        '31 - 60 días',
                                        'Q 18,940.00',
                                        '14%',
                                        'bg-amber-400',
                                    ],
                                    [
                                        '61 - 90 días',
                                        'Q 0.00',
                                        '0%',
                                        'bg-slate-300',
                                    ],
                                    [
                                        '+ 90 días',
                                        'Q 17,000.00',
                                        '13%',
                                        'bg-red-500',
                                    ],
                                ].map((item) => (
                                    <div
                                        key={item[0]}
                                        className="grid grid-cols-[72px_92px_1fr_34px] items-center gap-2 text-[11px]"
                                    >
                                        <span>{item[0]}</span>
                                        <span className="text-right font-semibold">
                                            {item[1]}
                                        </span>
                                        <div className="h-3 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className={`h-full ${item[3]}`}
                                                style={{ width: item[2] }}
                                            />
                                        </div>
                                        <span className="text-right">
                                            {item[2]}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        'Saldos actualizados con los últimos movimientos.',
                                    )
                                }
                                className="mt-4 flex items-center gap-2 text-xs text-slate-500"
                            >
                                <RefreshCcw className="size-3" />
                                Última actualización: 30 Sep 2026 16:30
                            </button>
                        </article>
                    </aside>
                </section>
            </div>
        </main>
    );
}
