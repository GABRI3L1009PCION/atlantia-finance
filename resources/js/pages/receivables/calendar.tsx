import type { ComponentProps } from 'react';
import AssistantCalendar from '@/pages/assistant/calendar';

/** Due dates, promised dates, follow-ups and collection tasks. */
export default function ReceivablesCalendar(
    props: ComponentProps<typeof AssistantCalendar>,
) {
    return <AssistantCalendar {...props} basePath="/receivables" />;
}
