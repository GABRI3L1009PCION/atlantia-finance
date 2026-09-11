import { Head } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Copy,
    Download,
    ExternalLink,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    Filter,
    Folder,
    Grid2X2,
    Link2,
    List,
    MessageCircle,
    Plus,
    Search,
    Upload,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type DocStatus = 'Vinculado' | 'Revisión' | 'Válido';
type DigitalDocument = {
    id: number;
    name: string;
    category: string;
    company: string;
    related: string;
    date: string;
    user: string;
    status: DocStatus;
    type: 'pdf' | 'image' | 'sheet';
    size: string;
    tags: string[];
};

const docs: DigitalDocument[] = [
    {
        id: 1,
        name: 'FAC-3287-2024.pdf',
        category: 'Compras',
        company: 'Grupo Atlantia, S.A.',
        related: 'Factura #FAC-3287',
        date: '14/04/2024 10:24',
        user: 'Juan Martínez',
        status: 'Vinculado',
        type: 'pdf',
        size: '324 KB',
        tags: ['factura', 'proveedor', 'software', '2024'],
    },
    {
        id: 2,
        name: 'Recibo_Banrural.jpg',
        category: 'Bancos',
        company: 'Inversiones Atlantia, S.A.',
        related: 'Movimiento #784392',
        date: '14/04/2024 09:18',
        user: 'Ana López',
        status: 'Revisión',
        type: 'image',
        size: '1.8 MB',
        tags: ['banco', 'recibo', 'conciliación'],
    },
    {
        id: 3,
        name: 'Contrato_Servicios.pdf',
        category: 'Contratos',
        company: 'Grupo Atlantia, S.A.',
        related: '—',
        date: '13/04/2024 16:42',
        user: 'Carlos Pérez',
        status: 'Vinculado',
        type: 'pdf',
        size: '2.1 MB',
        tags: ['contrato', 'servicios', 'legal'],
    },
    {
        id: 4,
        name: 'Planilla_Nomina_Marzo.xlsx',
        category: 'Nómina',
        company: 'Atlantia Recursos, S.A.',
        related: 'Nómina 03/2024',
        date: '13/04/2024 11:05',
        user: 'María González',
        status: 'Vinculado',
        type: 'sheet',
        size: '856 KB',
        tags: ['nómina', 'marzo', 'empleados'],
    },
    {
        id: 5,
        name: 'Declaracion_IVA_Q1.pdf',
        category: 'Impuestos',
        company: 'Grupo Atlantia, S.A.',
        related: 'Formulario IVA',
        date: '12/04/2024 14:33',
        user: 'Juan Martínez',
        status: 'Vinculado',
        type: 'pdf',
        size: '624 KB',
        tags: ['IVA', 'SAT', 'impuestos'],
    },
    {
        id: 6,
        name: 'Estado_Cuenta_Abril.pdf',
        category: 'Bancos',
        company: 'Inversiones Atlantia, S.A.',
        related: 'Conciliación bancaria',
        date: '12/04/2024 09:21',
        user: 'Ana López',
        status: 'Revisión',
        type: 'pdf',
        size: '948 KB',
        tags: ['banco', 'estado de cuenta', 'abril'],
    },
    {
        id: 7,
        name: 'Cotizacion_EQ-1023.pdf',
        category: 'Ventas',
        company: 'Atlantia Tecnología, S.A.',
        related: 'Cotización #EQ-1023',
        date: '11/04/2024 17:50',
        user: 'Luis Herrera',
        status: 'Vinculado',
        type: 'pdf',
        size: '441 KB',
        tags: ['cotización', 'ventas', 'cliente'],
    },
    {
        id: 8,
        name: 'Poliza_Seguro.pdf',
        category: 'Activos',
        company: 'Grupo Atlantia, S.A.',
        related: 'Activo #ACT-558',
        date: '10/04/2024 12:11',
        user: 'Ricardo Méndez',
        status: 'Válido',
        type: 'pdf',
        size: '1.3 MB',
        tags: ['seguro', 'activo', 'póliza'],
    },
    {
        id: 9,
        name: 'Factura_3281.pdf',
        category: 'Compras',
        company: 'Grupo Atlantia, S.A.',
        related: 'Factura #FAC-3281',
        date: '10/04/2024 09:03',
        user: 'Ana López',
        status: 'Vinculado',
        type: 'pdf',
        size: '318 KB',
        tags: ['factura', 'compras', 'proveedor'],
    },
    {
        id: 10,
        name: 'Escritura_Constitucion.pdf',
        category: 'Contratos',
        company: 'Inversiones Atlantia, S.A.',
        related: '—',
        date: '09/04/2024 15:37',
        user: 'Carlos Pérez',
        status: 'Válido',
        type: 'pdf',
        size: '4.2 MB',
        tags: ['legal', 'constitución', 'sociedad'],
    },
];

const folders = [
    ['Compras', '320 archivos', 'bg-blue-50 text-blue-600'],
    ['Ventas', '286 archivos', 'bg-emerald-50 text-emerald-600'],
    ['Bancos', '125 archivos', 'bg-violet-50 text-violet-600'],
    ['Nómina', '98 archivos', 'bg-amber-50 text-amber-600'],
    ['Impuestos', '210 archivos', 'bg-rose-50 text-rose-600'],
    ['Contratos', '84 archivos', 'bg-yellow-50 text-yellow-700'],
    ['Activos', '125 archivos', 'bg-slate-100 text-slate-600'],
];
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const statusStyle: Record<DocStatus, string> = {
    Vinculado: 'bg-emerald-100 text-emerald-700',
    Revisión: 'bg-amber-100 text-amber-700',
    Válido: 'bg-green-100 text-green-700',
};
const categoryStyle: Record<string, string> = {
    Compras: 'bg-blue-100 text-blue-700',
    Ventas: 'bg-emerald-100 text-emerald-700',
    Bancos: 'bg-violet-100 text-violet-700',
    Nómina: 'bg-amber-100 text-amber-700',
    Impuestos: 'bg-rose-100 text-rose-700',
    Contratos: 'bg-yellow-100 text-yellow-700',
    Activos: 'bg-slate-100 text-slate-700',
};
function DocumentTypeIcon({
    type,
    className,
}: {
    type: DigitalDocument['type'];
    className?: string;
}) {
    if (type === 'image') {
        return <FileImage className={className} />;
    }

    if (type === 'sheet') {
        return <FileSpreadsheet className={className} />;
    }

    return <FileText className={className} />;
}
const iconFor = (type: DigitalDocument['type']): LucideIcon =>
    type === 'image'
        ? FileImage
        : type === 'sheet'
          ? FileSpreadsheet
          : FileText;

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
    alert = false,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
    alert?: boolean;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-center gap-4 p-4`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div className="flex-1">
                <p className="text-sm text-slate-500">{label}</p>
                <strong className="text-2xl text-slate-950">{value}</strong>
                <p
                    className={`text-right text-[10px] font-semibold ${alert ? 'text-red-500' : 'text-emerald-600'}`}
                >
                    {detail}
                </p>
            </div>
        </article>
    );
}

function DocumentPreview({ document }: { document: DigitalDocument }) {
    return (
        <div className="mx-auto aspect-[.72] w-full max-w-[330px] border border-slate-300 bg-white p-5 text-[7px] shadow-md">
            <div className="flex justify-between">
                <div>
                    <div className="flex items-center gap-2 text-[#6f0008]">
                        <span className="text-3xl font-black">A</span>
                        <strong className="tracking-[.3em]">ATLANTIA</strong>
                    </div>
                    <p className="mt-3 font-bold">ATLANTIA SYSTEMS, S.A.</p>
                    <p>NIT: 97655988</p>
                    <p>Colonia La Repegua, Santo Tomás de Castilla</p>
                </div>
                <div className="text-right">
                    <strong className="text-xs">FACTURA</strong>
                    <p className="mt-3">Serie: A</p>
                    <p>No. 3287</p>
                    <p>Fecha: 14/04/2024</p>
                </div>
            </div>
            <div className="mt-5 border-y border-slate-300 py-3">
                <p>
                    <strong>Cliente:</strong> Grupo Atlantia, S.A.
                </p>
                <p>
                    <strong>NIT:</strong> 9876543-2
                </p>
                <p>
                    <strong>Dirección:</strong> Zona 10, Ciudad de Guatemala
                </p>
            </div>
            <table className="mt-4 w-full border-collapse">
                <thead>
                    <tr className="bg-slate-100">
                        <th className="border p-1 text-left">Descripción</th>
                        <th className="border p-1">Cantidad</th>
                        <th className="border p-1">Precio Unit.</th>
                        <th className="border p-1">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td className="border p-1">
                            Licencia Software Contable
                        </td>
                        <td className="border p-1 text-center">1</td>
                        <td className="border p-1 text-right">Q2,500.00</td>
                        <td className="border p-1 text-right">Q2,500.00</td>
                    </tr>
                    <tr>
                        <td className="border p-1">Soporte Técnico Anual</td>
                        <td className="border p-1 text-center">1</td>
                        <td className="border p-1 text-right">Q1,200.00</td>
                        <td className="border p-1 text-right">Q1,200.00</td>
                    </tr>
                </tbody>
            </table>
            <div className="mt-3 ml-auto grid w-32 grid-cols-2 gap-1 text-right">
                <span>Subtotal</span>
                <strong>Q3,700.00</strong>
                <span>IVA (12%)</span>
                <strong>Q444.00</strong>
                <span className="mt-1 font-bold">Total</span>
                <strong className="mt-1">Q4,144.00</strong>
            </div>
            <p className="mt-8">Gracias por su preferencia.</p>
            <p className="mt-4 truncate text-slate-400">
                Vista previa: {document.name}
            </p>
        </div>
    );
}

export default function DocumentsWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [query, setQuery] = useState('');
    const [folder, setFolder] = useState('Todos');
    const [view, setView] = useState<'list' | 'grid'>('list');
    const [detailTab, setDetailTab] = useState('Vista previa');
    const [notice, setNotice] = useState('');
    const [comment, setComment] = useState('');
    const selected = docs.find((doc) => doc.id === selectedId) ?? docs[0];
    const filtered = useMemo(
        () =>
            docs.filter(
                (doc) =>
                    (folder === 'Todos' || doc.category === folder) &&
                    `${doc.name} ${doc.category} ${doc.company} ${doc.related} ${doc.tags.join(' ')}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [folder, query],
    );

    return (
        <main className="min-h-full bg-[#f5f8fc] p-3 text-[#172033] sm:p-5">
            <Head title={`Documentos · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">Documentos</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Expediente digital y archivos relacionados.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs"
                        >
                            <Filter className="size-4" /> Filtros
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'Selector de archivos preparado para subir documentos.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg bg-[#6f0008] px-5 py-2.5 text-xs font-semibold text-white"
                        >
                            <Upload className="size-4" /> Subir documento
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
                        icon={File}
                        label="Archivos totales"
                        value="1,248"
                        detail="↑ +12% vs. mes anterior"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={Clock3}
                        label="Pendientes de revisión"
                        value="28"
                        detail="↑ +40% vs. mes anterior"
                        tone="bg-rose-50 text-rose-600"
                        alert
                    />
                    <Metric
                        icon={Upload}
                        label="Subidos hoy"
                        value="12"
                        detail="↑ +100% vs. ayer"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={Link2}
                        label="Vinculados a operaciones"
                        value="892"
                        detail="↑ +8% vs. mes anterior"
                        tone="bg-slate-100 text-slate-600"
                    />
                </section>
                <nav className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
                    {folders.map(([name, count, tone]) => (
                        <button
                            key={name}
                            type="button"
                            onClick={() =>
                                setFolder(folder === name ? 'Todos' : name)
                            }
                            className={`${panel} flex items-center gap-3 px-4 py-3 text-left ${folder === name ? 'ring-2 ring-[#790a12]/20' : ''}`}
                        >
                            <span
                                className={`grid size-9 place-items-center rounded-lg ${tone}`}
                            >
                                <Folder className="size-5" />
                            </span>
                            <span>
                                <strong className="block text-xs">
                                    {name}
                                </strong>
                                <span className="text-[10px] text-slate-500">
                                    {count}
                                </span>
                            </span>
                        </button>
                    ))}
                </nav>
                <div className="grid gap-4 2xl:grid-cols-[1fr_400px]">
                    <section className={`${panel} overflow-hidden`}>
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                            <h2 className="text-lg font-bold">
                                {folder === 'Todos'
                                    ? 'Todos los documentos (1,248)'
                                    : `${folder} (${filtered.length})`}
                            </h2>
                            <div className="flex gap-2">
                                <label className="relative">
                                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                    <input
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        placeholder="Buscar documentos..."
                                        className="w-56 rounded-lg border border-slate-200 py-2 pr-3 pl-9 text-xs outline-none focus:border-[#790a12]"
                                    />
                                </label>
                                <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 text-[10px]"
                                >
                                    Más recientes{' '}
                                    <ChevronDown className="size-3" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setView('list')}
                                    className={`grid size-9 place-items-center rounded border border-slate-200 ${view === 'list' ? 'bg-slate-100' : ''}`}
                                >
                                    <List className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setView('grid')}
                                    className={`grid size-9 place-items-center rounded border border-slate-200 ${view === 'grid' ? 'bg-slate-100' : ''}`}
                                >
                                    <Grid2X2 className="size-4" />
                                </button>
                            </div>
                        </div>
                        {view === 'list' ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[980px] text-left text-[10px]">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-2">□</th>
                                            <th>Archivo</th>
                                            <th>Categoría</th>
                                            <th>Empresa</th>
                                            <th>Documento relacionado</th>
                                            <th>Fecha ↓</th>
                                            <th>Usuario</th>
                                            <th>Estado</th>
                                            <th className="pr-4 text-right">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {filtered.map((doc) => {
                                            const DocIcon = iconFor(doc.type);

                                            return (
                                                <tr
                                                    key={doc.id}
                                                    onClick={() =>
                                                        setSelectedId(doc.id)
                                                    }
                                                    className={`cursor-pointer hover:bg-rose-50/30 ${selected.id === doc.id ? 'bg-blue-50' : ''}`}
                                                >
                                                    <td className="px-4 py-2">
                                                        {selected.id === doc.id
                                                            ? '☑'
                                                            : '□'}
                                                    </td>
                                                    <td className="font-medium">
                                                        <DocIcon
                                                            className={`mr-2 inline size-4 ${doc.type === 'sheet' ? 'text-emerald-600' : doc.type === 'image' ? 'text-blue-600' : 'text-red-600'}`}
                                                        />
                                                        {doc.name}
                                                    </td>
                                                    <td>
                                                        <span
                                                            className={`rounded px-2 py-1 ${categoryStyle[doc.category]}`}
                                                        >
                                                            {doc.category}
                                                        </span>
                                                    </td>
                                                    <td>{doc.company}</td>
                                                    <td>{doc.related}</td>
                                                    <td>{doc.date}</td>
                                                    <td>{doc.user}</td>
                                                    <td>
                                                        <span
                                                            className={`rounded-full px-2 py-1 ${statusStyle[doc.status]}`}
                                                        >
                                                            {doc.status}
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
                                {filtered.length === 0 && (
                                    <div className="p-10 text-center text-sm text-slate-400">
                                        No hay documentos que coincidan con los
                                        filtros.
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {filtered.map((doc) => {
                                    const DocIcon = iconFor(doc.type);

                                    return (
                                        <button
                                            key={doc.id}
                                            type="button"
                                            onClick={() =>
                                                setSelectedId(doc.id)
                                            }
                                            className={`rounded-xl border p-4 text-left hover:bg-blue-50 ${selected.id === doc.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200'}`}
                                        >
                                            <DocIcon className="size-8 text-red-600" />
                                            <strong className="mt-3 block truncate text-xs">
                                                {doc.name}
                                            </strong>
                                            <span className="mt-1 block text-[10px] text-slate-500">
                                                {doc.category} · {doc.size}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-[10px] text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 1,248
                                documentos
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                {['1', '2', '3', '4', '5', '…', '125'].map(
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
                                10 por página⌄
                            </button>
                        </footer>
                    </section>
                    <aside className={`${panel} overflow-hidden`}>
                        <div className="flex items-start gap-3 border-b border-slate-200 p-4">
                            <DocumentTypeIcon
                                type={selected.type}
                                className="size-8 shrink-0 text-red-600"
                            />
                            <div className="min-w-0 flex-1">
                                <h2 className="truncate text-lg font-bold">
                                    {selected.name}
                                </h2>
                                <p className="text-[10px] text-slate-500">
                                    {selected.category} · Subido el{' '}
                                    {selected.date}
                                </p>
                            </div>
                            <span
                                className={`rounded-full px-2 py-1 text-[10px] ${statusStyle[selected.status]}`}
                            >
                                {selected.status}
                            </span>
                            <button type="button" aria-label="Cerrar detalle">
                                <X className="size-4" />
                            </button>
                        </div>
                        <nav className="flex border-b border-slate-200">
                            {['Vista previa', 'Información', 'Historial'].map(
                                (tab) => (
                                    <button
                                        key={tab}
                                        type="button"
                                        onClick={() => setDetailTab(tab)}
                                        className={`flex-1 border-b-2 py-3 text-xs font-semibold ${detailTab === tab ? 'border-[#6f0008] text-[#6f0008]' : 'border-transparent text-slate-500'}`}
                                    >
                                        {tab}
                                    </button>
                                ),
                            )}
                        </nav>
                        {detailTab === 'Vista previa' ? (
                            <div>
                                <div className="flex items-center justify-between bg-slate-100 px-4 py-2 text-xs">
                                    <span>1 / 1</span>
                                    <div className="flex items-center gap-3">
                                        <ZoomOut className="size-4" />
                                        <span>100%</span>
                                        <ZoomIn className="size-4" />
                                    </div>
                                    <div className="flex gap-3">
                                        <Download className="size-4" />
                                        <ExternalLink className="size-4" />
                                    </div>
                                </div>
                                <div className="bg-slate-100 p-3">
                                    <DocumentPreview document={selected} />
                                </div>
                            </div>
                        ) : (
                            <div className="grid min-h-80 place-items-center p-6 text-center">
                                <div>
                                    <DocumentTypeIcon
                                        type={selected.type}
                                        className="mx-auto size-10 text-[#6f0008]"
                                    />
                                    <h3 className="mt-3 font-bold">
                                        {detailTab}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Consulta la información del archivo
                                        seleccionado.
                                    </p>
                                </div>
                            </div>
                        )}
                        <div className="space-y-4 p-4">
                            <section>
                                <h3 className="text-sm font-bold">Metadatos</h3>
                                <dl className="mt-2 grid grid-cols-[110px_1fr] gap-1 text-[10px]">
                                    <dt className="text-slate-500">
                                        Tipo de archivo
                                    </dt>
                                    <dd>
                                        {selected.type.toUpperCase()}{' '}
                                        (Documento)
                                    </dd>
                                    <dt className="text-slate-500">Tamaño</dt>
                                    <dd>{selected.size}</dd>
                                    <dt className="text-slate-500">
                                        Fecha de subida
                                    </dt>
                                    <dd>{selected.date}</dd>
                                    <dt className="text-slate-500">
                                        Subido por
                                    </dt>
                                    <dd>{selected.user}</dd>
                                    <dt className="text-slate-500">Versión</dt>
                                    <dd>1.0</dd>
                                    <dt className="text-slate-500">
                                        Hash (SHA-256)
                                    </dt>
                                    <dd className="flex items-center gap-2 truncate">
                                        a3f9e2c7d4b1e8f0a9c6d3e2f7b1{' '}
                                        <Copy className="size-3 shrink-0" />
                                    </dd>
                                </dl>
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
                                    <button
                                        type="button"
                                        className="grid size-6 place-items-center rounded border border-slate-200"
                                    >
                                        <Plus className="size-3" />
                                    </button>
                                </div>
                            </section>
                            <section>
                                <h3 className="flex items-center gap-2 text-sm font-bold">
                                    <MessageCircle className="size-4" />{' '}
                                    Comentarios
                                </h3>
                                <div className="mt-2 flex gap-2">
                                    <input
                                        value={comment}
                                        onChange={(event) =>
                                            setComment(event.target.value)
                                        }
                                        placeholder="Agregar un comentario..."
                                        className="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-xs outline-none focus:border-[#790a12]"
                                    />
                                    <button
                                        type="button"
                                        disabled={!comment.trim()}
                                        onClick={() => {
                                            setNotice('Comentario publicado.');
                                            setComment('');
                                        }}
                                        className="rounded-lg bg-[#6f0008] px-4 text-xs font-semibold text-white disabled:bg-slate-300"
                                    >
                                        Publicar
                                    </button>
                                </div>
                            </section>
                        </div>
                    </aside>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Expediente digital</span>
                    <span>Archivos protegidos y trazables</span>
                </footer>
            </div>
        </main>
    );
}
