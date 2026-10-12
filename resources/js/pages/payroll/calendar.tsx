import type { ComponentProps } from 'react';
import AssistantCalendar from '@/pages/assistant/calendar';

/** Payroll steps of each open period (from the calendar parameters), payment dates and payroll tasks. */
export default function PayrollCalendar(
    props: ComponentProps<typeof AssistantCalendar>,
) {
    return <AssistantCalendar {...props} basePath="/payroll" />;
}
