import { Link, router, useForm, usePoll } from '@inertiajs/react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import * as accounting from '@/routes/accounting';
import * as fel from '@/routes/accounting/fel';
import { labels, TestNotice } from './types';
import type { Document } from './types';

export default function FelShow({
    company,
    document: doc,
    history,
    canWrite,
    notice,
    entry,
}: {
    company: Company;
    document: Document;
    history: { action: string; user_id: number; occurred_at: string }[];
    canWrite: boolean;
    notice: string;
    entry: number | null;
}) {
    const action = useForm({});
    const cancellation = useForm({ reason: '' });
    const args: [string, string] = [company.public_id, doc.public_id];
    usePoll(5000, { only: ['document', 'history', 'entry'] });

    return (
        <AccountingSection
            company={company}
            title={`Documento ${doc.series}-${doc.number}`}
            action={
                <Link className={secondary} href={fel.index(company.public_id)}>
                    Documentos
                </Link>
            }
        >
            <TestNotice notice={notice} />
            <Errors errors={action.errors} />
            <Errors errors={cancellation.errors} />
            <div className="flex flex-wrap items-center gap-3">
                <span className="rounded-full bg-white px-4 py-2 font-semibold">
                    {labels[doc.status]}
                </span>
                {doc.pdf_status === 'ready' && (
                    <a className={secondary} href={fel.pdf.url(args)}>
                        Descargar PDF
                    </a>
                )}
                {canWrite && doc.status === 'draft' && (
                    <Link className={secondary} href={fel.edit(args)}>
                        Editar borrador
                    </Link>
                )}
                {canWrite && ['draft', 'failed'].includes(doc.status) && (
                    <button
                        className={primary}
                        disabled={action.processing}
                        onClick={() => action.post(fel.certify.url(args))}
                    >
                        {doc.status === 'failed'
                            ? 'Reintentar certificación'
                            : 'Certificar en prueba'}
                    </button>
                )}
                {canWrite &&
                    ['certified', 'cancelled'].includes(doc.status) &&
                    doc.pdf_status !== 'ready' && (
                        <button
                            className={secondary}
                            disabled={action.processing}
                            onClick={() =>
                                action.post(fel.regeneratePdf.url(args))
                            }
                        >
                            Generar PDF
                        </button>
                    )}
                {canWrite &&
                    doc.status === 'certified' &&
                    doc.pdf_status === 'ready' &&
                    !doc.email_sent_at &&
                    doc.receiver_snapshot.email && (
                        <button
                            className={secondary}
                            disabled={action.processing}
                            onClick={() => action.post(fel.email.url(args))}
                        >
                            Enviar por correo
                        </button>
                    )}
                {canWrite && doc.status === 'certified' && !entry && (
                    <button
                        className={secondary}
                        disabled={action.processing}
                        onClick={() => action.post(fel.accounting.url(args))}
                    >
                        Crear borrador contable
                    </button>
                )}
                <button className={secondary} onClick={() => router.reload()}>
                    Actualizar
                </button>
            </div>
            <div className="grid gap-5 lg:grid-cols-2">
                <Card title="Emisor y receptor">
                    <p className="font-semibold">
                        {doc.fiscal_snapshot.name} · NIT{' '}
                        {doc.fiscal_snapshot.nit}
                    </p>
                    <p className="mt-4">
                        {doc.receiver_snapshot.name} · NIT{' '}
                        {doc.receiver_snapshot.nit}
                    </p>
                    <p>{doc.receiver_snapshot.address}</p>
                    <p>{doc.receiver_snapshot.email}</p>
                    <p className="mt-4">
                        Emisión: {doc.issued_on.slice(0, 10)} · GTQ
                    </p>
                </Card>
                <Card title="Resultado de certificación">
                    <p className="break-all">
                        Autorización simulada:{' '}
                        {doc.authorization_number ?? 'Pendiente'}
                    </p>
                    <p className="mt-3">
                        Serie FEL: {doc.fel_series ?? '—'} · Número FEL:{' '}
                        {doc.fel_number ?? '—'}
                    </p>
                    <p className="mt-3">
                        PDF:{' '}
                        {doc.pdf_status === 'ready'
                            ? 'Disponible'
                            : doc.pdf_status === 'failed'
                              ? 'Falló la generación'
                              : 'Pendiente'}
                    </p>
                    <p>
                        Correo:{' '}
                        {doc.email_sent_at
                            ? 'Enviado'
                            : ['failed', 'needs_review'].includes(
                                    doc.email_status,
                                )
                              ? 'Falló el envío'
                              : doc.receiver_snapshot.email
                                ? 'Pendiente'
                                : 'Sin destinatario'}
                    </p>
                    {entry && (
                        <Link
                            className="mt-3 block text-[#640000]"
                            href={accounting.show(company.public_id)}
                        >
                            Asiento contable #{entry} · Ver libros
                        </Link>
                    )}
                </Card>
            </div>
            <Card title="Detalle">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr>
                                {[
                                    'Descripción',
                                    'Cantidad',
                                    'Precio',
                                    'Descuento',
                                    'IVA',
                                    'Total',
                                ].map((h) => (
                                    <th className="p-3" key={h}>
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {doc.lines.map((line) => (
                                <tr
                                    className="border-t border-slate-100"
                                    key={line.id}
                                >
                                    <td className="p-3">
                                        {line.item}
                                        <p className="text-slate-500">
                                            {line.description}
                                        </p>
                                    </td>
                                    <td className="p-3">{line.quantity}</td>
                                    <td className="p-3">
                                        {money(line.unit_cents)}
                                    </td>
                                    <td className="p-3">
                                        {money(line.discount_cents)}
                                    </td>
                                    <td className="p-3">
                                        {money(line.tax_cents)}
                                    </td>
                                    <td className="p-3">
                                        {money(line.total_cents)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="mt-5 space-y-2 text-right">
                    <p>Subtotal: {money(doc.subtotal_cents)}</p>
                    <p>Descuentos: {money(doc.discount_cents)}</p>
                    <p>IVA: {money(doc.tax_cents)}</p>
                    <p className="text-xl font-semibold">
                        Total: {money(doc.total_cents)}
                    </p>
                </div>
                <p className="mt-4 text-sm">{doc.observations}</p>
            </Card>
            {canWrite && doc.status === 'certified' && (
                <Card title="Anulación de prueba">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            cancellation.post(fel.cancel.url(args));
                        }}
                    >
                        <label>
                            Motivo
                            <textarea
                                className={field}
                                minLength={5}
                                maxLength={500}
                                required
                                value={cancellation.data.reason}
                                onChange={(e) =>
                                    cancellation.setData(
                                        'reason',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <button
                            className={secondary + ' mt-3'}
                            disabled={cancellation.processing}
                        >
                            Anular documento simulado
                        </button>
                    </form>
                </Card>
            )}
            <Card title="Historial de certificación">
                <ol className="space-y-3 text-sm">
                    {history.map((event, i) => (
                        <li
                            key={i}
                            className="flex flex-wrap justify-between gap-2 border-b border-slate-100 pb-3"
                        >
                            <span>
                                {event.action.replace('fel.', '')} · Usuario #
                                {event.user_id}
                            </span>
                            <time>{event.occurred_at}</time>
                        </li>
                    ))}
                </ol>
            </Card>
        </AccountingSection>
    );
}
