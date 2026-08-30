# Modelo de amenazas: compras multiempresa

## Alcance y activos

El análisis cubre las rutas web de proveedores, documentos de compra, pagos, archivos, exportaciones y automatización contable. Los activos principales son datos de proveedores, importes y vencimientos, documentos privados, saldos, mapeos contables, asientos y bitácoras de auditoría.

## Actores y fronteras de confianza

- Invitado no autenticado.
- Usuario autenticado de otra organización.
- Miembro de la misma organización sin asignación a la empresa.
- Visor, editor y propietario de la empresa.
- Navegador/Inertia, aplicación Laravel, almacenamiento privado y PostgreSQL.
- Proveedores externos usados por CI para reglas SAST, avisos de dependencias y verificación de secretos.

Las fronteras están entre navegador y Laravel, contexto de organización y empresa, empresa y registros secundarios, Laravel y almacenamiento, y repositorio y servicios de seguridad del pipeline.

## Amenazas y controles

| ID    | STRIDE                | Amenaza                                                    | Control preventivo/detectivo                                            | Riesgo residual                                          |
| ----- | --------------------- | ---------------------------------------------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------- |
| TM-01 | Suplantación          | Usar una sesión ajena o inactiva                           | Autenticación, membresía y suscripción activas                          | Robo de sesión fuera del módulo                          |
| TM-02 | Elevación             | Visor intenta crear o modificar compras                    | Permiso `editor` o rol `owner` verificado en servidor                   | Error futuro al añadir una ruta sin `scope(write: true)` |
| TM-03 | Elevación             | Editor aprueba, anula o cambia cuentas                     | `scope(owner: true)` antes de la acción sensible                        | Compromiso de cuenta propietaria                         |
| TM-04 | Divulgación           | Usuario cambia UUID para leer otra organización            | Empresa limitada por organización/oficina; respuesta `404`              | Canales laterales de tiempo no medidos                   |
| TM-05 | Divulgación           | Miembro consulta otra empresa de la misma oficina          | Asignación explícita `company_user` y pruebas de todos los endpoints    | Asignación administrativa errónea                        |
| TM-06 | Manipulación          | Proveedor, cuenta, pago o archivo pertenece a otra empresa | Resolución desde padre limitado y consultas con `company_id`            | SQL nuevo sin el patrón aprobado                         |
| TM-07 | Manipulación          | Pago mayor al saldo o importes ambiguos                    | Centavos enteros, validación de saldo, fecha y estado, transacción      | Condiciones de carrera fuera del bloqueo empresarial     |
| TM-08 | Repudio               | Aprobar, pagar o anular sin evidencia                      | `audit_logs`, usuario, fecha, referencia y `accounting_source_entries`  | Retención/monitoreo operativo pendiente                  |
| TM-09 | Divulgación           | Descarga directa de un archivo privado                     | Disco local privado y autorización encadenada empresa-documento-archivo | Malware dentro de un archivo permitido                   |
| TM-10 | Manipulación          | Alterar un respaldo después de aprobar                     | Archivos de documentos aprobados inmutables y SHA-256 almacenado        | No existe firma digital externa                          |
| TM-11 | Denegación            | Abusar exportaciones o cargas                              | Límites de tamaño, tipos permitidos y `throttle` por ruta               | Ataque distribuido requiere control perimetral           |
| TM-12 | Cadena de suministro  | Introducir dependencia vulnerable                          | `composer audit`, `pnpm audit` y revisión de dependencias en PR         | Avisos aún no publicados                                 |
| TM-13 | Ejecución/divulgación | Introducir patrón inseguro o secreto                       | Semgrep SAST y TruffleHog en CI                                         | Falsos negativos de reglas/detectores                    |

## Escenarios de abuso obligatorios

1. Propietario de organización A prueba los 13 endpoints contra empresa B: todos deben devolver `404` sin mutaciones.
2. Editor asignado a empresa A prueba empresa B de la misma organización: lectura/escritura devuelve `404`; operaciones exclusivas devuelven `403` antes de resolver empresa.
3. Visor asignado usa las cinco lecturas y prueba las ocho mutaciones: lecturas permitidas y mutaciones `403`.
4. Editor asignado usa lecturas y cinco escrituras operativas; tres acciones críticas permanecen `403`.
5. Invitado prueba todas las rutas: redirección a autenticación antes de procesar entradas.

## Revisión y aceptación de riesgo

- Revisar este documento cuando cambie una ruta, rol, proveedor de archivos o automatización contable.
- No aceptar excepciones de SAST, auditoría o secretos sin responsable, justificación, vencimiento y enlace al hallazgo.
- Riesgos de malware, retención y monitoreo se consideran pendientes de controles operativos posteriores; no se presentan como resueltos por estas pruebas.
