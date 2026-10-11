<?php

declare(strict_types=1);

namespace App\Services\Integrations;

/**
 * Webhook events and the audited actions that produce them. Events come from the central audit trail, so every
 * channel (screens, API, Treasury, FEL…) triggers the same event without each module knowing about webhooks.
 */
class WebhookEvents
{
    /** @var array<string, array{label: string, group: string, table: string}> */
    public const EVENTS = [
        'customer.created' => ['label' => 'Cliente creado', 'group' => 'Ventas', 'table' => 'sales_customers'],
        'customer.updated' => ['label' => 'Cliente actualizado', 'group' => 'Ventas', 'table' => 'sales_customers'],
        'sales_invoice.created' => ['label' => 'Venta registrada (borrador)', 'group' => 'Ventas', 'table' => 'sales_invoices'],
        'sales_invoice.issued' => ['label' => 'Venta emitida', 'group' => 'Ventas', 'table' => 'sales_invoices'],
        'sales_invoice.voided' => ['label' => 'Venta anulada', 'group' => 'Ventas', 'table' => 'sales_invoices'],
        'payment.received' => ['label' => 'Cobro aplicado a una venta', 'group' => 'Ventas', 'table' => 'sales_receipts'],
        'credit_note.issued' => ['label' => 'Nota de crédito emitida', 'group' => 'Ventas', 'table' => 'sales_credit_notes'],
        'supplier.created' => ['label' => 'Proveedor creado', 'group' => 'Compras', 'table' => 'purchase_suppliers'],
        'supplier.updated' => ['label' => 'Proveedor actualizado', 'group' => 'Compras', 'table' => 'purchase_suppliers'],
        'purchase_document.created' => ['label' => 'Compra registrada (borrador)', 'group' => 'Compras', 'table' => 'purchase_documents'],
        'purchase_document.approved' => ['label' => 'Compra aprobada', 'group' => 'Compras', 'table' => 'purchase_documents'],
        'purchase_document.voided' => ['label' => 'Compra anulada', 'group' => 'Compras', 'table' => 'purchase_documents'],
        'payment.sent' => ['label' => 'Pago a proveedor registrado', 'group' => 'Compras', 'table' => 'purchase_payments'],
        'fel_document.certified' => ['label' => 'Documento FEL certificado', 'group' => 'FEL', 'table' => 'fel_documents'],
        'fel_document.cancelled' => ['label' => 'Documento FEL anulado', 'group' => 'FEL', 'table' => 'fel_documents'],
        'accounting_period.closed' => ['label' => 'Período contable cerrado', 'group' => 'Contabilidad', 'table' => 'accounting_periods'],
        'accounting_period.reopened' => ['label' => 'Período contable reabierto', 'group' => 'Contabilidad', 'table' => 'accounting_periods'],
        'report.published' => ['label' => 'Reporte publicado al cliente', 'group' => 'Portal del cliente', 'table' => 'published_reports'],
        'client_document.received' => ['label' => 'Documento recibido del cliente', 'group' => 'Portal del cliente', 'table' => 'client_documents'],
    ];

    /** Audited action => event. */
    public const ACTIONS = [
        'sales.customer.created' => 'customer.created',
        'sales.customer.updated' => 'customer.updated',
        'sales.invoice.drafted' => 'sales_invoice.created',
        'sales.invoice.issue' => 'sales_invoice.issued',
        'sales.invoice.void' => 'sales_invoice.voided',
        'sales.receipt.created' => 'payment.received',
        'sales.credit_note.issued' => 'credit_note.issued',
        'purchase.supplier.created' => 'supplier.created',
        'purchase.supplier.updated' => 'supplier.updated',
        'purchase.document.drafted' => 'purchase_document.created',
        'purchase.document.approve' => 'purchase_document.approved',
        'purchase.document.void' => 'purchase_document.voided',
        'purchase.payment.created' => 'payment.sent',
        'fel.certified' => 'fel_document.certified',
        'fel.cancelled' => 'fel_document.cancelled',
        'accounting_period.closed' => 'accounting_period.closed',
        'accounting_period.reopened' => 'accounting_period.reopened',
        'client_portal.report.publish' => 'report.published',
        'client.document.uploaded' => 'client_document.received',
    ];

    public const PING = 'webhook.ping';
}
