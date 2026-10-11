import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import type { TaxContext } from '@/components/tax/kit';

type Row = {
    company: string;
    status: string;
    message: string | null;
    lines: {
        concept: string;
        source_cents: number;
        ledger_cents: number;
        difference_cents: number;
        account: string | null;
    }[];
    items: {
        document: string;
        name: string;
        source: string;
        vat_cents: number;
        issue: string;
    }[];
    destination: string | null;
};

const statusLabel: Record<string, [string, string]> = {
    MATCHED: ['Conciliado', 'green'],
    DIFFERENCE: ['Con diferencia', 'red'],
    PENDING: ['Pendiente de configurar', 'amber'],
    ERROR: ['No fue posible calcular', 'violet'],
    NOT_APPLICABLE: ['No aplica', 'slate'],
};

export default function TaxReconciliation({
    context,
    rows,
}: {
    context: TaxContext;
    rows: Row[];
}) {
    return (
        <>
            <Head title="Conciliación fiscal-contable" />
            <Shell>
                <PageHeader
                    title="Conciliación fiscal-contable"
                    subtitle={`IVA según libros contra las cuentas de IVA del mayor configuradas por empresa · ${context.period?.name ?? 'Sin período'}`}
                />
                <p className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <AlertTriangle className="size-4" /> No existe “cuadrar
                    automáticamente”: la diferencia se explica con sus
                    documentos y se corrige en el origen.
                </p>
                {rows.length === 0 && (
                    <Card title="Sin empresas">
                        <p className="text-sm text-slate-500">
                            No tienes empresas asignadas para gestión fiscal.
                        </p>
                    </Card>
                )}
                {rows.map((row) => {
                    const [label, tone] = statusLabel[row.status] ?? [
                        row.status,
                        'slate',
                    ];

                    return (
                        <Card
                            key={row.company}
                            title={row.company}
                            action={
                                <div className="flex items-center gap-2">
                                    <span className={toneBadge(tone)}>
                                        {label}
                                    </span>
                                    {row.destination && (
                                        <a
                                            href={row.destination}
                                            className={secondaryButton}
                                        >
                                            Abrir libro
                                        </a>
                                    )}
                                </div>
                            }
                        >
                            {row.message && (
                                <p className="mb-2 text-sm text-[#526b92]">
                                    {row.message}
                                </p>
                            )}
                            {row.lines.length > 0 && (
                                <Table
                                    headers={[
                                        'Concepto',
                                        'Cuenta',
                                        'Según libros',
                                        'Según mayor',
                                        'Diferencia',
                                    ]}
                                >
                                    {row.lines.map((line) => (
                                        <tr key={line.concept}>
                                            <td className="px-2 py-2 font-bold">
                                                {line.concept}
                                            </td>
                                            <td className="px-2 py-2">
                                                {line.account ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {money(line.source_cents)}
                                            </td>
                                            <td className="px-2 py-2">
                                                {money(line.ledger_cents)}
                                            </td>
                                            <td
                                                className={`px-2 py-2 font-bold ${line.difference_cents !== 0 ? 'text-red-700' : 'text-emerald-700'}`}
                                            >
                                                {money(line.difference_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </Table>
                            )}
                            {row.items.length > 0 && (
                                <div className="mt-3">
                                    <p className="mb-1 text-xs font-bold text-[#526b92] uppercase">
                                        Documentos que explican la diferencia
                                    </p>
                                    <Table
                                        headers={[
                                            'Documento',
                                            'Tercero',
                                            'Origen',
                                            'IVA',
                                            'Motivo',
                                        ]}
                                    >
                                        {row.items.map((item, index) => (
                                            <tr key={index}>
                                                <td className="px-2 py-2">
                                                    {item.document || '—'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {item.name}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {item.source}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {money(item.vat_cents)}
                                                </td>
                                                <td className="px-2 py-2 text-red-700">
                                                    {item.issue}
                                                </td>
                                            </tr>
                                        ))}
                                    </Table>
                                </div>
                            )}
                        </Card>
                    );
                })}
            </Shell>
        </>
    );
}
