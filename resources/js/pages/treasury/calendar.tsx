import type { ComponentProps } from 'react';
import AssistantCalendar from '@/pages/assistant/calendar';

/** Scheduled payments, payables and receivables due, plus treasury tasks. */
export default function TreasuryCalendar(
    props: ComponentProps<typeof AssistantCalendar>,
) {
    return <AssistantCalendar {...props} basePath="/treasury" />;
}
