import { usePage } from '@inertiajs/react';
import { AlertTriangle, X } from 'lucide-react';
import { useState } from 'react';

type PlatformIncident = {
    code: string;
    title: string;
    severity: string;
    status: string;
    message: string;
    at: string;
};

/**
 * Incidents that Atlantia published to the current organization. Dismissing hides the update only for this visit.
 */
export function PlatformIncidentBanner() {
    const incidents =
        (
            usePage().props.platformNotices as
                { incidents?: PlatformIncident[] } | undefined
        )?.incidents ?? [];
    const [dismissed, setDismissed] = useState<string[]>([]);
    const visible = incidents.filter(
        (incident) => !dismissed.includes(`${incident.code}-${incident.at}`),
    );

    if (visible.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2 px-2 pt-2 sm:px-4">
            {visible.map((incident) => (
                <div
                    key={`${incident.code}-${incident.at}`}
                    role="status"
                    className={`flex items-start gap-3 rounded-xl border p-3 text-sm ${['high', 'critical'].includes(incident.severity) ? 'border-red-200 bg-red-50 text-red-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}
                >
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold">
                            {incident.title} · {incident.status}
                        </p>
                        <p className="whitespace-pre-wrap">
                            {incident.message}
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label="Ocultar aviso"
                        className="shrink-0 opacity-60 hover:opacity-100"
                        onClick={() =>
                            setDismissed([
                                ...dismissed,
                                `${incident.code}-${incident.at}`,
                            ])
                        }
                    >
                        <X className="size-4" />
                    </button>
                </div>
            ))}
        </div>
    );
}
