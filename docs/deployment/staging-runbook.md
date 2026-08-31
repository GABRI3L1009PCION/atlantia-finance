# Staging seguro

## Objetivo

Publicar la rama `main` en un servidor Linux con Nginx, PHP 8.3 y PostgreSQL. El despliegue usa HTTPS, mantiene los secretos fuera del repositorio y conserva una versión anterior para rollback de aplicación.

## Preparación única del servidor

1. Crear `/srv/atlantia-finance/releases` y `/srv/atlantia-finance/shared/storage` para el usuario de despliegue.
2. Copiar `.env.staging.example` a `/srv/atlantia-finance/shared/.env` y completar los valores directamente en el servidor. El archivo debe tener permisos `600` y nunca debe copiarse al repositorio.
3. Configurar Nginx a partir de `docs/deployment/nginx-staging.conf.example`, sustituir el dominio y emitir el certificado TLS con el proveedor elegido o Let's Encrypt.
4. Apuntar el document root de Nginx exclusivamente a `/srv/atlantia-finance/current/public`.
5. Crear un usuario SSH sin privilegios de root y una llave exclusiva de despliegue.

## Secretos de GitHub

Crear el environment `staging` y restringirlo a `main`. Registrar estos secretos en el environment o, si el plan de GitHub no habilita environment secrets en repositorios privados, como Repository secrets:

- `STAGING_HOST`
- `STAGING_PORT` (opcional; el valor por defecto es `22`)
- `STAGING_USER`
- `STAGING_PATH` (por ejemplo, `/srv/atlantia-finance`)
- `STAGING_SSH_PRIVATE_KEY`
- `STAGING_KNOWN_HOSTS` obtenido por un canal confiable del administrador del servidor

Registrar `STAGING_URL` como variable del environment, obligatoriamente con esquema `https://`.

Los secretos de aplicación (`APP_KEY`, base de datos, correo y Recurrente) sólo viven en `shared/.env`. GitHub no los necesita para construir el release.

## Publicación

1. Confirmar que `tests` y `security` están verdes para `main`.
2. Abrir **Actions > staging > Run workflow**.
3. El workflow ejecuta `composer ci:check`, construye el frontend, valida `staging:preflight`, ejecuta `migrate --force`, optimiza Laravel y activa el release.
4. La prueba de humo exige certificado TLS válido, respuesta `200` de `/up` y encabezado HSTS.
5. Si la prueba de humo falla, el workflow restaura automáticamente el symlink al release anterior. No revierte la base de datos automáticamente porque una reversión destructiva requiere respaldo y aprobación humana.

## Rollback controlado

Para volver sólo el código, apuntar `current` al release anterior registrado en `.previous-release`, ejecutar `php artisan optimize` y `php artisan reload`.

Para revertir una migración, primero tomar un respaldo verificable de PostgreSQL y revisar el método `down()` involucrado. Después, durante una ventana de mantenimiento:

```bash
php artisan down --render="errors::503"
php artisan migrate:rollback --step=1 --force
php artisan optimize
php artisan reload
php artisan up
```

Confirmar de nuevo `/up`, autenticación, compras y aislamiento multiempresa. Si el `down()` elimina o transforma datos, restaurar el respaldo en vez de ejecutar rollback.

## Evidencia

Guardar el enlace del workflow, SHA desplegado, dominio, fecha, responsable, resultado de `/up`, resultado de migraciones y decisión de aceptación en `docs/quality/staging-acceptance.md`.
