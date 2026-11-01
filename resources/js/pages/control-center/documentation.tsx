import { Head, router, useForm } from '@inertiajs/react';
import {
    CCPage,
    Panel,
    bytes,
    ccButton,
    ccGhost,
    ccInput,
    ccTextarea,
    date,
} from '@/components/control-center/kit';

type ProjectDocument = {
    id: string;
    title: string;
    description: string | null;
    file_name: string;
    size: number;
    uploaded_at: string;
};

function Errors({ errors }: { errors: Record<string, string | undefined> }) {
    const messages = Object.values(errors).filter(Boolean);

    return messages.length > 0 ? (
        <p className="text-xs text-[#f87171]">{messages.join(' ')}</p>
    ) : null;
}

function PasswordPanel({
    hasPassword,
    canManage,
}: {
    hasPassword: boolean;
    canManage: boolean;
}) {
    const form = useForm({
        access_password: '',
        access_password_confirmation: '',
    });

    return (
        <Panel
            title="Contraseña de acceso"
            action={
                <span
                    className={`text-xs ${hasPassword ? 'text-emerald-400' : 'text-amber-400'}`}
                >
                    {hasPassword
                        ? 'Configurada'
                        : 'Sin contraseña: la página está cerrada'}
                </span>
            }
        >
            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/control-center/documentation/password', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <div className="grid gap-2 sm:grid-cols-2">
                    <input
                        type="password"
                        autoComplete="new-password"
                        className={ccInput}
                        placeholder={
                            hasPassword
                                ? 'Nueva contraseña'
                                : 'Contraseña (mínimo 8 caracteres)'
                        }
                        disabled={!canManage}
                        value={form.data.access_password}
                        onChange={(event) =>
                            form.setData('access_password', event.target.value)
                        }
                    />
                    <input
                        type="password"
                        autoComplete="new-password"
                        className={ccInput}
                        placeholder="Repite la contraseña"
                        disabled={!canManage}
                        value={form.data.access_password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'access_password_confirmation',
                                event.target.value,
                            )
                        }
                    />
                </div>
                <p className="text-[11px] text-slate-500">
                    Compártela con la terna evaluadora. Solo se guarda cifrada:
                    si la olvidas, crea otra. Al cambiarla, quien ya había
                    entrado tendrá que escribir la nueva.
                </p>
                <Errors errors={form.errors} />
                {canManage && (
                    <button
                        className={ccButton}
                        disabled={
                            form.processing || form.data.access_password === ''
                        }
                    >
                        {hasPassword
                            ? 'Cambiar contraseña'
                            : 'Crear contraseña'}
                    </button>
                )}
            </form>
        </Panel>
    );
}

function UploadPanel({ maxUploadMb }: { maxUploadMb: number }) {
    const form = useForm<{
        title: string;
        description: string;
        file: File | null;
    }>({ title: '', description: '', file: null });

    return (
        <Panel title="Subir documento">
            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/control-center/documentation/files', {
                        preserveScroll: true,
                        forceFormData: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <input
                    className={ccInput}
                    placeholder="Título (por ejemplo: Manual técnico)"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
                <textarea
                    className={ccTextarea}
                    rows={2}
                    placeholder="Descripción breve (opcional)"
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
                <input
                    key={form.data.file === null ? 'empty' : 'selected'}
                    type="file"
                    accept="application/pdf,.pdf"
                    className="block w-full text-xs text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
                <p className="text-[11px] text-slate-500">
                    Solo PDF, hasta {maxUploadMb} MB.
                </p>
                {form.progress && (
                    <progress
                        className="w-full"
                        value={form.progress.percentage}
                        max={100}
                    />
                )}
                <Errors errors={form.errors} />
                <button
                    className={ccButton}
                    disabled={
                        form.processing ||
                        form.data.file === null ||
                        form.data.title.trim() === ''
                    }
                >
                    Publicar documento
                </button>
            </form>
        </Panel>
    );
}

export default function Documentation({
    documents,
    has_password,
    max_upload_mb,
    can_manage,
}: {
    documents: ProjectDocument[];
    has_password: boolean;
    max_upload_mb: number;
    can_manage: boolean;
}) {
    return (
        <>
            <Head title="Documentación del proyecto · Control Center" />
            <CCPage
                title="Documentación del proyecto"
                subtitle="PDFs que la terna evaluadora consulta en /documentacion (enlace «Documentación» del pie de página). La página pide la contraseña que definas aquí; el acceso dura unas horas por navegador."
                actions={
                    <a
                        href="/documentacion"
                        target="_blank"
                        rel="noreferrer"
                        className={ccGhost}
                    >
                        Ver página pública
                    </a>
                }
            >
                <div className="grid gap-3 lg:grid-cols-2">
                    <PasswordPanel
                        hasPassword={has_password}
                        canManage={can_manage}
                    />
                    {can_manage && <UploadPanel maxUploadMb={max_upload_mb} />}
                </div>
                <Panel
                    title="Documentos publicados"
                    action={
                        <span className="text-xs text-slate-400">
                            {documents.length}
                        </span>
                    }
                >
                    {documents.length === 0 ? (
                        <p className="text-sm text-slate-400">
                            Aún no hay documentos.
                        </p>
                    ) : (
                        <ul className="divide-y divide-white/5">
                            {documents.map((document) => (
                                <li
                                    key={document.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold text-white">
                                            {document.title}
                                        </p>
                                        {document.description && (
                                            <p className="text-xs text-slate-400">
                                                {document.description}
                                            </p>
                                        )}
                                        <p className="text-[11px] text-slate-500">
                                            {document.file_name} ·{' '}
                                            {bytes(document.size)} ·{' '}
                                            {date(document.uploaded_at)}
                                        </p>
                                    </div>
                                    {can_manage && (
                                        <button
                                            type="button"
                                            className={ccGhost}
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        `¿Eliminar «${document.title}»?`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        `/control-center/documentation/files/${document.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Eliminar
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </CCPage>
        </>
    );
}
