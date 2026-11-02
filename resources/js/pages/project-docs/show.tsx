import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, Download, Eye, Lock, LogOut } from 'lucide-react';
import { PublicHeader } from '@/components/landing/public-header';
import { BookCover, bookCovers } from '@/components/library/book-carousel';

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

/** Each document gets its own library cover, in this order, so the shelf reads like the Biblioteca Normativa. */
const shelf = ['guia', 'mercantil', 'contable', 'laboral', 'fiscal', 'interna'];

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

function Book({
    document,
    position,
}: {
    document: ProjectDocument;
    position: number;
}) {
    const cover = bookCovers[shelf[position % shelf.length]];
    const kind = /^manual/i.test(document.title) ? 'Manual' : 'Documento';

    return (
        <li className="group flex flex-col">
            <a
                href={`/documentacion/archivo/${document.id}`}
                target="_blank"
                rel="noreferrer"
                aria-label={`Ver ${document.title}`}
                className="block"
            >
                <BookCover
                    cover={cover}
                    title={document.title}
                    reference={`PDF · ${size(document.size)}`}
                    footer="Atlantia Finance"
                />
            </a>
            <p className="mt-3 text-xs font-bold text-[#061633]">{kind}</p>
            {document.description && (
                <p
                    className="line-clamp-3 text-[11px] text-[#526b92]"
                    title={document.description}
                >
                    {document.description}
                </p>
            )}
            <p className="mt-1 text-[10px] text-slate-400">
                Publicado el {published(document.uploaded_at)}
            </p>
            <div className="mt-auto grid grid-cols-2 gap-2 pt-3">
                <a
                    href={`/documentacion/archivo/${document.id}`}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-[#640000] text-xs font-bold text-white transition hover:bg-[#7a0a0a]"
                >
                    <Eye className="size-3.5" /> Ver
                </a>
                <a
                    href={`/documentacion/archivo/${document.id}?download=1`}
                    className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-[#640000]/25 bg-white text-xs font-bold text-[#640000] transition hover:bg-[#640000]/5"
                >
                    <Download className="size-3.5" /> Descargar
                </a>
            </div>
        </li>
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
            <div className="min-h-screen bg-[#FBF8F6] text-[#0C1424] antialiased">
                <PublicHeader
                    actions={
                        unlocked && (
                            <Link
                                href="/documentacion/salir"
                                method="post"
                                as="button"
                                className="inline-flex items-center gap-1.5 px-2 py-2 text-sm font-semibold text-white/85 transition hover:text-white"
                            >
                                <LogOut className="size-4" />
                                <span className="hidden md:inline">
                                    Cerrar acceso
                                </span>
                            </Link>
                        )
                    }
                />
                <main className="mx-auto max-w-6xl px-5 py-10 sm:px-8">
                    <div className="flex items-center gap-3">
                        <span className="grid size-11 place-items-center rounded-2xl bg-[#640000]/10 text-[#640000]">
                            <BookOpen className="size-5" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-bold text-[#061633]">
                                Documentación del proyecto
                            </h1>
                            <p className="text-sm text-[#526b92]">
                                Material para la terna evaluadora de Atlantia
                                Finance.
                            </p>
                        </div>
                    </div>
                    <div className="mt-8">
                        {!unlocked ? (
                            <PasswordForm available={available} />
                        ) : documents.length === 0 ? (
                            <p className="rounded-2xl border border-slate-200 bg-white p-6 text-center text-slate-600 shadow-sm">
                                Todavía no hay documentos publicados.
                            </p>
                        ) : (
                            <div className="rounded-2xl border border-slate-200 bg-white px-5 pt-6 pb-4 shadow-sm">
                                <ul className="grid grid-cols-2 gap-x-5 gap-y-8 sm:grid-cols-3 lg:grid-cols-5">
                                    {documents.map((document, position) => (
                                        <Book
                                            key={document.id}
                                            document={document}
                                            position={position}
                                        />
                                    ))}
                                </ul>
                                <p className="mt-6 rounded-lg bg-[#fdf5e6] px-3 py-2 text-xs text-[#7a5b1f]">
                                    {documents.length} documento
                                    {documents.length === 1 ? '' : 's'} · El
                                    acceso se cierra solo después de{' '}
                                    {access_hours} horas.
                                </p>
                            </div>
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}
