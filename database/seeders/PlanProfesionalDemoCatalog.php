<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Datos ficticios del escenario de demostración del Plan Profesional: la firma «Asesores Contables del Caribe» de Puerto
 * Barrios (una sola oficina), su equipo de exactamente 8 personas y 20 empresas cliente de Izabal. Las operaciones de cada
 * sector (clientes, proveedores, ventas, compras, productos) y la plantilla de empleados se toman del catálogo de sectores
 * de demostración, sin modificarlo. Nombres, NIT, DPI, direcciones y montos son inventados; los correos usan .test.
 *
 * @phpstan-type Company array{tax_id: string, name: string, trade_name: string, sector: string, address: string, phone: string, email: string, contact: string, capital: string, series: string, scale: float, bank: array{0: string, 1: string, 2: string}, payroll: bool, asset: array{0: string, 1: string, 2: string, 3: int}|null, production: bool}
 */
final class PlanProfesionalDemoCatalog
{
    public const ORGANIZATION_TAX_ID = '7741268-0';

    public const DOMAIN = 'asesorescaribe.test';

    public const CLIENT_DOMAIN = 'clientes-asesorescaribe.test';

    /** @return array{name: string, legal_name: string, office: array{code: string, name: string, address: string, city: string, phone: string}} */
    public static function firm(): array
    {
        return [
            'name' => 'Asesores Contables del Caribe',
            'legal_name' => 'Asesores Contables del Caribe, S.A.',
            'office' => ['code' => 'PBA-01', 'name' => 'Oficina Puerto Barrios', 'address' => '7a. Avenida entre 13 y 14 Calle, Barrio El Centro', 'city' => 'Puerto Barrios', 'phone' => '+502 7948 3030'],
        ];
    }

    /**
     * Los 8 usuarios internos del plan: clave, nombre, usuario de correo, rol principal (código real de ROLE_PRESETS) y
     * roles adicionales del mismo usuario para cubrir funciones sin superar los asientos (Tesorería y Nómina).
     *
     * @return list<array{key: string, name: string, email: string, role: string, extra: list<string>}>
     */
    public static function team(): array
    {
        return [
            ['key' => 'owner', 'name' => 'Raúl Ernesto Pineda Arriola', 'email' => 'raul.pineda', 'role' => 'owner_director', 'extra' => []],
            ['key' => 'general', 'name' => 'Ana Lucía Hernández Pérez', 'email' => 'ana.hernandez', 'role' => 'general_accountant', 'extra' => []],
            ['key' => 'accountant', 'name' => 'Carlos Eduardo Méndez García', 'email' => 'carlos.mendez', 'role' => 'accountant_in_charge', 'extra' => []],
            ['key' => 'assistant', 'name' => 'Sofía Isabel García Hernández', 'email' => 'sofia.garcia', 'role' => 'accounting_assistant', 'extra' => []],
            ['key' => 'supervisor', 'name' => 'Claudia Patricia López Morales', 'email' => 'claudia.lopez', 'role' => 'accounting_supervisor', 'extra' => []],
            ['key' => 'tax', 'name' => 'María Fernanda Castillo Morales', 'email' => 'maria.castillo', 'role' => 'tax_specialist', 'extra' => []],
            ['key' => 'receivables', 'name' => 'Luis Fernando Morales González', 'email' => 'luis.morales', 'role' => 'accounts_receivable', 'extra' => ['treasury']],
            ['key' => 'payables', 'name' => 'Pedro Alejandro Morales Cruz', 'email' => 'pedro.morales', 'role' => 'accounts_payable', 'extra' => ['payroll']],
        ];
    }

    /** @return list<Company> */
    public static function companies(): array
    {
        $bi = 'Banco Industrial';
        $br = 'Banrural';
        $bam = 'Banco Agromercantil';
        $gyt = 'Banco G&T Continental';
        $company = fn (string $taxId, string $name, string $trade, string $sector, string $address, string $phone, string $email, string $contact, string $capital, string $series, float $scale, string $bank, string $lastFour, bool $payroll = false, ?array $asset = null, bool $production = false): array => [
            'tax_id' => $taxId, 'name' => $name, 'trade_name' => $trade, 'sector' => $sector, 'address' => $address, 'phone' => $phone, 'email' => $email, 'contact' => $contact,
            'capital' => $capital, 'series' => $series, 'scale' => $scale, 'bank' => [$bank, 'Cuenta monetaria '.$trade, $lastFour], 'payroll' => $payroll, 'asset' => $asset === null ? null : [(string) $asset[0], (string) $asset[1], (string) $asset[2], (int) $asset[3]], 'production' => $production,
        ];

        return [
            $company('8120457-3', 'Ferretería El Martillo, S.A.', 'Ferretería El Martillo', 'ferreteria', '9a. Avenida y 18 Calle, Barrio San Agustín, Puerto Barrios, Izabal', '+502 7948 3388', 'gerencia@elmartillo.test', 'Julio Roberto Aldana Pérez', '90000.00', 'FEM', 1.1, $bam, '7310', true),
            $company('3206981-4', 'Distribuidora El Progreso del Caribe, S.A.', 'Distribuidora El Progreso', 'distribuidora', 'Kilómetro 295 Carretera al Atlántico, Puerto Barrios, Izabal', '+502 7948 1122', 'contabilidad@progresocaribe.test', 'Carlos Humberto Paiz Ruano', '160000.00', 'DPC', 1.4, $bi, '4471', true),
            $company('5419082-7', 'Comercializadora del Caribe', 'Comercializadora del Caribe', 'comercio', '7a. Calle y 8a. Avenida, Barrio El Progreso, Puerto Barrios, Izabal', '+502 7948 2290', 'finanzas@comercaribe.test', 'Lilian Esperanza Monroy Paz', '80000.00', 'CDC', 1.0, $bi, '5820'),
            $company('6630174-2', 'Transportes del Atlántico, S.A.', 'Transportes del Atlántico', 'transporte', 'Zona Portuaria, 2a. Calle, Santo Tomás de Castilla, Izabal', '+502 7948 4410', 'finanzas@transatlantico.test', 'Héctor Manuel Sagastume Orellana', '200000.00', 'TDA', 1.3, $bi, '5523', false, ['Camión Isuzu de 8 toneladas', 'vehicles', '313600.00', 60]),
            $company('2875310-6', 'Servicios Integrales de Izabal', 'Servicios Integrales de Izabal', 'servicios', '5a. Avenida y 11 Calle, Edificio Caribe, oficina 3, Puerto Barrios, Izabal', '+502 7948 4455', 'gerencia@integralesizabal.test', 'Ingrid Johana Castañeda Lima', '45000.00', 'SII', 0.9, $bi, '2207'),
            $company('7093628-1', 'Restaurante La Ceiba', 'Restaurante La Ceiba', 'restaurante', '6a. Avenida entre 12 y 13 Calle, Puerto Barrios, Izabal', '+502 7948 5512', 'reservas@restaurantelaceiba.test', 'Doña Esperanza Gómez de Pineda', '35000.00', 'RLC', 0.9, $br, '1458'),
            $company('4058219-3', 'Constructora San José, S.A.', 'Constructora San José', 'construccion', '20 Calle final, Colonia Las Flores, Puerto Barrios, Izabal', '+502 7948 7021', 'proyectos@constructorasanjose.test', 'Ing. Mario Roberto Chávez Ortiz', '260000.00', 'CSJ', 1.0, $gyt, '9013', true, ['Retroexcavadora Caterpillar 416', 'machinery', '504000.00', 96]),
            $company('9317462-5', 'Inversiones Puerto del Sol, S.A.', 'Inversiones Puerto del Sol', 'arrendamiento', '13 Calle entre 6a. y 7a. Avenida, Puerto Barrios, Izabal', '+502 7948 6677', 'administracion@puertodelsol.test', 'Ernesto Rafael Solís Monterroso', '300000.00', 'IPS', 1.5, $gyt, '6134'),
            $company('1784530-9', 'Agroservicios del Norte', 'Agroservicios del Norte', 'distribuidora', 'Kilómetro 245 Carretera al Atlántico, Morales, Izabal', '+502 7947 3366', 'ventas@agronorte.test', 'Fredy Estuardo Lemus Galdámez', '70000.00', 'ADN', 0.9, $br, '6624'),
            $company('5520873-1', 'Comercial La Esperanza', 'Comercial La Esperanza', 'comercio', 'Calle principal, Barrio El Mitchal, Morales, Izabal', '+502 7947 1150', 'laesperanza@comercial.test', 'Rosa Amelia Cruz Villatoro', '50000.00', 'CLE', 0.8, $br, '8842'),
            $company('8641927-0', 'Panificadora Santo Tomás, S.A.', 'Panificadora Santo Tomás', 'industrial', 'Colonia El Delfín, lote 21, Santo Tomás de Castilla, Izabal', '+502 7948 8834', 'produccion@panificadorastc.test', 'Ing. Rodrigo Andrés Valdez Quiñónez', '150000.00', 'PST', 1.0, $bi, '2286', true, ['Horno industrial rotativo de 18 bandejas', 'machinery', '168000.00', 120], true),
            $company('3392781-6', 'Hotel Brisas de Amatique', 'Hotel Brisas de Amatique', 'turismo', 'Playa Santo Tomás, frente al malecón, Santo Tomás de Castilla, Izabal', '+502 7948 7720', 'reservas@brisasamatique.test', 'Liliana Marisol Peralta Ayala', '120000.00', 'HBA', 1.1, $bi, '6649'),
            $company('6185029-4', 'Logística Portuaria del Atlántico, S.A.', 'Logística Portuaria del Atlántico', 'logistica', 'Recinto Portuario, bodega 9, Santo Tomás de Castilla, Izabal', '+502 7948 5590', 'operaciones@logisticaportuaria.test', 'Lic. Mynor Estuardo Paredes Cruz', '180000.00', 'LPA', 1.2, $gyt, '3302'),
            $company('2047615-8', 'Clínica Odontológica Sonrisa Caribe', 'Sonrisa Caribe', 'servicios', '5a. Avenida y 15 Calle, Barrio Las Pozas, Puerto Barrios, Izabal', '+502 7948 9090', 'citas@sonrisacaribe.test', 'Dra. Claudia Beatriz Arana Fuentes', '55000.00', 'COS', 1.1, $bi, '3390'),
            $company('7726304-1', 'Abarrotería Doña Chepita', 'Abarrotería Doña Chepita', 'comercio', 'Barrio Pueblo Nuevo, Livingston, Izabal', '+502 7947 0644', 'abarroteria@donachepita.test', 'Josefa Isabel Sabán Martínez', '30000.00', 'ADC', 0.6, $br, '5274'),
            $company('4913870-2', 'Tours Río Dulce Aventura', 'Río Dulce Aventura', 'turismo', 'Aldea Fronteras, a un costado del puente, Río Dulce, Livingston, Izabal', '+502 7930 5120', 'reservas@riodulceaventura.test', 'Kevin Josué Mejía Barrios', '50000.00', 'TRA', 0.9, $br, '4407'),
            $company('6302517-9', 'Taller Industrial El Puerto', 'Taller El Puerto', 'servicios', '3a. Avenida y 22 Calle, Colonia Ferrocarril, Puerto Barrios, Izabal', '+502 7948 6120', 'taller@tallerelpuerto.test', 'Ing. Walter Giovanni Morán Recinos', '75000.00', 'TIP', 1.0, $bam, '1190'),
            $company('9158403-7', 'Mariscos y Restaurante El Muelle', 'Restaurante El Muelle', 'restaurante', 'Calle principal frente al muelle municipal, Livingston, Izabal', '+502 7947 0315', 'reservas@elmuelle.test', 'Marvin Alexander Castillo Ramos', '40000.00', 'RMM', 1.0, $br, '1275'),
            $company('1569248-0', 'Lanchas Express Livingston', 'Lanchas Express', 'transporte', 'Muelle municipal, Livingston, Izabal', '+502 7947 0533', 'operaciones@lanchasexpress.test', 'Edgar Rolando Sabán Coc', '70000.00', 'LEL', 0.8, $br, '3398'),
            $company('3851726-4', 'Materiales de Construcción Morales', 'Materiales Morales', 'ferreteria', 'Barrio Nuevo, frente al mercado municipal, Morales, Izabal', '+502 7947 2288', 'ventas@materialesmorales.test', 'Julio César Ortiz Paz', '85000.00', 'MCM', 0.9, $bam, '3105', true),
        ];
    }

    /**
     * Contactos del cliente en el portal (no ocupan asientos de la oficina): NIT de la empresa, nombre, usuario de correo y
     * rol de contacto.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function clientContacts(): array
    {
        return [
            ['8120457-3', 'Julio Roberto Aldana Pérez', 'julio.aldana', 'owner_contact'],
            ['3206981-4', 'Carlos Humberto Paiz Ruano', 'carlos.paiz', 'owner_contact'],
            ['5419082-7', 'Lilian Esperanza Monroy Paz', 'lilian.monroy', 'owner_contact'],
            ['6630174-2', 'Héctor Manuel Sagastume Orellana', 'hector.sagastume', 'owner_contact'],
            ['2875310-6', 'Ingrid Johana Castañeda Lima', 'ingrid.castaneda', 'owner_contact'],
            ['7093628-1', 'Esperanza Gómez de Pineda', 'esperanza.gomez', 'owner_contact'],
            ['4058219-3', 'Mario Roberto Chávez Ortiz', 'mario.chavez', 'owner_contact'],
            ['9317462-5', 'Ernesto Rafael Solís Monterroso', 'ernesto.solis', 'owner_contact'],
        ];
    }
}
