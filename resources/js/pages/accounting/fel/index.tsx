import { Link, router, useForm, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    field,
    money,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import * as fel from '@/routes/accounting/fel';
import { FelInvoiceForm } from './create';
import { FelIssuerForm } from './settings';
import { labels, TestNotice } from './types';
import type { Customer, Document } from './types';

export default function FelIndex({
    company,
    companies,
    documents,
    stats,
    filters,
    customers,
    canWrite,
    canManage,
    notice,
    issuer,
    certification,
    certifiers,
    vatAffiliations,
    isPlatform,
    today,
}: {
    company: Company;
    companies: Company[];
    documents: Page<Document>;
    stats: Record<string, number>;
    filters: Record<string, string>;
    customers: Customer[];
    canWrite: boolean;
    canManage: boolean;
    notice: string;
    issuer: {
        name: string;
        nit: string;
        address: string;
        logo_data_uri: string | null;
        provider: string;
        environment: string;
        establishment_code?: string;
        vat_affiliation?: string;
        issuer_email?: string | null;
        trade_name?: string | null;
        certifier_environment?: string;
    };
    certification: { certifier: string; credentials: Record<string, string> };
    certifiers: {
        code: string;
        label: string;
        fields: { key: string; label: string; secret?: boolean }[];
    }[];
    vatAffiliations: Record<string, string>;
    isPlatform: boolean;
    today: string;
}) {
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        customer: filters.customer ?? '',
        min: filters.min ?? '',
        max: filters.max ?? '',
    });
    usePoll(10000, { only: ['documents', 'stats'] });

    return (
        <AccountingSection
            company={company}
            title="Facturación electrónica"
            denseHeader
            action={
                canWrite && (
                    <div className="flex flex-wrap gap-2">
                        {canManage && (
                            <button
                                type="button"
                                className={secondary}
                                onClick={() => setSettingsOpen(true)}
                            >
                                Configurar emisor
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => setInvoiceOpen(true)}
                        >
                            Nueva factura
                        </button>
                    </div>
                )
            }
        >
            <TestNotice notice={notice} compact />
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                {Object.entries({
                    documents: 'Documentos del mes',
                    total: 'Total del mes',
                    pending: 'Pendientes',
                    certified: 'Certificados',
                    failed: 'Fallidos',
                    cancelled: 'Anulados',
                }).map(([key, label]) => (
                    <div
                        key={key}
                        className="rounded-xl border border-rose-100 bg-white px-4 py-3"
                    >
                        <p className="text-xs text-slate-500">{label}</p>
                        <p className="mt-1 text-lg leading-tight font-semibold">
                            {key === 'total' ? money(stats[key]) : stats[key]}
                        </p>
                    </div>
                ))}
            </div>
            <Card title="Documentos">
                <form
                    className="grid gap-3 sm:grid-cols-3 lg:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.get(fel.index.url(company.public_id), {
                            preserveState: true,
                        });
                    }}
                >
                    <label className="text-sm">
                        Empresa
                        <select
                            className={field}
                            value={company.public_id}
                            onChange={(e) =>
                                router.visit(fel.index(e.target.value))
                            }
                        >
                            {companies.map((c) => (
                                <option key={c.public_id} value={c.public_id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Número interno
                        <input
                            className={field}
                            value={form.data.search}
                            onChange={(e) =>
                                form.setData('search', e.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm">
                        Estado
                        <select
                            className={field}
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            <option value="">Todos</option>
                            {Object.entries(labels).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Cliente
                        <select
                            className={field}
                            value={form.data.customer}
                            onChange={(e) =>
                                form.setData('customer', e.target.value)
                            }
                        >
                            <option value="">Todos</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Desde
                        <input
                            type="date"
                            className={field}
                            value={form.data.from}
                            onChange={(e) =>
                                form.setData('from', e.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm">
                        Hasta
                        <input
                            type="date"
                            className={field}
                            value={form.data.to}
                            onChange={(e) => form.setData('to', e.target.value)}
                        />
                    </label>
                    <label className="text-sm">
                        Monto mínimo
                        <input
                            className={field}
                            inputMode="decimal"
                            value={form.data.min}
                            onChange={(e) =>
                                form.setData('min', e.target.value)
                            }
                        />
                    </label>
                    <label className="text-sm">
                        Monto máximo
                        <input
                            className={field}
                            inputMode="decimal"
                            value={form.data.max}
                            onChange={(e) =>
                                form.setData('max', e.target.value)
                            }
                        />
                    </label>
                    <button className={primary} disabled={form.processing}>
                        Filtrar
                    </button>
                </form>
                <div className="mt-5 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-xs text-slate-500">
                            <tr>
                                {[
                                    'Documento',
                                    'Cliente',
                                    'Fecha',
                                    'Subtotal',
                                    'Impuestos',
                                    'Total',
                                    'Estado',
                                    'Acciones',
                                ].map((h) => (
                                    <th key={h} className="p-3">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {documents.data.map((doc) => (
                                <tr
                                    key={doc.public_id}
                                    className="border-b border-slate-100"
                                >
                                    <td className="p-3 font-semibold">
                                        {doc.series}-{doc.number}
                                    </td>
                                    <td className="p-3">
                                        {doc.receiver_snapshot.name}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {doc.issued_on.slice(0, 10)}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {money(doc.subtotal_cents)}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {money(doc.tax_cents)}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {money(doc.total_cents)}
                                    </td>
                                    <td className="p-3">
                                        {labels[doc.status]}
                                    </td>
                                    <td className="p-3">
                                        <Link
                                            className="font-medium text-[#640000]"
                                            href={fel.show([
                                                company.public_id,
                                                doc.public_id,
                                            ])}
                                        >
                                            Ver documento
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {!documents.data.length && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="p-10 text-center text-slate-500"
                                    >
                                        No hay documentos para estos filtros.
                                        Crea tu primera factura de prueba.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Pager page={documents} />
            </Card>
            <p className="text-sm text-slate-500">
                Tipo disponible: factura. Notas de crédito y débito se
                incorporarán en una fase posterior.
            </p>
            <Dialog open={settingsOpen} onOpenChange={setSettingsOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-4xl">
                    <DialogTitle>Configurar emisor</DialogTitle>
                    <DialogDescription>
                        Datos fiscales y de emisión usados por la facturación
                        electrónica de esta empresa.
                    </DialogDescription>
                    <FelIssuerForm
                        company={company}
                        issuer={issuer}
                        certification={certification}
                        certifiers={certifiers}
                        vatAffiliations={vatAffiliations}
                        isPlatform={isPlatform}
                        notice={null}
                    />
                </DialogContent>
            </Dialog>
            <Dialog open={invoiceOpen} onOpenChange={setInvoiceOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-6xl">
                    <DialogTitle>Nueva factura</DialogTitle>
                    <DialogDescription>
                        Completa los datos del receptor, líneas e impuestos
                        antes de emitir la factura de prueba.
                    </DialogDescription>
                    <TestNotice notice={notice} compact />
                    <FelInvoiceForm
                        company={company}
                        customers={customers}
                        document={null}
                        today={today}
                        notice={notice}
                    />
                </DialogContent>
            </Dialog>
        </AccountingSection>
    );
}
