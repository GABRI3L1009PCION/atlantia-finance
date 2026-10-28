import { router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    head,
    PageShell,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import { Card, field, primary } from '@/components/accounting-ui';

type Policy = {
    key: string;
    label: string;
    group: string;
    type: 'bool' | 'int' | 'money' | 'percent' | 'select';
    options: Record<string, string> | null;
    help: string | null;
    value: string;
    default: string;
    customized: boolean;
};
type Version = {
    key: string;
    label: string;
    old: string | null;
    new: string;
    reason: string | null;
    by: string | null;
    at: string;
};

export default function Policies({
    company,
    policies,
    history,
    canManage,
}: {
    company: Company;
    policies: Policy[];
    history: Version[];
    canManage: boolean;
}) {
    const url = `/accounting/companies/${company.public_id}/policies`;
    const [values, setValues] = useState<Record<string, string>>(
        Object.fromEntries(
            policies.map((policy) => [policy.key, policy.value]),
        ),
    );
    const [reasons, setReasons] = useState<Record<string, string>>({});
    const groups = [...new Set(policies.map((policy) => policy.group))];

    const save = (policy: Policy) =>
        router.patch(
            url,
            {
                key: policy.key,
                value: values[policy.key],
                reason: reasons[policy.key] ?? '',
            },
            { preserveScroll: true },
        );

    return (
        <PageShell
            company={company}
            title="Políticas contables"
            subtitle="Criterios que aplica el sistema"
            icon={ScrollText}
        >
            {groups.map((group) => (
                <Card key={group} title={group}>
                    <div className="divide-y divide-slate-100">
                        {policies
                            .filter((policy) => policy.group === group)
                            .map((policy) => (
                                <div
                                    key={policy.key}
                                    className="grid gap-3 py-4 lg:grid-cols-[1fr_260px_220px_auto] lg:items-end"
                                >
                                    <div>
                                        <p className="font-semibold">
                                            {policy.label}{' '}
                                            {policy.customized && (
                                                <Badge tone="amber">
                                                    Personalizada
                                                </Badge>
                                            )}
                                        </p>
                                        {policy.help && (
                                            <p className="mt-1 text-xs leading-5 text-slate-500">
                                                {policy.help}
                                            </p>
                                        )}
                                    </div>
                                    {policy.type === 'select' &&
                                    policy.options ? (
                                        <select
                                            className={field}
                                            disabled={!canManage}
                                            value={values[policy.key]}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [policy.key]:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            {Object.entries(policy.options).map(
                                                ([key, label]) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    ) : policy.type === 'bool' ? (
                                        <select
                                            className={field}
                                            disabled={!canManage}
                                            value={values[policy.key]}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [policy.key]:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            <option value="1">Sí</option>
                                            <option value="0">No</option>
                                        </select>
                                    ) : (
                                        <input
                                            className={field}
                                            disabled={!canManage}
                                            inputMode="decimal"
                                            value={values[policy.key]}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [policy.key]:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    )}
                                    <input
                                        className={field}
                                        disabled={!canManage}
                                        placeholder="Motivo del cambio"
                                        value={reasons[policy.key] ?? ''}
                                        onChange={(e) =>
                                            setReasons({
                                                ...reasons,
                                                [policy.key]: e.target.value,
                                            })
                                        }
                                    />
                                    {canManage && (
                                        <button
                                            className={primary}
                                            disabled={
                                                values[policy.key] ===
                                                policy.value
                                            }
                                            onClick={() => save(policy)}
                                        >
                                            Guardar
                                        </button>
                                    )}
                                </div>
                            ))}
                    </div>
                </Card>
            ))}

            <Card title="Historial de cambios">
                {history.length === 0 ? (
                    <Empty>
                        Todavía no se han modificado políticas. Cada cambio
                        queda registrado con quién lo hizo y por qué.
                    </Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px]">
                            <thead>
                                <tr>
                                    {[
                                        'Fecha',
                                        'Política',
                                        'Antes',
                                        'Ahora',
                                        'Motivo',
                                        'Por',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((row, index) => (
                                    <tr
                                        key={index}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {row.at.slice(0, 16)}
                                        </td>
                                        <td className={cell}>{row.label}</td>
                                        <td className={cell}>
                                            {row.old ?? '—'}
                                        </td>
                                        <td className={`${cell} font-semibold`}>
                                            {row.new}
                                        </td>
                                        <td className={cell}>
                                            {row.reason ?? '—'}
                                        </td>
                                        <td className={cell}>
                                            {row.by ?? 'Sistema'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </PageShell>
    );
}
