/** Spanish labels of the statuses that the backend sends in English. One place, used by every screen. */
export const entryStatusLabels: Record<string, string> = {
    draft: 'Borrador',
    pending_review: 'En revisión',
    reviewed: 'Revisado',
    approved: 'Aprobado',
    posted: 'Contabilizado',
    reversed: 'Revertido',
    rejected: 'Rechazado',
    void: 'Anulado',
    cancelled: 'Anulado',
    canceled: 'Anulado',
};

export const statusLabels: Record<string, string> = {
    ...entryStatusLabels,
    active: 'Activo',
    archived: 'Archivado',
    blocked: 'Bloqueado',
    calculated: 'Calculado',
    closed: 'Cerrado',
    completed: 'Completado',
    confirmed: 'Confirmado',
    counting: 'En conteo',
    expired: 'Vencido',
    failed: 'Fallido',
    generated: 'Generado',
    in_progress: 'En proceso',
    in_review: 'En revisión',
    inactive: 'Inactivo',
    issued: 'Emitido',
    maintenance: 'En mantenimiento',
    needs_review: 'Requiere revisión',
    open: 'Abierto',
    overdue: 'Vencido',
    paid: 'Pagado',
    partially_received: 'Recepción parcial',
    partially_paid: 'Pago parcial',
    paused: 'En pausa',
    pending: 'Pendiente',
    processing: 'Procesando',
    published: 'Publicado',
    ready: 'Listo',
    received: 'Recibido',
    reconciled: 'Conciliado',
    reopened: 'Reabierto',
    review: 'Revisión',
    sent: 'Enviado',
    snoozed: 'Pospuesto',
    submitted: 'Enviado',
    superseded: 'Sustituido',
    testing: 'En prueba',
    transferred: 'Trasladado',
    validation: 'Validación',
    adjustment: 'Ajustes',
};

/** Label of a status; an unknown one is shown readable (spaces instead of underscores) rather than raw. */
export function statusLabel(status: string | null | undefined): string {
    if (!status) {
        return '';
    }

    return statusLabels[status] ?? status.replaceAll('_', ' ');
}
