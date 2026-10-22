<?php

return [
    'default' => 'essential',
    'billing_periods' => ['monthly', 'annual'],
    'catalog' => [
        'essential' => [
            'name' => 'Esencial',
            'monthly_price' => 399,
            'annual_price' => 3990,
            'features' => [
                '1 oficina y 2 usuarios',
                '150 empresas cliente',
                '2 GB de almacenamiento',
                '20 consultas de IA al mes',
            ],
        ],
        'professional' => [
            'name' => 'Profesional',
            'monthly_price' => 799,
            'annual_price' => 7990,
            'features' => [
                '1 oficina y 8 usuarios',
                '500 empresas cliente',
                '10 GB de almacenamiento',
                '120 consultas de IA al mes',
            ],
        ],
        'multi_office' => [
            'name' => 'Multi-oficina',
            'monthly_price' => 1499,
            'annual_price' => 14990,
            'features' => [
                'Hasta 5 oficinas y 25 usuarios',
                '1,500 empresas cliente',
                '40 GB de almacenamiento',
                '500 consultas de IA al mes',
            ],
        ],
        'corporate' => [
            'name' => 'Corporativo',
            'monthly_price' => 2999,
            'annual_price' => 29990,
            'features' => [
                'Hasta 10 oficinas y 60 usuarios',
                '5,000 empresas cliente',
                '120 GB de almacenamiento',
                '1,500 consultas de IA al mes',
            ],
        ],
        'enterprise' => [
            'name' => 'Empresarial',
            'monthly_price' => 4999,
            'annual_price' => 49990,
            'price_prefix' => 'Desde',
            'features' => [
                'Todas las funciones de Atlantia',
                'Capacidad e infraestructura a medida',
                'Integraciones especiales',
                'Implementación y acompañamiento',
            ],
        ],
    ],
];
