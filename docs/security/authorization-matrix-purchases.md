# Matriz de autorización: módulo de compras

## Convenciones

- `302 login`: autenticación requerida.
- `404`: el recurso queda oculto fuera del alcance organizacional o empresarial.
- `403`: el usuario pertenece al alcance general, pero carece del permiso requerido.
- `R`: lectura autorizada.
- `W`: escritura operativa autorizada.
- `O`: acción crítica exclusiva del propietario.

| Operación / ruta nombrada | Invitado | Propietario de otra organización | Miembro sin asignación a empresa | Visor asignado | Editor asignado | Propietario |
|---|---:|---:|---:|---:|---:|---:|
| Cartera `accounting.purchases` | 302 login | 404 | 404 | R | R | R |
| Exportar `accounting.purchasesExport` | 302 login | 404 | 404 | R | R | R |
| Ver documento `accounting.purchaseShow` | 302 login | 404 | 404 | R | R | R |
| PDF `accounting.purchasePdf` | 302 login | 404 | 404 | R | R | R |
| Descargar archivo `accounting.purchaseFileDownload` | 302 login | 404 | 404 | R | R | R |
| Crear proveedor `accounting.purchaseSupplier` | 302 login | 404 | 404 | 403 | W | W |
| Crear documento `accounting.purchaseStore` | 302 login | 404 | 404 | 403 | W | W |
| Registrar pago `accounting.purchasePayment` | 302 login | 404 | 404 | 403 | W | W |
| Adjuntar archivo `accounting.purchaseFileStore` | 302 login | 404 | 404 | 403 | W | W |
| Retirar archivo de borrador `accounting.purchaseFileDestroy` | 302 login | 404 | 404 | 403 | W | W |
| Aprobar/anular documento `accounting.purchaseProcess` | 302 login | 404 | 403 | 403 | 403 | O |
| Anular pago `accounting.purchasePaymentVoid` | 302 login | 404 | 403 | 403 | 403 | O |
| Cambiar mapeo `accounting.accountingMapping` | 302 login | 404 | 403 | 403 | 403 | O |

## Reglas adicionales

- La pertenencia a la organización no concede acceso automático a una empresa.
- El permiso de empresa tampoco sustituye una asignación activa a su oficina.
- Toda lectura y escritura vuelve a resolver la empresa en el servidor.
- Los campos `company_id`, `created_by`, estados, numeración y referencias se derivan o validan en el servidor.
- Los controles visuales del frontend son informativos; esta matriz se aplica en Laravel.

