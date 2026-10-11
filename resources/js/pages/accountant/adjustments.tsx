import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { EntriesTable } from '@/components/accountant/entries';
import type {
    AccountantContext,
    AccountantPermissions,
    Entry,
} from '@/components/accountant/kit';
import { Notice, PageHeader, Shell } from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function AccountantAdjustments({
    context,
    entries,
    userId,
    permissions,
    notice,
}: {
    context: AccountantContext;
    entries: Entry[];
    userId: number;
    permissions: AccountantPermissions;
    notice: string | null;
}) {
    return (
        <>
            <Head title="Ajustes contables" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Ajustes contables"
                    subtitle={`Ajustes, reclasificaciones, provisiones y pólizas de cierre · ${context.period?.name ?? 'Sin período'}`}
                />
                <p className="flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                    <ShieldCheck className="size-4" /> Segregación de funciones:
                    quien crea un ajuste no puede aprobarlo. Crea los ajustes
                    desde el libro diario de la empresa.
                </p>
                <Card title={`${entries.length} ajuste(s)`}>
                    <EntriesTable
                        entries={entries}
                        permissions={permissions}
                        userId={userId}
                    />
                </Card>
            </Shell>
        </>
    );
}
