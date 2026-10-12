import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    EvidenceTable,
    FindingForm,
} from '@/components/auditor/engagement-sections';
import {
    ActionButton,
    Field,
    amount,
    findingTone,
    procedureTone,
    textarea,
    useAuditorHref,
} from '@/components/auditor/kit';
import type {
    AuditorContext,
    Engagement,
    EvidenceRequest,
    Finding,
    Paper,
    Procedure,
    Sample,
} from '@/components/auditor/kit';
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

export default function AuditorProcedure({
    procedure: p,
    engagement,
    samples,
    evidence,
    papers,
    findings,
    responsibles,
    options,
    can,
    period,
    notice,
}: {
    context: AuditorContext;
    procedure: Procedure;
    engagement: Engagement;
    samples: Sample[];
    evidence: EvidenceRequest[];
    papers: Paper[];
    findings: Finding[];
    responsibles: { id: number; name: string }[];
    options: {
        universes: Record<string, string>;
        methods: Record<string, string>;
        results: Record<string, string>;
        areas: Record<string, string>;
        levels: Record<string, string>;
    };
    can: Record<string, boolean>;
    period: { from: string; to: string };
    notice: string | null;
}) {
    const href = useAuditorHref();
    const sample = useForm({
        universe: 'purchases',
        universe_from: period.from,
        universe_to: period.to,
        method: 'monetary_threshold',
        size: '10',
        threshold: '',
        min: '',
        max: '',
        text: '',
        date_from: '',
        date_to: '',
        ids: '',
    });
    const complete = useForm({ result: 'satisfactory', conclusion: '' });
    const [findingFor, setFindingFor] = useState<number | null>(null);
    const evidenceForm = useForm({
        title: '',
        description: '',
        requested_from_user_id: '',
        due_date: '',
        audit_procedure_id: String(p.id),
    });
    const open = can.execute && !can.locked;
    const running = [
        'in_progress',
        'waiting_evidence',
        'exception_found',
    ].includes(p.status);

    return (
        <>
            <Head title={`${p.code} · ${p.title}`} />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title={`${p.code} · ${p.title}`}
                    subtitle={`${engagement.code} · ${engagement.company} · ${p.area_label} · ${p.type_label} · responsable ${p.responsible ?? '—'}`}
                    actions={
                        <>
                            <span
                                className={toneBadge(
                                    procedureTone[p.status] ?? 'slate',
                                )}
                            >
                                {p.status_label}
                            </span>
                            <Link
                                href={href(
                                    `${engagement.destination}?tab=procedures`,
                                )}
                                className={secondaryButton}
                            >
                                Volver a la auditoría
                            </Link>
                        </>
                    }
                />
                {p.objective && (
                    <p className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
                        <b>Objetivo:</b> {p.objective}
                    </p>
                )}
                {p.status_reason && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
                        {p.status_reason}
                    </p>
                )}
                {open && (
                    <div className="flex flex-wrap gap-2">
                        {p.status === 'not_started' && (
                            <ActionButton
                                url={`/work/audit/procedures/${p.id}/start`}
                                label="Iniciar procedimiento"
                                tone="primary"
                            />
                        )}
                        {['waiting_evidence', 'blocked'].includes(p.status) && (
                            <ActionButton
                                url={`/work/audit/procedures/${p.id}/resume`}
                                label="Reanudar"
                            />
                        )}
                        {!['completed', 'not_applicable', 'blocked'].includes(
                            p.status,
                        ) && (
                            <ActionButton
                                url={`/work/audit/procedures/${p.id}/block`}
                                label="Bloquear"
                                ask="Motivo del bloqueo"
                            />
                        )}
                        {!['completed', 'not_applicable'].includes(
                            p.status,
                        ) && (
                            <ActionButton
                                url={`/work/audit/procedures/${p.id}/not_applicable`}
                                label="No aplica"
                                ask="Justifica por qué no aplica"
                            />
                        )}
                    </div>
                )}

                <Card title="Muestreo">
                    {open && running && (
                        <form
                            className="mb-3 grid gap-2 md:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                sample.post(
                                    `/work/audit/procedures/${p.id}/samples`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <Field label="Universo">
                                <select
                                    className={input}
                                    value={sample.data.universe}
                                    onChange={(event) =>
                                        sample.setData(
                                            'universe',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(options.universes).map(
                                        ([k, l]) => (
                                            <option key={k} value={k}>
                                                {l}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                            <Field label="Desde">
                                <input
                                    type="date"
                                    className={input}
                                    value={sample.data.universe_from}
                                    onChange={(event) =>
                                        sample.setData(
                                            'universe_from',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Hasta">
                                <input
                                    type="date"
                                    className={input}
                                    value={sample.data.universe_to}
                                    onChange={(event) =>
                                        sample.setData(
                                            'universe_to',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Método">
                                <select
                                    className={input}
                                    value={sample.data.method}
                                    onChange={(event) =>
                                        sample.setData(
                                            'method',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(options.methods).map(
                                        ([k, l]) => (
                                            <option key={k} value={k}>
                                                {l}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                            {sample.data.method === 'random' && (
                                <Field label="Tamaño">
                                    <input
                                        type="number"
                                        min="1"
                                        max="500"
                                        className={input}
                                        value={sample.data.size}
                                        onChange={(event) =>
                                            sample.setData(
                                                'size',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                            {sample.data.method === 'monetary_threshold' && (
                                <Field label="Umbral (Q)">
                                    <input
                                        type="number"
                                        step="0.01"
                                        className={input}
                                        value={sample.data.threshold}
                                        onChange={(event) =>
                                            sample.setData(
                                                'threshold',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                            {sample.data.method === 'criteria_based' && (
                                <>
                                    <Field label="Monto mínimo (Q)">
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={input}
                                            value={sample.data.min}
                                            onChange={(event) =>
                                                sample.setData(
                                                    'min',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Monto máximo (Q)">
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={input}
                                            value={sample.data.max}
                                            onChange={(event) =>
                                                sample.setData(
                                                    'max',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Texto en referencia">
                                        <input
                                            className={input}
                                            value={sample.data.text}
                                            onChange={(event) =>
                                                sample.setData(
                                                    'text',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                            {sample.data.method === 'date_range' && (
                                <>
                                    <Field label="Fecha desde">
                                        <input
                                            type="date"
                                            className={input}
                                            value={sample.data.date_from}
                                            onChange={(event) =>
                                                sample.setData(
                                                    'date_from',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Fecha hasta">
                                        <input
                                            type="date"
                                            className={input}
                                            value={sample.data.date_to}
                                            onChange={(event) =>
                                                sample.setData(
                                                    'date_to',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                            {sample.data.method === 'manual' && (
                                <Field label="Identificadores (separados por coma)">
                                    <input
                                        className={input}
                                        value={sample.data.ids}
                                        onChange={(event) =>
                                            sample.setData(
                                                'ids',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                            <div className="flex items-end">
                                <button
                                    type="submit"
                                    className={primaryButton}
                                    disabled={sample.processing}
                                >
                                    Generar candidatos
                                </button>
                            </div>
                        </form>
                    )}
                    {samples.length === 0 && (
                        <p className="py-4 text-center text-sm text-slate-500">
                            Este procedimiento no tiene muestra.
                        </p>
                    )}
                    {samples.map((s) => (
                        <div key={s.id} className="mb-4">
                            <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
                                <b>{s.code}</b> · {s.universe}{' '}
                                {formatDate(s.universe_from)} –{' '}
                                {formatDate(s.universe_to)} · {s.method} ·
                                población {s.population_count}
                                {s.population_cents !== null &&
                                    ` (${amount(s.population_cents)})`}{' '}
                                · muestra {s.sample_count}
                                {s.seed ? ` · semilla ${s.seed}` : ''}
                                <span
                                    className={toneBadge(
                                        s.status === 'confirmed'
                                            ? 'green'
                                            : 'amber',
                                    )}
                                >
                                    {s.status === 'confirmed'
                                        ? `Congelada por ${s.confirmed_by} el ${formatDate(s.confirmed_at)}`
                                        : 'Borrador: revisa y confirma'}
                                </span>
                                {s.status === 'draft' && can.confirm_sample && (
                                    <ActionButton
                                        url={`/work/audit/samples/${s.id}/confirm`}
                                        label="Confirmar y congelar"
                                        tone="primary"
                                    />
                                )}
                            </div>
                            {s.note && (
                                <p className="mb-2 text-[11px] text-[#526b92]">
                                    {s.note}
                                </p>
                            )}
                            <Table
                                headers={[
                                    'Referencia',
                                    'Fecha',
                                    'Importe',
                                    'Resultado',
                                    'Nota',
                                    'Acciones',
                                ]}
                            >
                                {s.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-2 py-2">
                                            <a
                                                href={item.trace}
                                                className="text-blue-700 hover:underline"
                                            >
                                                {item.reference}
                                            </a>
                                            {item.changed === true && (
                                                <p className="text-[11px] font-bold text-red-700">
                                                    El origen cambió después del
                                                    muestreo
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-2 py-2">
                                            {formatDate(item.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {item.amount_cents === null
                                                ? '—'
                                                : amount(item.amount_cents)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span
                                                className={toneBadge(
                                                    {
                                                        pass: 'green',
                                                        exception: 'red',
                                                        pending_evidence:
                                                            'violet',
                                                        not_applicable: 'slate',
                                                    }[item.result] ?? 'amber',
                                                )}
                                            >
                                                {item.result_label}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2 text-[11px]">
                                            {item.note ?? '—'}
                                        </td>
                                        <td className="px-2 py-2 whitespace-nowrap">
                                            {s.status === 'draft' &&
                                                can.sample && (
                                                    <ActionButton
                                                        url={`/work/audit/sample-items/${item.id}`}
                                                        label="Quitar"
                                                        method="delete"
                                                    />
                                                )}
                                            {s.status === 'confirmed' &&
                                                open && (
                                                    <span className="flex flex-wrap gap-1">
                                                        <button
                                                            type="button"
                                                            className="rounded border border-emerald-300 px-2 py-0.5 text-[11px] font-bold text-emerald-700"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/work/audit/sample-items/${item.id}/result`,
                                                                    {
                                                                        result: 'pass',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Conforme
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="rounded border border-red-300 px-2 py-0.5 text-[11px] font-bold text-red-700"
                                                            onClick={() => {
                                                                const note =
                                                                    window.prompt(
                                                                        'Describe la excepción',
                                                                    );

                                                                if (note) {
                                                                    router.post(
                                                                        `/work/audit/sample-items/${item.id}/result`,
                                                                        {
                                                                            result: 'exception',
                                                                            note,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Excepción
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="rounded border border-violet-300 px-2 py-0.5 text-[11px] font-bold text-violet-700"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/work/audit/sample-items/${item.id}/result`,
                                                                    {
                                                                        result: 'pending_evidence',
                                                                        note: 'Esperando soporte',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Espera evidencia
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="rounded border border-slate-300 px-2 py-0.5 text-[11px] font-bold"
                                                            onClick={() => {
                                                                const note =
                                                                    window.prompt(
                                                                        '¿Por qué no aplica?',
                                                                    );

                                                                if (note) {
                                                                    router.post(
                                                                        `/work/audit/sample-items/${item.id}/result`,
                                                                        {
                                                                            result: 'not_applicable',
                                                                            note,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            No aplica
                                                        </button>
                                                        {item.result ===
                                                            'exception' &&
                                                            can.findings && (
                                                                <button
                                                                    type="button"
                                                                    className="rounded bg-[#640000] px-2 py-0.5 text-[11px] font-bold text-white"
                                                                    onClick={() =>
                                                                        setFindingFor(
                                                                            item.id,
                                                                        )
                                                                    }
                                                                >
                                                                    Preparar
                                                                    hallazgo
                                                                </button>
                                                            )}
                                                    </span>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </div>
                    ))}
                    {findingFor !== null && (
                        <div className="mt-3 rounded-lg border border-red-200 p-3">
                            <p className="mb-2 text-xs font-bold">
                                Hallazgo desde la excepción (la conclusión la
                                documenta el auditor)
                            </p>
                            <FindingForm
                                engagementId={engagement.id}
                                procedures={[
                                    { id: p.id, code: p.code, title: p.title },
                                ]}
                                responsibles={responsibles}
                                options={options}
                                defaults={{
                                    area: p.area,
                                    audit_procedure_id: String(p.id),
                                    audit_sample_item_id: String(findingFor),
                                }}
                            />
                        </div>
                    )}
                </Card>

                <Card title="Evidencia del procedimiento">
                    <EvidenceTable rows={evidence} canReview={can.execute} />
                    {can.request_evidence && !can.locked && (
                        <form
                            className="mt-3 grid gap-2 md:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                evidenceForm.post(
                                    `/work/audit/engagements/${engagement.id}/evidence`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            evidenceForm.reset(
                                                'title',
                                                'description',
                                            ),
                                    },
                                );
                            }}
                        >
                            <Field label="Evidencia">
                                <input
                                    className={input}
                                    value={evidenceForm.data.title}
                                    onChange={(event) =>
                                        evidenceForm.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Responsable">
                                <select
                                    className={input}
                                    value={
                                        evidenceForm.data.requested_from_user_id
                                    }
                                    onChange={(event) =>
                                        evidenceForm.setData(
                                            'requested_from_user_id',
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">Selecciona…</option>
                                    {responsibles.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Fecha límite">
                                <input
                                    type="date"
                                    className={input}
                                    value={evidenceForm.data.due_date}
                                    onChange={(event) =>
                                        evidenceForm.setData(
                                            'due_date',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Descripción">
                                <input
                                    className={input}
                                    value={evidenceForm.data.description}
                                    onChange={(event) =>
                                        evidenceForm.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <div>
                                <button
                                    type="submit"
                                    className={secondaryButton}
                                >
                                    Solicitar evidencia
                                </button>
                            </div>
                        </form>
                    )}
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Conclusión del procedimiento">
                        {p.conclusion ? (
                            <p className="text-sm">
                                <b>{p.result_label}:</b> {p.conclusion}
                            </p>
                        ) : open && running ? (
                            <form
                                className="grid gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    complete.post(
                                        `/work/audit/procedures/${p.id}/complete`,
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <Field label="Resultado">
                                    <select
                                        className={input}
                                        value={complete.data.result}
                                        onChange={(event) =>
                                            complete.setData(
                                                'result',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="satisfactory">
                                            Satisfactorio
                                        </option>
                                        <option value="exceptions_noted">
                                            Con excepciones documentadas
                                        </option>
                                    </select>
                                </Field>
                                <Field label="Conclusión documentada">
                                    <textarea
                                        className={textarea}
                                        value={complete.data.conclusion}
                                        onChange={(event) =>
                                            complete.setData(
                                                'conclusion',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                                <div>
                                    <button
                                        type="submit"
                                        className={primaryButton}
                                        disabled={complete.processing}
                                    >
                                        Completar procedimiento
                                    </button>
                                </div>
                            </form>
                        ) : (
                            <p className="text-sm text-slate-500">
                                Inicia el procedimiento para documentar su
                                resultado.
                            </p>
                        )}
                    </Card>
                    <Card title="Hallazgos y papeles relacionados">
                        <ul className="grid gap-1 text-xs">
                            {findings.map((f) => (
                                <li key={f.id}>
                                    <Link
                                        href={href(f.destination)}
                                        className="font-bold text-blue-700"
                                    >
                                        {f.code}
                                    </Link>{' '}
                                    {f.title}{' '}
                                    <span
                                        className={toneBadge(
                                            findingTone[f.status] ?? 'slate',
                                        )}
                                    >
                                        {f.status_label}
                                    </span>
                                </li>
                            ))}
                            {papers.map((paper) => (
                                <li key={paper.id}>
                                    {paper.code} v{paper.version} ·{' '}
                                    {paper.title} ({paper.status_label})
                                </li>
                            ))}
                            {findings.length + papers.length === 0 && (
                                <li className="text-slate-500">
                                    Sin hallazgos ni papeles relacionados.
                                </li>
                            )}
                        </ul>
                    </Card>
                </div>
            </Shell>
        </>
    );
}
