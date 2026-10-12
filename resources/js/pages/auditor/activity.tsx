import type { ComponentProps } from 'react';
import AssistantActivity from '@/pages/assistant/activity';

/** What the auditor did (its own actions are audited too). */
export default function AuditorActivity(
    props: ComponentProps<typeof AssistantActivity>,
) {
    return <AssistantActivity {...props} />;
}
