import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Users } from 'lucide-react';

/**
 * Payroll of a client company. Employees, periods, calculation, approvals, receipts and payment orders live in the
 * Payroll panel (with its segregation of duties); this module only points there instead of showing sample figures.
 */
export default function PayrollWorkspace({
    company,
}: {
    company: { name: string };
}) {
    return (
        <main className="min-h-full bg-[#f3f7fb] p-3 text-[#12213d] sm:p-5">
            <Head title={`Nómina · ${company.name}`} />
            <section className="mx-auto grid max-w-3xl gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center gap-3">
                    <span className="grid size-11 place-items-center rounded-full bg-red-50 text-[#640000]">
                        <Users className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-2xl font-black">Nómina</h1>
                        <p className="text-sm text-slate-500">{company.name}</p>
                    </div>
                </div>
                <p className="text-sm text-slate-600">
                    La nómina de la empresa se prepara, calcula y aprueba en el
                    Panel de Nómina: empleados, novedades, pre-nómina, cálculo
                    con parámetros legales vigentes, revisión por otra persona,
                    recibos, asiento contable en borrador y órdenes de pago para
                    Tesorería. El acceso requiere el rol de Nómina o permiso de
                    aprobación en la empresa.
                </p>
                <p className="text-sm text-slate-600">
                    Los asientos de nómina llegan a esta contabilidad como
                    borradores con origen «Nómina» y «Pago de nómina»; el
                    checklist de cierre verifica que la nómina coincida con el
                    Libro Mayor.
                </p>
                <div>
                    <Link
                        href="/payroll/dashboard"
                        className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#640000] px-4 text-sm font-bold text-white hover:bg-[#7a0a0a]"
                    >
                        Abrir Panel de Nómina
                        <ArrowRight className="size-4" />
                    </Link>
                </div>
            </section>
        </main>
    );
}
