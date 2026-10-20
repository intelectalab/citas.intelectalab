# 2026-10-20 — El sistema publicado en citas.intelectalab.com

## Contexto

El sistema se publicó para las pruebas de los jefes en `https://citas.intelectalab.com`. Corre en un servidor compartido (al que se entra con `ssh licoria`) donde ya funcionan Licoria y la plataforma de cursos, y no se tocó nada de esos dos. Queda en **modo de pruebas** (`APP_ENV=staging`) con las 11 empresas de ejemplo.

## Cómo quedó armado

| Pieza | Dónde |
|---|---|
| Código | `/var/www/citas` (descargado de GitHub con una llave de solo lectura, `servidor citas`) |
| Usuario que lo corre | `citas` (sin contraseña; se entra como root y se actúa como `citas`) |
| API (Laravel) | PHP 8.4 con su propio grupo de procesos, `/run/php/php8.4-fpm-citas.sock` |
| Web (Next.js) | puerto 3100, solo interno; proceso `citas-web` en supervisor; registro en `/home/citas/citas-web.log` |
| Base de datos | PostgreSQL 16, base y usuario `citas`; contraseña en `/home/citas/.citas-db-password` |
| Configuración de la API | `/var/www/citas/apps/api/.env` |
| Nginx | `/etc/nginx/sites-available/citas.conf`: la web en `/` y la API en `/api` |
| Certificado | Let's Encrypt, se renueva solo (vence el 2027-01-18 y se renueva antes) |
| Tarea programada | crontab del usuario `citas`, cada minuto `php8.4 artisan schedule:run` |
| Copias de la base | `/var/backups/citas/` (las hace el script de actualización; guarda las últimas 14) |

En el servidor, `php` es la versión 8.3 (la usa Licoria). Para este sistema siempre se usa `php8.4`.

## Cambios en el código

| Archivo | Cambio |
|---|---|
| `deploy/actualizar.sh` | Nuevo. Actualiza el servidor en un solo paso: copia de la base, descarga del código, dependencias, migraciones, compilación de la web y reinicio |
| `apps/web/src/app/(auth)/forgot-password/page.tsx`, `(auth)/reset-password/page.tsx`, `(dashboard)/subscription/page.tsx` | Eliminados. Eran archivos vacíos: en desarrollo no se notaba, pero la compilación para el servidor fallaba |

## Cómo actualizar el servidor

1. Subes tus cambios a GitHub como siempre.
2. Entras al servidor y ejecutas el script:
   ```
   ssh licoria
   bash /var/www/citas/deploy/actualizar.sh
   ```
   O me pides "actualiza el servidor" y lo hago yo.

El script se detiene si algo falla. La copia de la base queda hecha antes de cualquier cambio.

**La primera vez** el script todavía no está en el servidor: llega con tu próxima subida. Esa vez se descarga a mano:
```
ssh licoria
cd /var/www/citas && sudo -u citas -H git pull
bash /var/www/citas/deploy/actualizar.sh
```

## Cómo se comprobó

- `citas.intelectalab.com`, `/login`, `/up` y la API responden 200 con certificado válido.
- Las cuentas de una empresa y de la plataforma consultan sus pantallas principales sin errores.
- La base tiene las 11 empresas de ejemplo; la tarea programada corre cada minuto.
- Después de cada cambio, cursos.intelectalab.com y app.licoria.pe siguieron respondiendo 200 (Licoria se comprobó por IP, porque su DNS está desactivado).
