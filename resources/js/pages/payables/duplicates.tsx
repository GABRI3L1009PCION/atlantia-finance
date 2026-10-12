import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { amount } from '@/components/payables/kit';
import type { PayablesContext } from '@/components/payables/kit';
import { Card, toneBadge } from '@/components/supervisor/kit';

type Pair = {
    key: string;
    level: string;
    level_label: string;
    reason: string;
    documents: {
        id: number;
        document: string;
        supplier: string;
        date: string;
        total_cents: number;
        status: string;
        destination: string;
    }[];
    incident: { id: number; status: string; resolution: string | null } | null;
};

function Dismiss({ incidentId }: { incidentId: number }) {
    const [resolution, setResolution] = useState('');

    return (
        <div className="flex flex-wrap items-center gap-1">
            <input
                aria-label="Por qué no es duplicado"
                className="h-8 min-w-64 flex-1 rounded-md border border-slate-300 px-2 text-xs"
                placeholder="Por qué no es duplicado (obligatorio)"
                value={resolution}
                onChange={(event) => setResolution(event.target.value)}
            />
            <button
                type="button"
                className={secondaryButton}
                disabled={resolution.trim().length < 5}
                onClick={() =>
                    router.patch(
                        `/payables/incidents/${incidentId}`,
                        { status: 'dismissed', resolution },
                        { preserveScroll: true },
                    )
                }
            >
                No es duplicado
            </button>
        </div>
    );
}

export default function PayablesDuplicates({
    pairs,
    notice,
    errors,
}: {
    context: PayablesContext;
    pairs: Pair[];
    notice: string | null;
    errors: Record<string, string>;
}) {
    return (
        <>
            <Head title="Posibles facturas duplicadas" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Posibles facturas duplicadas"
                    subtitle="Comparación por proveedor (o NIT), serie y número fiscal, referencia, fecha, monto y moneda. Nada se bloquea solo: anula el duplicado en Compras o descártalo con justificación"
                />
                {errors.status && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {errors.status}
                    </p>
                )}
                {pairs.length === 0 && (
                    <Card title="Sin coincidencias">
                        <p className="text-sm text-slate-500">
                            No se detectaron posibles duplicados.
                        </p>
                    </Card>
                )}
                {pairs.map((pair) => (
                    <Card
                        key={pair.key}
                        title={pair.reason}
                        action={
                            <span
                                className={toneBadge(
                                    pair.level === 'strong' ? 'red' : 'amber',
                                )}
                            >
                                {pair.level_label}
                            </span>
                        }
                    >
                        <div className="grid gap-2 md:grid-cols-2">
                            {pair.documents.map((document) => (
                                <a
                                    key={document.id}
                                    href={document.destination}
                                    className="rounded-lg border border-[#e7e1dd] p-3 text-sm hover:border-[#640000]/40"
                                >
                                    <b>{document.document}</b> ·{' '}
                                    {document.status}
                                    <p className="text-xs text-[#526b92]">
                                        {document.supplier} ·{' '}
                                        {formatDate(document.date)} ·{' '}
                                        {amount(document.total_cents)}
                                    </p>
                                </a>
                            ))}
                        </div>
                        <div className="mt-2">
                            {pair.incident === null ? null : [
                                  'dismissed',
                                  'resolved',
                              ].includes(pair.incident.status) ? (
                                <p className="text-xs text-emerald-800">
                                    Revisado: {pair.incident.resolution ?? '—'}
                                </p>
                            ) : (
                                <Dismiss incidentId={pair.incident.id} />
                            )}
                        </div>
                    </Card>
                ))}
            </Shell>
        </>
    );
}
