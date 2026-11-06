<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Datos ficticios del escenario de demostración del Plan Multi-oficina: la firma «Consultores Contables del Atlántico»
 * con cuatro sedes en Izabal, su equipo de 21 personas y 32 empresas cliente de distintos sectores. Nombres, NIT, DPI,
 * direcciones, teléfonos y montos son inventados; los correos usan el dominio reservado .test.
 *
 * Cada empresa toma las operaciones de su sector (clientes, proveedores, ventas y compras mensuales) y las escala con su
 * propio factor, para que ninguna tenga las mismas cifras.
 *
 * Ventas: [cliente, día, líneas [descripción, cantidad, precio con IVA, tratamiento, código de producto|null], al contado].
 * Compras: [proveedor, día, cuenta, tratamiento, descripción, total con IVA, al contado, código de producto|null, cantidad].
 *
 * @phpstan-type SaleLine array{0: string, 1: int, 2: string, 3: string, 4: string|null}
 * @phpstan-type Sale array{0: string, 1: int, 2: list<SaleLine>, 3: bool}
 * @phpstan-type Purchase array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string, 6: bool, 7: string|null, 8: int}
 * @phpstan-type Item array{0: string, 1: string, 2: string, 3: string, 4: int}
 * @phpstan-type Sector array{revenue: string, customers: list<array{0: string, 1: string, 2: string|null, 3: int}>, suppliers: list<array{0: string, 1: string, 2: string, 3: string, 4: int}>, sales: list<Sale>, purchases: list<Purchase>, items: list<Item>}
 * @phpstan-type Company array{tax_id: string, name: string, trade_name: string, office: string, sector: string, address: string, phone: string, email: string, contact: string, capital: string, series: string, scale: float, bank: array{0: string, 1: string, 2: string}, payroll: bool, asset: array{0: string, 1: string, 2: string, 3: int}|null, production: bool}
 */
final class PlanMultiOficinaDemoCatalog
{
    public const ORGANIZATION_TAX_ID = '5560987-2';

    public const DOMAIN = 'consultoresdelatlantico.test';

    public const GROUP_NAME = 'Grupo Empresarial del Atlántico';

    /** @return array{name: string, legal_name: string} */
    public static function firm(): array
    {
        return ['name' => 'Consultores Contables del Atlántico', 'legal_name' => 'Consultores Contables del Atlántico, S.A.'];
    }

    /**
     * Las cuatro sedes: código, nombre, dirección, ciudad, teléfono, si es la principal y sus días de anticipación fiscal
     * (preparación interna y revisión).
     *
     * @return list<array{code: string, name: string, address: string, city: string, phone: string, primary: bool, internal_days: int, review_days: int}>
     */
    public static function offices(): array
    {
        return [
            ['code' => 'CEN-01', 'name' => 'Oficina Central', 'address' => '8a. Calle entre 6a. y 7a. Avenida, Barrio El Centro', 'city' => 'Puerto Barrios', 'phone' => '+502 7948 1200', 'primary' => true, 'internal_days' => 5, 'review_days' => 3],
            ['code' => 'MOR-01', 'name' => 'Oficina Morales', 'address' => 'Barrio El Centro, frente al parque central', 'city' => 'Morales', 'phone' => '+502 7947 2400', 'primary' => false, 'internal_days' => 4, 'review_days' => 2],
            ['code' => 'STC-01', 'name' => 'Oficina Santo Tomás', 'address' => 'Avenida Principal, Zona Portuaria, local 12', 'city' => 'Santo Tomás de Castilla', 'phone' => '+502 7948 3600', 'primary' => false, 'internal_days' => 4, 'review_days' => 2],
            ['code' => 'LIV-01', 'name' => 'Oficina Livingston', 'address' => 'Calle Principal, Barrio Pueblo Nuevo', 'city' => 'Livingston', 'phone' => '+502 7947 0800', 'primary' => false, 'internal_days' => 6, 'review_days' => 3],
        ];
    }

    /**
     * El equipo: clave interna, nombre, usuario de correo, rol (código real de ROLE_PRESETS) y oficinas donde trabaja
     * (null = toda la firma). El Administrador General se incorpora al final, cuando la firma ya está en marcha.
     *
     * @return list<array{key: string, name: string, email: string, role: string, offices: list<string>|null}>
     */
    public static function team(): array
    {
        return [
            ['key' => 'owner', 'name' => 'Roberto Carlos Sagastume Pineda', 'email' => 'roberto.sagastume', 'role' => 'owner_director', 'offices' => null],
            ['key' => 'manager', 'name' => 'Pedro Antonio García Ramírez', 'email' => 'pedro.garcia', 'role' => 'accounting_manager', 'offices' => null],
            ['key' => 'tax', 'name' => 'Gabriela Alejandra Solís Monterroso', 'email' => 'gabriela.solis', 'role' => 'tax_specialist', 'offices' => null],
            ['key' => 'treasury', 'name' => 'Óscar René Villeda Cardona', 'email' => 'oscar.villeda', 'role' => 'treasury', 'offices' => null],
            ['key' => 'receivables', 'name' => 'Marta Lidia Bol Caal', 'email' => 'marta.bol', 'role' => 'accounts_receivable', 'offices' => null],
            ['key' => 'payables', 'name' => 'Edwin Rolando Pérez Chacón', 'email' => 'edwin.perez', 'role' => 'accounts_payable', 'offices' => null],
            ['key' => 'payroll', 'name' => 'Karla Beatriz Ordóñez Chacón', 'email' => 'karla.ordonez', 'role' => 'payroll', 'offices' => null],
            ['key' => 'auditor', 'name' => 'Sofía Isabel López Hernández', 'email' => 'sofia.lopez', 'role' => 'auditor', 'offices' => null],
            ['key' => 'readonly', 'name' => 'Héctor Manuel Juárez Cifuentes', 'email' => 'hector.juarez', 'role' => 'read_only', 'offices' => ['MOR-01']],
            ['key' => 'admin-cen', 'name' => 'Luis Fernando Morales González', 'email' => 'luis.morales', 'role' => 'office_admin', 'offices' => ['CEN-01']],
            ['key' => 'admin-mor', 'name' => 'Rosa María Cruz de León', 'email' => 'rosa.cruz', 'role' => 'office_admin', 'offices' => ['MOR-01']],
            ['key' => 'general', 'name' => 'Ana Lucía Hernández Pérez', 'email' => 'ana.hernandez', 'role' => 'general_accountant', 'offices' => ['CEN-01', 'STC-01']],
            ['key' => 'supervisor-cen', 'name' => 'Jorge Alberto Cardona Villeda', 'email' => 'jorge.cardona', 'role' => 'accounting_supervisor', 'offices' => ['CEN-01', 'LIV-01']],
            ['key' => 'supervisor-mor', 'name' => 'Claudia Patricia Morales Pérez', 'email' => 'claudia.morales', 'role' => 'accounting_supervisor', 'offices' => ['MOR-01', 'STC-01']],
            ['key' => 'accountant-cen', 'name' => 'Carlos Eduardo Méndez García', 'email' => 'carlos.mendez', 'role' => 'accountant_in_charge', 'offices' => ['CEN-01']],
            ['key' => 'accountant-mor', 'name' => 'María Fernanda Castillo Morales', 'email' => 'maria.castillo', 'role' => 'accountant_in_charge', 'offices' => ['MOR-01']],
            ['key' => 'accountant-stc', 'name' => 'José Alejandro Ramírez López', 'email' => 'jose.ramirez', 'role' => 'accountant_in_charge', 'offices' => ['STC-01']],
            ['key' => 'accountant-liv', 'name' => 'Brenda Lisseth Arana Fuentes', 'email' => 'brenda.arana', 'role' => 'accountant_in_charge', 'offices' => ['LIV-01']],
            ['key' => 'assistant-cen', 'name' => 'Andrea Valeska Pineda Ruiz', 'email' => 'andrea.pineda', 'role' => 'accounting_assistant', 'offices' => ['CEN-01']],
            ['key' => 'assistant-mor', 'name' => 'Josué Daniel Tzul Gómez', 'email' => 'josue.tzul', 'role' => 'accounting_assistant', 'offices' => ['MOR-01']],
            ['key' => 'admin-general', 'name' => 'Silvia Marisol Ochoa Barrios', 'email' => 'silvia.ochoa', 'role' => 'office_admin', 'offices' => null],
        ];
    }

    /**
     * Quién atiende cada sede: contador encargado, auxiliar, supervisor y contador general (si tiene).
     *
     * @return array<string, array{accountant: string, assistant: string|null, supervisor: string, general: string|null}>
     */
    public static function officeStaff(): array
    {
        return [
            'CEN-01' => ['accountant' => 'accountant-cen', 'assistant' => 'assistant-cen', 'supervisor' => 'supervisor-cen', 'general' => 'general'],
            'MOR-01' => ['accountant' => 'accountant-mor', 'assistant' => 'assistant-mor', 'supervisor' => 'supervisor-mor', 'general' => null],
            'STC-01' => ['accountant' => 'accountant-stc', 'assistant' => null, 'supervisor' => 'supervisor-mor', 'general' => 'general'],
            'LIV-01' => ['accountant' => 'accountant-liv', 'assistant' => null, 'supervisor' => 'supervisor-cen', 'general' => null],
        ];
    }

    /**
     * Grupo empresarial: la controladora y sus subsidiarias con su participación (en puntos base). Las operaciones entre
     * ellas se registran con los NIT exactos de cada empresa, que es como el consolidado las identifica.
     *
     * @return array{parent: string, members: list<array{0: string, 1: int}>}
     */
    public static function group(): array
    {
        return ['parent' => '7364821-9', 'members' => [['2190834-6', 10000], ['3481927-5', 8000], ['4720195-3', 7000]]];
    }

    /**
     * Operaciones mensuales entre empresas del grupo: [NIT del vendedor, NIT del comprador, día, descripción, total con IVA,
     * tratamiento, cuenta de gasto del comprador].
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string, 5: string, 6: string}>
     */
    public static function intercompany(): array
    {
        return [
            ['7364821-9', '2190834-6', 2, 'Arrendamiento mensual de bodega central', '11200.00', 'services', '5203'],
            ['7364821-9', '3481927-5', 2, 'Arrendamiento mensual de local comercial', '6720.00', 'services', '5203'],
            ['4720195-3', '2190834-6', 15, 'Fletes de distribución a Petén y Alta Verapaz', '8960.00', 'services', '5201'],
            ['2190834-6', '3481927-5', 18, 'Venta de abarrotes para reventa', '12320.00', 'goods', '5103'],
        ];
    }

    /** @return array<string, Sector> */
    public static function sectors(): array
    {
        $energy = ['Empresa Eléctrica Municipal de Puerto Barrios', '8523697-1', 'facturacion@eempb.test', '+502 7948 0101', 10];
        $phone = ['Telecomunicaciones del Atlántico, S.A.', '4569871-3', 'clientes@telatlantico.test', '+502 7948 7788', 10];
        $rent = ['Arrendamientos y Bienes Raíces del Caribe', '1597534-8', 'cobros@bienesraicescaribe.test', '+502 7948 5566', 0];
        $lawyers = ['Bufete Jurídico Hernández & Asociados', '5293846-7', 'bufete@hernandezasociados.test', '+502 7948 1001', 15];
        $fuel = ['Combustibles y Lubricantes del Puerto', '9638527-0', 'ventas@combupuerto.test', '+502 7948 6060', 0];

        return [
            'comercio' => [
                'revenue' => '4201',
                'customers' => [['Tienda Doña Chepita', '2468135-7', 'compras@donachepita.test', 15], ['Supermercado La Bahía, S.A.', '7531594-2', 'proveeduria@labahia.test', 30], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [['Distribuidora de Granos del Norte, S.A.', '3579512-6', 'ventas@granosdelnorte.test', '+502 7952 3344', 30], $rent, $energy],
                'sales' => [
                    ['Tienda Doña Chepita', 6, [['Arroz blanco, quintal', 10, '425.00', 'goods', 'ARROZ-QQ'], ['Frijol negro, quintal', 6, '610.00', 'goods', 'FRIJOL-QQ']], false],
                    ['Supermercado La Bahía, S.A.', 14, [['Azúcar blanca, fardo de 25 lb', 30, '118.00', 'goods', 'AZUCAR-FD']], false],
                    ['Consumidor final', 27, [['Ventas de mostrador del mes', 1, '9850.00', 'goods', null]], true],
                ],
                'purchases' => [
                    ['Arrendamientos y Bienes Raíces del Caribe', 1, '5203', 'services', 'Alquiler del local comercial', '5600.00', false, null, 0],
                    ['Distribuidora de Granos del Norte, S.A.', 3, '1201', 'goods', 'Compra de arroz para reventa', '6720.00', false, 'ARROZ-QQ', 20],
                    ['Distribuidora de Granos del Norte, S.A.', 4, '1201', 'goods', 'Compra de frijol para reventa', '5880.00', false, 'FRIJOL-QQ', 12],
                    ['Distribuidora de Granos del Norte, S.A.', 5, '1201', 'goods', 'Compra de azúcar para reventa', '2464.00', false, 'AZUCAR-FD', 28],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica del local', '1120.00', true, null, 0],
                ],
                'items' => [['ARROZ-QQ', 'Arroz blanco, quintal', 'quintal', '300.00', 40], ['FRIJOL-QQ', 'Frijol negro, quintal', 'quintal', '437.50', 25], ['AZUCAR-FD', 'Azúcar blanca, fardo de 25 lb', 'fardo', '78.57', 60]],
            ],
            'ferreteria' => [
                'revenue' => '4201',
                'customers' => [['Constructora Río Motagua, S.A.', '5432167-8', 'compras@riomotagua.test', 30], ['Municipalidad de Morales', '3698521-4', 'tesoreria@munimorales.test', 30], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [['Cementos y Agregados de Oriente, S.A.', '8529637-4', 'ventas@cementosoriente.test', '+502 7941 8800', 30], ['Hierros y Aceros de Guatemala, S.A.', '9517534-2', 'creditos@hierrosgt.test', '+502 2380 4400', 30], $rent, $phone],
                'sales' => [
                    ['Constructora Río Motagua, S.A.', 6, [['Cemento gris, saco de 42.5 kg', 80, '86.00', 'goods', 'CEM-42'], ['Hierro corrugado 3/8, varilla', 150, '38.50', 'goods', 'HIE-38']], false],
                    ['Municipalidad de Morales', 13, [['Tubería PVC de 4 pulgadas, tubo de 6 m', 30, '185.00', 'goods', 'PVC-4']], false],
                    ['Consumidor final', 29, [['Ventas de mostrador del mes', 1, '12450.00', 'goods', null]], true],
                ],
                'purchases' => [
                    ['Arrendamientos y Bienes Raíces del Caribe', 1, '5203', 'services', 'Alquiler del local de la ferretería', '5600.00', false, null, 0],
                    ['Cementos y Agregados de Oriente, S.A.', 3, '1201', 'goods', 'Compra de cemento gris', '6720.00', false, 'CEM-42', 100],
                    ['Hierros y Aceros de Guatemala, S.A.', 4, '1201', 'goods', 'Compra de hierro corrugado', '4704.00', false, 'HIE-38', 168],
                    ['Hierros y Aceros de Guatemala, S.A.', 5, '1201', 'goods', 'Compra de tubería PVC', '4032.00', false, 'PVC-4', 36],
                    ['Telecomunicaciones del Atlántico, S.A.', 14, '5204', 'services', 'Servicio de internet y telefonía', '448.00', true, null, 0],
                ],
                'items' => [['CEM-42', 'Cemento gris, saco de 42.5 kg', 'saco', '60.00', 120], ['HIE-38', 'Hierro corrugado 3/8, varilla', 'varilla', '25.00', 250], ['PVC-4', 'Tubería PVC de 4 pulgadas', 'tubo', '100.00', 40]],
            ],
            'distribuidora' => [
                'revenue' => '4201',
                'customers' => [['Abarrotería San Antonio', '6547893-1', 'sanantonio@abarroteria.test', 15], ['Hotel Valle Tropical', '3216987-4', 'compras@valletropical.test', 30], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [['Embotelladora del Caribe, S.A.', '2581473-9', 'pedidos@embotelladoracaribe.test', '+502 2420 5000', 30], $fuel, $energy],
                'sales' => [
                    ['Abarrotería San Antonio', 4, [['Agua pura, fardo de 24 unidades', 60, '42.00', 'goods', 'AGUA-24'], ['Refresco de 600 ml, caja de 24', 30, '118.00', 'goods', 'REF-24']], false],
                    ['Hotel Valle Tropical', 10, [['Jugos naturales, caja de 12', 25, '96.00', 'goods', 'JUGO-12']], false],
                    ['Consumidor final', 28, [['Ventas en ruta del mes', 1, '11200.00', 'goods', null]], true],
                ],
                'purchases' => [
                    ['Embotelladora del Caribe, S.A.', 2, '1201', 'goods', 'Compra de agua pura', '2016.00', false, 'AGUA-24', 72],
                    ['Embotelladora del Caribe, S.A.', 2, '1201', 'goods', 'Compra de refrescos', '2688.00', false, 'REF-24', 32],
                    ['Embotelladora del Caribe, S.A.', 3, '1201', 'goods', 'Compra de jugos naturales', '1792.00', false, 'JUGO-12', 28],
                    ['Combustibles y Lubricantes del Puerto', 7, '5201', 'goods', 'Combustible para camiones repartidores', '3920.00', true, null, 0],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de bodega refrigerada', '2688.00', false, null, 0],
                ],
                'items' => [['AGUA-24', 'Agua pura, fardo de 24 unidades', 'fardo', '25.00', 100], ['REF-24', 'Refresco de 600 ml, caja de 24', 'caja', '75.00', 50], ['JUGO-12', 'Jugos naturales, caja de 12', 'caja', '57.14', 40]],
            ],
            'industrial' => [
                'revenue' => '4201',
                'customers' => [['Supermercado La Bahía, S.A.', '7531594-2', 'proveeduria@labahia.test', 30], ['Cafetería El Portal', '5566778-1', 'compras@elportal.test', 15], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [['Molinos de Harina del Sur, S.A.', '6677889-0', 'ventas@molinosdelsur.test', '+502 2410 3300', 30], ['Azucarera El Trapiche', '3344112-9', 'pedidos@eltrapiche.test', '+502 2385 1200', 15], $energy],
                'sales' => [
                    ['Supermercado La Bahía, S.A.', 8, [['Pan de molde empacado', 300, '24.50', 'goods', 'PAN-MOLDE']], false],
                    ['Cafetería El Portal', 16, [['Pan de molde empacado', 120, '24.50', 'goods', 'PAN-MOLDE']], false],
                    ['Consumidor final', 28, [['Ventas de la tienda de fábrica', 1, '8400.00', 'goods', null]], true],
                ],
                'purchases' => [
                    ['Molinos de Harina del Sur, S.A.', 2, '1201', 'goods', 'Compra de harina de trigo', '8960.00', false, 'HARINA-QQ', 32],
                    ['Azucarera El Trapiche', 3, '1201', 'goods', 'Compra de azúcar para producción', '2240.00', false, 'AZUCAR-QQ', 8],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de hornos industriales', '4480.00', false, null, 0],
                ],
                'items' => [['HARINA-QQ', 'Harina de trigo, quintal', 'quintal', '250.00', 40], ['AZUCAR-QQ', 'Azúcar, quintal', 'quintal', '250.00', 10], ['PAN-MOLDE', 'Pan de molde empacado', 'unidad', '12.00', 500]],
            ],
            'restaurante' => [
                'revenue' => '4201',
                'customers' => [['Agencia de Viajes Río Dulce Tours', '3847261-5', 'reservaciones@riodulcetours.test', 15], ['Asociación Garífuna de Livingston', '7261938-4', 'asociacion@garifunalivingston.test', 15], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [['Pescadores Unidos de la Bahía', '5829164-7', 'ventas@pescadoresbahia.test', '+502 5510 2233', 0], ['Gas Propano del Atlántico', '2918374-6', 'pedidos@gaspropano.test', '+502 7948 8800', 10], $rent],
                'sales' => [
                    ['Agencia de Viajes Río Dulce Tours', 12, [['Almuerzos para grupo de turistas', 40, '95.00', 'goods', null]], false],
                    ['Asociación Garífuna de Livingston', 19, [['Alimentación para evento cultural', 1, '6500.00', 'goods', null]], false],
                    ['Consumidor final', 30, [['Ventas del restaurante del mes', 1, '22400.00', 'goods', null]], true],
                ],
                'purchases' => [
                    ['Arrendamientos y Bienes Raíces del Caribe', 1, '5203', 'services', 'Alquiler del local del restaurante', '4480.00', false, null, 0],
                    ['Pescadores Unidos de la Bahía', 4, '5103', 'goods', 'Compra de pescado y mariscos frescos', '7840.00', true, null, 0],
                    ['Gas Propano del Atlántico', 13, '5204', 'goods', 'Gas propano para cocina', '1120.00', false, null, 0],
                ],
                'items' => [],
            ],
            'transporte' => [
                'revenue' => '4101',
                'customers' => [['Agroexportadora Bananera del Motagua, S.A.', '8642097-5', 'logistica@bananeramotagua.test', 30], ['Naviera Caribe Express, S.A.', '2097531-8', 'operaciones@caribeexpress.test', 30], ['Importadora Centroamericana de Repuestos', '6420975-3', 'compras@icrepuestos.test', 15]],
                'suppliers' => [$fuel, ['Llantas y Servicios del Atlántico', '7531598-2', 'ventas@llantasatlantico.test', '+502 7948 9911', 30], ['Seguros del Istmo, S.A.', '4826157-3', 'cobranza@segurosistmo.test', '+502 2328 0000', 15]],
                'sales' => [
                    ['Agroexportadora Bananera del Motagua, S.A.', 7, [['Flete de contenedor refrigerado Morales - Santo Tomás', 10, '2350.00', 'services', null]], false],
                    ['Naviera Caribe Express, S.A.', 14, [['Traslado de contenedores dentro del puerto', 15, '980.00', 'services', null]], false],
                    ['Importadora Centroamericana de Repuestos', 21, [['Flete Santo Tomás - Ciudad de Guatemala', 3, '4200.00', 'services', null]], true],
                ],
                'purchases' => [
                    ['Combustibles y Lubricantes del Puerto', 5, '5201', 'goods', 'Combustible diésel para la flotilla', '13440.00', true, null, 0],
                    ['Llantas y Servicios del Atlántico', 11, '5201', 'goods', 'Llantas para cabezal', '6160.00', false, null, 0],
                    ['Seguros del Istmo, S.A.', 16, '5201', 'exempt', 'Prima mensual del seguro de flotilla', '3150.00', false, null, 0],
                ],
                'items' => [],
            ],
            'logistica' => [
                'revenue' => '4101',
                'customers' => [['Naviera Caribe Express, S.A.', '2097531-8', 'operaciones@caribeexpress.test', 30], ['Importadora Centroamericana de Repuestos', '6420975-3', 'compras@icrepuestos.test', 15], ['Exportadora de Café de Altura, S.A.', '1029384-7', 'logistica@cafedealtura.test', 30]],
                'suppliers' => [$fuel, ['Montacargas y Equipos del Puerto', '3829105-6', 'servicio@montacargaspuerto.test', '+502 7948 4321', 15], $energy, $phone],
                'sales' => [
                    ['Naviera Caribe Express, S.A.', 5, [['Almacenaje de contenedores, día', 120, '85.00', 'services', null]], false],
                    ['Importadora Centroamericana de Repuestos', 12, [['Consolidación de carga y estiba', 4, '1850.00', 'services', null]], false],
                    ['Exportadora de Café de Altura, S.A.', 20, [['Servicio logístico de exportación', 2, '4480.00', 'services', null]], false],
                ],
                'purchases' => [
                    ['Combustibles y Lubricantes del Puerto', 6, '5201', 'goods', 'Combustible para montacargas', '3360.00', true, null, 0],
                    ['Montacargas y Equipos del Puerto', 10, '5201', 'services', 'Mantenimiento de montacargas', '2800.00', false, null, 0],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de bodegas', '3360.00', false, null, 0],
                    ['Telecomunicaciones del Atlántico, S.A.', 14, '5204', 'services', 'Internet dedicado y telefonía', '896.00', false, null, 0],
                ],
                'items' => [],
            ],
            'servicios' => [
                'revenue' => '4101',
                'customers' => [['Aseguradora La Ceiba, S.A.', '2758194-6', 'reclamos@aseguradoralaceiba.test', 30], ['Cooperativa Integral Santo Tomás, R.L.', '9517538-4', 'cooperativa@santotomas.test', 30], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [$rent, $lawyers, $phone],
                'sales' => [
                    ['Aseguradora La Ceiba, S.A.', 9, [['Servicios profesionales del mes', 1, '8960.00', 'services', null]], false],
                    ['Cooperativa Integral Santo Tomás, R.L.', 15, [['Asesoría administrativa y contable', 1, '6720.00', 'services', null]], false],
                    ['Consumidor final', 28, [['Servicios a particulares del mes', 1, '5600.00', 'services', null]], true],
                ],
                'purchases' => [
                    ['Arrendamientos y Bienes Raíces del Caribe', 1, '5203', 'services', 'Alquiler de oficina', '3920.00', false, null, 0],
                    ['Bufete Jurídico Hernández & Asociados', 10, '5208', 'services', 'Honorarios por asesoría legal', '2800.00', false, null, 0],
                    ['Telecomunicaciones del Atlántico, S.A.', 14, '5204', 'services', 'Servicio de internet y telefonía', '560.00', true, null, 0],
                ],
                'items' => [],
            ],
            'construccion' => [
                'revenue' => '4101',
                'customers' => [['Municipalidad de Puerto Barrios', '1357902-4', 'obras@munipuertobarrios.test', 30], ['Empresa Portuaria Santo Tomás de Castilla', '1948276-3', 'proyectos@puertostc.test', 30], ['Residenciales Brisas del Caribe, S.A.', '8024613-9', 'gerencia@brisasdelcaribe.test', 30]],
                'suppliers' => [['Cementos y Agregados de Oriente, S.A.', '8529637-4', 'ventas@cementosoriente.test', '+502 7941 8800', 30], ['Alquiler de Maquinaria Pesada del Norte', '6293817-0', 'renta@maquinarianorte.test', '+502 7952 6600', 15], $lawyers],
                'sales' => [
                    ['Municipalidad de Puerto Barrios', 10, [['Estimación de avance: pavimentación de calles', 1, '42000.00', 'services', null]], false],
                    ['Empresa Portuaria Santo Tomás de Castilla', 17, [['Reparación de muelle, estimación mensual', 1, '33600.00', 'services', null]], false],
                    ['Residenciales Brisas del Caribe, S.A.', 24, [['Construcción de viviendas, avance de obra', 1, '28000.00', 'services', null]], false],
                ],
                'purchases' => [
                    ['Cementos y Agregados de Oriente, S.A.', 4, '5101', 'goods', 'Materiales de construcción para obras', '33600.00', false, null, 0],
                    ['Alquiler de Maquinaria Pesada del Norte', 8, '5201', 'services', 'Alquiler de retroexcavadora y vibrocompactadora', '14560.00', false, null, 0],
                    ['Bufete Jurídico Hernández & Asociados', 20, '5208', 'services', 'Honorarios por contratos de obra', '3360.00', true, null, 0],
                ],
                'items' => [],
            ],
            'turismo' => [
                'revenue' => '4101',
                'customers' => [['Agencia de Viajes Río Dulce Tours', '3847261-5', 'reservaciones@riodulcetours.test', 15], ['Operadora de Turismo Maya Caribe', '4916283-7', 'reservas@mayacaribe.test', 30], ['Consumidor final', 'CF', null, 0]],
                'suppliers' => [['Lavandería Industrial del Golfete', '7163829-4', 'servicio@lavanderiagolfete.test', '+502 7930 1122', 15], $energy, ['Pescadores Unidos de la Bahía', '5829164-7', 'ventas@pescadoresbahia.test', '+502 5510 2233', 0]],
                'sales' => [
                    ['Agencia de Viajes Río Dulce Tours', 8, [['Paquete turístico de dos noches', 12, '1450.00', 'services', null]], false],
                    ['Operadora de Turismo Maya Caribe', 16, [['Tour en lancha a Siete Altares', 30, '275.00', 'services', null]], false],
                    ['Consumidor final', 29, [['Hospedaje y tours a particulares del mes', 1, '15680.00', 'services', null]], true],
                ],
                'purchases' => [
                    ['Lavandería Industrial del Golfete', 6, '5201', 'services', 'Lavandería de blancos y toallas', '1680.00', false, null, 0],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de las instalaciones', '2240.00', false, null, 0],
                    ['Pescadores Unidos de la Bahía', 15, '5103', 'goods', 'Insumos para el restaurante del hotel', '3360.00', true, null, 0],
                ],
                'items' => [],
            ],
            'arrendamiento' => [
                'revenue' => '4101',
                'customers' => [['Farmacia La Salud de Izabal', '4862039-7', 'gerencia@farmacialasalud.test', 5], ['Óptica Visión Caribe', '2039486-1', 'opticavision@caribe.test', 5]],
                'suppliers' => [['Servicios de Limpieza Brillo Total', '8402619-3', 'contacto@brillototal.test', '+502 7948 2244', 15], ['Seguridad Integral del Caribe, S.A.', '3946128-0', 'facturacion@seguridadcaribe.test', '+502 7948 3355', 15], $lawyers],
                'sales' => [
                    ['Farmacia La Salud de Izabal', 2, [['Arrendamiento mensual de local comercial No. 3', 1, '7840.00', 'services', null]], false],
                    ['Óptica Visión Caribe', 2, [['Arrendamiento mensual de local comercial No. 5', 1, '5600.00', 'services', null]], false],
                ],
                'purchases' => [
                    ['Servicios de Limpieza Brillo Total', 5, '5201', 'services', 'Limpieza de áreas comunes', '3360.00', false, null, 0],
                    ['Seguridad Integral del Caribe, S.A.', 5, '5201', 'services', 'Servicio de seguridad privada', '8960.00', false, null, 0],
                    ['Bufete Jurídico Hernández & Asociados', 20, '5208', 'services', 'Honorarios por renovación de contratos', '2800.00', true, null, 0],
                ],
                'items' => [],
            ],
        ];
    }

    /** @return list<Company> */
    public static function companies(): array
    {
        $bi = 'Banco Industrial';
        $br = 'Banrural';
        $bam = 'Banco Agromercantil';
        $gyt = 'Banco G&T Continental';
        $company = fn (string $taxId, string $name, string $trade, string $office, string $sector, string $address, string $phone, string $email, string $contact, string $capital, string $series, float $scale, string $bank, string $lastFour, bool $payroll = false, ?array $asset = null, bool $production = false): array => [
            'tax_id' => $taxId, 'name' => $name, 'trade_name' => $trade, 'office' => $office, 'sector' => $sector, 'address' => $address, 'phone' => $phone, 'email' => $email,
            'contact' => $contact, 'capital' => $capital, 'series' => $series, 'scale' => $scale, 'bank' => [$bank, 'Cuenta monetaria '.$trade, $lastFour], 'payroll' => $payroll,
            'asset' => $asset === null ? null : [(string) $asset[0], (string) $asset[1], (string) $asset[2], (int) $asset[3]], 'production' => $production,
        ];

        return [
            // Oficina Central — Puerto Barrios
            $company('7364821-9', 'Inversiones Puerto del Sol, S.A.', 'Inversiones Puerto del Sol', 'CEN-01', 'arrendamiento', '13 Calle entre 6a. y 7a. Avenida, Puerto Barrios, Izabal', '+502 7948 6677', 'administracion@puertodelsol.test', 'Ernesto Rafael Solís Monterroso', '350000.00', 'IPS', 1.6, $gyt, '6134'),
            $company('2190834-6', 'Distribuidora El Progreso, S.A.', 'Distribuidora El Progreso', 'CEN-01', 'distribuidora', 'Kilómetro 295 Carretera al Atlántico, Puerto Barrios, Izabal', '+502 7948 1122', 'contabilidad@distelprogreso.test', 'Carlos Humberto Paiz Ruano', '180000.00', 'DEP', 1.5, $bi, '4471', true),
            $company('3481927-5', 'Comercializadora del Caribe, S.A.', 'Comercializadora del Caribe', 'CEN-01', 'comercio', '7a. Calle y 8a. Avenida, Barrio El Progreso, Puerto Barrios, Izabal', '+502 7948 2290', 'finanzas@comcaribe.test', 'Lilian Esperanza Monroy Paz', '120000.00', 'CDC', 1.3, $bi, '5820', true),
            $company('4718293-5', 'Ferretería San José', 'Ferretería San José', 'CEN-01', 'ferreteria', '9a. Avenida y 18 Calle, Barrio San Agustín, Puerto Barrios, Izabal', '+502 7948 3388', 'ferreteria@sanjose-pb.test', 'Luis Alberto Reyes Mazariegos', '85000.00', 'FSJ', 1.0, $bam, '7310', true),
            $company('6018273-4', 'Servicios Administrativos de Izabal', 'Servicios Administrativos de Izabal', 'CEN-01', 'servicios', '5a. Avenida y 11 Calle, Edificio Caribe, oficina 3, Puerto Barrios, Izabal', '+502 7948 4455', 'gerencia@serviciosizabal.test', 'Ingrid Johana Castañeda Lima', '40000.00', 'SAI', 0.9, $bi, '2207'),
            $company('8195273-6', 'Clínica Médica Bahía de Amatique', 'Clínica Bahía de Amatique', 'CEN-01', 'servicios', '5a. Avenida y 15 Calle, Barrio Las Pozas, Puerto Barrios, Izabal', '+502 7948 9090', 'clinica@bahiaamatique.test', 'Dra. Claudia Beatriz Arana Fuentes', '60000.00', 'CBA', 1.4, $bi, '3390'),
            $company('5098271-3', 'Constructora Atlántico Norte, S.A.', 'Constructora Atlántico Norte', 'CEN-01', 'construccion', '20 Calle final, Colonia Las Flores, Puerto Barrios, Izabal', '+502 7948 7021', 'proyectos@atlanticonorte.test', 'Ing. Mario Roberto Chávez Ortiz', '250000.00', 'CAN', 1.0, $gyt, '9013', true, ['Camión de volteo Hino 2024', 'vehicles', '392000.00', 60]),
            $company('9031846-2', 'Cafetería y Pastelería La Esperanza', 'Pastelería La Esperanza', 'CEN-01', 'restaurante', '6a. Avenida entre 12 y 13 Calle, Puerto Barrios, Izabal', '+502 7948 5512', 'pedidos@pastelerialaesperanza.test', 'Doña Esperanza Gómez de Pineda', '30000.00', 'PLE', 0.7, $br, '1458'),
            // Oficina Morales
            $company('2846193-7', 'Comercial La Esperanza', 'Comercial La Esperanza', 'MOR-01', 'comercio', 'Calle principal, Barrio El Mitchal, Morales, Izabal', '+502 7947 1150', 'laesperanza@comercial.test', 'Rosa Amelia Cruz Villatoro', '45000.00', 'CLE', 0.8, $br, '8842'),
            $company('3927164-0', 'Agroservicios El Motagua', 'Agroservicios El Motagua', 'MOR-01', 'distribuidora', 'Kilómetro 245 Carretera al Atlántico, Morales, Izabal', '+502 7947 3366', 'ventas@agromotagua.test', 'Fredy Estuardo Lemus Galdámez', '65000.00', 'AEM', 0.9, $br, '6624'),
            $company('6729184-3', 'Ferretería y Materiales La Bendición', 'Ferretería La Bendición', 'MOR-01', 'ferreteria', 'Barrio Nuevo, frente al mercado municipal, Morales, Izabal', '+502 7947 2288', 'labendicion@ferreteria.test', 'Julio César Ortiz Paz', '75000.00', 'FLB', 0.9, $bam, '3105'),
            $company('8462913-7', 'Transportes Morales Express', 'Morales Express', 'MOR-01', 'transporte', 'Aldea Bananera, entrada principal, Morales, Izabal', '+502 7947 4410', 'operaciones@moralesexpress.test', 'Hugo Leonel Sandoval Tejada', '140000.00', 'TME', 1.1, $bi, '5507', false, ['Cabezal Freightliner modelo 2023', 'vehicles', '588000.00', 60]),
            $company('1827364-9', 'Farmacia San Rafael de Morales', 'Farmacia San Rafael', 'MOR-01', 'comercio', 'Avenida Bananera y 3a. Calle, Morales, Izabal', '+502 7947 1801', 'farmacia@sanrafael.test', 'Lic. Beatriz Adriana Mejía Solórzano', '50000.00', 'FSR', 0.7, $br, '9971'),
            $company('7482910-5', 'Panificadora Industrial del Oriente, S.A.', 'Panificadora del Oriente', 'MOR-01', 'industrial', 'Zona Industrial, lote 7, Morales, Izabal', '+502 7947 5630', 'produccion@panificadoraoriente.test', 'Ing. Rodrigo Andrés Valdez Quiñónez', '160000.00', 'PIO', 1.0, $bi, '2286', true, ['Horno industrial rotativo de 18 bandejas', 'machinery', '168000.00', 120], true),
            $company('2938471-6', 'Servicios Contables y Legales del Motagua', 'Servicios del Motagua', 'MOR-01', 'servicios', '2a. Calle y 4a. Avenida, Barrio El Centro, Morales, Izabal', '+502 7947 2101', 'contacto@serviciosmotagua.test', 'Lic. Fernando José Rivas Duarte', '35000.00', 'SCM', 0.8, $bam, '4430'),
            $company('5602938-1', 'Restaurante El Ranchón de Bananera', 'El Ranchón de Bananera', 'MOR-01', 'restaurante', 'Kilómetro 239 Carretera al Atlántico, Bananera, Morales, Izabal', '+502 7947 6612', 'reservas@elranchon.test', 'Marvin Estuardo Castillo Ramos', '40000.00', 'ERB', 0.9, $br, '7788'),
            // Oficina Santo Tomás
            $company('4720195-3', 'Transportes y Logística del Norte, S.A.', 'Transportes y Logística del Norte', 'STC-01', 'logistica', 'Zona Portuaria, 2a. Calle, Santo Tomás de Castilla, Izabal', '+502 7948 4410', 'finanzas@logisticadelnorte.test', 'Héctor Manuel Sagastume Orellana', '200000.00', 'TLN', 1.3, $bi, '5523', true, ['Montacargas Toyota de 5 toneladas', 'machinery', '235200.00', 96]),
            $company('9283746-1', 'Agencia Aduanal Puerta del Caribe', 'Puerta del Caribe', 'STC-01', 'servicios', 'Edificio Portuario, oficina 4, Santo Tomás de Castilla, Izabal', '+502 7948 5501', 'tramites@puertadelcaribe.test', 'Lic. Óscar Danilo Villeda Paz', '55000.00', 'APC', 1.1, $bi, '9061'),
            $company('6381920-4', 'Almacenadora Portuaria del Atlántico, S.A.', 'Almacenadora del Atlántico', 'STC-01', 'logistica', 'Recinto Portuario, bodega 9, Santo Tomás de Castilla, Izabal', '+502 7948 5590', 'operaciones@almacenadoraatlantico.test', 'Lic. Mynor Estuardo Paredes Cruz', '180000.00', 'APA', 1.2, $gyt, '3302'),
            $company('7192836-0', 'Distribuidora de Combustibles del Puerto', 'Combustibles del Puerto', 'STC-01', 'distribuidora', 'Carretera a Santo Tomás, kilómetro 3, Santo Tomás de Castilla, Izabal', '+502 7948 6061', 'ventas@distcombustibles.test', 'Ronald Eduardo Fuentes Morales', '150000.00', 'DCP', 1.2, $bam, '8017'),
            $company('3061829-7', 'Hotel y Restaurante Bahía Tropical', 'Hotel Bahía Tropical', 'STC-01', 'turismo', 'Playa Santo Tomás, frente al malecón, Santo Tomás de Castilla, Izabal', '+502 7948 7720', 'reservas@bahiatropical.test', 'Liliana Marisol Peralta Ayala', '120000.00', 'HBT', 1.1, $bi, '6649'),
            $company('8274910-2', 'Talleres Industriales Santo Tomás, S.A.', 'Talleres Santo Tomás', 'STC-01', 'servicios', 'Colonia El Delfín, lote 21, Santo Tomás de Castilla, Izabal', '+502 7948 8834', 'taller@talleresstc.test', 'Ing. Walter Giovanni Morán Recinos', '95000.00', 'TIS', 1.0, $br, '1190'),
            $company('4182736-5', 'Comercializadora de Mariscos del Atlántico', 'Mariscos del Atlántico', 'STC-01', 'comercio', 'Muelle municipal, local 6, Santo Tomás de Castilla, Izabal', '+502 7948 9015', 'ventas@mariscosatlantico.test', 'Alba Luz Hernández Ical', '60000.00', 'CMA', 0.8, $br, '5568'),
            $company('6193827-1', 'Construcciones Portuarias Izabal, S.A.', 'Construcciones Portuarias Izabal', 'STC-01', 'construccion', 'Zona Portuaria, 5a. Calle, Santo Tomás de Castilla, Izabal', '+502 7948 3170', 'obras@construccionesportuarias.test', 'Ing. Selvin Rolando Barrios Coc', '220000.00', 'CPI', 0.9, $gyt, '2741'),
            // Oficina Livingston
            $company('6472918-3', 'Restaurante Mariscos La Caribeña', 'Mariscos La Caribeña', 'LIV-01', 'restaurante', 'Calle principal frente al muelle municipal, Livingston, Izabal', '+502 7947 0315', 'reservas@lacaribena.test', 'Marvin Alexander Castillo Ramos', '40000.00', 'RMC', 1.0, $br, '1275'),
            $company('7539128-4', 'Tours y Aventuras Río Dulce', 'Aventuras Río Dulce', 'LIV-01', 'turismo', 'Aldea Fronteras, a un costado del puente, Río Dulce, Livingston, Izabal', '+502 7930 5120', 'reservas@aventurasriodulce.test', 'Kevin Josué Mejía Barrios', '55000.00', 'TAR', 0.9, $br, '4407'),
            $company('2817364-0', 'Hotel Casa Garífuna', 'Hotel Casa Garífuna', 'LIV-01', 'turismo', 'Barrio Marcos Sánchez Díaz, Livingston, Izabal', '+502 7947 0422', 'hotel@casagarifuna.test', 'Doña Teresa Martínez Arzú', '90000.00', 'HCG', 1.0, $bi, '6012'),
            $company('9174628-3', 'Lanchas Turísticas del Golfete', 'Lanchas del Golfete', 'LIV-01', 'transporte', 'Muelle municipal, Livingston, Izabal', '+502 7947 0533', 'operaciones@lanchasgolfete.test', 'Edgar Rolando Sabán Coc', '70000.00', 'LTG', 0.8, $br, '3398'),
            $company('3746182-9', 'Tienda y Abarrotería La Bendición de Livingston', 'Abarrotería La Bendición', 'LIV-01', 'comercio', 'Calle Principal, Barrio Pueblo Nuevo, Livingston, Izabal', '+502 7947 0644', 'abarroteria@labendicion-liv.test', 'Juana Isabel Sabán Martínez', '35000.00', 'TLB', 0.6, $br, '5274'),
            $company('8392017-6', 'Artesanías Garífunas del Caribe', 'Artesanías Garífunas', 'LIV-01', 'comercio', 'Barrio Capitanía, Livingston, Izabal', '+502 7947 0755', 'ventas@artesaniasgarifunas.test', 'Ana Gloria Arzú Castillo', '25000.00', 'AGC', 0.6, $br, '8106'),
            $company('1639284-7', 'Servicios Ecoturísticos Siete Altares', 'Ecoturismo Siete Altares', 'LIV-01', 'turismo', 'Camino a Siete Altares, kilómetro 2, Livingston, Izabal', '+502 7947 0866', 'reservas@sietealtares.test', 'Pablo Antonio Coc Caal', '30000.00', 'SES', 0.7, $br, '9203'),
            $company('5928173-4', 'Panadería Garífuna Doña Juana', 'Panadería Doña Juana', 'LIV-01', 'restaurante', 'Barrio Barique, Livingston, Izabal', '+502 7947 0977', 'pedidos@panaderiadonajuana.test', 'Juana María Ramírez Arzú', '20000.00', 'PDJ', 0.5, $br, '1846'),
        ];
    }

    /**
     * Personal de las empresas con planilla: [código, nombres, apellidos, puesto, departamento, salario base, sexo].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    public static function employees(): array
    {
        return [
            ['EMP-001', 'Juan Carlos', 'Pérez Ruano', 'Gerente administrativo', 'Administración', '9500.00', 'M'],
            ['EMP-002', 'María José', 'Hernández Lima', 'Asistente contable', 'Administración', '4800.00', 'F'],
            ['EMP-003', 'Luis Enrique', 'Caal Xol', 'Encargado de bodega', 'Operaciones', '4200.00', 'M'],
            ['EMP-004', 'Ana Patricia', 'López Monroy', 'Vendedora', 'Ventas', '3900.00', 'F'],
            ['EMP-005', 'Edgar Rolando', 'Morales Choc', 'Piloto repartidor', 'Operaciones', '4100.00', 'M'],
        ];
    }
}
