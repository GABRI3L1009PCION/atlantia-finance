import type { ComponentProps } from 'react';
import AssistantActivity from '@/pages/assistant/activity';

/** What the payables specialist did, read from the audit log. */
export default function PayablesActivity(
    props: ComponentProps<typeof AssistantActivity>,
) {
    return <AssistantActivity {...props} />;
}
