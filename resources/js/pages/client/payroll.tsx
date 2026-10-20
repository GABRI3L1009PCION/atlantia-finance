import { Link } from '@inertiajs/react';
import { CalendarClock, Users, Wallet } from 'lucide-react';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
    requestTone,
    toneBadge,
} from '@/components/client-portal/kit';
import type { ClientRequest } from '@/components/client-portal/kit';
import { money } from '@/components/supervisor/kit';

type Period = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    payment_date: string;
    status: string;
    status_label: string;
    employees: number;
    net_cents: number;
    cost_cents: number;
    paid: boolean;
};

type Payroll = {
    periods: Period[];
    next: Period | null;
    totals: {
        employees: number;
        paid_year_cents: number;
        open_periods: number;
    };
};

const periodTone: Record<string, string> = {
    open: 'amber',
    calculated: 'blue',
    in_review: 'blue',
    submitted: 'blue',
    approved: 'green',
    paid: 'green',
    closed: 'slate',
};

export default function ClientPayroll({
    payroll,
    requests,
}: {
    payroll: Payroll;
    requests: ClientRequest[];
}) {
    const open = requests.filter((row) =>
        ['pending', 'in_progress', 'more_information_required'].includes(
            row.status,
        ),
    );

    return (
        <ClientPage
            title="Nómina"
            subtitle="Los períodos que tu oficina procesa, cuánto debes fondear y lo que te pide para calcularlos"
        >
            <section className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-xl border border-[#ebe5e1] bg-white p-3 shadow-sm">
                    <p className="flex items-center justify-between text-xs font-bold tracking-wide text-[#526b92] uppercase">
                        Colaboradores{' '}
                        <Users className="size-5 text-[#640000]" />
                    </p>
                    <p className="mt-2 text-2xl font-black text-[#061633]">
                        {payroll.totals.employees}
                    </p>
                </div>
                <div className="rounded-xl border border-[#ebe5e1] bg-white p-3 shadow-sm">
                    <p className="flex items-center justify-between text-xs font-bold tracking-wide text-[#526b92] uppercase">
                        Pagado en el año{' '}
                        <Wallet className="size-5 text-emerald-600" />
                    </p>
                    <p className="mt-2 text-2xl font-black text-[#061633] tabular-nums">
                        {money(payroll.totals.paid_year_cents)}
                    </p>
                </div>
                <div className="rounded-xl border border-[#ebe5e1] bg-white p-3 shadow-sm">
                    <p className="flex items-center justify-between text-xs font-bold tracking-wide text-[#526b92] uppercase">
                        Próximo pago{' '}
                        <CalendarClock className="size-5 text-amber-600" />
                    </p>
                    <p className="mt-2 text-2xl font-black text-[#061633]">
                        {payroll.next
                            ? formatDate(payroll.next.payment_date)
                            : '—'}
                    </p>
                    {payroll.next && (
                        <p className="text-xs text-[#526b92]">
                            {payroll.next.name} · fondear{' '}
                            {money(payroll.next.net_cents)}
                        </p>
                    )}
                </div>
            </section>

            {open.length > 0 && (
                <Card
                    title={`Tu oficina te pide (${open.length})`}
                    action={
                        <Link
                            href="/client/document-requests"
                            className="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-[#640000]"
                        >
                            Responder
                        </Link>
                    }
                >
                    <Table headers={['Solicitud', 'Vence', 'Estado']}>
                        {open.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-2 font-bold">
                                    {row.title}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(row.due_date)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            requestTone[row.status] ?? 'slate',
                                        )}
                                    >
                                        {row.status_label}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            <Card title="Períodos de nómina">
                <Table
                    headers={[
                        'Período',
                        'Del',
                        'Al',
                        'Pago',
                        'Colaboradores',
                        'Neto a pagar',
                        'Costo para la empresa',
                        'Estado',
                    ]}
                    empty={
                        payroll.periods.length === 0 &&
                        'Tu oficina aún no ha abierto períodos de nómina.'
                    }
                >
                    {payroll.periods.map((period) => (
                        <tr key={period.id}>
                            <td className="px-2 py-2 font-bold">
                                {period.name}
                            </td>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(period.starts_on)}
                            </td>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(period.ends_on)}
                            </td>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(period.payment_date)}
                            </td>
                            <td className="px-2 py-2 text-right">
                                {period.employees}
                            </td>
                            <td className="px-2 py-2 text-right font-black tabular-nums">
                                {money(period.net_cents)}
                            </td>
                            <td className="px-2 py-2 text-right tabular-nums">
                                {money(period.cost_cents)}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        periodTone[period.status] ?? 'slate',
                                    )}
                                >
                                    {period.status_label}
                                </span>
                            </td>
                        </tr>
                    ))}
                </Table>
                <p className="mt-2 text-xs text-[#526b92]">
                    Por privacidad, aquí no se muestran sueldos individuales. Tu
                    oficina te entrega los recibos y reportes por separado.
                </p>
            </Card>
        </ClientPage>
    );
}
