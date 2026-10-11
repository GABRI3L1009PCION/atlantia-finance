import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, textarea } from '@/components/auditor/kit';
import type {
    Approval,
    ClientDocument,
    ClientRequest,
    Conversation,
} from '@/components/client-portal/kit';
import {
    Card,
    Table,
    approvalTone,
    documentTone,
    formatDate,
    requestTone,
    toneBadge,
} from '@/components/client-portal/kit';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    primaryButton,
} from '@/components/office-admin/kit';

type Option = { id: number; label: string };
type OfficeReport = {
    id: number;
    title: string;
    report_type: string;
    period: string;
    version: number;
    status: string;
    status_label: string;
    prepared_by: string | null;
    approved_by: string | null;
    published_at: string | null;
    revoke_reason: string | null;
    download: string;
};
type Props = {
    companies: { id: number; name: string }[];
    companyId: number | null;
    tab: string;
    contacts?: { id: number; name: string; role: string; role_label: string }[];
    requests?: (ClientRequest & {
        internal_note: string | null;
        requested_from: string;
    })[];
    documents?: ClientDocument[];
    approvals?: (Approval & { internal_note: string | null })[];
    reports?: OfficeReport[];
    conversations?: Conversation[];
    changes?: {
        id: number;
        field: string;
        current: string | null;
        requested: string;
        reason: string | null;
        status: string;
        by: string;
        at: string;
    }[];
    sources?: Record<string, Option[]>;
    clientUsers: { id: number; name: string; email: string }[];
    options: {
        types: Record<string, string>;
        approval_types: Record<string, string>;
        report_types: Record<string, string>;
        generated: string[];
        contact_roles: Record<string, string>;
        categories: Record<string, string>;
    };
    can: Record<string, boolean | number>;
    notice: string | null;
};

const tabs: Record<string, string> = {
    requests: 'Solicitudes',
    documents: 'Documentos',
    approvals: 'Autorizaciones',
    reports: 'Reportes publicados',
    messages: 'Mensajes',
    company: 'Datos y contactos',
};

const sourceLabels: Record<string, string> = {
    fiscal_obligations: 'Obligación fiscal',
    payroll_periods: 'Nómina',
    payment_instructions: 'Pago de Tesorería',
};

const reportTone: Record<string, string> = {
    draft: 'slate',
    in_review: 'amber',
    approved: 'blue',
    published: 'green',
    revoked: 'red',
};

function SourcePicker({
    sources,
    value,
    onChange,
}: {
    sources: Record<string, Option[]>;
    value: string;
    onChange: (type: string, id: string) => void;
}) {
    return (
        <select
            aria-label="Origen"
            className={input}
            value={value}
            onChange={(event) => {
                const [type, id] = event.target.value.split(':');
                onChange(type ?? '', id ?? '');
            }}
        >
            <option value="">Sin proceso vinculado</option>
            {Object.entries(sources).map(([type, options]) =>
                options.length > 0 ? (
                    <optgroup key={type} label={sourceLabels[type] ?? type}>
                        {options.map((option) => (
                            <option
                                key={option.id}
                                value={`${type}:${option.id}`}
                            >
                                {option.label}
                            </option>
                        ))}
                    </optgroup>
                ) : null,
            )}
        </select>
    );
}

export default function OfficeClientPortal(props: Props) {
    const { companies, companyId, tab, can, options, notice } = props;
    const go = (params: Record<string, string | number>) =>
        router.get(
            '/work/client-portal',
            { company_id: companyId ?? '', tab, ...params },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Portal del cliente" />
            <Shell>
                <PageHeader
                    title="Portal del cliente"
                    subtitle="Lo que la oficina pide, recibe, publica y conversa con cada empresa cliente"
                    actions={
                        <select
                            aria-label="Empresa"
                            className={`${input} min-w-64`}
                            value={companyId ?? ''}
                            onChange={(event) =>
                                go({ company_id: event.target.value })
                            }
                        >
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    }
                />
                <Notice message={notice} />
                {companyId === null ? (
                    <Card title="Sin empresas">
                        <p className="py-6 text-center text-sm text-slate-500">
                            No tienes empresas asignadas.
                        </p>
                    </Card>
                ) : (
                    <>
                        <nav className="flex flex-wrap gap-1">
                            {Object.entries(tabs).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => go({ tab: key })}
                                    className={`rounded-full px-3 py-1.5 text-xs font-bold ${tab === key ? 'bg-[#640000] text-white' : 'bg-white text-[#172033] ring-1 ring-slate-200'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </nav>
                        {tab === 'requests' && <RequestsTab {...props} />}
                        {tab === 'documents' && <DocumentsTab {...props} />}
                        {tab === 'approvals' && <ApprovalsTab {...props} />}
                        {tab === 'reports' && <ReportsTab {...props} />}
                        {tab === 'messages' && <MessagesTab {...props} />}
                        {tab === 'company' && (
                            <CompanyTab
                                {...props}
                                canContacts={Boolean(can.contacts)}
                                roles={options.contact_roles}
                            />
                        )}
                    </>
                )}
            </Shell>
        </>
    );
}

function RequestsTab({
    companyId,
    requests = [],
    contacts = [],
    sources = {},
    options,
    can,
}: Props) {
    const form = useForm({
        company_id: companyId,
        type: 'document',
        title: '',
        description: '',
        document_type: 'invoices',
        period_label: '',
        requested_from_user_id: '',
        due_date: '',
        source_type: '',
        source_id: '',
        internal_note: '',
    });

    return (
        <>
            {can.requests && (
                <Card title="Nueva solicitud al cliente">
                    <form
                        className="grid gap-2 md:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                ...data,
                                requested_from_user_id:
                                    data.requested_from_user_id || null,
                                source_type: data.source_type || null,
                                source_id: data.source_id || null,
                                document_type:
                                    data.type === 'document'
                                        ? data.document_type
                                        : null,
                            }));
                            form.post('/work/client-portal/requests', {
                                preserveScroll: true,
                                onSuccess: () =>
                                    form.reset(
                                        'title',
                                        'description',
                                        'internal_note',
                                    ),
                            });
                        }}
                    >
                        <select
                            aria-label="Tipo"
                            className={input}
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                        >
                            {Object.entries(options.types).map(
                                ([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                        <input
                            className={input}
                            placeholder="Qué necesitas"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                        {form.data.type === 'document' ? (
                            <select
                                aria-label="Categoría"
                                className={input}
                                value={form.data.document_type}
                                onChange={(event) =>
                                    form.setData(
                                        'document_type',
                                        event.target.value,
                                    )
                                }
                            >
                                {Object.entries(options.categories).map(
                                    ([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        ) : (
                            <span />
                        )}
                        <select
                            aria-label="Contacto"
                            className={input}
                            value={form.data.requested_from_user_id}
                            onChange={(event) =>
                                form.setData(
                                    'requested_from_user_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Todos los contactos</option>
                            {contacts.map((contact) => (
                                <option key={contact.id} value={contact.id}>
                                    {contact.name} · {contact.role_label}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            aria-label="Fecha límite"
                            className={input}
                            value={form.data.due_date}
                            onChange={(event) =>
                                form.setData('due_date', event.target.value)
                            }
                        />
                        <input
                            className={input}
                            placeholder="Período (opcional)"
                            value={form.data.period_label}
                            onChange={(event) =>
                                form.setData('period_label', event.target.value)
                            }
                        />
                        <SourcePicker
                            sources={sources}
                            value={
                                form.data.source_type
                                    ? `${form.data.source_type}:${form.data.source_id}`
                                    : ''
                            }
                            onChange={(type, id) =>
                                form.setData({
                                    ...form.data,
                                    source_type: type,
                                    source_id: id,
                                })
                            }
                        />
                        <textarea
                            className={`${textarea} md:col-span-2`}
                            placeholder="Descripción visible para el cliente"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                        <textarea
                            className={`${textarea} md:col-span-2`}
                            placeholder="Nota interna (el cliente nunca la ve)"
                            value={form.data.internal_note}
                            onChange={(event) =>
                                form.setData(
                                    'internal_note',
                                    event.target.value,
                                )
                            }
                        />
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Enviar al cliente
                        </button>
                    </form>
                    {contacts.length === 0 && (
                        <p className="mt-2 text-xs text-amber-700">
                            Esta empresa aún no tiene contactos con acceso al
                            portal.
                        </p>
                    )}
                </Card>
            )}
            <Card title={`${requests.length} solicitud(es)`}>
                <Table
                    headers={[
                        'Código',
                        'Solicitud',
                        'Para',
                        'Vence',
                        'Estado',
                        'Respuesta',
                        'Acciones',
                    ]}
                    empty={requests.length === 0 && 'Sin solicitudes.'}
                >
                    {requests.map((request) => (
                        <tr key={request.id}>
                            <td className="px-2 py-2 font-bold">
                                {request.code}
                            </td>
                            <td className="px-2 py-2">
                                <span className="block font-bold">
                                    {request.title}
                                </span>
                                <span className="block text-[#526b92]">
                                    {request.type_label}
                                    {request.source_label &&
                                        ` · ${request.source_label}`}
                                </span>
                                {request.internal_note && (
                                    <span className="block text-violet-700">
                                        Interna: {request.internal_note}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {request.requested_from}
                            </td>
                            <td
                                className={`px-2 py-2 ${request.overdue ? 'font-bold text-red-700' : ''}`}
                            >
                                {formatDate(request.due_date)}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        requestTone[request.status] ?? 'slate',
                                    )}
                                >
                                    {request.status_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {request.response ?? '—'}
                                {request.documents > 0 &&
                                    ` · ${request.documents} doc.`}
                            </td>
                            <td className="px-2 py-2">
                                {can.requests && (
                                    <div className="flex flex-wrap gap-1">
                                        {['submitted', 'under_review'].includes(
                                            request.status,
                                        ) && (
                                            <>
                                                <ActionButton
                                                    url={`/work/client-portal/requests/${request.id}/accept`}
                                                    label="Aceptar"
                                                    tone="primary"
                                                />
                                                <ActionButton
                                                    url={`/work/client-portal/requests/${request.id}/more_information`}
                                                    label="Pedir más"
                                                    ask="¿Qué falta? (el cliente lo verá)"
                                                />
                                            </>
                                        )}
                                        {[
                                            'pending',
                                            'in_progress',
                                            'more_information_required',
                                            'submitted',
                                            'under_review',
                                        ].includes(request.status) && (
                                            <ActionButton
                                                url={`/work/client-portal/requests/${request.id}/cancel`}
                                                label="Cancelar"
                                                ask="Motivo de la cancelación"
                                            />
                                        )}
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </>
    );
}

function DocumentsTab({ documents = [], can }: Props) {
    return (
        <Card title={`${documents.length} documento(s) del cliente`}>
            <Table
                headers={[
                    'Documento',
                    'Categoría',
                    'Subido',
                    'Estado',
                    'Versión',
                    'Acciones',
                ]}
                empty={
                    documents.length === 0 &&
                    'El cliente no ha enviado documentos.'
                }
            >
                {documents.map((document) => (
                    <tr key={document.id}>
                        <td className="px-2 py-2">
                            <span className="block font-bold">
                                {document.title}
                            </span>
                            <span className="block text-[#526b92]">
                                {document.filename}
                                {document.period && ` · ${document.period}`}
                            </span>
                            {document.review_note && (
                                <span className="block text-amber-700">
                                    {document.review_note}
                                </span>
                            )}
                        </td>
                        <td className="px-2 py-2">{document.category_label}</td>
                        <td className="px-2 py-2">
                            {formatDate(document.uploaded_at, true)}
                            <span className="block text-[#526b92]">
                                {document.uploaded_by}
                            </span>
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    documentTone[document.status] ?? 'slate',
                                )}
                            >
                                {document.status_label}
                            </span>
                        </td>
                        <td className="px-2 py-2">
                            {document.versions.map((version) => (
                                <a
                                    key={version.version}
                                    href={version.download}
                                    className="mr-1 font-bold text-[#640000]"
                                    title={version.reason ?? version.file_name}
                                >
                                    v{version.version}
                                </a>
                            ))}
                        </td>
                        <td className="px-2 py-2">
                            {can.documents &&
                                !['processed', 'rejected', 'archived'].includes(
                                    document.status,
                                ) && (
                                    <div className="flex flex-wrap gap-1">
                                        {document.status === 'received' && (
                                            <ActionButton
                                                url={`/work/client-portal/documents/${document.id}/review`}
                                                label="En revisión"
                                            />
                                        )}
                                        <ActionButton
                                            url={`/work/client-portal/documents/${document.id}/accept`}
                                            label="Aceptar"
                                            tone="primary"
                                        />
                                        {document.status === 'accepted' && (
                                            <ActionButton
                                                url={`/work/client-portal/documents/${document.id}/process`}
                                                label="Procesado"
                                            />
                                        )}
                                        <ActionButton
                                            url={`/work/client-portal/documents/${document.id}/more_information`}
                                            label="Pedir más"
                                            ask="¿Qué debe corregir el cliente?"
                                        />
                                        <ActionButton
                                            url={`/work/client-portal/documents/${document.id}/reject`}
                                            label="Rechazar"
                                            ask="Motivo (el cliente lo verá)"
                                        />
                                    </div>
                                )}
                        </td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

function ApprovalsTab({
    companyId,
    approvals = [],
    contacts = [],
    sources = {},
    options,
    can,
}: Props) {
    const form = useForm({
        company_id: companyId,
        type: 'payroll_confirmation',
        title: '',
        description: '',
        requested_from_user_id: '',
        due_date: '',
        source_type: '',
        source_id: '',
        internal_note: '',
    });

    return (
        <>
            {can.approvals && (
                <Card title="Solicitar autorización al cliente">
                    <form
                        className="grid gap-2 md:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                ...data,
                                requested_from_user_id:
                                    data.requested_from_user_id || null,
                                source_type: data.source_type || null,
                                source_id: data.source_id || null,
                            }));
                            form.post('/work/client-portal/approvals', {
                                preserveScroll: true,
                                onSuccess: () =>
                                    form.reset(
                                        'title',
                                        'description',
                                        'internal_note',
                                    ),
                            });
                        }}
                    >
                        <select
                            aria-label="Tipo"
                            className={input}
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                        >
                            {Object.entries(options.approval_types).map(
                                ([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                        <input
                            className={input}
                            placeholder="Qué debe confirmar"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                        <select
                            aria-label="Contacto"
                            className={input}
                            value={form.data.requested_from_user_id}
                            onChange={(event) =>
                                form.setData(
                                    'requested_from_user_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">
                                Cualquier contacto autorizado
                            </option>
                            {contacts.map((contact) => (
                                <option key={contact.id} value={contact.id}>
                                    {contact.name} · {contact.role_label}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            aria-label="Fecha límite"
                            className={input}
                            value={form.data.due_date}
                            onChange={(event) =>
                                form.setData('due_date', event.target.value)
                            }
                        />
                        <SourcePicker
                            sources={sources}
                            value={
                                form.data.source_type
                                    ? `${form.data.source_type}:${form.data.source_id}`
                                    : ''
                            }
                            onChange={(type, id) =>
                                form.setData({
                                    ...form.data,
                                    source_type: type,
                                    source_id: id,
                                })
                            }
                        />
                        <span />
                        <textarea
                            className={`${textarea} md:col-span-2`}
                            placeholder="Detalle visible para el cliente"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                        <textarea
                            className={textarea}
                            placeholder="Nota interna"
                            value={form.data.internal_note}
                            onChange={(event) =>
                                form.setData(
                                    'internal_note',
                                    event.target.value,
                                )
                            }
                        />
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Solicitar
                        </button>
                    </form>
                    <p className="mt-2 text-xs text-[#526b92]">
                        La respuesta del cliente queda registrada; nunca ejecuta
                        el pago, la nómina ni la presentación.
                    </p>
                </Card>
            )}
            <Card title={`${approvals.length} autorización(es)`}>
                <Table
                    headers={[
                        'Código',
                        'Autorización',
                        'Vence',
                        'Estado',
                        'Respuesta',
                        '',
                    ]}
                    empty={approvals.length === 0 && 'Sin autorizaciones.'}
                >
                    {approvals.map((approval) => (
                        <tr key={approval.id}>
                            <td className="px-2 py-2 font-bold">
                                {approval.code}
                            </td>
                            <td className="px-2 py-2">
                                <span className="block font-bold">
                                    {approval.title}
                                </span>
                                <span className="block text-[#526b92]">
                                    {approval.type_label}
                                    {approval.source_label &&
                                        ` · ${approval.source_label}`}
                                </span>
                                {approval.internal_note && (
                                    <span className="block text-violet-700">
                                        Interna: {approval.internal_note}
                                    </span>
                                )}
                            </td>
                            <td
                                className={`px-2 py-2 ${approval.overdue ? 'font-bold text-red-700' : ''}`}
                            >
                                {formatDate(approval.due_date)}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        approvalTone[approval.status] ??
                                            'slate',
                                    )}
                                >
                                    {approval.status_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {approval.response ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                {can.approvals &&
                                    [
                                        'pending',
                                        'viewed',
                                        'more_information_required',
                                    ].includes(approval.status) && (
                                        <ActionButton
                                            url={`/work/client-portal/approvals/${approval.id}/cancel`}
                                            label="Cancelar"
                                            ask="Motivo de la cancelación"
                                        />
                                    )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </>
    );
}

function ReportsTab({ companyId, reports = [], options, can }: Props) {
    const form = useForm<{
        company_id: number | null;
        report_type: string;
        period_start: string;
        period_end: string;
        file: File | null;
    }>({
        company_id: companyId,
        report_type: 'income_statement',
        period_start: '',
        period_end: '',
        file: null,
    });
    const generated = options.generated.includes(form.data.report_type);
    const userId = Number(can.user_id);

    return (
        <>
            {can.publish && (
                <Card title="Preparar reporte para el cliente">
                    <form
                        className="grid gap-2 md:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/work/client-portal/reports', {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => form.reset('file'),
                            });
                        }}
                    >
                        <select
                            aria-label="Reporte"
                            className={input}
                            value={form.data.report_type}
                            onChange={(event) =>
                                form.setData('report_type', event.target.value)
                            }
                        >
                            {Object.entries(options.report_types).map(
                                ([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                        <input
                            type="date"
                            aria-label="Desde"
                            className={input}
                            value={form.data.period_start}
                            onChange={(event) =>
                                form.setData('period_start', event.target.value)
                            }
                            required
                        />
                        <input
                            type="date"
                            aria-label="Hasta"
                            className={input}
                            value={form.data.period_end}
                            onChange={(event) =>
                                form.setData('period_end', event.target.value)
                            }
                            required
                        />
                        <input
                            type="file"
                            accept="application/pdf"
                            aria-label="PDF"
                            className={`${input} pt-1.5`}
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            required={!generated}
                        />
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Preparar borrador
                        </button>
                    </form>
                    <p className="mt-2 text-xs text-[#526b92]">
                        {generated
                            ? 'Se genera desde la contabilidad registrada y queda congelado como versión.'
                            : 'Adjunta el PDF del reporte.'}{' '}
                        Otra persona debe aprobarlo antes de publicarlo; el
                        cliente nunca ve borradores.
                    </p>
                </Card>
            )}
            <Card title={`${reports.length} reporte(s)`}>
                <Table
                    headers={[
                        'Reporte',
                        'Período',
                        'Versión',
                        'Estado',
                        'Preparó / aprobó',
                        'Acciones',
                    ]}
                    empty={reports.length === 0 && 'Sin reportes.'}
                >
                    {reports.map((report) => (
                        <tr key={report.id}>
                            <td className="px-2 py-2 font-bold">
                                <a
                                    href={report.download}
                                    className="hover:text-[#640000]"
                                >
                                    {report.title}
                                </a>
                            </td>
                            <td className="px-2 py-2">{report.period}</td>
                            <td className="px-2 py-2">v{report.version}</td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        reportTone[report.status] ?? 'slate',
                                    )}
                                >
                                    {report.status_label}
                                </span>
                                {report.revoke_reason && (
                                    <span className="block text-red-700">
                                        {report.revoke_reason}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {report.prepared_by ?? '—'} /{' '}
                                {report.approved_by ?? '—'}
                            </td>
                            <td className="px-2 py-2">
                                {can.publish && (
                                    <div className="flex flex-wrap gap-1">
                                        {report.status === 'draft' && (
                                            <ActionButton
                                                url={`/work/client-portal/reports/${report.id}/submit`}
                                                label="Enviar a revisión"
                                            />
                                        )}
                                        {report.status === 'in_review' && (
                                            <ActionButton
                                                url={`/work/client-portal/reports/${report.id}/approve`}
                                                label="Aprobar"
                                                tone="primary"
                                            />
                                        )}
                                        {report.status === 'approved' && (
                                            <ActionButton
                                                url={`/work/client-portal/reports/${report.id}/publish`}
                                                label="Publicar"
                                                tone="primary"
                                            />
                                        )}
                                        {report.status === 'published' && (
                                            <ActionButton
                                                url={`/work/client-portal/reports/${report.id}/revoke`}
                                                label="Revocar"
                                                ask="Motivo de la revocación"
                                            />
                                        )}
                                    </div>
                                )}
                                {report.status === 'in_review' &&
                                    userId > 0 &&
                                    report.prepared_by && (
                                        <span className="block text-[10px] text-[#526b92]">
                                            Aprueba alguien distinto de quien
                                            preparó.
                                        </span>
                                    )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </>
    );
}

function MessagesTab({ companyId, conversations = [], can }: Props) {
    const params = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );
    const open = Number(params.get('conversation')) || null;
    const current = conversations.find(
        (conversation) => conversation.id === open,
    );
    const form = useForm({
        company_id: companyId,
        conversation_id: open,
        subject: '',
        body: '',
        internal: false,
    });

    return (
        <div className="grid gap-3 lg:grid-cols-3">
            <Card title="Conversaciones">
                <ul className="grid gap-1 text-sm">
                    <li>
                        <Link
                            href={`/work/client-portal?company_id=${companyId}&tab=messages`}
                            className={`block rounded-lg px-3 py-2 font-bold ${open === null ? 'bg-red-50 text-[#640000]' : ''}`}
                        >
                            + Nueva conversación
                        </Link>
                    </li>
                    {conversations.map((conversation) => (
                        <li key={conversation.id}>
                            <Link
                                href={`/work/client-portal?company_id=${companyId}&tab=messages&conversation=${conversation.id}`}
                                className={`block rounded-lg px-3 py-2 ${conversation.id === open ? 'bg-red-50' : 'hover:bg-slate-50'}`}
                            >
                                <span className="flex justify-between">
                                    <span className="font-bold">
                                        {conversation.subject}
                                    </span>
                                    {conversation.unread > 0 && (
                                        <span className="rounded-full bg-[#640000] px-1.5 text-xs text-white">
                                            {conversation.unread}
                                        </span>
                                    )}
                                </span>
                                <span className="block truncate text-xs text-[#526b92]">
                                    {conversation.preview}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
            <Card
                title={current ? current.subject : 'Nueva conversación'}
                className="lg:col-span-2"
            >
                {current && (
                    <ul className="mb-3 grid max-h-[28rem] gap-2 overflow-y-auto">
                        {current.messages.map((message) => (
                            <li
                                key={message.id}
                                className={`max-w-[85%] rounded-xl p-3 text-sm ${message.internal ? 'ml-auto border border-dashed border-violet-300 bg-violet-50' : message.side === 'office' ? 'ml-auto bg-red-50' : 'bg-slate-100'}`}
                            >
                                <p className="text-xs font-bold text-[#526b92]">
                                    {message.author} ·{' '}
                                    {message.side === 'client'
                                        ? 'Cliente'
                                        : 'Oficina'}
                                    {message.internal && ' · Nota interna'} ·{' '}
                                    {formatDate(message.at, true)}
                                </p>
                                <p className="whitespace-pre-line">
                                    {message.body}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
                {can.messages ? (
                    <form
                        className="grid gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/work/client-portal/messages', {
                                preserveScroll: true,
                                onSuccess: () => form.reset('body'),
                            });
                        }}
                    >
                        {!current && (
                            <input
                                className={input}
                                placeholder="Asunto"
                                value={form.data.subject}
                                onChange={(event) =>
                                    form.setData('subject', event.target.value)
                                }
                            />
                        )}
                        <textarea
                            className={textarea}
                            placeholder="Mensaje"
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                            required
                        />
                        <div className="flex items-center justify-between gap-2">
                            {current ? (
                                <label className="flex items-center gap-2 text-xs font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={form.data.internal}
                                        onChange={(event) =>
                                            form.setData(
                                                'internal',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Nota interna (el cliente no la ve)
                                </label>
                            ) : (
                                <span />
                            )}
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Enviar
                            </button>
                        </div>
                    </form>
                ) : (
                    <p className="text-xs text-[#526b92]">Solo lectura.</p>
                )}
            </Card>
        </div>
    );
}

function CompanyTab({
    companyId,
    contacts = [],
    changes = [],
    clientUsers,
    can,
    canContacts,
    roles,
}: Props & { canContacts: boolean; roles: Record<string, string> }) {
    const [form, setForm] = useState({
        user_id: '',
        contact_role: 'accounting_contact',
    });

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            <Card title="Contactos con acceso al portal">
                <Table
                    headers={['Contacto', 'Rol', '']}
                    empty={contacts.length === 0 && 'Sin contactos.'}
                >
                    {contacts.map((contact) => (
                        <tr key={contact.id}>
                            <td className="px-2 py-2 font-bold">
                                {contact.name}
                            </td>
                            <td className="px-2 py-2">{contact.role_label}</td>
                            <td className="px-2 py-2">
                                {canContacts && (
                                    <ActionButton
                                        url="/work/client-portal/contacts"
                                        label="Revocar"
                                        data={{
                                            company_id: companyId,
                                            user_id: contact.id,
                                            contact_role: contact.role,
                                            status: 'revoked',
                                        }}
                                    />
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
                {canContacts && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        <select
                            aria-label="Usuario"
                            className={`${input} w-auto`}
                            value={form.user_id}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    user_id: event.target.value,
                                })
                            }
                        >
                            <option value="">Usuario cliente…</option>
                            {clientUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name} · {user.email}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Rol"
                            className={`${input} w-auto`}
                            value={form.contact_role}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    contact_role: event.target.value,
                                })
                            }
                        >
                            {Object.entries(roles).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={!form.user_id}
                            onClick={() =>
                                router.post(
                                    '/work/client-portal/contacts',
                                    { company_id: companyId, ...form },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Dar acceso
                        </button>
                    </div>
                )}
            </Card>
            <Card title="Cambios de datos solicitados">
                <Table
                    headers={[
                        'Dato',
                        'Actual → solicitado',
                        'Solicitó',
                        'Estado',
                        '',
                    ]}
                    empty={changes.length === 0 && 'Sin solicitudes de cambio.'}
                >
                    {changes.map((change) => (
                        <tr key={change.id}>
                            <td className="px-2 py-2">{change.field}</td>
                            <td className="px-2 py-2">
                                {change.current ?? '—'} →{' '}
                                <b>{change.requested}</b>
                                {change.reason && (
                                    <span className="block text-[#526b92]">
                                        {change.reason}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {change.by}
                                <span className="block text-[#526b92]">
                                    {formatDate(change.at)}
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                {change.status === 'pending'
                                    ? 'Pendiente'
                                    : change.status === 'approved'
                                      ? 'Aplicado'
                                      : 'Rechazado'}
                            </td>
                            <td className="px-2 py-2">
                                {can.company && change.status === 'pending' && (
                                    <div className="flex gap-1">
                                        <ActionButton
                                            url={`/work/client-portal/changes/${change.id}/approve`}
                                            label="Aplicar"
                                            tone="primary"
                                        />
                                        <ActionButton
                                            url={`/work/client-portal/changes/${change.id}/reject`}
                                            label="Rechazar"
                                            ask="Motivo (el cliente lo verá)"
                                        />
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </div>
    );
}
