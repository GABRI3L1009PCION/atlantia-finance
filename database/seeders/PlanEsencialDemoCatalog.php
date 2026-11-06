<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Datos ficticios del escenario de demostración del Plan Esencial: la oficina contable de Puerto Barrios, su equipo y
 * los diez clientes que atiende en Izabal. Nombres, NIT, direcciones, teléfonos y montos son inventados; los correos
 * usan el dominio reservado .test.
 *
 * Ventas: [cliente, día del mes, líneas [descripción, cantidad, precio unitario con IVA, tratamiento], al contado].
 * Compras: [proveedor, día del mes, cuenta, tratamiento, descripción, total con IVA, al contado].
 *
 * @phpstan-type DemoSale array{0: string, 1: int, 2: list<array{0: string, 1: int, 2: string, 3: string}>, 3: bool}
 * @phpstan-type DemoPurchase array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string, 6: bool}
 * @phpstan-type DemoProfile array{tax_id: string, name: string, trade_name: string, activity: string, address: string, phone: string, email: string, contact: string, representative: string, revenue: string, capital: string, series: string, owner_books: bool, bank: array{0: string, 1: string, 2: string}, customers: list<array{0: string, 1: string, 2: string|null, 3: int}>, suppliers: list<array{0: string, 1: string, 2: string, 3: string, 4: int}>, sales: list<DemoSale>, purchases: list<DemoPurchase>}
 */
final class PlanEsencialDemoCatalog
{
    /** NIT ficticio de la oficina contable de demostración. */
    public const ORGANIZATION_TAX_ID = '9081726-3';

    public const OWNER_EMAIL = 'jose.ramirez@contadoresdelatlantico.test';

    public const COLLABORATOR_EMAIL = 'maria.castillo@contadoresdelatlantico.test';

    /** @return array{name: string, legal_name: string, office: array{code: string, name: string, email: string, phone: string, address: string, city: string, state: string}} */
    public static function firm(): array
    {
        return [
            'name' => 'Contadores Asociados del Atlántico',
            'legal_name' => 'José Miguel Ramírez López, Contador Público y Auditor',
            'office' => [
                'code' => 'PBA-01', 'name' => 'Oficina Puerto Barrios', 'email' => 'oficina@contadoresdelatlantico.test', 'phone' => '+502 7948 2310',
                'address' => '6a. Avenida entre 9a. y 10a. Calle, Barrio El Centro', 'city' => 'Puerto Barrios', 'state' => 'Izabal',
            ],
        ];
    }

    /** @return array{owner: array{name: string, email: string}, collaborator: array{name: string, email: string}} */
    public static function team(): array
    {
        return [
            'owner' => ['name' => 'José Miguel Ramírez López', 'email' => self::OWNER_EMAIL],
            'collaborator' => ['name' => 'María Fernanda Castillo Morales', 'email' => self::COLLABORATOR_EMAIL],
        ];
    }

    /**
     * Una factura recibida que la SAT reporta y la empresa aún no registró: el cruce la muestra como faltante.
     *
     * @return array{name: string, tax_id: string, series: string, number: string, total_cents: int, vat_cents: int}
     */
    public static function missingSatInvoice(): array
    {
        return ['name' => 'Librería y Papelería El Estudiante', 'tax_id' => '3216549-0', 'series' => 'LPE', 'number' => '88214', 'total_cents' => 33600, 'vat_cents' => 3600];
    }

    /** @return list<DemoProfile> */
    public static function companies(): array
    {
        return [
            [
                'tax_id' => '6231457-9', 'name' => 'Comercializadora El Progreso, S.A.', 'trade_name' => 'Comercializadora El Progreso', 'activity' => 'Comercio de abarrotes al por mayor',
                'address' => '7a. Calle y 8a. Avenida, Barrio El Progreso, Puerto Barrios, Izabal', 'phone' => '+502 7948 1122', 'email' => 'contabilidad@elprogreso.test',
                'contact' => 'Carlos Eduardo Méndez García', 'representative' => 'Carlos Eduardo Méndez García',
                'revenue' => '4201', 'capital' => '85000.00', 'series' => 'CEP', 'owner_books' => false,
                'bank' => ['Banco Industrial', 'Cuenta monetaria Comercializadora El Progreso', '4471'],
                'customers' => [
                    ['Tienda Doña Chepita', '2468135-7', 'compras@donachepita.test', 15],
                    ['Supermercado La Bahía, S.A.', '7531594-2', 'proveeduria@labahia.test', 30],
                    ['Cooperativa Integral Santo Tomás, R.L.', '9517538-4', 'cooperativa@santotomas.test', 30],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Distribuidora de Granos del Norte, S.A.', '3579512-6', 'ventas@granosdelnorte.test', '+502 7952 3344', 30],
                    ['Inmobiliaria Barrios del Caribe', '1597534-8', 'cobros@barriosdelcaribe.test', '+502 7948 5566', 0],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', '8523697-1', 'facturacion@eempb.test', '+502 7948 0101', 10],
                    ['Telecomunicaciones del Atlántico, S.A.', '4569871-3', 'clientes@telatlantico.test', '+502 7948 7788', 10],
                ],
                'sales' => [
                    ['Tienda Doña Chepita', 5, [['Arroz blanco, quintal', 8, '425.00', 'goods'], ['Frijol negro, quintal', 6, '610.00', 'goods']], false],
                    ['Supermercado La Bahía, S.A.', 11, [['Azúcar blanca, fardo de 25 lb', 40, '118.00', 'goods'], ['Aceite vegetal, caja de 12 litros', 15, '265.00', 'goods']], false],
                    ['Cooperativa Integral Santo Tomás, R.L.', 18, [['Maíz amarillo, quintal', 20, '235.00', 'goods']], false],
                    ['Consumidor final', 27, [['Ventas de mostrador del mes', 1, '9850.00', 'goods']], true],
                ],
                'purchases' => [
                    ['Inmobiliaria Barrios del Caribe', 1, '5203', 'services', 'Alquiler de bodega y local comercial', '6720.00', false],
                    ['Distribuidora de Granos del Norte, S.A.', 3, '5103', 'goods', 'Compra de granos básicos para reventa', '16800.00', false],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de bodega', '1456.00', false],
                    ['Telecomunicaciones del Atlántico, S.A.', 14, '5204', 'services', 'Servicio de internet y telefonía', '448.00', true],
                ],
            ],
            [
                'tax_id' => '5823916-4', 'name' => 'Distribuidora Los Pinos, S.A.', 'trade_name' => 'Distribuidora Los Pinos', 'activity' => 'Distribución de bebidas y productos de consumo',
                'address' => 'Kilómetro 292 Carretera al Atlántico, Santo Tomás de Castilla, Izabal', 'phone' => '+502 7948 3390', 'email' => 'administracion@lospinos.test',
                'contact' => 'Ana Lucía Hernández Pérez', 'representative' => 'Rodolfo Antonio Pinto Barrios',
                'revenue' => '4201', 'capital' => '120000.00', 'series' => 'DLP', 'owner_books' => false,
                'bank' => ['Banrural', 'Cuenta monetaria Distribuidora Los Pinos', '2098'],
                'customers' => [
                    ['Abarrotería San Antonio', '6547893-1', 'sanantonio@abarroteria.test', 15],
                    ['Hotel Valle Tropical', '3216987-4', 'compras@valletropical.test', 30],
                    ['Restaurante El Muelle', '7894561-2', 'gerencia@elmuelle.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Embotelladora del Caribe, S.A.', '2581473-9', 'pedidos@embotelladoracaribe.test', '+502 2420 5000', 30],
                    ['Combustibles y Lubricantes del Puerto', '9638527-0', 'ventas@combupuerto.test', '+502 7948 6060', 0],
                    ['Servicios Mecánicos Morales', '1472583-6', 'taller@mecanicosmorales.test', '+502 7947 1234', 15],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', '8523697-1', 'facturacion@eempb.test', '+502 7948 0101', 10],
                ],
                'sales' => [
                    ['Abarrotería San Antonio', 4, [['Agua pura, fardo de 24 unidades', 60, '42.00', 'goods'], ['Refresco de 600 ml, caja de 24', 30, '118.00', 'goods']], false],
                    ['Hotel Valle Tropical', 10, [['Agua en garrafón de 5 galones', 80, '18.00', 'goods'], ['Jugos naturales, caja de 12', 25, '96.00', 'goods']], false],
                    ['Restaurante El Muelle', 19, [['Cerveza nacional, caja de 12', 40, '168.00', 'goods']], false],
                    ['Consumidor final', 28, [['Ventas en ruta del mes', 1, '12400.00', 'goods']], true],
                ],
                'purchases' => [
                    ['Embotelladora del Caribe, S.A.', 2, '5103', 'goods', 'Compra de bebidas para distribución', '24640.00', false],
                    ['Combustibles y Lubricantes del Puerto', 7, '5201', 'goods', 'Combustible para camiones repartidores', '3920.00', true],
                    ['Servicios Mecánicos Morales', 15, '5201', 'services', 'Mantenimiento preventivo de flotilla', '2240.00', false],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de bodega refrigerada', '2688.00', false],
                ],
            ],
            [
                'tax_id' => '4718293-5', 'name' => 'Ferretería San José', 'trade_name' => 'Ferretería San José', 'activity' => 'Venta de materiales de construcción y ferretería',
                'address' => 'Barrio Nuevo, frente al parque central, Morales, Izabal', 'phone' => '+502 7947 2288', 'email' => 'ferreteria@sanjose-morales.test',
                'contact' => 'Luis Fernando Morales González', 'representative' => 'Luis Fernando Morales González',
                'revenue' => '4201', 'capital' => '70000.00', 'series' => 'FSJ', 'owner_books' => false,
                'bank' => ['Banco Agromercantil', 'Cuenta monetaria Ferretería San José', '7310'],
                'customers' => [
                    ['Constructora Río Motagua, S.A.', '5432167-8', 'compras@riomotagua.test', 30],
                    ['Municipalidad de Morales', '3698521-4', 'tesoreria@munimorales.test', 30],
                    ['Maestro de obras Juan Carlos Pérez Ruano', '7412589-6', 'jcperez@correo.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Cementos y Agregados de Oriente, S.A.', '8529637-4', 'ventas@cementosoriente.test', '+502 7941 8800', 30],
                    ['Hierros y Aceros de Guatemala, S.A.', '9517534-2', 'creditos@hierrosgt.test', '+502 2380 4400', 30],
                    ['Arrendamientos El Centro', '1593578-5', 'cobros@arrendamientoscentro.test', '+502 7947 3030', 0],
                    ['Telecomunicaciones del Atlántico, S.A.', '4569871-3', 'clientes@telatlantico.test', '+502 7948 7788', 10],
                ],
                'sales' => [
                    ['Constructora Río Motagua, S.A.', 6, [['Cemento gris, saco de 42.5 kg', 120, '86.00', 'goods'], ['Hierro corrugado de 3/8", varilla', 200, '38.50', 'goods']], false],
                    ['Municipalidad de Morales', 13, [['Tubería PVC de 4", tubo de 6 m', 45, '185.00', 'goods'], ['Pintura de aceite, galón', 20, '210.00', 'goods']], false],
                    ['Maestro de obras Juan Carlos Pérez Ruano', 20, [['Block de concreto 15x20x40', 900, '5.75', 'goods']], false],
                    ['Consumidor final', 29, [['Ventas de mostrador del mes', 1, '14250.00', 'goods']], true],
                ],
                'purchases' => [
                    ['Arrendamientos El Centro', 1, '5203', 'services', 'Alquiler del local de la ferretería', '5600.00', false],
                    ['Cementos y Agregados de Oriente, S.A.', 4, '5103', 'goods', 'Compra de cemento y agregados', '18480.00', false],
                    ['Hierros y Aceros de Guatemala, S.A.', 9, '5103', 'goods', 'Compra de hierro y alambre de amarre', '9520.00', false],
                    ['Telecomunicaciones del Atlántico, S.A.', 14, '5204', 'services', 'Servicio de internet y telefonía', '392.00', true],
                ],
            ],
            [
                'tax_id' => '3592847-1', 'name' => 'Transportes del Atlántico, S.A.', 'trade_name' => 'Transportes del Atlántico', 'activity' => 'Transporte terrestre de carga',
                'address' => 'Zona Portuaria, 2a. Calle, Santo Tomás de Castilla, Izabal', 'phone' => '+502 7948 4410', 'email' => 'finanzas@transatlantico.test',
                'contact' => 'Sandra Patricia López Ruiz', 'representative' => 'Héctor Manuel Sagastume Orellana',
                'revenue' => '4101', 'capital' => '150000.00', 'series' => 'TDA', 'owner_books' => false,
                'bank' => ['Banco Industrial', 'Cuenta monetaria Transportes del Atlántico', '5523'],
                'customers' => [
                    ['Agroexportadora Bananera del Motagua, S.A.', '8642097-5', 'logistica@bananeramotagua.test', 30],
                    ['Naviera Caribe Express, S.A.', '2097531-8', 'operaciones@caribeexpress.test', 30],
                    ['Importadora Centroamericana de Repuestos', '6420975-3', 'compras@icrepuestos.test', 15],
                ],
                'suppliers' => [
                    ['Combustibles y Lubricantes del Puerto', '9638527-0', 'ventas@combupuerto.test', '+502 7948 6060', 0],
                    ['Llantas y Servicios del Atlántico', '7531598-2', 'ventas@llantasatlantico.test', '+502 7948 9911', 30],
                    ['Inmobiliaria Barrios del Caribe', '1597534-8', 'cobros@barriosdelcaribe.test', '+502 7948 5566', 0],
                    ['Seguros del Istmo, S.A.', '4826157-3', 'cobranza@segurosistmo.test', '+502 2328 0000', 15],
                ],
                'sales' => [
                    ['Agroexportadora Bananera del Motagua, S.A.', 7, [['Flete de contenedor refrigerado Morales - Santo Tomás', 12, '2350.00', 'services']], false],
                    ['Naviera Caribe Express, S.A.', 14, [['Traslado de contenedores dentro del puerto', 18, '980.00', 'services']], false],
                    ['Importadora Centroamericana de Repuestos', 21, [['Flete Santo Tomás - Ciudad de Guatemala', 3, '4200.00', 'services']], true],
                ],
                'purchases' => [
                    ['Inmobiliaria Barrios del Caribe', 1, '5203', 'services', 'Alquiler de predio para parqueo de camiones', '8960.00', false],
                    ['Combustibles y Lubricantes del Puerto', 5, '5201', 'goods', 'Combustible diésel para la flotilla', '13440.00', true],
                    ['Llantas y Servicios del Atlántico', 11, '5201', 'goods', 'Llantas para cabezal', '6160.00', false],
                    ['Seguros del Istmo, S.A.', 16, '5201', 'exempt', 'Prima mensual del seguro de flotilla', '3150.00', false],
                ],
            ],
            [
                'tax_id' => '2846193-7', 'name' => 'Comercial La Bendición', 'trade_name' => 'Comercial La Bendición', 'activity' => 'Venta de ropa, calzado y artículos para el hogar',
                'address' => 'Calle principal, Barrio El Mitchal, Los Amates, Izabal', 'phone' => '+502 7943 1150', 'email' => 'labendicion@comercial.test',
                'contact' => 'Rosa María Cruz de León', 'representative' => 'Rosa María Cruz de León',
                'revenue' => '4201', 'capital' => '45000.00', 'series' => 'CLB', 'owner_books' => false,
                'bank' => ['Banrural', 'Cuenta monetaria Comercial La Bendición', '8842'],
                'customers' => [
                    ['Colegio Mixto Bilingüe Los Amates', '9753186-2', 'administracion@colegiolosamates.test', 30],
                    ['Asociación de Comerciantes de Los Amates', '1357924-6', 'asociacion@comerciantesamates.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Textiles y Confecciones de Oriente, S.A.', '6248135-9', 'ventas@textilesoriente.test', '+502 7941 2200', 30],
                    ['Calzado Fino Centroamericano, S.A.', '3814726-5', 'pedidos@calzadofino.test', '+502 2445 3100', 30],
                    ['Propietario del local Julio César Ortiz Paz', '5172839-4', 'jcortiz@correo.test', '+502 5544 2211', 0],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', '8523697-1', 'facturacion@eempb.test', '+502 7948 0101', 10],
                ],
                'sales' => [
                    ['Colegio Mixto Bilingüe Los Amates', 8, [['Uniforme escolar completo', 35, '245.00', 'goods'], ['Zapato escolar negro, par', 35, '195.00', 'goods']], false],
                    ['Asociación de Comerciantes de Los Amates', 17, [['Camisas promocionales bordadas', 60, '72.00', 'goods']], false],
                    ['Consumidor final', 26, [['Ventas de mostrador del mes', 1, '11380.00', 'goods']], true],
                ],
                'purchases' => [
                    ['Propietario del local Julio César Ortiz Paz', 1, '5203', 'services', 'Alquiler del local comercial', '3360.00', false],
                    ['Textiles y Confecciones de Oriente, S.A.', 3, '5103', 'goods', 'Compra de ropa y uniformes para reventa', '9800.00', false],
                    ['Calzado Fino Centroamericano, S.A.', 10, '5103', 'goods', 'Compra de calzado escolar y casual', '7840.00', false],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica del local', '672.00', true],
                ],
            ],
            [
                'tax_id' => '7364821-9', 'name' => 'Inversiones Puerto del Sol, S.A.', 'trade_name' => 'Inversiones Puerto del Sol', 'activity' => 'Arrendamiento de locales y bodegas',
                'address' => '13 Calle entre 6a. y 7a. Avenida, Puerto Barrios, Izabal', 'phone' => '+502 7948 6677', 'email' => 'administracion@puertodelsol.test',
                'contact' => 'Gabriela Alejandra Solís Monterroso', 'representative' => 'Ernesto Rafael Solís Monterroso',
                'revenue' => '4101', 'capital' => '200000.00', 'series' => 'IPS', 'owner_books' => false,
                'bank' => ['Banco G&T Continental', 'Cuenta monetaria Inversiones Puerto del Sol', '6134'],
                'customers' => [
                    ['Farmacia La Salud de Izabal', '4862039-7', 'gerencia@farmacialasalud.test', 5],
                    ['Óptica Visión Caribe', '2039486-1', 'opticavision@caribe.test', 5],
                    ['Bodegas Portuarias de Izabal, S.A.', '6039482-5', 'pagos@bodegasizabal.test', 10],
                ],
                'suppliers' => [
                    ['Servicios de Limpieza Brillo Total', '8402619-3', 'contacto@brillototal.test', '+502 7948 2244', 15],
                    ['Seguridad Integral del Caribe, S.A.', '3946128-0', 'facturacion@seguridadcaribe.test', '+502 7948 3355', 15],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', '8523697-1', 'facturacion@eempb.test', '+502 7948 0101', 10],
                    ['Bufete Jurídico Ramírez & Asociados', '5293846-7', 'bufete@ramirezasociados.test', '+502 7948 1001', 15],
                ],
                'sales' => [
                    ['Farmacia La Salud de Izabal', 2, [['Arrendamiento mensual de local comercial No. 3', 1, '7840.00', 'services']], false],
                    ['Óptica Visión Caribe', 2, [['Arrendamiento mensual de local comercial No. 5', 1, '5600.00', 'services']], false],
                    ['Bodegas Portuarias de Izabal, S.A.', 3, [['Arrendamiento mensual de bodega de 600 m²', 1, '16800.00', 'services']], false],
                ],
                'purchases' => [
                    ['Servicios de Limpieza Brillo Total', 5, '5201', 'services', 'Limpieza de áreas comunes del centro comercial', '3360.00', false],
                    ['Seguridad Integral del Caribe, S.A.', 5, '5201', 'services', 'Servicio de seguridad privada', '8960.00', false],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica de áreas comunes', '2016.00', false],
                    ['Bufete Jurídico Ramírez & Asociados', 20, '5208', 'services', 'Honorarios por renovación de contratos de arrendamiento', '2800.00', true],
                ],
            ],
            [
                'tax_id' => '8195273-6', 'name' => 'Servicios Médicos Bahía de Amatique', 'trade_name' => 'Clínica Bahía de Amatique', 'activity' => 'Servicios médicos de consulta externa',
                'address' => '5a. Avenida y 15 Calle, Barrio Las Pozas, Puerto Barrios, Izabal', 'phone' => '+502 7948 9090', 'email' => 'clinica@bahiaamatique.test',
                'contact' => 'Dra. Claudia Beatriz Arana Fuentes', 'representative' => 'Dra. Claudia Beatriz Arana Fuentes',
                'revenue' => '4101', 'capital' => '60000.00', 'series' => 'SMB', 'owner_books' => true,
                'bank' => ['Banco Industrial', 'Cuenta monetaria Clínica Bahía de Amatique', '3390'],
                'customers' => [
                    ['Aseguradora La Ceiba, S.A.', '2758194-6', 'reclamos@aseguradoralaceiba.test', 30],
                    ['Empresa Portuaria Santo Tomás de Castilla', '1948276-3', 'recursoshumanos@puertostc.test', 30],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Droguería Nacional de Oriente, S.A.', '6182735-9', 'ventas@drogueriaoriente.test', '+502 7941 4400', 30],
                    ['Laboratorio Clínico San Lucas', '9271846-5', 'resultados@labsanlucas.test', '+502 7948 3121', 15],
                    ['Inmobiliaria Barrios del Caribe', '1597534-8', 'cobros@barriosdelcaribe.test', '+502 7948 5566', 0],
                ],
                'sales' => [
                    ['Aseguradora La Ceiba, S.A.', 9, [['Consultas médicas de asegurados', 42, '250.00', 'services']], false],
                    ['Empresa Portuaria Santo Tomás de Castilla', 15, [['Evaluaciones médicas ocupacionales', 25, '380.00', 'services']], false],
                    ['Consumidor final', 28, [['Consultas particulares del mes', 1, '18200.00', 'services']], true],
                ],
                'purchases' => [
                    ['Inmobiliaria Barrios del Caribe', 1, '5203', 'services', 'Alquiler de las instalaciones de la clínica', '7280.00', false],
                    ['Droguería Nacional de Oriente, S.A.', 6, '5201', 'goods', 'Medicamentos e insumos médicos', '6720.00', false],
                    ['Laboratorio Clínico San Lucas', 18, '5208', 'services', 'Honorarios por exámenes de laboratorio', '3080.00', true],
                ],
            ],
            [
                'tax_id' => '6472918-3', 'name' => 'Restaurante Mariscos La Caribeña', 'trade_name' => 'Mariscos La Caribeña', 'activity' => 'Restaurante de comida típica y mariscos',
                'address' => 'Calle principal frente al muelle municipal, Livingston, Izabal', 'phone' => '+502 7947 0315', 'email' => 'reservas@lacaribena.test',
                'contact' => 'Marvin Estuardo Castillo Ramos', 'representative' => 'Marvin Estuardo Castillo Ramos',
                'revenue' => '4201', 'capital' => '40000.00', 'series' => 'RMC', 'owner_books' => false,
                'bank' => ['Banrural', 'Cuenta monetaria Mariscos La Caribeña', '1275'],
                'customers' => [
                    ['Agencia de Viajes Río Dulce Tours', '3847261-5', 'reservaciones@riodulcetours.test', 15],
                    ['Asociación Garífuna de Livingston', '7261938-4', 'asociacion@garifunalivingston.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Pescadores Unidos de la Bahía', '5829164-7', 'ventas@pescadoresbahia.test', '+502 5510 2233', 0],
                    ['Distribuidora de Granos del Norte, S.A.', '3579512-6', 'ventas@granosdelnorte.test', '+502 7952 3344', 30],
                    ['Gas Propano del Atlántico', '2918374-6', 'pedidos@gaspropano.test', '+502 7948 8800', 10],
                    ['Propietaria del local Marta Lidia Bol Caal', '9164827-3', 'martabol@correo.test', '+502 5566 7788', 0],
                ],
                'sales' => [
                    ['Agencia de Viajes Río Dulce Tours', 12, [['Almuerzos para grupo de turistas', 48, '95.00', 'goods']], false],
                    ['Asociación Garífuna de Livingston', 19, [['Servicio de alimentación para evento cultural', 1, '6500.00', 'goods']], false],
                    ['Consumidor final', 30, [['Ventas del restaurante del mes', 1, '26400.00', 'goods']], true],
                ],
                'purchases' => [
                    ['Propietaria del local Marta Lidia Bol Caal', 1, '5203', 'services', 'Alquiler del local del restaurante', '4480.00', false],
                    ['Pescadores Unidos de la Bahía', 4, '5103', 'goods', 'Compra de pescado y mariscos frescos', '8960.00', true],
                    ['Distribuidora de Granos del Norte, S.A.', 8, '5103', 'goods', 'Compra de arroz, frijol y abarrotes', '3360.00', false],
                    ['Gas Propano del Atlántico', 13, '5204', 'goods', 'Gas propano para cocina', '1120.00', false],
                ],
            ],
            [
                'tax_id' => '9283746-1', 'name' => 'Agencia Aduanal Puerta del Caribe', 'trade_name' => 'Puerta del Caribe', 'activity' => 'Servicios de agencia aduanal',
                'address' => 'Edificio Portuario, oficina 4, Santo Tomás de Castilla, Izabal', 'phone' => '+502 7948 5501', 'email' => 'tramites@puertadelcaribe.test',
                'contact' => 'Óscar René Villeda Cardona', 'representative' => 'Óscar René Villeda Cardona',
                'revenue' => '4101', 'capital' => '55000.00', 'series' => 'APC', 'owner_books' => true,
                'bank' => ['Banco Industrial', 'Cuenta monetaria Agencia Aduanal Puerta del Caribe', '9061'],
                'customers' => [
                    ['Importadora Centroamericana de Repuestos', '6420975-3', 'compras@icrepuestos.test', 15],
                    ['Comercializadora El Progreso, S.A.', '6231457-9', 'contabilidad@elprogreso.test', 15],
                    ['Ferretería y Materiales El Puerto', '4173829-6', 'compras@materialeselpuerto.test', 15],
                ],
                'suppliers' => [
                    ['Inmobiliaria Barrios del Caribe', '1597534-8', 'cobros@barriosdelcaribe.test', '+502 7948 5566', 0],
                    ['Telecomunicaciones del Atlántico, S.A.', '4569871-3', 'clientes@telatlantico.test', '+502 7948 7788', 10],
                    ['Mensajería Express del Atlántico', '7392816-4', 'envios@mensajeriaatlantico.test', '+502 7948 4040', 0],
                ],
                'sales' => [
                    ['Importadora Centroamericana de Repuestos', 6, [['Gestión de póliza de importación', 6, '1450.00', 'services']], false],
                    ['Comercializadora El Progreso, S.A.', 13, [['Trámite de desaduanaje de contenedor', 2, '2100.00', 'services']], false],
                    ['Ferretería y Materiales El Puerto', 22, [['Asesoría en clasificación arancelaria', 1, '3360.00', 'services']], true],
                ],
                'purchases' => [
                    ['Inmobiliaria Barrios del Caribe', 1, '5203', 'services', 'Alquiler de oficina en edificio portuario', '3920.00', false],
                    ['Telecomunicaciones del Atlántico, S.A.', 14, '5204', 'services', 'Servicio de internet dedicado', '784.00', false],
                    ['Mensajería Express del Atlántico', 19, '5201', 'services', 'Envío de documentos a la capital', '560.00', true],
                ],
            ],
            [
                'tax_id' => '5617382-0', 'name' => 'Taller Mecánico Hermanos Pérez', 'trade_name' => 'Taller Hermanos Pérez', 'activity' => 'Reparación y mantenimiento de vehículos',
                'address' => 'Aldea Fronteras, a un costado del puente, Río Dulce, Livingston, Izabal', 'phone' => '+502 7930 5120', 'email' => 'taller@hermanosperez.test',
                'contact' => 'Edwin Rolando Pérez Chacón', 'representative' => 'Edwin Rolando Pérez Chacón',
                'revenue' => '4101', 'capital' => '35000.00', 'series' => 'THP', 'owner_books' => true,
                'bank' => ['Banrural', 'Cuenta monetaria Taller Hermanos Pérez', '4407'],
                'customers' => [
                    ['Transportes del Atlántico, S.A.', '3592847-1', 'finanzas@transatlantico.test', 15],
                    ['Lanchas Turísticas Río Dulce', '8361927-4', 'operaciones@lanchasriodulce.test', 15],
                    ['Consumidor final', 'CF', null, 0],
                ],
                'suppliers' => [
                    ['Repuestos y Accesorios El Volante', '2739184-5', 'ventas@elvolante.test', '+502 7930 6611', 15],
                    ['Lubricantes y Filtros de Oriente', '6184927-3', 'pedidos@lubrioriente.test', '+502 7941 7722', 15],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', '8523697-1', 'facturacion@eempb.test', '+502 7948 0101', 10],
                ],
                'sales' => [
                    ['Transportes del Atlántico, S.A.', 8, [['Servicio mayor a camión de carga', 2, '3450.00', 'services']], false],
                    ['Lanchas Turísticas Río Dulce', 16, [['Reparación de motor fuera de borda', 1, '4800.00', 'services']], false],
                    ['Consumidor final', 27, [['Servicios de taller del mes', 1, '9650.00', 'services']], true],
                ],
                'purchases' => [
                    ['Repuestos y Accesorios El Volante', 3, '5201', 'goods', 'Repuestos para reparaciones', '5040.00', false],
                    ['Lubricantes y Filtros de Oriente', 9, '5201', 'goods', 'Aceites, filtros y grasas', '2464.00', true],
                    ['Empresa Eléctrica Municipal de Puerto Barrios', 12, '5204', 'services', 'Energía eléctrica del taller', '896.00', false],
                ],
            ],
        ];
    }
}
