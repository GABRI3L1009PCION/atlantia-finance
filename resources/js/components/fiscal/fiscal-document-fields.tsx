export type FiscalDocumentData = {
    fiscal_document_type: string;
    fiscal_series: string;
    fiscal_number: string;
    fiscal_authorization: string;
};

export type TaxTreatment = 'goods' | 'services' | 'exempt';

export const fiscalDocumentTypes: Record<string, string> = {
    FACT: 'Factura (FACT)',
    FCAM: 'Factura cambiaria (FCAM)',
    FPEQ: 'Factura pequeño contribuyente (FPEQ)',
    FESP: 'Factura especial (FESP)',
};

export const taxTreatments: Record<TaxTreatment, string> = {
    goods: 'Bien',
    services: 'Servicio',
    exempt: 'Exento',
};

/** Fiscal identity of an invoice as printed on the FEL document (needed by the VAT books). */
export function FiscalDocumentFields({
    data,
    onChange,
    field,
    types = Object.keys(fiscalDocumentTypes),
    hint,
}: {
    data: FiscalDocumentData;
    onChange: (key: keyof FiscalDocumentData, value: string) => void;
    field: string;
    types?: string[];
    hint?: string;
}) {
    const code = (value: string) => value.toUpperCase().replace(/\s+/g, '');

    return (
        <fieldset className="col-span-full grid gap-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:grid-cols-4">
            <legend className="px-1 text-xs font-semibold text-slate-600">
                Documento fiscal (Libros IVA)
            </legend>
            <label>
                Tipo
                <select
                    className={field}
                    value={data.fiscal_document_type}
                    onChange={(event) =>
                        onChange('fiscal_document_type', event.target.value)
                    }
                >
                    {types.map((type) => (
                        <option key={type} value={type}>
                            {fiscalDocumentTypes[type] ?? type}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                Serie
                <input
                    className={field}
                    value={data.fiscal_series}
                    maxLength={40}
                    placeholder="Ej. A1B2C3D4"
                    onChange={(event) =>
                        onChange('fiscal_series', code(event.target.value))
                    }
                />
            </label>
            <label>
                Número
                <input
                    className={field}
                    value={data.fiscal_number}
                    maxLength={40}
                    placeholder="Ej. 1234567890"
                    onChange={(event) =>
                        onChange('fiscal_number', code(event.target.value))
                    }
                />
            </label>
            <label>
                Autorización FEL (opcional)
                <input
                    className={field}
                    value={data.fiscal_authorization}
                    maxLength={64}
                    placeholder="UUID de certificación"
                    onChange={(event) =>
                        onChange(
                            'fiscal_authorization',
                            code(event.target.value),
                        )
                    }
                />
            </label>
            {hint && (
                <p className="col-span-full text-xs text-slate-500">{hint}</p>
            )}
        </fieldset>
    );
}

export function TaxTreatmentSelect({
    value,
    onChange,
    field,
}: {
    value: string;
    onChange: (value: TaxTreatment) => void;
    field: string;
}) {
    return (
        <select
            className={field}
            value={value}
            aria-label="Clasificación fiscal"
            onChange={(event) => onChange(event.target.value as TaxTreatment)}
        >
            {Object.entries(taxTreatments).map(([key, label]) => (
                <option key={key} value={key}>
                    {label}
                </option>
            ))}
        </select>
    );
}
