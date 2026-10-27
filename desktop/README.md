# Atlantia Finance para escritorio

Aplicación de Windows y Mac. Con conexión muestra Atlantia Finance igual que el navegador. En segundo plano guarda
una copia cifrada de las empresas de la persona. Si el servidor o el internet fallan, cambia sola al modo sin conexión:
ahí se consultan saldos, cuentas por cobrar y por pagar, movimientos y obligaciones, y se capturan ventas, compras y
asientos. Al volver la conexión, las capturas se suben y quedan como borradores para revisión.

## Desarrollo

```bash
cd desktop
npm install
npm test          # lógica del modo sin conexión (cifrado, validaciones, conexión)
npm start         # abre la app contra el servidor de producción
ATLANTIA_URL=http://127.0.0.1:8000 npm start   # contra un servidor local
```

## Instaladores

El flujo `.github/workflows/desktop.yml` genera `AtlantiaFinance-<versión>-Windows.exe` y los `.dmg` de Mac
(Intel y Apple Silicon). Se ejecuta desde la pestaña Actions ("Run workflow") o al publicar una etiqueta `desktop-vX.Y.Z`.

Los instaladores no están firmados. Windows muestra "Windows protegió su PC" (Más información → Ejecutar de todas
formas) y macOS pide abrirlo con clic derecho → Abrir. Para quitar esas advertencias hace falta un certificado de
firma de código para Windows y una cuenta de Apple Developer para Mac.

## Seguridad

- La copia se cifra con AES-256-GCM. La clave la protege el sistema operativo (DPAPI en Windows, Llavero en Mac),
  así que los archivos no sirven en otra computadora.
- Cada persona guarda solo sus empresas y solo lo que su rol puede ver.
- La copia se bloquea si pasan 15 días sin conectarse.
- Desde el servidor se puede desconectar un equipo: la próxima vez que se conecte, borra su copia.
