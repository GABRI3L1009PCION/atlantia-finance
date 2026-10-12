import type { ComponentProps } from 'react';
import AssistantActivity from '@/pages/assistant/activity';

/** What the receivables specialist did, read from the audit log. */
export default function ReceivablesActivity(
    props: ComponentProps<typeof AssistantActivity>,
) {
    return <AssistantActivity {...props} />;
}
