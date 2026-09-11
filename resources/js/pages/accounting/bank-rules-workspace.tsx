import {
    Beaker,
    BookOpen,
    Building2,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    Clock3,
    MoreVertical,
    Plus,
    Search,
    Sparkles,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type RuleStatus = 'active' | 'review' | 'inactive';

type BankRule = {
    id: number;
    name: string;
    bank: string;
    condition: string;
    action: string;
    applications: number;
    status: RuleStatus;
    description: string;
    account: string;
};

const seedRules: BankRule[] = [
    {
        id: 1,
        name: 'Comisiones Banco Industrial',
        bank: 'Banco Industrial',
        condition: 'Contiene "COMISIÓN"',
        action: 'Gastos bancarios',
        applications: 28,
        status: 'active',
        description:
            'Clasifica automáticamente las comisiones bancarias del Banco Industrial.',
        account: '6.2.05 - Gastos bancarios',
    },
    {
        id: 2,
        name: 'Cobros Comercial López',
        bank: 'Todos',
        condition: 'Contiene "COMERCIAL LÓPEZ"',
        action: 'Buscar cobro',
        applications: 14,
        status: 'active',
        description: 'Busca y relaciona cobros recibidos de Comercial López.',
        account: '1.1.02 - Bancos',
    },
    {
        id: 3,
        name: 'Transferencias internas',
        bank: 'Todos',
        condition: 'Cuenta propia',
        action: 'Transferencia interna',
        applications: 19,
        status: 'active',
        description: 'Reconoce transferencias entre cuentas propias.',
        account: '1.1.02 - Bancos',
    },
    {
        id: 4,
        name: 'Energía eléctrica',
        bank: 'Banco Industrial',
        condition: 'Contiene "ENERGÍA"',
        action: 'Servicios básicos',
        applications: 11,
        status: 'active',
        description: 'Clasifica pagos recurrentes de energía eléctrica.',
        account: '6.2.03 - Servicios básicos',
    },
    {
        id: 5,
        name: 'Pago de nómina',
        bank: 'Todos',
        condition: 'Contiene "NÓMINA"',
        action: 'Nómina',
        applications: 8,
        status: 'active',
        description: 'Relaciona débitos con el pago mensual de nómina.',
        account: '6.1.01 - Sueldos y salarios',
    },
    {
        id: 6,
        name: 'Pago SAT',
        bank: 'Todos',
        condition: 'Contiene "SAT"',
        action: 'Pago fiscal',
        applications: 12,
        status: 'active',
        description: 'Identifica pagos de impuestos a SAT.',
        account: '2.1.04 - Impuestos por pagar',
    },
    {
        id: 7,
        name: 'Intereses bancarios',
        bank: 'Banco Industrial',
        condition: 'Contiene "INTERÉS"',
        action: 'Ingresos financieros',
        applications: 6,
        status: 'active',
        description: 'Clasifica los intereses acreditados por el banco.',
        account: '5.2.01 - Ingresos financieros',
    },
    {
        id: 8,
        name: 'Tarjetas de crédito',
        bank: 'Todos',
        condition: 'Contiene "VISA" o "MASTERCARD"',
        action: 'Buscar cobro',
        applications: 9,
        status: 'review',
        description:
            'Busca liquidaciones de tarjetas pendientes de confirmación.',
        account: '1.1.03 - Cobros por acreditar',
    },
    {
        id: 9,
        name: 'Seguro de vehículo',
        bank: 'Todos',
        condition: 'Contiene "SEGURO"',
        action: 'Seguros',
        applications: 4,
        status: 'active',
        description: 'Clasifica pagos de pólizas de vehículos.',
        account: '6.2.06 - Seguros',
    },
    {
        id: 10,
        name: 'Servicios varios',
        bank: 'Todos',
        condition: 'Monto entre Q50 - Q500',
        action: 'Requiere revisión',
        applications: 3,
        status: 'inactive',
        description: 'Sugiere una revisión manual para cargos pequeños.',
        account: '6.2.99 - Otros servicios',
    },
];

const statusPresentation = {
    active: {
        label: 'Activa',
        className: 'bg-emerald-100 text-emerald-700',
        dot: 'bg-emerald-600',
    },
    review: {
        label: 'Revisión',
        className: 'bg-amber-100 text-amber-700',
        dot: 'bg-amber-500',
    },
    inactive: {
        label: 'Inactiva',
        className: 'bg-slate-200 text-slate-600',
        dot: 'bg-slate-500',
    },
};

const inputClass =
    'mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs font-medium text-slate-800 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100';

export default function BankRulesWorkspace({
    company,
}: {
    company: { name: string };
}) {
    const [rules, setRules] = useState(seedRules);
    const [selectedId, setSelectedId] = useState(1);
    const [query, setQuery] = useState('');
    const [bankFilter, setBankFilter] = useState('Todos los bancos');
    const [statusFilter, setStatusFilter] = useState('Todos los estados');
    const [activeTab, setActiveTab] = useState('General');
    const [stopAfterMatch, setStopAfterMatch] = useState(true);
    const [notice, setNotice] = useState('');
    const selected = rules.find((rule) => rule.id === selectedId) ?? rules[0];
    const filtered = useMemo(
        () =>
            rules.filter((rule) => {
                const matchesQuery =
                    `${rule.name} ${rule.bank} ${rule.condition} ${rule.action}`
                        .toLowerCase()
                        .includes(query.toLowerCase());
                const matchesBank =
                    bankFilter === 'Todos los bancos' ||
                    rule.bank === bankFilter;
                const matchesStatus =
                    statusFilter === 'Todos los estados' ||
                    rule.status === statusFilter;

                return matchesQuery && matchesBank && matchesStatus;
            }),
        [bankFilter, query, rules, statusFilter],
    );

    const updateSelected = (field: keyof BankRule, value: string | number) => {
        setRules((current) =>
            current.map((rule) =>
                rule.id === selected.id ? { ...rule, [field]: value } : rule,
            ),
        );
    };

    const addRule = () => {
        const id = Math.max(...rules.map((rule) => rule.id)) + 1;
        setRules((current) => [
            {
                id,
                name: 'Nueva regla bancaria',
                bank: 'Todos',
                condition: 'Define una condición',
                action: 'Requiere revisión',
                applications: 0,
                status: 'review',
                description:
                    'Describe qué movimientos debe clasificar esta regla.',
                account: 'Seleccionar cuenta contable',
            },
            ...current,
        ]);
        setSelectedId(id);
        setNotice('Nueva regla creada como borrador para revisión.');
    };

    return (
        <main className="min-h-full bg-[#f5f7fb] p-3 text-[#172033] sm:p-5">
            <div className="mx-auto max-w-[1750px] space-y-4">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Bancos</span>
                    <ChevronRight className="size-3" />
                    <span>Reglas bancarias</span>
                </div>
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Reglas bancarias
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Automatiza la clasificación de tus movimientos
                            bancarios con reglas inteligentes
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                setNotice(
                                    'La guía explica condiciones, prioridades y niveles de automatización.',
                                )
                            }
                            className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-blue-700 shadow-sm"
                        >
                            <BookOpen className="size-4" /> Ver guía
                        </button>
                        <button
                            type="button"
                            onClick={addRule}
                            className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-700/20 hover:bg-blue-700"
                        >
                            <Plus className="size-4" /> Nueva regla
                        </button>
                    </div>
                </header>

                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                        <span className="flex items-center gap-2">
                            <Sparkles className="size-4" />
                            {notice}
                        </span>
                        <button
                            type="button"
                            onClick={() => setNotice('')}
                            aria-label="Cerrar aviso"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(430px,.7fr)]">
                    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="grid gap-2 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-[1.5fr_.75fr_.85fr_.75fr]">
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Buscar reglas..."
                                    className="w-full rounded-lg border border-slate-200 py-2.5 pr-3 pl-9 text-xs outline-none focus:border-blue-500"
                                />
                            </label>
                            <label className="relative">
                                <select
                                    value={bankFilter}
                                    onChange={(event) =>
                                        setBankFilter(event.target.value)
                                    }
                                    className="w-full appearance-none rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs font-medium outline-none"
                                >
                                    <option>Todos los bancos</option>
                                    <option>Banco Industrial</option>
                                    <option>Todos</option>
                                </select>
                                <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-400" />
                            </label>
                            <button
                                type="button"
                                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs font-medium"
                            >
                                Todas las categorías
                                <ChevronDown className="size-4 text-slate-400" />
                            </button>
                            <label className="relative">
                                <select
                                    value={statusFilter}
                                    onChange={(event) =>
                                        setStatusFilter(event.target.value)
                                    }
                                    className="w-full appearance-none rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs font-medium outline-none"
                                >
                                    <option>Todos los estados</option>
                                    <option value="active">Activa</option>
                                    <option value="review">Revisión</option>
                                    <option value="inactive">Inactiva</option>
                                </select>
                                <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-400" />
                            </label>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[820px] text-left text-xs">
                                <thead className="border-b border-slate-200 bg-slate-50 text-[10px] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">#</th>
                                        <th>Nombre de la regla</th>
                                        <th>Banco</th>
                                        <th>Condición principal</th>
                                        <th>Acción</th>
                                        <th className="text-center">
                                            Aplicaciones
                                        </th>
                                        <th>Estado</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filtered.map((rule) => {
                                        const status =
                                            statusPresentation[rule.status];

                                        return (
                                            <tr
                                                key={rule.id}
                                                onClick={() =>
                                                    setSelectedId(rule.id)
                                                }
                                                className={`cursor-pointer transition hover:bg-blue-50/60 ${selected.id === rule.id ? 'bg-blue-50' : ''}`}
                                            >
                                                <td className="px-4 py-3 text-slate-500">
                                                    {rule.id}
                                                </td>
                                                <td className="max-w-40 font-semibold text-[#10245c]">
                                                    {rule.name}
                                                </td>
                                                <td>{rule.bank}</td>
                                                <td className="max-w-40">
                                                    {rule.condition}
                                                </td>
                                                <td>{rule.action}</td>
                                                <td className="text-center font-semibold text-blue-700">
                                                    {rule.applications}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[10px] font-semibold ${status.className}`}
                                                    >
                                                        <i
                                                            className={`size-2 rounded-full ${status.dot}`}
                                                        />
                                                        {status.label}
                                                    </span>
                                                </td>
                                                <td className="pr-3 text-right">
                                                    <button
                                                        type="button"
                                                        aria-label={`Acciones de ${rule.name}`}
                                                    >
                                                        <MoreVertical className="size-4 text-slate-500" />
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            {filtered.length === 0 && (
                                <div className="p-10 text-center text-sm text-slate-400">
                                    No hay reglas que coincidan con los filtros.
                                </div>
                            )}
                        </div>
                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                            <span>
                                Mostrando 1 a {filtered.length} de{' '}
                                {rules.length} reglas
                            </span>
                            <div className="flex items-center gap-1">
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded bg-blue-600 font-semibold text-white"
                                >
                                    1
                                </button>
                                <button
                                    type="button"
                                    className="grid size-8 place-items-center rounded border border-slate-200"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                            <button
                                type="button"
                                className="flex items-center gap-6 rounded-lg border border-slate-200 px-3 py-2"
                            >
                                25 por página
                                <ChevronDown className="size-4" />
                            </button>
                        </footer>
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-lg font-bold">
                                    {selected.name}
                                </h2>
                                <span
                                    className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[10px] font-semibold ${statusPresentation[selected.status].className}`}
                                >
                                    <i
                                        className={`size-2 rounded-full ${statusPresentation[selected.status].dot}`}
                                    />
                                    {statusPresentation[selected.status].label}
                                </span>
                            </div>
                            <MoreVertical className="size-5 text-slate-500" />
                        </div>
                        <nav className="mt-4 flex gap-5 border-b border-slate-200 text-xs font-semibold">
                            {[
                                'General',
                                'Condiciones',
                                'Acciones',
                                'Historial',
                            ].map((tab) => (
                                <button
                                    key={tab}
                                    type="button"
                                    onClick={() => setActiveTab(tab)}
                                    className={`border-b-2 px-2 py-3 ${activeTab === tab ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'}`}
                                >
                                    {tab}
                                </button>
                            ))}
                        </nav>

                        {activeTab === 'General' ? (
                            <div className="mt-4 space-y-4 text-xs">
                                <label className="block font-semibold">
                                    Nombre de la regla{' '}
                                    <span className="text-red-500">*</span>
                                    <input
                                        value={selected.name}
                                        onChange={(event) =>
                                            updateSelected(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </label>
                                <label className="block font-semibold">
                                    Descripción
                                    <textarea
                                        value={selected.description}
                                        onChange={(event) =>
                                            updateSelected(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                        className={inputClass}
                                    />
                                </label>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="font-semibold">
                                        Banco
                                        <select
                                            value={selected.bank}
                                            onChange={(event) =>
                                                updateSelected(
                                                    'bank',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        >
                                            <option>Banco Industrial</option>
                                            <option>Banrural</option>
                                            <option>BAC</option>
                                            <option>Todos</option>
                                        </select>
                                    </label>
                                    <label className="font-semibold">
                                        Tipo de movimiento
                                        <select className={inputClass}>
                                            <option>Salida (Débito)</option>
                                            <option>Entrada (Crédito)</option>
                                            <option>Ambos</option>
                                        </select>
                                    </label>
                                    <label className="font-semibold">
                                        Categoría
                                        <select
                                            value={selected.action}
                                            onChange={(event) =>
                                                updateSelected(
                                                    'action',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        >
                                            <option>Gastos bancarios</option>
                                            <option>Buscar cobro</option>
                                            <option>
                                                Transferencia interna
                                            </option>
                                            <option>Servicios básicos</option>
                                            <option>Requiere revisión</option>
                                        </select>
                                    </label>
                                    <label className="font-semibold">
                                        Cuenta contable
                                        <select
                                            value={selected.account}
                                            onChange={(event) =>
                                                updateSelected(
                                                    'account',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        >
                                            <option>{selected.account}</option>
                                            <option>
                                                6.2.05 - Gastos bancarios
                                            </option>
                                            <option>1.1.02 - Bancos</option>
                                        </select>
                                    </label>
                                    <label className="font-semibold">
                                        Prioridad{' '}
                                        <CircleHelp className="ml-1 inline size-3.5 text-slate-400" />
                                        <input
                                            type="number"
                                            defaultValue={10}
                                            className={inputClass}
                                        />
                                    </label>
                                    <label className="font-semibold">
                                        Nivel de automatización{' '}
                                        <CircleHelp className="ml-1 inline size-3.5 text-slate-400" />
                                        <select className={inputClass}>
                                            <option>Solo sugerir</option>
                                            <option>Aplicar y notificar</option>
                                            <option>
                                                Aplicar automáticamente
                                            </option>
                                        </select>
                                    </label>
                                </div>
                                <div className="flex items-start gap-3 rounded-lg bg-slate-50 p-3">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setStopAfterMatch(
                                                (current) => !current,
                                            )
                                        }
                                        aria-pressed={stopAfterMatch}
                                        className={`relative mt-0.5 h-6 w-11 rounded-full transition ${stopAfterMatch ? 'bg-blue-600' : 'bg-slate-300'}`}
                                    >
                                        <span
                                            className={`absolute top-1 size-4 rounded-full bg-white transition ${stopAfterMatch ? 'left-6' : 'left-1'}`}
                                        />
                                    </button>
                                    <div>
                                        <p className="font-semibold">
                                            Detener después de aplicar esta
                                            regla
                                        </p>
                                        <p className="mt-1 text-[11px] text-slate-500">
                                            Evita que otras reglas se apliquen
                                            al mismo movimiento.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="grid min-h-80 place-items-center rounded-lg bg-slate-50 p-8 text-center">
                                <div>
                                    <Sparkles className="mx-auto size-8 text-blue-500" />
                                    <h3 className="mt-3 font-semibold">
                                        {activeTab} de la regla
                                    </h3>
                                    <p className="mt-1 max-w-sm text-xs text-slate-500">
                                        Configura y revisa este apartado para
                                        controlar cómo se ejecuta la
                                        automatización.
                                    </p>
                                </div>
                            </div>
                        )}

                        <div className="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        'Los cambios sin guardar fueron descartados.',
                                    )
                                }
                                className="rounded-lg border border-slate-200 px-5 py-2.5 text-xs font-semibold text-slate-600"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        `Prueba completada: ${selected.applications || 24} coincidencias encontradas.`,
                                    )
                                }
                                className="rounded-lg border border-blue-500 px-5 py-2.5 text-xs font-semibold text-blue-700"
                            >
                                Probar regla
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        `Cambios guardados en ${selected.name}.`,
                                    )
                                }
                                className="rounded-lg bg-blue-600 px-5 py-2.5 text-xs font-semibold text-white shadow-md shadow-blue-700/20"
                            >
                                Guardar cambios
                            </button>
                        </div>
                    </section>
                </div>

                <div className="grid gap-4 xl:grid-cols-[1.35fr_1fr]">
                    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="flex items-center gap-2 text-base font-bold">
                                    <Beaker className="size-5 text-blue-600" />{' '}
                                    Probar regla con movimientos históricos
                                </h2>
                                <p className="mt-2 text-xs text-slate-500">
                                    Esta regla coincidiría con 24 movimientos en
                                    los últimos 6 meses.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    setNotice(
                                        'Prueba histórica ejecutada: 22 coincidencias correctas y 2 para revisión.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border border-blue-500 px-4 py-2 text-xs font-semibold text-blue-700"
                            >
                                <Sparkles className="size-4" /> Ejecutar prueba
                            </button>
                        </div>
                        <div className="mt-4 grid gap-3 sm:grid-cols-3">
                            {[
                                [
                                    '22',
                                    'Coincidencias correctas',
                                    '92%',
                                    'bg-emerald-100 text-emerald-700',
                                ],
                                [
                                    '2',
                                    'Requieren revisión',
                                    '8%',
                                    'bg-amber-100 text-amber-700',
                                ],
                                [
                                    '0',
                                    'Posibles errores',
                                    '0%',
                                    'bg-red-100 text-red-700',
                                ],
                            ].map(([value, label, percent, tone]) => (
                                <article
                                    key={label}
                                    className="flex items-center gap-3 rounded-lg border border-slate-200 p-3"
                                >
                                    <strong
                                        className={`grid size-14 place-items-center rounded-lg text-2xl ${tone}`}
                                    >
                                        {value}
                                    </strong>
                                    <div>
                                        <p className="text-xs font-semibold">
                                            {label}
                                        </p>
                                        <strong className="mt-1 block text-sm">
                                            {percent}
                                        </strong>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                            <h2 className="flex items-center gap-2 text-sm font-bold">
                                <Clock3 className="size-4 text-blue-600" />{' '}
                                Últimas aplicaciones
                            </h2>
                            <button
                                type="button"
                                className="text-xs font-medium text-blue-600"
                            >
                                Ver todas
                            </button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[580px] text-left text-[10px]">
                                <thead className="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th className="px-4 py-2">Fecha</th>
                                        <th>Movimiento</th>
                                        <th>Descripción</th>
                                        <th>Resultado</th>
                                        <th>Usuario</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {[
                                        [
                                            '05/09/2026',
                                            'MB-00852',
                                            'COMISIÓN MANEJO DE CUENTA',
                                            'Ana López',
                                        ],
                                        [
                                            '28/08/2026',
                                            'MB-00741',
                                            'COMISIÓN TRANSFERENCIA',
                                            'Ana López',
                                        ],
                                        [
                                            '15/08/2026',
                                            'MB-00698',
                                            'COMISIÓN CTA.',
                                            'Sistema',
                                        ],
                                        [
                                            '03/08/2026',
                                            'MB-00612',
                                            'COMISIÓN BANCARIA',
                                            'Ana López',
                                        ],
                                    ].map(
                                        ([
                                            date,
                                            movement,
                                            description,
                                            user,
                                        ]) => (
                                            <tr key={movement}>
                                                <td className="px-4 py-2">
                                                    {date}
                                                </td>
                                                <td className="font-semibold text-blue-600">
                                                    {movement}
                                                </td>
                                                <td>{description}</td>
                                                <td>
                                                    <span className="rounded bg-emerald-100 px-2 py-1 text-emerald-700">
                                                        Clasificado
                                                    </span>
                                                </td>
                                                <td>{user}</td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <footer className="flex items-center justify-between text-[10px] text-slate-400">
                    <span>{company.name} · Motor de reglas bancarias</span>
                    <span className="flex items-center gap-1">
                        <Building2 className="size-3" /> Datos aislados por
                        empresa <Check className="size-3 text-emerald-600" />
                    </span>
                </footer>
            </div>
        </main>
    );
}
