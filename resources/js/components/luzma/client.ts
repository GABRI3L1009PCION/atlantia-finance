import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

export type LuzmaReference = {
    label: string;
    href: string;
    download?: boolean;
};

export type LuzmaTable = {
    title: string;
    columns: string[];
    rows: string[][];
    /** Column drawn as bars next to the table (amounts like "Q 1,234.00"). */
    chart?: { column: number };
};

export type LuzmaAction = {
    id: string;
    type: string;
    label: string;
    level: string;
    status: string;
    confirm_label: string;
    preview: {
        summary: string;
        title?: string;
        when?: string;
        company?: string | null;
        total?: string;
        proposed_date?: string;
        lines?: string[];
        assignee?: string;
        due?: string;
    };
    result?: { message: string; href: string | null };
    error?: string;
};

export type LuzmaMessage = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    status: 'ok' | 'fallback' | 'error' | 'pending';
    references: LuzmaReference[];
    actions: LuzmaAction[];
    tables?: LuzmaTable[];
    suggestions?: { type: string; label: string }[];
    tools: string[];
    tool_status?: string | null;
    feedback: string | null;
    created_at: string;
};

export type LuzmaInsight = {
    id: string;
    module: string;
    severity: string;
    label: string;
    title: string;
    body: string;
    company: string | null;
    status: string;
    destination: string | null;
    detected_at: string;
};

export type LuzmaReminder = {
    id: string;
    kind: string;
    title: string;
    message: string | null;
    company: string | null;
    remind_at: string;
    status: string;
    status_label: string;
    priority: string;
    condition: string | null;
    resolution: string | null;
    destination: string | null;
    initiated_via: string;
};

export type LuzmaScreen = {
    module: string;
    company?: string;
    company_id?: number;
    entity_type?: string;
    entity_id?: number;
    route: string;
};

export class LuzmaError extends Error {}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Readable message of a failed Luzma response: the validation error, the server message or a generic one. */
async function failure(response: Response): Promise<LuzmaError> {
    let message =
        response.status === 403
            ? 'No tienes permiso para esta consulta.'
            : response.status === 429
              ? 'Demasiadas consultas seguidas. Espera un momento.'
              : 'Luzma no está disponible temporalmente.';

    try {
        const data = await response.json();
        const errors = data?.errors
            ? Object.values(data.errors as Record<string, string[]>).flat()
            : [];
        message = errors[0] ?? data?.message ?? message;
    } catch {
        // Keep the generic message.
    }

    return new LuzmaError(message);
}

/** JSON calls to the Luzma API with the session's CSRF token; server errors become readable messages. */
export async function luzmaRequest<T>(
    url: string,
    method: 'GET' | 'POST' | 'PATCH' = 'GET',
    body?: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        throw await failure(response);
    }

    return (await response.json()) as T;
}

/** Sends a file to Luzma (multipart) with the screen context and the question. */
export async function luzmaUpload<T>(
    url: string,
    file: File,
    fields: Record<string, string | number | null | undefined>,
): Promise<T> {
    const body = new FormData();
    body.append('file', file);
    Object.entries(fields).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            body.append(key, String(value));
        }
    });
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body,
    });

    if (!response.ok) {
        throw await failure(response);
    }

    return (await response.json()) as T;
}

const ENTITY_ROUTES: [RegExp, string][] = [
    [/^\/payables\/documents\/(\d+)/, 'purchase_document'],
    [/^\/payables\/proposals\/(\d+)/, 'payment_proposal'],
    [/^\/receivables\/documents\/(\d+)/, 'sales_invoice'],
    [/^\/tax\/obligations\/(\d+)/, 'fiscal_obligation'],
    [/^\/treasury\/payments\/(\d+)/, 'payment_instruction'],
    [/^\/treasury\/reconciliations\/(\d+)/, 'bank_reconciliation'],
];

function moduleFor(path: string, hash: string): string {
    const [, first = '', second = '', , fourth = ''] = path.split('/');

    if (path === '/office/executive-dashboard') {
        return 'executive';
    }

    if (first === 'client') {
        return 'client';
    }

    if (first === 'work' && second === 'audit') {
        return 'audit_desk';
    }

    if (first === 'accounting' && second === 'companies') {
        if (hash === '#libro-mayor') {
            return 'ledger';
        }

        if (hash === '#libro-diario') {
            return 'journal';
        }

        return (
            {
                purchases: 'purchases',
                sales: 'sales',
                banks: 'banks',
                fel: 'fel',
                'vat-books': 'tax',
                dashboard: 'closing',
            }[fourth] ?? 'accounting'
        );
    }

    return (
        {
            payables: 'payables',
            payroll: 'payroll',
            receivables: 'receivables',
            treasury: 'treasury',
            tax: 'tax',
            accountant: 'accountant',
            assistant: 'assistant',
            supervisor: 'supervisor',
            operations: 'operations',
            office: 'office',
            audit: 'audit',
            work: 'dashboard',
            library: 'library',
        }[first] ?? 'global'
    );
}

/** What the user is looking at. It is only a hint: the server re-checks company, period and entity access. */
export function useLuzmaScreen(): LuzmaScreen {
    const page = usePage();

    return useMemo(() => {
        const url = new URL(page.url, 'https://atlantia.local');
        const path = url.pathname;
        const props = page.props as Record<string, unknown>;
        const company = props.company as { public_id?: string } | undefined;
        const companyId =
            Number(url.searchParams.get('company_id')) ||
            (typeof props.companyId === 'number' ? props.companyId : 0);
        const screen: LuzmaScreen = {
            module: path === '/luzma' ? 'global' : moduleFor(path, url.hash),
            route: path,
        };

        if (company?.public_id) {
            screen.company = company.public_id;
        } else if (companyId > 0) {
            screen.company_id = companyId;
        }

        for (const [pattern, type] of ENTITY_ROUTES) {
            const match = path.match(pattern);

            if (match) {
                screen.entity_type = type;
                screen.entity_id = Number(match[1]);
            }
        }

        return screen;
    }, [page.url, page.props]);
}

/** Opens the Luzma drawer from any screen, optionally with a question ready to send. */
export function askLuzma(question?: string): void {
    window.dispatchEvent(
        new CustomEvent('luzma:open', { detail: { question } }),
    );
}

export const severityStyle: Record<string, string> = {
    critical: 'border-red-300 bg-red-50 text-red-800',
    high_priority: 'border-orange-300 bg-orange-50 text-orange-800',
    warning: 'border-amber-300 bg-amber-50 text-amber-800',
    recommendation: 'border-blue-300 bg-blue-50 text-blue-800',
    info: 'border-slate-200 bg-slate-50 text-slate-700',
};
