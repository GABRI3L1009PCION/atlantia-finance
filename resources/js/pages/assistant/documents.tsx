import { Head, useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type {
    AssistantContext,
    AssistantPermissions,
    PendingDocument,
} from '@/components/assistant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Client = { id: number; name: string; company_ids: number[] };

export default function AssistantDocuments({
    context,
    documents,
    clients,
    permissions,
    notice,
}: {
    context: AssistantContext;
    documents: PendingDocument[];
    clients: Client[];
    permissions: AssistantPermissions;
    notice: string | null;
}) {
    const [requesting, setRequesting] = useState(false);
    const form = useForm({
        company_id: context.companies[0]?.id.toString() ?? '',
        recipient_id: '',
        type: 'document_request',
        subject: '',
        body: '',
        due_at: '',
    });
    const available = clients.filter((client) =>
        client.company_ids.includes(Number(form.data.company_id)),
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/communication', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('subject', 'body', 'due_at');
                setRequesting(false);
            },
        });
    };

    return (
        <>
            <Head title="Documentos pendientes" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Documentos pendientes"
                    subtitle="Solicitudes al cliente sin recibir y documentos cargados que falta procesar"
                    actions={
                        permissions.canRequestDocuments && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setRequesting(true)}
                            >
                                <Send className="size-4" /> Solicitar documento
                            </button>
                        )
                    }
                />
                <Card title={`${documents.length} documento(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Tipo',
                            'Descripción',
                            'Fecha',
                            'Origen',
                            'Estado',
                            '',
                        ]}
                        empty={
                            documents.length === 0 &&
                            'No existen documentos pendientes.'
                        }
                    >
                        {documents.map((document) => (
                            <tr key={document.key}>
                                <td className="px-2 py-2 font-bold">
                                    {document.company}
                                </td>
                                <td className="px-2 py-2">{document.type}</td>
                                <td className="max-w-md px-2 py-2">
                                    {document.description}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(document.requested_at)}
                                </td>
                                <td className="px-2 py-2">{document.source}</td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            document.status === 'requested'
                                                ? 'red'
                                                : document.status ===
                                                    'duplicate'
                                                  ? 'violet'
                                                  : 'amber',
                                        )}
                                    >
                                        {document.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {document.destination && (
                                        <a
                                            href={document.destination}
                                            className={secondaryButton}
                                        >
                                            Ver
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>

            <Dialog open={requesting} onOpenChange={setRequesting}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            Solicitar documento al cliente
                        </DialogTitle>
                        <DialogDescription>
                            El cliente recibe la solicitud en su bandeja; queda
                            como pendiente a tu cargo hasta que lo recibas y lo
                            proceses.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={submit}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        <label className="text-sm font-semibold">
                            Empresa
                            <select
                                className={`${input} mt-1`}
                                value={form.data.company_id}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        company_id: event.target.value,
                                        recipient_id: '',
                                    })
                                }
                            >
                                {context.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">
                            Cliente
                            <select
                                className={`${input} mt-1`}
                                value={form.data.recipient_id}
                                onChange={(event) =>
                                    form.setData(
                                        'recipient_id',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">
                                    {available.length === 0
                                        ? 'La empresa no tiene usuarios cliente'
                                        : 'Elegir'}
                                </option>
                                {available.map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-semibold sm:col-span-2">
                            Documento solicitado
                            <input
                                className={`${input} mt-1`}
                                placeholder="Ej. Estado de cuenta Banco Industrial septiembre"
                                value={form.data.subject}
                                onChange={(event) =>
                                    form.setData('subject', event.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="text-sm font-semibold">
                            Fecha límite
                            <input
                                type="date"
                                className={`${input} mt-1`}
                                value={form.data.due_at}
                                onChange={(event) =>
                                    form.setData('due_at', event.target.value)
                                }
                            />
                        </label>
                        <label className="text-sm font-semibold sm:col-span-2">
                            Mensaje
                            <textarea
                                className={`${input} mt-1 h-20 py-2`}
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                required
                            />
                        </label>
                        {Object.values(form.errors).length > 0 && (
                            <p className="text-sm text-red-700 sm:col-span-2">
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                        <DialogFooter className="sm:col-span-2">
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setRequesting(false)}
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={
                                    form.processing ||
                                    form.data.recipient_id === ''
                                }
                            >
                                Enviar solicitud
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
