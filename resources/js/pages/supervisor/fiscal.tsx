import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    ObservationDialog,
    Table,
    post,
    toneBadge,
    useSupervisorHref,
} from '@/components/supervisor/kit';
import type {
    SupervisorContext,
    SupervisorPermissions,
} from '@/components/supervisor/kit';

type Obligation = {
    key: string;
    company_id: number;
    company_name: string;
    type: string;
    name: string;
    period: string;
    due_on: string;
    days_left: number;
    status: string;
    status_label: string;
    tone: string;
    responsible: string | null;
    year: number;
    month: number;
};
type Fel = {
    simulated: boolean;
    summary: {
        type: string;
        label: string;
        certified: number;
        pending: number;
        failed: number;
        cancelled: number;
    }[];
    rows: {
        id: number;
        type: string;
        number: string;
        issued_on: string;
        status: string;
        environment: string;
        company: string;
        destination: string;
    }[];
};
type Calendar = {
    month: string;
    events: {
        date: string;
        type: string;
        title: string;
        subtitle?: string | null;
        responsible?: string | null;
        destination?: string | null;
    }[];
};

const views: Record<string, [string, string]> = {
    obligations: [
        'Obligaciones fiscales',
        'IVA e ISR de tus empresas: próximos vencimientos y vencidas.',
    ],
    declarations: [
        'Declaraciones',
        'Aprueba las declaraciones revisadas o solicita correcciones al responsable.',
    ],
    fel: ['FEL', 'Documentos tributarios electrónicos por empresa.'],
    calendar: [
        'Calendario fiscal',
        'Vencimientos fiscales, tareas y cierres del mes.',
    ],
};
const felTone: Record<string, string> = {
    certified: 'green',
    pending: 'amber',
    processing: 'amber',
    draft: 'slate',
    failed: 'red',
    cancelled: 'violet',
};
const felLabel: Record<string, string> = {
    certified: 'Certificado',
    pending: 'Pendiente',
    processing: 'En proceso',
    draft: 'Borrador',
    failed: 'Rechazado',
    cancelled: 'Anulado',
};

export default function SupervisorFiscal({
    view,
    data,
    permissions,
    notice,
}: {
    context: SupervisorContext;
    view: string;
    data: unknown;
    permissions: SupervisorPermissions;
    notice: string | null;
}) {
    const href = useSupervisorHref();
    const [title, subtitle] = views[view];
    const [correction, setCorrection] = useState<Obligation | null>(null);

    const obligationTable = (rows: Obligation[], withActions: boolean) => (
        <Card title={`${rows.length} obligación(es)`}>
            <Table
                headers={[
                    'Empresa',
                    'Obligación',
                    'Período',
                    'Fecha límite',
                    'Días',
                    'Responsable',
                    'Estado',
                    ...(withActions ? ['Acciones'] : []),
                ]}
                empty={rows.length === 0 && 'Sin obligaciones en el rango.'}
            >
                {rows.map((row) => (
                    <tr key={row.key}>
                        <td className="px-2 py-2 font-bold">
                            {row.company_name}
                        </td>
                        <td className="px-2 py-2">{row.name}</td>
                        <td className="px-2 py-2">{row.period}</td>
                        <td className="px-2 py-2">{formatDate(row.due_on)}</td>
                        <td
                            className={`px-2 py-2 ${row.days_left < 0 && row.status !== 'completed' ? 'font-bold text-red-700' : ''}`}
                        >
                            {row.status === 'completed'
                                ? '—'
                                : row.days_left < 0
                                  ? `${Math.abs(row.days_left)} vencida`
                                  : row.days_left}
                        </td>
                        <td className="px-2 py-2">
                            {row.responsible ?? 'Sin responsable'}
                        </td>
                        <td className="px-2 py-2">
                            <span className={toneBadge(row.tone)}>
                                {row.status_label}
                            </span>
                        </td>
                        {withActions && (
                            <td className="px-2 py-2">
                                {row.status !== 'completed' && (
                                    <div className="flex flex-wrap gap-1">
                                        {row.type === 'iva' &&
                                            permissions.canCloseVatBooks && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        window.confirm(
                                                            `¿Aprobar ${row.name} de ${row.period}? El libro de IVA del mes quedará cerrado.`,
                                                        ) &&
                                                        post(
                                                            `/supervisor/declarations/${row.company_id}/${row.year}/${row.month}/approve`,
                                                        )
                                                    }
                                                >
                                                    Aprobar
                                                </button>
                                            )}
                                        {permissions.canApproveFiscal && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    setCorrection(row)
                                                }
                                            >
                                                Solicitar corrección
                                            </button>
                                        )}
                                    </div>
                                )}
                            </td>
                        )}
                    </tr>
                ))}
            </Table>
        </Card>
    );

    const calendar = data as Calendar;
    const shiftMonth = (delta: number) => {
        const [year, month] = calendar.month.split('-').map(Number);
        const next = new Date(year, month - 1 + delta, 1);
        router.get(
            href('/supervisor/fiscal/calendar'),
            {
                month: `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={title} />
            <Shell>
                <Notice message={notice} />
                <PageHeader title={title} subtitle={subtitle} />
                <nav className="flex flex-wrap gap-1.5">
                    {Object.entries(views).map(([code, [label]]) => (
                        <Link
                            key={code}
                            href={href(`/supervisor/fiscal/${code}`)}
                            className={`${secondaryButton} ${code === view ? 'border-[#640000] text-[#640000]' : ''}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
                {view === 'obligations' &&
                    obligationTable(data as Obligation[], false)}
                {view === 'declarations' &&
                    obligationTable(data as Obligation[], true)}
                {view === 'fel' && (
                    <>
                        {(data as Fel).simulated && (
                            <p className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                <AlertTriangle className="size-4" /> Hay
                                documentos FEL de prueba (proveedor simulado)
                                sin validez ante la SAT. Configura la emisión
                                real en Configuración de facturación.
                            </p>
                        )}
                        <section className="grid gap-3 md:grid-cols-5">
                            {(data as Fel).summary.map((row) => (
                                <div
                                    key={row.type}
                                    className="rounded-xl border border-[#e7e1dd] bg-white p-3 text-sm"
                                >
                                    <p className="font-bold">{row.label}</p>
                                    <p className="text-2xl font-black">
                                        {row.certified}
                                    </p>
                                    <p className="text-xs text-[#526b92]">
                                        Pendientes {row.pending} · Rechazados{' '}
                                        {row.failed} · Anulados {row.cancelled}
                                    </p>
                                </div>
                            ))}
                        </section>
                        <Card title="Documentos recientes">
                            <Table
                                headers={[
                                    'Empresa',
                                    'Tipo',
                                    'Número',
                                    'Emisión',
                                    'Ambiente',
                                    'Estado',
                                ]}
                                empty={
                                    (data as Fel).rows.length === 0 &&
                                    'Sin documentos FEL.'
                                }
                            >
                                {(data as Fel).rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-2 py-2">
                                            {row.company}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.type}
                                        </td>
                                        <td className="px-2 py-2">
                                            <a
                                                href={row.destination}
                                                className="font-bold hover:text-[#640000]"
                                            >
                                                {row.number}
                                            </a>
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(row.issued_on)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.environment}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    felTone[row.status] ??
                                                        'slate',
                                                )}
                                            >
                                                {felLabel[row.status] ??
                                                    row.status}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    </>
                )}
                {view === 'calendar' && (
                    <Card
                        title={new Date(
                            `${calendar.month}-01T12:00:00`,
                        ).toLocaleDateString('es-GT', {
                            month: 'long',
                            year: 'numeric',
                        })}
                        action={
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    aria-label="Mes anterior"
                                    className={secondaryButton}
                                    onClick={() => shiftMonth(-1)}
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Mes siguiente"
                                    className={secondaryButton}
                                    onClick={() => shiftMonth(1)}
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        }
                    >
                        <Table
                            headers={[
                                'Fecha',
                                'Evento',
                                'Detalle',
                                'Responsable',
                            ]}
                            empty={
                                calendar.events.length === 0 &&
                                'Sin eventos este mes.'
                            }
                        >
                            {[...calendar.events]
                                .sort((a, b) => a.date.localeCompare(b.date))
                                .map((event, index) => (
                                    <tr key={`${event.date}-${index}`}>
                                        <td className="px-2 py-2 whitespace-nowrap">
                                            {formatDate(event.date)}
                                        </td>
                                        <td className="px-2 py-2 font-bold">
                                            {event.destination ? (
                                                <a
                                                    href={event.destination}
                                                    className="hover:text-[#640000]"
                                                >
                                                    {event.title}
                                                </a>
                                            ) : (
                                                event.title
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {event.subtitle ?? '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {event.responsible ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                        </Table>
                    </Card>
                )}
            </Shell>
            <ObservationDialog
                open={correction !== null}
                title={`Corrección: ${correction?.name ?? ''} · ${correction?.company_name ?? ''}`}
                onClose={() => setCorrection(null)}
                onSubmit={(message) =>
                    correction &&
                    post(
                        `/supervisor/declarations/${correction.company_id}/correction`,
                        {
                            obligation: `${correction.name} ${correction.period}`,
                            message,
                        },
                        () => setCorrection(null),
                    )
                }
            />
        </>
    );
}
