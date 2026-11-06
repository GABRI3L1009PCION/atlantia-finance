# Seeder de demostración — Plan Corporativo

`database/seeders/PlanCorporativoDemoSeeder.php` crea un escenario operativo completo del plan **Corporativo** (`corporate`): una firma contable con **8 sedes**, **49 usuarios internos** (el plan admite 60), **4 roles personalizados**, **96 empresas cliente**, **3 grupos consolidados**, la API v1 y los webhooks.

Los datos del escenario (firma, sedes, equipo, roles personalizados, empresas, grupos y operaciones entre empresas) están en `database/seeders/PlanCorporativoDemoCatalog.php`. Las plantillas de operaciones por sector y de empleados se leen de `PlanMultiOficinaDemoCatalog`, sin modificarlo. Los seeders de los demás planes no se tocaron.

> **Datos de demostración.** En producción el seeder solo corre si el `.env` tiene `ATLANTIA_ALLOW_DEMO_SEED=true` (quítalo al terminar). Correos, notificaciones, colas y llamadas HTTP externas se simulan, así que nada sale del servidor.

## Ejecución

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=PlanCorporativoDemoSeeder
```

- Antes ejecuta `PlanCatalogSeeder` (planes y versiones) y `PayrollParameterSeeder` (parámetros de nómina).
- Las fechas se calculan desde hoy: los **dos meses anteriores** y el **mes en curso**.
- Al terminar imprime:
  - una tabla con lo creado por módulo;
  - el código HTTP de cada llamada de demostración a la API;
  - los pasos que una regla real del sistema no permitió completar, con la empresa y el motivo.
- Es **idempotente**: una segunda ejecución no duplica registros.
  - Lo que ya existe se reconoce por NIT, `request_id` determinista, URL, nombre o `automation_key`.
  - Las acciones de administración de una segunda ejecución las hace la Administradora General, no el Director.

La prueba automática que valida el escenario es esta:

```bash
php artisan test --compact tests/Feature/PlanCorporativoDemoSeederTest.php
```

## Firma y sedes

- **Firma:** Grupo Contable Atlántico Norte, NIT ficticio `6829413-5`.
- **Suscripción:** `active` al precio de `corporate`, ligada a su versión publicada y con perfil de facturación.
- **Sedes:**
  - la Oficina Central se crea como sede principal;
  - las otras 7 se crean con la acción real de la pantalla Oficinas (`WorkManagementController::storeOffice`), que valida el límite de 10 sedes del plan;
  - las políticas de cada sede se fijan con `OfficeAdminController::updatePolicies`: flujo de revisión, plazos fiscales, tramos de antigüedad de saldos y políticas de cierre.

| Código | Sede | Ciudad | Revisión del período |
|---|---|---|---|
| CEN-01 | Oficina Central (principal) | Puerto Barrios, Izabal | Doble nivel |
| STC-01 | Oficina Santo Tomás | Santo Tomás de Castilla, Izabal | Doble nivel |
| MOR-01 | Oficina Morales | Morales, Izabal | Un nivel |
| LIV-01 | Oficina Livingston | Livingston, Izabal | Un nivel |
| ZAC-01 | Oficina Zacapa | Zacapa | Doble nivel |
| CHQ-01 | Oficina Chiquimula | Chiquimula | Un nivel |
| COB-01 | Oficina Cobán | Cobán, Alta Verapaz | Doble nivel |
| GUA-01 | Oficina Ciudad de Guatemala | Guatemala | Doble nivel |

## Usuarios, roles y credenciales

- **Correo:** `<primer nombre>.<primer apellido>@grupocontableatlantico.test`; el detalle está en `PlanCorporativoDemoCatalog::team()`.
- **Contraseña:** cada usuario recibe una contraseña aleatoria propia al crearse. El seeder la muestra **una sola vez**, en una tabla al terminar; en la base solo queda su hash. Si el usuario ya existía, conserva su contraseña.
- **Contactos del portal:** usan el dominio `clientes-grupocontableatlantico.test` y también reciben una contraseña aleatoria propia, que aparece en la misma tabla. El sistema les exige cambiarla y activar la doble autenticación al primer ingreso.

**Roles de toda la firma:**
- Director propietario;
- Gerente Contable;
- Auditor;
- Tesorería;
- Cuentas por Cobrar;
- Cuentas por Pagar;
- Nómina;
- Administradora General: `office_admin` sin sede, la última en incorporarse.

**Roles por sede o grupo de sedes:**
- 2 especialistas fiscales:
  - oriente: CEN, STC, MOR, LIV;
  - occidente: ZAC, CHQ, COB, GUA;
- 4 contadores generales, cada uno con dos sedes;
- por cada sede:
  - un administrador de oficina;
  - un supervisor contable;
  - un contador encargado;
  - un auxiliar;
- un usuario de solo consulta en GUA-01.

**Roles personalizados:** se crean con la acción real de Roles y permisos (`storeRole`) y solo tienen permisos de consulta y reporte.
- Coordinador de Cumplimiento Contable.
- Analista de Control Interno.
- Revisor de Documentación: asignado en MOR-01.
- Coordinador de Reportes: asignado en CEN-01.

**Restricción del Director:**
- Mientras exista una administración general activa, el sistema deja al Director en solo supervisión (`isExecutiveReadOnly`).
- Por eso el propietario solo instala la firma: sedes, políticas, roles, empresas e integraciones.
- La Administradora General se incorpora al final.
- El seeder nunca usa al Director para operaciones que el sistema le niega.

## Empresas y contabilidad

- **Empresas:** 96, doce sectores por sede (arrendamiento, distribuidora, comercio, industrial, construcción, ferretería, restaurante, transporte, logística, servicios, turismo y tecnología).
  - Se crean con `CompanyAdministrationService`.
  - Cada una recibe sus responsables con `CompanyAssignmentService`: contador, auxiliar, supervisor, especialista fiscal, tesorería, cuentas por cobrar y por pagar, nómina, auditor en las de grupo y consulta en GUA.

**32 empresas con trimestre completo** (las cuatro primeras de cada sede):
- ventas y cobros;
- compras y pagos;
- notas de crédito;
- retenciones de IVA recibidas;
- bancos con comisiones, intereses y caja chica;
- conciliación bancaria;
- libros de IVA;
- kardex y toma física;
- producción en las industriales;
- nómina en distribuidoras e industriales;
- activos fijos con depreciación en construcción e industrial;
- partidas en todos los estados del flujo;
- revisión del período según el flujo de la sede: auxiliar → contador encargado → (doble nivel) contador general → supervisor fiscal y cierre supervisado.

**64 empresas con operación pequeña del mes en curso:** apertura, una venta al contado cobrada y una compra.

**Separación de funciones:** cada paso lo hace quien tiene el permiso real, y nadie aprueba lo que preparó.
- El auxiliar registra.
- Cuentas por Cobrar emite las facturas y Cuentas por Pagar aprueba las compras.
- Tesorería registra cada cobro contra su depósito, prepara y ejecuta los pagos (con comprobante) y concilia cada movimiento del banco.
- El Contador Encargado aprueba cada orden de pago: quien la prepara no la aprueba.
- Por eso el mes cerrado no tiene movimientos bancarios pendientes y el supervisor lo puede cerrar.
- El contador general de la sede contabiliza.

**Otros datos:**
- obligaciones fiscales en varios estados;
- 9 tareas por empresa en todos los estados;
- incidencias operativas;
- portal del cliente en 16 empresas (arrendamiento y distribuidora de cada sede):
  - solicitudes;
  - documentos;
  - conversación;
  - autorizaciones;
  - un estado de resultados preparado por el contador encargado y aprobado y publicado por el Director.

## Grupos consolidados

| Grupo | Controladora | Subsidiarias |
|---|---|---|
| Grupo Empresarial del Atlántico | Inversiones Puerto del Sol, S.A. | Distribuidora El Progreso, S.A. (100 %), Comercializadora del Caribe (80 %) |
| Corporación Comercial del Caribe | Inversiones Santo Tomás, S.A. | Agroindustrias del Atlántico, S.A. (100 %), Comercial La Esperanza (60 %) |
| Grupo de Inversiones del Norte | Inversiones Verapaz, S.A. | Café y Cardamomo de Cobán, S.A. (100 %), Comercial El Quetzal Verapacense (75 %) |

**Operaciones entre empresas:** cada mes, la controladora arrienda bodega y local a sus subsidiarias, y la primera subsidiaria vende mercadería a la segunda.
- La venta lleva el NIT exacto de la compradora.
- La compra lleva el NIT exacto de la vendedora.

El consolidado lo calcula la aplicación al consultarlo; el seeder solo registra la estructura con `ConsolidationService`.

## API v1

**Llaves creadas con `ApiTokenService`:**
- **ERP de Distribuidora El Progreso:** activa, limitada a esa empresa y con permisos de lectura y escritura de clientes y ventas.
- **Tablero de indicadores:** activa, solo lectura, para toda la firma.
- **Llave del proveedor anterior:** revocada.
- **Llave de la auditoría externa 2026:** vencida.

**Llamadas de demostración:** se hacen dentro del proceso a través del kernel HTTP de la aplicación, nunca por la red, y quedan en `api_request_logs`. El resultado esperado de cada una es este:
- 200 en las consultas, incluidos la balanza de comprobación y el estado de resultados del período;
- 201 al crear un cliente y un borrador de factura;
- 404 cuando la llave del ERP consulta otra empresa (la API no revela si existe);
- 403 cuando la llave de solo lectura pide clientes, un permiso que no tiene;
- 401 con las llaves revocada y vencida.

**Seguridad de las llaves:**
- En la base de datos solo queda su huella SHA-256 (`token_hash`).
- El valor utilizable solo existe en memoria mientras corre el seeder: no se imprime ni se escribe en este documento.

**Llaves de la prueba:** la prueba crea sus propias llaves temporales y verifica:
- 401 sin llave o con llave inválida;
- 404 por empresa no permitida y 403 por permiso faltante;
- 429 por límite por minuto;
- 402 con la suscripción cancelada.

## Webhooks

| Destino | URL (simulada) | Comportamiento |
|---|---|---|
| ERP de Distribuidora El Progreso | `https://erp.distribuidoraelprogreso.test/atlantia/webhooks` | Solo eventos de su empresa; el servidor simulado responde 200 |
| Tablero de indicadores de la firma | `https://bi.grupocontableatlantico.test/eventos` | Cierres de período y reportes publicados; responde 503, así que las entregas se reintentan y, tras 15 fallas seguidas, el sistema lo deshabilita |
| Integración anterior (en desuso) | `https://legado.grupocontableatlantico.test/hooks` | Deshabilitado |

- Los secretos se guardan cifrados y nunca se imprimen.
- Las entregas se firman con HMAC SHA-256 en la cabecera `Atlantia-Signature`.

**Cómo se evita cualquier salida a la red:**
- Los dominios `.test` no existen.
- Todo HTTP saliente lo responde `Http::fake` (con `preventStrayRequests`).
- Las entregas se procesan dentro del proceso.
- La cola se simula con `Queue::fake`, así que ningún trabajo hace una entrega real.
- La resolución DNS del validador de URL se desactiva solo en la configuración de ese proceso, porque los dominios `.test` no resuelven.
- El resto de reglas del validador sigue activo: `https` obligatorio y rechazo de localhost, `.local`, `.internal` e IP privadas.

La prueba verifica, con un destino temporal propio:
- la firma HMAC;
- el rechazo de URL inseguras o internas;
- el reintento con espera;
- la desactivación automática;
- el aislamiento por empresa y por organización.

## Capacitación

- 3 cursos: IVA y FEL (completado), cierre mensual (en curso) y planilla, IGSS, IRTRA e INTECAP (programado).
- Inscripciones de contadores, auxiliares y Nómina.
- 4 certificaciones.
- Una necesidad de capacitación en NIIF para PYMES.

## Lo que el seeder no hace

- No certifica FEL: las series, números y autorizaciones son ficticios.
- No cobra ni ejecuta pagos.
- No envía correos ni notificaciones (`Mail::fake` y `Notification::fake`).
- No llama a bancos ni consulta Luzma IA.
- No hace solicitudes HTTP externas ni ejecuta webhooks reales.
- No desactiva RLS, triggers ni validaciones.
- No ejecuta `migrate:fresh` ni comandos destructivos.
- No guarda credenciales en texto plano.

## Validaciones realizadas

**Ejecutado:**
- `php -l` sobre `PlanCorporativoDemoSeeder.php`, `PlanCorporativoDemoCatalog.php` y `PlanCorporativoDemoSeederTest.php`: sin errores de sintaxis.
- Una comprobación en PHP puro del catálogo, sin base de datos:
  - 96 empresas, 32 con contabilidad completa;
  - 49 miembros con correo único;
  - NIT sin duplicados;
  - las 9 empresas de los grupos existen y tienen contabilidad completa.

**No ejecutado:** el seeder y la prueba Pest (por indicación expresa y porque el entorno no tenía las dependencias de Composer instaladas). Tampoco se ejecutaron Pint ni PHPStan. Antes de usarlo, conviene correr:

```bash
php artisan test --compact tests/Feature/PlanCorporativoDemoSeederTest.php
```

```bash
vendor/bin/pint --dirty --format agent
```
