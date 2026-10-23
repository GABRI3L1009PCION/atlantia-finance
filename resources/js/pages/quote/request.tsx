import { Head, Link, useForm } from '@inertiajs/react';
import { CheckCircle2, MessageCircle } from 'lucide-react';
import { useState } from 'react';

const WHATSAPP_URL = `https://wa.me/50255355469?text=${encodeURIComponent('Hola, me interesa el plan Empresarial de Atlantia Finance y quisiera una cotización.')}`;

const fieldClass =
    'mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none focus:border-[#640000] focus:ring-2 focus:ring-[#640000]/15';

export default function QuoteRequest() {
    const [sent, setSent] = useState(false);
    const form = useForm({
        name: '',
        firm: '',
        email: '',
        phone: '',
        offices: '',
        message: '',
        website: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/cotizacion', {
            preserveScroll: true,
            onSuccess: () => {
                setSent(true);
                form.reset();
            },
        });
    };

    return (
        <>
            <Head title="Cotización plan Empresarial" />
            <div className="flex min-h-svh items-center justify-center bg-[#faf7f2] px-4 py-8">
                <div className="w-full max-w-xl rounded-2xl border border-[#640000]/10 bg-white p-6 shadow-sm sm:p-8">
                    {sent ? (
                        <div className="py-6 text-center">
                            <CheckCircle2 className="mx-auto size-12 text-emerald-600" />
                            <h1 className="mt-4 text-2xl font-bold text-slate-900">
                                Recibimos tu solicitud
                            </h1>
                            <p className="mt-2 text-sm text-slate-600">
                                Nuestro equipo te escribirá pronto para armar
                                una propuesta a la medida de tu firma.
                            </p>
                            <Link
                                href="/"
                                className="mt-6 inline-flex rounded-xl bg-[#640000] px-5 py-2.5 text-sm font-semibold text-white"
                            >
                                Volver al inicio
                            </Link>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <p className="text-xs font-bold tracking-widest text-[#640000] uppercase">
                                    Plan Empresarial
                                </p>
                                <h1 className="mt-1 text-2xl font-bold text-slate-900">
                                    Cuéntanos de tu firma
                                </h1>
                                <p className="mt-2 text-sm text-slate-600">
                                    Este plan se arma a la medida. Déjanos tus
                                    datos y te contactamos con una propuesta.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="block text-sm font-medium text-slate-700">
                                    Tu nombre
                                    <input
                                        className={fieldClass}
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="name"
                                        required
                                    />
                                    {form.errors.name && (
                                        <span className="mt-1 block text-xs text-red-700">
                                            {form.errors.name}
                                        </span>
                                    )}
                                </label>
                                <label className="block text-sm font-medium text-slate-700">
                                    Firma o despacho
                                    <input
                                        className={fieldClass}
                                        value={form.data.firm}
                                        onChange={(event) =>
                                            form.setData(
                                                'firm',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="organization"
                                        required
                                    />
                                    {form.errors.firm && (
                                        <span className="mt-1 block text-xs text-red-700">
                                            {form.errors.firm}
                                        </span>
                                    )}
                                </label>
                                <label className="block text-sm font-medium text-slate-700">
                                    Correo
                                    <input
                                        type="email"
                                        className={fieldClass}
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="email"
                                        required
                                    />
                                    {form.errors.email && (
                                        <span className="mt-1 block text-xs text-red-700">
                                            {form.errors.email}
                                        </span>
                                    )}
                                </label>
                                <label className="block text-sm font-medium text-slate-700">
                                    Teléfono o WhatsApp (opcional)
                                    <input
                                        className={fieldClass}
                                        value={form.data.phone}
                                        onChange={(event) =>
                                            form.setData(
                                                'phone',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="tel"
                                    />
                                </label>
                            </div>

                            <label className="block text-sm font-medium text-slate-700">
                                ¿Cuántas oficinas tiene tu firma? (opcional)
                                <input
                                    className={fieldClass}
                                    value={form.data.offices}
                                    onChange={(event) =>
                                        form.setData(
                                            'offices',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>

                            <label className="block text-sm font-medium text-slate-700">
                                ¿Qué necesitas? (opcional)
                                <textarea
                                    className={fieldClass}
                                    rows={4}
                                    value={form.data.message}
                                    onChange={(event) =>
                                        form.setData(
                                            'message',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>

                            <div className="hidden" aria-hidden="true">
                                <input
                                    tabIndex={-1}
                                    autoComplete="off"
                                    value={form.data.website}
                                    onChange={(event) =>
                                        form.setData(
                                            'website',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <button
                                className="w-full rounded-xl bg-[#640000] px-5 py-3 font-semibold text-white disabled:opacity-50"
                                disabled={form.processing}
                            >
                                {form.processing
                                    ? 'Enviando...'
                                    : 'Solicitar cotización'}
                            </button>

                            <p className="flex items-center justify-center gap-2 text-sm text-slate-600">
                                <MessageCircle className="size-4" />
                                ¿Prefieres hablar ya?{' '}
                                <a
                                    href={WHATSAPP_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-semibold text-[#640000] underline"
                                >
                                    Escríbenos por WhatsApp
                                </a>
                            </p>
                        </form>
                    )}
                </div>
            </div>
        </>
    );
}
