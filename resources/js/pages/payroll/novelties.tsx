import { Head, router, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import {
    PeriodPicker,
    amount,
    noveltyTone,
    usePayrollHref,
} from '@/components/payroll/kit';
import type {
    Novelty,
    PayrollContext,
    PayrollPeriod,
    PayrollPermissions,
} from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Concept = {
    code: string;
    name: string;
    measure: string;
    unit: string | null;
};

export default function PayrollNovelties({
    novelties,
    periods,
    periodId,
    status,
    concepts,
    employees,
    permissions,
    notice,
}: {
    context: PayrollContext;
    novelties: Novelty[];
    periods: PayrollPeriod[];
    periodId: number | null;
    status: string;
    concepts: Concept[];
    employees: { id: number; company_id: number; name: string; code: string }[];
    permissions: PayrollPermissions;
    notice: string | null;
}) {
    const href = usePayrollHref();
    const period = periods.find((row) => row.id === periodId) ?? null;
    const form = useForm({
        period_id: periodId ? String(periodId) : '',
        payroll_employee_id: '',
        concept_code: concepts[0]?.code ?? '',
        quantity: '',
        amount: '',
        description: '',
        confirm_duplicate: false,
    });
    const concept = concepts.find((row) => row.code === form.data.concept_code);
    const review = (id: number, action: string) => {
        const note =
            action === 'reject' ? window.prompt('Motivo del rechazo') : null;

        if (action === 'reject' && !note) {
            return;
        }

        router.post(
            `/payroll/novelties/${id}/${action}`,
            note ? { note } : {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Novedades de nómina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Novedades"
                    subtitle="Horas extra, comisiones, bonos, ausencias y descuentos del período; se aprueban antes del cálculo"
                />
                <Card title="Período">
                    <div className="flex flex-wrap items-end gap-2">
                        <PeriodPicker
                            periods={periods}
                            periodId={periodId}
                            path="/payroll/novelties"
                            allowAll
                        />
                        <label className="grid gap-1 text-xs font-semibold">
                            Estado
                            <select
                                className={input}
                                value={status}
                                onChange={(event) =>
                                    router.get(
                                        href('/payroll/novelties'),
                                        {
                                            ...(periodId
                                                ? { period_id: periodId }
                                                : {}),
                                            status: event.target.value,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value="">Todas</option>
                                <option value="pending">Pendientes</option>
                                <option value="approved">Aprobadas</option>
                                <option value="rejected">Rechazadas</option>
                            </select>
                        </label>
                    </div>
                </Card>
                {permissions.canManageNovelties && period && (
                    <Card
                        title={`Registrar novedad · ${period.company} · ${period.name}`}
                    >
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/payroll/novelties', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset(
                                            'quantity',
                                            'amount',
                                            'description',
                                            'confirm_duplicate',
                                        ),
                                });
                            }}
                        >
                            <label className="grid min-w-56 gap-1 text-xs font-semibold">
                                Empleado
                                <select
                                    className={input}
                                    value={form.data.payroll_employee_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'payroll_employee_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {employees
                                        .filter(
                                            (employee) =>
                                                employee.company_id ===
                                                period.company_id,
                                        )
                                        .map((employee) => (
                                            <option
                                                key={employee.id}
                                                value={employee.id}
                                            >
                                                {employee.name} ·{' '}
                                                {employee.code}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold">
                                Concepto
                                <select
                                    className={input}
                                    value={form.data.concept_code}
                                    onChange={(event) =>
                                        form.setData(
                                            'concept_code',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {concepts.map((row) => (
                                        <option key={row.code} value={row.code}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {concept?.measure === 'quantity' ? (
                                <label className="grid w-32 gap-1 text-xs font-semibold">
                                    Cantidad ({concept.unit})
                                    <input
                                        type="number"
                                        step="0.5"
                                        min="0"
                                        className={input}
                                        value={form.data.quantity}
                                        onChange={(event) =>
                                            form.setData(
                                                'quantity',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                            ) : (
                                <label className="grid w-36 gap-1 text-xs font-semibold">
                                    Monto (Q)
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className={input}
                                        value={form.data.amount}
                                        onChange={(event) =>
                                            form.setData(
                                                'amount',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                            )}
                            <label className="grid min-w-56 flex-1 gap-1 text-xs font-semibold">
                                Descripción
                                <input
                                    className={input}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            {form.errors.concept_code?.includes(
                                'Ya existe',
                            ) && (
                                <label className="flex items-center gap-2 text-xs font-semibold text-amber-800">
                                    <input
                                        type="checkbox"
                                        checked={form.data.confirm_duplicate}
                                        onChange={(event) =>
                                            form.setData(
                                                'confirm_duplicate',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Es intencional
                                </label>
                            )}
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Registrar
                            </button>
                        </form>
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Las horas extra se valoran con el salario del
                            empleado y el recargo legal vigente al calcular; la
                            novedad solo registra la cantidad.
                        </p>
                    </Card>
                )}
                <Card title={`${novelties.length} novedad(es)`}>
                    <Table
                        headers={[
                            'Empleado',
                            'Nómina',
                            'Concepto',
                            'Cantidad / monto',
                            'Registrada',
                            'Estado',
                            'Acciones',
                        ]}
                        empty={
                            novelties.length === 0 &&
                            'No hay novedades con estos filtros.'
                        }
                    >
                        {novelties.map((novelty) => (
                            <tr key={novelty.id}>
                                <td className="px-2 py-2 font-bold">
                                    {novelty.employee}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {novelty.company}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{novelty.period}</td>
                                <td className="px-2 py-2">
                                    {novelty.concept}
                                    {novelty.description && (
                                        <p className="text-[11px] text-[#526b92]">
                                            {novelty.description}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {novelty.measure === 'quantity'
                                        ? `${novelty.quantity} ${novelty.unit ?? ''}`
                                        : amount(novelty.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(novelty.created_at)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            noveltyTone[novelty.status] ??
                                                'slate',
                                        )}
                                    >
                                        {novelty.status_label}
                                    </span>
                                    {novelty.review_note && (
                                        <p className="text-[11px] text-[#526b92]">
                                            {novelty.review_note}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {permissions.canManageNovelties &&
                                        novelty.editable && (
                                            <div className="flex gap-1">
                                                {novelty.status ===
                                                    'pending' && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            className="rounded-md bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white"
                                                            onClick={() =>
                                                                review(
                                                                    novelty.id,
                                                                    'approve',
                                                                )
                                                            }
                                                        >
                                                            Aprobar
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="rounded-md border border-red-300 px-2 py-1 text-[11px] font-bold text-red-700"
                                                            onClick={() =>
                                                                review(
                                                                    novelty.id,
                                                                    'reject',
                                                                )
                                                            }
                                                        >
                                                            Rechazar
                                                        </button>
                                                    </>
                                                )}
                                                <button
                                                    type="button"
                                                    className="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-bold"
                                                    onClick={() =>
                                                        window.confirm(
                                                            '¿Eliminar la novedad?',
                                                        ) &&
                                                        review(
                                                            novelty.id,
                                                            'delete',
                                                        )
                                                    }
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
