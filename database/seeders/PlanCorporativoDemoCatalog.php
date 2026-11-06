<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Support\Str;

/**
 * Datos ficticios del escenario de demostración del Plan Corporativo: la firma «Grupo Contable Atlántico Norte» con ocho
 * oficinas en Guatemala, 49 usuarios internos con sus roles reales (y dos roles personalizados), 96 empresas cliente de
 * 12 sectores, tres grupos empresariales, contactos del portal y las integraciones de demostración (API y webhooks).
 * Las operaciones por sector y los empleados se toman de las plantillas de PlanMultiOficinaDemoCatalog sin modificarlas.
 * Nombres, NIT, direcciones, teléfonos y montos son inventados; correos y URL usan dominios .test (no resuelven).
 *
 * @phpstan-type Company array{tax_id: string, name: string, trade_name: string, office: string, sector: string, address: string, phone: string, email: string, contact: string, capital: string, series: string, scale: float, bank: array{0: string, 1: string, 2: string}, payroll: bool, asset: array{0: string, 1: string, 2: string, 3: int}|null, production: bool, full: bool}
 * @phpstan-type Office array{code: string, name: string, address: string, city: string, state: string, phone: string, primary: bool, workflow: string, internal_days: int, review_days: int, buckets: list<int>}
 * @phpstan-type Member array{key: string, name: string, email: string, role: string, offices: list<string>|null, custom: string|null}
 */
final class PlanCorporativoDemoCatalog
{
    public const ORGANIZATION_TAX_ID = '6829413-5';

    public const DOMAIN = 'grupocontableatlantico.test';

    public const CLIENT_DOMAIN = 'clientes-grupocontableatlantico.test';

    /** @return array{name: string, legal_name: string, billing: array{name: string, nit: string, address: string}} */
    public static function firm(): array
    {
        return ['name' => 'Grupo Contable Atlántico Norte', 'legal_name' => 'Grupo Contable Atlántico Norte, S.A.',
            'billing' => ['name' => 'Grupo Contable Atlántico Norte, S.A.', 'nit' => self::ORGANIZATION_TAX_ID, 'address' => '8a. Calle entre 6a. y 7a. Avenida, Barrio El Centro, Puerto Barrios, Izabal']];
    }

    /** @return list<Office> */
    public static function offices(): array
    {
        return [
            ['code' => 'CEN-01', 'name' => 'Oficina Central', 'address' => '8a. Calle entre 6a. y 7a. Avenida, Barrio El Centro', 'city' => 'Puerto Barrios', 'state' => 'Izabal', 'phone' => '+502 7948 1200', 'primary' => true, 'workflow' => 'double_level', 'internal_days' => 5, 'review_days' => 3, 'buckets' => [30, 60, 90, 120]],
            ['code' => 'STC-01', 'name' => 'Oficina Santo Tomás', 'address' => 'Avenida Principal, Zona Portuaria, local 12', 'city' => 'Santo Tomás de Castilla', 'state' => 'Izabal', 'phone' => '+502 7948 3600', 'primary' => false, 'workflow' => 'double_level', 'internal_days' => 4, 'review_days' => 2, 'buckets' => [30, 60, 90]],
            ['code' => 'MOR-01', 'name' => 'Oficina Morales', 'address' => 'Barrio El Centro, frente al parque central', 'city' => 'Morales', 'state' => 'Izabal', 'phone' => '+502 7947 2400', 'primary' => false, 'workflow' => 'single_level', 'internal_days' => 4, 'review_days' => 2, 'buckets' => [30, 60, 90]],
            ['code' => 'LIV-01', 'name' => 'Oficina Livingston', 'address' => 'Calle Principal, Barrio Pueblo Nuevo', 'city' => 'Livingston', 'state' => 'Izabal', 'phone' => '+502 7947 0800', 'primary' => false, 'workflow' => 'single_level', 'internal_days' => 6, 'review_days' => 3, 'buckets' => [30, 60]],
            ['code' => 'ZAC-01', 'name' => 'Oficina Zacapa', 'address' => '4a. Calle 13-20, zona 1', 'city' => 'Zacapa', 'state' => 'Zacapa', 'phone' => '+502 7941 5500', 'primary' => false, 'workflow' => 'double_level', 'internal_days' => 5, 'review_days' => 2, 'buckets' => [30, 60, 90]],
            ['code' => 'CHQ-01', 'name' => 'Oficina Chiquimula', 'address' => '3a. Avenida 6-45, zona 1', 'city' => 'Chiquimula', 'state' => 'Chiquimula', 'phone' => '+502 7942 3300', 'primary' => false, 'workflow' => 'single_level', 'internal_days' => 4, 'review_days' => 2, 'buckets' => [30, 60, 90]],
            ['code' => 'COB-01', 'name' => 'Oficina Cobán', 'address' => '1a. Calle 3-12, zona 2', 'city' => 'Cobán', 'state' => 'Alta Verapaz', 'phone' => '+502 7951 4400', 'primary' => false, 'workflow' => 'double_level', 'internal_days' => 5, 'review_days' => 3, 'buckets' => [30, 60, 90, 120]],
            ['code' => 'GUA-01', 'name' => 'Oficina Ciudad de Guatemala', 'address' => '6a. Avenida 9-18, zona 10, Edificio Atlántico, nivel 7', 'city' => 'Guatemala', 'state' => 'Guatemala', 'phone' => '+502 2385 7700', 'primary' => false, 'workflow' => 'double_level', 'internal_days' => 6, 'review_days' => 3, 'buckets' => [30, 60, 90, 180]],
        ];
    }

    /**
     * Equipo interno: clave, nombre, usuario de correo, rol (código real de ROLE_PRESETS, o el código de un rol
     * personalizado), oficinas donde trabaja (null = toda la firma) y, si su rol es personalizado, la clave de ese rol.
     * La Administradora General se incorpora al final, cuando la firma ya está en marcha.
     *
     * @return list<Member>
     */
    public static function team(): array
    {
        $member = fn (string $key, string $name, string $role, ?array $offices, ?string $custom = null): array => ['key' => $key, 'name' => $name,
            'email' => Str::slug(implode(' ', array_slice(explode(' ', $name), 0, 1)).' '.(explode(' ', $name)[2] ?? ''), '.'), 'role' => $role, 'offices' => $offices, 'custom' => $custom];

        $team = [
            $member('owner', 'Ricardo Antonio Paz Morales', 'owner_director', null),
            $member('manager', 'Pedro Alejandro García Ramírez', 'accounting_manager', null),
            $member('auditor', 'Sofía Isabel López Hernández', 'auditor', null),
            $member('readonly', 'Héctor Manuel Juárez Cifuentes', 'read_only', ['GUA-01']),
            $member('tax-east', 'Gabriela Sofía Solís Ramírez', 'tax_specialist', ['CEN-01', 'STC-01', 'MOR-01', 'LIV-01']),
            $member('tax-west', 'Lilian Esperanza Monroy Paz', 'tax_specialist', ['ZAC-01', 'CHQ-01', 'COB-01', 'GUA-01']),
            $member('treasury', 'Óscar Fernando Villeda García', 'treasury', null),
            $member('receivables', 'Marta Isabel Bol Hernández', 'accounts_receivable', null),
            $member('payables', 'Edwin Alejandro Pérez López', 'accounts_payable', null),
            $member('payroll', 'Karla Beatriz Ordóñez Chacón', 'payroll', null),
            $member('general-1', 'Ana Lucía Hernández García', 'general_accountant', ['CEN-01', 'STC-01']),
            $member('general-2', 'Mario Roberto Chávez Ortiz', 'general_accountant', ['MOR-01', 'LIV-01']),
            $member('general-3', 'Beatriz Adriana Mejía Solórzano', 'general_accountant', ['ZAC-01', 'CHQ-01']),
            $member('general-4', 'Fernando José Rivas Duarte', 'general_accountant', ['COB-01', 'GUA-01']),
            $member('coord-reportes', 'Ingrid Johana Castañeda Lima', 'coordinador-de-reportes', ['CEN-01'], 'reports'),
            $member('revisor-doc', 'Wálter Giovanni Morán Recinos', 'revisor-de-documentacion', ['MOR-01'], 'documents'),
        ];
        $staff = [
            'CEN-01' => ['Silvia Marisol Barrios Coc', 'Jorge Alejandro Cardona Pérez', 'Carlos Eduardo Méndez Castillo', 'Andrea Fernanda Pineda Morales'],
            'STC-01' => ['Rosa Amelia Cruz Villatoro', 'Claudia Patricia Morales Pérez', 'José Alejandro Ramírez López', 'Mariela Esperanza Caal Xol'],
            'MOR-01' => ['Julio César Ortiz Paz', 'Hugo Leonel Sandoval Tejada', 'María Fernanda Castillo Morales', 'Josué Daniel Tzul Gómez'],
            'LIV-01' => ['Teresa del Carmen Martínez Arzú', 'Kevin Josué Mejía Barrios', 'Brenda Lisseth Arana Fuentes', 'Pablo Antonio Coc Caal'],
            'ZAC-01' => ['Ronald Eduardo Fuentes Morales', 'Alba Luz Hernández Ical', 'Selvin Rolando Barrios Coc', 'Dulce María Guerra Portillo'],
            'CHQ-01' => ['Liliana Marisol Peralta Ayala', 'Mynor Estuardo Paredes Cruz', 'Fredy Estuardo Lemus Galdámez', 'Yesenia Carolina Vásquez Lemus'],
            'COB-01' => ['Edgar Rolando Sabán Coc', 'Juana Isabel Choc Tiul', 'Rodrigo Andrés Valdez Quiñónez', 'Mirna Lucrecia Bol Caal'],
            'GUA-01' => ['Ernesto Rafael Solís Monterroso', 'Lucía Gabriela Arriaga Toledo', 'Diego Sebastián Castañeda Rivera', 'Valeria Alejandra Quiñónez Díaz'],
        ];
        foreach ($staff as $office => [$admin, $supervisor, $accountant, $assistant]) {
            $tag = strtolower(substr($office, 0, 3));
            $team[] = $member('admin-'.$tag, $admin, 'office_admin', [$office]);
            $team[] = $member('supervisor-'.$tag, $supervisor, 'accounting_supervisor', [$office]);
            $team[] = $member('accountant-'.$tag, $accountant, 'accountant_in_charge', [$office]);
            $team[] = $member('assistant-'.$tag, $assistant, 'accounting_assistant', [$office]);
        }
        $team[] = $member('admin-general', 'Silvia Patricia Ochoa López', 'office_admin', null);

        return $team;
    }

    /** @return array{admin: string, supervisor: string, accountant: string, assistant: string, general: string, tax: string} */
    public static function officeStaff(string $office): array
    {
        $tag = strtolower(substr($office, 0, 3));
        $general = match ($office) {
            'CEN-01', 'STC-01' => 'general-1',
            'MOR-01', 'LIV-01' => 'general-2',
            'ZAC-01', 'CHQ-01' => 'general-3',
            default => 'general-4',
        };

        return ['admin' => 'admin-'.$tag, 'supervisor' => 'supervisor-'.$tag, 'accountant' => 'accountant-'.$tag, 'assistant' => 'assistant-'.$tag, 'general' => $general,
            'tax' => in_array($office, ['CEN-01', 'STC-01', 'MOR-01', 'LIV-01'], true) ? 'tax-east' : 'tax-west'];
    }

    /**
     * Roles personalizados de la firma con permisos de consulta y reporte (sin privilegios de administración).
     *
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function customRoles(): array
    {
        return [
            'compliance' => ['name' => 'Coordinador de Cumplimiento Contable', 'description' => 'Da seguimiento a obligaciones fiscales y libros de IVA de las empresas de la sede.',
                'permissions' => ['fiscal.view', 'vat_books.view', 'tasks.view_all', 'reports.view']],
            'control' => ['name' => 'Analista de Control Interno', 'description' => 'Consulta libros, balances y la bitácora para verificar controles.',
                'permissions' => ['journal.view', 'ledger.view', 'trial_balance.view', 'office.audit.view']],
            'documents' => ['name' => 'Revisor de Documentación', 'description' => 'Revisa la documentación recibida de los clientes antes de su registro.',
                'permissions' => ['documents.view', 'client_portal.view', 'purchases.view', 'sales.view']],
            'reports' => ['name' => 'Coordinador de Reportes', 'description' => 'Prepara y exporta los reportes financieros de la sede.',
                'permissions' => ['reports.view', 'reports.export', 'trial_balance.view', 'ledger.view']],
        ];
    }

    /** Sectores por posición dentro de cada oficina; las cuatro primeras empresas de cada sede llevan contabilidad completa. */
    private const SECTOR_ORDER = ['arrendamiento', 'distribuidora', 'comercio', 'industrial', 'construccion', 'ferreteria', 'restaurante', 'transporte', 'logistica', 'servicios', 'turismo', 'tecnologia'];

    /** @var array<string, list<string>> Nombre de la empresa por sector y por oficina (en el orden de offices()). */
    private const NAMES = [
        'arrendamiento' => ['Inversiones Puerto del Sol, S.A.', 'Inversiones Santo Tomás, S.A.', 'Inversiones Bananera, S.A.', 'Inversiones El Golfete, S.A.', 'Inversiones Valle de Zacapa, S.A.', 'Inversiones de Oriente, S.A.', 'Inversiones Verapaz, S.A.', 'Corporación Inmobiliaria Capitalina, S.A.'],
        'distribuidora' => ['Distribuidora El Progreso, S.A.', 'Agroindustrias del Atlántico, S.A.', 'Agroservicios del Motagua', 'Distribuidora Río Dulce', 'Agropecuaria El Valle de Zacapa', 'Distribuidora Chiquimulteca', 'Café y Cardamomo de Cobán, S.A.', 'Distribuidora Metropolitana, S.A.'],
        'comercio' => ['Comercializadora del Caribe', 'Comercial La Esperanza', 'Tienda El Ahorro', 'Almacén La Económica', 'Comercial San Rafael', 'Comercial Las Américas', 'Comercial El Quetzal Verapacense', 'Bodega El Buen Precio'],
        'industrial' => ['Panificadora del Atlántico, S.A.', 'Panificadora Santo Tomás, S.A.', 'Panadería Industrial Morales', 'Panificadora Garífuna', 'Panificadora Zacapaneca, S.A.', 'Panificadora La Perla de Oriente', 'Panificadora Verapaz, S.A.', 'Panificadora Metropolitana, S.A.'],
        'construccion' => ['Constructora San José, S.A.', 'Construcciones Portuarias Izabal, S.A.', 'Constructora Río Motagua', 'Constructora del Golfete', 'Constructora Zacapa, S.A.', 'Constructora Oriental, S.A.', 'Constructora Verapaz, S.A.', 'Constructora Metropolitana, S.A.'],
        'ferreteria' => ['Ferretería El Martillo', 'Ferretería La Llave', 'Materiales La Bendición', 'Ferretería El Tornillo', 'Materiales y Ferretería Zacapa', 'Ferretería La Unión', 'Ferretería Verapaz', 'Ferretería La Capital'],
        'restaurante' => ['Restaurante La Ceiba', 'Mariscos El Muelle', 'Restaurante El Ranchón', 'Restaurante Tapado Garífuna', 'Comedor y Restaurante Zacapaneco', 'Restaurante Los Arcos', 'Restaurante Kakaw Cobán', 'Restaurante La Fonda Capitalina'],
        'transporte' => ['Transportes del Atlántico, S.A.', 'Transportes Portuarios Santo Tomás', 'Transportes Morales Express', 'Lanchas Express Livingston', 'Transportes Zacapa', 'Transportes Oriente Express', 'Transportes Verapaz', 'Transportes Metropolitanos, S.A.'],
        'logistica' => ['Transportes y Logística del Norte, S.A.', 'Almacenadora Portuaria del Atlántico, S.A.', 'Logística Bananera', 'Logística del Golfete', 'Centro Logístico Zacapa', 'Logística Fronteriza de Oriente', 'Logística Verapaz', 'Logística Integral de Guatemala, S.A.'],
        'servicios' => ['Servicios Industriales de Izabal', 'Agencia Aduanal Puerta del Caribe', 'Servicios Contables del Motagua', 'Clínica Médica Livingston', 'Servicios Profesionales Zacapa', 'Consultores de Oriente', 'Clínica Dental Verapaz', 'Servicios Administrativos Capitalinos'],
        'turismo' => ['Hotel Brisas de Amatique', 'Hotel Bahía Tropical', 'Hotel Plaza Morales', 'Hotel Casa Garífuna', 'Hotel Valle de Zacapa', 'Hotel Chiquimula Real', 'Hotel Posada Cobán', 'Hotel Capital Plaza'],
        'tecnologia' => ['Soluciones Tecnológicas del Caribe', 'Sistemas Portuarios Integrados', 'Redes y Cómputo del Motagua', 'Tecnología Garífuna Digital', 'Soluciones Informáticas Zacapa', 'Tecnología de Oriente', 'Sistemas Verapaz', 'Desarrollo de Software Capitalino'],
    ];

    private const CONTACTS = ['Humberto Rafael Paiz Ruano', 'Esperanza Lucía Monroy de León', 'Julio Roberto Aldana Pérez', 'Johana Beatriz Lima Castañeda', 'Manuel Antonio Sagastume Ruiz',
        'Doña Esperanza Gómez de Pineda', 'Rafael Ernesto Monterroso Solís', 'Marisol Liliana Ayala Peralta', 'Alexander Marvin Ramos Castillo', 'Rolando Edgar Coc Sabán', 'Gloria Ana Castillo Arzú', 'Josué Kevin Barrios Mejía'];

    /** @return list<Company> */
    public static function companies(): array
    {
        $banks = ['Banco Industrial', 'Banrural', 'Banco Agromercantil', 'Banco G&T Continental'];
        $assets = ['construccion' => ['Retroexcavadora Caterpillar 416', 'machinery', '504000.00', 96], 'industrial' => ['Horno industrial rotativo de 18 bandejas', 'machinery', '168000.00', 120]];
        $capital = ['arrendamiento' => 300000, 'distribuidora' => 160000, 'comercio' => 80000, 'industrial' => 150000, 'construccion' => 250000, 'logistica' => 180000, 'turismo' => 120000, 'transporte' => 140000];
        $companies = [];
        $sequence = 0;
        foreach (self::offices() as $officeIndex => $office) {
            foreach (self::SECTOR_ORDER as $position => $sector) {
                $name = self::NAMES[$sector][$officeIndex];
                $sequence++;
                $base = 2100000 + $sequence * 73129 % 7800000;
                $taxId = $base.'-'.($base % 9);
                $trade = trim(str_replace([', S.A.', ' S.A.'], '', $name));
                $full = $position < 4;
                $companies[] = [
                    'tax_id' => $taxId, 'name' => $name, 'trade_name' => $trade, 'office' => $office['code'], 'sector' => $sector === 'tecnologia' ? 'servicios' : $sector,
                    'address' => (($position % 3) + 2).'a. Calle '.(($sequence * 7) % 20 + 1).'-'.(($sequence * 13) % 90 + 10).', zona '.(($position % 4) + 1).', '.$office['city'].', '.$office['state'],
                    'phone' => '+502 '.(7900 + $officeIndex * 10 + $position).' '.sprintf('%04d', ($sequence * 379) % 10000),
                    'email' => 'contabilidad@'.Str::slug($trade).'.test', 'contact' => self::CONTACTS[$sequence % count(self::CONTACTS)],
                    'capital' => number_format($capital[$sector] ?? 60000, 2, '.', ''), 'series' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($trade)) ?? 'EMP', 0, 3)).$officeIndex,
                    'scale' => 0.6 + (($sequence * 3) % 10) / 10, 'bank' => [$banks[$sequence % 4], 'Cuenta monetaria '.$trade, sprintf('%04d', ($sequence * 1777) % 10000)],
                    'payroll' => $full && in_array($sector, ['distribuidora', 'industrial'], true), 'asset' => $full ? ($assets[$sector] ?? null) : null,
                    'production' => $full && $sector === 'industrial', 'full' => $full,
                ];
            }
        }

        return $companies;
    }

    /**
     * Grupos empresariales: nombre, controladora y subsidiarias con su participación en puntos base (por nombre de empresa).
     *
     * @return list<array{name: string, parent: string, members: list<array{0: string, 1: int}>}>
     */
    public static function groups(): array
    {
        return [
            ['name' => 'Grupo Empresarial del Atlántico', 'parent' => 'Inversiones Puerto del Sol, S.A.', 'members' => [['Distribuidora El Progreso, S.A.', 10000], ['Comercializadora del Caribe', 8000]]],
            ['name' => 'Corporación Comercial del Caribe', 'parent' => 'Inversiones Santo Tomás, S.A.', 'members' => [['Agroindustrias del Atlántico, S.A.', 10000], ['Comercial La Esperanza', 6000]]],
            ['name' => 'Grupo de Inversiones del Norte', 'parent' => 'Inversiones Verapaz, S.A.', 'members' => [['Café y Cardamomo de Cobán, S.A.', 10000], ['Comercial El Quetzal Verapacense', 7500]]],
        ];
    }

    /**
     * Operaciones mensuales entre empresas de cada grupo: [vendedor, comprador, día, descripción, total con IVA, tratamiento,
     * cuenta de gasto del comprador]. Se registran con el nombre y NIT exactos de la contraparte.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string, 5: string, 6: string}>
     */
    public static function intercompany(): array
    {
        $rows = [];
        foreach (self::groups() as $group) {
            [$first, $second] = [$group['members'][0][0], $group['members'][1][0]];
            $rows[] = [$group['parent'], $first, 2, 'Arrendamiento mensual de bodega', '11200.00', 'services', '5203'];
            $rows[] = [$group['parent'], $second, 2, 'Arrendamiento mensual de local comercial', '6720.00', 'services', '5203'];
            $rows[] = [$first, $second, 18, 'Venta de mercadería para reventa', '12320.00', 'goods', '5103'];
        }

        return $rows;
    }

    /** Empresas con contacto del cliente en el portal: las dos primeras de cada oficina. @return list<string> */
    public static function portalCompanies(): array
    {
        $names = [];
        foreach (array_keys(self::offices()) as $officeIndex) {
            $names[] = self::NAMES['arrendamiento'][$officeIndex];
            $names[] = self::NAMES['distribuidora'][$officeIndex];
        }

        return $names;
    }
}
