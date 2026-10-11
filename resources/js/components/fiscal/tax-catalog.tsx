import { router, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    ChevronDown,
    ChevronRight,
    Pencil,
    Plus,
    Power,
    Scale,
    XCircle,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    fiscalTaxStatus,
    fiscalTaxStore,
    fiscalTaxUpdate,
    fiscalTaxVersionCancel,
    fiscalTaxVersionStore,
} from '@/routes/accounting';

export type TaxVersion = {
    id: number;
    rate_basis_points: number;
    amount_cents: number | null;
    threshold_cents: number | null;
    valid_from: string;
    valid_to: string | null;
    is_active: boolean;
    legal_reference: string | null;
};

export type FiscalTax = {
    id: number;
    code: string;
    name: string;
    tax_type: string;
    regime: string;
    applies_to: string;
    calculation: 'percentage' | 'fixed_amount';
    price_includes_tax: boolean;
    recoverable: boolean;
    recoverable_account_id: number | null;
    payable_account_id: number | null;
    recoverable_account_label: string | null;
    payable_account_label: string | null;
    legal_basis: string | null;
    sat_form: string | null;
    is_system: boolean;
    status: 'active' | 'inactive';
    versions: TaxVersion[];
    current: TaxVersion | null;
};

export type LedgerAccountOption = {
    id: number;
    code: string;
    name: string;
    type: string;
};

const taxTypes: Record<string, string> = {
    vat: 'IVA',
    income_tax: 'ISR',
    withholding: 'Retención',
    stamp: 'Timbre',
    specific: 'Impuesto específico',
    other: 'Otro',
};

const appliesTo: Record<string, string> = {
    sales: 'Ventas',
    purchases: 'Compras',
    both: 'Ventas y compras',
    income: 'Renta / utilidades',
    payroll: 'Planilla',
    other: 'Otro',
};

const regimes: Record<string, string> = {
    general: 'Régimen general',
    small_taxpayer: 'Pequeño contribuyente',
    utilities: 'Sobre utilidades',
    optional_simplified: 'Opcional simplificado',
    exempt: 'Exento',
};

const percent = (basisPoints: number) => `${(basisPoints / 100).toFixed(2)}%`;

function versionValue(tax: FiscalTax, version: TaxVersion): string {
    return tax.calculation === 'fixed_amount'
        ? money(version.amount_cents ?? 0)
        : percent(version.rate_basis_points);
}

function versionState(
    version: TaxVersion,
    today: string,
): { label: string; tone: string } {
    if (!version.is_active) {
        return { label: 'Anulada', tone: 'bg-slate-100 text-slate-500' };
    }

    if (version.valid_from > today) {
        return { label: 'Programada', tone: 'bg-blue-50 text-blue-700' };
    }

    if (version.valid_to !== null && version.valid_to < today) {
        return { label: 'Histórica', tone: 'bg-slate-100 text-slate-600' };
    }

    return { label: 'Vigente', tone: 'bg-emerald-50 text-emerald-700' };
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block text-xs font-semibold text-slate-600">
            {label}
            {children}
        </label>
    );
}

function AccountSelect({
    value,
    accounts,
    onChange,
    placeholder,
}: {
    value: number | '' | null;
    accounts: LedgerAccountOption[];
    onChange: (value: number | '') => void;
    placeholder: string;
}) {
    return (
        <select
            className={field}
            value={value ?? ''}
            onChange={(event) =>
                onChange(event.target.value ? Number(event.target.value) : '')
            }
        >
            <option value="">{placeholder}</option>
            {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                    {account.code} · {account.name}
                </option>
            ))}
        </select>
    );
}

type DefinitionData = {
    name: string;
    applies_to: string;
    price_includes_tax: boolean;
    recoverable: boolean;
    recoverable_account_id: number | '';
    payable_account_id: number | '';
    legal_basis: string;
    sat_form: string;
};

function DefinitionFields({
    data,
    setData,
    accounts,
}: {
    data: DefinitionData;
    setData: <K extends keyof DefinitionData>(
        key: K,
        value: DefinitionData[K],
    ) => void;
    accounts: LedgerAccountOption[];
}) {
    return (
        <>
            <Field label="Nombre">
                <input
                    className={field}
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                />
            </Field>
            <Field label="Aplica a">
                <select
                    className={field}
                    value={data.applies_to}
                    onChange={(event) =>
                        setData('applies_to', event.target.value)
                    }
                >
                    {Object.entries(appliesTo).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </Field>
            <div className="grid gap-2 sm:grid-cols-2">
                <label className="flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <input
                        type="checkbox"
                        checked={data.price_includes_tax}
                        onChange={(event) =>
                            setData('price_includes_tax', event.target.checked)
                        }
                    />
                    El precio incluye el impuesto
                </label>
                <label className="flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <input
                        type="checkbox"
                        checked={data.recoverable}
                        onChange={(event) =>
                            setData('recoverable', event.target.checked)
                        }
                    />
                    Genera crédito recuperable
                </label>
            </div>
            <Field label="Cuenta de impuesto recuperable (activo)">
                <AccountSelect
                    value={data.recoverable_account_id}
                    accounts={accounts}
                    placeholder="Sin cuenta"
                    onChange={(value) =>
                        setData('recoverable_account_id', value)
                    }
                />
            </Field>
            <Field label="Cuenta de impuesto por pagar (pasivo)">
                <AccountSelect
                    value={data.payable_account_id}
                    accounts={accounts}
                    placeholder="Sin cuenta"
                    onChange={(value) => setData('payable_account_id', value)}
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
                <Field label="Base legal">
                    <input
                        className={field}
                        value={data.legal_basis}
                        placeholder="Ej. Decreto 27-92"
                        onChange={(event) =>
                            setData('legal_basis', event.target.value)
                        }
                    />
                </Field>
                <Field label="Formulario SAT">
                    <input
                        className={field}
                        value={data.sat_form}
                        placeholder="SAT-2237"
                        onChange={(event) =>
                            setData('sat_form', event.target.value)
                        }
                    />
                </Field>
            </div>
        </>
    );
}

type VersionData = {
    rate: string;
    amount: string;
    threshold: string;
    valid_from: string;
    legal_reference: string;
};

function VersionFields({
    calculation,
    data,
    setData,
}: {
    calculation: string;
    data: VersionData;
    setData: <K extends keyof VersionData>(
        key: K,
        value: VersionData[K],
    ) => void;
}) {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-3">
                {calculation === 'fixed_amount' ? (
                    <Field label="Monto fijo (Q)">
                        <input
                            className={field}
                            inputMode="decimal"
                            value={data.amount}
                            onChange={(event) =>
                                setData('amount', event.target.value)
                            }
                            required
                        />
                    </Field>
                ) : (
                    <Field label="Tasa (%)">
                        <input
                            className={field}
                            inputMode="decimal"
                            value={data.rate}
                            onChange={(event) =>
                                setData('rate', event.target.value)
                            }
                            required
                        />
                    </Field>
                )}
                <Field label="Umbral (Q, opcional)">
                    <input
                        className={field}
                        inputMode="decimal"
                        value={data.threshold}
                        onChange={(event) =>
                            setData('threshold', event.target.value)
                        }
                    />
                </Field>
                <Field label="Rige desde">
                    <input
                        type="date"
                        className={field}
                        value={data.valid_from}
                        onChange={(event) =>
                            setData('valid_from', event.target.value)
                        }
                        required
                    />
                </Field>
            </div>
            <Field label="Referencia legal de esta vigencia">
                <input
                    className={field}
                    value={data.legal_reference}
                    placeholder="Ej. Decreto 4-2031, artículo 3"
                    onChange={(event) =>
                        setData('legal_reference', event.target.value)
                    }
                />
            </Field>
        </>
    );
}

const emptyVersion = (today: string): VersionData => ({
    rate: '',
    amount: '',
    threshold: '',
    valid_from: today,
    legal_reference: '',
});

function CreateTaxDialog({
    company,
    accounts,
    today,
    open,
    onClose,
}: {
    company: string;
    accounts: LedgerAccountOption[];
    today: string;
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        code: '',
        tax_type: 'other',
        regime: 'general',
        calculation: 'percentage',
        name: '',
        applies_to: 'sales',
        price_includes_tax: false,
        recoverable: false,
        recoverable_account_id: '' as number | '',
        payable_account_id: '' as number | '',
        legal_basis: '',
        sat_form: '',
        ...emptyVersion(today),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(fiscalTaxStore.url(company), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Nuevo impuesto</DialogTitle>
                    <DialogDescription>
                        Úsalo cuando un decreto crea un tributo nuevo. Su
                        identidad (código, tipo, régimen y forma de cálculo) no
                        podrá cambiarse después.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-3" onSubmit={submit}>
                    <Errors errors={form.errors} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Código">
                            <input
                                className={field}
                                value={form.data.code}
                                placeholder="IMPUESTO_NUEVO"
                                onChange={(event) =>
                                    form.setData(
                                        'code',
                                        event.target.value
                                            .toUpperCase()
                                            .replace(/[^A-Z0-9_]/g, ''),
                                    )
                                }
                                required
                            />
                        </Field>
                        <Field label="Tipo">
                            <select
                                className={field}
                                value={form.data.tax_type}
                                onChange={(event) =>
                                    form.setData('tax_type', event.target.value)
                                }
                            >
                                {Object.entries(taxTypes).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <Field label="Régimen">
                            <input
                                className={field}
                                value={form.data.regime}
                                onChange={(event) =>
                                    form.setData(
                                        'regime',
                                        event.target.value
                                            .toLowerCase()
                                            .replace(/[^a-z0-9_]/g, ''),
                                    )
                                }
                                required
                            />
                        </Field>
                        <Field label="Forma de cálculo">
                            <select
                                className={field}
                                value={form.data.calculation}
                                onChange={(event) =>
                                    form.setData(
                                        'calculation',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="percentage">Porcentaje</option>
                                <option value="fixed_amount">Monto fijo</option>
                            </select>
                        </Field>
                    </div>
                    <DefinitionFields
                        data={form.data}
                        setData={form.setData}
                        accounts={accounts}
                    />
                    <p className="border-t pt-3 text-xs font-semibold text-slate-700">
                        Primera vigencia
                    </p>
                    <VersionFields
                        calculation={form.data.calculation}
                        data={form.data}
                        setData={form.setData}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className={secondary}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button className={primary} disabled={form.processing}>
                            <Plus className="size-4" /> Crear impuesto
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditTaxDialog({
    company,
    tax,
    accounts,
    onClose,
}: {
    company: string;
    tax: FiscalTax;
    accounts: LedgerAccountOption[];
    onClose: () => void;
}) {
    const form = useForm<DefinitionData>({
        name: tax.name,
        applies_to: tax.applies_to,
        price_includes_tax: tax.price_includes_tax,
        recoverable: tax.recoverable,
        recoverable_account_id: tax.recoverable_account_id ?? '',
        payable_account_id: tax.payable_account_id ?? '',
        legal_basis: tax.legal_basis ?? '',
        sat_form: tax.sat_form ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(fiscalTaxUpdate.url({ company, tax: tax.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Editar {tax.code}</DialogTitle>
                    <DialogDescription>
                        Cambia la descripción y las cuentas contables. Las tasas
                        se cambian registrando una nueva vigencia.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-3" onSubmit={submit}>
                    <Errors errors={form.errors} />
                    <DefinitionFields
                        data={form.data}
                        setData={form.setData}
                        accounts={accounts}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className={secondary}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button className={primary} disabled={form.processing}>
                            <Pencil className="size-4" /> Guardar cambios
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function VersionDialog({
    company,
    tax,
    today,
    onClose,
}: {
    company: string;
    tax: FiscalTax;
    today: string;
    onClose: () => void;
}) {
    const form = useForm<VersionData>(emptyVersion(today));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(fiscalTaxVersionStore.url({ company, tax: tax.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Nueva vigencia · {tax.name}</DialogTitle>
                    <DialogDescription>
                        Registra el cambio de tasa por decreto. La vigencia
                        actual{' '}
                        {tax.current
                            ? `(${versionValue(tax, tax.current)})`
                            : ''}{' '}
                        se cerrará automáticamente el día anterior y los
                        documentos previos conservarán su tasa.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-3" onSubmit={submit}>
                    <Errors errors={form.errors} />
                    <VersionFields
                        calculation={tax.calculation}
                        data={form.data}
                        setData={form.setData}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className={secondary}
                            onClick={onClose}
                        >
                            Cancelar
                        </button>
                        <button className={primary} disabled={form.processing}>
                            <CalendarClock className="size-4" /> Registrar
                            vigencia
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CancelVersionDialog({
    company,
    version,
    onClose,
}: {
    company: string;
    version: TaxVersion;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(fiscalTaxVersionCancel.url({ company, rule: version.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Anular vigencia programada</DialogTitle>
                    <DialogDescription>
                        La vigencia del {version.valid_from} no llegará a
                        aplicarse y la anterior volverá a quedar abierta.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-3" onSubmit={submit}>
                    <Errors errors={form.errors} />
                    <Field label="Motivo">
                        <textarea
                            className={field}
                            rows={3}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            className={secondary}
                            onClick={onClose}
                        >
                            Volver
                        </button>
                        <button className={primary} disabled={form.processing}>
                            <XCircle className="size-4" /> Anular vigencia
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function TaxCatalog({
    company,
    taxes,
    accounts,
    today,
    canManage,
}: {
    company: string;
    taxes: FiscalTax[];
    accounts: LedgerAccountOption[];
    today: string;
    canManage: boolean;
}) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<FiscalTax | null>(null);
    const [versioning, setVersioning] = useState<FiscalTax | null>(null);
    const [cancelling, setCancelling] = useState<TaxVersion | null>(null);

    const toggleStatus = (tax: FiscalTax) =>
        router.patch(
            fiscalTaxStatus.url({ company, tax: tax.id }),
            { status: tax.status === 'active' ? 'inactive' : 'active' },
            { preserveScroll: true },
        );

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-bold">
                        <Scale className="size-5 text-[#76080e]" /> Impuestos y
                        vigencias
                    </h2>
                    <p className="mt-1 max-w-3xl text-sm text-slate-500">
                        Cada cálculo usa la vigencia en efecto en la fecha del
                        documento. Si un decreto cambia una tasa, registra una
                        nueva vigencia; si crea un tributo nuevo, agrégalo como
                        impuesto.
                    </p>
                </div>
                {canManage && (
                    <button
                        type="button"
                        className={primary}
                        onClick={() => setCreating(true)}
                    >
                        <Plus className="size-4" /> Nuevo impuesto
                    </button>
                )}
            </header>

            <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full min-w-[980px] text-left text-sm">
                    <thead className="bg-[#faf4f4] text-xs text-slate-600 uppercase">
                        <tr>
                            <th className="p-3">Impuesto</th>
                            <th className="p-3">Régimen / aplica a</th>
                            <th className="p-3">Vigente hoy</th>
                            <th className="p-3">Cuentas contables</th>
                            <th className="p-3">Estado</th>
                            <th className="p-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        {taxes.map((tax) => (
                            <Fragment key={tax.id}>
                                <tr className="border-t border-slate-100 align-top">
                                    <td className="p-3">
                                        <button
                                            type="button"
                                            className="flex items-start gap-2 text-left"
                                            onClick={() =>
                                                setExpanded(
                                                    expanded === tax.id
                                                        ? null
                                                        : tax.id,
                                                )
                                            }
                                            aria-expanded={expanded === tax.id}
                                        >
                                            {expanded === tax.id ? (
                                                <ChevronDown className="mt-0.5 size-4 shrink-0" />
                                            ) : (
                                                <ChevronRight className="mt-0.5 size-4 shrink-0" />
                                            )}
                                            <span>
                                                <span className="block font-semibold">
                                                    {tax.name}
                                                </span>
                                                <span className="block text-xs text-slate-500">
                                                    {tax.code} ·{' '}
                                                    {taxTypes[tax.tax_type] ??
                                                        tax.tax_type}
                                                    {tax.sat_form
                                                        ? ` · ${tax.sat_form}`
                                                        : ''}
                                                </span>
                                                {tax.legal_basis && (
                                                    <span className="block text-xs text-slate-400">
                                                        {tax.legal_basis}
                                                    </span>
                                                )}
                                            </span>
                                        </button>
                                    </td>
                                    <td className="p-3 text-xs">
                                        <span className="block font-medium text-slate-700">
                                            {regimes[tax.regime] ?? tax.regime}
                                        </span>
                                        <span className="block text-slate-500">
                                            {appliesTo[tax.applies_to] ??
                                                tax.applies_to}
                                            {tax.price_includes_tax
                                                ? ' · incluido en precio'
                                                : ''}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {tax.current ? (
                                            <>
                                                <span className="block font-semibold tabular-nums">
                                                    {versionValue(
                                                        tax,
                                                        tax.current,
                                                    )}
                                                </span>
                                                <span className="block text-xs text-slate-500">
                                                    desde{' '}
                                                    {tax.current.valid_from}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">
                                                Sin vigencia hoy
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 text-xs text-slate-600">
                                        <span className="block">
                                            Recuperable:{' '}
                                            {tax.recoverable_account_label ??
                                                '—'}
                                        </span>
                                        <span className="block">
                                            Por pagar:{' '}
                                            {tax.payable_account_label ?? '—'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        <span
                                            className={`rounded-full px-2 py-1 text-xs font-semibold ${tax.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                        >
                                            {tax.status === 'active'
                                                ? 'Activo'
                                                : 'Inactivo'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {canManage && (
                                            <div className="flex flex-wrap justify-end gap-1">
                                                <button
                                                    type="button"
                                                    className="rounded-lg px-2 py-1 text-xs font-semibold text-[#76080e] hover:bg-[#fbf4f4]"
                                                    onClick={() =>
                                                        setVersioning(tax)
                                                    }
                                                    disabled={
                                                        tax.status !== 'active'
                                                    }
                                                >
                                                    Nueva vigencia
                                                </button>
                                                <button
                                                    type="button"
                                                    className="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                                                    onClick={() =>
                                                        setEditing(tax)
                                                    }
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                                                    onClick={() =>
                                                        toggleStatus(tax)
                                                    }
                                                >
                                                    <Power className="size-3" />
                                                    {tax.status === 'active'
                                                        ? 'Desactivar'
                                                        : 'Activar'}
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                                {expanded === tax.id && (
                                    <tr className="bg-slate-50/70">
                                        <td colSpan={6} className="p-3">
                                            <table className="w-full text-xs">
                                                <thead className="text-slate-500">
                                                    <tr>
                                                        <th className="p-2 text-left">
                                                            Desde
                                                        </th>
                                                        <th className="p-2 text-left">
                                                            Hasta
                                                        </th>
                                                        <th className="p-2 text-left">
                                                            Valor
                                                        </th>
                                                        <th className="p-2 text-left">
                                                            Umbral
                                                        </th>
                                                        <th className="p-2 text-left">
                                                            Referencia legal
                                                        </th>
                                                        <th className="p-2 text-left">
                                                            Estado
                                                        </th>
                                                        <th className="p-2" />
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {tax.versions.map(
                                                        (version) => {
                                                            const state =
                                                                versionState(
                                                                    version,
                                                                    today,
                                                                );

                                                            return (
                                                                <tr
                                                                    key={
                                                                        version.id
                                                                    }
                                                                    className="border-t border-slate-200"
                                                                >
                                                                    <td className="p-2">
                                                                        {
                                                                            version.valid_from
                                                                        }
                                                                    </td>
                                                                    <td className="p-2">
                                                                        {version.valid_to ??
                                                                            'sin vencimiento'}
                                                                    </td>
                                                                    <td className="p-2 font-semibold tabular-nums">
                                                                        {versionValue(
                                                                            tax,
                                                                            version,
                                                                        )}
                                                                    </td>
                                                                    <td className="p-2">
                                                                        {version.threshold_cents
                                                                            ? money(
                                                                                  version.threshold_cents,
                                                                              )
                                                                            : '—'}
                                                                    </td>
                                                                    <td className="p-2">
                                                                        {version.legal_reference ??
                                                                            '—'}
                                                                    </td>
                                                                    <td className="p-2">
                                                                        <span
                                                                            className={`rounded-full px-2 py-0.5 font-semibold ${state.tone}`}
                                                                        >
                                                                            {
                                                                                state.label
                                                                            }
                                                                        </span>
                                                                    </td>
                                                                    <td className="p-2 text-right">
                                                                        {canManage &&
                                                                            state.label ===
                                                                                'Programada' && (
                                                                                <button
                                                                                    type="button"
                                                                                    className="font-semibold text-red-700 hover:underline"
                                                                                    onClick={() =>
                                                                                        setCancelling(
                                                                                            version,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Anular
                                                                                </button>
                                                                            )}
                                                                    </td>
                                                                </tr>
                                                            );
                                                        },
                                                    )}
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            <CreateTaxDialog
                company={company}
                accounts={accounts}
                today={today}
                open={creating}
                onClose={() => setCreating(false)}
            />
            {editing && (
                <EditTaxDialog
                    company={company}
                    tax={editing}
                    accounts={accounts}
                    onClose={() => setEditing(null)}
                />
            )}
            {versioning && (
                <VersionDialog
                    company={company}
                    tax={versioning}
                    today={today}
                    onClose={() => setVersioning(null)}
                />
            )}
            {cancelling && (
                <CancelVersionDialog
                    company={company}
                    version={cancelling}
                    onClose={() => setCancelling(null)}
                />
            )}
        </section>
    );
}
