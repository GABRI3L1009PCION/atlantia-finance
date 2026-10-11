import type { ComponentProps } from 'react';
import AssistantCalendar from '@/pages/assistant/calendar';

/** Statutory, internal and review deadlines of the obligations plus fiscal tasks. */
export default function TaxCalendar(
    props: ComponentProps<typeof AssistantCalendar>,
) {
    return <AssistantCalendar {...props} basePath="/tax" />;
}
