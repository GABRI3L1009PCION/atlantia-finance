import { Head, router, useForm } from '@inertiajs/react';
import { Clock3, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';

type Props = {
    entries: {
        id: number;
        date: string;
        minutes: number;
        activity: string | null;
        note: string | null;
        company: string;
    }[];
    total_minutes: number;
    month: string;
    companies: { id: number; public_id: string; name: string }[];
    activities: Record<string, string>;
    today: string;
    windowDays: number;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const field =
    'h-10 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const hours = (minutes: number) =>
    `${(minutes / 60).toLocaleString('es-GT', { maximumFractionDigits: 2 })} h`;

export default function Time({
    entries,
    total_minutes,
    month,
    companies,
    activities,
    today,
    windowDays,
}: Props) {
    const form = useForm({
        company_id: String(companies[0]?.id ?? ''),
        date: today,
        hours: '',
        activity: 'registro',
        note: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/work/time', {
            preserveScroll: true,
            onSuccess: () => form.reset('hours', 'note'),
        });
    };

    return (
        <>
            <Head title="Mis horas" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#061633] sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    <header className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="text-xs font-black tracking-[0.2em] text-[#9b5757] uppercase">
                                Mi trabajo
                            </p>
                            <h1 className="mt-1 text-3xl font-black tracking-tight">
                                Mis horas
                            </h1>
                            <p className="mt-1 text-sm text-slate-600">
                                Registra el tiempo que dedicas a cada cliente.
                                Con eso la dirección ve la rentabilidad de cada
                                empresa. Puedes registrar los últimos{' '}
                                {windowDays} días.
                            </p>
                        </div>
                        <input
                            type="month"
                            value={month}
                            onChange={(event) =>
                                router.get(
                                    '/work/time',
                                    { month: event.target.value },
                                    { preserveScroll: true },
                                )
                            }
                            className={`${field} !w-auto`}
                            aria-label="Mes"
                        />
                    </header>

                    <section className={card}>
                        <h2 className="mb-3 flex items-center gap-2 text-lg font-black">
                            <Clock3 className="size-5 text-[#8a0b0b]" />{' '}
                            Registrar horas
                        </h2>
                        {companies.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                Todavía no tienes empresas asignadas para
                                registrar horas.
                            </p>
                        ) : (
                            <form
                                onSubmit={submit}
                                className="grid gap-3 md:grid-cols-6"
                            >
                                <label className="text-xs font-bold text-slate-600 md:col-span-2">
                                    Cliente
                                    <select
                                        className={`${field} mt-1`}
                                        value={form.data.company_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'company_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {companies.map((company) => (
                                            <option
                                                key={company.id}
                                                value={company.id}
                                            >
                                                {company.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-xs font-bold text-slate-600">
                                    Fecha
                                    <input
                                        type="date"
                                        max={today}
                                        className={`${field} mt-1`}
                                        value={form.data.date}
                                        onChange={(event) =>
                                            form.setData(
                                                'date',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="text-xs font-bold text-slate-600">
                                    Horas
                                    <input
                                        inputMode="decimal"
                                        placeholder="1.5"
                                        className={`${field} mt-1`}
                                        value={form.data.hours}
                                        onChange={(event) =>
                                            form.setData(
                                                'hours',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="text-xs font-bold text-slate-600 md:col-span-2">
                                    Actividad
                                    <select
                                        className={`${field} mt-1`}
                                        value={form.data.activity}
                                        onChange={(event) =>
                                            form.setData(
                                                'activity',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {Object.entries(activities).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                <label className="text-xs font-bold text-slate-600 md:col-span-4">
                                    Nota (opcional)
                                    <input
                                        className={`${field} mt-1`}
                                        maxLength={255}
                                        value={form.data.note}
                                        onChange={(event) =>
                                            form.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <div className="flex items-end md:col-span-2">
                                    <button
                                        className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-[#8a0b0b] px-4 text-sm font-bold text-white disabled:opacity-60"
                                        disabled={form.processing}
                                    >
                                        <Plus className="size-4" /> Registrar
                                    </button>
                                </div>
                                {Object.values(form.errors).map((error) => (
                                    <p
                                        key={error}
                                        className="text-xs text-red-600 md:col-span-6"
                                    >
                                        {error}
                                    </p>
                                ))}
                            </form>
                        )}
                    </section>

                    <section className={`${card} overflow-x-auto`}>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-lg font-black">
                                Registros del mes
                            </h2>
                            <b>{hours(total_minutes)}</b>
                        </div>
                        {entries.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                                No has registrado horas este mes.
                            </p>
                        ) : (
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-xs font-bold tracking-wide text-slate-500 uppercase">
                                        {[
                                            'Fecha',
                                            'Cliente',
                                            'Actividad',
                                            'Horas',
                                            'Nota',
                                            '',
                                        ].map((title) => (
                                            <th
                                                key={title}
                                                className="px-3 py-2"
                                            >
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b border-slate-100"
                                        >
                                            <td className="px-3 py-2">
                                                {entry.date}
                                            </td>
                                            <td className="px-3 py-2 font-semibold">
                                                {entry.company}
                                            </td>
                                            <td className="px-3 py-2">
                                                {entry.activity ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {hours(entry.minutes)}
                                            </td>
                                            <td className="px-3 py-2 text-slate-600">
                                                {entry.note ?? ''}
                                            </td>
                                            <td className="px-3 py-2">
                                                <button
                                                    type="button"
                                                    aria-label="Eliminar registro"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/work/time/${entry.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    className="text-red-700"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}
