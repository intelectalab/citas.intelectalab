# 2026-10-05 — Login multi-tenant, registro de clientes con código de empresa y rubros

## Contexto

Las cuentas usan solo correo y contraseña. Una misma persona puede ser cliente (o personal) de varias empresas con el mismo correo, así que el correo ya no identifica por sí solo a qué tenant entra. Este avance cubre:

- Código de empresa de 8 dígitos por tenant, para que los clientes se registren.
- Registro de cuentas de cliente con ese código.
- Login que resuelve el tenant y, si hace falta, pide elegir empresa.
- Rubros de negocio y vocabulario por tenant (cliente, paciente, alumno, tutor).
- Portal de cliente mínimo y bloqueo del backoffice para cuentas de cliente.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. Esquema: `apps/api/infrastructure/docker/postgres/init.sql`

| Cambio | Detalle |
|---|---|
| Tabla nueva `business_types` | Catálogo global de rubros: `slug`, `name`, `short_name`, `description`, `icon`, `color`, `vocabulary` (jsonb), `sort_order`, `status`. |
| `tenants.code` | `char(8) NOT NULL UNIQUE`, solo dígitos (`CHECK`). Es el código que la empresa entrega a sus clientes. |
| `tenants.business_type_id` | Rubro del tenant. Nulo permitido. |
| Tabla nueva `tenant_settings` | Una fila por tenant: `vocabulary` (jsonb que sobrescribe el del rubro), `brand_color`, `logo_url`, `allow_customer_signup`. La configuración del SaaS sigue en `saas_plans` y `subscriptions`. |
| `users.account_type` | `staff` (personal del tenant o admin SaaS) o `customer` (cuenta del portal). Por defecto `staff`. |
| `customers.user_id` | Cuenta del portal vinculada a la ficha del cliente. Índice único parcial. |
| Tablas nuevas `cache` y `cache_locks` | Las necesita Laravel con `CACHE_STORE=database`. Sin ellas el límite de intentos del login responde 500. |

El esquema pasa de 83 a 87 tablas.

Las cuentas de cliente viven en `users`, igual que el personal. El índice `UNIQUE (tenant_id, email)` ya existía: un correo puede repetirse entre tenants, pero no dentro del mismo.

## 2. Datos: `apps/api/infrastructure/docker/postgres/seed.sql`

- Los 10 rubros del prototipo con su vocabulario: `salon`, `barber`, `aesthetics`, `dental`, `medical`, `physio`, `psychology`, `fitness`, `vet` y `generic`.
- Cada tenant recibe código y rubro:

| Tenant | Código | Rubro |
|---|---|---|
| Centro Médico Integral | 48271639 | medical |
| Clínica Odontológica DentalPlus | 73920518 | dental |
| Consultorio Psicológico Mente Sana | 15846027 | psychology |
| Centro Fisioterapia Vital | 60394872 | physio |
| Laboratorio Clínico BioAnálisis | 92716345 | generic |
| Policlínico San Judas (suspendido) | 37058194 | medical |

- Una fila de `tenant_settings` por tenant.
- Cuenta de cliente de prueba `juliana.diaz@gmail.com` en Centro Médico Integral y en DentalPlus, con la misma contraseña que el resto del seed. Sirve para probar el selector de empresa.

## 3. Backend

### Login (`POST /api/login`)

`app/Http/Controllers/Auth/LoginController.php`

1. Busca todas las cuentas con ese correo y prueba la contraseña contra cada una.
2. Ninguna coincide: 401 "Credenciales incorrectas".
3. Una coincide: devuelve el token de esa cuenta. El usuario entra directo.
4. Varias coinciden: devuelve `requires_tenant_selection: true` y la lista `accounts` (empresa, rubro, tipo de cuenta). No emite token.
5. El frontend repite la llamada con `account_id`. El servidor vuelve a validar correo y contraseña contra esa cuenta.

Solo se listan empresas donde la contraseña ya fue validada. Así nadie puede averiguar en qué empresas está registrado un correo ajeno. Si las contraseñas son distintas en cada empresa, la contraseña escrita decide a cuál entra y no aparece el selector.

Se usa `account_id` y no `tenant_id` para la selección porque el admin SaaS no tiene tenant. Dentro de un tenant hay una sola cuenta por correo, así que equivale a elegir la empresa.

El token pertenece a una fila de `users` con su `tenant_id`. Todo lo que se haga en la sesión queda dentro de ese tenant.

### Registro de cliente (`POST /api/register-customer`)

`app/Http/Controllers/Auth/RegisterCustomerController.php` (nuevo)

Recibe `tenant_code`, `first_name`, `last_name`, `email`, `password` y `phone` opcional. Nombres y apellidos son obligatorios porque la ficha `customers` los exige.

1. Busca el tenant activo por el código. Si no existe, está suspendido o tiene el registro desactivado: 422 "El código de empresa no es válido."
2. Busca el correo solo dentro de ese tenant, en `users` y en `customers`. Si existe: 409 "Ya tienes una cuenta registrada en esta empresa. Ingresa con tus credenciales."
3. Si no existe: crea la cuenta (`account_type = customer`) y la ficha `customers` vinculada, con su código correlativo. Devuelve el token.

Que el correo exista en otra empresa no impide el registro.

### Otros cambios

| Archivo | Cambio |
|---|---|
| `app/Http/Middleware/EnsureStaffAccount.php` | Nuevo. Responde 403 a las cuentas de cliente. Alias `staff` en `bootstrap/app.php`. |
| `routes/api.php` | Rutas del backoffice bajo `auth:sanctum` + `staff`. `/user` y `/logout` quedan para ambos tipos. Nuevas: `/register-customer` y `GET /business-types`. Límite de 10 intentos por minuto en `/login` y `/register-customer`. |
| `app/Http/Controllers/AuthController.php` | `/api/user` agrega `account_type`, `tenant` (nombre, rubro, color, vocabulario y, solo para personal, el código) y `customer` (id y código de la ficha). |
| `app/Http/Controllers/Auth/RegisterTenantController.php` | Ya no exige correo único global. Acepta `business_type_id` opcional (por defecto `generic`). Crea la fila de `tenant_settings`. |
| `app/Http/Controllers/Api/UserController.php` | `/api/users` lista solo personal. |
| `app/Models/Tenant.php` | Genera el código de 8 dígitos al crear. Relaciones `businessType` y `settings`. `vocabulary()` combina base, rubro y lo sobrescrito por el tenant. |
| `app/Models/BusinessType.php`, `TenantSetting.php` | Nuevos. |
| `app/Models/User.php`, `Customer.php` | `account_type`, relación `customer` y `user_id`. |

## 4. Frontend

| Archivo | Cambio |
|---|---|
| `src/app/(auth)/login/page.tsx` | El login abre el selector cuando el servidor lo pide y redirige a `/portal` o `/home` según el tipo de cuenta. El formulario de registro que estaba sin uso (enviaba campos que `/register-tenant` no acepta) pasa a ser el registro de cliente con código de empresa. |
| `src/features/auth/components/TenantSelectModal.tsx` | Nuevo. Popup "¿A qué empresa deseas ingresar?". Al elegir muestra la carga y reutiliza el correo y la contraseña ya escritos, que solo viven en memoria. |
| `src/app/(portal)/layout.tsx`, `src/app/(portal)/portal/page.tsx` | Nuevos. Portal mínimo: nombre de la empresa, saludo con el vocabulario del rubro y cierre de sesión. |
| `src/app/(dashboard)/layout.tsx` | Una cuenta de cliente que entra al panel es enviada a `/portal`. |
| `src/hooks/useAuth.tsx` | Tipos de `account_type`, `tenant` y `customer`. |
| `src/features/home.tsx` | Muestra al personal el código de empresa. |

El registro de empresa (`/register`, con planes) no cambió.

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal del contenedor `erp_postgres`. La base `erp_saas` no se tocó y la temporal se eliminó.
- Pruebas de la API contra esa base:
  - Correo en un solo tenant: entra directo. Contraseña incorrecta: 401.
  - Mismo correo y contraseña en dos tenants: devuelve las dos empresas sin token. Con `account_id` entra a la elegida.
  - Selección con contraseña incorrecta o con la cuenta de otra persona: 401.
  - Mismo correo con contraseñas distintas: entra directo a la empresa cuya contraseña coincide.
  - Cuenta de cliente en `/api/customers`: 403. `/api/user` devuelve su empresa, el vocabulario ("Paciente") y su código de cliente.
  - Registro con código inexistente o de tenant suspendido: 422.
  - Registro válido: 201, ficha `CLI-0004` creada. Repetido en el mismo tenant: 409. Mismo correo en otro tenant: 201.
  - Correo de un cliente que ya existe en la ficha pero sin cuenta: 409.
  - Registro de empresa con un correo que ya es cliente en otra: 201, con código generado, rubro `generic` y configuración creada.
  - Al superar 10 intentos por minuto: 429.
- `php -l` sin errores en los archivos PHP modificados.
- `tsc --noEmit` sin errores en `src`.
- Las pantallas no se probaron en el navegador: la base `erp_saas` todavía tiene el esquema anterior.

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` a mano. Para probar el selector: iniciar sesión con `juliana.diaz@gmail.com` y la contraseña del seed.
