import type { ComponentProps } from 'react';
import AssistantCalendar from '@/pages/assistant/calendar';

/** Due dates, proposed payment dates, scheduled treasury payments and payables tasks. */
export default function PayablesCalendar(
    props: ComponentProps<typeof AssistantCalendar>,
) {
    return <AssistantCalendar {...props} basePath="/payables" />;
}
