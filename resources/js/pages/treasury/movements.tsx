import { Head, router, useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import {
    amount,
    movementTone,
    useTreasuryHref,
} from '@/components/treasury/kit';
import type {
    AccountOption,
    Movement,
    TreasuryContext,
    TreasuryPermissions,
} from '@/components/treasury/kit';

type PreviewRow = {
    line: number;
    date: string | null;
    reference: string;
    description: string;
    flow: 'inflow' | 'outflow';
    amount_cents: number;
    status: 'valid' | 'duplicate' | 'invalid';
    errors: string[];
};
type Preview = {
    token: string;
    company_id: number;
    account: { id: number; name: string };
    rows: PreviewRow[];
    valid: number;
    duplicates: number;
    invalid: number;
};

const statusFilters: [string, string][] = [
    ['open', 'Por conciliar'],
    ['pending', 'Pendientes'],
    ['unmatched', 'Sin identificar'],
    ['matched', 'Conciliados'],
    ['all', 'Todos'],
];
const previewTone = { valid: 'green', duplicate: 'amber', invalid: 'red' };
const previewLabel = {
    valid: 'Se importará',
    duplicate: 'Duplicado',
    invalid: 'Inválido',
};

function ImportForm({ accounts }: { accounts: AccountOption[] }) {
    const form = useForm<{ bank_account_id: string; file: File | null }>({
        bank_account_id: accounts[0] ? String(accounts[0].id) : '',
        file: null,
    });

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/treasury/movements/import', {
                    forceFormData: true,
                    preserveScroll: true,
                });
            }}
        >
            <label className="grid min-w-56 gap-1 text-xs font-semibold">
                Cuenta
                <select
                    className={input}
                    value={form.data.bank_account_id}
                    onChange={(event) =>
                        form.setData('bank_account_id', event.target.value)
                    }
                >
                    {accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.company} · {account.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1 text-xs font-semibold">
                Estado de cuenta (CSV: fecha, referencia, descripcion, monto)
                <input
                    type="file"
                    accept=".csv,text/csv"
                    className="text-xs"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
            </label>
            <button
                type="submit"
                className={primaryButton}
                disabled={form.processing || !form.data.file}
            >
                <Upload className="size-4" /> Vista previa
            </button>
            {(form.errors.file || form.errors.bank_account_id) && (
                <p className="w-full text-xs text-red-700">
                    {form.errors.file ?? form.errors.bank_account_id}
                </p>
            )}
        </form>
    );
}

function MovementActions({
    movement,
    classifications,
    canMatch,
}: {
    movement: Movement;
    classifications: Record<string, string>;
    canMatch: boolean;
}) {
    const [classification, setClassification] = useState('');

    if (!canMatch) {
        return null;
    }

    if (movement.status === 'matched') {
        return movement.matched &&
            ['treasury_receipts', 'payment_instructions'].includes(
                movement.matched.type,
            ) ? (
            <button
                type="button"
                className={secondaryButton}
                onClick={() =>
                    router.post(
                        `/treasury/movements/${movement.id}/unmatch`,
                        {},
                        { preserveScroll: true },
                    )
                }
            >
                Deshacer match
            </button>
        ) : null;
    }

    return (
        <div className="grid gap-1">
            {(movement.suggestions ?? []).map((suggestion) => (
                <button
                    key={`${suggestion.type}-${suggestion.id}`}
                    type="button"
                    className="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-left text-[11px] font-semibold text-emerald-800"
                    onClick={() =>
                        router.post(
                            `/treasury/movements/${movement.id}/match`,
                            { type: suggestion.type, id: suggestion.id },
                            { preserveScroll: true },
                        )
                    }
                >
                    Confirmar: {suggestion.label} ·{' '}
                    {formatDate(suggestion.date)}
                </button>
            ))}
            <div className="flex gap-1">
                <select
                    aria-label="Clasificar movimiento"
                    className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                    value={classification}
                    onChange={(event) => setClassification(event.target.value)}
                >
                    <option value="">Clasificar…</option>
                    {Object.entries(classifications)
                        .filter(
                            ([key]) =>
                                !['receipt', 'payment', 'transfer'].includes(
                                    key,
                                ),
                        )
                        .map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                </select>
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={classification === ''}
                    onClick={() =>
                        router.post(
                            `/treasury/movements/${movement.id}/classify`,
                            { classification },
                            { preserveScroll: true },
                        )
                    }
                >
                    Aplicar
                </button>
            </div>
        </div>
    );
}

export default function TreasuryMovements({
    movements,
    filters,
    accounts,
    classifications,
    preview,
    permissions,
    notice,
}: {
    context: TreasuryContext;
    movements: Movement[];
    filters: { company_id?: string; account_id?: string; status?: string };
    accounts: AccountOption[];
    classifications: Record<string, string>;
    preview: Preview | null;
    permissions: TreasuryPermissions;
    notice: string | null;
}) {
    const href = useTreasuryHref();
    const status = filters.status ?? 'open';
    const filter = (changes: Record<string, string>) =>
        router.get(
            href('/treasury/movements'),
            { ...filters, ...changes },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title="Movimientos bancarios · Tesorería" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Movimientos por conciliar"
                    subtitle="Importa estados de cuenta, confirma las coincidencias sugeridas y clasifica comisiones, intereses o movimientos sin identificar"
                />

                {permissions.canImport && accounts.length > 0 && (
                    <Card title="Importar estado de cuenta">
                        <ImportForm accounts={accounts} />
                        <p className="mt-2 text-[11px] text-[#526b92]">
                            Primero verás una vista previa; nada se guarda hasta
                            confirmar. Los montos negativos son débitos. Los
                            duplicados (misma referencia o misma huella) se
                            omiten.
                        </p>
                    </Card>
                )}

                {preview && (
                    <Card
                        title={`Vista previa · ${preview.account.name}`}
                        action={
                            <button
                                type="button"
                                className={primaryButton}
                                disabled={preview.valid === 0}
                                onClick={() =>
                                    router.post(
                                        '/treasury/movements/import/confirm',
                                        {
                                            token: preview.token,
                                            company_id: preview.company_id,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Importar {preview.valid} movimiento(s)
                            </button>
                        }
                    >
                        <p className="mb-2 text-xs text-[#526b92]">
                            {preview.valid} válidos · {preview.duplicates}{' '}
                            duplicados · {preview.invalid} inválidos
                        </p>
                        <Table
                            headers={[
                                'Línea',
                                'Fecha',
                                'Referencia',
                                'Descripción',
                                'Monto',
                                'Resultado',
                            ]}
                        >
                            {preview.rows.map((row) => (
                                <tr key={row.line}>
                                    <td className="px-2 py-2">{row.line}</td>
                                    <td className="px-2 py-2">
                                        {row.date ? formatDate(row.date) : '—'}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {row.reference || '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.description}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${row.flow === 'inflow' ? 'text-emerald-700' : 'text-red-700'}`}
                                    >
                                        {row.flow === 'inflow' ? '+' : '−'}
                                        {amount(row.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                previewTone[row.status],
                                            )}
                                        >
                                            {previewLabel[row.status]}
                                        </span>
                                        {row.errors.length > 0 && (
                                            <p className="text-[11px] text-red-700">
                                                {row.errors.join(', ')}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}

                <Card title={`${movements.length} movimiento(s)`}>
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        {statusFilters.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => filter({ status: key })}
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${status === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                        <select
                            aria-label="Cuenta"
                            className="h-8 rounded-md border border-slate-300 px-2 text-xs"
                            value={filters.account_id ?? ''}
                            onChange={(event) =>
                                filter({ account_id: event.target.value })
                            }
                        >
                            <option value="">Todas las cuentas</option>
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.company} · {account.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Table
                        headers={[
                            'Fecha',
                            'Cuenta',
                            'Referencia',
                            'Descripción',
                            'Monto',
                            'Clasificación',
                            'Estado',
                            'Acción',
                        ]}
                        empty={
                            movements.length === 0 &&
                            'No hay movimientos con este filtro.'
                        }
                    >
                        {movements.map((movement) => (
                            <tr key={movement.id} className="align-top">
                                <td className="px-2 py-2">
                                    {formatDate(movement.date)}
                                </td>
                                <td className="px-2 py-2">
                                    {movement.account}
                                    <p className="text-[11px] text-[#526b92]">
                                        {movement.company}
                                    </p>
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {movement.reference}
                                </td>
                                <td className="max-w-52 px-2 py-2">
                                    {movement.description}
                                </td>
                                <td
                                    className={`px-2 py-2 font-semibold ${movement.flow === 'inflow' ? 'text-emerald-700' : 'text-red-700'}`}
                                >
                                    {movement.flow === 'inflow' ? '+' : '−'}
                                    {amount(movement.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {movement.classification_label ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            movementTone[movement.status] ??
                                                'slate',
                                        )}
                                    >
                                        {movement.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <MovementActions
                                        movement={movement}
                                        classifications={classifications}
                                        canMatch={permissions.canMatch}
                                    />
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        Las comisiones e intereses generan un asiento en
                        borrador según el mapeo contable de la empresa; los
                        movimientos sin identificar abren una incidencia.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
