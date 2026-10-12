import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import AssistantIncidents from '@/pages/assistant/incidents';

/** Receivables incidents of the companies (broken promises, overdue without follow-up, unapplied receipts, disputes, ledger differences). */
export default function ReceivablesIncidents(
    props: ComponentProps<typeof AssistantIncidents>,
) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;

    return (
        <AssistantIncidents
            {...props}
            basePath="/receivables"
            userId={userId}
        />
    );
}
