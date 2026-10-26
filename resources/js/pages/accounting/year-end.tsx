import { router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BookCheck,
    CalendarCheck,
    Coins,
    Download,
    Lock,
} from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    head,
    Label,
    PageShell,
    Stat,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
} from '@/components/accounting-ui';
import { ConfirmDialog } from '@/components/office-admin/kit';

type CloseLine = {
    account_id: number;
    code: string;
    name: string;
    debit_cents: number;
    credit_cents: number;
};
type Entry = { status: string; public_id: string } | null;
type RevaluationRow = {
    side: string;
    document: string;
    party: string;
    currency: string;
    foreign_cents: number;
    book_cents: number;
    rate: string;
    revalued_cents: number;
    difference_cents: number;
};
type Print = {
    book: string;
    book_label: string;
    from: string;
    to: string;
    first_folio: number;
    last_folio: number;
    authorization: string | null;
    printed_by: string;
    printed_at: string;
};

type Props = {
    company: Company;
    years: number[];
    year: number;
    month: string;
    closing: {
        year: number;
        from: string;
        to: string;
        lines: CloseLine[];
        income_cents: number;
        expense_cents: number;
        result_cents: number;
        pending_entries: number;
        transfer: CloseLine[];
        closing: Entry;
        transfer_entry: Entry;
        blockers: string[];
    };
    revaluation: {
        as_of: string;
        rows: RevaluationRow[];
        rates: Record<string, string>;
        receivable_cents: number;
        payable_cents: number;
        net_cents: number;
        missing_rates: string[];
        entry: Entry;
    };
    books: Record<string, string>;
    nextFolios: Record<string, number>;
    history: Print[];
    canManage: boolean;
    canPrint: boolean;
    journalUrl: string;
};

const STATUS: Record<string, string> = {
    draft: 'Borrador',
    pending_review: 'En revisión',
    reviewed: 'Revisado',
    approved: 'Aprobado',
    posted: 'Contabilizado',
    reversed: 'Revertido',
};

const date = (value: string) => value.split('-').reverse().join('/');

function Lines({ lines }: { lines: CloseLine[] }) {
    const debit = lines.reduce((sum, line) => sum + line.debit_cents, 0);
    const credit = lines.reduce((sum, line) => sum + line.credit_cents, 0);

    return (
        <div className="overflow-x-auto">
            <table className="w-full">
                <thead>
                    <tr className="border-b border-slate-200">
                        <th className={head}>Cuenta</th>
                        <th className={`${head} text-right`}>Debe</th>
                        <th className={`${head} text-right`}>Haber</th>
                    </tr>
                </thead>
                <tbody>
                    {lines.map((line) => (
                        <tr
                            key={line.code}
                            className="border-b border-slate-100"
                        >
                            <td className={cell}>
                                {line.code} {line.name}
                            </td>
                            <td className={`${cell} text-right`}>
                                {line.debit_cents
                                    ? money(line.debit_cents)
                                    : ''}
                            </td>
                            <td className={`${cell} text-right`}>
                                {line.credit_cents
                                    ? money(line.credit_cents)
                                    : ''}
                            </td>
                        </tr>
                    ))}
                    <tr className="font-semibold">
                        <td className={cell}>Sumas iguales</td>
                        <td className={`${cell} text-right`}>{money(debit)}</td>
                        <td className={`${cell} text-right`}>
                            {money(credit)}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

export default function YearEnd({
    company,
    years,
    year,
    month,
    closing,
    revaluation,
    books,
    nextFolios,
    history,
    canManage,
    canPrint,
    journalUrl,
}: Props) {
    const base = `/accounting/companies/${company.public_id}`;
    const errors = usePage().props.errors as Record<string, string>;
    const [confirm, setConfirm] = useState<'close' | 'revalue' | null>(null);
    const [processing, setProcessing] = useState(false);
    const [book, setBook] = useState('diario');
    const [from, setFrom] = useState(closing.from);
    const [to, setTo] = useState(closing.to);
    const [folio, setFolio] = useState(String(nextFolios.diario ?? 1));
    const [authorization, setAuthorization] = useState('');
    const visit = (params: Record<string, string | number>) =>
        router.get(
            `${base}/year-end`,
            { year, month, ...params },
            { preserveScroll: true, preserveState: false },
        );
    const post = (url: string, data: Record<string, string | number>) =>
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirm(null);
            },
        });
    const bookUrl = `${base}/legal-books/${book}?${new URLSearchParams({
        from,
        to,
        first_folio: folio,
        ...(authorization.trim()
            ? { authorization: authorization.trim() }
            : {}),
    }).toString()}`;

    return (
        <PageShell
            company={company}
            title="Cierre anual y libros legales"
            subtitle="Partida de cierre, diferencial cambiario y libros foliados"
            icon={BookCheck}
        >
            <Errors errors={errors} />

            <Card title="Cierre del ejercicio">
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <Label text="Ejercicio">
                        <select
                            className={`${field} w-32`}
                            value={year}
                            onChange={(event) =>
                                visit({ year: event.target.value })
                            }
                        >
                            {years.map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                    </Label>
                    <p className="pb-2 text-sm text-slate-500">
                        Del {date(closing.from)} al {date(closing.to)}
                    </p>
                    {closing.closing && (
                        <Badge
                            tone={
                                closing.closing.status === 'posted'
                                    ? 'green'
                                    : 'amber'
                            }
                        >
                            Partida de cierre:{' '}
                            {STATUS[closing.closing.status] ??
                                closing.closing.status}
                        </Badge>
                    )}
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Stat
                        label="Ingresos del ejercicio"
                        value={money(closing.income_cents)}
                    />
                    <Stat
                        label="Costos y gastos"
                        value={money(closing.expense_cents)}
                    />
                    <Stat
                        label={
                            closing.result_cents >= 0
                                ? 'Utilidad del ejercicio'
                                : 'Pérdida del ejercicio'
                        }
                        value={money(Math.abs(closing.result_cents))}
                        tone={closing.result_cents >= 0 ? 'good' : 'bad'}
                    />
                </div>
                {closing.blockers.length > 0 && (
                    <div className="mt-4 space-y-1 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        {closing.blockers.map((blocker) => (
                            <p key={blocker} className="flex gap-2">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                {blocker}
                            </p>
                        ))}
                    </div>
                )}
                <p className="mt-4 text-sm text-slate-600">
                    La partida de cierre lleva a cero los ingresos y gastos del
                    año contra <strong>3203 Resultado del ejercicio</strong>,
                    con fecha {date(closing.to)}. El primer día del año
                    siguiente, el resultado pasa a resultados acumulados (o
                    pérdidas acumuladas). Ambas quedan en borrador y siguen la
                    revisión normal del diario; los estados financieros del año
                    no cambian.
                </p>
                {closing.lines.length > 0 ? (
                    <div className="mt-4 space-y-4">
                        <Lines lines={closing.lines} />
                        {closing.transfer.length > 0 && (
                            <div>
                                <p className="mb-2 text-xs font-bold tracking-wide text-slate-500 uppercase">
                                    Traslado del resultado (año siguiente)
                                    {closing.transfer_entry &&
                                        ` · ${STATUS[closing.transfer_entry.status] ?? closing.transfer_entry.status}`}
                                </p>
                                <Lines lines={closing.transfer} />
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="mt-4">
                        <Empty>
                            No hay ingresos ni gastos contabilizados en el
                            ejercicio.
                        </Empty>
                    </div>
                )}
                <div className="mt-4 flex flex-wrap gap-2">
                    {canManage &&
                        !closing.closing &&
                        closing.blockers.length === 0 && (
                            <button
                                type="button"
                                className={primary}
                                onClick={() => setConfirm('close')}
                            >
                                <Lock className="size-4" /> Generar partida de
                                cierre
                            </button>
                        )}
                    {closing.closing && (
                        <a
                            href={journalUrl}
                            className="text-sm font-semibold text-[#640000] underline"
                        >
                            Ir al diario para revisarla y contabilizarla
                        </a>
                    )}
                </div>
            </Card>

            <Card title="Diferencial cambiario del mes">
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <Label text="Mes">
                        <input
                            type="month"
                            className={`${field} w-44`}
                            value={month}
                            onChange={(event) =>
                                event.target.value &&
                                visit({ month: event.target.value })
                            }
                        />
                    </Label>
                    <p className="pb-2 text-sm text-slate-500">
                        Valorado al {date(revaluation.as_of)}
                        {Object.keys(revaluation.rates).length > 0 &&
                            ` · ${Object.entries(revaluation.rates)
                                .map(
                                    ([currency, rate]) =>
                                        `${currency} Q ${Number(rate).toFixed(5)}`,
                                )
                                .join(' · ')}`}
                    </p>
                    {revaluation.entry && (
                        <Badge
                            tone={
                                revaluation.entry.status === 'posted'
                                    ? 'green'
                                    : 'amber'
                            }
                        >
                            Registrado:{' '}
                            {STATUS[revaluation.entry.status] ??
                                revaluation.entry.status}
                        </Badge>
                    )}
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Stat
                        label="Cuentas por cobrar"
                        value={money(revaluation.receivable_cents)}
                        tone={
                            revaluation.receivable_cents >= 0 ? 'good' : 'bad'
                        }
                    />
                    <Stat
                        label="Cuentas por pagar"
                        value={money(-revaluation.payable_cents)}
                        tone={revaluation.payable_cents <= 0 ? 'good' : 'bad'}
                    />
                    <Stat
                        label={
                            revaluation.net_cents >= 0
                                ? 'Diferencial a favor'
                                : 'Diferencial en contra'
                        }
                        value={money(Math.abs(revaluation.net_cents))}
                        tone={revaluation.net_cents >= 0 ? 'good' : 'bad'}
                    />
                </div>
                {revaluation.missing_rates.length > 0 && (
                    <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        Falta el tipo de cambio de{' '}
                        {revaluation.missing_rates.join(', ')} al cierre del
                        mes. Actualízalo desde Banguat en la configuración
                        fiscal.
                    </p>
                )}
                <p className="mt-4 text-sm text-slate-600">
                    Lo que clientes y proveedores deben en moneda extranjera se
                    valora al tipo de cambio del último día del mes. La
                    diferencia va a 4303 o 5303 contra las cuentas de ajuste
                    1149 y 2149, y se revierte el primer día del mes siguiente.
                </p>
                {revaluation.rows.length > 0 ? (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b border-slate-200">
                                    <th className={head}>Documento</th>
                                    <th className={head}>Moneda</th>
                                    <th className={`${head} text-right`}>
                                        En libros
                                    </th>
                                    <th className={`${head} text-right`}>
                                        Valor al cierre
                                    </th>
                                    <th className={`${head} text-right`}>
                                        Diferencia
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {revaluation.rows.map((row) => (
                                    <tr
                                        key={row.side + row.document}
                                        className="border-b border-slate-100"
                                    >
                                        <td className={cell}>
                                            {row.side === 'receivable'
                                                ? 'Por cobrar'
                                                : 'Por pagar'}{' '}
                                            · {row.document} · {row.party}
                                        </td>
                                        <td className={cell}>
                                            {row.currency}{' '}
                                            {(row.foreign_cents / 100).toFixed(
                                                2,
                                            )}
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.book_cents)}
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.revalued_cents)}
                                        </td>
                                        <td className={`${cell} text-right`}>
                                            {money(row.difference_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="mt-4">
                        <Empty>
                            No hay saldos pendientes en moneda extranjera a esa
                            fecha.
                        </Empty>
                    </div>
                )}
                {canManage &&
                    !revaluation.entry &&
                    revaluation.missing_rates.length === 0 &&
                    revaluation.net_cents !== 0 && (
                        <button
                            type="button"
                            className={`${primary} mt-4`}
                            onClick={() => setConfirm('revalue')}
                        >
                            <Coins className="size-4" /> Registrar diferencial
                            cambiario
                        </button>
                    )}
            </Card>

            <Card title="Libros legales foliados">
                <p className="mb-4 text-sm text-slate-600">
                    Imprime los libros que exige el Código de Comercio en las
                    hojas habilitadas por la SAT. Cada hoja lleva la empresa, su
                    NIT, el libro y su número de folio, que continúa desde la
                    última impresión de ese libro.
                </p>
                {canPrint ? (
                    <div className="grid gap-3 md:grid-cols-6">
                        <div className="md:col-span-2">
                            <Label text="Libro">
                                <select
                                    className={field}
                                    value={book}
                                    onChange={(event) => {
                                        setBook(event.target.value);
                                        setFolio(
                                            String(
                                                nextFolios[
                                                    event.target.value
                                                ] ?? 1,
                                            ),
                                        );
                                    }}
                                >
                                    {Object.entries(books).map(
                                        ([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Label>
                        </div>
                        {book !== 'inventarios' && (
                            <Label text="Desde">
                                <input
                                    type="date"
                                    className={field}
                                    value={from}
                                    onChange={(event) =>
                                        setFrom(event.target.value)
                                    }
                                />
                            </Label>
                        )}
                        <Label text={book === 'inventarios' ? 'Al' : 'Hasta'}>
                            <input
                                type="date"
                                className={field}
                                value={to}
                                onChange={(event) => setTo(event.target.value)}
                            />
                        </Label>
                        <Label text="Primer folio">
                            <input
                                type="number"
                                min={1}
                                className={field}
                                value={folio}
                                onChange={(event) =>
                                    setFolio(event.target.value)
                                }
                            />
                        </Label>
                        <div className="md:col-span-3">
                            <Label text="Habilitación SAT (opcional)">
                                <input
                                    className={field}
                                    maxLength={120}
                                    value={authorization}
                                    placeholder="Número de resolución o constancia"
                                    onChange={(event) =>
                                        setAuthorization(event.target.value)
                                    }
                                />
                            </Label>
                        </div>
                        <div className="flex items-end md:col-span-3">
                            <a
                                href={
                                    book === 'inventarios'
                                        ? bookUrl.replace(
                                              `from=${from}`,
                                              `from=${to}`,
                                          )
                                        : bookUrl
                                }
                                className={primary}
                            >
                                <Download className="size-4" /> Imprimir{' '}
                                {books[book]} (PDF)
                            </a>
                        </div>
                    </div>
                ) : (
                    <Empty>Tu rol no puede imprimir los libros legales.</Empty>
                )}
                <div className="mt-6">
                    <p className="mb-2 flex items-center gap-2 text-xs font-bold tracking-wide text-slate-500 uppercase">
                        <CalendarCheck className="size-4" /> Impresiones
                        anteriores
                    </p>
                    {history.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b border-slate-200">
                                        <th className={head}>Libro</th>
                                        <th className={head}>Período</th>
                                        <th className={head}>Folios</th>
                                        <th className={head}>Impreso por</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map((print) => (
                                        <tr
                                            key={print.printed_at + print.book}
                                            className="border-b border-slate-100"
                                        >
                                            <td className={cell}>
                                                {print.book_label}
                                            </td>
                                            <td className={cell}>
                                                {date(print.from)} al{' '}
                                                {date(print.to)}
                                            </td>
                                            <td className={cell}>
                                                {print.first_folio} al{' '}
                                                {print.last_folio}
                                            </td>
                                            <td className={cell}>
                                                {print.printed_by} ·{' '}
                                                {new Date(
                                                    print.printed_at,
                                                ).toLocaleDateString('es-GT')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Empty>Todavía no se ha impreso ningún libro.</Empty>
                    )}
                </div>
            </Card>

            <ConfirmDialog
                open={confirm === 'close'}
                icon={<Lock className="size-6" />}
                title={`Cerrar el ejercicio ${year}`}
                description="Se creará en borrador la partida de cierre y, si ya existe el período de enero, el traslado del resultado. Revísalas y contabilízalas desde el diario."
                confirmLabel="Generar partida de cierre"
                processing={processing}
                onConfirm={() => post(`${base}/year-end/close`, { year })}
                onClose={() => setConfirm(null)}
            />
            <ConfirmDialog
                open={confirm === 'revalue'}
                icon={<Coins className="size-6" />}
                title="Registrar diferencial cambiario"
                description={`Se creará en borrador el ajuste al ${date(revaluation.as_of)} y su reversión el primer día del mes siguiente.`}
                confirmLabel="Registrar"
                processing={processing}
                onConfirm={() =>
                    post(`${base}/year-end/revaluation`, { month })
                }
                onClose={() => setConfirm(null)}
            />
        </PageShell>
    );
}
