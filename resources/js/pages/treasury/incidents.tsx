import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import AssistantIncidents from '@/pages/assistant/incidents';

/** Bank, payment, receipt and cash incidents of the companies; only the responsible can act on each one. */
export default function TreasuryIncidents(
    props: ComponentProps<typeof AssistantIncidents>,
) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;

    return (
        <AssistantIncidents {...props} basePath="/treasury" userId={userId} />
    );
}
