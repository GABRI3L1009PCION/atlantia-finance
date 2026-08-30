# Matriz requisito-prueba-evidencia: compras

| Requisito | Criterio verificable | Prueba automatizada | Evidencia |
|---|---|---|---|
| PUR-TEN-001 | Ningún endpoint expone o modifica compras de otra organización | `an owner from another organization cannot reach any purchase endpoint` | 13 respuestas `404`, huella de BD idéntica y archivo intacto |
| PUR-TEN-002 | Un miembro solo accede a empresas asignadas dentro de su organización | `a member assigned to another company in the same organization cannot reach any target purchase endpoint` | 10 respuestas `404`, 3 respuestas `403`, sin mutaciones |
| PUR-AUTH-003 | El visor puede leer cartera, detalle, PDF, exportación y archivo, pero no escribir | `a company viewer can use every read endpoint but no purchase mutation endpoint` | 5 lecturas `200`, 8 escrituras `403`, huella idéntica |
| PUR-AUTH-004 | El editor realiza operación diaria, pero no aprueba, anula ni remapea | `a company editor can perform operational writes but cannot approve void or remap accounting` | Lecturas `200`, 5 redirecciones exitosas, 3 acciones críticas `403` |
| PUR-AUTH-005 | Un invitado no llega a controladores de compras | `guests are redirected before every purchase endpoint is evaluated` | 13 redirecciones a `login` |
| PUR-DATA-006 | Proveedor, cuenta y documento pertenecen a la empresa autorizada | `purchase approval payment and cancellation remain balanced and traceable` | Validaciones de BD en `PurchasesTest.php` |
| PUR-ACC-007 | Aprobación y pago generan partida doble y vínculo de origen | Misma prueba de ciclo de compras | Débitos = créditos y filas `accounting_source_entries` |
| PUR-REV-008 | Anular pago conserva historial y crea reverso | Misma prueba de ciclo de compras | Pago marcado anulado y asiento original en estado `void` |
| PUR-SCHEMA-009 | El esquema completo existe y una migración crea una tabla | Pruebas de esquema en `PurchasesTest.php` | 46 migraciones de creación y columnas de compras verificadas |
| SEC-SAST-010 | Código PHP/JS/TS se analiza estáticamente en cada cambio | Job `sast` | Semgrep CE con fallo ante hallazgos |
| SEC-SCA-011 | Dependencias conocidas vulnerables bloquean CI | Jobs `dependency-audit` y `dependency-review` | Composer, npm y revisión diferencial de PR |
| SEC-SECRET-012 | Credenciales verificadas en historial bloquean CI | Job `secret-scan` | TruffleHog con historial completo y `--fail` |

## Comandos de reproducción

```bash
php artisan test --compact tests/Feature/PurchasesTenantIsolationTest.php
php artisan test --compact tests/Feature/PurchasesTest.php
composer ci:check
composer audit --locked --abandoned=fail
npm audit --audit-level=high
```

La evidencia del pipeline se conserva en los resultados de GitHub Actions asociados al commit o pull request. Un control omitido o marcado `continue-on-error` no satisface esta matriz.

