# 2026-10-06 — Copia del rubro por empresa y aplicación explícita de cambios

## Contexto

Hasta ahora cada empresa leía su rubro en vivo. Al editar un rubro cambiaban al instante el vocabulario, los módulos, las reglas y los dependientes de todas las empresas que lo usaban; solo el catálogo inicial quedaba fuera. Apagar un módulo en "Clínica dental" se lo quitaba a todas las clínicas sin aviso.

Decisión: cada empresa guarda su propia copia de lo que el rubro le dio, y los cambios del rubro solo le llegan cuando la plataforma los aplica de forma explícita.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. Cómo funciona

| Momento | Qué pasa |
|---|---|
| La empresa se registra | Recibe una copia del rubro: vocabulario, módulos apagados, reglas y dependientes. Además se copia el catálogo inicial, como antes. |
| El administrador SaaS edita el rubro | Las empresas existentes no cambian. Las que se registren después reciben el rubro nuevo. |
| El administrador SaaS aplica el rubro a empresas | Esas empresas reciben el rubro tal como está en ese momento. |
| El administrador SaaS cambia el rubro de una empresa | La empresa recibe una copia nueva, del rubro nuevo. |

Lo que la empresa personalizó en su configuración (su vocabulario propio, los módulos que apagó, las reglas que sobrescribió) vive en `tenant_settings` y nunca se toca al aplicar.

El catálogo inicial (servicios y ficha) no se vuelve a copiar al aplicar.

## 2. Esquema

| Columna | Detalle |
|---|---|
| `tenants.business_profile` | jsonb. `{vocabulary, modules_off, default_rules, dependents}`: la copia de la empresa. |
| `tenants.business_profile_synced_at` | Cuándo recibió esa copia. |

El seed llena la copia de las seis empresas de ejemplo.

Si una empresa no tiene copia (`business_profile` nulo), lee el rubro directamente, como antes. Solo ocurre con bases que no se han reseteado.

## 3. Backend

| Archivo | Cambio |
|---|---|
| `app/Models/Tenant.php` | `businessProfile(parte)` devuelve la copia de la empresa. `vocabulary()`, `modules()`, `toggleableModules()`, `rules()` y `dependents()` leen de ahí y ya no del rubro. `fillBusinessProfile()` toma la copia del rubro. `outdatedProfileParts()` dice en qué difiere la copia del rubro actual. Al crear un tenant con rubro, la copia se llena sola. |
| `app/Http/Controllers/Api/BusinessTypeController.php` | `tenants()` y `apply()`. El listado y el detalle agregan `outdated_tenants_count`. |
| `app/Http/Controllers/Api/TenantController.php` | El cambio de rubro renueva la copia. La ficha agrega `business_profile_outdated`. |
| `app/Http/Controllers/Api/TenantSettingController.php` | Lee el vocabulario y los módulos del rubro desde la copia. |

### Rutas (solo administrador SaaS)

| Ruta | Efecto |
|---|---|
| `GET /api/admin/business-types/{id}/tenants` | Empresas del rubro y vista previa: qué partes cambiarían y qué módulos ganaría o perdería cada una. |
| `POST /api/admin/business-types/{id}/apply` (`tenant_ids`, `parts` opcional) | Aplica el rubro actual a esas empresas. Solo acepta empresas de ese rubro. `parts` permite aplicar solo algunas partes (`vocabulary`, `modules_off`, `default_rules`, `dependents`). |

Cada aplicación queda en `audit_events` como `tenant.business_profile_applied`, con la copia anterior y la nueva.

## 4. Frontend

| Archivo | Cambio |
|---|---|
| `features/business-types/components/BusinessTypeTenants.tsx` | Nuevo. En la página del rubro, lista sus empresas: "Al día" o las partes que cambiaron, con los módulos que ganaría (verde) o perdería (rojo). Las desactualizadas vienen marcadas; el botón "Aplicar a N empresas" pide confirmación y avisa si alguna pierde módulos. |
| `BusinessTypeDetail.tsx` | Muestra ese panel bajo la cabecera. |
| `FormBusinessType.tsx` | El aviso ahora dice que guardar no cambia a las empresas existentes y dónde aplicarlo. |
| `BusinessTypesTable.tsx` | Seis tarjetas que filtran (ver abajo). La columna Empresas marca "N sin aplicar". |
| `TenantDetailsModal.tsx` | Aviso cuando el rubro de la empresa cambió y ella conserva la versión anterior. |
| `app/components/ui/table/DataTable.tsx` | Con más de tres tarjetas de métricas, usa seis columnas en pantallas grandes. Las tablas con dos o tres tarjetas no cambian. |
| `features/settings/components/TenantSettings.tsx` | Ancho completo. |

### Tarjetas de la tabla de rubros

| Tarjeta | Filtra |
|---|---|
| Activos / Inactivos | Por estado (ya existían). |
| Con empresas | Rubros que alguna empresa usa. |
| Sin empresas | Rubros que nadie usa: candidatos a revisar o desactivar. |
| Cambios sin aplicar | Rubros con empresas que aún no reciben su última versión. |
| Sin catálogo inicial | Rubros cuyas empresas nuevas empezarían sin servicios. |

Cada tarjeta se activa con un clic y se desactiva con otro.

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal; las seis empresas quedan con copia. La temporal se eliminó.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| Editar el rubro dental (vocabulario, módulos, reglas y dependientes): DentalPlus conserva sus 16 módulos, "Odontólogos", 24 h y sin dependientes | Correcto |
| El listado marca 1 empresa con cambios sin aplicar; la vista previa dice que ganaría `loyalty` y perdería `inventory` | Correcto |
| Una empresa nueva del mismo rubro recibe el rubro editado | Correcto |
| Aplicar solo el vocabulario: cambia el vocabulario y nada más | Correcto |
| Aplicar todo: la empresa queda igual al rubro; aplicar otra vez no cambia nada | Correcto |
| Aplicar a una empresa de otro rubro | 422 |
| El vocabulario propio de la empresa y el módulo que ella apagó se conservan tras aplicar | Correcto |
| Cambio de rubro: la empresa recibe la copia del rubro nuevo | Correcto |
| Dueño de empresa y soporte SaaS en aplicar y vista previa | 403 |
| Bitácora: un evento por cada aplicación | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En el navegador, solo lectura: la tabla de rubros con las seis tarjetas en una fila y el filtro "Con empresas" (deja 5 de 10); la página del rubro con el panel de empresas.
- El botón "Aplicar" se probó en el navegador contra una base temporal: con el rubro dental editado, el panel mostró "1 de 1 empresa tiene cambios sin aplicar" con las etiquetas "+ Puntos y gift cards" y "− Inventario"; la confirmación avisó que pierde módulos; al aplicar pasó a "La empresa de este rubro está al día" y la base quedó con el vocabulario y los módulos nuevos y su evento en la bitácora.

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` a mano. Sin resetear, las empresas siguen leyendo el rubro en vivo y "Aplicar" falla.

## 7. Página propia de cada empresa y cambio de nombre (2026-10-06)

### Tenants

La ficha de la empresa dejó de ser una ventana emergente. Ahora es una página, `/admin/tenants/{id}` (`features/tenants/components/TenantDetail.tsx`), a la que llevan el ojo y el nombre en la tabla. Se eliminó `TenantDetailsModal.tsx`; los estados quedaron en `features/tenants/tenantStatus.ts`.

Está organizada en tarjetas de distinto tamaño (bento):

| Tarjeta | Contenido |
|---|---|
| Empresa | Nombre, estado, razón social, RUC, dirección, rubro, código, zona horaria, fecha de registro y el botón "Entrar al panel". Lleva una franja con el color del rubro. |
| Plan | Fondo azul: plan, estado, precio según su ciclo, inicio, vencimiento y días que faltan. |
| Uso (cinco tarjetas) | Usuarios, sedes, profesionales, citas del mes y WhatsApp del mes. Cada una con un anillo que se llena: verde hasta 70 %, ámbar desde 70 %, rojo desde 90 % o al pasarse, y una frase ("Le quedan 17", "Solo le quedan 2", "Pasó el límite por 3"). |
| Módulos | Por grupo, con el motivo cuando no lo tiene. El interruptor de la plataforma solo aparece en los módulos que su plan y su rubro le dan. |
| Suscripción y cobros | Cambiar plan, registrar pago, mover vencimiento, cancelar y la lista de cobros. |
| Su configuración | Lo que la empresa ajustó por su cuenta: cómo nombra a su gente y qué términos cambió, los módulos que ella apagó, cuántas reglas cambió y si su registro de clientes está abierto. |
| Rubro | Rubro actual, enlace a su plantilla, aviso si la plantilla cambió, y el cambio de rubro. |
| Acceso | Suspender o reactivar. Se pinta de rojo cuando está suspendida. |
| Personal | Usuarios de la empresa y número de cuentas de clientes. |

`GET /api/tenants/{id}` agrega `settings` con lo que la empresa personalizó.

### Plantillas de rubros

"Rubros" pasó a llamarse "Plantillas de rubros" en el menú, la tabla, la página de detalle y el formulario. Las rutas (`/admin/business-types`) no cambian.

### Verificación en el navegador (base temporal)

| Prueba | Resultado |
|---|---|
| El nombre y el ojo de la tabla llevan a la página de la empresa | Correcto |
| Apagar "Comisiones" y guardar: pasa de 14 a 13 módulos activos y muestra "Apagado por la plataforma" | Correcto |
| Registrar pago: aviso y la lista pasa a "Cobros (1)" | Correcto |
| Suspender: el estado pasa a "Suspendido"; reactivar: vuelve a "Activo" | Correcto |
| En una empresa dental no hay interruptor en Inventario ni en Clases (los apaga su rubro) y sí en Paquetes | Correcto |
| El menú dice "Plantillas de rubros" | Correcto |

No se probó en pantalla: cambiar plan, cambiar rubro, mover vencimiento, cancelar, "Entrar al panel" ni la vista del soporte SaaS.
