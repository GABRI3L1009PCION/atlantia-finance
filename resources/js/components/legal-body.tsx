import { Fragment } from 'react';
import type { ReactNode } from 'react';

/** Inline marks of the legal text: **bold**, *italic* and [PLACEHOLDERS] that the owner still has to define. */
function inline(text: string): ReactNode[] {
    return text
        .split(/(\*\*[^*]+\*\*|\*[^*]+\*|\[[^\]]+\])/g)
        .filter((part) => part !== '')
        .map((part, index) => {
            if (part.startsWith('**') && part.endsWith('**')) {
                return <strong key={index}>{part.slice(2, -2)}</strong>;
            }

            if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
                return <em key={index}>{part.slice(1, -1)}</em>;
            }

            if (part.startsWith('[') && part.endsWith(']')) {
                return (
                    <mark
                        key={index}
                        className="rounded bg-amber-100 px-1 text-amber-900"
                    >
                        {part}
                    </mark>
                );
            }

            return <Fragment key={index}>{part}</Fragment>;
        });
}

export function LegalBody({ body }: { body: string }) {
    const blocks = body
        .replace(/\r\n?/g, '\n')
        .split(/\n{2,}/)
        .map((block) => block.trim());
    const nodes: ReactNode[] = [];
    let bullets: string[] = [];

    const flush = () => {
        if (bullets.length > 0) {
            nodes.push(
                <ul
                    key={`ul-${nodes.length}`}
                    className="list-disc space-y-1 pl-6"
                >
                    {bullets.map((item, index) => (
                        <li key={index}>{inline(item)}</li>
                    ))}
                </ul>,
            );
            bullets = [];
        }
    };

    blocks.forEach((block) => {
        if (block.startsWith('- ')) {
            bullets.push(block.slice(2));

            return;
        }

        flush();

        if (block.startsWith('## ')) {
            nodes.push(
                <h2
                    key={`h-${nodes.length}`}
                    className="pt-4 text-xl font-bold text-slate-900"
                >
                    {block.slice(3)}
                </h2>,
            );
        } else if (block !== '') {
            nodes.push(<p key={`p-${nodes.length}`}>{inline(block)}</p>);
        }
    });
    flush();

    return (
        <div className="space-y-3 text-[15px] leading-7 text-slate-700">
            {nodes}
        </div>
    );
}
