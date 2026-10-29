import { Head } from '@inertiajs/react';
import {
    BookOpen,
    Bot,
    Building2,
    CheckCircle2,
    ChevronDown,
    Cpu,
    Landmark,
    Package,
    Pencil,
    Play,
    Save,
    Settings2,
    ShieldCheck,
    ShoppingCart,
    Sparkles,
    Tag,
    Users,
    WalletCards,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    createOperationalRecord,
    listOperationalRecords,
    transitionOperationalRecord,
    updateOperationalRecord,
} from '@/lib/operational-api';
import type { OperationalRecord } from '@/lib/operational-api';

const panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';

function Metric({
    icon: Icon,
    label,
    value,
    detail,
    tone,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone: string;
}) {
    return (
        <article className={`${panel} flex min-h-24 items-center gap-4 p-5`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone}`}
            >
                <Icon className="size-6" />
            </span>
            <div className="min-w-0">
                <p className="text-xs font-semibold text-slate-600">{label}</p>
                <strong className="mt-1 block truncate text-xl text-[#111b36] sm:text-2xl">
                    {value}
                </strong>
                <p className="text-xs text-slate-500">{detail}</p>
            </div>
        </article>
    );
}

function Toggle({
    enabled,
    onChange,
    label,
}: {
    enabled: boolean;
    onChange: () => void;
    label: string;
}) {
    return (
        <button
            type="button"
            onClick={onChange}
            aria-label={label}
            aria-pressed={enabled}
            className={`relative h-6 w-11 shrink-0 rounded-full transition ${enabled ? 'bg-[#74000a]' : 'bg-slate-300'}`}
        >
            <span
                className={`absolute top-1 size-4 rounded-full bg-white shadow transition ${enabled ? 'left-6' : 'left-1'}`}
            />
        </button>
    );
}

const sections: { label: string; icon: LucideIcon }[] = [
    { label: 'Empresa', icon: Building2 },
    { label: 'Contabilidad', icon: WalletCards },
    { label: 'Ventas y Compras', icon: ShoppingCart },
    { label: 'Bancos y Caja', icon: Landmark },
    { label: 'Inventario', icon: Package },
    { label: 'Nómina', icon: Users },
    { label: 'Activos', icon: Tag },
    { label: 'Fiscal', icon: BookOpen },
    { label: 'Seguridad', icon: ShieldCheck },
    { label: 'Luzma', icon: Bot },
];

export default function ConfigurationWorkspace({
    company,
}: {
    company: { name: string; public_id: string };
}) {
    const [activeSection, setActiveSection] = useState('Luzma');
    const [enabled, setEnabled] = useState(true);
    const [automaticActions, setAutomaticActions] = useState(false);
    const [humanApproval, setHumanApproval] = useState(true);
    const [model, setModel] = useState('qwen3:8b-q4_K_M');
    const [processing, setProcessing] = useState(
        'Local (en servidores de la empresa)',
    );
    const [url, setUrl] = useState('http://127.0.0.1:8100');
    const [notice, setNotice] = useState('');
    const [savedRecord, setSavedRecord] = useState<OperationalRecord | null>(
        null,
    );
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        void listOperationalRecords(company.public_id, 'configuracion')
            .then((records) => {
                const record = records.find(
                    (item) => item.record_type === 'atlantia_ai',
                );

                if (!record) {
                    return;
                }

                const data = record.data ?? {};

                setSavedRecord(record);
                setEnabled(data.enabled !== false);
                setAutomaticActions(data.automatic_actions === true);
                setHumanApproval(data.human_approval !== false);

                if (typeof data.model === 'string') {
                    setModel(data.model);
                }

                if (typeof data.processing === 'string') {
                    setProcessing(data.processing);
                }

                if (typeof data.url === 'string') {
                    setUrl(data.url);
                }
            })
            .catch((error: unknown) => {
                setNotice(
                    error instanceof Error
                        ? error.message
                        : 'No fue posible cargar la configuración.',
                );
            });
    }, [company.public_id]);

    const showNotice = (message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(''), 3400);
    };

    const saveConfiguration = async () => {
        setSaving(true);

        try {
            const data = {
                enabled,
                automatic_actions: automaticActions,
                human_approval: humanApproval,
                model,
                processing,
                url,
            };
            let record = savedRecord;

            if (!record) {
                record = await createOperationalRecord(
                    company.public_id,
                    'configuracion',
                    {
                        request_id: crypto.randomUUID(),
                        record_type: 'atlantia_ai',
                        title: 'Configuración de Luzma',
                        effective_on: new Date().toISOString().slice(0, 10),
                        amount: '0.00',
                        data,
                    },
                );
            } else {
                if (record.status === 'active') {
                    record = await transitionOperationalRecord(
                        company.public_id,
                        'configuracion',
                        record.public_id,
                        'draft',
                    );
                }

                record = await updateOperationalRecord(
                    company.public_id,
                    'configuracion',
                    record.public_id,
                    { data },
                );
            }

            record = await transitionOperationalRecord(
                company.public_id,
                'configuracion',
                record.public_id,
                'active',
            );
            setSavedRecord(record);
            showNotice(
                `Configuración guardada y activada · versión ${record.version}.`,
            );
        } catch (error) {
            showNotice(
                error instanceof Error
                    ? error.message
                    : 'No fue posible guardar la configuración.',
            );
        } finally {
            setSaving(false);
        }
    };

    const testConnection = async () => {
        try {
            const response = await fetch(
                `/accounting/companies/${company.public_id}/configuration/ai/health`,
                {
                    headers: { Accept: 'application/json' },
                },
            );
            const result = (await response.json()) as { message?: string };

            showNotice(
                result.message ??
                    (response.ok
                        ? 'Conexión comprobada correctamente.'
                        : 'Luzma no respondió.'),
            );
        } catch {
            showNotice('No fue posible conectar con Luzma.');
        }
    };

    return (
        <main className="min-h-full overflow-x-hidden bg-[#f6f8fb] p-3 text-[#172033] sm:p-5">
            <Head title={`Configuración · ${company.name}`} />
            <div className="mx-auto max-w-[1900px] space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs text-slate-500">
                            Inicio <span className="px-2">›</span> Configuración
                        </p>
                        <h1 className="text-3xl font-bold text-[#74000a]">
                            Configuración
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Parámetros generales, módulos e integraciones del
                            sistema
                        </p>
                    </div>
                    <p className="max-w-sm text-right text-xs text-slate-500 italic">
                        “Configuración hoy, eficiencia mañana.”
                        <br />— Atlantia Finance
                    </p>
                </header>

                {notice && (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
                        <span className="flex items-center gap-2">
                            <CheckCircle2 className="size-4" />
                            {notice}
                        </span>
                        <button type="button" onClick={() => setNotice('')}>
                            <X className="size-4" />
                        </button>
                    </div>
                )}

                <section className="grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
                    <Metric
                        icon={Building2}
                        label="Empresa activa"
                        value={company.name}
                        detail="NIT 97655988"
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                    <Metric
                        icon={Sparkles}
                        label="Integraciones"
                        value="6 conectadas"
                        detail="Bancos, SAT, correo y más"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={ShieldCheck}
                        label="Seguridad"
                        value="2FA activo"
                        detail="Cuenta protegida"
                        tone="bg-emerald-50 text-emerald-600"
                    />
                    <Metric
                        icon={Cpu}
                        label="IA local"
                        value="Ollama conectado"
                        detail={`Modelo ${model}`}
                        tone="bg-rose-50 text-[#9b1923]"
                    />
                </section>

                <section className="grid gap-4 xl:grid-cols-[250px_minmax(0,1fr)_350px]">
                    <nav
                        className={`${panel} h-fit p-2`}
                        aria-label="Secciones de configuración"
                    >
                        {sections.map(({ label, icon: Icon }) => (
                            <button
                                key={label}
                                type="button"
                                onClick={() => {
                                    setActiveSection(label);

                                    if (label !== 'Luzma') {
                                        showNotice(
                                            `Sección ${label} seleccionada.`,
                                        );
                                    }
                                }}
                                className={`flex w-full items-center gap-3 rounded-lg border-l-2 px-4 py-3 text-left text-sm transition ${activeSection === label ? 'border-[#74000a] bg-rose-50 font-bold text-[#74000a]' : 'border-transparent hover:bg-slate-50'}`}
                            >
                                <Icon className="size-5" />
                                {label}
                            </button>
                        ))}
                    </nav>

                    <article className={`${panel} overflow-hidden`}>
                        <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div className="flex items-center gap-3">
                                <Bot className="size-8 text-[#74000a]" />
                                <div>
                                    <h2 className="text-xl font-bold text-[#74000a]">
                                        {activeSection}
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Configura el asistente de inteligencia
                                        artificial local del sistema
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    showNotice(
                                        'Documentación de Luzma disponible.',
                                    )
                                }
                                className="flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold"
                            >
                                <BookOpen className="size-4" />
                                Ver documentación
                            </button>
                        </header>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            <label className="space-y-2 text-sm font-semibold">
                                Estado
                                <button
                                    type="button"
                                    onClick={() => setEnabled(!enabled)}
                                    className="flex w-full items-center justify-between rounded-lg border bg-white px-3 py-2.5 font-normal"
                                >
                                    <span className="flex items-center gap-2">
                                        <span
                                            className={`size-3 rounded-full ${enabled ? 'bg-emerald-500' : 'bg-slate-400'}`}
                                        />
                                        {enabled ? 'Activada' : 'Desactivada'}
                                    </span>
                                    <ChevronDown className="size-4" />
                                </button>
                            </label>
                            <label className="space-y-2 text-sm font-semibold">
                                Procesamiento
                                <select
                                    value={processing}
                                    onChange={(event) =>
                                        setProcessing(event.target.value)
                                    }
                                    className="block w-full rounded-lg border bg-white px-3 py-2.5 font-normal"
                                >
                                    <option>
                                        Local (en servidores de la empresa)
                                    </option>
                                    <option>Nube privada</option>
                                    <option>Híbrido</option>
                                </select>
                            </label>
                            <label className="space-y-2 text-sm font-semibold">
                                Modelo
                                <select
                                    value={model}
                                    onChange={(event) =>
                                        setModel(event.target.value)
                                    }
                                    className="block w-full rounded-lg border bg-white px-3 py-2.5 font-normal"
                                >
                                    <option>qwen3:8b-q4_K_M</option>
                                    <option>llama3.2:8b</option>
                                    <option>mistral:7b</option>
                                </select>
                                <span className="block text-xs font-normal text-slate-500">
                                    Modelo de Ollama para procesamiento local.
                                </span>
                            </label>
                            <label className="space-y-2 text-sm font-semibold">
                                FastAPI URL
                                <input
                                    value={url}
                                    onChange={(event) =>
                                        setUrl(event.target.value)
                                    }
                                    className="block w-full rounded-lg border px-3 py-2.5 font-normal"
                                />
                                <span className="block text-xs font-normal text-slate-500">
                                    URL del servicio FastAPI que expone el
                                    modelo.
                                </span>
                            </label>
                            <div>
                                <p className="mb-2 text-sm font-semibold">
                                    Ollama
                                </p>
                                <div className="flex items-center gap-3 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                                    <span className="size-3 rounded-full bg-emerald-500" />
                                    Conectado
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Ollama se encuentra operativo y
                                    respondiendo.
                                </p>
                            </div>
                            <div>
                                <p className="mb-2 text-sm font-semibold">
                                    GPU
                                </p>
                                <div className="flex items-center gap-3 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                                    <Cpu className="size-5" />
                                    NVIDIA RTX 5050
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Dispositivo de procesamiento detectado.
                                </p>
                            </div>
                            <div>
                                <p className="mb-2 text-sm font-semibold">
                                    Acciones automáticas
                                </p>
                                <div className="flex items-center gap-4">
                                    <Toggle
                                        enabled={automaticActions}
                                        onChange={() =>
                                            setAutomaticActions(
                                                !automaticActions,
                                            )
                                        }
                                        label="Cambiar acciones automáticas"
                                    />
                                    <span className="text-sm">
                                        {automaticActions
                                            ? 'Activadas'
                                            : 'Desactivadas'}
                                    </span>
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Permite que la IA ejecute acciones en el
                                    sistema.
                                </p>
                            </div>
                            <div>
                                <p className="mb-2 text-sm font-semibold">
                                    Requerir aprobación humana
                                </p>
                                <div className="flex items-center gap-4">
                                    <Toggle
                                        enabled={humanApproval}
                                        onChange={() =>
                                            setHumanApproval(!humanApproval)
                                        }
                                        label="Cambiar aprobación humana"
                                    />
                                    <span className="text-sm">
                                        {humanApproval
                                            ? 'Activado'
                                            : 'Desactivado'}
                                    </span>
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Solicitar aprobación antes de ejecutar
                                    acciones críticas.
                                </p>
                            </div>
                        </div>
                        <footer className="flex flex-wrap justify-center gap-3 border-t px-5 py-4">
                            <button
                                type="button"
                                onClick={() => void saveConfiguration()}
                                disabled={saving}
                                className="flex items-center gap-2 rounded-lg bg-[#74000a] px-8 py-3 text-sm font-semibold text-white"
                            >
                                <Save className="size-4" />
                                {saving ? 'Guardando…' : 'Guardar cambios'}
                            </button>
                            <button
                                type="button"
                                onClick={() => void testConnection()}
                                className="flex items-center gap-2 rounded-lg border border-[#9b1923] px-8 py-3 text-sm font-semibold text-[#74000a]"
                            >
                                <Play className="size-4" />
                                Probar conexión
                            </button>
                        </footer>
                    </article>

                    <aside className="space-y-4">
                        <article className={`${panel} p-5`}>
                            <header className="mb-4 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 font-bold">
                                    <Building2 className="size-5 text-[#74000a]" />
                                    Información de la empresa
                                </h2>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Edición de empresa habilitada.',
                                        )
                                    }
                                    className="flex items-center gap-1 rounded-lg border px-3 py-2 text-xs"
                                >
                                    <Pencil className="size-3" />
                                    Editar
                                </button>
                            </header>
                            <dl className="divide-y text-xs">
                                {[
                                    ['Nombre comercial', company.name],
                                    ['NIT', '97655988'],
                                    ['País', 'Guatemala'],
                                    ['Plan', 'Empresarial'],
                                    ['Usuarios', '28 activos'],
                                    ['Módulos', 'Todos los módulos'],
                                    ['Fecha de creación', '15 Ene 2024'],
                                ].map(([key, value]) => (
                                    <div
                                        key={key}
                                        className="grid grid-cols-2 gap-3 py-2"
                                    >
                                        <dt className="text-slate-500">
                                            {key}
                                        </dt>
                                        <dd>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </article>
                        <article className={`${panel} p-5`}>
                            <header className="mb-4 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 font-bold">
                                    <ShieldCheck className="size-5 text-[#74000a]" />
                                    Seguridad del sistema
                                </h2>
                                <button
                                    type="button"
                                    onClick={() =>
                                        showNotice(
                                            'Edición de seguridad habilitada.',
                                        )
                                    }
                                    className="flex items-center gap-1 rounded-lg border px-3 py-2 text-xs"
                                >
                                    <Pencil className="size-3" />
                                    Editar
                                </button>
                            </header>
                            <dl className="divide-y text-xs">
                                {[
                                    [
                                        'Autenticación de dos factores',
                                        '● Activado',
                                    ],
                                    ['Política de contraseñas', 'Robusta'],
                                    ['Sesión válida por', '8 horas'],
                                    ['Intentos de acceso', '5 por minuto'],
                                    ['Registro de auditoría', '● Activo'],
                                    [
                                        'Última actualización',
                                        '20 Sep 2026 14:32',
                                    ],
                                ].map(([key, value]) => (
                                    <div
                                        key={key}
                                        className="grid grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] gap-3 py-2"
                                    >
                                        <dt className="text-slate-500">
                                            {key}
                                        </dt>
                                        <dd
                                            className={
                                                value.startsWith('●')
                                                    ? 'text-emerald-700'
                                                    : ''
                                            }
                                        >
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </article>
                    </aside>
                </section>

                <section
                    className={`${panel} flex flex-wrap items-center justify-between gap-4 bg-blue-50/40 p-5`}
                >
                    <div className="flex max-w-5xl items-start gap-3">
                        <Settings2 className="mt-0.5 size-5 text-blue-600" />
                        <div>
                            <h2 className="font-bold">Acerca de Luzma</h2>
                            <p className="mt-1 text-xs text-slate-500">
                                Luzma es tu asistente inteligente ejecutándose
                                en tu infraestructura. Procesa documentos,
                                responde consultas y ayuda a automatizar tareas
                                de forma segura y privada.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => showNotice('Guía de uso preparada.')}
                        className="rounded-lg border bg-white px-4 py-2 text-xs font-semibold"
                    >
                        Guía de uso
                    </button>
                </section>
            </div>
        </main>
    );
}
