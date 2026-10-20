# Página de fuera de horario con Cloudflare

Con este Worker puedes **apagar el servidor** fuera de horario: Cloudflare responde con la página de cierre sin necesitar la aplicación.

## Qué hace

| Situación | Respuesta |
| --- | --- |
| Fuera de horario (por defecto antes de las 6:00 AM y desde las 11:00 PM, hora de Guatemala) | Página de cierre (503), sin contactar al servidor |
| Dentro de horario y el servidor responde | La aplicación, sin cambios |
| Dentro de horario pero el servidor no responde (errores 521, 522, 523, 524…) | La misma página con el mensaje "nuestro servicio no está disponible", en lugar de la pantalla de error de Cloudflare |
| `/up`, `/webhooks/*`, `/api/*`, `/calendar/*` | Siempre pasan al servidor |

Mientras el servidor esté apagado, los pagos (webhooks de Recurrente) y la API no pueden responder. Recurrente reintenta los webhooks fallidos; los clientes de la API recibirán el error de Cloudflare.

## Instalación (una sola vez)

1. En Cloudflare entra a **Workers & Pages** > **Create** > **Create Worker**. Ponle el nombre `atlantia-after-hours` y pulsa **Deploy**.
2. Pulsa **Edit code**, borra el contenido de ejemplo, pega el contenido de `after-hours-worker.js` y pulsa **Deploy**.
3. Abre el Worker > **Settings** > **Domains & Routes** > **Add** > **Route**:
   - Route: `finance.atlantiasystems.com/*`
   - Zone: `atlantiasystems.com`
4. Comprueba que el registro DNS de `finance.atlantiasystems.com` esté con la **nube naranja** (proxied). Sin eso el Worker no recibe el tráfico.
5. En Cloudflare, en **SSL/TLS**, el modo debe seguir como lo tienes hoy; el Worker reenvía las peticiones con el mismo esquema.
6. En el servidor de la aplicación deja `SERVICE_HOURS_ENABLED=false` en el `.env`. El Worker ya controla el horario; activar los dos a la vez no hace daño, pero es redundante.

## Variables opcionales

En el Worker > **Settings** > **Variables and Secrets**:

| Nombre | Uso |
| --- | --- |
| `FORCE_CLOSED` | `true` cierra la plataforma ahora mismo, a cualquier hora |
| `BYPASS_TOKEN` | Guárdalo como **Secret**. Abre `https://finance.atlantiasystems.com/?bypass=TU_SECRETO` una vez y podrás entrar fuera de horario durante 12 horas (por ejemplo, el equipo de Atlantia al Control Center) |
| `OPENS_AT` / `CLOSES_AT` | Horario en formato `HH:MM`, hora de Guatemala. Por defecto `06:00` y `23:00` |

## Cómo probarlo

1. Pon `FORCE_CLOSED` en `true` y abre el sitio: debe verse la página de cierre.
2. Quita la variable (o ponla en `false`): el sitio vuelve a la normalidad.
3. Apaga el servidor en horario de servicio: debe verse la página con el mensaje "no está disponible", no la pantalla de Cloudflare.

## Límites del plan gratuito

El plan gratuito de Workers permite 100 000 peticiones al día, suficiente para este uso. Si se supera, Cloudflare deja pasar el tráfico directo al servidor.
