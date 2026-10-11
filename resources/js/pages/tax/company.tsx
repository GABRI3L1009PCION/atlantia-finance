import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ProgressValue, StageIcon } from '@/components/assistant/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import {
    felTone,
    fiscalStatusTone,
    obligationTone,
    useTaxHref,
} from '@/components/tax/kit';
import type {
    FelDocument,
    FiscalCompany,
    FiscalDocument,
    Obligation,
    TaxContext,
} from '@/components/tax/kit';

type Tax = {
    code: string;
    name: string;
    tax_type: string;
    rate_basis_points: number;
    valid_from: string;
    valid_to: string | null;
};
type Observation = {
    id: number;
    message: string;
    status_label: string;
    author_name: string | null;
    assignee_name: string | null;
};

const roles: Record<string, string> = {
    accountant_in_charge: 'Contador encargado',
    accounting_assistant: 'Auxiliar',
    tax_specialist: 'Encargado fiscal',
    accounting_supervisor: 'Supervisor',
    general_accountant: 'Contador general',
};
const regimes: Record<string, string> = {
    general: 'IVA general',
    small_taxpayer: 'Pequeño contribuyente',
    exempt: 'Exento',
    utilities: 'ISR sobre utilidades',
    optional_simplified: 'ISR opcional simplificado',
};
const taxTypes: Record<string, string> = {
    vat: 'IVA',
    income_tax: 'ISR',
    withholding: 'Retención',
    stamp: 'Timbres',
    specific: 'Específico',
    other: 'Otro',
};

export default function TaxCompany({
    company,
    profile,
    taxes,
    team,
    obligations,
    fel,
    documents,
    observations,
}: {
    context: TaxContext;
    company: FiscalCompany;
    profile: {
        vat_regime: string;
        income_tax_regime: string;
        timezone: string;
    } | null;
    taxes: Tax[];
    team: { name: string; role: string }[];
    obligations: Obligation[];
    fel: FelDocument[];
    documents: FiscalDocument[];
    observations: Observation[];
}) {
    const href = useTaxHref();

    return (
        <>
            <Head title={`Fiscal · ${company.name}`} />
            <Shell>
                <Link
                    href={href('/tax/companies')}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-[#526b92] hover:text-[#640000]"
                >
                    <ArrowLeft className="size-4" /> Estado por empresa
                </Link>
                <PageHeader
                    title={company.name}
                    subtitle={`NIT ${company.tax_id} · ${company.period}`}
                    actions={
                        <a
                            href={company.company_dashboard}
                            className={secondaryButton}
                        >
                            Ver empresa completa
                        </a>
                    }
                />

                <section className="grid gap-3 md:grid-cols-4">
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Estado fiscal</p>
                        <span
                            className={toneBadge(
                                company.overdue
                                    ? 'red'
                                    : (fiscalStatusTone[company.status] ??
                                          'slate'),
                            )}
                        >
                            {company.overdue
                                ? 'Atrasada'
                                : company.status_label}
                        </span>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Avance fiscal</p>
                        <ProgressValue value={company.progress} />
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Regímenes</p>
                        <b className="text-sm">
                            {profile
                                ? `${regimes[profile.vat_regime] ?? profile.vat_regime} · ${regimes[profile.income_tax_regime] ?? profile.income_tax_regime}`
                                : 'Sin perfil fiscal'}
                        </b>
                    </div>
                    <div className="rounded-xl border border-[#e7e1dd] bg-white p-3">
                        <p className="text-xs text-[#526b92]">Equipo</p>
                        <p className="text-xs">
                            {team
                                .map(
                                    (member) =>
                                        `${member.name} (${roles[member.role] ?? member.role})`,
                                )
                                .join(' · ') || '—'}
                        </p>
                    </div>
                </section>

                <Card title="Etapas del mes">
                    <ul className="grid gap-2 text-sm md:grid-cols-2 xl:grid-cols-4">
                        {company.stages.map((stage) => (
                            <li
                                key={stage.key}
                                className="flex items-start gap-2 rounded-lg border border-slate-100 p-2"
                            >
                                <StageIcon stage={stage} />
                                <span>
                                    <b>{stage.label}</b>
                                    <span className="block text-xs text-[#526b92]">
                                        {stage.detail}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card title="Configuración fiscal vigente">
                        <Table
                            headers={['Impuesto', 'Tipo', 'Tasa', 'Vigencia']}
                            empty={
                                taxes.length === 0 &&
                                'Sin impuestos configurados.'
                            }
                        >
                            {taxes.map((tax) => (
                                <tr key={`${tax.code}-${tax.valid_from}`}>
                                    <td className="px-2 py-2 font-bold">
                                        {tax.code}
                                        <p className="text-[11px] font-normal text-[#526b92]">
                                            {tax.name}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">
                                        {taxTypes[tax.tax_type] ?? tax.tax_type}
                                    </td>
                                    <td className="px-2 py-2">
                                        {(tax.rate_basis_points / 100).toFixed(
                                            2,
                                        )}
                                        %
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(tax.valid_from)} –{' '}
                                        {tax.valid_to
                                            ? formatDate(tax.valid_to)
                                            : 'vigente'}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Obligaciones">
                        <Table
                            headers={[
                                'Obligación',
                                'Período',
                                'Vence',
                                'Estado',
                            ]}
                            empty={
                                obligations.length === 0 &&
                                'No existen obligaciones.'
                            }
                        >
                            {obligations.map((obligation) => (
                                <tr key={obligation.id}>
                                    <td className="px-2 py-2 font-bold">
                                        <Link
                                            href={href(obligation.destination)}
                                            className="hover:text-[#640000]"
                                        >
                                            {obligation.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {obligation.reference_period}
                                    </td>
                                    <td
                                        className={`px-2 py-2 ${obligation.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(obligation.due_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                obligationTone[
                                                    obligation.status
                                                ] ?? 'slate',
                                            )}
                                        >
                                            {obligation.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="FEL que requieren atención">
                        <Table
                            headers={[
                                'Documento',
                                'Fecha',
                                'Total',
                                'Estado',
                                '',
                            ]}
                            empty={
                                fel.length === 0 &&
                                'No existen incidencias FEL que requieran atención.'
                            }
                        >
                            {fel.map((document) => (
                                <tr key={document.id}>
                                    <td className="px-2 py-2 font-bold">
                                        {document.document}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(document.issued_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(document.total_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                felTone[document.status] ??
                                                    'slate',
                                            )}
                                        >
                                            {document.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">
                                        <a
                                            href={document.destination}
                                            className={secondaryButton}
                                        >
                                            Ver
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Documentos y observaciones">
                        <ul className="divide-y divide-slate-100 text-sm">
                            {documents.length + observations.length === 0 && (
                                <li className="py-4 text-center text-slate-500">
                                    Sin pendientes.
                                </li>
                            )}
                            {documents.map((document) => (
                                <li key={document.key} className="py-2">
                                    <b>{document.type}</b> ·{' '}
                                    {document.description}{' '}
                                    <span className="text-xs text-amber-700">
                                        ({document.status_label})
                                    </span>
                                </li>
                            ))}
                            {observations.map((observation) => (
                                <li key={observation.id} className="py-2">
                                    <b>Observación</b> · {observation.message}{' '}
                                    <span className="text-xs text-violet-700">
                                        ({observation.status_label})
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </Shell>
        </>
    );
}
