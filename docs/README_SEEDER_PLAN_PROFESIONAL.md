# Seeder de demostración — Plan Profesional

`database/seeders/PlanProfesionalDemoSeeder.php` crea un escenario operativo completo del plan **Profesional** (`professional`): una firma contable con **una oficina**, **8 usuarios internos**, 20 empresas cliente de Izabal y tres meses de trabajo contable.

Los datos propios del escenario (firma, equipo, empresas y contactos del cliente) están en `database/seeders/PlanProfesionalDemoCatalog.php`. Las plantillas de operaciones por sector y de empleados se leen de `PlanMultiOficinaDemoCatalog`, sin modificarlo.

> **Solo para desarrollo o pruebas.** El seeder se niega a correr con `APP_ENV=production`. No modifica los seeders de los otros planes.

## Ejecución

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=PlanProfesionalDemoSeeder
```

- El seeder ejecuta antes `PlanCatalogSeeder` (planes y versiones) y `PayrollParameterSeeder` (parámetros de nómina de la aplicación).
- Las fechas se calculan desde hoy: los **dos meses anteriores** y el **mes en curso**.
- Al terminar imprime una tabla con lo creado por módulo y los pasos que una regla real del sistema no permitió completar, con la empresa y el motivo.

La prueba automática que valida el escenario es esta:

```bash
php artisan test --compact tests/Feature/PlanProfesionalDemoSeederTest.php
```

## Organización y oficina

- **Firma:** Asesores Contables del Caribe, S.A., NIT ficticio `7741268-0`.
- **Suscripción:** manual `active` al precio mensual de `professional`, ligada a su versión publicada.
- **Oficina:** solo una, como permite el plan: **Oficina Puerto Barrios**, 7a. Avenida entre 13 y 14 Calle, Barrio El Centro, Puerto Barrios, Izabal.
- **Configuración de la oficina:**
  - plazos fiscales internos de 5 días para preparación y 3 para revisión;
  - **revisión del período de doble nivel** (`review_workflow = double_level`).

## Usuarios, roles y credenciales

- **Correo:** `<usuario>@asesorescaribe.test`.
- **Contraseña:** cada usuario recibe una contraseña aleatoria propia al crearse. El seeder la muestra **una sola vez**, en una tabla al terminar; en la base solo queda su hash. Si el usuario ya existía, conserva su contraseña.

| Usuario | Nombre | Rol principal (`ROLE_PRESETS`) | Rol adicional | Panel |
|---|---|---|---|---|
| `raul.pineda` | Raúl Ernesto Pineda Arriola | `owner_director` (propietario) | — | Panel ejecutivo |
| `ana.hernandez` | Ana Lucía Hernández Pérez | `general_accountant` | — | Revisión contable |
| `carlos.mendez` | Carlos Eduardo Méndez García | `accountant_in_charge` | — | Contador |
| `sofia.garcia` | Sofía Isabel García Hernández | `accounting_assistant` | — | Auxiliar |
| `claudia.lopez` | Claudia Patricia López Morales | `accounting_supervisor` | — | Supervisor |
| `maria.castillo` | María Fernanda Castillo Morales | `tax_specialist` | — | Fiscal |
| `luis.morales` | Luis Fernando Morales González | `accounts_receivable` | `treasury` | Cuentas por cobrar |
| `pedro.morales` | Pedro Alejandro Morales Cruz | `accounts_payable` | `payroll` | Cuentas por pagar |

**Roles adicionales.** La arquitectura permite varias asignaciones de rol activas por usuario: los permisos se suman (`WorkAuthorizationService::can`) y el rol principal, que decide el panel, es el último asignado. Por eso los roles adicionales se asignan primero. Así, Tesorería y Nómina quedan cubiertas sin superar los 8 asientos.

**Responsables por empresa** (`CompanyAssignmentService`, en modo estricto):

| Responsabilidad | Persona | Empresas |
|---|---|---|
| Contador encargado | Carlos | Todas |
| Auxiliar | Sofía | Todas |
| Supervisora | Claudia | Todas |
| Encargada fiscal | María | Todas |
| Cuentas por cobrar y tesorería | Luis | Todas |
| Cuentas por pagar | Pedro | Todas |
| Nómina | Pedro | Las empresas con planilla |

La Contadora General ve todas las empresas por su rol de alcance de oficina.

**Quién ejecuta cada paso** (permisos reales; nadie aprueba lo que preparó):

| Paso | Quién |
|---|---|
| Registrar ventas, compras, asientos y soportes | Auxiliar |
| Emitir ventas, cobrar, registrar notas de crédito y retenciones recibidas (`sales.process`) | Cuentas por Cobrar |
| Aprobar compras y registrar notas de crédito de proveedor (`purchases.approve`) | Cuentas por Pagar |
| Cuentas bancarias, estado de cuenta, cobros contra su depósito, órdenes de pago (preparación y ejecución con comprobante), clasificación de comisiones e intereses y conciliación | Tesorería |
| Aprobar cada orden de pago (`treasury.payments.approve`; quien la prepara no la aprueba) | Contador Encargado |
| Revisar asientos y aprobar el primer nivel; revisar la conciliación | Contador Encargado |
| Contabilizar (`journal.post`) y hacer la revisión superior | Contadora General |
| Rechazar asientos improcedentes, aprobación fiscal, cierre supervisado e incidencias | Supervisora |
| Preparar obligaciones y cerrar libros de IVA | Encargada Fiscal |
| Calcular y enviar la planilla | Nómina (Pedro) |
| Aprobar la planilla | Contador Encargado (persona distinta de quien calculó) |

**Contactos del cliente (portal).** No ocupan asientos de oficina: el sistema les asigna el rol `client_user`. Son 8 contactos, uno por cada una de las primeras 8 empresas, con correo `<usuario>@clientes-asesorescaribe.test` (por ejemplo, `julio.aldana`, `carlos.paiz`, `lilian.monroy`).

- Se crean con `ClientAccessAdminService::createContact`.
- Reciben una contraseña aleatoria propia (en la tabla final del seeder), y el sistema sigue exigiendo cambiarla y activar la doble autenticación al primer ingreso.
- Cada uno ve **solo su empresa**.

## Empresas (20)

| Sector | Empresas |
|---|---|
| Ferretería | Ferretería El Martillo, S.A. (con nómina) · Materiales de Construcción Morales (con nómina) |
| Distribuidora | Distribuidora El Progreso del Caribe, S.A. (con nómina) · Agroservicios del Norte |
| Comercio | Comercializadora del Caribe · Comercial La Esperanza · Abarrotería Doña Chepita |
| Industrial | Panificadora Santo Tomás, S.A. (producción, nómina y activo fijo) |
| Transporte | Transportes del Atlántico, S.A. (activo fijo) · Lanchas Express Livingston |
| Construcción | Constructora San José, S.A. (nómina y activo fijo) |
| Arrendamiento | Inversiones Puerto del Sol, S.A. |
| Servicios | Servicios Integrales de Izabal · Clínica Odontológica Sonrisa Caribe · Taller Industrial El Puerto |
| Restaurante | Restaurante La Ceiba · Mariscos y Restaurante El Muelle |
| Turismo | Hotel Brisas de Amatique · Tours Río Dulce Aventura |
| Logística | Logística Portuaria del Atlántico, S.A. |

Cada empresa tiene nombre comercial, NIT ficticio, dirección en Izabal, teléfono, correo `.test` y GTQ. Por crearse con el servicio de la pantalla Clientes, también tiene su catálogo de cuentas propio, perfil fiscal, impuestos, mapeo contable, períodos y responsables.

## Escenarios contables generados

**Libros (por empresa, tres meses):**

- **Apertura:** capital y, en las empresas con inventario, existencias iniciales. Lleva documento de soporte.
- **Operaciones:**
  - ventas al crédito y al contado, con cobros completos o parciales;
  - compras al crédito y al contado, con pagos por cheque o transferencia; facturas vencidas y cuentas por pagar abiertas;
  - notas de crédito de venta y de proveedor;
  - **retención de IVA recibida** de un cliente, a la tasa sugerida por `WithholdingService`;
  - caja chica.
- **Activos fijos:** compra financiada que queda pendiente de pago y depreciación mensual.
- **Bancos:**
  - depósitos, transferencias, cheques (uno en circulación), retiro y comisiones;
  - **intereses ganados** clasificados por Tesorería (con asiento automático y conciliados);
  - otros movimientos pendientes de conciliar;
  - conciliación del mes anterior revisada por el Contador.
- **Libros de IVA** cerrados por la Encargada Fiscal.

**Flujo de asientos** (cuatro empresas, mes en curso): borrador, pendiente de revisión, revisado, aprobado (sin contabilizar), contabilizado, rechazado por la Supervisora y un reverso con su asiento corregido.

**Inventario** (empresas de comercio, ferretería, distribución e industria):

- productos con unidad;
- existencias iniciales, entradas por compra (contabilizadas en 1201) y salidas por venta en el kardex valorizado;
- toma física al cierre del mes anterior con un faltante convertido en ajuste;
- costo de ventas generado por `postCosts`;
- órdenes de producción de pan de molde en la panificadora.

**Nómina** (5 empresas, 5 empleados ficticios cada una):

- **Meses anteriores:** se calculan con las reglas y parámetros de la aplicación. Los envía Nómina (Pedro) y los aprueba el Contador Encargado, generando el asiento de planilla.
- **Mes en curso:** queda calculado.

## Revisión del período y cierres

Se usan `CompanyPeriodReviewService` en doble nivel y el cierre supervisado de `SupervisorService` (que llama a `CloseAccountingPeriod`). La secuencia es:

**Auxiliar → Contador Encargado → Contadora General → Supervisora → cierre.**

| Mes | Escenario |
|---|---|
| Primer mes | **Cerrado** en todas las empresas por el flujo completo, salvo la última, que queda **lista para cierre** (contabilidad y fiscal aprobados). En la primera empresa el Contador Encargado **solicita la reapertura** |
| Mes anterior | Una etapa distinta según la empresa, en ciclo: sin iniciar · enviada · en revisión · con observaciones · aprobada en primer nivel (pendiente de revisión superior) · aprobada con fiscal pendiente · lista para cierre · con observaciones fiscales · cerrada |
| Mes en curso | Abierto |

## Obligaciones, tareas, incidencias y portal

- **Obligaciones:** calendario fiscal real (IVA, ISR, ISO según el régimen).
  - Las de meses cerrados las prepara la Encargada Fiscal, las aprueba su revisor (el Contador Encargado) y se presentan con una constancia en PDF de demostración.
  - Quedan IVA en revisión, con cambios solicitados, en proceso y uno vencido. Las del mes en curso quedan pendientes o próximas a vencer.
- **Tareas:** 9 por empresa, repartidas entre los 8 usuarios. Quedan completadas, en proceso, en revisión, con cambios solicitados, aprobadas, pendientes y vencidas, y todas las revisa una persona distinta del responsable.
- **Incidencias:** 3, abiertas por la Supervisora con el Contador como responsable.
- **Portal** (8 empresas):
  - una solicitud respondida con archivo y aceptada, y otra pendiente;
  - documentos subidos por el cliente: uno aceptado y otro por revisar;
  - una conversación con su respuesta;
  - una autorización aprobada por el cliente y otra pendiente;
  - el estado de resultados del primer mes, preparado por el Contador Encargado, aprobado y publicado por el propietario.
- **Auditoría:** cada acción queda en `audit_logs` porque se ejecuta con los servicios del sistema; no se fabrican registros.

## Validaciones

El seeder **no se ejecutó en este entorno**, porque el contenedor no puede instalar las dependencias de Composer. La prueba `tests/Feature/PlanProfesionalDemoSeederTest.php` comprueba:

1. Hay una sola oficina, exactamente 8 usuarios internos, plan `professional` y 20 empresas.
2. Los roles adicionales dan los permisos reales sin cambiar el panel principal, y la auxiliar no puede contabilizar.
3. Cada contacto del cliente accede solo a su empresa.
4. Ningún asiento está descuadrado y los estados financieros cuadran.
5. Las revisiones llegan a todas sus etapas, sin que quien envía apruebe ni quien aprueba el primer nivel haga la revisión superior.
6. Hay períodos cerrados y asientos, kardex, nómina, reportes publicados y tareas en todos los estados.
7. Una segunda ejecución no agrega registros.

## Limitaciones encontradas

- **Planillas pagadas y recibos de nómina.** El pago requiere enviar la planilla a Tesorería y ejecutar las órdenes de pago, y no se simula. Las planillas quedan aprobadas, con su asiento.
- **Reapertura.** Se deja como solicitud; su aprobación y ejecución quedan para probarse en pantalla.
- **Pasos rechazados.** Si una regla real rechaza un paso (por ejemplo, el checklist de un cierre), se deshace y se informa en el resumen; no se fuerza ningún estado.
- **Lo que no se ejecuta:** FEL, pagos, correos, bancos e IA. Las autorizaciones de DTE son UUID ficticios.
- **Plantillas compartidas.** Las operaciones por sector se toman de `PlanMultiOficinaDemoCatalog::sectors()`, por lo que algunas empresas tienen productos genéricos de su sector.
