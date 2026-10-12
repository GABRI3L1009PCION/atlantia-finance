import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { EntriesTable } from '@/components/accountant/entries';
import type {
    AccountantContext,
    AccountantPermissions,
    Entry,
} from '@/components/accountant/kit';
import { Notice, PageHeader, Shell } from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function AccountantEntries({
    context,
    entries,
    permissions,
    notice,
}: {
    context: AccountantContext;
    entries: Entry[];
    permissions: AccountantPermissions;
    notice: string | null;
}) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;
    const [company, setCompany] = useState('');
    const visible = entries.filter(
        (entry) => company === '' || entry.company_id === Number(company),
    );

    return (
        <>
            <Head title="Pólizas pendientes" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Pólizas pendientes"
                    subtitle={`Borradores, en revisión y aprobadas sin contabilizar · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card
                    title={`${visible.length} póliza(s)`}
                    action={
                        <select
                            aria-label="Empresa"
                            className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                            value={company}
                            onChange={(event) => setCompany(event.target.value)}
                        >
                            <option value="">Todas las empresas</option>
                            {context.companies.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    }
                >
                    <EntriesTable
                        entries={visible}
                        permissions={permissions}
                        userId={userId}
                    />
                </Card>
            </Shell>
        </>
    );
}
