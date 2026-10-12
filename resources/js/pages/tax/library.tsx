import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import type { TaxContext } from '@/components/tax/kit';

type Document = {
    id: number;
    title: string;
    reference: string | null;
    type: string;
    status: string;
    effective_on: string;
    company: string;
    destination: string;
};

export default function TaxLibrary({
    documents,
    search,
}: {
    context: TaxContext;
    documents: Document[];
    search: string;
}) {
    const [term, setTerm] = useState(search);

    return (
        <>
            <Head title="Biblioteca normativa" />
            <Shell>
                <PageHeader
                    title="Biblioteca normativa"
                    subtitle="Normas publicadas en la biblioteca de tus empresas. Es la fuente de consulta; Luzma solo ayuda a buscar y resumir."
                />
                <Card
                    title="Biblioteca compartida de Atlantia"
                    action={
                        <a href="/library" className={secondaryButton}>
                            Abrir biblioteca
                        </a>
                    }
                >
                    <p className="text-sm text-slate-600">
                        Ley de Actualización Tributaria (Decreto 10-2012, con el
                        Libro I de ISR), Código de Comercio (Decreto 2-70),
                        guías de Balance General, Estado de Resultados y Flujo
                        de Efectivo (bajo ISR y NIIF) y fichas NIIF, con
                        búsqueda por artículo y advertencias de reformas.
                    </p>
                </Card>
                <Card
                    title={`Normas internas de tus empresas (${documents.length})`}
                    action={
                        <form
                            className="flex gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.get(
                                    '/tax/library',
                                    term === '' ? {} : { q: term },
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <div className="relative">
                                <Search className="absolute top-2.5 left-3 size-4 text-slate-400" />
                                <input
                                    aria-label="Buscar norma"
                                    className="h-9 rounded-lg border border-slate-200 pr-3 pl-9 text-sm"
                                    placeholder="Título o referencia"
                                    value={term}
                                    onChange={(event) =>
                                        setTerm(event.target.value)
                                    }
                                />
                            </div>
                            <button type="submit" className={secondaryButton}>
                                Buscar
                            </button>
                        </form>
                    }
                >
                    <Table
                        headers={[
                            'Norma',
                            'Referencia',
                            'Tipo',
                            'Vigencia desde',
                            'Empresa',
                            'Estado',
                            '',
                        ]}
                        empty={
                            documents.length === 0 &&
                            'No hay normas publicadas en la biblioteca de tus empresas.'
                        }
                    >
                        {documents.map((document) => (
                            <tr key={document.id}>
                                <td className="px-2 py-2 font-bold">
                                    {document.title}
                                </td>
                                <td className="px-2 py-2">
                                    {document.reference ?? '—'}
                                </td>
                                <td className="px-2 py-2">{document.type}</td>
                                <td className="px-2 py-2">
                                    {formatDate(document.effective_on)}
                                </td>
                                <td className="px-2 py-2">
                                    {document.company}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            document.status === 'Vigente'
                                                ? 'green'
                                                : 'slate',
                                        )}
                                    >
                                        {document.status}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={document.destination}
                                        className={secondaryButton}
                                    >
                                        Abrir
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
