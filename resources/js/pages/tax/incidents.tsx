import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import AssistantIncidents from '@/pages/assistant/incidents';

/** Fiscal, FEL and document incidents of the companies; only the responsible can act on each one. */
export default function TaxIncidents(
    props: ComponentProps<typeof AssistantIncidents>,
) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;

    return <AssistantIncidents {...props} basePath="/tax" userId={userId} />;
}
