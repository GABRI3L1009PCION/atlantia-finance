import { Award } from 'lucide-react';

export type CertificateData = {
    person: string;
    course: string;
    issuer: string;
    code: string | null;
    issued_on: string;
    firm: string;
};

const longDate = (value: string): string => {
    const date = new Date(`${value.slice(0, 10)}T12:00:00`);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleDateString('es-GT', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          });
};

/** Certificate of completion. Sizes use the width of the box (cqw), so it scales from a preview to a full page. */
export function CertificatePreview({
    data,
    draft = false,
}: {
    data: CertificateData;
    draft?: boolean;
}) {
    const gold = '#c9a45a';
    const wine = '#7a1523';

    return (
        <div className="@container w-full">
            <div
                className="relative aspect-[1.414/1] w-full overflow-hidden bg-[#fffdf8] font-serif text-[#2a1f19] shadow-md"
                style={{ containerType: 'inline-size' }}
            >
                <span
                    aria-hidden
                    className="absolute top-0 left-0 h-[22%] w-[22%]"
                    style={{
                        background: `linear-gradient(135deg, ${wine}, #4a0b15)`,
                        clipPath: 'polygon(0 0, 100% 0, 0 100%)',
                    }}
                />
                <span
                    aria-hidden
                    className="absolute right-0 bottom-0 h-[22%] w-[22%]"
                    style={{
                        background: `linear-gradient(135deg, ${wine}, #4a0b15)`,
                        clipPath: 'polygon(100% 0, 100% 100%, 0 100%)',
                    }}
                />
                <span
                    aria-hidden
                    className="absolute inset-[3.5%] border"
                    style={{ borderColor: gold, borderWidth: '0.25cqw' }}
                />
                <span
                    aria-hidden
                    className="absolute inset-[4.6%] border"
                    style={{ borderColor: `${gold}88`, borderWidth: '0.1cqw' }}
                />
                {draft && (
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 grid -rotate-[24deg] place-items-center font-bold tracking-widest text-black/[0.06] uppercase select-none"
                        style={{ fontSize: '9cqw' }}
                    >
                        Vista previa
                    </span>
                )}
                <div className="relative flex h-full flex-col items-center px-[9%] py-[6.5%] text-center">
                    <p style={{ fontSize: '3.2cqw' }}>
                        <span className="font-semibold">{data.firm}</span>
                    </p>
                    <span
                        className="mt-[1%] h-px w-[8%]"
                        style={{ background: gold }}
                    />
                    <h2
                        className="mt-[3%] font-semibold tracking-wide uppercase"
                        style={{
                            fontSize: '4.6cqw',
                            fontVariant: 'small-caps',
                        }}
                    >
                        Certificado de finalización
                    </h2>
                    <p className="mt-[2.5%]" style={{ fontSize: '1.9cqw' }}>
                        Se otorga a
                    </p>
                    <p
                        className="mt-[1%] leading-tight font-semibold"
                        style={{ fontSize: '6.2cqw' }}
                    >
                        {data.person || 'Nombre del colaborador'}
                    </p>
                    <span
                        className="mt-[1.5%] h-px w-[52%]"
                        style={{ background: gold }}
                    />
                    <p className="mt-[3%]" style={{ fontSize: '1.9cqw' }}>
                        Por completar el curso
                    </p>
                    <p
                        className="mt-[0.8%] font-semibold"
                        style={{ fontSize: '3cqw' }}
                    >
                        {data.course || 'Nombre del curso o certificación'}
                    </p>
                    <p
                        className="mt-[1.2%] text-[#5a4a3e]"
                        style={{ fontSize: '1.7cqw' }}
                    >
                        Emitido por {data.issuer || data.firm}
                    </p>

                    <div className="mt-auto grid w-full grid-cols-3 items-end">
                        <div style={{ fontSize: '1.4cqw' }}>
                            <p className="font-semibold">
                                {longDate(data.issued_on)}
                            </p>
                            <p className="text-[#5a4a3e]">Fecha de emisión</p>
                        </div>
                        <div className="grid place-items-center">
                            <span
                                className="grid place-items-center rounded-full text-white shadow"
                                style={{
                                    width: '7cqw',
                                    height: '7cqw',
                                    background: `radial-gradient(circle at 35% 30%, #b3263c, ${wine})`,
                                    boxShadow: `0 0 0 0.5cqw ${gold}`,
                                }}
                            >
                                <Award
                                    style={{
                                        width: '3.6cqw',
                                        height: '3.6cqw',
                                    }}
                                />
                            </span>
                        </div>
                        <div style={{ fontSize: '1.4cqw' }}>
                            <p className="font-semibold">{data.code ?? '—'}</p>
                            <p className="text-[#5a4a3e]">
                                Código del certificado
                            </p>
                        </div>
                    </div>
                    <div
                        className="mt-[2.5%] w-[34%] border-t border-[#2a1f19]/70 pt-[0.6%]"
                        style={{ fontSize: '1.3cqw' }}
                    >
                        Responsable de capacitación
                    </div>
                </div>
            </div>
        </div>
    );
}
