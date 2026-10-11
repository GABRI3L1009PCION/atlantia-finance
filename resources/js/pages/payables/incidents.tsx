import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import AssistantIncidents from '@/pages/assistant/incidents';

/** Payables incidents of the companies (overdue without follow-up, duplicated invoices, ledger differences, disputes). */
export default function PayablesIncidents(
    props: ComponentProps<typeof AssistantIncidents>,
) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;

    return (
        <AssistantIncidents {...props} basePath="/payables" userId={userId} />
    );
}
