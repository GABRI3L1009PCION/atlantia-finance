import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useAuditorHref } from '@/components/auditor/kit';
import type { AuditorContext } from '@/components/auditor/kit';
import { AskLuzmaButton } from '@/components/luzma/luzma-button';
import {
    PageHeader,
    Shell,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';
import { EventsTable } from './system-events';
import type { SystemEvent } from './system-events';

export default function AuditorSensitiveChanges({
    context,
    changes,
    categories,
    filters,
}: {
    context: AuditorContext;
    changes: SystemEvent[];
    categories: Record<string, string>;
    filters: Record<string, string>;
}) {
    const href = useAuditorHref();
    const [form, setForm] = useState({
        company_id: '',
        category: '',
        from: '',
        to: '',
        ...filters,
    });

    return (
        <>
            <Head title="Cambios sensibles" />
            <Shell>
                <PageHeader
                    title="Cambios sensibles"
                    subtitle="Reaperturas, reversiones, ajustes, roles, permisos, pagos y configuración: son candidatos a revisión, no hallazgos automáticos"
                    actions={
                        <AskLuzmaButton
                            question="Resume los cambios sensibles recientes distinguiendo hechos de interpretaciones"
                            label="Resumir con Luzma"
                        />
                    }
                />
                <Card title="Filtros">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                href('/work/audit/sensitive-changes'),
                                Object.fromEntries(
                                    Object.entries(form).filter(([, v]) => v),
                                ),
                            );
                        }}
                    >
                        <select
                            aria-label="Empresa"
                            className={`${input} w-52`}
                            value={form.company_id}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    company_id: event.target.value,
                                })
                            }
                        >
                            <option value="">Todas mis empresas</option>
                            {context.companies.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Categoría"
                            className={`${input} w-60`}
                            value={form.category}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    category: event.target.value,
                                })
                            }
                        >
                            <option value="">Todas las categorías</option>
                            {Object.entries(categories).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            className={`${input} w-40`}
                            value={form.from}
                            onChange={(event) =>
                                setForm({ ...form, from: event.target.value })
                            }
                        />
                        <input
                            type="date"
                            className={`${input} w-40`}
                            value={form.to}
                            onChange={(event) =>
                                setForm({ ...form, to: event.target.value })
                            }
                        />
                        <button type="submit" className={secondaryButton}>
                            Filtrar
                        </button>
                    </form>
                </Card>
                <Card title={`${changes.length} cambio(s)`}>
                    <EventsTable events={changes} category />
                </Card>
            </Shell>
        </>
    );
}
