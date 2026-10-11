import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    companyTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    TreasuryCompany,
    TreasuryContext,
} from '@/components/treasury/kit';

export default function TreasuryCompanies({
    context,
    companies,
}: {
    context: TreasuryContext;
    companies: TreasuryCompany[];
}) {
    const href = useTreasuryHref();

    return (
        <>
            <Head title="Mis empresas · Tesorería" />
            <Shell>
                <PageHeader
                    title="Mis empresas"
                    subtitle={`Estado de tesorería por empresa al corte ${formatDate(companies[0]?.cutoff ?? null)} · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card title={`${companies.length} empresa(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Cuentas',
                            'Conciliadas al corte',
                            'Saldo bancario',
                            'Saldo contable',
                            'Pendientes',
                            'Cobros sin aplicar',
                            'Pagos por aprobar',
                            'Estado',
                            '',
                        ]}
                        empty={
                            companies.length === 0 &&
                            'No tienes empresas asignadas para tesorería.'
                        }
                    >
                        {companies.map((company) => (
                            <tr key={company.id}>
                                <td className="px-2 py-2 font-bold">
                                    {company.name}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        NIT {company.tax_id}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {company.accounts}
                                </td>
                                <td className="px-2 py-2">
                                    {company.reconciled_accounts === null
                                        ? 'No disponible'
                                        : `${company.reconciled_accounts} / ${company.accounts}`}
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {company.accounts === 0
                                        ? '—'
                                        : amount(company.bank_balance_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {company.accounts === 0
                                        ? '—'
                                        : amount(company.ledger_balance_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {company.pending_movements ??
                                        'No disponible'}
                                </td>
                                <td className="px-2 py-2">
                                    {company.unapplied_receipts}
                                </td>
                                <td className="px-2 py-2">
                                    {company.payments_to_approve}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            companyTone[company.status] ??
                                                'slate',
                                        )}
                                        title={company.error ?? undefined}
                                    >
                                        {company.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex gap-1">
                                        <Link
                                            href={href(company.destination)}
                                            className={secondaryButton}
                                        >
                                            Detalle
                                        </Link>
                                        <a
                                            href={company.company_dashboard}
                                            className={secondaryButton}
                                        >
                                            Empresa
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
