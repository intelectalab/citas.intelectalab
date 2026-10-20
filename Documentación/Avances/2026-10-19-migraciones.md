# 2026-10-19 — La base se arma y se actualiza con migraciones

## Contexto

Hasta hoy la base se armaba cargando a mano `init.sql`, `seed.sql` y `seed_demo.sql`, y cada cambio de tablas obligaba a borrarla y volver a crearla. En local no importa, pero en el servidor borraría los datos de las empresas. El sistema se sube hoy al servidor para las pruebas de mañana, así que desde ahora la base se arma y se actualiza con **migraciones** de Laravel. Cada migración es un cambio pequeño y numerado. Laravel anota cuáles ya aplicó y en cada actualización ejecuta solo las que faltan, sin tocar los datos.

## Qué archivo hace qué

| Archivo | Qué trae | Dónde se usa | ¿Se sigue editando? |
|---|---|---|---|
| `infrastructure/docker/postgres/init.sql` | Todas las tablas, tal como están hoy | Lo carga la migración `esquema_inicial` | **No.** Queda congelado |
| `infrastructure/docker/postgres/seed.sql` | Datos esenciales (catálogos, permisos, planes, rubros, cuentas de la plataforma) | Lo carga la migración `datos_esenciales` | **No.** Queda congelado |
| `infrastructure/docker/postgres/seed_demo.sql` | Empresas de ejemplo | Solo en local, con `php artisan db:demo` | Sí, para que coincida con las tablas nuevas |
| `database/migrations/` | Los cambios desde hoy en adelante | En todos lados, con `php artisan migrate` | Se agregan archivos nuevos; los que ya corrieron en el servidor no se modifican |

## Cambios realizados

| Archivo | Cambio |
|---|---|
| `apps/api/database/migrations/0001_01_01_000000_esquema_inicial.php` | Nuevo. En una base vacía ejecuta `init.sql`. Si la base ya tiene tablas (como la de tu máquina), no hace nada y solo queda anotada |
| `apps/api/database/migrations/0001_01_01_000001_datos_esenciales.php` | Nuevo. Ejecuta `seed.sql`. Es seguro aunque los datos ya estén, porque el archivo tolera cargarse dos veces |
| `apps/api/database/migrations/2026_09_24_094946_create_cache_table.php` | Eliminado. `init.sql` ya crea las tablas `cache` y `cache_locks`; con las dos, la migración fallaba en una base existente |
| `apps/api/routes/console.php` | Nuevo comando `php artisan db:demo`: borra la base local, la vuelve a crear con las migraciones y carga `seed_demo.sql`. Pide confirmación y se niega si `APP_ENV=production` |
| `docker-compose.yml` | Postgres ya no carga `init.sql` al crear el volumen. Si lo hiciera, la primera migración encontraría las tablas creadas pero sin anotar |

## Primera instalación en el servidor

1. Crear una base PostgreSQL 17 **vacía**.
2. En el `.env` del servidor: `APP_ENV=production`, `APP_DEBUG=false`, los datos `DB_*` de esa base y `FRONTEND_URL` con la dirección de la web. Generar la clave con `php artisan key:generate`.
3. Ejecutar:
   ```
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   ```
   Así quedan todas las tablas y los datos esenciales, sin empresas de ejemplo.
4. Entrar con `admin@tusaas.com` y `soporte@tusaas.com` y **cambiarles la contraseña**: vienen del seed con la misma contraseña que en local.
5. Activar el programador de tareas (una línea de cron que ejecute `php artisan schedule:run` cada minuto), para que salgan los recordatorios.

**Si el servidor es solo de pruebas y quieren las empresas de ejemplo:** usar `APP_ENV=staging` en vez de `production` y ejecutar `php artisan db:demo`. El día que entren clientes reales, usar una base nueva con `APP_ENV=production`.

## Cada actualización en el servidor

```
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
```

Si no hay migraciones nuevas, `migrate` responde "Nothing to migrate" y no toca nada.

## En tu máquina

- **Una sola vez, ahora:** tu base `erp_saas` no tiene las últimas tablas (Paquetes y Mensajes). Ejecuta `php artisan db:demo` desde `apps/api` para dejarla al día con las empresas de ejemplo.
- **Después:** cuando lleguen cambios, `php artisan migrate`. Para empezar de cero con los datos de muestra, `php artisan db:demo`.

## Cómo se probó

En bases temporales, sin tocar `erp_saas`:

- **Base vacía:** `migrate` creó todo. Una segunda vez respondió "Nothing to migrate".
- **Igual que antes:** se comparó con una base cargada a la antigua (`init.sql` + `seed.sql`): las mismas 107 tablas, las mismas 1184 columnas y las mismas filas en cada tabla.
- **`db:demo`:** dejó las 11 empresas y 4645 citas. Repetido, volvió a dejar la base limpia. Con `APP_ENV=production` se negó y no tocó nada.
- **Base existente** (cargada a la antigua con las empresas): `migrate` anotó las dos migraciones sin cambiar nada y las 11 empresas quedaron intactas.
- **API:** las cuentas de la empresa y de la plataforma consultaron sus pantallas principales (inicio, citas, sedes, usuarios, paquetes, empresas, planes, monedas) sin errores.
