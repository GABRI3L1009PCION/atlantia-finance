import {
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock3,
    Download,
    FilePenLine,
    History,
    LoaderCircle,
    Paperclip,
    Plus,
    RefreshCw,
    Save,
    Trash2,
    Workflow,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    createOperationalRecord,
    deleteOperationalFile,
    getOperationalModule,
    getOperationalRecord,
    operationalFileUrl,
    transitionOperationalRecord,
    updateOperationalRecord,
    uploadOperationalFile,
} from '@/lib/operational-api';
import type {
    OperationalEvent,
    OperationalFile,
    OperationalRecord,
    OperationalWorkflow,
} from '@/lib/operational-api';
import { statusLabel } from '@/lib/status-labels';

type Props = {
    company: { public_id: string; name: string };
    module: string;
    title: string;
    canWrite: boolean;
    defaultOpen?: boolean;
};

const today = () => new Date().toISOString().slice(0, 10);
const label = statusLabel;
const money = (cents: number, currency: string) =>
    new Intl.NumberFormat('es-GT', { style: 'currency', currency }).format(
        cents / 100,
    );
type ModuleField = { key: string; label: string; placeholder: string };
const moduleFields: Record<string, ModuleField[]> = {
    conciliacion: [
        {
            key: 'bank_account',
            label: 'Cuenta bancaria',
            placeholder: 'Banco y terminación de cuenta',
        },
        {
            key: 'bank_balance',
            label: 'Saldo bancario',
            placeholder: 'Saldo del estado de cuenta',
        },
        {
            key: 'book_balance',
            label: 'Saldo en Atlantia',
            placeholder: 'Saldo contable',
        },
    ],
    reglas: [
        {
            key: 'condition',
            label: 'Condición',
            placeholder: 'Texto, referencia o monto',
        },
        {
            key: 'action',
            label: 'Acción',
            placeholder: 'Categoría o cuenta destino',
        },
        { key: 'priority', label: 'Prioridad', placeholder: '1 - 100' },
    ],
    caja: [
        {
            key: 'cashbox',
            label: 'Caja',
            placeholder: 'Caja general o caja chica',
        },
        {
            key: 'responsible',
            label: 'Responsable',
            placeholder: 'Nombre del responsable',
        },
        {
            key: 'movement',
            label: 'Movimiento',
            placeholder: 'Ingreso, egreso, arqueo o depósito',
        },
    ],
    inventario: [
        {
            key: 'product',
            label: 'Producto',
            placeholder: 'Código y descripción',
        },
        { key: 'warehouse', label: 'Bodega', placeholder: 'Bodega afectada' },
        { key: 'quantity', label: 'Cantidad', placeholder: 'Unidades' },
    ],
    kardex: [
        {
            key: 'product',
            label: 'Producto',
            placeholder: 'Código del producto',
        },
        { key: 'warehouse', label: 'Bodega', placeholder: 'Bodega' },
        {
            key: 'movement',
            label: 'Tipo de movimiento',
            placeholder: 'Entrada, salida, traslado o ajuste',
        },
    ],
    'inventario-fisico': [
        { key: 'warehouse', label: 'Bodega', placeholder: 'Bodega del conteo' },
        {
            key: 'responsible',
            label: 'Responsable',
            placeholder: 'Responsable del conteo',
        },
        {
            key: 'method',
            label: 'Método',
            placeholder: 'Conteo normal, ciego o cíclico',
        },
    ],
    'activos-fijos': [
        { key: 'asset', label: 'Activo', placeholder: 'Código y descripción' },
        {
            key: 'location',
            label: 'Ubicación',
            placeholder: 'Ubicación actual',
        },
        {
            key: 'responsible',
            label: 'Responsable',
            placeholder: 'Persona asignada',
        },
    ],
    nomina: [
        { key: 'period', label: 'Periodo', placeholder: 'Mes y año' },
        {
            key: 'employees',
            label: 'Empleados',
            placeholder: 'Cantidad de empleados',
        },
        { key: 'pay_date', label: 'Fecha de pago', placeholder: 'AAAA-MM-DD' },
    ],
    proyectos: [
        {
            key: 'client',
            label: 'Cliente',
            placeholder: 'Cliente del proyecto',
        },
        {
            key: 'responsible',
            label: 'Responsable',
            placeholder: 'Responsable del proyecto',
        },
        { key: 'deadline', label: 'Fecha final', placeholder: 'AAAA-MM-DD' },
    ],
    presupuestos: [
        { key: 'period', label: 'Ejercicio', placeholder: 'Año o periodo' },
        {
            key: 'cost_center',
            label: 'Centro de costos',
            placeholder: 'Centro de costos',
        },
        {
            key: 'account',
            label: 'Cuenta',
            placeholder: 'Cuenta presupuestaria',
        },
    ],
    normativa: [
        {
            key: 'code',
            label: 'Código normativo',
            placeholder: 'Código oficial',
        },
        {
            key: 'category',
            label: 'Categoría',
            placeholder: 'Fiscal, contable, laboral...',
        },
        {
            key: 'version',
            label: 'Versión',
            placeholder: 'Versión del documento',
        },
    ],
    documentos: [
        {
            key: 'category',
            label: 'Categoría',
            placeholder: 'Compras, ventas, bancos...',
        },
        {
            key: 'related_document',
            label: 'Documento relacionado',
            placeholder: 'Referencia relacionada',
        },
        { key: 'tags', label: 'Etiquetas', placeholder: 'Separadas por comas' },
    ],
    cotizaciones: [
        {
            key: 'client',
            label: 'Cliente',
            placeholder: 'Nombre o razón social',
        },
        {
            key: 'valid_until',
            label: 'Válida hasta',
            placeholder: 'AAAA-MM-DD',
        },
        {
            key: 'seller',
            label: 'Vendedor',
            placeholder: 'Responsable comercial',
        },
    ],
    'portal-cliente': [
        { key: 'client', label: 'Cliente', placeholder: 'Cliente solicitante' },
        {
            key: 'request',
            label: 'Solicitud',
            placeholder: 'Detalle solicitado',
        },
        { key: 'due_date', label: 'Fecha límite', placeholder: 'AAAA-MM-DD' },
    ],
    'notas-credito': [
        {
            key: 'invoice',
            label: 'Factura relacionada',
            placeholder: 'Serie y número',
        },
        { key: 'client', label: 'Cliente', placeholder: 'Receptor' },
        {
            key: 'reason_detail',
            label: 'Motivo',
            placeholder: 'Devolución, descuento o anulación',
        },
    ],
    'ordenes-compra': [
        {
            key: 'supplier',
            label: 'Proveedor',
            placeholder: 'Proveedor seleccionado',
        },
        {
            key: 'buyer',
            label: 'Comprador',
            placeholder: 'Responsable de compra',
        },
        {
            key: 'delivery_date',
            label: 'Fecha de entrega',
            placeholder: 'AAAA-MM-DD',
        },
    ],
    'libros-iva': [
        { key: 'book', label: 'Libro', placeholder: 'Compras o ventas' },
        { key: 'period', label: 'Periodo fiscal', placeholder: 'Mes y año' },
        {
            key: 'documents',
            label: 'Documentos',
            placeholder: 'Cantidad de documentos',
        },
    ],
    'cierre-periodo': [
        { key: 'period', label: 'Periodo contable', placeholder: 'Mes y año' },
        {
            key: 'responsible',
            label: 'Responsable',
            placeholder: 'Responsable del cierre',
        },
        {
            key: 'checklist',
            label: 'Checklist',
            placeholder: 'Validaciones requeridas',
        },
    ],
    'estados-cuenta': [
        {
            key: 'party_type',
            label: 'Tipo de tercero',
            placeholder: 'Cliente o proveedor',
        },
        {
            key: 'party',
            label: 'Tercero',
            placeholder: 'Nombre o razón social',
        },
        {
            key: 'cutoff_date',
            label: 'Fecha de corte',
            placeholder: 'AAAA-MM-DD',
        },
    ],
    recordatorios: [
        {
            key: 'category',
            label: 'Categoría',
            placeholder: 'Fiscal, contable, pago...',
        },
        {
            key: 'responsible',
            label: 'Responsable',
            placeholder: 'Usuario responsable',
        },
        {
            key: 'due_at',
            label: 'Vencimiento',
            placeholder: 'AAAA-MM-DD HH:mm',
        },
    ],
    auditoria: [
        {
            key: 'area',
            label: 'Área',
            placeholder: 'Módulo o proceso auditado',
        },
        {
            key: 'risk',
            label: 'Riesgo',
            placeholder: 'Bajo, medio, alto o crítico',
        },
        {
            key: 'finding',
            label: 'Hallazgo',
            placeholder: 'Descripción del hallazgo',
        },
    ],
    'usuarios-roles': [
        {
            key: 'user_email',
            label: 'Correo del usuario',
            placeholder: 'usuario@empresa.com',
        },
        { key: 'role', label: 'Rol', placeholder: 'Rol solicitado' },
        {
            key: 'scope',
            label: 'Alcance',
            placeholder: 'Empresa, oficina o módulo',
        },
    ],
};

export default function OperationalModuleCenter({
    company,
    module,
    title,
    canWrite,
    defaultOpen = false,
}: Props) {
    const [open, setOpen] = useState(defaultOpen);
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState(false);
    const [records, setRecords] = useState<OperationalRecord[]>([]);
    const [workflow, setWorkflow] = useState<OperationalWorkflow | null>(null);
    const [selected, setSelected] = useState<OperationalRecord | null>(null);
    const [events, setEvents] = useState<OperationalEvent[]>([]);
    const [files, setFiles] = useState<OperationalFile[]>([]);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [titleValue, setTitleValue] = useState('');
    const [typeValue, setTypeValue] = useState('registro');
    const [reference, setReference] = useState('');
    const [date, setDate] = useState(today());
    const [amount, setAmount] = useState('0.00');
    const [notes, setNotes] = useState('');
    const [details, setDetails] = useState<Record<string, string>>({});
    const [reason, setReason] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async () => {
        setBusy(true);
        setError('');

        try {
            const result = await getOperationalModule(
                company.public_id,
                module,
            );
            setRecords(result.records);
            setWorkflow(result.workflow);
            setSelected(
                (current) =>
                    result.records.find(
                        (item) => item.public_id === current?.public_id,
                    ) ??
                    result.records[0] ??
                    null,
            );
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'No fue posible cargar las operaciones.',
            );
        } finally {
            setBusy(false);
        }
    }, [company.public_id, module]);

    useEffect(() => {
        const timer = window.setTimeout(() => void load(), 0);

        return () => window.clearTimeout(timer);
    }, [load]);

    useEffect(() => {
        const handleNewOperation = (event: Event) => {
            const detail = (
                event as CustomEvent<{ module: string; title: string }>
            ).detail;

            if (detail.module !== module) {
                return;
            }

            setOpen(true);
            setCreating(true);
            setTitleValue(detail.title);
            window.setTimeout(
                () =>
                    document
                        .getElementById(`operational-center-${module}`)
                        ?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start',
                        }),
                0,
            );
        };
        window.addEventListener('atlantia:operation:new', handleNewOperation);

        return () =>
            window.removeEventListener(
                'atlantia:operation:new',
                handleNewOperation,
            );
    }, [module]);

    useEffect(() => {
        if (!selected) {
            return;
        }

        void getOperationalRecord(company.public_id, module, selected.public_id)
            .then((result) => {
                setEvents(result.record.events ?? []);
                setFiles(result.record.files ?? []);
            })
            .catch(() => {
                setEvents([]);
                setFiles([]);
            });
    }, [company.public_id, module, selected]);

    const allowed = useMemo(
        () => workflow?.transitions[selected?.status ?? ''] ?? [],
        [selected, workflow],
    );

    const create = async () => {
        if (!titleValue.trim()) {
            setError('Escribe el nombre de la operación.');

            return;
        }

        setBusy(true);
        setError('');

        try {
            const record = await createOperationalRecord(
                company.public_id,
                module,
                {
                    request_id: crypto.randomUUID(),
                    record_type: typeValue,
                    title: titleValue.trim(),
                    reference: reference.trim() || null,
                    effective_on: date,
                    currency: 'GTQ',
                    amount,
                    data: {
                        notes: notes.trim() || null,
                        ...details,
                        source: 'module_center',
                    },
                },
            );
            setRecords((items) => [record, ...items]);
            setSelected(record);
            setCreating(false);
            setTitleValue('');
            setReference('');
            setAmount('0.00');
            setNotes('');
            setDetails({});
            setNotice(`Operación creada en estado ${label(record.status)}.`);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'No fue posible crear la operación.',
            );
        } finally {
            setBusy(false);
        }
    };

    const save = async () => {
        if (!selected) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            const record = await updateOperationalRecord(
                company.public_id,
                module,
                selected.public_id,
                {
                    title: selected.title,
                    reference: selected.reference,
                    effective_on: selected.effective_on.slice(0, 10),
                    amount: (selected.amount_cents / 100).toFixed(2),
                    data: selected.data ?? {},
                },
            );
            setSelected(record);
            setRecords((items) =>
                items.map((item) =>
                    item.public_id === record.public_id ? record : item,
                ),
            );
            setNotice(`Cambios guardados · versión ${record.version}.`);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'No fue posible guardar.',
            );
        } finally {
            setBusy(false);
        }
    };

    const transition = async (status: string) => {
        if (!selected) {
            return;
        }

        if (workflow?.reasons.includes(status) && !reason.trim()) {
            setError(`Indica el motivo para cambiar a ${label(status)}.`);

            return;
        }

        setBusy(true);
        setError('');

        try {
            const record = await transitionOperationalRecord(
                company.public_id,
                module,
                selected.public_id,
                status,
                reason.trim() || undefined,
            );
            setSelected(record);
            setRecords((items) =>
                items.map((item) =>
                    item.public_id === record.public_id ? record : item,
                ),
            );
            setReason('');
            setNotice(`Estado actualizado a ${label(record.status)}.`);
            const detail = await getOperationalRecord(
                company.public_id,
                module,
                record.public_id,
            );
            setEvents(detail.record.events ?? []);
            setFiles(detail.record.files ?? []);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'No fue posible cambiar el estado.',
            );
        } finally {
            setBusy(false);
        }
    };

    const uploadFile = async (file: File) => {
        if (!selected) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            const uploaded = await uploadOperationalFile(
                company.public_id,
                module,
                selected.public_id,
                file,
            );
            setFiles((items) => [uploaded, ...items]);
            setNotice(`Archivo ${uploaded.original_name} adjuntado.`);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'No fue posible adjuntar el archivo.',
            );
        } finally {
            setBusy(false);

            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    const removeFile = async (file: OperationalFile) => {
        if (!selected) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await deleteOperationalFile(
                company.public_id,
                module,
                selected.public_id,
                file.public_id,
            );
            setFiles((items) =>
                items.filter((item) => item.public_id !== file.public_id),
            );
            setNotice(`Archivo ${file.original_name} eliminado.`);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'No fue posible eliminar el archivo.',
            );
        } finally {
            setBusy(false);
        }
    };

    const exportRecords = () => {
        const escape = (value: string | number) =>
            `"${String(value).replaceAll('"', '""')}"`;
        const rows = records.map((record) => [
            record.reference ?? '',
            record.title,
            label(record.status),
            record.effective_on.slice(0, 10),
            record.currency,
            (record.amount_cents / 100).toFixed(2),
            record.version,
        ]);
        const csv = [
            [
                'Referencia',
                'Nombre',
                'Estado',
                'Fecha',
                'Moneda',
                'Monto',
                'Versión',
            ],
            ...rows,
        ]
            .map((row) => row.map(escape).join(','))
            .join('\r\n');
        const url = URL.createObjectURL(
            new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }),
        );
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `${module}-${today()}.csv`;
        anchor.click();
        URL.revokeObjectURL(url);
        setNotice(`${records.length} registros exportados.`);
    };

    return (
        <section
            id={`operational-center-${module}`}
            className="scroll-mt-4 border-b border-slate-200 bg-white px-3 py-3 sm:px-5"
        >
            <div className="mx-auto max-w-[1900px] rounded-xl border border-blue-200 bg-blue-50/40 shadow-sm">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    className="flex w-full items-center gap-3 px-4 py-3 text-left"
                >
                    <span className="grid size-9 place-items-center rounded-lg bg-blue-700 text-white">
                        <Workflow className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <strong className="block text-sm">
                            Operaciones reales · {title}
                        </strong>
                        <small className="text-slate-500">
                            {records.length} registros persistentes para{' '}
                            {company.name}
                        </small>
                    </span>
                    {busy && (
                        <LoaderCircle className="size-4 animate-spin text-blue-700" />
                    )}
                    {open ? (
                        <ChevronUp className="size-5" />
                    ) : (
                        <ChevronDown className="size-5" />
                    )}
                </button>
                {open && (
                    <div className="grid gap-4 border-t p-4 xl:grid-cols-[1fr_1.1fr]">
                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                {canWrite && (
                                    <button
                                        type="button"
                                        onClick={() => setCreating(!creating)}
                                        className="flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-xs font-semibold text-white"
                                    >
                                        <Plus className="size-4" />
                                        Nueva operación
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => void load()}
                                    className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2 text-xs"
                                >
                                    <RefreshCw className="size-4" />
                                    Actualizar
                                </button>
                                <button
                                    type="button"
                                    disabled={records.length === 0}
                                    onClick={exportRecords}
                                    className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2 text-xs disabled:opacity-50"
                                >
                                    <Download className="size-4" />
                                    Exportar CSV
                                </button>
                                {notice && (
                                    <span className="flex items-center gap-1 text-xs text-emerald-700">
                                        <CheckCircle2 className="size-4" />
                                        {notice}
                                    </span>
                                )}
                            </div>
                            {error && (
                                <div className="flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                    <span>{error}</span>
                                    <button
                                        type="button"
                                        onClick={() => setError('')}
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                            )}
                            {creating && (
                                <div className="grid gap-2 rounded-lg border bg-white p-3 sm:grid-cols-2">
                                    <input
                                        aria-label="Nombre de la operación"
                                        value={titleValue}
                                        onChange={(event) =>
                                            setTitleValue(event.target.value)
                                        }
                                        placeholder="Nombre de la operación"
                                        className="rounded-lg border px-3 py-2 text-xs sm:col-span-2"
                                    />
                                    <input
                                        aria-label="Tipo de registro"
                                        value={typeValue}
                                        onChange={(event) =>
                                            setTypeValue(event.target.value)
                                        }
                                        placeholder="Tipo"
                                        className="rounded-lg border px-3 py-2 text-xs"
                                    />
                                    <input
                                        aria-label="Referencia"
                                        value={reference}
                                        onChange={(event) =>
                                            setReference(event.target.value)
                                        }
                                        placeholder="Referencia"
                                        className="rounded-lg border px-3 py-2 text-xs"
                                    />
                                    <input
                                        aria-label="Fecha efectiva"
                                        type="date"
                                        value={date}
                                        onChange={(event) =>
                                            setDate(event.target.value)
                                        }
                                        className="rounded-lg border px-3 py-2 text-xs"
                                    />
                                    <input
                                        aria-label="Monto"
                                        inputMode="decimal"
                                        value={amount}
                                        onChange={(event) =>
                                            setAmount(event.target.value)
                                        }
                                        placeholder="0.00"
                                        className="rounded-lg border px-3 py-2 text-xs"
                                    />
                                    {(moduleFields[module] ?? []).map(
                                        (field) => (
                                            <label
                                                key={field.key}
                                                className="text-[11px] font-semibold text-slate-600"
                                            >
                                                <span className="mb-1 block">
                                                    {field.label}
                                                </span>
                                                <input
                                                    aria-label={field.label}
                                                    value={
                                                        details[field.key] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setDetails({
                                                            ...details,
                                                            [field.key]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    placeholder={
                                                        field.placeholder
                                                    }
                                                    className="w-full rounded-lg border px-3 py-2 text-xs font-normal"
                                                />
                                            </label>
                                        ),
                                    )}
                                    <textarea
                                        aria-label="Notas"
                                        value={notes}
                                        onChange={(event) =>
                                            setNotes(event.target.value)
                                        }
                                        placeholder="Notas y contexto"
                                        className="rounded-lg border px-3 py-2 text-xs sm:col-span-2"
                                    />
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => void create()}
                                        className="rounded-lg bg-[#74000a] px-4 py-2 text-xs font-semibold text-white sm:col-span-2"
                                    >
                                        Crear registro
                                    </button>
                                </div>
                            )}
                            <div className="max-h-72 overflow-auto rounded-lg border bg-white">
                                {records.length === 0 && !busy ? (
                                    <p className="p-6 text-center text-xs text-slate-500">
                                        Todavía no existen operaciones reales.
                                    </p>
                                ) : (
                                    records.map((record) => (
                                        <button
                                            key={record.public_id}
                                            type="button"
                                            onClick={() => setSelected(record)}
                                            className={`grid w-full grid-cols-[1fr_auto] gap-3 border-b px-3 py-2 text-left text-xs ${selected?.public_id === record.public_id ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                                        >
                                            <span>
                                                <strong className="block">
                                                    {record.title}
                                                </strong>
                                                <small className="text-slate-500">
                                                    {record.reference ??
                                                        record.record_type}{' '}
                                                    ·{' '}
                                                    {record.effective_on.slice(
                                                        0,
                                                        10,
                                                    )}
                                                </small>
                                            </span>
                                            <span className="text-right">
                                                <b className="block text-blue-800">
                                                    {label(record.status)}
                                                </b>
                                                <small>
                                                    {money(
                                                        record.amount_cents,
                                                        record.currency,
                                                    )}
                                                </small>
                                            </span>
                                        </button>
                                    ))
                                )}
                            </div>
                        </div>
                        <div className="rounded-lg border bg-white p-3">
                            {!selected ? (
                                <div className="grid min-h-48 place-items-center text-xs text-slate-500">
                                    Selecciona o crea una operación.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <strong>{selected.title}</strong>
                                            <p className="text-xs text-slate-500">
                                                Versión {selected.version} ·{' '}
                                                {label(selected.status)}
                                            </p>
                                        </div>
                                        <FilePenLine className="size-5 text-blue-700" />
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <input
                                            aria-label="Editar nombre"
                                            value={selected.title}
                                            disabled={!canWrite}
                                            onChange={(event) =>
                                                setSelected({
                                                    ...selected,
                                                    title: event.target.value,
                                                })
                                            }
                                            className="rounded-lg border px-3 py-2 text-xs sm:col-span-2"
                                        />
                                        <input
                                            aria-label="Editar referencia"
                                            value={selected.reference ?? ''}
                                            disabled={!canWrite}
                                            onChange={(event) =>
                                                setSelected({
                                                    ...selected,
                                                    reference:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border px-3 py-2 text-xs"
                                        />
                                        <input
                                            aria-label="Editar fecha"
                                            type="date"
                                            value={selected.effective_on.slice(
                                                0,
                                                10,
                                            )}
                                            disabled={!canWrite}
                                            onChange={(event) =>
                                                setSelected({
                                                    ...selected,
                                                    effective_on:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border px-3 py-2 text-xs"
                                        />
                                    </div>
                                    {Object.entries(selected.data ?? {}).filter(
                                        ([key]) => key !== 'source',
                                    ).length > 0 && (
                                        <div className="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
                                            {Object.entries(selected.data ?? {})
                                                .filter(
                                                    ([key]) => key !== 'source',
                                                )
                                                .map(([key, value]) => (
                                                    <label
                                                        key={key}
                                                        className="text-[11px] font-semibold text-slate-600"
                                                    >
                                                        <span className="mb-1 block">
                                                            {key.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </span>
                                                        <input
                                                            value={String(
                                                                value ?? '',
                                                            )}
                                                            disabled={!canWrite}
                                                            onChange={(event) =>
                                                                setSelected({
                                                                    ...selected,
                                                                    data: {
                                                                        ...(selected.data ??
                                                                            {}),
                                                                        [key]: event
                                                                            .target
                                                                            .value,
                                                                    },
                                                                })
                                                            }
                                                            className="w-full rounded-lg border bg-white px-3 py-2 text-xs font-normal"
                                                        />
                                                    </label>
                                                ))}
                                        </div>
                                    )}
                                    {canWrite && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => void save()}
                                            className="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-semibold"
                                        >
                                            <Save className="size-4" />
                                            Guardar edición
                                        </button>
                                    )}
                                    {allowed.length > 0 && canWrite && (
                                        <div className="space-y-2 border-t pt-3">
                                            <p className="text-xs font-semibold">
                                                Siguiente estado permitido
                                            </p>
                                            <input
                                                value={reason}
                                                onChange={(event) =>
                                                    setReason(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Motivo cuando sea obligatorio"
                                                className="w-full rounded-lg border px-3 py-2 text-xs"
                                            />
                                            <div className="flex flex-wrap gap-2">
                                                {allowed.map((status) => (
                                                    <button
                                                        key={status}
                                                        type="button"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void transition(
                                                                status,
                                                            )
                                                        }
                                                        className="rounded-full bg-[#74000a] px-3 py-1.5 text-[11px] font-semibold text-white"
                                                    >
                                                        {label(status)}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    <div className="rounded-lg border p-3">
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <p className="flex items-center gap-2 text-xs font-semibold">
                                                <Paperclip className="size-4" />
                                                Archivos privados
                                            </p>
                                            {canWrite && (
                                                <label className="cursor-pointer rounded-md border px-2 py-1 text-[11px] font-medium">
                                                    Adjuntar archivo
                                                    <input
                                                        ref={fileInput}
                                                        type="file"
                                                        className="hidden"
                                                        disabled={busy}
                                                        accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.csv,.doc,.docx,.txt,.xml"
                                                        onChange={(event) => {
                                                            const file =
                                                                event.target
                                                                    .files?.[0];

                                                            if (file) {
                                                                void uploadFile(
                                                                    file,
                                                                );
                                                            }
                                                        }}
                                                    />
                                                </label>
                                            )}
                                        </div>
                                        {files.length === 0 ? (
                                            <p className="text-[11px] text-slate-500">
                                                Sin archivos adjuntos.
                                            </p>
                                        ) : (
                                            <div className="space-y-1">
                                                {files.map((file) => (
                                                    <div
                                                        key={file.public_id}
                                                        className="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-2 py-1.5 text-[11px]"
                                                    >
                                                        <a
                                                            className="min-w-0 truncate text-blue-700 hover:underline"
                                                            href={operationalFileUrl(
                                                                company.public_id,
                                                                module,
                                                                selected.public_id,
                                                                file.public_id,
                                                            )}
                                                        >
                                                            {file.original_name}
                                                        </a>
                                                        {canWrite && (
                                                            <button
                                                                type="button"
                                                                aria-label={`Eliminar ${file.original_name}`}
                                                                disabled={busy}
                                                                onClick={() =>
                                                                    void removeFile(
                                                                        file,
                                                                    )
                                                                }
                                                                className="text-red-700"
                                                            >
                                                                <Trash2 className="size-3.5" />
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <div className="border-t pt-3">
                                        <p className="mb-2 flex items-center gap-2 text-xs font-semibold">
                                            <History className="size-4" />
                                            Historial verificable
                                        </p>
                                        <div className="max-h-36 space-y-2 overflow-auto">
                                            {events.map((event) => (
                                                <div
                                                    key={event.id}
                                                    className="flex gap-2 text-[11px]"
                                                >
                                                    <Clock3 className="mt-0.5 size-3 shrink-0 text-slate-400" />
                                                    <span>
                                                        <b>{event.action}</b>
                                                        {event.to_status
                                                            ? ` · ${label(event.to_status)}`
                                                            : ''}
                                                        <small className="block text-slate-500">
                                                            {new Date(
                                                                event.occurred_at,
                                                            ).toLocaleString(
                                                                'es-GT',
                                                            )}{' '}
                                                            ·{' '}
                                                            {event.user?.name ??
                                                                'Sistema'}
                                                        </small>
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
