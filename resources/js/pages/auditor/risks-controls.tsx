import { Head, Link, useForm } from '@inertiajs/react';
import { Field, severityTone, useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Risk = {
    id: number;
    engagement_id: number;
    engagement: string;
    company: string;
    code: string;
    area: string;
    title: string;
    risk_level: string;
    risk_label: string;
    control_reference: string | null;
    status: string;
};
type Control = {
    id: number;
    company: string;
    code: string;
    name: string;
    area: string;
    frequency: string | null;
    assessments: number;
    last_effectiveness: string | null;
};

export default function AuditorRisksControls({
    context,
    risks,
    controls,
    areas,
    canRegister,
    notice,
}: {
    context: AuditorContext;
    risks: Risk[];
    controls: Control[];
    areas: Record<string, string>;
    canRegister: boolean;
    notice: string | null;
}) {
    const href = useAuditorHref();
    const form = useForm({
        company_id: context.companies[0] ? String(context.companies[0].id) : '',
        code: '',
        name: '',
        area: 'accounting',
        description: '',
        frequency: '',
    });

    return (
        <>
            <Head title="Riesgos y controles" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Riesgos y controles"
                    subtitle="Riesgos de tus auditorías y registro de controles por empresa; el nivel es una clasificación de auditoría, no una conclusión"
                />
                <Card title={`Riesgos (${risks.length})`}>
                    <Table
                        headers={[
                            'Auditoría',
                            'Empresa',
                            'Código',
                            'Área',
                            'Riesgo',
                            'Nivel',
                            'Control',
                            'Estado',
                        ]}
                        empty={
                            risks.length === 0 &&
                            'Aún no hay riesgos registrados.'
                        }
                    >
                        {risks.map((risk) => (
                            <tr key={risk.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(
                                            `/work/audit/engagements/${risk.engagement_id}?tab=risks`,
                                        )}
                                        className="text-blue-700 hover:underline"
                                    >
                                        {risk.engagement}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{risk.company}</td>
                                <td className="px-2 py-2">{risk.code}</td>
                                <td className="px-2 py-2">{risk.area}</td>
                                <td className="px-2 py-2">{risk.title}</td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            severityTone[risk.risk_level] ??
                                                'slate',
                                        )}
                                    >
                                        {risk.risk_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {risk.control_reference ?? '—'}
                                </td>
                                <td className="px-2 py-2">{risk.status}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card title={`Registro de controles (${controls.length})`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Código',
                            'Control',
                            'Área',
                            'Frecuencia',
                            'Evaluaciones',
                            'Última efectividad',
                        ]}
                        empty={
                            controls.length === 0 &&
                            'Aún no hay controles registrados.'
                        }
                    >
                        {controls.map((control) => (
                            <tr key={control.id}>
                                <td className="px-2 py-2">{control.company}</td>
                                <td className="px-2 py-2 font-bold">
                                    {control.code}
                                </td>
                                <td className="px-2 py-2">{control.name}</td>
                                <td className="px-2 py-2">{control.area}</td>
                                <td className="px-2 py-2">
                                    {control.frequency ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {control.assessments}
                                </td>
                                <td className="px-2 py-2">
                                    {control.last_effectiveness ??
                                        'No evaluado'}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    {canRegister && (
                        <form
                            className="mt-3 grid gap-2 md:grid-cols-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/work/audit/controls', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset(
                                            'code',
                                            'name',
                                            'description',
                                        ),
                                });
                            }}
                        >
                            <Field label="Empresa">
                                <select
                                    className={input}
                                    value={form.data.company_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'company_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {context.companies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Código">
                                <input
                                    className={input}
                                    value={form.data.code}
                                    onChange={(event) =>
                                        form.setData('code', event.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Control">
                                <input
                                    className={input}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Área">
                                <select
                                    className={input}
                                    value={form.data.area}
                                    onChange={(event) =>
                                        form.setData('area', event.target.value)
                                    }
                                >
                                    {Object.entries(areas).map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Frecuencia">
                                <input
                                    className={input}
                                    value={form.data.frequency}
                                    onChange={(event) =>
                                        form.setData(
                                            'frequency',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div className="flex items-end">
                                <button
                                    type="submit"
                                    className={secondaryButton}
                                    disabled={form.processing}
                                >
                                    Registrar control
                                </button>
                            </div>
                        </form>
                    )}
                </Card>
            </Shell>
        </>
    );
}
