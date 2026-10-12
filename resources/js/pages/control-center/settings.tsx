import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    SensitiveAction,
    ccButton,
    ccGhost,
    ccInput,
    ccTextarea,
    date,
} from '@/components/control-center/kit';

type Setting = {
    key: string;
    group: string;
    label: string;
    type: string;
    default: unknown;
    value: unknown;
    sensitive: boolean;
    options?: string[];
    min?: number;
    max?: number;
};
type Template = {
    key: string;
    label: string;
    subject: string;
    body: string;
    version: number;
    history: {
        version: number;
        subject: string;
        status: string;
        created_at: string;
    }[];
};

function SettingRow({
    setting,
    canManage,
}: {
    setting: Setting;
    canManage: boolean;
}) {
    const [value, setValue] = useState<string | boolean>(
        setting.type === 'bool'
            ? Boolean(setting.value)
            : String(setting.value ?? ''),
    );
    const changed =
        setting.type === 'bool'
            ? value !== Boolean(setting.value)
            : value !== String(setting.value ?? '');
    const payload = { key: setting.key, value };

    return (
        <div className="grid items-center gap-2 border-b border-white/[0.04] py-2 sm:grid-cols-[1fr_260px_auto]">
            <div>
                <p className="text-sm text-slate-200">
                    {setting.label}{' '}
                    {setting.sensitive && (
                        <Pill tone="red" dot={false}>
                            sensible
                        </Pill>
                    )}
                </p>
                <p className="font-mono text-[10px] text-slate-500">
                    {setting.key} · predeterminado {String(setting.default)}
                </p>
            </div>
            {setting.type === 'bool' ? (
                <label className="flex items-center gap-2 text-xs text-slate-300">
                    <input
                        type="checkbox"
                        disabled={!canManage}
                        checked={Boolean(value)}
                        onChange={(e) => setValue(e.target.checked)}
                    />{' '}
                    {value ? 'Activado' : 'Desactivado'}
                </label>
            ) : setting.type === 'select' ? (
                <select
                    className={ccInput}
                    disabled={!canManage}
                    value={String(value)}
                    onChange={(e) => setValue(e.target.value)}
                >
                    {(setting.options ?? []).map((o) => (
                        <option key={o} value={o}>
                            {o}
                        </option>
                    ))}
                </select>
            ) : (
                <input
                    className={ccInput}
                    disabled={!canManage}
                    type={
                        setting.type === 'int'
                            ? 'number'
                            : setting.type === 'email'
                              ? 'email'
                              : 'text'
                    }
                    min={setting.min}
                    max={setting.max}
                    value={String(value)}
                    onChange={(e) => setValue(e.target.value)}
                />
            )}
            <div className="min-w-[90px]">
                {canManage &&
                    changed &&
                    (setting.sensitive ? (
                        <SensitiveAction
                            url="/control-center/settings"
                            data={payload}
                            label="Guardar"
                            title={`Cambiar ${setting.label}`}
                            description="Configuración sensible: requiere contraseña, 2FA y motivo."
                        />
                    ) : (
                        <button
                            type="button"
                            className={ccGhost}
                            onClick={() =>
                                router.post(
                                    '/control-center/settings',
                                    payload,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Guardar
                        </button>
                    ))}
            </div>
        </div>
    );
}

function TemplateEditor({
    template,
    canManage,
}: {
    template: Template;
    canManage: boolean;
}) {
    const form = useForm({ subject: template.subject, body: template.body });

    return (
        <Panel
            title={template.label}
            action={
                <span className="text-xs text-slate-400">
                    {template.version === 0
                        ? 'Predeterminada'
                        : `Versión ${template.version}`}
                </span>
            }
        >
            <form
                className="space-y-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(
                        `/control-center/settings/templates/${template.key}`,
                        { preserveScroll: true },
                    );
                }}
            >
                <input
                    className={ccInput}
                    disabled={!canManage}
                    value={form.data.subject}
                    onChange={(e) => form.setData('subject', e.target.value)}
                />
                <textarea
                    className={`${ccTextarea} font-mono text-xs`}
                    rows={5}
                    disabled={!canManage}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
                {Object.values(form.errors).length > 0 && (
                    <p className="text-xs text-[#f87171]">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
                {canManage && form.isDirty && (
                    <button className={ccButton} disabled={form.processing}>
                        Guardar nueva versión
                    </button>
                )}
            </form>
            {template.history.length > 0 && (
                <p className="mt-2 text-[10px] text-slate-500">
                    Historial:{' '}
                    {template.history
                        .map(
                            (h) =>
                                `v${h.version} (${h.status}, ${date(h.created_at)})`,
                        )
                        .join(' · ')}
                </p>
            )}
        </Panel>
    );
}

export default function Settings({
    settings,
    groups,
    templates,
    can_manage,
}: {
    settings: Setting[];
    groups: Record<string, string>;
    templates: Template[];
    can_manage: boolean;
}) {
    return (
        <>
            <Head title="Configuración global · Control Center" />
            <CCPage
                title="Configuración global"
                subtitle="Parámetros de toda la plataforma y plantillas de notificación versionadas. Cada cambio queda auditado; los sensibles exigen re-autenticación."
            >
                <div className="grid gap-3 xl:grid-cols-2">
                    {Object.entries(groups).map(([group, label]) => {
                        const items = settings.filter((s) => s.group === group);

                        return items.length === 0 ? null : (
                            <Panel key={group} title={label}>
                                {items.map((setting) => (
                                    <SettingRow
                                        key={`${setting.key}-${String(setting.value)}`}
                                        setting={setting}
                                        canManage={can_manage}
                                    />
                                ))}
                            </Panel>
                        );
                    })}
                </div>
                <h2 className="pt-2 text-lg font-bold text-white">
                    Plantillas de notificación
                </h2>
                <p className="-mt-2 text-xs text-slate-400">
                    {
                        'Usa variables como {{name}}, {{organization}} o {{amount}}. Guardar crea una versión nueva y conserva la anterior.'
                    }
                </p>
                <div className="grid gap-3 xl:grid-cols-2">
                    {templates.map((template) => (
                        <TemplateEditor
                            key={`${template.key}-${template.version}`}
                            template={template}
                            canManage={can_manage}
                        />
                    ))}
                </div>
            </CCPage>
        </>
    );
}
