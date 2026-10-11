import { Head, Link } from '@inertiajs/react';
import { Loader2, Search, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { DocumentCard, ResultRow } from '@/components/library/kit';
import type { LibraryDocument, LibraryResult } from '@/components/library/kit';
import { askLuzma, luzmaRequest } from '@/components/luzma/client';

/**
 * Normative Library inside a company: the shared catalog (laws, guides and NIIF summaries) with search.
 * The documents are the same for every company; the full reader lives at /library. The company's own (internal)
 * norms are rendered below as children.
 */
export default function NormativeLibraryWorkspace({
    company,
    children,
}: {
    company: { name: string };
    children?: ReactNode;
}) {
    const [documents, setDocuments] = useState<LibraryDocument[] | null>(null);
    const [results, setResults] = useState<LibraryResult[]>([]);
    const [query, setQuery] = useState('');
    const [searched, setSearched] = useState('');
    const [busy, setBusy] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const load = async (term = '') => {
        setBusy(true);
        setError(null);

        try {
            const data = await luzmaRequest<{
                documents: LibraryDocument[];
                results: LibraryResult[];
            }>(`/library/search?q=${encodeURIComponent(term)}`);
            setDocuments(data.documents);
            setResults(data.results);
            setSearched(term);
        } catch {
            setError('No fue posible cargar la biblioteca.');
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        luzmaRequest<{ documents: LibraryDocument[] }>('/library/search')
            .then((data) => setDocuments(data.documents))
            .catch(() => setError('No fue posible cargar la biblioteca.'))
            .finally(() => setBusy(false));
    }, []);

    return (
        <main className="min-h-full bg-[#f5f8fc] p-3 text-[#172033] sm:p-5">
            <Head title={`Biblioteca Normativa · ${company.name}`} />
            <div className="mx-auto max-w-[1600px] space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-black text-[#061633]">
                            Biblioteca Normativa
                        </h1>
                        <p className="text-sm text-[#526b92]">
                            Leyes guatemaltecas artículo por artículo, guías de
                            estados financieros y fichas NIIF.
                        </p>
                    </div>
                    <Link
                        href="/library"
                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-[#061633]"
                    >
                        Abrir biblioteca completa
                    </Link>
                </div>

                <form
                    className="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void load(query.trim());
                    }}
                >
                    <label className="relative min-w-64 flex-1">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            aria-label="Buscar en la biblioteca"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Ej. libros obligatorios, pagos trimestrales ISR, flujo de efectivo"
                            className="h-10 w-full rounded-lg border border-slate-300 pl-9 text-sm outline-none"
                        />
                    </label>
                    <button
                        type="submit"
                        className="h-10 rounded-lg bg-[#640000] px-4 text-sm font-bold text-white"
                    >
                        Buscar
                    </button>
                    {query.trim().length > 2 && (
                        <button
                            type="button"
                            onClick={() =>
                                askLuzma(
                                    `¿Qué dice la normativa sobre ${query.trim()}?`,
                                )
                            }
                            className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-[#d9c7a3] bg-[#fffaf0] px-3 text-sm font-bold text-[#8a5b32]"
                        >
                            <Sparkles className="size-4" /> Preguntar a Luzma
                        </button>
                    )}
                </form>

                {busy && (
                    <p className="inline-flex items-center gap-2 text-xs text-[#526b92]">
                        <Loader2 className="size-3.5 animate-spin" />{' '}
                        Consultando la biblioteca...
                    </p>
                )}
                {error && <p className="text-sm text-red-700">{error}</p>}

                {searched !== '' && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-black text-[#061633]">
                            {results.length} resultado(s) para "{searched}"
                        </h2>
                        {results.map((result) => (
                            <ResultRow
                                key={`${result.document_code}-${result.key}`}
                                result={result}
                            />
                        ))}
                    </section>
                )}

                {documents !== null && (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {documents.map((document) => (
                            <DocumentCard
                                key={document.id}
                                document={document}
                            />
                        ))}
                    </div>
                )}

                {children}
            </div>
        </main>
    );
}
