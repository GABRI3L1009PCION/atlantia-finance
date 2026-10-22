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

const published = (value: string) =>
    new Date(value).toLocaleDateString('es-GT', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

export default function LegalShow({
    document,
    sections,
    links,
}: {
    document: Document;
    sections: Document[];
    links: { label: string; href: string }[];
}) {
    // The terms page carries the other documents too, so its menu jumps inside the page.
    const together = sections.length > 1;

    return (
        <>
            <Head title={document.title} />
            <div className="min-h-screen scroll-smooth bg-[#faf7f2]">
                <header className="sticky top-0 z-10 border-b border-[#640000]/10 bg-white">
                    <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <Link href="/" className="font-bold text-[#640000]">
                            Atlantia Finance
                        </Link>
                        <nav className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                            {together
                                ? sections.map((section) => (
                                      <a
                                          key={section.slug}
                                          href={`#${section.slug}`}
                                          className="hover:text-[#640000]"
                                      >
                                          {section.label}
                                      </a>
                                  ))
                                : links.map((link) => (
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
                <main className="mx-auto max-w-3xl space-y-14 px-4 py-10">
                    {sections.map((section) => (
                        <section
                            key={section.slug}
                            id={section.slug}
                            className="scroll-mt-20"
                        >
                            <h1 className="text-3xl font-bold text-slate-900">
                                {section.title}
                            </h1>
                            <p className="mt-1 mb-8 text-sm text-slate-500">
                                Versión {section.version} · Publicada el{' '}
                                {published(section.published_at)}
                            </p>
                            <LegalBody body={section.body} />
                        </section>
                    ))}
                </main>
            </div>
        </>
    );
}
