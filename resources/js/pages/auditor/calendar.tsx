import type { ComponentProps } from 'react';
import AssistantCalendar from '@/pages/assistant/calendar';

/** Procedures, evidence, findings, verifications and engagement milestones of the auditor. */
export default function AuditorCalendar(
    props: ComponentProps<typeof AssistantCalendar>,
) {
    return <AssistantCalendar {...props} basePath="/work/audit" />;
}
