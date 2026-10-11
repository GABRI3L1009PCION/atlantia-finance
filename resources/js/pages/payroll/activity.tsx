import type { ComponentProps } from 'react';
import AssistantActivity from '@/pages/assistant/activity';

/** What the payroll specialist did, read from the audit log. */
export default function PayrollActivity(
    props: ComponentProps<typeof AssistantActivity>,
) {
    return <AssistantActivity {...props} />;
}
