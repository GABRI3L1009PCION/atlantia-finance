# Producción en AWS

Atlantia Finance corre en AWS detrás de un balanceador de carga (ALB), con el dominio en Cloudflare. Esta guía lista lo que hace falta para publicarla sin sorpresas. El manual de un solo servidor está en `staging-runbook.md`.

## Arquitectura

| Pieza | Uso |
| --- | --- |
| Cloudflare (nube naranja) | Dominio, TLS público y página fuera de horario (`cloudflare/README.md`) |
| ALB | Reparte el tráfico; solo acepta conexiones desde las IP de Cloudflare |
| EC2 (una o más) | Nginx + PHP-FPM 8.3, el programador (`schedule:run`) y el worker de colas |
| RDS PostgreSQL 16+ | Base de datos con Row Level Security |
| EFS | Carpeta compartida para los archivos privados (`storage/app/private`) |
| S3 privado | Respaldos diarios (`BACKUP_S3_URI`) |
| Atlantia AI | Servicio local de Luzma (`services/atlantia-ai`), en la misma red privada |

Los documentos subidos se guardan en el disco `local` (`storage/app/private`). **Con más de una instancia, o si la instancia puede reemplazarse, esa carpeta debe ser un volumen EFS montado en todas las instancias**; si no, los archivos se pierden o dan 404. Con una sola instancia fija basta su volumen EBS más los respaldos diarios.

## Variables de entorno

Los valores reales viven solo en el servidor (archivo `.env` con permisos `600`, o AWS Systems Manager Parameter Store / Secrets Manager). Nunca se suben al repositorio.

| Variable | Valor en producción |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | Generada una vez con `php artisan key:generate --show`; igual en todas las instancias |
| `APP_URL` / `APP_FORCE_HTTPS` | `https://tu-dominio` / `true` |
| `APP_TIMEZONE` | `America/Guatemala` |
| `TRUSTED_PROXIES` | CIDR de la VPC del ALB, por ejemplo `10.0.0.0/16` |
| `TRUST_CLOUDFLARE_PROXIES` | `true` (el dominio pasa por Cloudflare) |
| `LOG_LEVEL` | `warning` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` | `pgsql` y los datos de RDS |
| `DB_USERNAME` / `DB_PASSWORD` | `atlantia_finance_user` y su contraseña (rol sin `BYPASSRLS`) |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (compartidos entre instancias) |
| `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` | `true` |
| `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT` | `smtp`, `smtps`, `smtp.resend.com`, `465` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | `resend` / la API key de Resend |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | Un remitente del dominio verificado en Resend |
| `QUOTE_REQUEST_EMAIL` | Correo que recibe las cotizaciones del plan Empresarial |
| `RECURRENTE_SECRET_KEY` | Llave secreta en vivo de Recurrente |
| `RECURRENTE_WEBHOOK_SECRET` | Secreto del webhook (`whsec_…`) del endpoint `https://tu-dominio/webhooks/recurrente` |
| `RECURRENTE_ENVIRONMENT` | `live` |
| `FEL_PROVIDER` | `sat_manual` |
| `FEL_PLATFORM_NAME`, `FEL_PLATFORM_NIT`, `FEL_PLATFORM_ADDRESS` | Datos fiscales de Atlantia como emisor |
| `BACKUP_ENABLED`, `BACKUP_DESTINATION`, `BACKUP_S3_URI`, `BACKUP_RETENTION_DAYS` | `true`, carpeta local, bucket privado, `30` |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` | Solo si la instancia no usa un rol IAM para S3 |
| `ATLANTIA_AI_URL`, `LUZMA_PROVIDERS` | URL privada de Atlantia AI, `ollama` |
| `INTEGRATIONS_HIDDEN_PROVIDERS` | `gmail,outlook,dropbox,hubspot` |

No uses `MAIL_MAILER=resend`: ese transporte necesita el paquete `resend/resend-php`, que no está instalado. El SMTP de Resend entrega los mismos correos.

## PHP

Extensiones obligatorias: `bcmath`, `mbstring`, `pdo_pgsql`, `intl`, `gd`, `zip` y `opcache`. Configuración recomendada (`php.ini` de FPM y CLI):

```ini
expose_php = Off
display_errors = Off
log_errors = On
session.upload_progress.enabled = Off
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 512M
max_execution_time = 60
opcache.enable = 1
opcache.validate_timestamps = 0
```

`upload_max_filesize` cubre el archivo más grande que acepta la aplicación (20 MB). Con `opcache.validate_timestamps = 0`, reinicia PHP-FPM en cada despliegue.

## Red y seguridad

1. El grupo de seguridad del ALB acepta 443 solo desde las IP de Cloudflare (https://www.cloudflare.com/ips/).
2. Las instancias aceptan tráfico solo desde el ALB; RDS y EFS solo desde las instancias.
3. En Cloudflare, SSL/TLS en modo **Full (strict)** con un certificado de ACM en el ALB.
4. El webhook de Recurrente (`/webhooks/recurrente`) debe quedar accesible las 24 horas.

## Primer despliegue

1. Crear la base de datos y el rol de la aplicación como superusuario de RDS:
   `psql -U postgres -h <host-rds> -v app_password="'<contraseña>'" -f database/postgres/provision.sql`
2. Montar EFS en `storage/app/private` (si hay más de una instancia).
3. Instalar dependencias y compilar:
   `composer install --no-dev --optimize-autoloader` y `npm ci && npm run build`
4. Migrar y sembrar la base limpia (con `APP_ENV=production` no se cargan datos de demostración):
   `php artisan migrate --force` y `php artisan db:seed --force`
5. Crear el primer administrador del Control Center (muestra una contraseña temporal una sola vez; al entrar debe cambiarla y activar la verificación en dos pasos):
   `php artisan platform:create-admin <correo> "<nombre>"`
6. Configurar a Atlantia como emisor FEL de las suscripciones:
   `php artisan fel:setup-platform <correo-del-administrador> --address="<dirección fiscal>"`
7. Programar en **una sola** instancia: `* * * * * php /ruta/artisan schedule:run`
8. Correr el worker de colas como servicio (systemd o Supervisor): `php artisan queue:work --tries=3 --max-time=3600`
9. Cachear la configuración: `php artisan config:cache && php artisan route:cache && php artisan view:cache`

## Verificación

1. `php artisan production:preflight` debe terminar en "passed". Revisa HTTPS, proxies, correo, servicios compartidos, respaldos, FEL, Recurrente en vivo, `bcmath` y el programador.
2. `curl -I https://tu-dominio/up` responde 200 con la cabecera `Strict-Transport-Security`.
3. Registrar una cuenta de prueba: el código de verificación llega por correo.
4. Pagar con una tarjeta de prueba en el entorno de pruebas de Recurrente antes de pasar a `live`: el webhook activa la suscripción y la factura aparece en el Control Center como FEL pendiente de emitir.
5. Subir un archivo y descargarlo desde otra instancia (comprueba EFS).
6. `php artisan backup:create --upload` y luego `php artisan backup:verify <archivo-generado>`.
