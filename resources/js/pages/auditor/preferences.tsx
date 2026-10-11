import { Head, Link, useForm } from '@inertiajs/react';
import { ActionButton, Field } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
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

type Grant = {
    id: number;
    auditor: string;
    email: string;
    company: string;
    period: string;
    modules: string[];
    expires_at: string;
    status: string;
};

export default function AuditorPreferences({
    context,
    canManageAccess,
    modules,
    grants,
    auditors,
    notice,
}: {
    context: AuditorContext;
    canManageAccess: boolean;
    modules: Record<string, string>;
    grants: Grant[];
    auditors: { id: number; name: string }[];
    notice: string | null;
}) {
    const form = useForm({
        auditor_user_id: '',
        company_id: '',
        period_start: '',
        period_end: '',
        allowed_modules: [] as string[],
        expires_at: '',
    });

    return (
        <>
            <Head title="Preferencias de auditoría" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Preferencias"
                    subtitle="Accesos temporales de auditores externos y preferencias personales"
                    actions={
                        <Link
                            href="/settings/profile"
                            className={secondaryButton}
                        >
                            Mi perfil y notificaciones
                        </Link>
                    }
                />
                <Card
                    title={
                        canManageAccess
                            ? 'Accesos de auditores externos'
                            : 'Mis accesos temporales'
                    }
                >
                    <Table
                        headers={[
                            'Auditor',
                            'Empresa',
                            'Período',
                            'Módulos',
                            'Vence',
                            'Estado',
                            '',
                        ]}
                        empty={
                            grants.length === 0 && 'No hay accesos temporales.'
                        }
                    >
                        {grants.map((grant) => (
                            <tr key={grant.id}>
                                <td className="px-2 py-2 font-bold">
                                    {grant.auditor}
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {grant.email}
                                    </p>
                                </td>
                                <td className="px-2 py-2">{grant.company}</td>
                                <td className="px-2 py-2">{grant.period}</td>
                                <td className="px-2 py-2">
                                    {grant.modules.join(', ')}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(grant.expires_at, true)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            grant.status === 'active'
                                                ? 'green'
                                                : grant.status === 'expired'
                                                  ? 'amber'
                                                  : 'slate',
                                        )}
                                    >
                                        {{
                                            active: 'Vigente',
                                            expired: 'Expirado',
                                            revoked: 'Revocado',
                                        }[grant.status] ?? grant.status}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {canManageAccess &&
                                        grant.status === 'active' && (
                                            <ActionButton
                                                url={`/work/audit/access-grants/${grant.id}/revoke`}
                                                label="Revocar"
                                            />
                                        )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    {canManageAccess && (
                        <form
                            className="mt-3 grid gap-2 md:grid-cols-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/work/audit/access-grants', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                });
                            }}
                        >
                            <Field label="Auditor">
                                <select
                                    className={input}
                                    value={form.data.auditor_user_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'auditor_user_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {auditors.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
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
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {context.companies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Vence">
                                <input
                                    type="datetime-local"
                                    className={input}
                                    value={form.data.expires_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'expires_at',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Período desde">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.period_start}
                                    onChange={(event) =>
                                        form.setData(
                                            'period_start',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Período hasta">
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.period_end}
                                    onChange={(event) =>
                                        form.setData(
                                            'period_end',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Módulos (solo lectura)">
                                <div className="flex flex-wrap gap-1">
                                    {Object.entries(modules).map(
                                        ([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-center gap-1 rounded border border-slate-200 px-2 py-0.5 text-[11px] font-normal"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.allowed_modules.includes(
                                                        key,
                                                    )}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'allowed_modules',
                                                            event.target.checked
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .allowed_modules,
                                                                      key,
                                                                  ]
                                                                : form.data.allowed_modules.filter(
                                                                      (m) =>
                                                                          m !==
                                                                          key,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {label}
                                            </label>
                                        ),
                                    )}
                                </div>
                            </Field>
                            <div>
                                <button
                                    type="submit"
                                    className={primaryButton}
                                    disabled={form.processing}
                                >
                                    Otorgar acceso temporal
                                </button>
                            </div>
                        </form>
                    )}
                </Card>
            </Shell>
        </>
    );
}
