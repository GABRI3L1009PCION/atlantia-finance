import { useForm } from '@inertiajs/react';
import { Badge, Label } from '@/components/accounting-page';
import { Card, Errors, field, primary } from '@/components/accounting-ui';

export type StatementReview = {
    checks: {
        key: string;
        label: string;
        status: 'ok' | 'warning' | 'error';
        detail: string;
    }[];
    errors: number;
    warnings: number;
    ready: boolean;
};

export type StatementSigners = {
    signer_name: string;
    signer_title: string;
    accountant_name: string;
    accountant_title: string;
    accountant_registration: string;
    signing_place: string;
};

const tones = { ok: 'green', warning: 'amber', error: 'red' } as const;
const labels = { ok: 'Correcto', warning: 'Revisar', error: 'Error' };

export function StatementReviewCard({ review }: { review: StatementReview }) {
    return (
        <Card title="Revisión antes de firmar">
            <p className="mb-3 text-sm text-slate-600">
                {review.ready
                    ? review.warnings === 0
                        ? 'Los estados financieros pasan todas las revisiones.'
                        : `Sin errores, con ${review.warnings} punto(s) por revisar antes de firmar.`
                    : `${review.errors} error(es): los estados financieros no pueden firmarse así.`}
            </p>
            <ul className="divide-y divide-slate-100">
                {review.checks.map((check) => (
                    <li
                        key={check.key}
                        className="flex flex-wrap items-start gap-3 py-2 text-sm"
                    >
                        <Badge tone={tones[check.status]}>
                            {labels[check.status]}
                        </Badge>
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">{check.label}</p>
                            <p className="text-slate-600">{check.detail}</p>
                        </div>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

export function StatementSignersCard({
    action,
    signers,
    accountantTitles,
    signerTitles,
}: {
    action: string;
    signers: StatementSigners;
    accountantTitles: string[];
    signerTitles: string[];
}) {
    const form = useForm(signers);
    const input = (key: keyof StatementSigners, label: string) => (
        <Label text={label}>
            <input
                className={field}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </Label>
    );
    const select = (
        key: keyof StatementSigners,
        label: string,
        options: string[],
    ) => (
        <Label text={label}>
            <select
                className={field}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            >
                {options.map((option) => (
                    <option key={option}>{option}</option>
                ))}
            </select>
        </Label>
    );

    return (
        <Card title="Certificación y firmas">
            <p className="mb-3 text-sm text-slate-600">
                Salen al pie de los estados financieros en PDF y de los libros
                de Inventarios y Estados Financieros, con la cifra certificada
                en letras.
            </p>
            <form
                className="grid gap-3 md:grid-cols-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(action, { preserveScroll: true });
                }}
            >
                {input('signer_name', 'Propietario o representante legal')}
                {select('signer_title', 'Firma como', signerTitles)}
                {input('signing_place', 'Lugar (ej. Puerto Barrios, Izabal)')}
                {input('accountant_name', 'Contador')}
                {select('accountant_title', 'Título', accountantTitles)}
                {input('accountant_registration', 'Registro SAT del contador')}
                <div className="md:col-span-3">
                    <button className={primary} disabled={form.processing}>
                        Guardar firmantes
                    </button>
                    <Errors errors={form.errors} />
                </div>
            </form>
        </Card>
    );
}
