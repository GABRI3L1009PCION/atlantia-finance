import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    ArrowDownToLine,
    ArrowUpFromLine,
    Boxes,
    BriefcaseBusiness,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Edit3,
    ExternalLink,
    FileText,
    Filter,
    History,
    MapPin,
    PackageCheck,
    Plus,
    Search,
    ShieldCheck,
    UserRound,
    Wrench,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type AssetStatus = 'active' | 'maintenance' | 'moved' | 'retired';
type FixedAsset = {
    id: number;
    code: string;
    icon: string;
    name: string;
    category: string;
    location: string;
    responsible: string;
    originalCost: number;
    depreciation: number;
    status: AssetStatus;
};

const fixedAssets: FixedAsset[] = [
    {
        id: 1,
        code: 'AF-000125',
        icon: '💻',
        name: 'Laptop Dell Latitude 5520',
        category: 'Equipo de cómputo',
        location: 'Oficina Central',
        responsible: 'Ana López',
        originalCost: 800000,
        depreciation: 240000,
        status: 'active',
    },
    {
        id: 2,
        code: 'AF-000124',
        icon: '🗄️',
        name: 'Escritorio ejecutivo',
        category: 'Mobiliario y equipo',
        location: 'Oficina Central',
        responsible: 'Carlos Pérez',
        originalCost: 250000,
        depreciation: 50000,
        status: 'active',
    },
    {
        id: 3,
        code: 'AF-000123',
        icon: '🚙',
        name: 'Vehículo Toyota Hilux',
        category: 'Vehículos',
        location: 'Sucursal PB',
        responsible: 'Miguel Torres',
        originalCost: 18000000,
        depreciation: 6000000,
        status: 'active',
    },
    {
        id: 4,
        code: 'AF-000122',
        icon: '🖨️',
        name: 'Impresora HP LaserJet',
        category: 'Equipo de oficina',
        location: 'Oficina Central',
        responsible: 'Ana López',
        originalCost: 180000,
        depreciation: 60000,
        status: 'maintenance',
    },
    {
        id: 5,
        code: 'AF-000121',
        icon: '❄️',
        name: 'Aire acondicionado 24BTU',
        category: 'Equipo industrial',
        location: 'Sucursal STC',
        responsible: '—',
        originalCost: 850000,
        depreciation: 200000,
        status: 'active',
    },
    {
        id: 6,
        code: 'AF-000120',
        icon: '📹',
        name: 'Cámara de seguridad',
        category: 'Equipo de seguridad',
        location: 'Oficina Central',
        responsible: 'Daniel Ruiz',
        originalCost: 110000,
        depreciation: 25000,
        status: 'active',
    },
    {
        id: 7,
        code: 'AF-000119',
        icon: '🖥️',
        name: 'Servidor Dell PowerEdge',
        category: 'Equipo de cómputo',
        location: 'Data Center',
        responsible: 'TI',
        originalCost: 3500000,
        depreciation: 1000000,
        status: 'active',
    },
    {
        id: 8,
        code: 'AF-000118',
        icon: '📽️',
        name: 'Proyector Epson',
        category: 'Equipo audiovisual',
        location: 'Sala de reuniones',
        responsible: 'Laura Méndez',
        originalCost: 580000,
        depreciation: 160000,
        status: 'active',
    },
    {
        id: 9,
        code: 'AF-000117',
        icon: '🛋️',
        name: 'Mobiliario de recepción',
        category: 'Mobiliario y equipo',
        location: 'Recepción',
        responsible: '—',
        originalCost: 520000,
        depreciation: 140000,
        status: 'moved',
    },
    {
        id: 10,
        code: 'AF-000116',
        icon: '🎙️',
        name: 'Equipo de comunicación',
        category: 'Equipo de comunicación',
        location: 'Oficina Central',
        responsible: 'Jorge Castillo',
        originalCost: 230000,
        depreciation: 80000,
        status: 'retired',
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
const statuses = {
    active: { label: 'Activo', className: 'bg-emerald-100 text-emerald-700' },
    maintenance: {
        label: 'En mantenimiento',
        className: 'bg-amber-100 text-amber-700',
    },
    moved: { label: 'Trasladado', className: 'bg-blue-100 text-blue-700' },
    retired: { label: 'Dado de baja', className: 'bg-rose-100 text-rose-700' },
};

function AssetMetric({
    label,
    value,
    detail,
    icon: Icon,
    tone,
}: {
    label: string;
    value: string;
    detail?: string;
    icon: LucideIcon;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-start gap-3 p-4`}>
            <span
                className={`grid size-11 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-5" />
            </span>
            <div>
                <p className="text-[11px] text-slate-500">{label}</p>
                <strong className="mt-1 block text-xl text-[#12213d]">
                    {value}
                </strong>
                {detail && (
                    <p className="mt-1 text-[10px] text-emerald-700">
                        {detail}
                    </p>
                )}
            </div>
        </article>
    );
}

function DepreciationChart({ originalCost }: { originalCost: number }) {
    const book = [
        originalCost * 0.7,
        originalCost * 0.56,
        originalCost * 0.43,
        originalCost * 0.3,
    ];
    const depreciation = [
        originalCost * 0.3,
        originalCost * 0.44,
        originalCost * 0.57,
        originalCost * 0.7,
    ];

    return (
        <div className="mt-3">
            <div className="flex justify-end gap-4 text-[9px] text-slate-500">
                <span className="text-[#17324f]">■ Valor en libros</span>
                <span className="text-blue-400">■ Depreciación acumulada</span>
            </div>
            <div className="mt-2 flex h-36 items-end justify-around border-b border-slate-200 px-4">
                {book.map((value, index) => (
                    <div
                        key={index}
                        className="relative flex h-full flex-1 items-end justify-center gap-1"
                    >
                        <span
                            className="w-8 bg-[#17324f]"
                            style={{
                                height: `${Math.max(12, (value / originalCost) * 100)}%`,
                            }}
                        />
                        <span
                            className="w-8 bg-blue-400"
                            style={{
                                height: `${Math.max(12, (depreciation[index] / originalCost) * 100)}%`,
                            }}
                        />
                        <span className="absolute -bottom-5 text-[9px] text-slate-500">
                            {2025 + index}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function FixedAssetsWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [query, setQuery] = useState('');
    const [listTab, setListTab] = useState('Todos (85)');
    const [detailTab, setDetailTab] = useState('Resumen');
    const [notice, setNotice] = useState('');
    const selected =
        fixedAssets.find((asset) => asset.id === selectedId) ?? fixedAssets[0];
    const filtered = useMemo(
        () =>
            fixedAssets.filter((asset) => {
                const matchesQuery =
                    `${asset.code} ${asset.name} ${asset.category} ${asset.location} ${asset.responsible}`
                        .toLowerCase()
                        .includes(query.toLowerCase());
                const matchesTab =
                    listTab.startsWith('Todos') ||
                    (listTab.startsWith('Activos') &&
                        asset.status === 'active') ||
                    (listTab.startsWith('En mantenimiento') &&
                        asset.status === 'maintenance') ||
                    (listTab.startsWith('Trasladados') &&
                        asset.status === 'moved') ||
                    (listTab.startsWith('Dados de baja') &&
                        asset.status === 'retired');

                return matchesQuery && matchesTab;
            }),
        [listTab, query],
    );
    const bookValue = selected.originalCost - selected.depreciation;

    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#12213d] sm:p-5">
            <Head title={`Activos fijos · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Activos fijos</span>
                    <span>›</span>
                    <strong>Activos</strong>
                </div>
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">Activos fijos</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestiona los bienes de larga duración de la empresa,
                            su depreciación, mantenimiento y ubicación.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Selecciona un archivo para importar activos.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <ArrowDownToLine className="size-4" /> Importar
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice('Catálogo de activos exportado.')
                            }
                            className="flex items-center gap-2 rounded-lg border border-blue-500 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700"
                        >
                            <ArrowUpFromLine className="size-4" /> Exportar
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Formulario de nuevo activo preparado.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-[#0c3574] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Plus className="size-4" /> Nuevo activo
                        </button>
                    </div>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <Check className="size-4" />
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
                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                    <AssetMetric
                        label="Total de activos"
                        value="85"
                        detail="↑ 6.3%"
                        icon={Boxes}
                        tone="bg-blue-50 text-blue-600"
                    />
                    <AssetMetric
                        label="Costo histórico"
                        value={money(125000000)}
                        icon={Clock3}
                        tone="bg-violet-50 text-violet-600"
                    />
                    <AssetMetric
                        label="Depreciación acumulada"
                        value={money(43000000)}
                        icon={Archive}
                        tone="bg-orange-50 text-orange-600"
                    />
                    <AssetMetric
                        label="Valor en libros"
                        value={money(82000000)}
                        icon={PackageCheck}
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <AssetMetric
                        label="Activos por vencer"
                        value="5"
                        detail="Próximos 12 meses"
                        icon={AlertTriangle}
                        tone="bg-rose-50 text-rose-600"
                    />
                </section>

                <div className="grid gap-4 2xl:grid-cols-[1fr_.72fr]">
                    <section className={`${panel} overflow-hidden`}>
                        <nav className="flex overflow-x-auto border-b border-slate-200 px-3">
                            {[
                                'Todos (85)',
                                'Activos (72)',
                                'En mantenimiento (4)',
                                'Trasladados (3)',
                                'Dados de baja (6)',
                            ].map((tab) => (
                                <button
                                    key={tab}
                                    type="button"
                                    onClick={() => setListTab(tab)}
                                    className={`border-b-2 px-4 py-3 text-xs font-semibold whitespace-nowrap ${listTab === tab ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {tab}
                                </button>
                            ))}
                        </nav>
                        <div className="grid gap-2 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-[1.3fr_.8fr_.8fr_.7fr_auto]">
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar activos..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-blue-500"
                                />
                            </label>
                            {[
                                'Todas las categorías',
                                'Todas las ubicaciones',
                                'Todos los estados',
                            ].map((label) => (
                                <button
                                    key={label}
                                    type="button"
                                    className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                                >
                                    {label}
                                    <ChevronDown className="size-4" />
                                </button>
                            ))}
                            <button
                                type="button"
                                className="flex items-center justify-center gap-2 rounded-lg border border-blue-500 px-4 py-2.5 text-xs font-semibold text-blue-700"
                            >
                                <Filter className="size-4" /> Filtros
                            </button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-left text-[10px]">
                                <thead className="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th className="px-4 py-2">□</th>
                                        <th>Código ↓</th>
                                        <th>Descripción</th>
                                        <th>Categoría</th>
                                        <th>Ubicación</th>
                                        <th>Responsable</th>
                                        <th className="text-right">
                                            Valor en libros
                                        </th>
                                        <th className="text-center">Estado</th>
                                        <th className="pr-4 text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((asset) => (
                                        <tr
                                            key={asset.id}
                                            onClick={() =>
                                                setSelectedId(asset.id)
                                            }
                                            className={`cursor-pointer hover:bg-blue-50/50 ${selected.id === asset.id ? 'bg-blue-50' : ''}`}
                                        >
                                            <td className="px-4 py-2">□</td>
                                            <td className="text-blue-700">
                                                {asset.code}
                                            </td>
                                            <td className="font-semibold">
                                                <span className="mr-2 text-base">
                                                    {asset.icon}
                                                </span>
                                                {asset.name}
                                            </td>
                                            <td>{asset.category}</td>
                                            <td>{asset.location}</td>
                                            <td>{asset.responsible}</td>
                                            <td className="text-right font-semibold">
                                                {money(
                                                    asset.originalCost -
                                                        asset.depreciation,
                                                )}
                                            </td>
                                            <td className="text-center">
                                                <span
                                                    className={`rounded-full px-2 py-1 ${statuses[asset.status].className}`}
                                                >
                                                    ●{' '}
                                                    {
                                                        statuses[asset.status]
                                                            .label
                                                    }
                                                </span>
                                            </td>
                                            <td className="pr-4 text-right">
                                                •••
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {filtered.length === 0 && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay activos que coincidan con los
                                    filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 85 activos
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                {['1', '2', '3', '4', '5', '…', '9'].map(
                                    (page) => (
                                        <button
                                            key={page}
                                            type="button"
                                            className={`grid size-8 place-items-center rounded ${page === '1' ? 'bg-blue-600 text-white' : 'border border-slate-200'}`}
                                        >
                                            {page}
                                        </button>
                                    ),
                                )}
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
                    </section>

                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex flex-wrap items-start gap-4 p-4">
                            <span className="grid size-20 place-items-center rounded-xl border border-slate-200 bg-slate-50 text-5xl">
                                {selected.icon}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-lg font-bold">
                                        {selected.code}
                                    </h2>
                                    <span
                                        className={`rounded-full px-2 py-1 text-[10px] ${statuses[selected.status].className}`}
                                    >
                                        ● {statuses[selected.status].label}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm text-slate-600">
                                    {selected.name}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <span className="rounded bg-slate-100 px-2 py-1 text-[10px]">
                                        {selected.category}
                                    </span>
                                    <span className="flex items-center gap-1 rounded bg-slate-100 px-2 py-1 text-[10px]">
                                        <MapPin className="size-3" />{' '}
                                        {selected.location}
                                    </span>
                                    <span className="flex items-center gap-1 rounded bg-slate-100 px-2 py-1 text-[10px]">
                                        <UserRound className="size-3" />{' '}
                                        {selected.responsible}
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(`Editando ${selected.code}.`)
                                }
                                className="flex items-center gap-2 rounded-lg border border-blue-500 px-4 py-2 text-xs font-semibold text-blue-700"
                            >
                                <Edit3 className="size-4" /> Editar
                            </button>
                            <div className="grid w-full grid-cols-3 divide-x divide-slate-200 border-t border-slate-100 pt-3">
                                {[
                                    [
                                        'Costo original',
                                        money(selected.originalCost),
                                    ],
                                    [
                                        'Dep. acumulada',
                                        money(selected.depreciation),
                                    ],
                                    ['Valor en libros', money(bookValue)],
                                ].map(([label, value]) => (
                                    <div key={label} className="px-3">
                                        <p className="text-[10px] text-slate-500">
                                            {label}
                                        </p>
                                        <strong className="text-lg">
                                            {value}
                                        </strong>
                                    </div>
                                ))}
                            </div>
                        </div>
                        <nav className="flex overflow-x-auto border-y border-slate-200 px-3">
                            {[
                                'Resumen',
                                'Depreciación',
                                'Movimientos',
                                'Mantenimiento',
                                'Documentos',
                            ].map((tab) => (
                                <button
                                    key={tab}
                                    type="button"
                                    onClick={() => setDetailTab(tab)}
                                    className={`border-b-2 px-4 py-3 text-xs font-medium whitespace-nowrap ${detailTab === tab ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {tab}
                                </button>
                            ))}
                        </nav>
                        {detailTab === 'Resumen' ? (
                            <div className="space-y-5 p-4">
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <section>
                                        <h3 className="text-sm font-bold">
                                            Información general
                                        </h3>
                                        <dl className="mt-3 grid grid-cols-2 gap-2 text-[10px]">
                                            {[
                                                ['Código', selected.code],
                                                [
                                                    'Marca / Modelo',
                                                    'Dell Latitude 5520',
                                                ],
                                                [
                                                    'Número de serie',
                                                    'DL-856214',
                                                ],
                                                [
                                                    'Fecha de adquisición',
                                                    '10/09/2025',
                                                ],
                                                [
                                                    'Vida útil',
                                                    '3 años (36 meses)',
                                                ],
                                                [
                                                    'Método de depreciación',
                                                    'Línea recta',
                                                ],
                                                ['Valor residual', money(0)],
                                            ].map(([label, value]) => (
                                                <div
                                                    key={label}
                                                    className="contents"
                                                >
                                                    <dt className="text-slate-500">
                                                        {label}
                                                    </dt>
                                                    <dd>{value}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </section>
                                    <section>
                                        <h3 className="text-sm font-bold">
                                            Ubicación y asignación
                                        </h3>
                                        <dl className="mt-3 grid grid-cols-2 gap-2 text-[10px]">
                                            {[
                                                [
                                                    'Ubicación',
                                                    selected.location,
                                                ],
                                                [
                                                    'Responsable',
                                                    selected.responsible,
                                                ],
                                                [
                                                    'Departamento',
                                                    'Contabilidad',
                                                ],
                                                [
                                                    'Estado',
                                                    statuses[selected.status]
                                                        .label,
                                                ],
                                                [
                                                    'Garantía',
                                                    'Hasta 10/09/2028',
                                                ],
                                                [
                                                    'Proveedor',
                                                    'Tecnología Maya',
                                                ],
                                            ].map(([label, value]) => (
                                                <div
                                                    key={label}
                                                    className="contents"
                                                >
                                                    <dt className="text-slate-500">
                                                        {label}
                                                    </dt>
                                                    <dd>{value}</dd>
                                                </div>
                                            ))}
                                            <dt className="text-slate-500">
                                                Factura
                                            </dt>
                                            <dd className="text-blue-600">
                                                FC-4587{' '}
                                                <ExternalLink className="inline size-3" />
                                            </dd>
                                        </dl>
                                    </section>
                                </div>
                                <section>
                                    <h3 className="text-sm font-bold">
                                        Depreciación acumulada
                                    </h3>
                                    <DepreciationChart
                                        originalCost={selected.originalCost}
                                    />
                                </section>
                            </div>
                        ) : (
                            <div className="grid min-h-[390px] place-items-center p-8 text-center">
                                <div>
                                    <BriefcaseBusiness className="mx-auto size-10 text-blue-600" />
                                    <h3 className="mt-3 font-bold">
                                        {detailTab} de {selected.code}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Consulta y administra este apartado del
                                        activo seleccionado.
                                    </p>
                                </div>
                            </div>
                        )}
                    </section>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <CalendarDays className="size-4 text-blue-600" />{' '}
                            Próximos eventos
                        </h2>
                        <div className="mt-3 divide-y divide-slate-100 text-[10px]">
                            {[
                                [
                                    '10/09/2026',
                                    'Depreciación del mes',
                                    money(22222),
                                ],
                                ['10/09/2028', 'Fin de garantía', 'En 2 años'],
                                [
                                    '10/09/2028',
                                    'Depreciación completa',
                                    'En 2 años',
                                ],
                            ].map((row) => (
                                <div
                                    key={row[1]}
                                    className="grid grid-cols-[90px_1fr_auto] gap-2 py-2"
                                >
                                    <span>{row[0]}</span>
                                    <span>{row[1]}</span>
                                    <strong>{row[2]}</strong>
                                </div>
                            ))}
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <h2 className="flex items-center gap-2 text-sm font-bold">
                            <Wrench className="size-4 text-blue-600" /> Último
                            mantenimiento
                        </h2>
                        <div className="mt-3 flex justify-between text-[10px]">
                            <span>05/08/2026</span>
                            <span>Mantenimiento preventivo</span>
                            <strong>{money(25000)}</strong>
                        </div>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Nuevo mantenimiento preparado para registro.',
                                )
                            }
                            className="mt-6 w-full rounded-lg border border-blue-500 py-2 text-xs font-semibold text-blue-700"
                        >
                            Registrar mantenimiento
                        </button>
                    </section>
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <FileText className="size-4 text-blue-600" />{' '}
                                Documentos
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todos
                            </button>
                        </div>
                        <div className="mt-3 divide-y divide-slate-100 text-[10px]">
                            {[
                                ['Factura FC-4587.pdf', '245 KB'],
                                ['Garantía.pdf', '1.2 MB'],
                                ['Manual de usuario.pdf', '3.4 MB'],
                            ].map(([name, size]) => (
                                <div
                                    key={name}
                                    className="flex items-center gap-2 py-2"
                                >
                                    <FileText className="size-4 text-red-500" />
                                    <span className="flex-1 text-blue-600">
                                        {name}
                                    </span>
                                    <span>{size}</span>
                                </div>
                            ))}
                        </div>
                    </section>
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <History className="size-4 text-blue-600" />{' '}
                                Historial reciente
                            </h2>
                            <button
                                type="button"
                                className="text-[10px] text-blue-600"
                            >
                                Ver todos
                            </button>
                        </div>
                        <div className="mt-3 divide-y divide-slate-100 text-[10px]">
                            {[
                                [
                                    '15/08/2026',
                                    'Actualización de responsable',
                                    'Ana López',
                                ],
                                [
                                    '05/08/2026',
                                    'Mantenimiento registrado',
                                    'Sistema',
                                ],
                                ['10/09/2025', 'Activo creado', 'Ana López'],
                            ].map((row) => (
                                <div
                                    key={row[1]}
                                    className="grid grid-cols-[80px_1fr_auto] gap-2 py-2"
                                >
                                    <span>{row[0]}</span>
                                    <span>{row[1]}</span>
                                    <span>{row[2]}</span>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Activos fijos</span>
                    <span className="flex items-center gap-1">
                        <ShieldCheck className="size-3 text-emerald-600" />{' '}
                        Depreciación calculada en GTQ
                    </span>
                </footer>
            </div>
        </main>
    );
}
