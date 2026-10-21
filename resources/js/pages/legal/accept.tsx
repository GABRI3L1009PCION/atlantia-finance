import { Head, useForm } from '@inertiajs/react';

type Pending = {
    slug: string;
    label: string;
    version: number;
    url: string;
};

export default function LegalAccept({ documents }: { documents: Pending[] }) {
    const form = useForm({ accepted: false });

    return (
        <>
            <Head title="Aceptar documentos legales" />
            <div className="flex min-h-screen items-center justify-center bg-[#faf7f2] px-4">
                <form
                    className="w-full max-w-lg space-y-5 rounded-2xl border border-[#640000]/10 bg-white p-8 shadow-sm"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/legal/aceptar');
                    }}
                >
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">
                            Documentos legales actualizados
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            Para seguir usando Atlantia Finance como propietario
                            de tu firma, lee y acepta la versión vigente de
                            estos documentos.
                        </p>
                    </div>
                    <ul className="space-y-2 text-sm">
                        {documents.map((document) => (
                            <li key={document.slug}>
                                <a
                                    href={document.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-semibold text-[#640000] underline"
                                >
                                    {document.label}
                                </a>{' '}
                                <span className="text-slate-500">
                                    (versión {document.version})
                                </span>
                            </li>
                        ))}
                    </ul>
                    <label className="flex items-start gap-3 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.data.accepted}
                            onChange={(event) =>
                                form.setData('accepted', event.target.checked)
                            }
                        />
                        He leído y acepto los documentos indicados.
                    </label>
                    {form.errors.accepted && (
                        <p className="text-sm text-red-700">
                            {form.errors.accepted}
                        </p>
                    )}
                    <button
                        className="w-full rounded-xl bg-[#640000] px-5 py-3 font-semibold text-white disabled:opacity-50"
                        disabled={form.processing}
                    >
                        Aceptar y continuar
                    </button>
                </form>
            </div>
        </>
    );
}
