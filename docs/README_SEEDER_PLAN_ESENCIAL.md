# Seeder de demostración — Plan Esencial

`database/seeders/PlanEsencialDemoSeeder.php` crea un escenario completo del Plan Esencial: una oficina contable de Puerto Barrios, Izabal, con sus dos usuarios, una suscripción activa al plan Esencial y diez empresas cliente con tres meses de contabilidad.

Los datos ficticios están en `database/seeders/PlanEsencialDemoCatalog.php`: nombres, NIT, direcciones, teléfonos, clientes, proveedores, ventas y compras.

> **Datos de demostración.** En producción el seeder solo corre si el `.env` tiene `ATLANTIA_ALLOW_DEMO_SEED=true` (quítalo al terminar). Correos, notificaciones, colas y llamadas HTTP externas se simulan, así que nada sale del servidor.

## Ejecución

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=PlanEsencialDemoSeeder
```

- El seeder ejecuta antes `PlanCatalogSeeder`, para que exista el plan Esencial con su versión publicada.
- La fecha de los datos se calcula desde el día actual: son los **dos meses anteriores** y el **mes en curso**. Los días futuros del mes en curso se omiten.
- Al final imprime una tabla con la cantidad de registros creados y los pasos que una regla del sistema no permitió completar, si hubo alguno.

La prueba automática que valida el escenario es esta:

```bash
php artisan test --compact tests/Feature/PlanEsencialDemoSeederTest.php
```

## Credenciales de demostración

| Usuario | Correo | Rol |
|---|---|---|
| José Miguel Ramírez López | `jose.ramirez@contadoresdelatlantico.test` | Propietario (`owner` / Director-Socio) |
| María Fernanda Castillo Morales | `maria.castillo@contadoresdelatlantico.test` | Contadora Encargada (`accountant_in_charge`) |

- **Contraseña:** cada usuario recibe una contraseña aleatoria propia al crearse. El seeder la muestra **una sola vez**, en una tabla al terminar; en la base solo queda su hash. Si el usuario ya existía, conserva su contraseña.
- **Organización:** Contadores Asociados del Atlántico, NIT ficticio `9081726-3`. Tiene una oficina: Oficina Puerto Barrios.

## Qué genera

| Área | Contenido |
|---|---|
| Plan | Suscripción manual `active` al precio mensual del plan Esencial, ligada a su versión publicada. Respeta los límites: 1 oficina, 2 usuarios y 10 de las 150 empresas permitidas. No consume consultas de Luzma |
| Equipo | Roles `owner_director` y `accountant_in_charge`, los únicos que admite el plan sin el módulo de roles. La contadora tiene asignadas 7 empresas; el propietario lleva 3 |
| Empresas | 10 empresas de Izabal (comercio, distribución, ferretería, transporte, arrendamiento, salud, restaurante, agencia aduanal y taller), con nombre comercial, NIT, dirección, teléfono, correo, GTQ, perfil fiscal, catálogo de cuentas, mapeo contable y 3 períodos |
| Contabilidad | Asiento de apertura con soporte, ventas al crédito y al contado, ingresos por servicios, compras al crédito y al contado, alquileres, servicios básicos, honorarios, cobros, pagos, notas de crédito de venta y de proveedor, retiro para caja chica y comisiones |
| Flujo de revisión | Asientos en borrador, en revisión, revisados, aprobados y contabilizados, rechazados, y un reverso con su asiento corregido. Los ajustes los prepara la contadora y los aprueba el propietario |
| Bancos | Una cuenta por empresa con depósitos, transferencias, cheques, retiros y comisiones. La comisión del primer mes queda clasificada y conciliada; el resto queda pendiente. Hay una conciliación del mes anterior con un cheque en circulación y una comisión por registrar |
| IVA | Libros de IVA cerrados con su liquidación (IVA débito y crédito) |
| Cruce con la SAT | Listado de DTE del mes anterior con una factura recibida que falta en los libros |
| Períodos | El primer mes de cada empresa se cierra con el procedimiento real: checklist y fotografía contable. Los otros dos quedan abiertos |
| Obligaciones | Calendario fiscal real. Las obligaciones pasadas se presentan con su constancia. Hay obligaciones en revisión, en proceso y una vencida |
| Comercial | Cotizaciones enviadas, aceptadas (convertidas en factura en borrador) y rechazadas, una orden de venta y una orden de compra |
| Tareas | Por empresa: una completada, una en proceso, una en revisión (o en proceso si la lleva el propietario), una pendiente y una vencida |

Todo se registra con las mismas acciones de las pantallas (`SalesController`, `PurchaseController`, `BankController`, `AccountingDocumentController`) y con los servicios del sistema. Por eso los asientos automáticos, el IVA, los saldos, los libros y los reportes salen de los documentos, no de cifras insertadas a mano.

## Seguridad e integridad

- **Idempotente:** cada registro usa una llave determinística (UUID v5 o `automation_key`) y se busca antes de crearlo. Volver a ejecutar el seeder no duplica nada.
- **Aislado:** solo escribe en su propia organización. La prueba verifica que otra oficina no cambia y que la seguridad por filas (RLS) oculta estas empresas a otras organizaciones.
- **Transaccional:** cada empresa y cada paso corren en una transacción. Si una regla del sistema rechaza un paso, se deshace y se informa en el resumen; nunca se fuerza un estado.
- **Sin servicios externos:** no certifica FEL, no cobra, no envía correos y no se conecta a bancos. Las series, los números y las autorizaciones de los DTE son **ficticios**, con formato de UUID, y no corresponden a documentos certificados.
- **Sin cambios al sistema:** no modifica migraciones ni reglas de negocio.

## Verificación

El seeder **no se ejecutó en este entorno**, porque el contenedor no tiene las dependencias de Composer instaladas. La prueba `tests/Feature/PlanEsencialDemoSeederTest.php` comprueba lo siguiente:

1. Hay 2 usuarios activos, 1 oficina y 10 empresas.
2. Ningún asiento está descuadrado (suma del debe = suma del haber).
3. El estado financiero de cada empresa cuadra y tiene ingresos.
4. Hay períodos cerrados y asientos en todos los estados del flujo.
5. Hay tareas en sus distintos estados, obligaciones presentadas, DTE del cruce con la SAT y documentos comerciales.
6. Una segunda ejecución no agrega registros.
7. No se altera otra organización y RLS aísla las empresas.

Ejecútala en una base de pruebas con PostgreSQL (el rol de la aplicación sin `BYPASSRLS`). Si algún paso queda rechazado por una regla real del sistema, el resumen final del seeder lo indica con la empresa y el motivo.
