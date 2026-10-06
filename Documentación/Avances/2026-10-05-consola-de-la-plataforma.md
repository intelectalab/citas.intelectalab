# 2026-10-05 — Consola de la plataforma (administrador SaaS y soporte SaaS)

## Contexto

La consola del administrador SaaS tenía tres pantallas (Tenants, Planes, Contactos) y sus rutas respondían a cualquier empleado de cualquier empresa. Este avance cubre seis puntos:

1. Cerrar el acceso a la consola y bloquear el ingreso de empresas suspendidas.
2. Ficha de la empresa.
3. Cambiar el plan y el rubro de una empresa.
4. Planes a medida y módulos que la plataforma apaga (en lugar de complementos sueltos).
5. Catálogo de rubros editable.
6. Suscripciones y cobros.

Además: entrar al panel de una empresa para dar soporte, y el rol nuevo Soporte SaaS.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones. Se mantuvieron las pantallas y componentes existentes (`TenantsTable`, `TenantDetailsModal`, `FormTenant`, `DataTable`, `FormDrawer`); lo nuevo sigue la misma organización (`features/<módulo>/components`, `app/(dashboard)/admin/<página>`).

## 1. Quién es quién

| Cuenta | Cómo se reconoce | Qué puede hacer |
|---|---|---|
| Administrador SaaS | Personal sin empresa, sin el rol `saas_support` | Todo en la consola. |
| Soporte SaaS | Personal sin empresa con el rol `saas_support` | Ver empresas y su ficha, y entrar a su panel. No cambia planes, rubros, catálogos, cobros ni suspende. |
| Administrador de empresa | Dueño o rol `owner`/`admin` de un tenant | Su panel y su configuración. No entra a la consola. |

`/api/user` entrega `is_saas_admin`, `is_saas_support` e `impersonator`.

Usuario de prueba nuevo en el seed: `soporte@tusaas.com`, con la misma contraseña que el resto.

## 2. Acceso (punto 1)

Middleware nuevo `saas` (`app/Http/Middleware/EnsurePlatformStaff.php`):

- `saas`: administrador SaaS o soporte SaaS.
- `saas:admin`: solo administrador SaaS.

| Rutas | Antes | Ahora |
|---|---|---|
| `GET /api/tenants`, `GET /api/tenants/{id}`, `POST /api/tenants/{id}/impersonate` | Cualquier personal | `saas` |
| Resto de `/api/tenants/*`, `/api/saas-plans`, `/api/tenant-contacts`, `/api/admin/business-types` | Cualquier personal | `saas:admin` |

### Empresas suspendidas y cuentas desactivadas

- El login rechaza con 403 las cuentas con `users.status` distinto de `active` y las de empresas con `tenants.status` distinto de `active`. Códigos: `ACCOUNT_DISABLED` y `TENANT_SUSPENDED`.
- El aviso solo se da después de validar la contraseña. Con contraseña incorrecta la respuesta sigue siendo "Credenciales incorrectas".
- Al suspender una empresa se cierran las sesiones abiertas de todo su personal y sus clientes.
- Estados de empresa: `active`, `suspended`, `inactive`. Solo `active` puede iniciar sesión.

## 3. Ficha de la empresa (punto 2)

`GET /api/tenants/{id}` devuelve:

- Datos: nombre, código, rubro, razón social, estado y motivo de suspensión.
- Suscripción: plan, estado, si está vigente, ciclo y fechas.
- Cobros registrados.
- Módulos: cada uno con `enabled` y el motivo si no lo tiene (`no_subscription`, `plan`, `business_type`, `platform`, `tenant`).
- Uso frente a los límites del plan: usuarios, sedes, profesionales, citas del mes y mensajes de WhatsApp del mes.
- Personal de la empresa y número de cuentas de clientes.

Acciones del administrador SaaS:

| Ruta | Efecto |
|---|---|
| `POST /api/tenants/{id}/suspend` (`reason` opcional) | Suspende y cierra sesiones. |
| `POST /api/tenants/{id}/reactivate` | Reactiva. |

Pantalla: el modal de detalles de Tenants tiene cinco pestañas (General, Suscripción, Módulos, Uso, Usuarios). La tabla de Tenants muestra rubro, plan y el estado "Suspendido".

## 4. Cambiar plan y rubro (punto 3)

La empresa, desde su configuración, solo puede apagar módulos que ya tiene. Lo que la empresa **recibe** lo decide la plataforma con dos palancas: su plan y su rubro. Este punto son esas dos palancas.

| Ruta | Efecto |
|---|---|
| `PUT /api/tenants/{id}/plan` (`plan_id`, `billing_cycle` opcional) | Cambia el plan. Acepta cualquier plan activo, también los privados. Si la suscripción está vigente conserva su estado y sus fechas; si no, empieza una activa hoy. |
| `PUT /api/tenants/{id}/business-type` (`business_type_id`, `install_template`) | Cambia el rubro: cambian vocabulario, módulos que el rubro apaga y reglas. Los servicios y fichas de la empresa no se tocan. El catálogo del rubro nuevo solo se copia si se pide y la empresa no tiene servicios. |

Ejemplos de uso: una clínica que pasa de Esencial a Profesional; una empresa que se registró como "Genérico" y en realidad es veterinaria.

## 5. Planes a medida y módulos apagados por la plataforma (punto 4)

No se modelaron complementos sueltos. La decisión fue:

- **Plan a medida.** Un plan con `is_public_catalog = false` no aparece en el registro ni en la selección de plan, y solo lo puede asignar el administrador SaaS. Es la forma de darle a una empresa una combinación propia de módulos y límites.
- **Módulos apagados por la plataforma.** `tenants.modules_off` (columna nueva). La plataforma solo quita; para dar un módulo que el plan no tiene se cambia el plan.

```
módulos activos = base
                + módulos de su plan
                − los que apaga su rubro
                − los que le apaga la plataforma   (nuevo)
                − los que apaga la propia empresa
```

| Cambio | Detalle |
|---|---|
| `GET /api/plans` | Solo planes activos y públicos. |
| `POST /api/register-tenant` | Rechaza planes privados (422). |
| `POST /api/subscriptions/subscribe` | Solo el administrador de la empresa, y solo planes públicos. |
| `PUT /api/tenants/{id}/modules` (`modules_off`) | La plataforma apaga módulos. No acepta módulos base. |
| `/api/tenant-settings` | Un módulo apagado por la plataforma sale bloqueado con el motivo `platform`; la empresa no puede volver a encenderlo. |

## 6. Rubros (punto 5)

CRUD en `/api/admin/business-types` (`BusinessTypeController`): datos, vocabulario, módulos que apaga, dependientes, reglas por defecto y catálogo inicial.

- El catálogo público `GET /api/business-types` no cambia: solo rubros activos y sin la plantilla.
- Un rubro inactivo no aparece en el registro; las empresas que ya lo usan siguen igual.
- No se puede eliminar un rubro con empresas (422).
- La plantilla se valida: cada servicio necesita nombre, duración y precio; los tipos de campo de la ficha deben ser los del esquema.

Pantalla nueva: `/admin/business-types` ("Rubros" en el menú). Tabla y formulario lateral con seis secciones. El catálogo inicial se edita con un editor visual (`TemplateEditor.tsx`, agregado el 2026-10-06):

- **Categorías** como tarjetas desplegables, con el nombre editable en la cabecera.
- **Servicios** como filas con nombre, código, duración y precio. Al desplegar una fila se editan sus datos, sus opciones (reserva en línea, adelanto, videollamada, con ficha, clase grupal y cupo) y su control de seguimiento.
- **Ficha de atención:** nombre, campos (nombre, tipo, obligatorio, opciones una por línea, orden con flechas) y consentimiento.
- Antes de guardar avisa lo que falta (categoría o servicio sin nombre, lista sin opciones, control incompleto).
- "Clase grupal" no se ofrece si el rubro apaga el módulo de clases.

Las reglas por defecto se editan con `RulesEditor.tsx` (2026-10-06): 22 reglas con nombre y explicación, agrupadas en Reservas, Cancelaciones e inasistencias, Atención, Fichas y seguimiento, y Ventas e inventario. Las de sí/no usan un interruptor y las numéricas un campo con su unidad (vacío = la regla no aplica). El catálogo de reglas está en `src/lib/rules.ts`. Las claves que el formulario no muestra se conservan al guardar.

## 7. Suscripciones y cobros (punto 6)

### Vigencia

Una suscripción está vigente si su estado es `active` o `trialing` y su `ends_at` no pasó (sin `ends_at` no vence). `Tenant::activeSubscription` ya lo revisa: una empresa vencida se queda sin módulos aunque nadie haya actualizado su estado.

El comando `php artisan subscriptions:expire` deja el estado al día: prueba vencida → `expired`; activa vencida → `past_due`. Está programado cada hora; necesita el planificador de Laravel (`php artisan schedule:work` o un cron).

### Acciones del administrador SaaS

| Ruta | Efecto |
|---|---|
| `POST /api/tenants/{id}/subscription/payments` (`billing_cycle`, `amount`, `reference`, `paid_at`, `notes`) | Registra un cobro pagado y deja la suscripción activa. Suma un mes o un año: desde el vencimiento actual si sigue vigente, o desde el pago si estaba en prueba o vencida. Sin `amount` usa el precio del plan. |
| `PUT /api/tenants/{id}/subscription/extend` (`ends_at`) | Mueve el vencimiento sin registrar cobro. Debe ser una fecha futura. |
| `POST /api/tenants/{id}/subscription/cancel` | Cancela. La empresa queda sin módulos, pero puede iniciar sesión. |

`subscription_invoices` tiene columnas nuevas: `period_start`, `period_end` y `notes`.

## 8. Entrar al panel de una empresa

`POST /api/tenants/{id}/impersonate` (administrador SaaS o soporte SaaS) entrega una sesión temporal como el dueño de la empresa.

- Caduca sola a las 2 horas.
- `/api/user` devuelve `impersonator` con el nombre de quien entró.
- `POST /api/impersonation/stop` la cierra. No funciona con una sesión normal (422).
- Requiere que la empresa tenga una cuenta de dueño activa.

En pantalla: botón en la tabla de Tenants y en la ficha. Mientras dura la sesión, una franja amarilla avisa en qué empresa se está y ofrece "Salir y volver a la consola". El token de la plataforma se guarda aparte y se restaura al salir o cuando la sesión caduca.

## 9. Bitácora

Estas acciones quedan en `audit_events` con quién, cuándo, IP y los valores antes y después (`app/Models/AuditEvent.php`):

`tenant.suspended`, `tenant.reactivated`, `tenant.plan_changed`, `tenant.business_type_changed`, `tenant.modules_changed`, `subscription.payment_registered`, `subscription.extended`, `subscription.canceled`, `impersonation.started`, `impersonation.ended`.

Todavía no hay pantalla para consultarla.

## 10. Archivos

| Backend | |
|---|---|
| Nuevos | `Middleware/EnsurePlatformStaff.php`, `Api/TenantSubscriptionController.php`, `Api/ImpersonationController.php`, `Api/BusinessTypeController.php`, `Models/AuditEvent.php`, `Models/SubscriptionInvoice.php` |
| Modificados | `Api/TenantController.php`, `Api/TenantSettingController.php`, `Auth/LoginController.php`, `Auth/SubscriptionController.php`, `Auth/RegisterTenantController.php`, `AuthController.php`, `Models/Tenant.php`, `Models/User.php`, `Models/Subscription.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php` |

| Frontend | |
|---|---|
| Nuevos | `app/(dashboard)/admin/business-types/page.tsx`, `features/business-types/components/BusinessTypesTable.tsx`, `FormBusinessType.tsx` |
| Modificados | `features/tenants/components/TenantsTable.tsx`, `TenantDetailsModal.tsx`, `FormTenant.tsx`, `hooks/useAuth.tsx`, `app/(dashboard)/layout.tsx`, `app/components/layout/Sidebar.tsx`, `features/settings/components/TenantSettings.tsx`, `features/home.tsx` |

## 11. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| Dueño de empresa en las 8 rutas de la consola | 403 en todas |
| Soporte: lista y ficha 200; plan, suspender, planes, rubros, contactos y cancelar 403 | Correcto |
| Suspender: el token anterior del dueño deja de servir (401) y el login responde 403 `TENANT_SUSPENDED` | Correcto |
| Login con contraseña incorrecta en empresa suspendida | 401, sin revelar la suspensión |
| Reactivar y volver a entrar | 200 |
| Cuenta desactivada | 403 `ACCOUNT_DISABLED` |
| Plan privado: no sale en `/api/plans`; registro y autoasignación 422; el admin lo asigna y la empresa queda con sus módulos y límites | Correcto |
| La plataforma apaga `inventory`: la empresa lo ve bloqueado, guardar "todo encendido" no lo enciende e intentar tocarlo da 422 | Correcto |
| Apagar un módulo base desde la plataforma | 422 |
| Cambio de rubro salón → veterinaria: vocabulario "Tutores", dependientes activos, conserva sus 10 servicios | Correcto |
| Soporte entra a una empresa: ve sus servicios, no la consola (403); al salir la sesión deja de servir (401) | Correcto |
| El dueño de una empresa intenta entrar a otra | 403 |
| Pago en prueba → activa por un mes; segundo pago suma desde el vencimiento; mover vencimiento; fecha pasada 422 | Correcto |
| Suscripción vencida: 0 módulos antes del comando; el comando la pasa a `past_due`; un pago la reactiva | Correcto |
| Cancelar: 0 módulos. Asignar plan a una cancelada: vuelve a activa | Correcto |
| Rubros: crear, validar plantilla y módulos, desactivar, no eliminar con empresas; el público no expone la plantilla | Correcto |
| Bitácora: un evento por cada acción | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En el navegador, como administrador SaaS y solo lectura: la tabla de Tenants con rubro, plan y estado; la ficha con sus cinco pestañas; la página de Rubros y su formulario.
- No se probó en el navegador: entrar al panel de una empresa (cambio de sesión y franja de aviso), ni guardar desde la ficha o el formulario de rubros. La base `erp_saas` no tiene todavía las columnas nuevas y no se quiso escribir en ella. Esas acciones están cubiertas por las pruebas de la API.

## 12. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` a mano. Sin resetear, la ficha carga pero apagar módulos y registrar pagos fallan por las columnas que faltan.

### Ajustes de diseño del 2026-10-06

- **Módulos del rubro, en positivo.** La sección del formulario pasó de "Módulos que el rubro apaga" (casillas) a "Módulos del rubro": tarjetas con interruptor, encendido = el rubro lo usa. El dato guardado sigue siendo `modules_off`.
- **Columna "Módulos" de la tabla de rubros.** Muestra cuántos están encendidos ("14 de 18"). Al pasar el cursor o enfocar con el teclado abre un globo con la lista por grupos (`ModulesBubble.tsx`).
- **Ficha de empresa.** En la pestaña Módulos, la casilla "Apagar" pasó a ser un interruptor de encendido.
- Componente nuevo `app/components/common/Switch.tsx`.
- **Página de detalle del rubro** (`/admin/business-types/{id}`, `BusinessTypeDetail.tsx`). Solo lectura: cabecera con cifras, vocabulario y dependientes, módulos, reglas definidas, catálogo inicial y, a la derecha, la ficha de atención con forma de hoja (`RecordPreview.tsx`). Se llega desde el ojo de la tabla o desde el nombre del rubro. Tiene botón "Editar rubro".
- **Formulario de rubro.** El título muestra el nombre y el slug del rubro que se edita. La sección de reglas abre con un resumen de las reglas activas.
- **Menú lateral.** Un ítem queda resaltado también en sus páginas internas.
