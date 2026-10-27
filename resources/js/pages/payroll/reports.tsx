import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    PageHeader,
    Shell,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import type {
    PayrollContext,
    PayrollPeriod,
    PayrollPermissions,
} from '@/components/payroll/kit';
import { Card } from '@/components/supervisor/kit';

const legalReports: [string, string, string][] = [
    [
        'salary_book',
        'Libro de salarios',
        'Un folio por trabajador con sus meses del año de la nómina elegida (formato único del Ministerio de Trabajo). El PDF numera los folios.',
    ],
    [
        'igss_planilla',
        'Planilla del IGSS',
        'Por trabajador del mes de la nómina elegida: afiliación, salario afecto y cuotas laboral, patronal, IRTRA e INTECAP.',
    ],
];

const reports: [string, string, string, boolean][] = [
    [
        'companies',
        'Nómina por empresa',
        'Empleados, período en curso, novedades y neto por empresa.',
        false,
    ],
    [
        'payroll',
        'Planilla de nómina',
        'Devengado, descuentos y líquido por empleado (requiere permiso de salarios).',
        true,
    ],
    [
        'igss',
        'Cuotas IGSS, IRTRA e INTECAP',
        'Salario afecto y cuotas laborales y patronales de nóminas aprobadas.',
        true,
    ],
    [
        'isr',
        'Retenciones de ISR',
        'ISR en relación de dependencia retenido por empleado (insumo para Fiscal).',
        true,
    ],
    [
        'payments',
        'Órdenes de pago',
        'Estado de cada pago a empleados y su referencia bancaria.',
        true,
    ],
    [
        'reconciliation',
        'Nómina vs Mayor',
        'Conciliación de sueldos por pagar por empresa.',
        false,
    ],
];

export default function PayrollReports({
    periods,
    permissions,
}: {
    context: PayrollContext;
    periods: PayrollPeriod[];
    permissions: PayrollPermissions;
}) {
    const [period, setPeriod] = useState('');
    const url = (report: string, format: string, byPeriod: boolean) =>
        `/payroll/reports/${report}?format=${format}${byPeriod && period ? `&period_id=${period}` : ''}`;

    return (
        <>
            <Head title="Reportes de nómina" />
            <Shell>
                <PageHeader
                    title="Reportes"
                    subtitle="Exportaciones en Excel o PDF; los montos se presentan por empresa y período"
                />
                <Card title="Filtro">
                    <label className="grid max-w-md gap-1 text-xs font-semibold">
                        Nómina (opcional)
                        <select
                            className={input}
                            value={period}
                            onChange={(event) => setPeriod(event.target.value)}
                        >
                            <option value="">
                                Todas las nóminas aprobadas
                            </option>
                            {periods.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.company} · {row.name} ·{' '}
                                    {row.status_label}
                                </option>
                            ))}
                        </select>
                    </label>
                </Card>
                {!permissions.canViewReports ? (
                    <p className="rounded-lg border border-slate-200 bg-white p-4 text-sm">
                        No tienes permiso para exportar reportes de nómina.
                    </p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {reports.map(([key, title, description, byPeriod]) => (
                            <Card key={key} title={title}>
                                <p className="mb-3 text-xs text-[#526b92]">
                                    {description}
                                </p>
                                <div className="flex gap-2">
                                    <a
                                        href={url(key, 'xlsx', byPeriod)}
                                        className={secondaryButton}
                                    >
                                        Excel
                                    </a>
                                    <a
                                        href={url(key, 'pdf', byPeriod)}
                                        className={secondaryButton}
                                    >
                                        PDF
                                    </a>
                                </div>
                            </Card>
                        ))}
                        {permissions.canViewSalary &&
                            legalReports.map(([key, title, description]) => (
                                <Card key={key} title={title}>
                                    <p className="mb-3 text-xs text-[#526b92]">
                                        {description}
                                    </p>
                                    {period ? (
                                        <div className="flex gap-2">
                                            <a
                                                href={url(key, 'xlsx', true)}
                                                className={secondaryButton}
                                            >
                                                Excel
                                            </a>
                                            <a
                                                href={url(key, 'pdf', true)}
                                                className={secondaryButton}
                                            >
                                                PDF
                                            </a>
                                        </div>
                                    ) : (
                                        <p className="text-xs font-semibold text-amber-700">
                                            Elige una nómina arriba para
                                            generarlo.
                                        </p>
                                    )}
                                </Card>
                            ))}
                    </div>
                )}
            </Shell>
        </>
    );
}
