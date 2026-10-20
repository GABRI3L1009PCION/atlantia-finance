import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { CertificatePreview } from '@/components/certificate';
import type { CertificateData } from '@/components/certificate';

export default function Certificate({
    certificate,
}: {
    certificate: CertificateData;
}) {
    return (
        <>
            <Head title={`Certificado - ${certificate.person}`} />
            <style>{`@media print { @page { size: A4 landscape; margin: 0 } body { background: #fff !important } .no-print { display: none !important } }`}</style>
            <main className="mx-auto flex min-h-screen max-w-5xl flex-col items-center gap-4 bg-[#f6f1e7] p-4 print:bg-white print:p-0">
                <div className="no-print flex w-full items-center justify-between">
                    <a
                        href="/office/training"
                        className="text-sm font-semibold text-[#640000]"
                    >
                        ← Volver a Capacitación
                    </a>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex h-10 items-center gap-2 rounded-xl bg-[#640000] px-4 text-sm font-bold text-white"
                    >
                        <Printer className="size-4" /> Imprimir / Guardar PDF
                    </button>
                </div>
                <CertificatePreview data={certificate} />
            </main>
        </>
    );
}
