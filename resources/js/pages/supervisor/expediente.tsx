import { Head, Link, router, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import {
    PageHeader,
    Shell,
    formatDate,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    ProgressBar,
    Table,
    money,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';
import type {
    SupervisedCompany,
    SupervisorContext,
} from '@/components/supervisor/kit';

type OpenItem = {
    id: number;
    party: string;
    document: string;
    due_on: string;
    balance_cents: number;
    status_label: string;
    status: string;
    destination: string;
};
type File = {
    company: {
        id: number;
        name: string;
        tax_id: string;
        email: string | null;
        vat_regime: string;
        income_tax_regime: string;
        office: string;
        responsibles: string[];
        links: Record<string, string>;
    };
    status:
        | (SupervisedCompany & {
              stages: {
                  label: string;
                  progress: number;
                  done: number;
                  total: number;
              }[];
          })
        | null;
    documents: {
        id: number;
        category: string;
        name: string;
        received_at: string;
        status: string;
        status_label: string;
    }[];
    accounting: {
        draft: number;
        pending_review: number;
        approved: number;
        posted: number;
    } | null;
    purchases: {
        id: number;
        number: string;
        supplier_name: string | null;
        document_date: string;
        total_cents: number;
        status: string;
    }[];
    sales: {
        id: number;
        number: string | null;
        customer_name: string | null;
        issued_on: string;
        total_cents: number;
        status: string;
    }[];
    receivables: { rows: OpenItem[]; summary: Record<string, number> };
    payables: { rows: OpenItem[]; summary: Record<string, number> };
    banks: {
        id: number;
        bank: string;
        bank_balance_cents: number;
        ledger_balance_cents: number;
        difference_cents: number;
        pending: number;
        status_label: string;
        status: string;
    }[];
    fiscal: {
        key: string;
        name: string;
        period: string;
        due_on: string;
        days_left: number;
        status_label: string;
        tone: string;
    }[];
    observations: {
        text: string;
        stage: string;
        author: string | null;
        date: string;
    }[];
    history: {
        id: number;
        user: string;
        action: string;
        module: string;
        occurred_at: string;
    }[];
};

const tabs = [
    ['summary', 'Resumen'],
    ['documents', 'Documentos'],
    ['accounting', 'Contabilidad'],
    ['purchases', 'Compras'],
    ['sales', 'Ventas'],
    ['receivables', 'Cuentas por cobrar'],
    ['payables', 'Cuentas por pagar'],
    ['banks', 'Bancos'],
    ['fiscal', 'Fiscal'],
    ['reports', 'Reportes'],
    ['observations', 'Observaciones'],
    ['history', 'Historial'],
] as const;

export default function SupervisorExpediente({
    file,
    tab,
}: {
    context: SupervisorContext;
    file: File;
    tab: string;
}) {
    const page = usePage();
    const go = (key: string) => {
        const params = Object.fromEntries(
            new URL(page.url, 'https://atlantia.local').searchParams,
        );
        router.get(
            page.url.split('?')[0],
            { ...params, tab: key },
            { preserveScroll: true, preserveState: true },
        );
    };
    const { company, status } = file;
    const openItems = (
        data: { rows: OpenItem[]; summary: Record<string, number> },
        party: string,
    ) => (
        <>
            <div className="mb-3 grid gap-2 text-sm sm:grid-cols-4">
                <p>
                    Total: <b>{money(data.summary.total_cents)}</b>
                </p>
                <p className="text-red-700">
                    Vencido: <b>{money(data.summary.overdue_cents)}</b>
                </p>
                <p className="text-amber-700">
                    Próximo a vencer:{' '}
                    <b>{money(data.summary.due_soon_cents)}</b>
                </p>
                <p className="text-emerald-700">
                    Del mes: <b>{money(data.summary.settled_cents)}</b>
                </p>
            </div>
            <Table
                headers={[party, 'Documento', 'Vencimiento', 'Saldo', 'Estado']}
                empty={data.rows.length === 0 && 'Sin saldos abiertos.'}
            >
                {data.rows.map((row) => (
                    <tr key={row.id}>
                        <td className="px-2 py-2">{row.party}</td>
                        <td className="px-2 py-2">
                            <a
                                href={row.destination}
                                className="hover:text-[#640000]"
                            >
                                {row.document}
                            </a>
                        </td>
                        <td className="px-2 py-2">{formatDate(row.due_on)}</td>
                        <td className="px-2 py-2 font-bold">
                            {money(row.balance_cents)}
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    row.status === 'overdue'
                                        ? 'red'
                                        : row.status === 'partial'
                                          ? 'amber'
                                          : row.status === 'paid'
                                            ? 'green'
                                            : 'slate',
                                )}
                            >
                                {row.status_label}
                            </span>
                        </td>
                    </tr>
                ))}
            </Table>
        </>
    );

    return (
        <>
            <Head title={`Expediente · ${company.name}`} />
            <Shell>
                <PageHeader
                    title={company.name}
                    subtitle={`Expediente contable · NIT ${company.tax_id} · ${company.office}`}
                    actions={
                        <Link
                            href="/supervisor/companies"
                            className={secondaryButton}
                        >
                            Volver a empresas
                        </Link>
                    }
                />
                <Card title="Datos generales">
                    <div className="grid gap-2 text-sm md:grid-cols-3">
                        <p>
                            <span className="text-[#526b92]">
                                Razón social:
                            </span>{' '}
                            <b>{company.name}</b>
                        </p>
                        <p>
                            <span className="text-[#526b92]">NIT:</span>{' '}
                            <b>{company.tax_id}</b>
                        </p>
                        <p>
                            <span className="text-[#526b92]">Correo:</span>{' '}
                            {company.email ?? '—'}
                        </p>
                        <p>
                            <span className="text-[#526b92]">Régimen IVA:</span>{' '}
                            {company.vat_regime}
                        </p>
                        <p>
                            <span className="text-[#526b92]">Régimen ISR:</span>{' '}
                            {company.income_tax_regime}
                        </p>
                        <p>
                            <span className="text-[#526b92]">
                                Responsables:
                            </span>{' '}
                            {company.responsibles.join(', ') || 'Sin asignar'}
                        </p>
                    </div>
                </Card>
                <div className="flex flex-wrap gap-1.5">
                    {tabs.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => go(key)}
                            className={`rounded-full px-3 py-1.5 text-xs font-bold ring-1 ${tab === key ? 'bg-[#640000] text-white ring-[#640000]' : 'bg-white text-[#395277] ring-slate-200'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'summary' && status && (
                    <Card
                        title={`Resumen · ${status.period_name ?? 'Sin período'}`}
                    >
                        <p className="mb-2">
                            <span
                                className={toneBadge(
                                    status.blocked
                                        ? 'red'
                                        : (statusTone[status.status] ??
                                              'slate'),
                                )}
                            >
                                {status.blocked
                                    ? 'Bloqueado'
                                    : status.status_label}
                            </span>{' '}
                            <span className="ml-2 font-black">
                                {status.progress}%
                            </span>{' '}
                            <span className="ml-2 text-sm text-[#526b92]">
                                Fiscal: {status.fiscal_label} · Documentos{' '}
                                {status.documents_done}/{status.documents_total}
                            </span>
                        </p>
                        {status.stages.map((stage) => (
                            <div
                                key={stage.label}
                                className="mb-1.5 grid grid-cols-[140px_1fr] items-center gap-2 text-xs"
                            >
                                <span>
                                    {stage.label} ({stage.done}/{stage.total})
                                </span>
                                <ProgressBar value={stage.progress} />
                            </div>
                        ))}
                        {status.stages.length === 0 && (
                            <p className="text-sm text-slate-500">
                                Sin tareas registradas en el período.
                            </p>
                        )}
                    </Card>
                )}
                {tab === 'documents' && (
                    <Card title="Documentos">
                        <Table
                            headers={[
                                'Documento',
                                'Clasificación',
                                'Recibido',
                                'Estado',
                            ]}
                            empty={
                                file.documents.length === 0 &&
                                'Sin documentos cargados.'
                            }
                        >
                            {file.documents.map((doc) => (
                                <tr key={`${doc.category}-${doc.id}`}>
                                    <td className="px-2 py-2">{doc.name}</td>
                                    <td className="px-2 py-2">
                                        {doc.category}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(doc.received_at)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                doc.status === 'processed'
                                                    ? 'green'
                                                    : doc.status === 'duplicate'
                                                      ? 'violet'
                                                      : doc.status ===
                                                          'rejected'
                                                        ? 'red'
                                                        : 'amber',
                                            )}
                                        >
                                            {doc.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {tab === 'accounting' && file.accounting && (
                    <Card
                        title="Contabilidad del período"
                        action={
                            <a
                                href={company.links.books}
                                className={secondaryButton}
                            >
                                <ExternalLink className="size-3.5" /> Libro
                                diario y mayor
                            </a>
                        }
                    >
                        <div className="grid gap-2 text-center sm:grid-cols-4">
                            {[
                                ['Borradores', file.accounting.draft],
                                ['En revisión', file.accounting.pending_review],
                                ['Aprobados', file.accounting.approved],
                                ['Contabilizados', file.accounting.posted],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-xl border border-slate-100 p-3"
                                >
                                    <p className="text-2xl font-black">
                                        {value}
                                    </p>
                                    <p className="text-xs text-[#526b92]">
                                        {label}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-[#526b92]">
                            Las pólizas se revisan, aprueban o devuelven desde
                            el libro diario de la empresa; lo aprobado no se
                            elimina, se revierte formalmente.
                        </p>
                    </Card>
                )}
                {tab === 'purchases' && (
                    <Card
                        title="Compras del período"
                        action={
                            <a
                                href={company.links.purchases}
                                className={secondaryButton}
                            >
                                <ExternalLink className="size-3.5" /> Abrir
                                compras
                            </a>
                        }
                    >
                        <Table
                            headers={[
                                'Número',
                                'Proveedor',
                                'Fecha',
                                'Total',
                                'Estado',
                            ]}
                            empty={
                                file.purchases.length === 0 &&
                                'Sin compras en el período.'
                            }
                        >
                            {file.purchases.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2">{row.number}</td>
                                    <td className="px-2 py-2">
                                        {row.supplier_name ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(row.document_date)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(row.total_cents)}
                                    </td>
                                    <td className="px-2 py-2">{row.status}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {tab === 'sales' && (
                    <Card
                        title="Ventas del período"
                        action={
                            <a
                                href={company.links.sales}
                                className={secondaryButton}
                            >
                                <ExternalLink className="size-3.5" /> Abrir
                                ventas
                            </a>
                        }
                    >
                        <Table
                            headers={[
                                'Número',
                                'Cliente',
                                'Fecha',
                                'Total',
                                'Estado',
                            ]}
                            empty={
                                file.sales.length === 0 &&
                                'Sin ventas en el período.'
                            }
                        >
                            {file.sales.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2">
                                        {row.number ?? 'Borrador'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.customer_name ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {formatDate(row.issued_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(row.total_cents)}
                                    </td>
                                    <td className="px-2 py-2">{row.status}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {tab === 'receivables' && (
                    <Card title="Cuentas por cobrar">
                        {openItems(file.receivables, 'Cliente')}
                    </Card>
                )}
                {tab === 'payables' && (
                    <Card title="Cuentas por pagar">
                        {openItems(file.payables, 'Proveedor')}
                    </Card>
                )}
                {tab === 'banks' && (
                    <Card
                        title="Bancos"
                        action={
                            <a
                                href={company.links.banks}
                                className={secondaryButton}
                            >
                                <ExternalLink className="size-3.5" /> Abrir
                                bancos
                            </a>
                        }
                    >
                        <Table
                            headers={[
                                'Cuenta',
                                'Saldo banco',
                                'Saldo contable',
                                'Diferencia',
                                'Pendientes',
                                'Estado',
                            ]}
                            empty={
                                file.banks.length === 0 &&
                                'Sin cuentas bancarias.'
                            }
                        >
                            {file.banks.map((bank) => (
                                <tr key={bank.id}>
                                    <td className="px-2 py-2">{bank.bank}</td>
                                    <td className="px-2 py-2">
                                        {money(bank.bank_balance_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {money(bank.ledger_balance_cents)}
                                    </td>
                                    <td
                                        className={`px-2 py-2 font-bold ${bank.difference_cents !== 0 ? 'text-red-700' : ''}`}
                                    >
                                        {money(bank.difference_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {bank.pending}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span
                                            className={toneBadge(
                                                bank.status === 'reconciled'
                                                    ? 'green'
                                                    : bank.status ===
                                                        'difference'
                                                      ? 'red'
                                                      : 'amber',
                                            )}
                                        >
                                            {bank.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {tab === 'fiscal' && (
                    <Card
                        title="Obligaciones fiscales"
                        action={
                            <a
                                href={company.links.vat_books}
                                className={secondaryButton}
                            >
                                <ExternalLink className="size-3.5" /> Libros IVA
                            </a>
                        }
                    >
                        <Table
                            headers={[
                                'Obligación',
                                'Período',
                                'Fecha límite',
                                'Días',
                                'Estado',
                            ]}
                            empty={
                                file.fiscal.length === 0 &&
                                'Sin obligaciones en el rango.'
                            }
                        >
                            {file.fiscal.map((row) => (
                                <tr key={row.key}>
                                    <td className="px-2 py-2">{row.name}</td>
                                    <td className="px-2 py-2">{row.period}</td>
                                    <td className="px-2 py-2">
                                        {formatDate(row.due_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.days_left}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge(row.tone)}>
                                            {row.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
                {tab === 'reports' && (
                    <Card title="Reportes de la empresa">
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={company.links.reports}
                                className={secondaryButton}
                            >
                                Estados financieros y balance
                            </a>
                            <a
                                href={company.links.books}
                                className={secondaryButton}
                            >
                                Libro diario y mayor
                            </a>
                            <a
                                href={company.links.vat_books}
                                className={secondaryButton}
                            >
                                Libros IVA
                            </a>
                            <a
                                href={company.links.dashboard}
                                className={secondaryButton}
                            >
                                Dashboard de la empresa
                            </a>
                        </div>
                    </Card>
                )}
                {tab === 'observations' && (
                    <Card title="Observaciones de revisión">
                        <ul className="divide-y divide-slate-100 text-sm">
                            {file.observations.map((row, index) => (
                                <li key={index} className="py-2">
                                    <p className="font-semibold">
                                        “{row.text}”
                                    </p>
                                    <p className="text-xs text-[#526b92]">
                                        {row.stage} · {row.author ?? 'Sistema'}{' '}
                                        · {formatDate(row.date, true)}
                                    </p>
                                </li>
                            ))}
                            {file.observations.length === 0 && (
                                <li className="py-4 text-slate-500">
                                    Sin observaciones registradas.
                                </li>
                            )}
                        </ul>
                    </Card>
                )}
                {tab === 'history' && (
                    <Card title="Historial">
                        <Table
                            headers={['Fecha', 'Usuario', 'Módulo', 'Acción']}
                            empty={
                                file.history.length === 0 && 'Sin movimientos.'
                            }
                        >
                            {file.history.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2">
                                        {formatDate(row.occurred_at, true)}
                                    </td>
                                    <td className="px-2 py-2">{row.user}</td>
                                    <td className="px-2 py-2">{row.module}</td>
                                    <td className="px-2 py-2">{row.action}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                )}
            </Shell>
        </>
    );
}
