import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    Eye,
    FileMinus2,
    FileText,
    Link2,
    MoreHorizontal,
    Plus,
    RotateCcw,
    Search,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Status = 'Emitida' | 'Pendiente' | 'Aplicada';

type CreditNote = {
    number: string;
    invoice: string;
    client: string;
    date: string;
    reason: string;
    amount: string;
    status: Status;
};

const notes: CreditNote[] = [
    {
        number: 'NC-2025-00028',
        invoice: 'FAC-2025-001245',
        client: 'Distribuidora del Norte, S.A.',
        date: '28/01/2025',
        reason: 'Devolución de mercadería',
        amount: 'Q 2,500.00',
        status: 'Emitida',
    },
    {
        number: 'NC-2025-00027',
        invoice: 'FAC-2025-001238',
        client: 'Inversiones Rivera, S.A.',
        date: '26/01/2025',
        reason: 'Descuento comercial',
        amount: 'Q 1,150.00',
        status: 'Pendiente',
    },
    {
        number: 'NC-2025-00026',
        invoice: 'FAC-2025-001233',
        client: 'Servicios Globales, S.A.',
        date: '24/01/2025',
        reason: 'Corrección de precio',
        amount: 'Q 3,200.00',
        status: 'Emitida',
    },
    {
        number: 'NC-2025-00025',
        invoice: 'FAC-2025-001221',
        client: 'Comercializadora Paz, S.A.',
        date: '20/01/2025',
        reason: 'Producto defectuoso',
        amount: 'Q 890.00',
        status: 'Aplicada',
    },
    {
        number: 'NC-2025-00024',
        invoice: 'FAC-2025-001208',
        client: 'Grupo Industrial Morales, S.A.',
        date: '18/01/2025',
        reason: 'Anulación parcial',
        amount: 'Q 4,750.00',
        status: 'Emitida',
    },
];

const card =
    'rounded-xl border border-[#dfe5ef] bg-white shadow-[0_18px_50px_-38px_rgba(15,23,42,0.72)]';

const statusTone: Record<Status, string> = {
    Emitida: 'bg-emerald-100 text-emerald-700',
    Pendiente: 'bg-amber-100 text-amber-700',
    Aplicada: 'bg-blue-100 text-blue-700',
};

function Metric({
    icon,
    label,
    value,
    detail,
    tone,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    detail: React.ReactNode;
    tone: string;
}) {
    return (
        <article
            className={`${card} flex min-h-[104px] items-center gap-5 p-5`}
        >
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-full ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-[#253047]">{label}</p>
                <div className="mt-1 flex items-end justify-between gap-3">
                    <strong className="text-2xl font-black tracking-tight text-[#071029]">
                        {value}
                    </strong>
                    <div className="text-right text-xs font-semibold text-[#526079]">
                        {detail}
                    </div>
                </div>
            </div>
        </article>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <label className="min-w-0 flex-1">
            <span className="text-sm font-bold text-[#1c2940]">{label}</span>
            <div className="mt-2 flex h-10 items-center rounded-lg border border-[#cfd8e6] bg-white px-3 text-sm text-[#536077]">
                {children}
            </div>
        </label>
    );
}

function StatusPill({ status }: { status: Status }) {
    return (
        <span
            className={`inline-flex min-w-20 justify-center rounded-full px-3 py-1 text-xs font-extrabold ${statusTone[status]}`}
        >
            {status}
        </span>
    );
}

export default function CreditNotesWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [query, setQuery] = useState('');
    const [selectedNumber, setSelectedNumber] = useState(notes[0].number);
    const selected =
        notes.find((note) => note.number === selectedNumber) ?? notes[0];

    const filtered = useMemo(
        () =>
            notes.filter((note) =>
                `${note.number} ${note.invoice} ${note.client} ${note.reason}`
                    .toLowerCase()
                    .includes(query.toLowerCase()),
            ),
        [query],
    );

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] px-4 py-5 text-[#14213d] sm:px-6">
            <Head title={`Notas de Crédito · ${company.name}`} />

            <div className="mx-auto max-w-[1900px] space-y-5">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium text-[#526079]">
                            Inicio{' '}
                            <span className="px-2 text-[#9aa6b7]">›</span>
                            Notas de Crédito
                        </p>
                        <h1 className="mt-5 text-[2.35rem] leading-none font-black tracking-tight text-[#071029]">
                            Notas de Crédito
                        </h1>
                        <p className="mt-2 text-xl text-[#536077]">
                            Corrección y reversión parcial de ventas
                        </p>
                    </div>
                    <button
                        type="button"
                        className="inline-flex h-12 items-center gap-3 rounded-lg bg-[#730009] px-6 text-base font-extrabold text-white shadow-[0_16px_28px_-22px_#730009] hover:bg-[#5d0007]"
                    >
                        <Plus className="size-5" />
                        Nueva Nota de Crédito
                    </button>
                </header>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={<FileMinus2 className="size-7 text-[#730009]" />}
                        label="Notas emitidas"
                        value="28"
                        tone="bg-red-50"
                        detail={
                            <>
                                <span className="text-emerald-600">↑ 12%</span>
                                <br />
                                vs. mes anterior
                            </>
                        }
                    />
                    <Metric
                        icon={
                            <span className="text-3xl font-black text-emerald-700">
                                $
                            </span>
                        }
                        label="Total acreditado"
                        value="Q 48,750.00"
                        tone="bg-emerald-100"
                        detail={
                            <>
                                <span className="text-emerald-600">↑ 18%</span>
                                <br />
                                vs. mes anterior
                            </>
                        }
                    />
                    <Metric
                        icon={<Clock3 className="size-7 text-amber-600" />}
                        label="Pendientes"
                        value="4"
                        tone="bg-amber-100"
                        detail={
                            <>
                                <span className="text-emerald-600">↓ 33%</span>
                                <br />
                                vs. mes anterior
                            </>
                        }
                    />
                    <Metric
                        icon={<Link2 className="size-7 text-blue-700" />}
                        label="Relacionadas con FEL"
                        value="25"
                        tone="bg-blue-100"
                        detail={<span>89% del total</span>}
                    />
                </section>

                <section className={`${card} p-5`}>
                    <div className="grid gap-4 xl:grid-cols-[1fr_1fr_0.9fr_0.9fr_0.9fr_auto_auto]">
                        <Field label="Cliente">Todos los clientes</Field>
                        <Field label="Documento origen">
                            Todas las facturas
                        </Field>
                        <Field label="Estado">Todos los estados</Field>
                        <Field label="Fecha desde">
                            <CalendarDays className="mr-3 size-4 text-[#536077]" />
                            01/01/2025
                        </Field>
                        <Field label="Fecha hasta">
                            <CalendarDays className="mr-3 size-4 text-[#536077]" />
                            31/01/2025
                        </Field>
                        <button
                            type="button"
                            className="mt-7 inline-flex h-10 min-w-32 items-center justify-center gap-3 rounded-lg bg-[#730009] px-6 text-sm font-extrabold text-white"
                        >
                            <Search className="size-4" />
                            Buscar
                        </button>
                        <button
                            type="button"
                            className="mt-7 inline-flex h-10 min-w-24 items-center justify-center gap-2 rounded-lg text-sm font-semibold text-[#536077]"
                            onClick={() => setQuery('')}
                        >
                            <RotateCcw className="size-4" />
                            Limpiar
                        </button>
                    </div>
                    <label className="mt-4 block max-w-xl">
                        <span className="sr-only">Buscar notas</span>
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Filtrar por cliente, factura, motivo o número..."
                            className="h-10 w-full rounded-lg border border-[#d4deeb] px-3 text-sm outline-none focus:border-[#730009] focus:ring-2 focus:ring-[#730009]/10"
                        />
                    </label>
                </section>

                <section className={`${card} overflow-hidden`}>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1100px] text-left text-sm">
                            <thead className="bg-[#eef3f8] text-xs font-extrabold text-[#27354d]">
                                <tr>
                                    <th className="w-3 px-0 py-3"></th>
                                    <th className="px-5 py-3">No.</th>
                                    <th className="px-5 py-3">
                                        Factura origen
                                    </th>
                                    <th className="px-5 py-3">Cliente</th>
                                    <th className="px-5 py-3">Fecha</th>
                                    <th className="px-5 py-3">Motivo</th>
                                    <th className="px-5 py-3 text-right">
                                        Monto
                                    </th>
                                    <th className="px-5 py-3 text-center">
                                        Estado
                                    </th>
                                    <th className="px-5 py-3 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((note) => (
                                    <tr
                                        key={note.number}
                                        onClick={() =>
                                            setSelectedNumber(note.number)
                                        }
                                        className={`cursor-pointer border-t border-[#dde6f0] ${
                                            selected.number === note.number
                                                ? 'bg-[#fff1f1]'
                                                : 'bg-white hover:bg-[#fafcff]'
                                        }`}
                                    >
                                        <td className="px-0 py-3">
                                            {selected.number ===
                                                note.number && (
                                                <span className="block h-10 w-1 bg-[#730009]" />
                                            )}
                                        </td>
                                        <td className="px-5 py-3 font-semibold">
                                            {note.number}
                                        </td>
                                        <td className="px-5 py-3">
                                            {note.invoice}
                                        </td>
                                        <td className="px-5 py-3">
                                            {note.client}
                                        </td>
                                        <td className="px-5 py-3">
                                            {note.date}
                                        </td>
                                        <td className="px-5 py-3">
                                            {note.reason}
                                        </td>
                                        <td className="px-5 py-3 text-right font-semibold">
                                            {note.amount}
                                        </td>
                                        <td className="px-5 py-3 text-center">
                                            <StatusPill status={note.status} />
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex items-center justify-center gap-5 text-[#536077]">
                                                <Eye className="size-4" />
                                                <MoreHorizontal className="size-5" />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-[#dde6f0] px-5 py-3 text-sm text-[#64748b]">
                        <span>Mostrando 1 a 5 de 28 registros</span>
                        <div className="flex items-center gap-2">
                            <button className="grid size-8 place-items-center rounded-lg border border-[#d6dfeb]">
                                <ChevronLeft className="size-4" />
                            </button>
                            {[1, 2, 3, 4, 5, 6].map((page) => (
                                <button
                                    key={page}
                                    className={`grid size-8 place-items-center rounded-lg border text-sm font-semibold ${
                                        page === 1
                                            ? 'border-[#730009] bg-[#730009] text-white'
                                            : 'border-[#d6dfeb] bg-white text-[#536077]'
                                    }`}
                                >
                                    {page}
                                </button>
                            ))}
                            <button className="grid size-8 place-items-center rounded-lg border border-[#d6dfeb]">
                                <ChevronRight className="size-4" />
                            </button>
                        </div>
                    </footer>
                </section>

                <section className={`${card} overflow-hidden p-5`}>
                    <header className="flex items-start justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <span className="grid size-12 place-items-center rounded-full bg-red-50 text-[#730009]">
                                <FileMinus2 className="size-6" />
                            </span>
                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h2 className="text-xl font-black text-[#071029]">
                                        {selected.number}
                                    </h2>
                                    <StatusPill status={selected.status} />
                                </div>
                                <p className="mt-1 text-sm text-[#64748b]">
                                    Emitida el 28 de enero de 2025 ·{' '}
                                    {selected.reason}
                                </p>
                            </div>
                        </div>
                        <X className="size-5 text-[#14213d]" />
                    </header>

                    <nav className="mt-5 flex justify-end gap-0">
                        {[
                            'Resumen',
                            'Documento original',
                            'Impacto en CxC',
                            'Impuestos',
                            'Observaciones',
                            'Bitácora',
                        ].map((tab, index) => (
                            <button
                                key={tab}
                                className={`min-w-36 border border-[#d6dfeb] px-4 py-2 text-sm font-bold ${
                                    index === 0
                                        ? 'rounded-l-lg bg-[#730009] text-white'
                                        : index === 5
                                          ? 'rounded-r-lg bg-[#f8fafc] text-[#334155]'
                                          : 'bg-[#f8fafc] text-[#334155]'
                                }`}
                            >
                                {tab}
                            </button>
                        ))}
                    </nav>

                    <div className="mt-5 grid gap-6 xl:grid-cols-[1.15fr_0.9fr_1.05fr_1.15fr]">
                        <section className="border-r border-[#dfe5ef] pr-6">
                            <h3 className="mb-4 font-black">
                                Información general
                            </h3>
                            <dl className="grid grid-cols-[170px_1fr] gap-y-2 text-sm">
                                <dt className="text-[#526079]">
                                    No. de Nota de Crédito
                                </dt>
                                <dd className="font-semibold">
                                    {selected.number}
                                </dd>
                                <dt className="text-[#526079]">
                                    Fecha de emisión
                                </dt>
                                <dd>28/01/2025</dd>
                                <dt className="text-[#526079]">Cliente</dt>
                                <dd>
                                    Distribuidora del Norte, S.A.
                                    <br />
                                    <span className="text-[#64748b]">
                                        NIT: 1234567-8
                                    </span>
                                </dd>
                                <dt className="text-[#526079]">Moneda</dt>
                                <dd>GTQ - Quetzales</dd>
                                <dt className="text-[#526079]">Estado</dt>
                                <dd>
                                    <StatusPill status="Emitida" />
                                </dd>
                                <dt className="text-[#526079]">Motivo</dt>
                                <dd>Devolución de mercadería</dd>
                                <dt className="text-[#526079]">Referencia</dt>
                                <dd>DEV-4587</dd>
                            </dl>
                        </section>

                        <section className="border-r border-[#dfe5ef] pr-6">
                            <h3 className="mb-4 font-black">
                                Totales de la nota
                            </h3>
                            <div className="rounded-lg border border-[#dfe5ef] bg-[#f8fafc] p-4 text-sm">
                                <div className="flex justify-between py-2">
                                    <span>Subtotal</span>
                                    <strong>Q 2,232.14</strong>
                                </div>
                                <div className="flex justify-between py-2">
                                    <span>Descuento</span>
                                    <strong>Q 0.00</strong>
                                </div>
                                <div className="flex justify-between py-2">
                                    <span>IVA (12%)</span>
                                    <strong>Q 267.86</strong>
                                </div>
                                <div className="mt-3 flex justify-between border-t border-[#dfe5ef] pt-4 text-lg">
                                    <span className="font-black">
                                        Total acreditado
                                    </span>
                                    <strong className="text-2xl">
                                        Q 2,500.00
                                    </strong>
                                </div>
                            </div>
                        </section>

                        <section className="border-r border-[#dfe5ef] pr-6">
                            <h3 className="mb-4 font-black">
                                Impacto en Cuentas por Cobrar
                            </h3>
                            <div className="rounded-lg border border-[#dfe5ef] bg-[#f8fafc] p-4 text-sm">
                                <div className="flex justify-between py-2">
                                    <span>Saldo original (Factura)</span>
                                    <strong>Q 12,500.00</strong>
                                </div>
                                <div className="flex justify-between py-2">
                                    <span>Nota de Crédito</span>
                                    <strong className="text-red-600">
                                        - Q 2,500.00
                                    </strong>
                                </div>
                                <div className="flex justify-between border-t border-[#dfe5ef] py-3">
                                    <span className="font-black">
                                        Nuevo saldo
                                    </span>
                                    <strong>Q 10,000.00</strong>
                                </div>
                            </div>
                            <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                                Esta nota de crédito reduce el saldo por cobrar
                                del cliente en Q 2,500.00.
                            </div>
                        </section>

                        <section>
                            <h3 className="mb-4 font-black">Observaciones</h3>
                            <div className="rounded-lg border border-[#dfe5ef] bg-[#f8fafc] p-4 text-sm text-[#334155]">
                                Devolución de 5 unidades del producto por daño
                                en empaque. Mercadería recibida en buen estado
                                en bodega el 27/01/2025.
                            </div>
                            <h3 className="mt-5 mb-3 font-black">
                                Archivos relacionados
                            </h3>
                            <div className="flex items-center gap-3 rounded-lg border border-[#dfe5ef] bg-[#f8fafc] p-4 text-sm">
                                <FileText className="size-5 text-[#536077]" />
                                <div className="flex-1">
                                    <strong className="block">
                                        Recepcion_mercaderia.pdf
                                    </strong>
                                    <span className="text-[#64748b]">
                                        320 KB
                                    </span>
                                </div>
                                <Download className="size-5 text-[#536077]" />
                            </div>
                        </section>
                    </div>
                </section>
            </div>
        </main>
    );
}
