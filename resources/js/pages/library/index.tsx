import { Head, Link, router } from '@inertiajs/react';
import {
    BookOpen,
    FileText,
    Search,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import {
    BookCarousel,
    categoryTabIcon,
} from '@/components/library/book-carousel';
import { ResultRow } from '@/components/library/kit';
import type { LibraryDocument, LibraryResult } from '@/components/library/kit';
import { askLuzma } from '@/components/luzma/client';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function LibraryIndex({
    documents,
    categories,
    query,
    results,
}: {
    documents: LibraryDocument[];
    categories: Record<string, string>;
    query: string;
    results: LibraryResult[];
}) {
    const [search, setSearch] = useState(query);
    const [category, setCategory] = useState('all');
    const groups = Object.entries(categories)
        .map(([key, label]) => ({
            key,
            label,
            documents: documents.filter(
                (document) => document.category === key,
            ),
        }))
        .filter((group) => group.documents.length > 0);
    const visibleDocuments =
        category === 'all'
            ? documents
            : documents.filter((document) => document.category === category);
    const collections = documents.flatMap((document) =>
        document.collections.map((collection) => ({ document, collection })),
    );

    return (
        <>
            <Head title="Biblioteca Normativa" />
            <Shell>
                <PageHeader
                    title="Biblioteca Normativa"
                    subtitle="Leyes guatemaltecas artículo por artículo, guías de estados financieros y fichas NIIF. Es la fuente de consulta: Luzma busca aquí y cita el artículo."
                />
                <Card title="Buscar en la biblioteca">
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                '/library',
                                { q: search },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <label className="relative min-w-64 flex-1">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                aria-label="Buscar"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Ej. libros de contabilidad obligatorios, reserva legal, pagos trimestrales ISR, método indirecto"
                                className="h-10 w-full rounded-lg border border-slate-300 pl-9 text-sm outline-none focus:border-[#640000]/50"
                            />
                        </label>
                        <button
                            type="submit"
                            className="h-10 rounded-lg bg-[#640000] px-4 text-sm font-bold text-white"
                        >
                            Buscar
                        </button>
                        {search.trim().length > 2 && (
                            <button
                                type="button"
                                onClick={() =>
                                    askLuzma(
                                        `¿Qué dice la normativa sobre ${search.trim()}?`,
                                    )
                                }
                                className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-[#d9c7a3] bg-[#fffaf0] px-3 text-sm font-bold text-[#8a5b32]"
                            >
                                <Sparkles className="size-4" /> Preguntar a
                                Luzma
                            </button>
                        )}
                    </form>
                    {query !== '' && (
                        <div className="mt-3 space-y-2">
                            <p className="text-xs text-[#526b92]">
                                {results.length} resultado(s) para "{query}"
                            </p>
                            {results.length === 0 && (
                                <p className="text-sm text-slate-500">
                                    No hay artículos que coincidan. Prueba con
                                    otras palabras.
                                </p>
                            )}
                            {results.map((result) => (
                                <ResultRow
                                    key={`${result.document_code}-${result.key}`}
                                    result={result}
                                />
                            ))}
                        </div>
                    )}
                </Card>

                {collections.length > 0 && (
                    <Card title="Accesos directos">
                        <div className="flex flex-wrap gap-2">
                            {collections.map(({ document, collection }) => (
                                <Link
                                    key={document.code + collection.label}
                                    href={`/library/${document.code}`}
                                    data={
                                        collection.path
                                            ? { path: collection.path }
                                            : { q: collection.search }
                                    }
                                    className="rounded-full border border-[#d8cfc9] bg-white px-3 py-1.5 text-xs font-bold text-[#061633] hover:border-[#640000]/40"
                                >
                                    {document.short_title} · {collection.label}
                                </Link>
                            ))}
                        </div>
                    </Card>
                )}

                <section className="space-y-3">
                    <div
                        className="flex flex-wrap gap-2"
                        role="tablist"
                        aria-label="Categorías"
                    >
                        {[
                            { key: 'all', label: 'Todos', Icon: BookOpen },
                            ...groups.map((group) => ({
                                key: group.key,
                                label: group.label,
                                Icon: categoryTabIcon[group.key] ?? FileText,
                            })),
                        ].map(({ key, label, Icon }) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={category === key}
                                onClick={() => setCategory(key)}
                                className={`inline-flex h-10 items-center gap-2 rounded-full border px-4 text-sm font-bold transition ${
                                    category === key
                                        ? 'border-[#640000] bg-[#640000] text-white'
                                        : 'border-slate-300 bg-white text-[#061633] hover:border-[#640000]/40'
                                }`}
                            >
                                <Icon className="size-4" /> {label}
                            </button>
                        ))}
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white px-4 pt-4 pb-3 shadow-sm">
                        <BookCarousel
                            key={category}
                            documents={visibleDocuments}
                        />
                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-[#fdf5e6] px-3 py-2 text-xs text-[#7a5b1f]">
                            <p className="flex items-center gap-2">
                                <TriangleAlert className="size-4 shrink-0" />
                                Textos legales con reformas posteriores. Las
                                fichas NIIF son resúmenes de orientación.
                            </p>
                            <p className="text-[#526b92]">
                                {visibleDocuments.length} de {documents.length}{' '}
                                recursos
                            </p>
                        </div>
                    </div>
                </section>
            </Shell>
        </>
    );
}
