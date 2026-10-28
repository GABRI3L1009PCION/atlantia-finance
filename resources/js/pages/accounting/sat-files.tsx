import { router } from '@inertiajs/react';
import { Download, FileUp } from 'lucide-react';
import { useState } from 'react';
import { Empty, Label, PageShell } from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import { Card, field, primary, secondary } from '@/components/accounting-ui';

type FileSummary = { lines: number; warnings: string[] };

type Props = {
    company: Company;
    year: number;
    month: number;
    summary: {
        asiste: FileSummary;
        retenisr: Record<string, FileSummary>;
    };
    retenisrFiles: Record<string, string>;
    inventoryTypes: Record<string, string>;
    concepts: Record<string, string>;
    semesterDate: string;
    canExport: boolean;
    hasAdvanced: boolean;
};

const MONTHS = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

function Warnings({ items }: { items: string[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-700">
            {items.map((item) => (
                <li key={item}>{item}</li>
            ))}
        </ul>
    );
}

export default function SatFiles({
    company,
    year,
    month,
    summary,
    retenisrFiles,
    inventoryTypes,
    concepts,
    semesterDate,
    canExport,
    hasAdvanced,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/sat-files`;
    const period = `year=${year}&month=${month}`;
    const [books, setBooks] = useState('all');
    const [asOf, setAsOf] = useState(semesterDate);
    const [type, setType] = useState('BDPVE');
    const [byRequest, setByRequest] = useState(false);
    const link = (className: string, href: string, label: string) =>
        canExport ? (
            <a className={className} href={href}>
                <Download className="size-4" /> {label}
            </a>
        ) : null;

    return (
        <PageShell
            company={company}
            title="Archivos para la SAT"
            subtitle="Archivos de carga para Asiste Libros, RetenISR 2 y el Informe de Inventarios"
            icon={FileUp}
        >
            <Card title="Período">
                <div className="flex flex-wrap items-end gap-3">
                    <Label text="Mes">
                        <select
                            className={field}
                            value={month}
                            onChange={(e) =>
                                router.get(base, {
                                    year,
                                    month: e.target.value,
                                })
                            }
                        >
                            {MONTHS.map((name, index) => (
                                <option key={name} value={index + 1}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </Label>
                    <Label text="Año">
                        <input
                            className={field}
                            type="number"
                            defaultValue={year}
                            onBlur={(e) =>
                                router.get(base, {
                                    year: e.target.value,
                                    month,
                                })
                            }
                        />
                    </Label>
                </div>
            </Card>

            <Card title="Asiste Libros · Libros de Compras y Ventas del IVA">
                <p className="text-sm text-slate-600">
                    Archivo .asl con las 32 columnas que pide la SAT, separadas
                    por “|” y sin títulos. En Asiste Libros: Procesos especiales
                    → Carga y envío electrónico de libros → Búsqueda → Validar
                    archivo → Enviar.
                </p>
                <p className="mt-2 text-sm font-semibold">
                    {summary.asiste.lines} documento(s) en{' '}
                    {MONTHS[month - 1].toLowerCase()} {year}
                </p>
                <Warnings items={summary.asiste.warnings} />
                <div className="mt-3 flex flex-wrap items-end gap-3">
                    <Label text="Libros">
                        <select
                            className={field}
                            value={books}
                            onChange={(e) => setBooks(e.target.value)}
                        >
                            <option value="all">Compras y ventas</option>
                            <option value="purchases">Solo compras</option>
                            <option value="sales">Solo ventas</option>
                        </select>
                    </Label>
                    {link(
                        primary,
                        `${base}/asiste-libros?${period}&books=${books}`,
                        'Descargar .asl',
                    )}
                </div>
            </Card>

            {hasAdvanced ? (
                <>
                    <Card title="RetenISR 2 Escritorio">
                        <p className="text-sm text-slate-600">
                            Libros de Excel con el formato de los modelos de
                            carga de la SAT (una sola hoja). En RetenISR use
                            “Carga archivo Excel” y valide antes de generar las
                            constancias.
                        </p>
                        <div className="mt-3 grid gap-3 md:grid-cols-3">
                            {Object.entries(retenisrFiles).map(
                                ([kind, label]) => {
                                    const file = summary.retenisr[kind];

                                    return (
                                        <article
                                            key={kind}
                                            className="rounded-xl border border-slate-200 p-3 text-sm"
                                        >
                                            <p className="font-semibold">
                                                {label}
                                            </p>
                                            <p className="text-slate-600">
                                                {file.lines} registro(s)
                                            </p>
                                            <Warnings items={file.warnings} />
                                            <div className="mt-2">
                                                {file.lines === 0 ? (
                                                    <Empty>
                                                        Sin datos en el mes.
                                                    </Empty>
                                                ) : (
                                                    link(
                                                        secondary,
                                                        `${base}/retenisr?${period}&kind=${kind}`,
                                                        'Descargar .xls',
                                                    )
                                                )}
                                            </div>
                                        </article>
                                    );
                                },
                            )}
                        </div>
                        <details className="mt-3 text-xs text-slate-600">
                            <summary className="cursor-pointer">
                                Tabla de conceptos (opcional simplificado)
                            </summary>
                            <ul className="mt-1 grid gap-1 md:grid-cols-2">
                                {Object.entries(concepts).map(
                                    ([code, label]) => (
                                        <li key={code}>
                                            {code}. {label}
                                        </li>
                                    ),
                                )}
                            </ul>
                        </details>
                    </Card>

                    <Card title="Informe de Inventarios">
                        <p className="text-sm text-slate-600">
                            Archivo de texto delimitado por tabulaciones con el
                            nombre que exige la SAT
                            (SAT_RSI_NIT_TIPO_dd_mm_aa.txt). El semestral se
                            presenta con existencias al 30 de junio y al 31 de
                            diciembre, a costo promedio del kardex.
                        </p>
                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <Label text="Existencias al">
                                <input
                                    className={field}
                                    type="date"
                                    value={asOf}
                                    onChange={(e) => setAsOf(e.target.value)}
                                />
                            </Label>
                            <Label text="Tipo de inventario">
                                <select
                                    className={field}
                                    value={type}
                                    onChange={(e) => setType(e.target.value)}
                                >
                                    {Object.entries(inventoryTypes).map(
                                        ([code, label]) => (
                                            <option key={code} value={code}>
                                                {code} · {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={byRequest}
                                    onChange={(e) =>
                                        setByRequest(e.target.checked)
                                    }
                                />
                                A requerimiento de Fiscalización
                            </label>
                            {link(
                                primary,
                                `${base}/inventarios?as_of=${asOf}&type=${type}&by_request=${byRequest ? 1 : 0}`,
                                'Descargar .txt',
                            )}
                        </div>
                    </Card>
                </>
            ) : (
                <Card title="RetenISR e Informe de Inventarios">
                    <p className="text-sm text-slate-600">
                        Los archivos de RetenISR 2 (retenciones de ISR) y el
                        Informe de Inventarios están disponibles desde el plan
                        Profesional.
                    </p>
                </Card>
            )}
        </PageShell>
    );
}
