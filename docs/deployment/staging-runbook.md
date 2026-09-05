# Staging seguro

## Objetivo

Publicar la rama `main` en un servidor Linux con Nginx, PHP 8.3 y PostgreSQL. El despliegue usa HTTPS, mantiene los secretos fuera del repositorio y conserva una versión anterior para rollback de aplicación.

## Preparación única del servidor

1. Crear `/srv/atlantia-finance/releases` y `/srv/atlantia-finance/shared/storage` para el usuario de despliegue.
2. Copiar `.env.staging.example` a `/srv/atlantia-finance/shared/.env` y completar los valores directamente en el servidor. El archivo debe tener permisos `600` y nunca debe copiarse al repositorio.
3. Configurar Nginx a partir de `docs/deployment/nginx-staging.conf.example`, sustituir el dominio y emitir el certificado TLS con el proveedor elegido o Let's Encrypt.
4. Apuntar el document root de Nginx exclusivamente a `/srv/atlantia-finance/current/public`.
5. Crear un usuario SSH sin privilegios de root y una llave exclusiva de despliegue.
6. Crear `/var/backups/atlantia-finance` fuera del document root, propiedad del usuario de despliegue y con permisos `750`.
7. Instalar `postgresql-client`, `tar` y AWS CLI. Crear un bucket S3 privado en la cuenta real, con bloqueo de acceso público, versionado, cifrado y una política IAM limitada exclusivamente a su prefijo de respaldos.

## Respaldo operativo

Configurar en `shared/.env` después de sustituir el bucket de ejemplo por el bucket privado real:

```env
BACKUP_ENABLED=true
BACKUP_DESTINATION=/var/backups/atlantia-finance
BACKUP_RETENTION_DAYS=30
BACKUP_S3_URI=s3://BUCKET-PRIVADO/atlantia/staging
