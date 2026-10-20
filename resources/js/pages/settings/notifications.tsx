import { Head, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { field, primary } from '@/components/accounting-ui';
import { SettingsCard } from '@/components/settings-card';

type Preference = {
    kind: string;
    label?: string;
    help?: string;
    company_id?: number;
    company?: string;
    in_app: boolean;
    email: boolean;
    frequency: string;
    lead_days: number;
    recipients: string;
};

type Props = {
    preferences: { general: Preference[]; companies: Preference[] };
    frequencies: Record<string, string>;
    companies: { id: number; name: string }[];
};

function Row({
    preference,
    frequencies,
    companyId,
    title,
    help,
}: {
    preference: Preference;
    frequencies: Record<string, string>;
    companyId: number | null;
    title: string;
    help?: string;
}) {
    const [state, setState] = useState(preference);
    const save = () =>
        router.put(
            '/settings/notifications',
            {
                kind: preference.kind,
                company_id: companyId,
                in_app: state.in_app,
                email: state.email,
                frequency: state.frequency,
                lead_days: state.lead_days,
                recipients: state.recipients,
            },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-3 border-b border-border py-4 last:border-0 lg:grid-cols-[1fr_auto]">
            <div>
                <p className="font-semibold">{title}</p>
                {help && (
                    <p className="mt-1 text-sm text-muted-foreground">{help}</p>
                )}
                <div className="mt-3 flex flex-wrap items-center gap-4 text-sm">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={state.in_app}
                            onChange={(e) =>
                                setState({ ...state, in_app: e.target.checked })
                            }
                        />{' '}
                        En la aplicación
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={state.email}
                            onChange={(e) =>
                                setState({ ...state, email: e.target.checked })
                            }
                        />{' '}
                        Por correo
                    </label>
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-3">
                    <label className="text-xs font-bold text-muted-foreground uppercase">
                        Frecuencia
                        <select
                            className={field}
                            value={state.frequency}
                            onChange={(e) =>
                                setState({
                                    ...state,
                                    frequency: e.target.value,
                                })
                            }
                        >
                            {Object.entries(frequencies).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-bold text-muted-foreground uppercase">
                        Avisar con (días)
                        <input
                            type="number"
                            min={0}
                            max={60}
                            className={field}
                            value={state.lead_days}
                            onChange={(e) =>
                                setState({
                                    ...state,
                                    lead_days: Number(e.target.value),
                                })
                            }
                        />
                    </label>
                    <label className="text-xs font-bold text-muted-foreground uppercase">
                        Destinatarios extra
                        <input
                            className={field}
                            placeholder="correo1@, correo2@"
                            value={state.recipients}
                            onChange={(e) =>
                                setState({
                                    ...state,
                                    recipients: e.target.value,
                                })
                            }
                        />
                    </label>
                </div>
            </div>
            <div className="flex items-end">
                <button className={primary} onClick={save}>
                    Guardar
                </button>
            </div>
        </div>
    );
}

export default function Notifications({
    preferences,
    frequencies,
    companies,
}: Props) {
    const [companyId, setCompanyId] = useState<number | ''>('');
    const overrides = preferences.companies.filter(
        (item) => item.company_id === companyId,
    );

    return (
        <>
            <Head title="Alertas" />
            <div className="space-y-6">
                <SettingsCard
                    icon={Bell}
                    title="Alertas y recordatorios"
                    description="Elige qué te avisamos, por qué canal y con cuánta anticipación. Estos ajustes aplican a todas tus empresas."
                >
                    {preferences.general.map((preference) => (
                        <Row
                            key={preference.kind}
                            preference={preference}
                            frequencies={frequencies}
                            companyId={null}
                            title={preference.label ?? preference.kind}
                            help={preference.help}
                        />
                    ))}
                </SettingsCard>

                {companies.length > 1 && (
                    <SettingsCard
                        icon={Bell}
                        title="Ajustes por empresa"
                        description="Sobrescribe tus preferencias generales para una empresa en particular."
                    >
                        <select
                            className={`${field} max-w-sm`}
                            value={companyId}
                            onChange={(e) =>
                                setCompanyId(
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                        >
                            <option value="">Elige una empresa</option>
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                        {companyId !== '' &&
                            preferences.general.map((general) => {
                                const override = overrides.find(
                                    (item) => item.kind === general.kind,
                                );

                                return (
                                    <Row
                                        key={`${companyId}-${general.kind}`}
                                        preference={override ?? general}
                                        frequencies={frequencies}
                                        companyId={companyId}
                                        title={general.label ?? general.kind}
                                        help={
                                            override
                                                ? 'Ajuste propio de esta empresa.'
                                                : 'Usa tus preferencias generales hasta que guardes un cambio.'
                                        }
                                    />
                                );
                            })}
                    </SettingsCard>
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Alertas', href: '/settings/notifications' }],
};
