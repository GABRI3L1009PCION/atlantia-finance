import { Head, useForm } from '@inertiajs/react';
import {
    CCPage,
    Panel,
    ccButton,
    ccInput,
    ccTextarea,
    date,
} from '@/components/control-center/kit';

type LegalDocument = {
    slug: string;
    label: string;
    path: string;
    title: string | null;
    body: string | null;
    version: number | null;
    accepted: number;
    history: {
        version: number;
        status: string;
        published_at: string;
        accepted: number;
    }[];
};

function DocumentEditor({
    document,
    canManage,
}: {
    document: LegalDocument;
    canManage: boolean;
}) {
    const form = useForm({
        title: document.title ?? document.label,
        body: document.body ?? '',
    });
    const placeholders = (form.data.body.match(/\[[^\]]+\]/g) ?? []).length;

    return (
        <Panel
            title={document.label}
            action={
                <span className="text-xs text-slate-400">
                    {document.version === null
                        ? 'Sin publicar'
                        : `Versión ${document.version} · ${document.accepted} aceptaciones`}
                </span>
            }
        >
            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/control-center/legal/${document.slug}`, {
                        preserveScroll: true,
                    });
                }}
            >
                <input
                    className={ccInput}
                    disabled={!canManage}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
                <textarea
                    className={`${ccTextarea} font-mono text-xs`}
                    rows={14}
                    disabled={!canManage}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                />
                <p className="text-[11px] text-slate-500">
                    Formato: «## Título», «**negrita**», «- viñeta». Lo que va
                    entre [corchetes] aún está por definir
                    {placeholders > 0
                        ? ` (${placeholders} pendiente${placeholders === 1 ? '' : 's'}).`
                        : '.'}
                </p>
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-[#f87171]">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                <div className="flex items-center gap-3">
                    {canManage && form.isDirty && (
                        <button className={ccButton} disabled={form.processing}>
                            Publicar nueva versión
                        </button>
                    )}
                    <a
                        href={document.path}
                        target="_blank"
                        rel="noreferrer"
                        className="text-xs text-slate-400 underline"
                    >
                        Ver página pública
                    </a>
                </div>
            </form>
            {document.history.length > 0 && (
                <p className="mt-2 text-[10px] text-slate-500">
                    Historial:{' '}
                    {document.history
                        .map(
                            (item) =>
                                `v${item.version} (${item.status}, ${date(item.published_at)}, ${item.accepted} aceptaciones)`,
                        )
                        .join(' · ')}
                </p>
            )}
        </Panel>
    );
}

export default function Legal({
    documents,
    can_manage,
}: {
    documents: LegalDocument[];
    can_manage: boolean;
}) {
    return (
        <>
            <Head title="Documentos legales · Control Center" />
            <CCPage
                title="Documentos legales"
                subtitle="Términos y Condiciones, Política de Privacidad y Tratamiento de datos. Al publicar una versión nueva, los dueños de firma deben aceptarla en su siguiente ingreso; cada aceptación guarda versión, fecha e IP."
            >
                <div className="space-y-3">
                    {documents.map((document) => (
                        <DocumentEditor
                            key={`${document.slug}-${document.version}`}
                            document={document}
                            canManage={can_manage}
                        />
                    ))}
                </div>
            </CCPage>
        </>
    );
}
