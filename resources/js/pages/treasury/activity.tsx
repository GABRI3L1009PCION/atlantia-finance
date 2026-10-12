import type { ComponentProps } from 'react';
import AssistantActivity from '@/pages/assistant/activity';

/** What the treasurer did, read from the audit log. */
export default function TreasuryActivity(
    props: ComponentProps<typeof AssistantActivity>,
) {
    return <AssistantActivity {...props} />;
}
