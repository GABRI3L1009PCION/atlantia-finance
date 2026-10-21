import { Head, Link } from '@inertiajs/react';
import { LegalBody } from '@/components/legal-body';

type Document = {
    slug: string;
    label: string;
    title: string;
    body: string;
    version: number;
    published_at: string;
};

export default function LegalShow({
    document,
    links,
}: {
    document: Document;
    links: { label: string; href: string }[];
}) {
    const published = new Date(document.published_at).toLocaleDateString(
        'es-GT',
        { day: 'numeric', month: 'long', year: 'numeric' },
    );

    return (
        <>
            <Head title={document.title} />
            <div className="min-h-screen bg-[#faf7f2]">
                <header className="border-b border-[#640000]/10 bg-white">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-4">
                        <Link href="/" className="font-bold text-[#640000]">
                            Atlantia Finance
                        </Link>
                        <nav className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                            {links.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    className={
                                        link.label === document.label
                                            ? 'font-semibold text-[#640000]'
                                            : 'hover:text-[#640000]'
                                    }
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </header>
                <main className="mx-auto max-w-3xl px-4 py-10">
                    <h1 className="text-3xl font-bold text-slate-900">
                        {document.title}
                    </h1>
                    <p className="mt-1 mb-8 text-sm text-slate-500">
                        Versión {document.version} · Publicada el {published}
                    </p>
                    <LegalBody body={document.body} />
                </main>
            </div>
        </>
    );
}
