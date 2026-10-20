import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import {
    PageHeader,
    Shell,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

type Step = {
    key: string;
    title: string;
    detail: string;
    done: boolean;
    current: number;
    total: number;
    href: string;
};

export default function OfficeSetup({
    setup,
}: {
    setup: { percent: number; done: number; total: number; steps: Step[] };
}) {
    const next = setup.steps.find((step) => !step.done);

    return (
        <>
            <Head title="Puesta en marcha" />
            <Shell>
                <PageHeader
                    title="Puesta en marcha"
                    subtitle="Los pasos para dejar tu firma trabajando, tomados de lo que ya existe en el sistema"
                    actions={
                        next && (
                            <Link href={next.href} className={primaryButton}>
                                Siguiente paso
                            </Link>
                        )
                    }
                />
                <Card title={`${setup.done} de ${setup.total} pasos completos`}>
                    <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                        <span
                            className="block h-full bg-emerald-500"
                            style={{ width: `${setup.percent}%` }}
                        />
                    </div>
                    <ul className="mt-4 divide-y divide-slate-100">
                        {setup.steps.map((step) => (
                            <li
                                key={step.key}
                                className="flex items-start gap-3 py-3"
                            >
                                {step.done ? (
                                    <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                ) : (
                                    <Circle className="mt-0.5 size-5 shrink-0 text-slate-300" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-bold">
                                        {step.title}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {step.detail}
                                    </p>
                                    <p className="mt-1 text-xs font-semibold text-slate-600">
                                        {step.current} de {step.total}
                                    </p>
                                </div>
                                <Link
                                    href={step.href}
                                    className={secondaryButton}
                                >
                                    {step.done ? 'Revisar' : 'Hacerlo'}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            </Shell>
        </>
    );
}
