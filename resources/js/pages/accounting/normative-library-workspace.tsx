import { Head } from '@inertiajs/react';
import {
    BookOpen,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    ExternalLink,
    Eye,
    FileText,
    Folder,
    Landmark,
    MoreVertical,
    Plus,
    Search,
    Settings,
    ShieldCheck,
    Sparkles,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type Document = {
    code: string;
    name: string;
    category: string;
    version: string;
    effective: string;
    owner: string;
    status: 'Vigente' | 'En revisión' | 'Derogado';
    summary: string;
    tags: string[];
};

const documents: Document[] = [
    {
        code: 'SAT-L-001',
        name: 'Código Tributario',
        category: 'Fiscal',
        version: '5.0',
        effective: '01/01/2022',
        owner: 'SAT',
        status: 'Vigente',
        summary:
            'Establece las normas generales de tributación en Guatemala, los derechos y obligaciones de los contribuyentes, así como los procedimientos de fiscalización, determinación y recaudación de los tributos.',
        tags: [
            'Tributario',
            'SAT',
            'Impuestos',
            'Obligaciones',
            'Fiscalización',
        ],
    },
    {
        code: 'SAT-R-004',
        name: 'Reglamento de la Ley del IVA',
        category: 'Fiscal',
        version: '3.2',
        effective: '01/01/2023',
        owner: 'SAT',
        status: 'Vigente',
        summary:
            'Regula la aplicación del Impuesto al Valor Agregado, la documentación de operaciones y el cumplimiento de obligaciones formales.',
        tags: ['IVA', 'SAT', 'Facturación', 'Crédito fiscal'],
    },
    {
        code: 'NIIF-001',
        name: 'NIIF para PYMES',
        category: 'Contable',
        version: '2.0',
        effective: '01/01/2024',
        owner: 'IASB',
        status: 'Vigente',
        summary:
            'Marco de información financiera aplicable a pequeñas y medianas entidades para la preparación de estados financieros.',
        tags: ['NIIF', 'PYMES', 'Contabilidad', 'Estados financieros'],
    },
    {
        code: 'MINTRAB-001',
        name: 'Código de Trabajo',
        category: 'Laboral',
        version: '4.1',
        effective: '01/01/2022',
        owner: 'Mintrab',
        status: 'Vigente',
        summary:
            'Contiene las disposiciones principales sobre relaciones laborales, contratos, jornadas, salarios y obligaciones patronales.',
        tags: ['Laboral', 'Contratos', 'Salarios', 'Empleados'],
    },
    {
        code: 'PRO-ADM-001',
        name: 'Manual de Cierre Contable',
        category: 'Procedimientos',
        version: '1.3',
        effective: '15/01/2025',
        owner: 'Finanzas',
        status: 'Vigente',
        summary:
            'Describe el flujo interno para revisar, aprobar y cerrar cada período contable de la organización.',
        tags: ['Cierre', 'Contabilidad', 'Procedimiento', 'Auditoría'],
    },
    {
        code: 'POL-001',
        name: 'Política de Gastos Corporativos',
        category: 'Políticas internas',
        version: '2.0',
        effective: '01/02/2025',
        owner: 'Dirección',
        status: 'Vigente',
        summary:
            'Define límites, comprobantes, responsables y autorizaciones para gastos y reembolsos corporativos.',
        tags: ['Gastos', 'Viáticos', 'Política', 'Aprobaciones'],
    },
    {
        code: 'SAT-C-152',
        name: 'Criterio Institucional 152-2024',
        category: 'Fiscal',
        version: '1.0',
        effective: '10/12/2024',
        owner: 'SAT',
        status: 'Vigente',
        summary:
            'Criterio institucional de referencia para la aplicación uniforme de disposiciones tributarias.',
        tags: ['Criterio', 'SAT', 'Tributario'],
    },
    {
        code: 'NIIF-016',
        name: 'Arrendamientos (NIIF 16)',
        category: 'Contable',
        version: '1.1',
        effective: '01/01/2024',
        owner: 'IASB',
        status: 'Vigente',
        summary:
            'Norma para el reconocimiento, medición y revelación de arrendamientos en los estados financieros.',
        tags: ['NIIF 16', 'Arrendamientos', 'Activos', 'Pasivos'],
    },
];

const categories: Array<[LucideIcon, string, string, string]> = [
    [
        Landmark,
        'Fiscal',
        'Leyes, reglamentos y criterios de la SAT',
        '128 documentos',
    ],
    [FileText, 'Contable', 'NIIF, manuales y lineamientos', '76 documentos'],
    [Users, 'Laboral', 'Leyes laborales y seguridad social', '54 documentos'],
    [
        Settings,
        'Procedimientos',
        'Manuales y procesos internos',
        '42 documentos',
    ],
    [
        ShieldCheck,
        'Políticas internas',
        'Lineamientos corporativos',
        '24 documentos',
    ],
];

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const categoryStyle: Record<string, string> = {
    Fiscal: 'bg-rose-100 text-rose-700',
    Contable: 'bg-indigo-100 text-indigo-700',
    Laboral: 'bg-orange-100 text-orange-700',
    Procedimientos: 'bg-purple-100 text-purple-700',
    'Políticas internas': 'bg-violet-100 text-violet-700',
};

function Metric({
    icon: Icon,
    value,
    label,
    detail,
    tone,
}: {
    icon: LucideIcon;
    value: string;
    label: string;
    detail: string;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-center gap-4 p-4`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div>
                <strong className="text-2xl text-slate-950">{value}</strong>
                <p className="text-xs font-semibold">{label}</p>
                <p className="mt-1 text-[10px] text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

export default function NormativeLibraryWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedCode, setSelectedCode] = useState('SAT-L-001');
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('Todas las categorías');
    const [status, setStatus] = useState('Todos los estados');
    const [notice, setNotice] = useState('');
    const selected =
        documents.find((document) => document.code === selectedCode) ??
        documents[0];
    const filtered = useMemo(
        () =>
            documents.filter((document) => {
                const matchesQuery =
                    `${document.code} ${document.name} ${document.category} ${document.owner} ${document.tags.join(' ')}`
                        .toLowerCase()
                        .includes(query.toLowerCase());
                const matchesCategory =
                    category === 'Todas las categorías' ||
                    document.category === category;
                const matchesStatus =
                    status === 'Todos los estados' ||
                    document.status === status;

                return matchesQuery && matchesCategory && matchesStatus;
            }),
        [category, query, status],
    );

    return (
        <main className="min-h-full bg-[#f5f8fc] p-3 text-[#172033] sm:p-5">
            <Head title={`Biblioteca Normativa · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold text-slate-950">
                            Biblioteca Normativa
                        </h1>
                        <p className="mt-1 text-base text-slate-500">
                            Leyes, criterios, manuales y procedimientos
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <span className="hidden rounded-lg border border-slate-200 bg-white px-5 py-3 text-xs text-slate-500 italic lg:block">
                            “Información normativa, decisiones más seguras.”
                        </span>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Formulario para agregar documento preparado.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-[#6f0008] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Plus className="size-4" /> Agregar documento
                        </button>
                    </div>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-rose-100 bg-rose-50 px-4 py-2 text-xs text-[#6f0008]">
                        <span className="flex items-center gap-2">
                            <Check className="size-4" />
                            {notice}
                        </span>
                        <button
                            type="button"
                            aria-label="Cerrar aviso"
                            onClick={() => setNotice('')}
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={FileText}
                        value="324"
                        label="Documentos vigentes"
                        detail="↑ 12% vs. período anterior"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Folder}
                        value="5"
                        label="Categorías"
                        detail="Estructura normativa"
                        tone="bg-violet-50 text-violet-600"
                    />
                    <Metric
                        icon={Clock3}
                        value="18"
                        label="Cambios recientes"
                        detail="Últimos 30 días"
                        tone="bg-orange-50 text-orange-600"
                    />
                    <Metric
                        icon={Sparkles}
                        value="156"
                        label="Consultas IA"
                        detail="↑ 28% vs. período anterior"
                        tone="bg-blue-50 text-blue-600"
                    />
                </section>
                <section>
                    <h2 className="mb-3 text-lg font-bold">Categorías</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                        {categories.map(([Icon, name, description, count]) => (
                            <button
                                key={name}
                                type="button"
                                onClick={() => setCategory(name)}
                                className={`${panel} flex min-h-28 gap-3 p-4 text-left transition hover:border-[#790a12] ${category === name ? 'ring-2 ring-[#790a12]/20' : ''}`}
                            >
                                <Icon className="mt-1 size-6 shrink-0 text-[#6f0008]" />
                                <span className="flex-1">
                                    <span className="flex items-center justify-between">
                                        <strong className="text-sm underline decoration-slate-300">
                                            {name}
                                        </strong>
                                        <ChevronRight className="size-4" />
                                    </span>
                                    <span className="mt-2 block text-xs text-slate-500">
                                        {description}
                                    </span>
                                    <span className="mt-1 block text-[10px] text-slate-500">
                                        {count}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                </section>
                <div className="grid gap-4 2xl:grid-cols-[1fr_390px]">
                    <section className={`${panel} overflow-hidden`}>
                        <h2 className="border-b border-slate-200 p-4 text-lg font-bold">
                            Documentos normativos
                        </h2>
                        <div className="grid gap-2 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_.65fr_.65fr_.65fr]">
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar por código, nombre o contenido..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-[#790a12]"
                                />
                            </label>
                            <button
                                type="button"
                                onClick={() =>
                                    setCategory(
                                        category === 'Fiscal'
                                            ? 'Todas las categorías'
                                            : 'Fiscal',
                                    )
                                }
                                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                {category}
                                <ChevronDown className="size-4" />
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setStatus(
                                        status === 'Vigente'
                                            ? 'Todos los estados'
                                            : 'Vigente',
                                    )
                                }
                                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                {status}
                                <ChevronDown className="size-4" />
                            </button>
                            <button
                                type="button"
                                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs"
                            >
                                Más recientes
                                <ChevronDown className="size-4" />
                            </button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[850px] text-left text-[10px]">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2">Código</th>
                                        <th>Documento</th>
                                        <th>Categoría</th>
                                        <th>Versión</th>
                                        <th>Vigencia</th>
                                        <th>Responsable</th>
                                        <th>Estado</th>
                                        <th className="pr-4 text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((document) => (
                                        <tr
                                            key={document.code}
                                            onClick={() =>
                                                setSelectedCode(document.code)
                                            }
                                            className={`cursor-pointer hover:bg-rose-50/40 ${selected.code === document.code ? 'bg-blue-50' : ''}`}
                                        >
                                            <td className="px-4 py-2 font-medium">
                                                {document.code}
                                            </td>
                                            <td>{document.name}</td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-2 py-1 ${categoryStyle[document.category]}`}
                                                >
                                                    {document.category}
                                                </span>
                                            </td>
                                            <td>{document.version}</td>
                                            <td>{document.effective}</td>
                                            <td>{document.owner}</td>
                                            <td>
                                                <span
                                                    className={
                                                        document.status ===
                                                        'Vigente'
                                                            ? 'text-emerald-700'
                                                            : 'text-amber-700'
                                                    }
                                                >
                                                    ● {document.status}
                                                </span>
                                            </td>
                                            <td className="pr-4">
                                                <div className="flex justify-end gap-3">
                                                    <button
                                                        type="button"
                                                        aria-label={`Ver ${document.name}`}
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setSelectedCode(
                                                                document.code,
                                                            );
                                                        }}
                                                    >
                                                        <Eye className="size-3.5" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        aria-label={`Descargar ${document.name}`}
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setNotice(
                                                                `${document.name} preparado para descarga.`,
                                                            );
                                                        }}
                                                    >
                                                        <Download className="size-3.5" />
                                                    </button>
                                                    <MoreVertical className="size-3.5" />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {filtered.length === 0 && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay documentos que coincidan con los
                                    filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-[10px] text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 324
                                documentos
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                {['1', '2', '3', '4', '5', '…', '41'].map(
                                    (page) => (
                                        <button
                                            key={page}
                                            type="button"
                                            className={`grid size-8 place-items-center rounded ${page === '1' ? 'bg-[#6f0008] text-white' : 'border border-slate-200'}`}
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
                                className="rounded border border-slate-200 px-3 py-2"
                            >
                                8 por página⌄
                            </button>
                        </footer>
                    </section>
                    <aside className={`${panel} overflow-hidden`}>
                        <div className="flex items-center justify-between border-b border-slate-200 p-4">
                            <button
                                type="button"
                                className="flex items-center gap-2 text-xs text-slate-500"
                            >
                                <ChevronLeft className="size-4" /> Volver al
                                listado
                            </button>
                            <MoreVertical className="size-4" />
                        </div>
                        <div className="space-y-5 p-4">
                            <div>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-xl font-bold">
                                            {selected.name}
                                        </h2>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Código: {selected.code}{' '}
                                            <span className="mx-2">|</span>{' '}
                                            Categoría: {selected.category}
                                        </p>
                                    </div>
                                    <span className="rounded-full bg-emerald-100 px-3 py-1 text-[10px] font-semibold text-emerald-700">
                                        {selected.status}
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-xl border border-slate-200 p-3">
                                <div className="flex items-center gap-3">
                                    <span className="grid size-14 place-items-center rounded-lg bg-[#6f0008] text-white">
                                        <FileText className="size-7" />
                                    </span>
                                    <span>
                                        <strong className="block text-sm">
                                            {selected.name}
                                        </strong>
                                        <span className="block text-xs text-slate-500">
                                            {selected.code}_v{selected.version}
                                            .pdf
                                        </span>
                                        <span className="text-[10px] text-slate-500">
                                            2.4 MB
                                        </span>
                                    </span>
                                </div>
                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setNotice(
                                                `${selected.name} abierto.`,
                                            )
                                        }
                                        className="flex items-center justify-center gap-2 rounded-lg bg-[#6f0008] px-3 py-2 text-xs font-semibold text-white"
                                    >
                                        <ExternalLink className="size-4" />{' '}
                                        Abrir documento
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setNotice(
                                                `${selected.name} preparado para descarga.`,
                                            )
                                        }
                                        className="flex items-center justify-center gap-2 rounded-lg border border-[#6f0008] px-3 py-2 text-xs font-semibold text-[#6f0008]"
                                    >
                                        <Download className="size-4" />{' '}
                                        Descargar
                                    </button>
                                </div>
                            </div>
                            <section>
                                <h3 className="text-sm font-bold">Resumen</h3>
                                <p className="mt-2 text-xs leading-5 text-slate-600">
                                    {selected.summary}
                                </p>
                            </section>
                            <section>
                                <h3 className="text-sm font-bold">Etiquetas</h3>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {selected.tags.map((tag) => (
                                        <span
                                            key={tag}
                                            className="rounded-full bg-slate-100 px-3 py-1 text-[10px]"
                                        >
                                            {tag}
                                        </span>
                                    ))}
                                </div>
                            </section>
                            <section className="rounded-xl bg-slate-50 p-4">
                                <div className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-full bg-rose-50 text-[#6f0008]">
                                        <BookOpen className="size-5" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-bold">
                                            Versión vigente
                                        </p>
                                        <strong className="text-xl">
                                            {selected.version}
                                        </strong>
                                    </div>
                                    <div className="ml-auto border-l border-slate-200 pl-4 text-[10px] text-slate-500">
                                        Vigente desde
                                        <br />
                                        <strong>{selected.effective}</strong>
                                    </div>
                                </div>
                            </section>
                            <section>
                                <h3 className="text-sm font-bold">
                                    Últimas actualizaciones
                                </h3>
                                <div className="mt-2 space-y-3 text-[10px]">
                                    {[
                                        [
                                            '12/01/2024',
                                            'Se incorporan disposiciones sobre factura electrónica.',
                                        ],
                                        [
                                            '05/07/2023',
                                            'Se actualizan sanciones y procedimientos administrativos.',
                                        ],
                                        [
                                            '20/03/2023',
                                            'Reformas al capítulo de infracciones.',
                                        ],
                                    ].map(([date, text], index) => (
                                        <div
                                            key={date}
                                            className="grid grid-cols-[10px_65px_1fr] gap-2"
                                        >
                                            <i
                                                className={`mt-1 size-2 rounded-full ${index === 0 ? 'bg-[#6f0008]' : 'bg-slate-400'}`}
                                            />
                                            <span className="text-slate-500">
                                                {date}
                                            </span>
                                            <span>{text}</span>
                                        </div>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    className="mt-3 text-[10px] text-[#6f0008]"
                                >
                                    Ver historial completo →
                                </button>
                            </section>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        `Atlantia AI está lista para responder preguntas sobre ${selected.name}.`,
                                    )
                                }
                                className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#6f0008] to-[#8d1119] px-4 py-4 text-sm font-semibold text-white"
                            >
                                <Sparkles className="size-5" /> Consultar con
                                Atlantia AI
                            </button>
                        </div>
                    </aside>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Biblioteca Normativa</span>
                    <span>Contenido interno de referencia</span>
                </footer>
            </div>
        </main>
    );
}
