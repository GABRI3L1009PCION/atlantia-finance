import { Head, router } from '@inertiajs/react';
import { ArrowDown } from 'lucide-react';
import { useState } from 'react';
import { amount, useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

type Node = {
    kind: string;
    label: string;
    date: string | null;
    amount_cents: number | null;
    status: string | null;
    href: string | null;
};

export default function AuditorTraceability({
    context,
    companyId,
    type,
    id,
    chain,
    types,
}: {
    context: AuditorContext;
    companyId: number | null;
    type: string;
    id: number | null;
    chain: { nodes: Node[]; message: string | null } | null;
    types: Record<string, string>;
}) {
    const href = useAuditorHref();
    const [form, setForm] = useState({
        company_id: companyId ? String(companyId) : '',
        type: type || 'purchase_documents',
        id: id ? String(id) : '',
    });

    return (
        <>
            <Head title="Trazabilidad" />
            <Shell>
                <PageHeader
                    title="Trazabilidad"
                    subtitle="Sigue una operación hasta el Mayor, o un asiento hasta su operación de origen"
                />
                <Card title="Operación a trazar">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(href('/work/audit/traceability'), form);
                        }}
                    >
                        <label className="grid min-w-56 gap-1 text-xs font-semibold">
                            Empresa
                            <select
                                className={input}
                                value={form.company_id}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        company_id: event.target.value,
                                    })
                                }
                            >
                                {context.companies.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold">
                            Tipo
                            <select
                                className={input}
                                value={form.type}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        type: event.target.value,
                                    })
                                }
                            >
                                {Object.entries(types).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid w-32 gap-1 text-xs font-semibold">
                            Identificador
                            <input
                                className={input}
                                value={form.id}
                                onChange={(event) =>
                                    setForm({ ...form, id: event.target.value })
                                }
                                inputMode="numeric"
                                required
                            />
                        </label>
                        <button type="submit" className={secondaryButton}>
                            Trazar
                        </button>
                    </form>
                </Card>
                {chain && (
                    <Card title="Cadena de la operación">
                        {chain.message && (
                            <p className="mb-3 rounded border border-slate-200 bg-slate-50 p-2 text-sm">
                                {chain.message}
                            </p>
                        )}
                        <ol className="grid gap-1">
                            {chain.nodes.map((node, index) => (
                                <li key={index} className="grid gap-1">
                                    {index > 0 && (
                                        <ArrowDown className="mx-auto size-4 text-slate-400" />
                                    )}
                                    <div className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
                                        <p className="text-[11px] font-bold tracking-wide text-[#640000] uppercase">
                                            {node.kind}
                                        </p>
                                        <p className="font-semibold">
                                            {node.href ? (
                                                <a
                                                    href={node.href}
                                                    className="text-blue-700 hover:underline"
                                                >
                                                    {node.label}
                                                </a>
                                            ) : (
                                                node.label
                                            )}
                                        </p>
                                        <p className="text-xs text-[#526b92]">
                                            {[
                                                node.date &&
                                                    formatDate(node.date),
                                                node.amount_cents !== null &&
                                                    amount(node.amount_cents),
                                                node.status,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </Card>
                )}
            </Shell>
        </>
    );
}
