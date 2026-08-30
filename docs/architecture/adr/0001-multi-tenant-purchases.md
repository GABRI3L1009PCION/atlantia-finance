# ADR-0001: aislamiento multiempresa del módulo de compras

- Estado: Aceptado
- Fecha: 2026-08-30
- Responsables: equipo Atlantia Finance

## Contexto

Atlantia Finance administra varias organizaciones, oficinas y empresas cliente dentro de una misma instalación. Compras contiene información financiera, proveedores, documentos, pagos, archivos y asientos contables que nunca deben cruzar los límites de una empresa.

La autorización debe ser verificable en el servidor. Los identificadores recibidos por ruta o formulario no son evidencia de pertenencia y no se confía en filtros del cliente.

## Decisión

Se adopta un aislamiento jerárquico y denegación por defecto:

```text
usuario -> organización activa -> oficina activa -> asignación de empresa
        -> proveedor/documento/pago/archivo/asiento de esa empresa
```

1. `WorkspaceContext` resuelve una organización activa con suscripción activa y verifica la función `basic_accounting` del plan.
2. `AccountingService::company()` limita la empresa a la organización y oficina activas. Los miembros requieren asignación explícita en `company_user` y oficina activa en `office_user`.
3. Cada consulta de compras parte de `company_id`. Los documentos, pagos, archivos y asientos secundarios se resuelven desde un registro padre ya limitado.
4. Los propietarios pueden leer y operar todas las empresas de su organización. Los editores solo operan empresas asignadas. Los visores solo leen.
5. Aprobar o anular documentos, anular pagos y cambiar el mapeo contable son acciones exclusivas del propietario.
6. Una empresa fuera del alcance responde `404` para no revelar su existencia. Una capacidad conocida pero no autorizada responde `403`.
7. Las escrituras financieras usan transacciones y bloqueo de la empresa. Los importes se convierten a centavos enteros.
8. Los archivos se almacenan en el disco privado bajo `purchases/{company_public_id}/{document_public_id}` y se descargan únicamente después de volver a autorizar empresa, documento y archivo.
9. La aprobación, pagos y reversos crean vínculos en `accounting_source_entries` y eventos en `audit_logs`; no se borran movimientos contables para corregirlos.

## Consecuencias

- Toda ruta nueva de compras debe reutilizar el alcance central y agregarse a la matriz de autorización y a las pruebas multiempresa.
- Una consulta que omita `company_id` o que acepte directamente un identificador secundario queda fuera de la arquitectura aprobada.
- La denegación temprana puede producir `403` antes de consultar una empresa en operaciones reservadas al propietario; esto no revela existencia porque ocurre para cualquier identificador.
- El modelo favorece seguridad y trazabilidad sobre consultas globales implícitas. Los reportes consolidados futuros deberán tener un caso de uso y permiso explícitos.

## Alternativas descartadas

- Confiar en UUID públicos: evita enumeración simple, pero no sustituye autorización.
- Filtrar únicamente en React/Inertia: el cliente es manipulable.
- Aplicar alcance solo al documento: permitiría referencias cruzadas de proveedores, cuentas o pagos.
- Una base por empresa: mejora aislamiento físico, pero eleva operación y migraciones sin ser necesario para el alcance actual.

## Evidencia

- `tests/Feature/PurchasesTenantIsolationTest.php`
- `tests/Feature/PurchasesTest.php`
- `docs/security/authorization-matrix-purchases.md`
- `docs/quality/purchases-traceability-matrix.md`

