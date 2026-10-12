<?php

declare(strict_types=1);

namespace App\Services\Integrations;

/**
 * What a public API key may do. Each ability maps to the application permission the key's owner must also hold
 * in the company and to the plan feature that has to be active, so a key never exceeds what its owner can do.
 */
class ApiAbilities
{
    /** @var array<string, array{label: string, group: string, permission: string|null, feature: string}> */
    public const ABILITIES = [
        'companies:read' => ['label' => 'Ver empresas', 'group' => 'Empresas', 'permission' => null, 'feature' => 'basic_accounting'],
        'customers:read' => ['label' => 'Ver clientes', 'group' => 'Ventas', 'permission' => 'sales.view', 'feature' => 'basic_invoicing'],
        'customers:write' => ['label' => 'Crear y actualizar clientes', 'group' => 'Ventas', 'permission' => 'sales.manage', 'feature' => 'basic_invoicing'],
        'sales:read' => ['label' => 'Ver ventas', 'group' => 'Ventas', 'permission' => 'sales.view', 'feature' => 'basic_invoicing'],
        'sales:write' => ['label' => 'Registrar ventas en borrador', 'group' => 'Ventas', 'permission' => 'sales.manage', 'feature' => 'basic_invoicing'],
        'receivables:read' => ['label' => 'Ver cuentas por cobrar', 'group' => 'Ventas', 'permission' => 'sales.view', 'feature' => 'basic_invoicing'],
        'suppliers:read' => ['label' => 'Ver proveedores', 'group' => 'Compras', 'permission' => 'purchases.view', 'feature' => 'basic_accounting'],
        'suppliers:write' => ['label' => 'Crear y actualizar proveedores', 'group' => 'Compras', 'permission' => 'purchases.manage', 'feature' => 'basic_accounting'],
        'purchases:read' => ['label' => 'Ver compras y gastos', 'group' => 'Compras', 'permission' => 'purchases.view', 'feature' => 'basic_accounting'],
        'purchases:write' => ['label' => 'Registrar compras en borrador', 'group' => 'Compras', 'permission' => 'purchases.manage', 'feature' => 'basic_accounting'],
        'payables:read' => ['label' => 'Ver cuentas por pagar', 'group' => 'Compras', 'permission' => 'purchases.view', 'feature' => 'basic_accounting'],
        'accounts:read' => ['label' => 'Ver catálogo de cuentas', 'group' => 'Contabilidad', 'permission' => 'ledger.view', 'feature' => 'basic_accounting'],
        'reports:read' => ['label' => 'Ver estados financieros', 'group' => 'Contabilidad', 'permission' => 'reports.view', 'feature' => 'basic_accounting'],
        'fel:read' => ['label' => 'Ver documentos FEL certificados', 'group' => 'FEL', 'permission' => 'fel.view', 'feature' => 'fel'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ABILITIES);
    }
}
