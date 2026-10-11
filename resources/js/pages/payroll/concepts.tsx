import { Head, router, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import { usePayrollHref } from '@/components/payroll/kit';
import type { PayrollContext } from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Concept = {
    id: number;
    code: string;
    name: string;
    type: string;
    affects_igss: boolean;
    affects_isr: boolean;
    is_system: boolean;
    active: boolean;
};

const typeLabel: Record<string, string> = {
    earning: 'Ingreso',
    deduction: 'Descuento',
    employer: 'Aporte empresa',
    provision: 'Provisión',
};

const accountLabels: Record<string, string> = {
    salaries_expense_id: 'Gasto de sueldos y salarios',
    employer_expense_id: 'Gasto de cuotas patronales',
    benefits_expense_id: 'Gasto de prestaciones',
    salaries_payable_id: 'Sueldos por pagar',
    social_security_payable_id: 'IGSS, IRTRA e INTECAP por pagar',
    isr_payable_id: 'ISR retenido por pagar',
    provisions_id: 'Provisiones laborales',
    other_deductions_payable_id: 'Otros descuentos por pagar',
    employee_receivables_id: 'Anticipos y préstamos a empleados',
};

export default function PayrollConcepts({
    context,
    companyId,
    concepts,
    mapping,
    accounts,
    canManage,
    notice,
}: {
    context: PayrollContext;
    companyId: number | null;
    concepts: Concept[];
    mapping: Record<string, number> | null;
    accounts: { id: number; label: string; type: string }[];
    canManage: boolean;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const form = useForm<Record<string, string>>({
        company_id: companyId ? String(companyId) : '',
        ...Object.fromEntries(
            Object.keys(accountLabels).map((key) => [
                key,
                mapping?.[key] ? String(mapping[key]) : '',
            ]),
        ),
    });

    return (
        <>
            <Head title="Conceptos de nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Conceptos de nómina"
                    subtitle="Conceptos legales con su tratamiento IGSS e ISR y las cuentas contables que usa el asiento de nómina"
                    actions={
                        <select
                            className={`${input} w-56`}
                            value={companyId ?? ''}
                            onChange={(event) =>
                                router.get(href('/payroll/concepts'), {
                                    company_id: event.target.value,
                                })
                            }
                            aria-label="Empresa"
                        >
                            {context.companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    }
                />
                <Card title={`${concepts.length} concepto(s)`}>
                    <Table
                        headers={[
                            'Código',
                            'Concepto',
                            'Tipo',
                            'Afecto IGSS',
                            'Afecto ISR',
                            'Origen',
                            'Estado',
                        ]}
                        empty={
                            concepts.length === 0 && 'Selecciona una empresa.'
                        }
                    >
                        {concepts.map((concept) => (
                            <tr key={concept.id}>
                                <td className="px-2 py-2 font-mono">
                                    {concept.code}
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {concept.name}
                                </td>
                                <td className="px-2 py-2">
                                    {typeLabel[concept.type] ?? concept.type}
                                </td>
                                <td className="px-2 py-2">
                                    {concept.type === 'earning'
                                        ? concept.affects_igss
                                            ? 'Sí'
                                            : 'No'
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {concept.type === 'earning'
                                        ? concept.affects_isr
                                            ? 'Sí'
                                            : 'No'
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {concept.is_system
                                        ? 'Legal (sistema)'
                                        : 'Propio'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            concept.active ? 'green' : 'slate',
                                        )}
                                    >
                                        {concept.active ? 'Activo' : 'Inactivo'}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        La bonificación incentivo (Decreto 37-2001) no es base
                        de IGSS; las tasas y montos provienen de los parámetros
                        legales vigentes.
                    </p>
                </Card>
                {mapping && (
                    <Card title="Cuentas contables de nómina">
                        <form
                            className="grid gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.put('/payroll/concepts/accounts', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {Object.entries(accountLabels).map(
                                    ([key, label]) => (
                                        <label
                                            key={key}
                                            className="grid gap-1 text-xs font-semibold"
                                        >
                                            {label}
                                            <select
                                                className={input}
                                                value={form.data[key]}
                                                disabled={!canManage}
                                                onChange={(event) =>
                                                    form.setData(
                                                        key,
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                {accounts.map((account) => (
                                                    <option
                                                        key={account.id}
                                                        value={account.id}
                                                    >
                                                        {account.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    ),
                                )}
                            </div>
                            {canManage && (
                                <div>
                                    <button
                                        type="submit"
                                        className={primaryButton}
                                        disabled={form.processing}
                                    >
                                        Guardar cuentas
                                    </button>
                                </div>
                            )}
                        </form>
                    </Card>
                )}
            </Shell>
        </>
    );
}
