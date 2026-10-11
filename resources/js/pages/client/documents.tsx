import { router, useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { Fragment, useState } from 'react';
import type {
    ClientDocument,
    ClientPermissions,
} from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Table,
    documentTone,
    formatDate,
    toneBadge,
} from '@/components/client-portal/kit';
import {
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';

export default function ClientDocuments({
    documents,
    categories,
    permissions,
    notice,
}: {
    documents: ClientDocument[];
    categories: Record<string, string>;
    permissions: ClientPermissions;
    notice: string | null;
}) {
    const [category, setCategory] = useState('');
    const [expanded, setExpanded] = useState<number | null>(null);
    const form = useForm<{
        file: File | null;
        category: string;
        title: string;
        period: string;
    }>({ file: null, category: 'invoices', title: '', period: '' });
    const visible = documents.filter(
        (document) => !category || document.category === category,
    );
    const replace = (document: ClientDocument, file: File) => {
        const reason = window.prompt(
            'Motivo del reemplazo (la versión anterior se conserva)',
        );

        if (!reason) {
            return;
        }

        router.post(
            `/client/documents/${document.id}/replace`,
            { file, reason },
            { forceFormData: true, preserveScroll: true },
        );
    };

    return (
        <ClientPage
            title="Documentos"
            subtitle="Lo que enviaste a tu oficina contable y en qué estado está"
            notice={notice}
        >
            {permissions['client.documents.upload'] && (
                <Card title="Subir documento">
                    <form
                        className="grid gap-2 md:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/client/documents', {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <input
                            type="file"
                            aria-label="Archivo"
                            className={`${input} pt-1.5`}
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            required
                        />
                        <select
                            aria-label="Categoría"
                            className={input}
                            value={form.data.category}
                            onChange={(event) =>
                                form.setData('category', event.target.value)
                            }
                        >
                            {Object.entries(categories).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <input
                            className={input}
                            placeholder="Descripción"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                        <input
                            className={input}
                            placeholder="Período (ej. Septiembre 2026)"
                            value={form.data.period}
                            onChange={(event) =>
                                form.setData('period', event.target.value)
                            }
                        />
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            <Upload className="size-4" />
                            {form.processing ? 'Subiendo…' : 'Subir'}
                        </button>
                    </form>
                    {form.progress && (
                        <div className="mt-2 h-1.5 overflow-hidden rounded bg-slate-100">
                            <div
                                className="h-full bg-[#640000]"
                                style={{
                                    width: `${form.progress.percentage ?? 0}%`,
                                }}
                            />
                        </div>
                    )}
                </Card>
            )}

            <Card
                title={`${visible.length} documento(s)`}
                action={
                    <select
                        aria-label="Categoría"
                        className={`${input} h-8 w-auto`}
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                    >
                        <option value="">Todas las categorías</option>
                        {Object.entries(categories).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                }
            >
                <Table
                    headers={[
                        'Documento',
                        'Categoría',
                        'Período',
                        'Subido',
                        'Estado',
                        'Versión',
                        'Acciones',
                    ]}
                    empty={
                        visible.length === 0 &&
                        'Aún no hay documentos. Usa «Subir documento» para enviar el primero.'
                    }
                >
                    {visible.map((document) => (
                        <Fragment key={document.id}>
                            <tr>
                                <td className="px-2 py-2">
                                    <span className="block font-bold">
                                        {document.title}
                                    </span>
                                    <span className="block text-[#526b92]">
                                        {document.filename}
                                    </span>
                                    {document.review_note && (
                                        <span className="block text-amber-700">
                                            {document.review_note}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {document.category_label}
                                </td>
                                <td className="px-2 py-2">
                                    {document.period ?? '—'}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(document.uploaded_at)}
                                    <span className="block text-[#526b92]">
                                        {document.uploaded_by}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            documentTone[document.status] ??
                                                'slate',
                                        )}
                                    >
                                        {document.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <button
                                        type="button"
                                        className="font-bold text-[#640000]"
                                        onClick={() =>
                                            setExpanded(
                                                expanded === document.id
                                                    ? null
                                                    : document.id,
                                            )
                                        }
                                    >
                                        v{document.current_version}
                                    </button>
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {document.versions[0] && (
                                            <a
                                                href={
                                                    document.versions[0]
                                                        .download
                                                }
                                                className={secondaryButton}
                                            >
                                                Descargar
                                            </a>
                                        )}
                                        {permissions[
                                            'client.documents.replace'
                                        ] &&
                                            !['processed', 'archived'].includes(
                                                document.status,
                                            ) && (
                                                <label
                                                    className={`${secondaryButton} cursor-pointer`}
                                                >
                                                    Reemplazar
                                                    <input
                                                        type="file"
                                                        className="hidden"
                                                        onChange={(event) => {
                                                            const file =
                                                                event.target
                                                                    .files?.[0];

                                                            if (file) {
                                                                replace(
                                                                    document,
                                                                    file,
                                                                );
                                                            }
                                                        }}
                                                    />
                                                </label>
                                            )}
                                    </div>
                                </td>
                            </tr>
                            {expanded === document.id && (
                                <tr key={`${document.id}-versions`}>
                                    <td
                                        colSpan={7}
                                        className="bg-slate-50 px-4 py-2"
                                    >
                                        <ul className="grid gap-1">
                                            {document.versions.map(
                                                (version) => (
                                                    <li
                                                        key={version.version}
                                                        className="flex flex-wrap gap-3"
                                                    >
                                                        <span className="font-bold">
                                                            v{version.version}
                                                        </span>
                                                        <span>
                                                            {version.file_name}
                                                        </span>
                                                        <span>
                                                            {formatDate(
                                                                version.uploaded_at,
                                                                true,
                                                            )}
                                                        </span>
                                                        {version.reason && (
                                                            <span className="text-[#526b92]">
                                                                {version.reason}
                                                            </span>
                                                        )}
                                                        <a
                                                            href={
                                                                version.download
                                                            }
                                                            className="font-bold text-[#640000]"
                                                        >
                                                            Descargar
                                                        </a>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </td>
                                </tr>
                            )}
                        </Fragment>
                    ))}
                </Table>
            </Card>
        </ClientPage>
    );
}
