export type OperationalRecord = {
    public_id: string;
    module: string;
    record_type: string;
    reference: string | null;
    title: string;
    status: string;
    effective_on: string;
    currency: string;
    amount_cents: number;
    data: Record<string, unknown> | null;
    version: number;
};

export type OperationalEvent = {
    id: number;
    action: string;
    from_status: string | null;
    to_status: string | null;
    reason: string | null;
    occurred_at: string;
    user?: { name: string; email: string };
};

export type OperationalFile = {
    public_id: string;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    created_at: string;
    uploader?: { name: string; email: string };
};

export type OperationalWorkflow = {
    initial: string;
    transitions: Record<string, string[]>;
    privileged: string[];
    reasons: string[];
};

type RecordResponse = { record: OperationalRecord };
type IndexResponse = {
    records: { data: OperationalRecord[] };
    workflow: OperationalWorkflow;
};
type ShowResponse = {
    record: OperationalRecord & {
        events: OperationalEvent[];
        files: OperationalFile[];
    };
    workflow: OperationalWorkflow;
};

function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.split('=').slice(1).join('='))
        : '';
}

export function operationalFileUrl(
    company: string,
    module: string,
    record: string,
    file: string,
): string {
    return `${baseUrl(company, module)}/${record}/files/${file}`;
}

export async function uploadOperationalFile(
    company: string,
    module: string,
    record: string,
    file: File,
): Promise<OperationalFile> {
    const form = new FormData();
    form.append('file', file);
    const response = await fetch(
        `${baseUrl(company, module)}/${record}/files`,
        {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
        },
    );
    const body = (await response.json()) as {
        file?: OperationalFile;
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (!response.ok || !body.file) {
        const detail = body.errors
            ? Object.values(body.errors).flat()[0]
            : body.message;

        throw new Error(detail ?? 'No fue posible adjuntar el archivo.');
    }

    return body.file;
}

export async function deleteOperationalFile(
    company: string,
    module: string,
    record: string,
    file: string,
): Promise<void> {
    await request(operationalFileUrl(company, module, record, file), {
        method: 'DELETE',
    });
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        ...init,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
            ...init.headers,
        },
    });
    const body = (await response.json()) as T & {
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (!response.ok) {
        const detail = body.errors
            ? Object.values(body.errors).flat()[0]
            : body.message;

        throw new Error(detail ?? 'No fue posible completar la operación.');
    }

    return body;
}

function baseUrl(company: string, module: string): string {
    return `/accounting/companies/${encodeURIComponent(company)}/operations/${encodeURIComponent(module)}`;
}

export async function listOperationalRecords(
    company: string,
    module: string,
): Promise<OperationalRecord[]> {
    const response = await request<IndexResponse>(
        `${baseUrl(company, module)}?per_page=100`,
    );

    return response.records.data;
}

export async function getOperationalModule(
    company: string,
    module: string,
): Promise<{ records: OperationalRecord[]; workflow: OperationalWorkflow }> {
    const response = await request<IndexResponse>(
        `${baseUrl(company, module)}?per_page=100`,
    );

    return { records: response.records.data, workflow: response.workflow };
}

export async function getOperationalRecord(
    company: string,
    module: string,
    record: string,
): Promise<ShowResponse> {
    return request<ShowResponse>(`${baseUrl(company, module)}/${record}`);
}

export async function createOperationalRecord(
    company: string,
    module: string,
    payload: Record<string, unknown>,
): Promise<OperationalRecord> {
    const response = await request<RecordResponse>(baseUrl(company, module), {
        method: 'POST',
        body: JSON.stringify(payload),
    });

    return response.record;
}

export async function updateOperationalRecord(
    company: string,
    module: string,
    record: string,
    payload: Record<string, unknown>,
): Promise<OperationalRecord> {
    const response = await request<RecordResponse>(
        `${baseUrl(company, module)}/${record}`,
        {
            method: 'PATCH',
            body: JSON.stringify(payload),
        },
    );

    return response.record;
}

export async function transitionOperationalRecord(
    company: string,
    module: string,
    record: string,
    status: string,
    reason?: string,
): Promise<OperationalRecord> {
    const response = await request<RecordResponse>(
        `${baseUrl(company, module)}/${record}/transition`,
        {
            method: 'POST',
            body: JSON.stringify({
                request_id: crypto.randomUUID(),
                status,
                reason,
            }),
        },
    );

    return response.record;
}
