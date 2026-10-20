import { Head, Link } from '@inertiajs/react';
import { FilePlus2 } from 'lucide-react';
import { useState } from 'react';
import {
    Pager,
    Stat,
    felLabel,
    felTone,
    selectClass,
    useServerFilters,
} from '@/components/essential/kit';
import type { EssentialContext, Paged } from '@/components/essential/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    primaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    essential: EssentialContext;
    invoices: {
        rows: {
            id: number;
            document_type: string;
            number: string;
            issued_on: string;
            status: string;
            total_cents: number;
            receiver: string;
            company: string;
            href: string;
        }[];
        summary: Record<string, { total: number; cents: number }>;
        companies: { id: number; name: string; public_id: string }[];
    } & Paged;
    filters: Record<string, string>;
};

const types: Record<string, string> = {
    FACT: 'Factura',
    FCAM: 'Factura cambiaria',
    FPEQ: 'Pequeño contribuyente',
    NCRE: 'Nota de crédito',
    NDEB: 'Nota de débito',
};

export default function EssentialInvoicing({
    essential,
    invoices,
    filters,
}: Props) {
    const apply = useServerFilters('/essential/invoicing');
    const [picking, setPicking] = useState(false);
    const s = invoices.summary;

    return (
        <>
            <Head title="Facturación" />
            <Shell>
                <PageHeader
                    title="Facturación"
                    subtitle={
                        essential.owner
                            ? 'Todas las facturas de tus clientes: las preparas aquí, las emites en el portal de la SAT y las registras con su XML'
                            : 'Las facturas de los clientes que tienes asignados'
                    }
                    actions={
                        <button
                            type="button"
                            className={primaryButton}
                            onClick={() => setPicking(true)}
                        >
                            <FilePlus2 className="size-4" /> Nueva factura
                        </button>
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        label="Por emitir en SAT"
                        value={s.awaiting_sat?.total ?? 0}
                        hint={money(s.awaiting_sat?.cents ?? 0)}
                        tone={
                            (s.awaiting_sat?.total ?? 0) > 0 ? 'amber' : 'slate'
                        }
                        href="/essential/invoicing?status=awaiting_sat"
                    />
                    <Stat
                        label="Borradores"
                        value={s.draft?.total ?? 0}
                        href="/essential/invoicing?status=draft"
                    />
                    <Stat
                        label="Emitidas"
                        value={s.certified?.total ?? 0}
                        hint={money(s.certified?.cents ?? 0)}
                        tone="green"
                        href="/essential/invoicing?status=certified"
                    />
                    <Stat
                        label="Con error o anuladas"
                        value={
                            (s.failed?.total ?? 0) + (s.cancelled?.total ?? 0)
                        }
                        tone={(s.failed?.total ?? 0) > 0 ? 'red' : 'slate'}
                    />
                </section>

                <Card
                    title={`${invoices.total} documento(s)`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label="Estado"
                                className={selectClass}
                                value={filters.status ?? 'all'}
                                onChange={(event) =>
                                    apply({ status: event.target.value })
                                }
                            >
                                <option value="all">Todos los estados</option>
                                <option value="awaiting_sat">
                                    Pendientes de emitir en SAT
                                </option>
                                <option value="draft">Borradores</option>
                                <option value="certified">Emitidas</option>
                                <option value="failed">Con error</option>
                                <option value="cancelled">Anuladas</option>
                            </select>
                            <select
                                aria-label="Cliente"
                                className={selectClass}
                                value={filters.company_id ?? ''}
                                onChange={(event) =>
                                    apply({
                                        company_id: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Todos los clientes</option>
                                {invoices.companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Documento',
                            'Cliente (emisor)',
                            'Receptor',
                            'Fecha',
                            'Total',
                            'Estado',
                        ]}
                        empty={
                            invoices.rows.length === 0 &&
                            'Aún no hay facturas con estos filtros.'
                        }
                    >
                        {invoices.rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={row.href}
                                        className="font-bold hover:text-[#640000]"
                                    >
                                        {row.number}
                                    </Link>
                                    <p className="text-[11px] text-slate-500">
                                        {types[row.document_type] ??
                                            row.document_type}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{row.company}</td>
                                <td className="px-2 py-2">{row.receiver}</td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(row.issued_on)}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {money(row.total_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            felTone[row.status] ?? 'slate',
                                        )}
                                    >
                                        {felLabel[row.status] ?? row.status}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <Pager
                        paged={invoices}
                        onPage={(page) => apply({ page })}
                    />
                </Card>
            </Shell>

            <Dialog open={picking} onOpenChange={setPicking}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Nueva factura</DialogTitle>
                        <DialogDescription>
                            Elige el cliente que emite la factura.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-80 space-y-1.5 overflow-y-auto">
                        {invoices.companies.length === 0 && (
                            <p className="text-sm text-slate-500">
                                Todavía no tienes clientes asignados.
                            </p>
                        )}
                        {invoices.companies.map((company) => (
                            <Link
                                key={company.id}
                                href={`/accounting/companies/${company.public_id}/fel/create`}
                                className="block rounded-lg border border-slate-200 p-3 text-sm font-semibold hover:border-[#c9a36a]"
                            >
                                {company.name}
                            </Link>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
