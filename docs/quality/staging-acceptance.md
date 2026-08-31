# Evidencia y aceptación de staging

## Revisión técnica

| Control               | Evidencia esperada                                                       | Estado                           |
| --------------------- | ------------------------------------------------------------------------ | -------------------------------- |
| Calidad de código     | `composer ci:check`                                                      | Aprobado el 2026-08-31           |
| Pruebas automatizadas | Suite Pest completa                                                      | 95 pruebas, 1088 aserciones      |
| SAST                  | Semgrep sin hallazgos bloqueantes                                        | 0 hallazgos en 313 archivos      |
| Secretos              | TruffleHog sin hallazgos                                                 | 0 hallazgos en cambios locales   |
| HTTPS                 | Certificado válido y `/up` responde 200                                  | Pendiente de infraestructura     |
| Depuración            | `staging:preflight` confirma `APP_DEBUG=false`                           | Prueba automatizada aprobada     |
| Cookies               | `Secure`, `HttpOnly`, `SameSite` y cifrado habilitados                   | Prueba automatizada aprobada     |
| Migración             | `migrate --force` sin errores                                            | 48 migraciones aprobadas         |
| Rollback              | Migración reversible en base desechable y rollback de release disponible | Rollback y remigración aprobados |
| Acceso cruzado        | `PurchasesTenantIsolationTest`                                           | 5 pruebas aprobadas              |

La comprobación combinada de preflight, humo local y acceso cruzado ejecutó 8 pruebas con 96 aserciones. La migración, rollback y remigración se hicieron sobre una base SQLite temporal; no se modificó la base PostgreSQL local.

## Resultado de la revisión de código

- No hay hallazgos bloqueantes de formato, tipos, análisis estático o SAST.
- El primer intento detectó una lectura de configuración demasiado temprana durante el bootstrap; se corrigió y se volvió a ejecutar la suite completa.
- El despliegue no copia `.env` ni secretos de aplicación al artefacto.
- El rollback automático restaura código únicamente. Un rollback de esquema exige respaldo y aprobación humana para evitar pérdida de datos.
- Falta validar el certificado, HSTS, migración y humo en la infraestructura real de staging.

## Registro de publicación

- SHA desplegado:
- URL de staging:
- Workflow:
- Fecha y hora:
- Responsable técnico:
- Resultado de humo:
- Migraciones aplicadas:
- Release anterior verificado:
- Incidencias o riesgos aceptados:

## Aceptación funcional

El Product Owner o asesor confirma que revisó en staging el inicio de sesión, el módulo de compras, los roles y el aislamiento entre empresas; además, revisó la evidencia técnica anterior.

- Decisión: [ ] Aceptado [ ] Rechazado [ ] Aceptado con observaciones
- Nombre del Product Owner o asesor:
- Observaciones:
- Fecha:
- Firma o enlace a la aprobación registrada:

La aceptación no se considera completa hasta que una persona autorizada llene esta sección o apruebe el deployment en GitHub. El autor del cambio no debe autoaprobar la aceptación final.
