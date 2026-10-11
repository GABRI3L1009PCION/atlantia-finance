import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Barcode,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Clock3,
    Download,
    Edit3,
    Filter,
    Lightbulb,
    ListChecks,
    PackagePlus,
    Plus,
    Printer,
    Search,
    Settings2,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type CountRow = {
    id: number;
    code: string;
    icon: string;
    product: string;
    category: string;
    system: number;
    physical: number | null;
    unitCost: number;
};

const initialRows: CountRow[] = [
    {
        id: 1,
        code: 'PROD-001',
        icon: '💻',
        product: 'Laptop Dell Inspiron 15',
        category: 'Tecnología',
        system: 12,
        physical: 11,
        unitCost: 550000,
    },
    {
        id: 2,
        code: 'PROD-002',
        icon: '📚',
        product: 'Resma papel carta',
        category: 'Papelería',
        system: 125,
        physical: 122,
        unitCost: 3500,
    },
    {
        id: 3,
        code: 'PROD-003',
        icon: '🖱️',
        product: 'Mouse inalámbrico',
        category: 'Tecnología',
        system: 50,
        physical: 50,
        unitCost: 8200,
    },
    {
        id: 4,
        code: 'PROD-004',
        icon: '⌨️',
        product: 'Teclado mecánico',
        category: 'Tecnología',
        system: 25,
        physical: 27,
        unitCost: 45000,
    },
    {
        id: 5,
        code: 'PROD-005',
        icon: '🪑',
        product: 'Silla ergonómica',
        category: 'Mobiliario',
        system: 8,
        physical: 8,
        unitCost: 89000,
    },
    {
        id: 6,
        code: 'PROD-006',
        icon: '🖥️',
        product: 'Monitor 24 pulgadas',
        category: 'Tecnología',
        system: 18,
        physical: null,
        unitCost: 141000,
    },
    {
        id: 7,
        code: 'PROD-007',
        icon: '🧴',
        product: 'Tinta impresora HP',
        category: 'Consumibles',
        system: 3,
        physical: 2,
        unitCost: 12000,
    },
    {
        id: 8,
        code: 'PROD-008',
        icon: '☕',
        product: 'Café en grano 1kg',
        category: 'Alimentos',
        system: 50,
        physical: 50,
        unitCost: 5900,
    },
    {
        id: 9,
        code: 'PROD-009',
        icon: '💾',
        product: 'Memoria USB 64GB',
        category: 'Tecnología',
        system: 40,
        physical: null,
        unitCost: 9500,
    },
    {
        id: 10,
        code: 'PROD-010',
        icon: '📓',
        product: 'Cuaderno universitario',
        category: 'Papelería',
        system: 200,
        physical: 198,
        unitCost: 2500,
    },
];

const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value / 100);
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

function CountMetric({
    label,
    value,
    detail,
    icon: Icon,
    tone,
    progress,
}: {
    label: string;
    value: string;
    detail: string;
    icon: LucideIcon;
    tone: string;
    progress?: number;
}) {
    return (
        <article className={`${panel} flex min-h-20 items-start gap-3 p-3`}>
            <span
                className={`grid size-10 shrink-0 place-items-center rounded-lg ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[10px] text-slate-500">{label}</p>
                <strong className="mt-1 block text-xl text-[#132143]">
                    {value}
                </strong>
                {progress !== undefined ? (
                    <div className="mt-2 flex items-center gap-2">
                        <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                            <i
                                className="block h-full rounded-full bg-emerald-500"
                                style={{ width: `${progress}%` }}
                            />
                        </span>
                        <span className="text-[10px]">{detail}</span>
                    </div>
                ) : (
                    <p className="mt-1 text-[10px] text-slate-500">{detail}</p>
                )}
            </div>
        </article>
    );
}

export default function PhysicalInventoryWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [rows, setRows] = useState(initialRows);
    const [query, setQuery] = useState('');
    const [tab, setTab] = useState('Productos');
    const [notice, setNotice] = useState('');
    const pending = rows.filter((row) => row.physical === null).length;
    const filtered = useMemo(
        () =>
            rows.filter((row) =>
                `${row.code} ${row.product} ${row.category}`
                    .toLowerCase()
                    .includes(query.toLowerCase()),
            ),
        [query, rows],
    );
    const updatePhysical = (id: number, value: string) =>
        setRows((current) =>
            current.map((row) =>
                row.id === id
                    ? { ...row, physical: value === '' ? null : Number(value) }
                    : row,
            ),
        );
    const stages = [
        ['Configuración', true],
        ['Conteo', true],
        ['Revisión', false],
        ['Ajustes', false],
        ['Cierre', false],
    ] as const;

    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#13213d] sm:p-5">
            <Head title={`Conteo de inventario físico · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Inventario</span>
                    <span>›</span>
                    <span>Conteos físicos</span>
                    <span>›</span>
                    <strong>CF-2026-000125</strong>
                </div>
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Conteo de inventario físico
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Verifica las existencias reales de tus productos y
                            genera ajustes cuando sea necesario.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Vista de impresión del conteo preparada.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <Printer className="size-4" /> Imprimir
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice('Conteo exportado correctamente.')
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <Download className="size-4" /> Exportar
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    pending
                                        ? `Aún quedan ${pending} productos pendientes de contar.`
                                        : 'Conteo finalizado y enviado a revisión.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-[#0c3574] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Check className="size-4" /> Finalizar conteo
                        </button>
                    </div>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
                            {notice}
                        </span>
                        <button
                            type="button"
                            onClick={() => setNotice('')}
                            aria-label="Cerrar aviso"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className={`${panel} overflow-x-auto p-3`}>
                    <div className="relative flex min-w-[720px] justify-around before:absolute before:top-4 before:right-[8%] before:left-[8%] before:h-px before:bg-slate-300">
                        {stages.map(([name, done], index) => (
                            <div
                                key={name}
                                className="relative z-10 flex w-32 items-center gap-2 bg-white px-2"
                            >
                                <span
                                    className={`grid size-8 shrink-0 place-items-center rounded-full text-xs font-bold ${done ? 'bg-[#0c3574] text-white' : 'bg-slate-200 text-slate-500'}`}
                                >
                                    {index === 0 ? (
                                        <Check className="size-4" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <strong
                                    className={
                                        index === 1 ? 'text-[#0c3574]' : ''
                                    }
                                >
                                    {name}
                                </strong>
                            </div>
                        ))}
                    </div>
                </section>

                <div className="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="space-y-4">
                        <section className={`${panel} p-4`}>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-lg bg-amber-50 text-amber-600">
                                        <ListChecks className="size-5" />
                                    </span>
                                    <h2 className="text-xl font-bold">
                                        CF-2026-000125
                                    </h2>
                                    <span className="rounded-full bg-blue-100 px-3 py-1 text-[10px] font-semibold text-blue-700">
                                        ● En conteo
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setNotice(
                                            'Datos generales del conteo habilitados para edición.',
                                        )
                                    }
                                    className="flex items-center gap-2 rounded-lg border border-blue-500 px-4 py-2 text-xs font-semibold text-blue-700"
                                >
                                    <Edit3 className="size-4" /> Editar
                                </button>
                            </div>
                            <div className="mt-4 grid gap-4 text-xs sm:grid-cols-2 2xl:grid-cols-4">
                                <dl className="space-y-2">
                                    <div>
                                        <dt className="text-[10px] text-slate-500">
                                            Empresa
                                        </dt>
                                        <dd className="font-semibold">
                                            {company.name}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[10px] text-slate-500">
                                            Bodega
                                        </dt>
                                        <dd className="font-semibold">
                                            Bodega Central
                                        </dd>
                                    </div>
                                </dl>
                                <dl className="space-y-2 border-l border-slate-100 pl-4">
                                    <div>
                                        <dt className="text-[10px] text-slate-500">
                                            Fecha de inicio
                                        </dt>
                                        <dd className="font-semibold">
                                            30/09/2026 08:00
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[10px] text-slate-500">
                                            Fecha de corte
                                        </dt>
                                        <dd className="font-semibold">
                                            30/09/2026 18:00
                                        </dd>
                                    </div>
                                </dl>
                                <dl className="space-y-2 border-l border-slate-100 pl-4">
                                    <div>
                                        <dt className="text-[10px] text-slate-500">
                                            Responsable
                                        </dt>
                                        <dd className="font-semibold">
                                            Carlos Pérez
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[10px] text-slate-500">
                                            Método
                                        </dt>
                                        <dd className="font-semibold">
                                            Conteo normal
                                        </dd>
                                    </div>
                                </dl>
                                <label className="text-[10px] text-slate-500">
                                    Observaciones
                                    <textarea
                                        defaultValue="Conteo mensual de inventario general."
                                        rows={3}
                                        className="mt-1 w-full rounded-lg border border-slate-200 p-2 text-xs text-slate-700 outline-none focus:border-blue-500"
                                    />
                                </label>
                            </div>
                        </section>
                        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                            <CountMetric
                                label="Productos en conteo"
                                value="185"
                                detail="100% listados"
                                icon={PackagePlus}
                                tone="bg-blue-50 text-blue-600"
                            />
                            <CountMetric
                                label="Contados"
                                value="142"
                                detail="77%"
                                progress={77}
                                icon={CheckCircle2}
                                tone="bg-emerald-50 text-emerald-600"
                            />
                            <CountMetric
                                label="Pendientes"
                                value="28"
                                detail="15%"
                                progress={15}
                                icon={Clock3}
                                tone="bg-amber-50 text-amber-600"
                            />
                            <CountMetric
                                label="Diferencias"
                                value="15"
                                detail="8%"
                                progress={8}
                                icon={AlertTriangle}
                                tone="bg-rose-50 text-rose-600"
                            />
                            <CountMetric
                                label="Valor en diferencias"
                                value={money(485000)}
                                detail="Impacto estimado"
                                icon={CircleDollarSign}
                                tone="bg-violet-50 text-violet-600"
                            />
                        </section>
                        <section className={`${panel} overflow-hidden`}>
                            <nav className="flex overflow-x-auto border-b border-slate-200 px-3">
                                {[
                                    'Productos',
                                    'Diferencias (15)',
                                    'Reconteos (2)',
                                    'Ajustes (0)',
                                    'Documentos',
                                    'Historial',
                                ].map((item) => (
                                    <button
                                        key={item}
                                        type="button"
                                        onClick={() => setTab(item)}
                                        className={`border-b-2 px-4 py-3 text-xs font-semibold whitespace-nowrap ${tab === item ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                                    >
                                        {item}
                                    </button>
                                ))}
                            </nav>
                            {tab === 'Productos' ? (
                                <>
                                    <div className="grid gap-2 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-[1.6fr_auto_.7fr_.7fr_auto]">
                                        <label className="relative">
                                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                            <input
                                                value={query}
                                                onChange={(event) =>
                                                    setQuery(event.target.value)
                                                }
                                                placeholder="Buscar producto por código, nombre o código de barras..."
                                                className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-blue-500"
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setNotice(
                                                    'Escáner listo para recibir un código de barras.',
                                                )
                                            }
                                            className="flex items-center justify-center gap-2 rounded-lg border border-blue-500 px-4 py-2.5 text-xs font-semibold text-blue-700"
                                        >
                                            <Barcode className="size-4" />{' '}
                                            Escanear código
                                        </button>
                                        <button
                                            type="button"
                                            className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                                        >
                                            Todos los estados
                                            <ChevronDown className="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                                        >
                                            Todas las categorías
                                            <ChevronDown className="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            className="grid place-items-center rounded-lg border border-slate-200 px-4"
                                        >
                                            <Filter className="size-4 text-blue-600" />
                                        </button>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[980px] text-left text-[10px]">
                                            <thead className="bg-slate-50 text-slate-600">
                                                <tr>
                                                    <th className="px-4 py-2">
                                                        #
                                                    </th>
                                                    <th>Código</th>
                                                    <th>Producto</th>
                                                    <th>Categoría</th>
                                                    <th className="text-center">
                                                        Sistema
                                                    </th>
                                                    <th className="text-center">
                                                        Conteo físico
                                                    </th>
                                                    <th className="text-center">
                                                        Diferencia
                                                    </th>
                                                    <th className="text-right">
                                                        Valor diferencia
                                                    </th>
                                                    <th className="text-center">
                                                        Estado
                                                    </th>
                                                    <th className="pr-4 text-right">
                                                        Acciones
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {filtered.map((row) => {
                                                    const difference =
                                                        row.physical === null
                                                            ? null
                                                            : row.physical -
                                                              row.system;
                                                    const state =
                                                        difference === null
                                                            ? [
                                                                  'Pendiente',
                                                                  'bg-amber-100 text-amber-700',
                                                              ]
                                                            : difference === 0
                                                              ? [
                                                                    'Correcto',
                                                                    'bg-emerald-100 text-emerald-700',
                                                                ]
                                                              : [
                                                                    'Diferencia',
                                                                    'bg-rose-100 text-rose-700',
                                                                ];

                                                    return (
                                                        <tr
                                                            key={row.id}
                                                            className="hover:bg-blue-50/50"
                                                        >
                                                            <td className="px-4 py-2">
                                                                {row.id}
                                                            </td>
                                                            <td className="text-blue-700">
                                                                {row.code}
                                                            </td>
                                                            <td className="font-semibold">
                                                                <span className="mr-2 text-base">
                                                                    {row.icon}
                                                                </span>
                                                                {row.product}
                                                            </td>
                                                            <td>
                                                                {row.category}
                                                            </td>
                                                            <td className="text-center font-bold">
                                                                {row.system}
                                                            </td>
                                                            <td className="text-center">
                                                                <input
                                                                    type="number"
                                                                    value={
                                                                        row.physical ??
                                                                        ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updatePhysical(
                                                                            row.id,
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="w-20 rounded border border-slate-200 px-2 py-1 text-center font-bold outline-none focus:border-blue-500"
                                                                />
                                                            </td>
                                                            <td
                                                                className={`text-center font-bold ${difference && difference < 0 ? 'text-red-600' : difference && difference > 0 ? 'text-emerald-700' : ''}`}
                                                            >
                                                                {difference ===
                                                                null
                                                                    ? '—'
                                                                    : difference >
                                                                        0
                                                                      ? `+${difference}`
                                                                      : difference}
                                                            </td>
                                                            <td
                                                                className={`text-right font-semibold ${difference ? 'text-red-600' : ''}`}
                                                            >
                                                                {difference ===
                                                                null
                                                                    ? '—'
                                                                    : money(
                                                                          difference *
                                                                              row.unitCost,
                                                                      )}
                                                            </td>
                                                            <td className="text-center">
                                                                <span
                                                                    className={`rounded-full px-2 py-1 ${state[1]}`}
                                                                >
                                                                    ● {state[0]}
                                                                </span>
                                                            </td>
                                                            <td className="pr-4 text-right">
                                                                •••
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                    <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                                        <span>
                                            Mostrando 1 a {filtered.length} de
                                            185 productos
                                        </span>
                                        <div className="flex gap-1">
                                            <button
                                                type="button"
                                                className="grid size-8 place-items-center rounded border border-slate-200"
                                            >
                                                <ChevronLeft className="size-4" />
                                            </button>
                                            {[
                                                '1',
                                                '2',
                                                '3',
                                                '4',
                                                '5',
                                                '…',
                                                '19',
                                            ].map((page) => (
                                                <button
                                                    key={page}
                                                    type="button"
                                                    className={`grid size-8 place-items-center rounded ${page === '1' ? 'bg-blue-600 text-white' : 'border border-slate-200'}`}
                                                >
                                                    {page}
                                                </button>
                                            ))}
                                            <button
                                                type="button"
                                                className="grid size-8 place-items-center rounded border border-slate-200"
                                            >
                                                <ChevronRight className="size-4" />
                                            </button>
                                        </div>
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-3 py-2"
                                        >
                                            10 por página⌄
                                        </button>
                                    </footer>
                                </>
                            ) : (
                                <div className="grid min-h-[480px] place-items-center p-8 text-center">
                                    <div>
                                        <ListChecks className="mx-auto size-10 text-blue-600" />
                                        <h3 className="mt-3 font-bold">
                                            {tab}
                                        </h3>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Consulta y administra esta etapa del
                                            conteo físico.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </section>
                    </div>

                    <aside className="space-y-4">
                        <section className={`${panel} p-4`}>
                            <h2 className="text-sm font-bold">
                                Resumen del conteo
                            </h2>
                            <div className="mt-3 divide-y divide-slate-100 text-xs">
                                {(
                                    [
                                        [
                                            PackagePlus,
                                            'Total de productos',
                                            '185',
                                            'text-blue-600',
                                        ],
                                        [
                                            CheckCircle2,
                                            'Productos contados',
                                            '142 (77%)',
                                            'text-emerald-600',
                                        ],
                                        [
                                            Clock3,
                                            'Pendientes',
                                            '28 (15%)',
                                            'text-amber-600',
                                        ],
                                        [
                                            AlertTriangle,
                                            'Con diferencias',
                                            '15 (8%)',
                                            'text-red-600',
                                        ],
                                        [
                                            CircleDollarSign,
                                            'Valor en diferencias',
                                            money(485000),
                                            'text-violet-600',
                                        ],
                                    ] satisfies [
                                        LucideIcon,
                                        string,
                                        string,
                                        string,
                                    ][]
                                ).map(([Icon, label, value, tone]) => (
                                    <div
                                        key={String(label)}
                                        className="flex items-center gap-3 py-2"
                                    >
                                        <Icon className={`size-4 ${tone}`} />
                                        <span className="flex-1">{label}</span>
                                        <strong>{value}</strong>
                                    </div>
                                ))}
                            </div>
                        </section>
                        <section className={`${panel} p-4`}>
                            <h2 className="text-sm font-bold">
                                Acciones rápidas
                            </h2>
                            <div className="mt-3 space-y-2">
                                {(
                                    [
                                        [Plus, 'Agregar producto'],
                                        [Download, 'Importar lista (Excel)'],
                                        [Printer, 'Imprimir hoja de conteo'],
                                        [
                                            ListChecks,
                                            'Ver productos sin contar',
                                        ],
                                        [Settings2, 'Aplicar ajustes'],
                                    ] satisfies [LucideIcon, string][]
                                ).map(([Icon, label], index) => (
                                    <button
                                        key={String(label)}
                                        type="button"
                                        disabled={index === 4}
                                        onClick={() =>
                                            setNotice(
                                                `${label}: acción preparada.`,
                                            )
                                        }
                                        className="flex w-full items-center justify-center gap-2 rounded-lg border border-blue-500 px-3 py-2.5 text-xs font-semibold text-blue-700 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                                    >
                                        <Icon className="size-4" />
                                        {label}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() =>
                                        setNotice(
                                            'La cancelación requiere confirmación administrativa.',
                                        )
                                    }
                                    className="flex w-full items-center justify-center gap-2 rounded-lg border border-red-400 px-3 py-2.5 text-xs font-semibold text-red-600"
                                >
                                    <Trash2 className="size-4" /> Cancelar
                                    conteo
                                </button>
                            </div>
                        </section>
                        <section className="overflow-hidden rounded-xl border border-violet-200 bg-white shadow-sm">
                            <h2 className="flex items-center justify-between bg-violet-50 px-4 py-3 text-base font-bold">
                                <span className="flex items-center gap-2">
                                    <Lightbulb className="size-6 text-violet-600" />{' '}
                                    Luzma
                                </span>
                                <span className="rounded bg-violet-100 px-2 py-1 text-[9px] text-violet-700">
                                    BETA
                                </span>
                            </h2>
                            <div className="space-y-3 p-4 text-xs">
                                <h3 className="flex items-center gap-2 font-bold">
                                    <Sparkles className="size-4 text-violet-600" />{' '}
                                    Análisis del conteo
                                </h3>
                                <p className="flex gap-2">
                                    <CircleDollarSign className="size-4 shrink-0 text-amber-600" />
                                    Se detectan 5 productos con diferencias
                                    mayores al 5%.
                                </p>
                                <p className="flex gap-2">
                                    <AlertTriangle className="size-4 shrink-0 text-red-600" />
                                    El mayor impacto es en Tecnología
                                    (Q6,520.00).
                                </p>
                                <p className="flex gap-2">
                                    <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />
                                    Se recomienda revisar transferencias
                                    pendientes antes de ajustar.
                                </p>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setNotice(
                                            'Análisis completo generado por Atlantia IA.',
                                        )
                                    }
                                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-violet-600 py-2.5 font-semibold text-white"
                                >
                                    Ver análisis completo →
                                </button>
                            </div>
                        </section>
                    </aside>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>Atlantia Finance v1.0.0</span>
                    <span>Control hoy. Crecimiento mañana.</span>
                </footer>
            </div>
        </main>
    );
}
