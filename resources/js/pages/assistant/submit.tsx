import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Send,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import {
    ProgressValue,
    StageIcon,
    useAssistantHref,
} from '@/components/assistant/kit';
import type {
    AssistantCompany,
    AssistantContext,
    AssistantPermissions,
} from '@/components/assistant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, statusTone, toneBadge } from '@/components/supervisor/kit';

type Finding = {
    key: string;
    label: string;
    detail: string;
    destination: string | null;
};
type Check = { ready: boolean; blockers: Finding[]; warnings: Finding[] };

export default function AssistantSubmit({
    company,
    check,
    canOverride,
    permissions,
    notice,
}: {
    context: AssistantContext;
    company: AssistantCompany;
    check: Check | null;
    canOverride: boolean;
    permissions: AssistantPermissions;
    notice: string | null;
}) {
    const href = useAssistantHref();
    const errors = usePage().props.errors as Record<string, string>;
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const submit = (override: boolean) => {
        if (company.period_id === null) {
            return;
        }

        setProcessing(true);
        router.post(
            `/assistant/companies/${company.id}/periods/${company.period_id}/submit`,
            override ? { override_reason: reason } : {},
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <>
            <Head title={`Enviar a revisión · ${company.name}`} />
            <Shell>
                <Notice message={notice} />
                {Object.values(errors).length > 0 && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <Link
                    href={href('/assistant/companies')}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-[#526b92] hover:text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Mis empresas
                </Link>
                <PageHeader
                    title={`Enviar a revisión: ${company.name}`}
                    subtitle={`${company.period_name ?? 'Sin período'} · Revisa: ${company.accountant?.name ?? 'contador encargado por asignar'}`}
                />

                <section className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Estado</p>
                        <span
                            className={toneBadge(
                                statusTone[company.status] ?? 'slate',
                            )}
                        >
                            {company.status_label}
                        </span>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Avance</p>
                        <ProgressValue value={company.progress} />
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Etapas</p>
                        <div className="mt-1 flex gap-1.5">
                            {company.stages.map((stage) => (
                                <StageIcon key={stage.key} stage={stage} />
                            ))}
                        </div>
                    </div>
                </section>

                {check === null ? (
                    <Card title="Validación">
                        <p className="text-sm text-[#526b92]">
                            La empresa no tiene un período abierto.
                        </p>
                    </Card>
                ) : (
                    <>
                        <Card
                            title={
                                check.ready
                                    ? 'Lista para enviar'
                                    : `No se puede enviar: ${check.blockers.length} bloqueo(s)`
                            }
                            action={
                                <span
                                    className={toneBadge(
                                        check.ready ? 'green' : 'red',
                                    )}
                                >
                                    {check.ready
                                        ? 'Sin bloqueos'
                                        : 'Con bloqueos'}
                                </span>
                            }
                        >
                            {check.blockers.length === 0 ? (
                                <p className="flex items-center gap-2 text-sm text-emerald-700">
                                    <CheckCircle2 className="size-4" /> Todas
                                    las validaciones obligatorias pasaron.
                                </p>
                            ) : (
                                <ul className="space-y-1.5 text-sm">
                                    {check.blockers.map((blocker) => (
                                        <li
                                            key={blocker.key}
                                            className="flex items-start gap-2 rounded-lg border border-red-100 bg-red-50/50 p-2"
                                        >
                                            <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                                            <span className="flex-1">
                                                <b>{blocker.label}.</b>{' '}
                                                <span className="text-[#526b92]">
                                                    {blocker.detail}
                                                </span>
                                            </span>
                                            {blocker.destination && (
                                                <a
                                                    href={blocker.destination}
                                                    className="shrink-0 text-xs font-bold text-[#640000] hover:underline"
                                                >
                                                    Resolver
                                                </a>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {check.warnings.length > 0 && (
                                <ul className="mt-3 space-y-1.5 text-sm">
                                    {check.warnings.map((warning) => (
                                        <li
                                            key={warning.key}
                                            className="flex items-start gap-2 rounded-lg border border-amber-100 bg-amber-50/50 p-2"
                                        >
                                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                            <span>
                                                <b>{warning.label}.</b>{' '}
                                                <span className="text-[#526b92]">
                                                    {warning.detail}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                        {company.can_submit && permissions.canSubmitReview && (
                            <Card title="Enviar">
                                {check.ready ? (
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        disabled={processing}
                                        onClick={() => submit(false)}
                                    >
                                        <Send className="size-4" /> Enviar a
                                        revisión
                                    </button>
                                ) : canOverride ? (
                                    <div className="grid gap-2">
                                        <p className="text-sm text-[#526b92]">
                                            Tu rol puede enviar con bloqueos. El
                                            motivo es obligatorio y queda
                                            auditado.
                                        </p>
                                        <textarea
                                            aria-label="Motivo"
                                            className={`${input} h-20 py-2`}
                                            placeholder="Motivo para enviar con bloqueos"
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                        <div>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                disabled={
                                                    processing ||
                                                    reason.trim().length < 10
                                                }
                                                onClick={() => submit(true)}
                                            >
                                                Enviar con bloqueos
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-sm text-[#526b92]">
                                        Resuelve los bloqueos para poder enviar
                                        la empresa al contador encargado.
                                    </p>
                                )}
                            </Card>
                        )}
                    </>
                )}
            </Shell>
        </>
    );
}
