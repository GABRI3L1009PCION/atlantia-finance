import { Head, Link } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import type { PayrollContext } from '@/components/payroll/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Parameter = {
    code: string;
    label: string;
    value: number;
    unit: string;
    effective_from: string;
    effective_to: string | null;
    legal_reference: string;
    own: boolean;
};

function display(parameter: Parameter): string {
    if (parameter.unit === 'rate') {
        return `${Math.round(parameter.value * 1000000) / 10000}%`;
    }

    if (parameter.unit === 'amount') {
        return `Q ${new Intl.NumberFormat('es-GT', { minimumFractionDigits: 2 }).format(parameter.value)}`;
    }

    return `${parameter.value} ${parameter.unit === 'days' ? 'día(s)' : parameter.unit}`;
}

export default function PayrollPreferences({
    parameters,
}: {
    context: PayrollContext;
    parameters: Parameter[];
}) {
    return (
        <>
            <Head title="Preferencias de nómina" />
            <Shell>
                <PageHeader
                    title="Preferencias"
                    subtitle="Parámetros legales vigentes que usa el cálculo; ningún porcentaje está fijo en las pantallas"
                    actions={
                        <Link
                            href="/settings/profile"
                            className={secondaryButton}
                        >
                            Mi perfil y notificaciones
                        </Link>
                    }
                />
                <Card title={`Parámetros vigentes hoy (${parameters.length})`}>
                    <Table
                        headers={[
                            'Parámetro',
                            'Valor',
                            'Vigencia',
                            'Fuente',
                            'Base legal',
                        ]}
                        empty={
                            parameters.length === 0 &&
                            'No hay parámetros cargados: ejecuta la sincronización de parámetros de nómina.'
                        }
                    >
                        {parameters.map((parameter) => (
                            <tr key={parameter.code}>
                                <td className="px-2 py-2 font-bold">
                                    {parameter.label}
                                    <p className="font-mono text-[10px] font-normal text-[#526b92]">
                                        {parameter.code}
                                    </p>
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {display(parameter)}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(parameter.effective_from)} –{' '}
                                    {parameter.effective_to
                                        ? formatDate(parameter.effective_to)
                                        : 'vigente'}
                                </td>
                                <td className="px-2 py-2">
                                    {parameter.own
                                        ? 'Propio de la firma'
                                        : 'Atlantia'}
                                </td>
                                <td className="max-w-md px-2 py-2 text-[11px] text-[#526b92]">
                                    {parameter.legal_reference}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        El Decreto 13-2026 reforma la deducción personal de ISR
                        a partir de 2027; su deducción extraordinaria de 2026 no
                        se aplica automáticamente hasta confirmar su mecánica.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
