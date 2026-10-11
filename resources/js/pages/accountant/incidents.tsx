import type { ComponentProps } from 'react';
import SupervisorIncidents from '@/pages/supervisor/incidents';

/** Same incident screen as supervision, posting to the accountant routes (scoped to the accountant's companies). */
export default function AccountantIncidents(
    props: ComponentProps<typeof SupervisorIncidents>,
) {
    return <SupervisorIncidents {...props} basePath="/accountant" />;
}
