import { Head } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Clock3,
    Download,
    Eye,
    FileMinus2,
    FileText,
    Filter,
    Link2,
    Mail,
    MoreVertical,
    Plus,
    Search,
    Send,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type Status = 'Autorizada' | 'Pendiente' | 'Anulada' | 'Borrador';
type CreditNote = {
    id: number;
    number: string;
    invoice: string;
    client: string;
    nit: string;
    date: string;
    reason: string;
    total: string;
    status: Status;
    uuid: string;
};
const notes: CreditNote[] = [
    {
        id: 1,
        number: 'NC-A1-0000187',
        invoice: 'FAC-A1-0001452',
        client: 'Distribuidora del Pacífico, S.A.',
        nit: '5489123-6',
        date: '28/04/2025',
        reason: 'Devolución parcial de mercadería',
        total: 'Q 2,450.00',
        status: 'Autorizada',
        uuid: '9A8B7C6D-5432-1098-ABCD-1234567890EF',
    },
    {
        id: 2,
        number: 'NC-A1-0000186',
        invoice: 'FAC-A1-0001448',
        client: 'Tecnologías Globales, S.A.',
        nit: '1234567-8',
        date: '27/04/2025',
        reason: 'Descuento comercial posterior',
        total: 'Q 1,250.00',
        status: 'Pendiente',
        uuid: '8F7E6D5C-4321-0987-FEDC-BA9876543210',
    },
    {
        id: 3,
        number: 'NC-A1-0000185',
        invoice: 'FAC-A1-0001432',
        client: 'Servicios Empresariales, S.A.',
        nit: '7854621-3',
        date: '25/04/2025',
        reason: 'Corrección en precio facturado',
        total: 'Q 875.00',
        status: 'Autorizada',
        uuid: '7E6D5C4B-3210-9876-EDCB-A8765432109F',
    },
    {
        id: 4,
        number: 'NC-A1-0000184',
        invoice: 'FAC-A1-0001421',
        client: 'Industrias Centroamericanas, S.A.',
        nit: '4682135-9',
        date: '23/04/2025',
        reason: 'Anulación de servicio no prestado',
        total: 'Q 4,800.00',
        status: 'Anulada',
        uuid: '6D5C4B3A-2109-8765-DCBA-7654321098FE',
    },
    {
        id: 5,
        number: 'NC-A1-0000183',
        invoice: 'FAC-A1-0001418',
        client: 'Comercializadora Rivera, S.A.',
        nit: '2347651-2',
        date: '22/04/2025',
        reason: 'Devolución total del pedido',
        total: 'Q 3,950.00',
        status: 'Borrador',
        uuid: '5C4B3A29-1098-7654-CBA9-6543210987ED',
    },
    {
        id: 6,
        number: 'NC-A1-0000182',
        invoice: 'FAC-A1-0001402',
        client: 'Grupo Empresarial, S.A.',
        nit: '5467891-2',
        date: '19/04/2025',
        reason: 'Ajuste por diferencia de cantidad',
        total: 'Q 620.00',
        status: 'Autorizada',
        uuid: '4B3A2918-0987-6543-BA98-5432109876DC',
    },
];
const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
const styles: Record<Status, string> = {
    Autorizada: 'bg-emerald-100 text-emerald-700',
    Pendiente: 'bg-amber-100 text-amber-700',
    Anulada: 'bg-red-100 text-red-700',
    Borrador: 'bg-slate-200 text-slate-700',
};

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
}) {
    return (
        <article
            className={`${panel} flex min-h-28 min-w-0 items-center gap-4 overflow-hidden p-5`}
        >
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-7" />
            </span>
            <div className="min-w-0">
                <p className="text-sm text-slate-600">{label}</p>
                <strong className="text-3xl text-slate-950">{value}</strong>
                <p className="mt-1 text-[10px] text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

export default function CreditNotesWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [selectedId, setSelectedId] = useState(1);
    const [status, setStatus] = useState('Todos');
    const [query, setQuery] = useState('');
    const [tab, setTab] = useState('Información');
    const [notice, setNotice] = useState('');
    const [page, setPage] = useState(1);
    const selected = notes.find((note) => note.id === selectedId) ?? notes[0];
    const filtered = useMemo(
        () =>
            notes.filter(
                (note) =>
                    (status === 'Todos' || note.status === status) &&
                    `${note.number} ${note.invoice} ${note.client} ${note.reason}`
                        .toLowerCase()
                        .includes(query.toLowerCase()),
            ),
        [query, status],
    );
    const action = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3200);
    };

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Notas de Crédito · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Ventas{' '}
                            <span className="px-2">›</span>{' '}
                            <b>Notas de Crédito</b>
                        </p>
                        <h1 className="text-3xl font-bold">Notas de Crédito</h1>
                        <p className="mt-1 text-base text-slate-500">
                            Ajustes y anulaciones sobre documentos emitidos
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            action(
                                'Formulario de nueva nota de crédito preparado.',
                            )
                        }
                        className="flex items-center gap-2 rounded-lg bg-[#74000a] px-5 py-3 text-sm font-semibold text-white"
                    >
                        <Plus className="size-5" />
                        Nueva nota de crédito
                    </button>
                </header>
                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs text-emerald-800">
                        <span className="flex items-center gap-2">
                            <Check className="size-4" />
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
                    <Metric
                        icon={FileMinus2}
                        label="Notas emitidas"
                        value="187"
                        detail="12 durante abril 2025"
                        tone="bg-blue-50 text-blue-600"
                    />
                    <Metric
                        icon={CheckCircle2}
                        label="Autorizadas"
                        value="164"
                        detail="87.7% del total"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Clock3}
                        label="Pendientes"
                        value="8"
                        detail="Requieren seguimiento"
                        tone="bg-amber-50 text-amber-600"
                    />
                    <Metric
                        icon={CircleAlert}
                        label="Total acreditado"
                        value="Q 48,950"
                        detail="En el período seleccionado"
                        tone="bg-rose-50 text-[#74000a]"
                    />
                </section>
                <section
                    className={`${panel} flex flex-wrap items-end gap-3 p-4`}
                >
                    <label className="relative min-w-64 flex-[2]">
                        <Search className="absolute bottom-3 left-3 size-4 text-slate-400" />
                        <span className="text-xs font-semibold">Buscar</span>
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Número, factura, cliente o motivo..."
                            className="mt-1 w-full rounded-lg border py-2.5 pr-3 pl-9 text-xs outline-none focus:border-[#74000a]"
                        />
                    </label>
                    <label className="min-w-44 flex-1 text-xs font-semibold">
                        Estado
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 w-full rounded-lg border bg-white px-3 py-2.5 font-normal"
                        >
                            <option>Todos</option>
                            {Object.keys(styles).map((item) => (
                                <option key={item}>{item}</option>
                            ))}
                        </select>
                    </label>
                    <button
                        type="button"
                        className="flex min-w-48 flex-1 items-center justify-between rounded-lg border px-3 py-2.5 text-xs"
                    >
                        <span>01/04/2025 – 30/04/2025</span>
                        <ChevronDown className="size-4" />
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            action(
                                `${filtered.length} notas coinciden con los filtros.`,
                            )
                        }
                        className="flex items-center gap-2 rounded-lg bg-[#74000a] px-5 py-2.5 text-xs font-semibold text-white"
                    >
                        <Filter className="size-4" />
                        Aplicar filtros
                    </button>
                </section>
                <div className="grid gap-4 2xl:grid-cols-[1fr_430px]">
                    <section className={`${panel} overflow-hidden`}>
                        <header className="flex items-center justify-between border-b px-4 py-3">
                            <h2 className="text-lg font-bold">
                                Notas de crédito emitidas
                            </h2>
                            <button
                                type="button"
                                onClick={() =>
                                    action('Listado preparado para exportar.')
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs"
                            >
                                <Download className="size-4" />
                                Exportar
                            </button>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[940px] text-left text-xs">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3">
                                            Nota de crédito
                                        </th>
                                        <th>Factura relacionada</th>
                                        <th>Cliente</th>
                                        <th>Fecha</th>
                                        <th>Motivo</th>
                                        <th>Total</th>
                                        <th>Estado</th>
                                        <th className="pr-4 text-center">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {filtered.map((note) => (
                                        <tr
                                            key={note.id}
                                            onClick={() =>
                                                setSelectedId(note.id)
                                            }
                                            className={`cursor-pointer hover:bg-rose-50/50 ${selected.id === note.id ? 'bg-rose-50' : ''}`}
                                        >
                                            <td className="px-4 py-3 font-semibold">
                                                {note.number}
                                            </td>
                                            <td className="text-blue-700">
                                                {note.invoice}
                                            </td>
                                            <td>{note.client}</td>
                                            <td>{note.date}</td>
                                            <td className="max-w-56 truncate">
                                                {note.reason}
                                            </td>
                                            <td className="font-semibold">
                                                {note.total}
                                            </td>
                                            <td>
                                                <span
                                                    className={`rounded-full px-3 py-1 font-semibold ${styles[note.status]}`}
                                                >
                                                    {note.status}
                                                </span>
                                            </td>
                                            <td className="pr-4">
                                                <div className="flex justify-center gap-3">
                                                    <Eye className="size-4" />
                                                    <Download className="size-4" />
                                                    <MoreVertical className="size-4" />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!filtered.length && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay notas de crédito para estos filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex items-center justify-between border-t px-4 py-3 text-xs text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de 187 notas
                            </span>
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setPage(Math.max(1, page - 1))
                                    }
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                {[1, 2, 3, 4].map((item) => (
                                    <button
                                        key={item}
                                        type="button"
                                        onClick={() => setPage(item)}
                                        className={`grid size-8 place-items-center rounded ${page === item ? 'bg-[#74000a] text-white' : 'border'}`}
                                    >
                                        {item}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => setPage(page + 1)}
                                    className="grid size-8 place-items-center rounded border"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        </footer>
                    </section>
                    <aside className={`${panel} overflow-hidden`}>
                        <header className="flex items-start gap-3 border-b p-4">
                            <span className="grid size-11 place-items-center rounded-lg bg-rose-50 text-[#74000a]">
                                <FileMinus2 className="size-6" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="font-bold">
                                        {selected.number}
                                    </h2>
                                    <span
                                        className={`rounded-full px-2 py-1 text-[10px] font-semibold ${styles[selected.status]}`}
                                    >
                                        {selected.status}
                                    </span>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    Emitida el {selected.date}
                                </p>
                            </div>
                            <X className="size-4" />
                        </header>
                        <nav className="flex border-b">
                            {['Información', 'Documento', 'Bitácora'].map(
                                (item) => (
                                    <button
                                        key={item}
                                        type="button"
                                        onClick={() => setTab(item)}
                                        className={`flex-1 border-b-2 py-3 text-xs font-semibold ${tab === item ? 'border-[#74000a] text-[#74000a]' : 'border-transparent text-slate-500'}`}
                                    >
                                        {item}
                                    </button>
                                ),
                            )}
                        </nav>
                        {tab === 'Información' ? (
                            <div className="space-y-3 p-4">
                                <section className="rounded-lg border p-3">
                                    <h3 className="mb-3 text-xs font-bold">
                                        Información general
                                    </h3>
                                    <dl className="grid grid-cols-[135px_1fr] gap-y-2 text-[10px]">
                                        <dt className="text-slate-500">
                                            Cliente
                                        </dt>
                                        <dd>{selected.client}</dd>
                                        <dt className="text-slate-500">NIT</dt>
                                        <dd>{selected.nit}</dd>
                                        <dt className="text-slate-500">
                                            Fecha de emisión
                                        </dt>
                                        <dd>{selected.date}</dd>
                                        <dt className="text-slate-500">
                                            Motivo
                                        </dt>
                                        <dd>{selected.reason}</dd>
                                        <dt className="text-slate-500">UUID</dt>
                                        <dd className="truncate">
                                            {selected.uuid}
                                        </dd>
                                    </dl>
                                </section>
                                <section className="rounded-lg border p-3">
                                    <h3 className="mb-3 text-xs font-bold">
                                        Documento relacionado
                                    </h3>
                                    <div className="flex items-center gap-3 rounded-lg bg-blue-50 p-3">
                                        <Link2 className="size-5 text-blue-600" />
                                        <div className="flex-1">
                                            <strong className="block text-xs text-blue-700">
                                                {selected.invoice}
                                            </strong>
                                            <small className="text-[10px] text-slate-500">
                                                Factura electrónica certificada
                                            </small>
                                        </div>
                                        <Eye className="size-4 text-blue-600" />
                                    </div>
                                </section>
                                <section className="rounded-lg border p-3">
                                    <h3 className="mb-2 text-xs font-bold">
                                        Detalle del ajuste
                                    </h3>
                                    <table className="w-full text-[10px]">
                                        <thead className="bg-slate-100">
                                            <tr>
                                                <th className="p-2 text-left">
                                                    Descripción
                                                </th>
                                                <th>Base</th>
                                                <th>IVA</th>
                                                <th className="text-right">
                                                    Total
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td className="p-2">
                                                    Ajuste a documento original
                                                </td>
                                                <td className="text-right">
                                                    Q 2,187.50
                                                </td>
                                                <td className="text-right">
                                                    Q 262.50
                                                </td>
                                                <td className="text-right">
                                                    Q 2,450.00
                                                </td>
                                            </tr>
                                            <tr className="border-t font-bold">
                                                <td className="p-2">
                                                    Total acreditado
                                                </td>
                                                <td />
                                                <td />
                                                <td className="text-right text-[#74000a]">
                                                    {selected.total}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </section>
                            </div>
                        ) : (
                            <div className="grid min-h-96 place-items-center p-8 text-center">
                                <div>
                                    <FileText className="mx-auto size-12 text-[#74000a]" />
                                    <h3 className="mt-3 font-bold">{tab}</h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Contenido asociado a la nota
                                        seleccionada.
                                    </p>
                                </div>
                            </div>
                        )}
                        <footer className="grid grid-cols-3 gap-2 border-t p-3">
                            <button
                                type="button"
                                onClick={() =>
                                    action('PDF preparado para descargar.')
                                }
                                className="flex items-center justify-center gap-1 rounded-lg border py-2 text-[10px]"
                            >
                                <Download className="size-4" />
                                Descargar
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        `Correo preparado para ${selected.client}.`,
                                    )
                                }
                                className="flex items-center justify-center gap-1 rounded-lg border py-2 text-[10px]"
                            >
                                <Mail className="size-4" />
                                Enviar
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    action(
                                        'Solicitud de autorización procesada.',
                                    )
                                }
                                className="flex items-center justify-center gap-1 rounded-lg bg-[#74000a] py-2 text-[10px] text-white"
                            >
                                <Send className="size-4" />
                                Autorizar
                            </button>
                        </footer>
                    </aside>
                </div>
            </div>
        </main>
    );
}
