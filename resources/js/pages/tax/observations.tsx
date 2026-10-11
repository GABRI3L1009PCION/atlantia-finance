import type { ComponentProps } from 'react';
import AssistantObservations from '@/pages/assistant/observations';

/** Same correction flow as the assistant: the specialist corrects at the source and the reviewer validates. */
export default function TaxObservations(
    props: ComponentProps<typeof AssistantObservations>,
) {
    return <AssistantObservations {...props} basePath="/tax" />;
}
