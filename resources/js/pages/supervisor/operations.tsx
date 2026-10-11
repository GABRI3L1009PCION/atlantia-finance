import { Head } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import type { SupervisorContext } from '@/components/supervisor/kit';

type OpenItem = {
    id: number;
    company: string;
    party: string;
    document: string;
    date: string | null;
    due_on: string;
    balance_cents: number;
    status: string;
    status_label: string;
    destination: string;
};
type Open = { rows: OpenItem[]; summary: Record<string, number> };
type Bank = {
    id: number;
    company: string;
    bank: string;
    bank_balance_cents: number;
    ledger_balance_cents: number;
    difference_cents: number;
    pending: number;
    status: string;
    status_label: string;
    destination: string;
};
type Accounting = {
    id: number;
    name: string;
    draft: number;
    pending_review: number;
    approved: number;
    posted: number;
    books: string;
    reports: string;
};
type Documents = {
    files: {
        id: number;
        category: string;
        name: string;
        company: string;
        received_at: string;
        status: string;
        status_label: string;
    }[];
    stats: {
        type: string;
        received: number;
        processed: number;
        pending: number;
        rejected: number;
    }[];
};

const titles: Record<string, [string, string]> = {
    accounting: [
        'Contabilidad',
        'Pólizas del período por empresa: revisa, observa y aprueba desde el libro diario de cada empresa.',
    ],
    receivables: [
        'Cuentas por cobrar',
        'Saldos abiertos de clientes de tus empresas.',
    ],
    payables: [
        'Cuentas por pagar',
        'Facturas de proveedores pendientes de pago.',
    ],
    banks: [
        'Bancos',
        'Saldo bancario contra saldo contable y movimientos sin conciliar.',
    ],
    documents: [
        'Documentos',
        'Repositorio de documentos recibidos por empresa.',
    ],
};

const statusTone = (status: string) =>
    (
        ({
            overdue: 'red',
            partial: 'amber',
            paid: 'green',
            pending: 'slate',
            reconciled: 'green',
            difference: 'red',
            in_progress: 'blue',
            processed: 'green',
            duplicate: 'violet',
            rejected: 'red',
        }) as Record<string, string>
    )[status] ?? 'amber';

export default function SupervisorOperations({
    area,
    data,
}: {
    context: SupervisorContext;
    area: string;
    data: unknown;
}) {
    const [title, subtitle] = titles[area];
    const openTable = (open: Open, party: string) => (
        <>
            <section className="grid gap-3 sm:grid-cols-4">
                {[
                    ['Total por cobrar/pagar', open.summary.total_cents, ''],
                    ['Vencido', open.summary.overdue_cents, 'text-red-700'],
                    [
                        'Próximo a vencer (7 días)',
                        open.summary.due_soon_cents,
                        'text-amber-700',
                    ],
                    [
                        'Recuperado / pagado este mes',
                        open.summary.settled_cents,
                        'text-emerald-700',
                    ],
                ].map(([label, value, tone]) => (
                    <div
                        key={label as string}
                        className="rounded-xl border border-[#e7e1dd] bg-white p-3"
                    >
                        <p className="text-xs text-[#526b92]">{label}</p>
                        <p className={`text-xl font-black ${tone}`}>
                            {money(value as number)}
                        </p>
                    </div>
                ))}
            </section>
            <Card title={`${open.rows.length} documento(s)`}>
                <Table
                    headers={[
                        'Empresa',
                        party,
                        'Documento',
                        'Fecha',
                        'Vencimiento',
                        'Saldo',
                        'Estado',
                    ]}
                    empty={open.rows.length === 0 && 'Sin saldos abiertos.'}
                >
                    {open.rows.map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2">{row.company}</td>
                            <td className="px-2 py-2">{row.party}</td>
                            <td className="px-2 py-2">
                                <a
                                    href={row.destination}
                                    className="font-bold hover:text-[#640000]"
                                >
                                    {row.document}
                                </a>
                            </td>
                            <td className="px-2 py-2">
                                {formatDate(row.date)}
                            </td>
                            <td className="px-2 py-2">
                                {formatDate(row.due_on)}
                            </td>
                            <td className="px-2 py-2 font-bold">
                                {money(row.balance_cents)}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        statusTone(row.status),
                                    )}
                                >
                                    {row.status_label}
                                </span>
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </>
    );

    return (
        <>
            <Head title={title} />
            <Shell>
                <PageHeader title={title} subtitle={subtitle} />
                {area === 'accounting' && (
                    <Card title="Pólizas por empresa">
                        <Table
                            headers={[
                                'Empresa',
                                'Borradores',
                                'En revisión',
                                'Aprobadas',
                                'Contabilizadas',
                                'Acciones',
                            ]}
                            empty={
                                (data as Accounting[]).length === 0 &&
                                'Sin empresas.'
                            }
                        >
                            {(data as Accounting[]).map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {row.name}
                                    </td>
                                    <td className="px-2 py-2">{row.draft}</td>
                                    <td
                                        className={`px-2 py-2 ${row.pending_review > 0 ? 'font-bold text-amber-700' : ''}`}
                                    >
                                        {row.pending_review}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.approved}
                                    </td>
                                    <td className="px-2 py-2">{row.posted}</td>
                                    <td className="px-2 py-2">
                                        <div className="flex gap-1.5">
                                            <a
                                                href={row.books}
                                                className={secondaryButton}
                                            >
                                                <ExternalLink className="size-3" />{' '}
                                                Diario y mayor
                                            </a>
                                            <a
                                                href={row.reports}
                                                className={secondaryButton}
                                            >
                                                Balance
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {area === 'receivables' && openTable(data as Open, 'Cliente')}
                {area === 'payables' && openTable(data as Open, 'Proveedor')}
                {area === 'banks' && (
                    <Card title="Cuentas bancarias">
                        <Table
                            headers={[
                                'Empresa',
                                'Cuenta',
                                'Saldo banco',
                                'Saldo contable',
                                'Diferencia',
                                'Sin conciliar',
                                'Estado',
                                '',
                            ]}
                            empty={
                                (data as Bank[]).length === 0 &&
                                'Sin cuentas bancarias.'
                            }
                        >
                            {(data as Bank[]).map((bank) => (
                                <tr key={bank.id}>
                                    <td className="px-2 py-2">
                                        {bank.company}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {bank.bank}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(bank.bank_balance_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(bank.ledger_balance_cents)}
                                    </td>
                                    <td
                                        className={`px-2 py-2 font-bold ${bank.difference_cents !== 0 ? 'text-red-700' : ''}`}
                                    >
                                        {money(bank.difference_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {bank.pending}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                statusTone(bank.status),
                                            )}
                                        >
                                            {bank.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <a
                                            href={bank.destination}
                                            className={secondaryButton}
                                        >
                                            Conciliar
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {area === 'documents' && (
                    <>
                        <section className="grid gap-3 md:grid-cols-5">
                            {(data as Documents).stats.map((row) => (
                                <div
                                    key={row.type}
                                    className="rounded-xl border border-[#e7e1dd] bg-white p-3 text-sm"
                                >
                                    <p className="font-bold">{row.type}</p>
                                    <p className="text-2xl font-black">
                                        {row.received}
                                    </p>
                                    <p className="text-xs text-[#526b92]">
                                        Procesados {row.processed} · Pendientes{' '}
                                        {row.pending} · Rechazados{' '}
                                        {row.rejected}
                                    </p>
                                </div>
                            ))}
                        </section>
                        <Card title="Repositorio">
                            <Table
                                headers={[
                                    'Documento',
                                    'Empresa',
                                    'Clasificación',
                                    'Recibido',
                                    'Estado',
                                ]}
                                empty={
                                    (data as Documents).files.length === 0 &&
                                    'Sin documentos cargados.'
                                }
                            >
                                {(data as Documents).files.map((file) => (
                                    <tr key={`${file.category}-${file.id}`}>
                                        <td className="px-2 py-2">
                                            {file.name}
                                        </td>
                                        <td className="px-2 py-2">
                                            {file.company}
                                        </td>
                                        <td className="px-2 py-2">
                                            {file.category}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(file.received_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    statusTone(file.status),
                                                )}
                                            >
                                                {file.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    </>
                )}
            </Shell>
        </>
    );
}
