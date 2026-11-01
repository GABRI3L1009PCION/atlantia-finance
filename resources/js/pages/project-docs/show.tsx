import { Head, Link, useForm } from '@inertiajs/react';
import { Download, Eye, FileText, Lock } from 'lucide-react';

type ProjectDocument = {
    id: string;
    title: string;
    description: string | null;
    file_name: string;
    size: number;
    uploaded_at: string;
};

const size = (value: number) =>
    value >= 1048576
        ? `${(value / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(value / 1024))} KB`;

const published = (value: string) =>
    new Date(value).toLocaleDateString('es-GT', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

function PasswordForm({ available }: { available: boolean }) {
    const form = useForm({ password: '' });

    if (!available) {
        return (
            <p className="rounded-xl border border-[#640000]/10 bg-white p-6 text-center text-slate-600">
                La documentación aún no está disponible.
            </p>
        );
    }

    return (
        <form
            className="mx-auto max-w-sm space-y-4 rounded-2xl border border-[#640000]/10 bg-white p-6 shadow-sm"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/documentacion', {
                    preserveScroll: true,
                    onError: () => form.reset('password'),
                });
            }}
        >
            <div className="flex items-center gap-3">
                <span className="grid size-10 place-items-center rounded-full bg-[#640000]/10 text-[#640000]">
                    <Lock className="size-5" />
                </span>
                <div>
                    <h2 className="font-semibold text-slate-900">
                        Acceso restringido
                    </h2>
                    <p className="text-sm text-slate-500">
                        Ingresa la contraseña que recibiste.
                    </p>
                </div>
            </div>
            <label className="block">
                <span className="sr-only">Contraseña</span>
                <input
                    type="password"
                    autoComplete="current-password"
                    autoFocus
                    required
                    placeholder="Contraseña"
                    className="h-11 w-full rounded-lg border border-slate-300 px-3 text-slate-900 outline-none focus:border-[#640000] focus:ring-2 focus:ring-[#640000]/20"
                    value={form.data.password}
                    onChange={(event) =>
                        form.setData('password', event.target.value)
                    }
                />
            </label>
            {form.errors.password && (
                <p className="text-sm text-red-700">{form.errors.password}</p>
            )}
            <button
                className="h-11 w-full rounded-lg bg-[#640000] font-semibold text-white transition hover:bg-[#7a0a0a] disabled:opacity-60"
                disabled={form.processing || form.data.password === ''}
            >
                Entrar
            </button>
        </form>
    );
}

export default function ProjectDocsShow({
    available,
    unlocked,
    documents,
    access_hours,
}: {
    available: boolean;
    unlocked: boolean;
    documents: ProjectDocument[];
    access_hours: number;
}) {
    return (
        <>
            <Head title="Documentación del proyecto">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <div className="min-h-screen bg-[#faf7f2]">
                <header className="sticky top-0 z-10 border-b border-[#640000]/10 bg-white">
                    <div className="mx-auto flex max-w-3xl items-center justify-between gap-2 px-4 py-3">
                        <Link href="/" className="font-bold text-[#640000]">
                            Atlantia Finance
                        </Link>
                        {unlocked && (
                            <Link
                                href="/documentacion/salir"
                                method="post"
                                as="button"
                                className="text-sm text-slate-600 hover:text-[#640000]"
                            >
                                Cerrar acceso
                            </Link>
                        )}
                    </div>
                </header>
                <main className="mx-auto max-w-3xl px-4 py-10">
                    <h1 className="text-3xl font-bold text-slate-900">
                        Documentación del proyecto
                    </h1>
                    <p className="mt-1 mb-8 text-sm text-slate-500">
                        Material para la terna evaluadora de Atlantia Finance.
                    </p>
                    {!unlocked ? (
                        <PasswordForm available={available} />
                    ) : documents.length === 0 ? (
                        <p className="rounded-xl border border-[#640000]/10 bg-white p-6 text-center text-slate-600">
                            Todavía no hay documentos publicados.
                        </p>
                    ) : (
                        <>
                            <ul className="space-y-3">
                                {documents.map((document) => (
                                    <li
                                        key={document.id}
                                        className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#640000]/10 bg-white p-5 shadow-sm"
                                    >
                                        <div className="flex min-w-0 items-start gap-3">
                                            <FileText className="mt-0.5 size-6 shrink-0 text-[#640000]" />
                                            <div className="min-w-0">
                                                <h2 className="font-semibold text-slate-900">
                                                    {document.title}
                                                </h2>
                                                {document.description && (
                                                    <p className="text-sm text-slate-600">
                                                        {document.description}
                                                    </p>
                                                )}
                                                <p className="mt-1 text-xs text-slate-500">
                                                    PDF · {size(document.size)}{' '}
                                                    ·{' '}
                                                    {published(
                                                        document.uploaded_at,
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            <a
                                                href={`/documentacion/archivo/${document.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#640000] px-3 text-sm font-semibold text-white hover:bg-[#7a0a0a]"
                                            >
                                                <Eye className="size-4" /> Ver
                                            </a>
                                            <a
                                                href={`/documentacion/archivo/${document.id}?download=1`}
                                                className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#640000]/20 px-3 text-sm font-semibold text-[#640000] hover:bg-[#640000]/5"
                                            >
                                                <Download className="size-4" />{' '}
                                                Descargar
                                            </a>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-6 text-xs text-slate-500">
                                El acceso se cierra solo después de{' '}
                                {access_hours} horas.
                            </p>
                        </>
                    )}
                </main>
            </div>
        </>
    );
}
