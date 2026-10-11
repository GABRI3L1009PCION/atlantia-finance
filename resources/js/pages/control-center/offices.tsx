import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    Table,
    ccInput,
    date,
    statusTone,
} from '@/components/control-center/kit';

type Office = {
    id: number;
    code: string;
    name: string;
    city: string | null;
    status: string;
    primary: boolean;
    organization_id: number;
    organization: string;
    users: number;
    companies: number;
    created_at: string;
};

export default function Offices({
    offices,
    filters,
}: {
    offices: Office[];
    filters: { search?: string };
}) {
    const [search, setSearch] = useState(filters.search ?? '');

    return (
        <>
            <Head title="Oficinas · Control Center" />
            <CCPage
                title="Oficinas"
                subtitle="Estructura de cada organización cliente para soporte, métricas y consumo. La asignación de personal la hace el administrador de cada oficina, no el Control Center."
            >
                <Panel>
                    <form
                        className="mb-3 flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/offices',
                                { search },
                                { preserveState: true },
                            );
                        }}
                    >
                        <input
                            className={`${ccInput} max-w-xs`}
                            placeholder="Oficina u organización"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </form>
                    <Table
                        headers={[
                            'Organización',
                            'Oficina',
                            'Ciudad',
                            'Estado',
                            'Usuarios',
                            'Empresas cliente',
                            'Creada',
                        ]}
                        empty={
                            offices.length === 0 &&
                            'No existen oficinas registradas.'
                        }
                    >
                        {offices.map((office) => (
                            <tr key={office.id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={`/control-center/organizations/${office.organization_id}?tab=offices`}
                                        className="font-semibold text-white"
                                    >
                                        {office.organization}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">
                                    {office.name}
                                    {office.primary && (
                                        <span className="ml-2 text-[10px] text-[#e8b04b]">
                                            Principal
                                        </span>
                                    )}
                                    <span className="block font-mono text-[10px] text-slate-500">
                                        {office.code}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {office.city ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill tone={statusTone[office.status]}>
                                        {office.status === 'active'
                                            ? 'Activa'
                                            : office.status}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2">{office.users}</td>
                                <td className="px-2 py-2">
                                    {office.companies}
                                </td>
                                <td className="px-2 py-2">
                                    {date(office.created_at)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
