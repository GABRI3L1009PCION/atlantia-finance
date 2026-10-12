import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ExternalLink,
    Search,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { statusTone } from '@/components/library/kit';
import type { LibraryDocument, LibrarySection } from '@/components/library/kit';
import { askLuzma } from '@/components/luzma/client';
import { Shell, formatDate } from '@/components/office-admin/kit';
import { Card, toneBadge } from '@/components/supervisor/kit';

export default function LibraryShow({
    document,
    parts,
    sections,
    total,
    path,
    query,
}: {
    document: LibraryDocument;
    parts: string[];
    sections: LibrarySection[];
    total: number;
    path: string | null;
    query: string | null;
}) {
    const [search, setSearch] = useState(query ?? '');
    const visit = (params: Record<string, string>) =>
        router.get(`/library/${document.code}`, params, {
            preserveScroll: false,
        });

    useEffect(() => {
        const hash = window.location.hash.slice(1);

        if (hash) {
            window.document
                .getElementById(hash)
                ?.scrollIntoView({ block: 'start' });
        }
    }, []);

    return (
        <>
            <Head title={document.short_title} />
            <Shell>
                <Link
                    href="/library"
                    className="inline-flex items-center gap-1 text-xs font-bold text-[#640000]"
                >
                    <ArrowLeft className="size-3.5" /> Biblioteca Normativa
                </Link>
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-black text-[#061633]">
                                {document.title}
                            </h1>
                            <p className="text-xs text-[#526b92]">
                                {document.issuer}
                                {document.reference &&
                                    ` · ${document.reference}`}{' '}
                                · {document.version}
                                {document.effective_on &&
                                    ` · vigente desde ${formatDate(document.effective_on)}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            <span
                                className={toneBadge(
                                    statusTone[document.status] ?? 'slate',
                                )}
                            >
                                {document.status_label}
                            </span>
                            <span className={toneBadge('slate')}>
                                {document.is_full_text
                                    ? 'Texto completo'
                                    : 'Resumen'}
                            </span>
                        </div>
                    </div>
                    <p className="mt-2 text-sm text-slate-700">
                        {document.summary}
                    </p>
                    {document.warning && (
                        <p className="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                            <AlertTriangle className="size-4 shrink-0" />
                            {document.warning}
                        </p>
                    )}
                    {document.reforms.length > 0 && (
                        <div className="mt-3 space-y-1 text-xs">
                            <p className="font-bold text-[#061633]">
                                Reformas posteriores conocidas
                            </p>
                            {document.reforms.map((reform) => (
                                <p
                                    key={reform.decree}
                                    className="text-slate-700"
                                >
                                    <b>{reform.decree}</b>
                                    {reform.published_on &&
                                        ` (publicado ${formatDate(reform.published_on)})`}
                                    : {reform.note}
                                    {reform.effective &&
                                        ` Vigencia: ${reform.effective}`}
                                </p>
                            ))}
                        </div>
                    )}
                    <p className="mt-3 text-[11px] text-slate-500">
                        {document.source_name}
                        {document.source_url && (
                            <>
                                {' · '}
                                <a
                                    href={document.source_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-0.5 underline"
                                >
                                    fuente <ExternalLink className="size-3" />
                                </a>
                            </>
                        )}
                        {document.official_url && (
                            <>
                                {' · '}
                                <a
                                    href={document.official_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-0.5 underline"
                                >
                                    sitio oficial{' '}
                                    <ExternalLink className="size-3" />
                                </a>
                            </>
                        )}
                        {document.license_note && ` · ${document.license_note}`}
                    </p>
                </div>

                <div className="grid gap-3 lg:grid-cols-[260px_minmax(0,1fr)]">
                    <aside className="space-y-3 lg:sticky lg:top-20 lg:self-start">
                        <Card title="Buscar en este documento">
                            <form
                                className="relative"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    visit(search ? { q: search } : {});
                                }}
                            >
                                <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-slate-400" />
                                <input
                                    aria-label="Buscar en el documento"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Palabra o tema"
                                    className="h-9 w-full rounded-lg border border-slate-300 pl-8 text-xs outline-none"
                                />
                            </form>
                        </Card>
                        {parts.length > 1 && (
                            <Card title="Contenido">
                                <nav className="space-y-1 text-xs">
                                    <button
                                        type="button"
                                        onClick={() => visit({})}
                                        className={`block w-full rounded px-2 py-1 text-left ${!path ? 'bg-[#061633] font-bold text-white' : 'hover:bg-slate-100'}`}
                                    >
                                        Todo el documento
                                    </button>
                                    {parts.map((part) => (
                                        <button
                                            key={part}
                                            type="button"
                                            onClick={() =>
                                                visit({
                                                    path: part.split(' (')[0],
                                                })
                                            }
                                            className={`block w-full rounded px-2 py-1 text-left ${path && part.startsWith(path) ? 'bg-[#061633] font-bold text-white' : 'hover:bg-slate-100'}`}
                                        >
                                            {part}
                                        </button>
                                    ))}
                                </nav>
                            </Card>
                        )}
                    </aside>

                    <div className="space-y-2">
                        <p className="text-xs text-[#526b92]">
                            {total} {total === 1 ? 'sección' : 'secciones'}
                            {path && ` en ${path}`}
                            {query && ` que coinciden con "${query}"`}
                            {sections.length < total &&
                                ` (se muestran ${sections.length}; usa el índice o la búsqueda para acotar)`}
                        </p>
                        {sections.map((section) => (
                            <article
                                key={section.key}
                                id={section.key}
                                className="scroll-mt-24 rounded-xl border border-slate-200 bg-white p-4 shadow-sm target:border-[#640000]"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="text-sm font-black text-[#061633]">
                                            {section.label}
                                            {section.heading &&
                                                ` · ${section.heading}`}
                                        </h2>
                                        {section.path && (
                                            <p className="text-[10px] text-slate-500">
                                                {section.path}
                                                {section.page &&
                                                    ` · pág. ${section.page}`}
                                            </p>
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            askLuzma(
                                                `Explícame el ${section.label}${section.heading ? ` (${section.heading})` : ''} de ${document.short_title} y cómo se aplica`,
                                            )
                                        }
                                        className="inline-flex items-center gap-1 rounded-lg border border-[#d9c7a3] bg-[#fffaf0] px-2 py-1 text-[11px] font-bold text-[#8a5b32]"
                                    >
                                        <Sparkles className="size-3.5" />{' '}
                                        Preguntar a Luzma
                                    </button>
                                </div>
                                {section.reform_note && (
                                    <p className="mt-2 flex gap-1.5 rounded-lg bg-amber-50 p-2 text-[11px] font-semibold text-amber-900">
                                        <AlertTriangle className="size-3.5 shrink-0" />
                                        Este artículo fue reformado:{' '}
                                        {section.reform_note}
                                    </p>
                                )}
                                <p className="mt-2 text-sm leading-relaxed whitespace-pre-line text-slate-800">
                                    {section.content}
                                </p>
                            </article>
                        ))}
                    </div>
                </div>
            </Shell>
        </>
    );
}
