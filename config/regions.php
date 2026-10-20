<?php

/*
 * Regional catalog used to configure a company: country with its legislation, currency, time zone, the tax structure
 * of the jurisdiction (reference rates, editable per company) and the chart-of-accounts templates it can install.
 * Rates are starting points; the accountant confirms them against the current law before using them.
 */
return [
    'currencies' => ['GTQ' => 'Quetzal guatemalteco', 'USD' => 'Dólar estadounidense', 'EUR' => 'Euro', 'HNL' => 'Lempira hondureño', 'CRC' => 'Colón costarricense', 'MXN' => 'Peso mexicano', 'PAB' => 'Balboa panameño'],

    'countries' => [
        'GT' => [
            'name' => 'Guatemala', 'currency' => 'GTQ', 'timezone' => 'America/Guatemala', 'authority' => 'SAT',
            'legislation' => ['Decreto 10-2012, Ley de Actualización Tributaria (ISR)', 'Decreto 27-92, Ley del Impuesto al Valor Agregado', 'Decreto 2-70, Código de Comercio', 'Normas Internacionales de Información Financiera (NIIF)'],
            'templates' => ['guatemala_general', 'guatemala_services', 'guatemala_commerce', 'niif_general'],
            'taxes' => [
                ['code' => 'GT_IVA', 'name' => 'IVA', 'kind' => 'vat', 'rate_bp' => 1200, 'legal_basis' => 'Decreto 27-92'],
                ['code' => 'GT_ISR_UTILIDADES', 'name' => 'ISR sobre utilidades', 'kind' => 'income_tax', 'rate_bp' => 2500, 'legal_basis' => 'Decreto 10-2012'],
            ],
        ],
        'SV' => [
            'name' => 'El Salvador', 'currency' => 'USD', 'timezone' => 'America/El_Salvador', 'authority' => 'Ministerio de Hacienda',
            'legislation' => ['Ley de Impuesto sobre la Renta', 'Ley de Impuesto a la Transferencia de Bienes Muebles y a la Prestación de Servicios (IVA)', 'Código Tributario', 'Normas Internacionales de Información Financiera (NIIF)'],
            'templates' => ['niif_general'],
            'taxes' => [
                ['code' => 'SV_IVA', 'name' => 'IVA', 'kind' => 'vat', 'rate_bp' => 1300, 'legal_basis' => 'Ley de IVA'],
                ['code' => 'SV_RENTA', 'name' => 'Impuesto sobre la renta', 'kind' => 'income_tax', 'rate_bp' => 3000, 'legal_basis' => 'Ley de Impuesto sobre la Renta'],
            ],
        ],
        'HN' => [
            'name' => 'Honduras', 'currency' => 'HNL', 'timezone' => 'America/Tegucigalpa', 'authority' => 'SAR',
            'legislation' => ['Ley del Impuesto sobre la Renta', 'Ley del Impuesto sobre Ventas (ISV)', 'Código Tributario', 'Normas Internacionales de Información Financiera (NIIF)'],
            'templates' => ['niif_general'],
            'taxes' => [
                ['code' => 'HN_ISV', 'name' => 'Impuesto sobre ventas (ISV)', 'kind' => 'vat', 'rate_bp' => 1500, 'legal_basis' => 'Ley del ISV'],
                ['code' => 'HN_ISR', 'name' => 'Impuesto sobre la renta', 'kind' => 'income_tax', 'rate_bp' => 2500, 'legal_basis' => 'Ley del ISR'],
            ],
        ],
        'CR' => [
            'name' => 'Costa Rica', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'authority' => 'Ministerio de Hacienda',
            'legislation' => ['Ley del Impuesto sobre la Renta', 'Ley del Impuesto sobre el Valor Agregado', 'Normas Internacionales de Información Financiera (NIIF)'],
            'templates' => ['niif_general'],
            'taxes' => [
                ['code' => 'CR_IVA', 'name' => 'IVA', 'kind' => 'vat', 'rate_bp' => 1300, 'legal_basis' => 'Ley del IVA'],
                ['code' => 'CR_RENTA', 'name' => 'Impuesto sobre la renta', 'kind' => 'income_tax', 'rate_bp' => 3000, 'legal_basis' => 'Ley del ISR'],
            ],
        ],
        'PA' => [
            'name' => 'Panamá', 'currency' => 'PAB', 'timezone' => 'America/Panama', 'authority' => 'DGI',
            'legislation' => ['Código Fiscal', 'Impuesto de Transferencia de Bienes Corporales Muebles y la Prestación de Servicios (ITBMS)', 'Normas Internacionales de Información Financiera (NIIF)'],
            'templates' => ['niif_general'],
            'taxes' => [
                ['code' => 'PA_ITBMS', 'name' => 'ITBMS', 'kind' => 'vat', 'rate_bp' => 700, 'legal_basis' => 'Código Fiscal'],
                ['code' => 'PA_ISR', 'name' => 'Impuesto sobre la renta', 'kind' => 'income_tax', 'rate_bp' => 2500, 'legal_basis' => 'Código Fiscal'],
            ],
        ],
        'MX' => [
            'name' => 'México', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'authority' => 'SAT',
            'legislation' => ['Ley del Impuesto sobre la Renta', 'Ley del Impuesto al Valor Agregado', 'Código Fiscal de la Federación', 'Normas de Información Financiera (NIF) y NIIF'],
            'templates' => ['niif_general'],
            'taxes' => [
                ['code' => 'MX_IVA', 'name' => 'IVA', 'kind' => 'vat', 'rate_bp' => 1600, 'legal_basis' => 'Ley del IVA'],
                ['code' => 'MX_ISR', 'name' => 'ISR', 'kind' => 'income_tax', 'rate_bp' => 3000, 'legal_basis' => 'Ley del ISR'],
            ],
        ],
    ],

    /** Accounts each template adds on top of the starter chart (code, name, type). */
    'templates' => [
        'guatemala_general' => ['label' => 'Guatemala · general', 'accounts' => [
            ['1302', 'Retenciones de ISR soportadas', 'asset'], ['2203', 'ISR retenido por pagar', 'liability'], ['2204', 'IVA retenido por pagar', 'liability'], ['2205', 'ISR por pagar', 'liability'],
            ['2206', 'Cuotas IGSS por pagar', 'liability'], ['2207', 'Prestaciones laborales por pagar', 'liability'], ['3202', 'Reserva legal', 'equity'], ['5301', 'Gasto de ISR', 'expense'],
        ]],
        'guatemala_services' => ['label' => 'Guatemala · servicios', 'accounts' => [
            ['1302', 'Retenciones de ISR soportadas', 'asset'], ['2203', 'ISR retenido por pagar', 'liability'], ['2204', 'IVA retenido por pagar', 'liability'], ['2205', 'ISR por pagar', 'liability'],
            ['2206', 'Cuotas IGSS por pagar', 'liability'], ['3202', 'Reserva legal', 'equity'], ['4102', 'Honorarios profesionales', 'income'], ['5208', 'Subcontratación de servicios', 'expense'], ['5301', 'Gasto de ISR', 'expense'],
        ]],
        'guatemala_commerce' => ['label' => 'Guatemala · comercio', 'accounts' => [
            ['1202', 'Mercaderías en tránsito', 'asset'], ['1302', 'Retenciones de ISR soportadas', 'asset'], ['2203', 'ISR retenido por pagar', 'liability'], ['2204', 'IVA retenido por pagar', 'liability'],
            ['2205', 'ISR por pagar', 'liability'], ['3202', 'Reserva legal', 'equity'], ['4202', 'Descuentos y devoluciones sobre ventas', 'income'], ['5102', 'Fletes sobre compras', 'expense'], ['5301', 'Gasto de ISR', 'expense'],
        ]],
        'niif_general' => ['label' => 'NIIF · general (regional)', 'accounts' => [
            ['1105', 'Inversiones temporales', 'asset'], ['1304', 'Gastos pagados por anticipado', 'asset'], ['2103', 'Beneficios a empleados por pagar', 'liability'], ['2205', 'Impuesto a las ganancias por pagar', 'liability'],
            ['2401', 'Pasivos por arrendamiento', 'liability'], ['3202', 'Reservas', 'equity'], ['3301', 'Otro resultado integral', 'equity'], ['4301', 'Otros ingresos', 'income'], ['5301', 'Gasto por impuesto a las ganancias', 'expense'],
            ['5302', 'Gastos financieros', 'expense'],
        ]],
    ],
];
