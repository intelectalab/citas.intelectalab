# 2026-10-05 — Módulos por plan y rubro, y vocabulario en el panel

## Contexto

En el prototipo, el rubro de la empresa cambia qué módulos ve y cómo nombra a sus clientes y profesionales. Este avance cubre cuatro pasos:

1. Completar el rubro en la base: módulos que apaga, reglas por defecto y dependientes.
2. Calcular los módulos activos de cada empresa en el backend y bloquear en la API los que no tiene.
3. Usar el vocabulario del rubro en el panel.
4. Mostrar el menú según los módulos activos.

Además se adoptaron los tres planes del prototipo, porque los módulos de una empresa salen de su plan.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. Cómo se decide qué módulos tiene una empresa

```
módulos activos = módulos base
                + módulos de su plan
                − módulos que apaga su rubro
                − módulos que apaga la propia empresa
```

- Sin suscripción activa (`active` o `trialing`) la empresa no tiene ningún módulo, tampoco los base.
- El admin SaaS no pertenece a un tenant y no está sujeto a esta regla.

## 2. Esquema: `apps/api/infrastructure/docker/postgres/init.sql`

| Cambio | Detalle |
|---|---|
| Tabla nueva `saas_modules` | Catálogo de módulos: `key`, `name`, `is_core`, `sort_order`. |
| `business_types.modules_off` | jsonb. Módulos que el rubro no usa aunque el plan los incluya. |
| `business_types.default_rules` | jsonb. Reglas de operación por defecto del rubro. |
| `business_types.dependents` | jsonb. `{enabled, kind, required}`. |
| `tenant_settings.modules_off` | jsonb. Módulos que la empresa apaga además de los del rubro. |
| `tenant_settings.rules` | jsonb. Reglas que la empresa sobrescribe. |

El esquema pasa de 87 a 88 tablas.

## 3. Datos: `apps/api/infrastructure/docker/postgres/seed.sql`

### Módulos (20, los del prototipo)

- Base: `agenda` (Agenda y citas) y `crm` (Clientes / CRM 360).
- Por plan: `booking`, `pos`, `invoicing`, `inventory`, `commissions`, `memberships`, `packages`, `loyalty`, `marketing`, `automations`, `resources`, `classes`, `multi_branch`, `reports_adv`, `api`, `records`, `quotes`, `walkin`.

### Planes

Los seis planes anteriores se reemplazan por los tres del prototipo. Conservan los ids `1111…`, `2222…` y `3333…`.

| Plan | Mensual | Anual | Prueba | Módulos por plan |
|---|---|---|---|---|
| Esencial | S/ 149 | S/ 1 490 | 14 días | booking, pos, invoicing, packages, resources, records, quotes, walkin |
| Profesional | S/ 299 | S/ 2 990 | 14 días | Esencial + inventory, commissions, memberships, loyalty, marketing, automations, classes, multi_branch, reports_adv |
| Empresarial | S/ 599 | S/ 5 990 | 30 días | Profesional + api |

| Límite | Esencial | Profesional | Empresarial |
|---|---|---|---|
| `users` | 5 | 20 | 100 |
| `branches` | 1 | 3 | 20 |
| `professionals` | 5 | 20 | 100 |
| `appointments_month` | 800 | 4 000 | 30 000 |
| `whatsapp_month` | 300 | 2 000 | 10 000 |

`saas_plans.modules` sigue siendo un objeto agrupado por categoría (`agenda`, `sales`, `loyalty`, `marketing`, `operations`, `platform`), que es la forma que ya usaban las pantallas de planes. Los módulos base no se listan en el plan.

Las suscripciones que apuntaban a planes eliminados se reasignaron: Fisioterapia Vital a Profesional, BioAnálisis a Esencial y San Judas (cancelada) a Empresarial.

### Rubros

| Rubro | Módulos que apaga | Dependientes |
|---|---|---|
| salon | quotes, walkin | — |
| barber | quotes | — |
| aesthetics | walkin, classes | — |
| dental | walkin, classes, memberships, loyalty | — |
| medical | walkin, classes, memberships, loyalty, inventory | — |
| physio | walkin, classes | — |
| psychology | walkin, classes, inventory, loyalty, memberships | — |
| fitness | walkin, quotes | — |
| vet | walkin, classes, memberships | mascota, obligatoria |
| generic | walkin, classes | — |

Cada rubro lleva también sus reglas por defecto del prototipo (por ejemplo, dental: `booking.deposit_percent` 50, `records.lock_hours` 24).

## 4. Backend

| Archivo | Cambio |
|---|---|
| `app/Models/SaasModule.php` | Nuevo. `coreKeys()` devuelve los módulos base. |
| `app/Models/SaaSPlan.php` | `moduleKeys()` aplana `modules` en una lista de claves. |
| `app/Models/Tenant.php` | `modules()`, `hasModule()`, `rules()`, `dependents()` y la relación `activeSubscription`. |
| `app/Models/BusinessType.php`, `TenantSetting.php` | Columnas nuevas. |
| `app/Http/Middleware/EnsureModuleEnabled.php` | Nuevo. Alias `module`. Responde 403 con `code: MODULE_DISABLED` si el tenant no tiene el módulo. |
| `routes/api.php` | Rutas agrupadas por módulo. Nueva `GET /api/modules` (catálogo). |
| `app/Http/Controllers/AuthController.php` | `/api/user` agrega `tenant.modules`, `tenant.rules` y `tenant.dependents`. |

### Rutas protegidas por módulo

| Módulo | Rutas |
|---|---|
| `agenda` | `specialties`, `services`, `service-categories`, `professionals` |
| `crm` | `customers`, `contacts`, `pipelines`, `pipeline-stages` |

Los módulos por plan aún no tienen endpoints. Cuando se creen, deben declararse con `->middleware('module:<clave>')`. Ocultar un módulo en el menú no basta: la API es la que lo impide.

**Cambio de comportamiento:** una empresa sin suscripción activa ahora recibe 403 en esas rutas. Antes la interfaz le pedía elegir un plan, pero la API seguía respondiendo.

## 5. Frontend

| Archivo | Cambio |
|---|---|
| `src/hooks/useAuth.tsx` | `hasModule(clave)` y tipos de `modules`, `rules` y `dependents`. |
| `src/hooks/useVocabulary.ts` | Nuevo. Devuelve `client`, `clients`, `pro`, `pros`, `dependent` y `dependents` del tenant. |
| `src/lib/modules.ts` | Nuevo. Grupos y etiquetas de los módulos para el menú y el formulario de planes. |
| `src/app/components/layout/Sidebar.tsx` | El menú usa el vocabulario y oculta los ítems cuyo módulo no está activo. |
| `src/app/(dashboard)/modules/[key]/page.tsx` | Nuevo. Página provisional de los módulos sin pantallas. Indica si el módulo está activo o no disponible. |
| `src/features/customers/components/CustomersTable.tsx`, `FormCustomer.tsx` | Títulos, botones y mensajes con el vocabulario. "Notas Médicas" pasa a "Observaciones". |
| `src/features/professionals/components/ProfessionalsTable.tsx`, `FormProfessional.tsx` | Igual, con el término del profesional. "Especialidades Médicas" pasa a "Especialidades". |
| `src/features/home.tsx` | El atajo "Nuevo Cliente" usa el vocabulario. |
| `src/features/plans/components/FormSaaSPlan.tsx` | El formulario de planes del admin SaaS usa los 18 módulos por plan y los cinco límites nuevos. Con las claves anteriores, editar un plan habría borrado sus módulos. |
| `src/features/plans/components/PlanDetailsModal.tsx` | Muestra profesionales y citas por mes en lugar de clientes y servicios. |
| `src/features/subscriptions/PlanSelection.tsx` | Los planes de respaldo son los tres nuevos. |

### Menú

| Grupo | Ítems (módulo) |
|---|---|
| Atención (antes "Gestión clínica") | Clientes* (crm), Citas (agenda), Cola sin cita (walkin), Clases (classes), Servicios (agenda), Fichas de atención (records) |
| Ventas (antes "CRM") | Oportunidades (crm), Presupuestos (quotes), Punto de venta (pos), Inventario (inventory) |
| Fidelización | Paquetes (packages), Membresías (memberships), Puntos y gift cards (loyalty), Marketing (marketing) |
| Administración de sede | Profesionales* (agenda), Sucursales, Empresa |

\* El nombre sale del vocabulario: Pacientes, Alumnos, Tutores; Médicos, Odontólogos, Equipo.

El grupo "CRM" dependía de la característica `crm` del plan, que ningún plan tenía, así que no se mostraba. Ahora "Oportunidades" depende del módulo base `crm` y se muestra.

## 6. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal del contenedor `erp_postgres`. La base `erp_saas` no se tocó y la temporal se eliminó.
- Pruebas de la API contra esa base. Para los módulos sin endpoints se registraron rutas de prueba con el middleware `module`:

| Empresa | Rubro y plan | Módulos | inventory | quotes | memberships | api | walkin |
|---|---|---|---|---|---|---|---|
| Centro Médico Integral | medical, Profesional | 14 | 403 | 200 | 403 | 403 | 403 |
| DentalPlus | dental, Empresarial | 16 | 200 | 200 | 403 | 200 | 403 |
| Mente Sana | psychology, Esencial | 9 | 403 | 200 | 403 | 403 | 403 |
| BioAnálisis | generic, Esencial | 9 | 403 | 200 | 403 | 403 | 403 |
| San Judas | suscripción cancelada | 0 | 403 | 403 | 403 | 403 | 403 |
| Admin SaaS | sin tenant | — | 200 | 200 | 200 | 200 | 200 |

  - `customers` y `services` responden 200 a todas menos a San Judas (403).
  - DentalPlus apaga `quotes` en su configuración: sale de su lista y la ruta responde 403 con `MODULE_DISABLED`.
  - DentalPlus sobrescribe `cancel.window_hours` a 48: `/api/user` devuelve 48 y conserva el resto de reglas del rubro.
  - Una cuenta de cliente en una ruta de módulo: 403.
  - `/api/user` devuelve el vocabulario correcto por empresa (Pacientes / Médicos, Pacientes / Odontólogos, Clientes / Profesionales).
- `php -l` sin errores en los archivos PHP modificados.
- `tsc --noEmit` sin errores en `src`.
- Las pantallas no se probaron en el navegador: la base `erp_saas` todavía tiene el esquema anterior.

## 7. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` a mano. Para ver la diferencia, entrar como `admin@luxuryspa.pe` (médico, plan Profesional) y como `admin@dentalplus.pe` (dental, plan Empresarial): cambian el menú y los nombres.
