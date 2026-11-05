# Seeder de demostración — Plan Multi-oficina

`database/seeders/PlanMultiOficinaDemoSeeder.php` crea un escenario integral del plan **Multi-oficina** (`multi_office`): una firma contable de Izabal con cuatro sedes, un equipo de 21 personas y 32 empresas cliente con tres meses de operación.

Los datos ficticios están en `database/seeders/PlanMultiOficinaDemoCatalog.php`: oficinas, equipo, sectores, empresas, grupo empresarial y empleados.

> **Solo para desarrollo o pruebas.** El seeder se niega a correr con `APP_ENV=production` (trait `RefusesProduction`). No modifica los seeders de los otros planes.

## Ejecución

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=PlanMultiOficinaDemoSeeder
```

- El seeder ejecuta antes `PlanCatalogSeeder` (planes y versiones) y `PayrollParameterSeeder` (tasas de IGSS, IRTRA e INTECAP de la aplicación).
- Las fechas se calculan desde hoy: los **dos meses anteriores** y el **mes en curso**. Los días futuros del mes en curso se omiten.
- Al terminar imprime una tabla con los registros creados por módulo y los pasos que una regla del sistema no permitió completar, cada uno con la empresa y el motivo.

La prueba automática que valida el escenario es esta:

```bash
php artisan test --compact tests/Feature/PlanMultiOficinaDemoSeederTest.php
```

## Organización y oficinas

**Firma:** Consultores Contables del Atlántico, S.A., NIT ficticio `5560987-2`. Tiene una suscripción manual `active` al precio mensual del plan `multi_office`, ligada a su versión publicada.

| Código | Oficina | Ciudad | Días de preparación / revisión fiscal |
|---|---|---|---|
| CEN-01 | Oficina Central (principal) | Puerto Barrios | 5 / 3 |
| MOR-01 | Oficina Morales | Morales | 4 / 2 |
| STC-01 | Oficina Santo Tomás | Santo Tomás de Castilla | 4 / 2 |
| LIV-01 | Oficina Livingston | Livingston | 6 / 3 |

Las políticas de cierre de cada sede (`*_close_policy`) quedan en `warn`, el valor del sistema. Se usan 4 de las 5 oficinas permitidas.

## Usuarios, roles y credenciales

- **Correo:** `<usuario>@consultoresdelatlantico.test`.
- **Contraseña:** la de `config('demo.password')`, es decir, la variable `ATLANTIA_DEMO_PASSWORD`. Fuera de producción, el valor por defecto es `AtlantiaDemo!2026`.

| Usuario (correo) | Nombre | Rol (`ROLE_PRESETS`) | Alcance |
|---|---|---|---|
| `roberto.sagastume` | Roberto Carlos Sagastume Pineda | `owner_director` (propietario) | Toda la firma |
| `silvia.ochoa` | Silvia Marisol Ochoa Barrios | `office_admin` | Toda la firma (Administradora General) |
| `luis.morales` | Luis Fernando Morales González | `office_admin` | Solo la Oficina Central |
| `rosa.cruz` | Rosa María Cruz de León | `office_admin` | Solo la Oficina Morales |
| `pedro.garcia` | Pedro Antonio García Ramírez | `accounting_manager` | Toda la firma |
| `ana.hernandez` | Ana Lucía Hernández Pérez | `general_accountant` | Central y Santo Tomás |
| `jorge.cardona` | Jorge Alberto Cardona Villeda | `accounting_supervisor` | Central y Livingston |
| `claudia.morales` | Claudia Patricia Morales Pérez | `accounting_supervisor` | Morales y Santo Tomás |
| `carlos.mendez` | Carlos Eduardo Méndez García | `accountant_in_charge` | Central |
| `maria.castillo` | María Fernanda Castillo Morales | `accountant_in_charge` | Morales |
| `jose.ramirez` | José Alejandro Ramírez López | `accountant_in_charge` | Santo Tomás |
| `brenda.arana` | Brenda Lisseth Arana Fuentes | `accountant_in_charge` | Livingston |
| `andrea.pineda` | Andrea Valeska Pineda Ruiz | `accounting_assistant` | Central |
| `josue.tzul` | Josué Daniel Tzul Gómez | `accounting_assistant` | Morales |
| `gabriela.solis` | Gabriela Alejandra Solís Monterroso | `tax_specialist` | Toda la firma |
| `oscar.villeda` | Óscar René Villeda Cardona | `treasury` | Toda la firma |
| `marta.bol` | Marta Lidia Bol Caal | `accounts_receivable` | Toda la firma |
| `edwin.perez` | Edwin Rolando Pérez Chacón | `accounts_payable` | Toda la firma |
| `karla.ordonez` | Karla Beatriz Ordóñez Chacón | `payroll` | Toda la firma |
| `sofia.lopez` | Sofía Isabel López Hernández | `auditor` | Toda la firma (solo lectura) |
| `hector.juarez` | Héctor Manuel Juárez Cifuentes | `read_only` | Morales (solo lectura) |

Son 21 de los 25 usuarios del plan. Se llenan `organization_user`, `office_user` y `user_role_assignments`. Quien trabaja en dos sedes recibe su rol en cada una; un administrador de sede **no** recibe alcance global.

**Responsables por empresa.** Se asignan con `CompanyAssignmentService::assign` en modo estricto, que valida que el rol del usuario permita la responsabilidad. La asignación llena `company_assignments` y `company_user`. Cada empresa recibe:

- contador encargado, auxiliar (Central y Morales), supervisor, encargado fiscal y tesorería;
- cuentas por cobrar y por pagar (Central y Santo Tomás);
- nómina (empresas con planilla);
- auditoría (empresas del grupo);
- consulta (Morales).

> **La Administradora General se incorpora al final**, cuando la firma ya está en marcha. Desde ese momento el Director / Socio queda en **solo lectura**, como define `WorkAuthorizationService::isExecutiveReadOnly`. Los movimientos de la demostración que el propietario registró ocurrieron antes de esa delegación.

## Empresas por oficina (8 en cada sede)

| Oficina | Empresas |
|---|---|
| **Central** | Inversiones Puerto del Sol, S.A. · Distribuidora El Progreso, S.A. · Comercializadora del Caribe, S.A. · Ferretería San José · Servicios Administrativos de Izabal · Clínica Médica Bahía de Amatique · Constructora Atlántico Norte, S.A. · Cafetería y Pastelería La Esperanza |
| **Morales** | Comercial La Esperanza · Agroservicios El Motagua · Ferretería y Materiales La Bendición · Transportes Morales Express · Farmacia San Rafael de Morales · Panificadora Industrial del Oriente, S.A. · Servicios Contables y Legales del Motagua · Restaurante El Ranchón de Bananera |
| **Santo Tomás** | Transportes y Logística del Norte, S.A. · Agencia Aduanal Puerta del Caribe · Almacenadora Portuaria del Atlántico, S.A. · Distribuidora de Combustibles del Puerto · Hotel y Restaurante Bahía Tropical · Talleres Industriales Santo Tomás, S.A. · Comercializadora de Mariscos del Atlántico · Construcciones Portuarias Izabal, S.A. |
| **Livingston** | Restaurante Mariscos La Caribeña · Tours y Aventuras Río Dulce · Hotel Casa Garífuna · Lanchas Turísticas del Golfete · Tienda y Abarrotería La Bendición de Livingston · Artesanías Garífunas del Caribe · Servicios Ecoturísticos Siete Altares · Panadería Garífuna Doña Juana |

Cada empresa se crea con el servicio de la pantalla Clientes, por lo que tiene su **propio** catálogo de cuentas, perfil fiscal, impuestos, mapeo contable y períodos. También recibe nombre comercial, NIT ficticio, dirección de Izabal, teléfono, correo `.test` y moneda GTQ.

Hay 11 sectores (comercio, ferretería, distribuidora, industrial, restaurante, transporte, logística, servicios, construcción, turismo y arrendamiento). Las operaciones de cada empresa salen de su sector, escaladas con un factor propio para que ninguna tenga las mismas cifras.

## Contabilidad generada (por empresa, tres meses)

- **Apertura:** capital y, en las empresas con inventario, existencias iniciales. Lleva documento de soporte.
- **Operaciones:**
  - ventas al crédito y al contado;
  - ingresos por servicios;
  - compras al crédito y al contado de mercadería, alquileres, servicios básicos, honorarios, combustible y seguros;
  - cobros (parciales en el mes en curso) y pagos con cheque o transferencia;
  - notas de crédito de venta y de proveedor;
  - caja chica.
- **Documentos vencidos:** la compra financiada del activo fijo queda pendiente de pago después de su vencimiento.
- **Flujo de revisión** (las dos primeras empresas de cada sede):
  - el auxiliar prepara y el contador encargado revisa y aprueba;
  - el supervisor rechaza lo que no procede y el Contador General (o el propietario) contabiliza;
  - quedan asientos en `draft`, `pending_review`, `reviewed`, `approved`, `posted`, `rejected` y `reversed` (con su corrección);
  - un ajuste nunca lo aprueba quien lo creó.
- **Bancos:** depósitos, transferencias, cheques (uno en circulación), retiro para caja chica y comisiones.
  - La comisión del primer mes la clasifica Tesorería, lo que genera su asiento y la deja conciliada.
  - Hay una conciliación del mes anterior, preparada por Tesorería y revisada por el contador.
  - Los movimientos del estado de cuenta no generan asientos por sí solos.
- **IVA y período:** Libros de IVA cerrados con su liquidación, y el primer mes cerrado con `CloseAccountingPeriod` (checklist y fotografía contable).

## Inventario

Aplica a 12 empresas de comercio, ferretería, distribución e industria; las de servicios no llevan inventario.

- **Productos** con unidad y existencia mínima.
- **Existencia inicial y compras** registradas en el kardex con su costo sin IVA. Las compras de mercadería se contabilizan en 1201 Inventarios.
- **Salidas** por las ventas de esos productos.
- **Toma física** al cierre del mes anterior, con un faltante (merma) que el sistema convierte en ajuste.
- **Costo de ventas** generado por `InventoryService::postCosts`.
- **Producción:** la Panificadora Industrial del Oriente produce 400 panes al mes con su receta de harina y azúcar (`ProductionService`).

## Nómina

Aplica a 6 empresas, con 5 empleados ficticios cada una. Cada empleado tiene DPI de 13 dígitos ficticio, número de IGSS, puesto, departamento, salario, bonificación incentivo de Q250 y pago por transferencia.

- **Meses anteriores:** se abren, se calculan con las reglas y tasas de la aplicación, los envía Nómina y los aprueba el propietario, nunca quien calculó. La aprobación genera el asiento de planilla.
- **Mes en curso:** queda calculado, pendiente de enviar a revisión.

## Activos fijos

4 empresas tienen un activo: un camión de volteo, un cabezal, un horno industrial y un montacargas. Cada uno tiene su factura de compra en 1401, su ficha en el módulo de activos y la depreciación de los dos primeros meses.

## Tareas, obligaciones, incidencias y supervisión

- **Tareas:** 7 por empresa, en los estados completada, en proceso, en revisión, con cambios solicitados, aprobada, pendiente y vencida. Las asigna el propietario a quien corresponde por su rol, y las revisa el supervisor o el contador.
- **Obligaciones:** se usa el calendario fiscal real.
  - Las de meses cerrados las prepara la encargada fiscal, las aprueba el propietario y se presentan con su constancia (PDF de demostración).
  - Quedan IVA en revisión (primera empresa de cada sede), con cambios solicitados (Morales), en proceso (Livingston) y uno vencido (Livingston).
- **Incidencias:** 4, una por sede, abiertas por el supervisor con el contador como responsable.
- **Auditoría:** todas las acciones quedan en `audit_logs` porque se ejecutan con los servicios del sistema; no se inserta ningún registro de auditoría a mano.

## Grupo empresarial consolidado

**Grupo Empresarial del Atlántico** (`company_groups` y `company_group_members`):

| Empresa | Papel | Participación |
|---|---|---|
| Inversiones Puerto del Sol, S.A. (7364821-9) | Controladora | — |
| Distribuidora El Progreso, S.A. (2190834-6) | Subsidiaria | 100 % |
| Comercializadora del Caribe, S.A. (3481927-5) | Subsidiaria | 80 % |
| Transportes y Logística del Norte, S.A. (4720195-3) | Subsidiaria | 70 % |

**Operaciones intercompañía cada mes.** Se registran con los NIT exactos de las empresas: la venta en una empresa y la compra en la otra, con cuentas por cobrar y por pagar entre ellas.

- Alquileres de la controladora a dos subsidiarias.
- Fletes de Transportes a Distribuidora.
- Venta de abarrotes de Distribuidora a Comercializadora.

`ConsolidationService` calcula al consultar `/accounting/consolidation` la suma por cuenta, las eliminaciones, la participación no controladora y la exportación. No se inserta ninguna cifra consolidada.

## Validaciones

El seeder **no se ejecutó en este entorno**, porque el contenedor no tiene las dependencias de Composer. La prueba `tests/Feature/PlanMultiOficinaDemoSeederTest.php` comprueba:

1. 4 oficinas, 21 usuarios (≤ 25) y 32 empresas, con 8 por oficina.
2. La administradora de Morales solo administra Morales y la contadora de Morales solo ve empresas de Morales.
3. Ningún asiento está descuadrado y ninguna línea usa cuentas de otra empresa.
4. Los estados financieros de cada empresa cuadran.
5. Hay asientos en todos los estados, períodos cerrados, kardex, nómina, 4 activos, tareas en todos los estados, obligaciones presentadas y 4 incidencias.
6. El grupo existe con participaciones de 100 %, 80 % y 70 %, el consolidado elimina operaciones intercompañía y calcula la participación no controladora.
7. Una segunda ejecución no agrega registros.

## Limitaciones encontradas

- **Pasos que pueden quedar rechazados.** Si una regla real del sistema rechaza un paso, el seeder lo deshace y lo informa en el resumen, sin forzarlo. Puede ocurrir, por ejemplo, con un cierre bloqueado por el checklist o con una asignación incompatible con el rol.
- **Revisiones de dos niveles del módulo de supervisión.** La revisión contable y fiscal del período y el cierre supervisado no se generan; el primer mes se cierra con el cierre directo del propietario.
- **Lo que no se ejecuta:** FEL, pagos, correos, conexiones bancarias e IA. Las autorizaciones de DTE son UUID ficticios.
- **Director en solo lectura en ejecuciones posteriores.** Después de la primera ejecución, el Director queda en solo lectura. Si una nueva ejecución necesitara crear algo a su nombre, el paso se informa como no completado; los registros existentes no se duplican.
