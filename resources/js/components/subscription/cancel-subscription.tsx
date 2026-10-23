import { router } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    cancelAtPeriodEnd: boolean;
    periodEndsAt: string;
    canManage: boolean;
};

/** The owner stops the renewal (access continues until the end of the paid period) or takes the cancellation back. */
export default function CancelSubscription({
    cancelAtPeriodEnd,
    periodEndsAt,
    canManage,
}: Props) {
    const [confirming, setConfirming] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    if (!canManage) {
        return null;
    }

    const finish = () => {
        setBusy(false);
        setConfirming(false);
    };

    if (cancelAtPeriodEnd) {
        return (
            <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <h2 className="text-lg font-black text-amber-900">
                    Tu suscripción no se renovará
                </h2>
                <p className="mt-1 text-sm text-amber-800">
                    Seguirás usando Atlantia hasta el {periodEndsAt}. Después la
                    cuenta se cierra y tus datos se conservan según los Términos
                    y Condiciones.
                </p>
                <button
                    type="button"
                    disabled={busy}
                    className="mt-3 rounded-lg bg-[#8a0b0b] px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
                    onClick={() => {
                        setBusy(true);
                        router.post(
                            '/office/subscription/keep',
                            {},
                            { onFinish: finish },
                        );
                    }}
                >
                    Mantener mi suscripción
                </button>
            </section>
        );
    }

    return (
        <section className="rounded-2xl border border-[#e4dbd6] bg-white p-5">
            <h2 className="text-lg font-black">Cancelar suscripción</h2>
            <p className="mt-1 text-sm text-slate-600">
                Si ya no vas a usar Atlantia, puedes dejar de renovar cuando
                quieras, sin penalización. Seguirás teniendo acceso hasta el{' '}
                {periodEndsAt}.
            </p>
            {confirming ? (
                <div className="mt-3 space-y-3">
                    <label className="block text-sm font-medium text-slate-700">
                        ¿Nos cuentas por qué? (opcional)
                        <textarea
                            className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                            rows={3}
                            maxLength={300}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </label>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="rounded-lg border px-4 py-2 text-sm font-semibold"
                            onClick={() => setConfirming(false)}
                        >
                            Volver
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            className="rounded-lg bg-[#8a0b0b] px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
                            onClick={() => {
                                setBusy(true);
                                router.post(
                                    '/office/subscription/cancel',
                                    { reason },
                                    { onFinish: finish },
                                );
                            }}
                        >
                            Confirmar cancelación
                        </button>
                    </div>
                </div>
            ) : (
                <button
                    type="button"
                    className="mt-3 rounded-lg border border-red-200 px-4 py-2 text-sm font-bold text-red-700"
                    onClick={() => setConfirming(true)}
                >
                    Cancelar mi suscripción
                </button>
            )}
        </section>
    );
}
