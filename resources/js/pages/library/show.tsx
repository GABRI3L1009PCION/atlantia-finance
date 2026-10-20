import { Head } from '@inertiajs/react';
import { BookReader } from '@/components/library/book-reader';
import type { LibraryDocument, LibrarySection } from '@/components/library/kit';
import { Shell } from '@/components/office-admin/kit';

export default function LibraryShow({
    document,
    sections,
    start,
}: {
    document: LibraryDocument;
    parts: string[];
    sections: LibrarySection[];
    total: number;
    path: string | null;
    query: string | null;
    start: string | null;
}) {
    return (
        <>
            <Head title={document.short_title} />
            <Shell>
                <BookReader
                    key={document.code}
                    doc={document}
                    sections={sections}
                    start={start}
                />
            </Shell>
        </>
    );
}
