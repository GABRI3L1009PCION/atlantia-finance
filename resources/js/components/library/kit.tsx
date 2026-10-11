import { Link } from '@inertiajs/react';
import { AlertTriangle, BookOpen, FileText, Scale } from 'lucide-react';
import { toneBadge } from '@/components/supervisor/kit';

export type LibraryDocument = {
    id: string;
    code: string;
    title: string;
    short_title: string;
    category: string;
    category_label: string;
    type: string;
    issuer: string;
    reference: string | null;
    status: string;
    status_label: string;
    published_on: string | null;
    effective_on: string | null;
    version: string;
    summary: string;
    source_name: string | null;
    source_url: string | null;
    official_url: string | null;
    is_full_text: boolean;
    license_note: string | null;
    warning: string | null;
    reforms: {
        decree: string;
        published_on: string | null;
        effective: string | null;
        note: string;
        sections: string[];
    }[];
    tags: string[];
    collections: { label: string; path?: string; search?: string }[];
    private: boolean;
    sections_count?: number;
};

export type LibrarySection = {
    key: string;
    label: string;
    heading: string | null;
    path: string | null;
    content: string;
    page: number | null;
    reform_note: string | null;
};

export type LibraryResult = LibrarySection & {
    fragment: string;
    document_code: string;
    document: string;
    document_title: string;
    status_label: string;
    is_full_text: boolean;
    warning: string | null;
    destination: string;
};

export const statusTone: Record<string, string> = {
    vigente: 'green',
    vigente_con_reformas: 'amber',
    derogado: 'red',
    referencia: 'blue',
};

const categoryIcon = {
    fiscal: Scale,
    mercantil: Scale,
    contable: BookOpen,
    guia: FileText,
};

/** Card of one document of the library, with status and reform warning. */
export function DocumentCard({ document }: { document: LibraryDocument }) {
    const Icon =
        categoryIcon[document.category as keyof typeof categoryIcon] ??
        FileText;

    return (
        <Link
            href={`/library/${document.code}`}
            className="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-[#640000]/40"
        >
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#fbf4f4] text-[#640000]">
                    <Icon className="size-5" />
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-black text-[#061633]">
                        {document.title}
                    </p>
                    <p className="text-[11px] text-[#526b92]">
                        {document.issuer}
                        {document.reference && ` · ${document.reference}`}
                    </p>
                </div>
            </div>
            <p className="line-clamp-3 text-xs text-slate-600">
                {document.summary}
            </p>
            <div className="mt-auto flex flex-wrap items-center gap-1.5">
                <span
                    className={toneBadge(
                        statusTone[document.status] ?? 'slate',
                    )}
                >
                    {document.status_label}
                </span>
                <span className={toneBadge('slate')}>
                    {document.is_full_text ? 'Texto completo' : 'Resumen'}
                </span>
                {document.sections_count !== undefined && (
                    <span className="text-[11px] text-[#526b92]">
                        {document.sections_count}{' '}
                        {document.category === 'fiscal' ||
                        document.category === 'mercantil'
                            ? 'artículos'
                            : 'secciones'}
                    </span>
                )}
            </div>
            {document.warning && (
                <p className="flex gap-1.5 rounded-lg bg-amber-50 p-2 text-[11px] text-amber-900">
                    <AlertTriangle className="size-3.5 shrink-0" />
                    <span className="line-clamp-2">{document.warning}</span>
                </p>
            )}
        </Link>
    );
}

/** A search hit: the document, the article and the highlighted fragment. */
export function ResultRow({ result }: { result: LibraryResult }) {
    const parts = result.fragment.split(/(«[^»]*»)/g);

    return (
        <Link
            href={result.destination}
            className="block rounded-lg border border-slate-200 bg-white p-3 hover:border-[#640000]/40"
        >
            <p className="text-xs font-bold text-[#640000]">
                {result.document} · {result.label}
                {result.heading && ` · ${result.heading}`}
            </p>
            {result.path && (
                <p className="text-[10px] text-slate-500">{result.path}</p>
            )}
            <p className="mt-1 text-xs text-slate-700">
                {parts.map((part, index) =>
                    part.startsWith('«') ? (
                        <mark
                            key={index}
                            className="rounded bg-amber-100 px-0.5 text-[#061633]"
                        >
                            {part.slice(1, -1)}
                        </mark>
                    ) : (
                        <span key={index}>{part}</span>
                    ),
                )}
            </p>
            {result.reform_note && (
                <p className="mt-1 flex gap-1 text-[11px] font-semibold text-amber-800">
                    <AlertTriangle className="size-3.5 shrink-0" /> Reformado:{' '}
                    {result.reform_note}
                </p>
            )}
            {!result.is_full_text && (
                <p className="mt-1 text-[10px] text-slate-500">
                    Resumen de Atlantia, no texto oficial.
                </p>
            )}
        </Link>
    );
}
