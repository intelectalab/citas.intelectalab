# 2026-10-05 — Datos del servicio, dependientes, configuración de la empresa y colegiatura por rubro

## Contexto

Este avance cubre cuatro cosas:

1. Columnas nuevas en `services` para los datos del prototipo que se perdían al copiar el catálogo del rubro.
2. Paso 8 del plan de rubros: dependientes (mascotas) en la ficha del cliente.
3. Paso 9: configuración de la empresa. La maneja solo su administrador y solo dentro de lo que le dan su plan y su rubro.
4. El número de colegiatura de los profesionales solo aparece en los rubros que lo usan.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. Datos del servicio

### Esquema: `services`

| Columna | Tipo | Para qué |
|---|---|---|
| `code` | varchar(30) | Código interno (`CAB-01`). |
| `requires_deposit` | boolean, `false` | Exige adelanto para confirmar la reserva. |
| `online_booking` | boolean, `true` | Se puede reservar desde el portal y la reserva pública. |
| `is_virtual` | boolean, `false` | Se atiende por videollamada. |
| `kind` | `individual` o `group` | `group` es una clase con cupos. |
| `capacity` | integer, `1` | Cupo por sesión. Solo puede ser mayor a 1 en clases. |
| `recall_label`, `recall_days` | varchar, integer | Control de seguimiento que se sugiere al completar la atención. Van los dos o ninguno. |
| `recall_service_id` | uuid | Servicio con el que se agenda el control. Vacío = el mismo servicio. |

`business_types.template` ahora trae esos datos por servicio y `Tenant::installBusinessTemplate()` los copia. Los controles que apuntan a otro servicio se resuelven por código (dental: "Control post extracción" → "Consulta y diagnóstico").

### API

`POST` y `PUT /api/services` aceptan los campos nuevos.

- La categoría y el servicio de control deben ser del mismo tenant. Un servicio no puede ser su propio control.
- Crear una clase grupal exige el módulo `classes`. Sin él responde 403 con `MODULE_DISABLED`.
- Si el servicio no es clase, el cupo se guarda en 1.
- Corrección: `Service::create` no guardaba el `id` generado, así que la respuesta del alta llegaba sin `id`.

### Pantalla

El formulario de servicios tiene el campo "Código interno" y una sección nueva, "Reserva y seguimiento": reserva en línea, adelanto, videollamada, modalidad y cupo (solo si la empresa tiene clases grupales) y control de seguimiento.

## 2. Dependientes (paso 8)

Solo aplica a los rubros con `dependents.enabled`. Hoy es la veterinaria, con mascotas.

### API

| Ruta | Detalle |
|---|---|
| `GET/POST /api/customers/{cliente}/dependents` | Lista y alta. |
| `PUT/DELETE /api/customers/{cliente}/dependents/{id}` | Edición y baja (borrado lógico). |
| `POST /api/customers` | Acepta `dependents` para registrar al cliente con sus mascotas en una sola llamada. |
| `GET /api/customers` | Incluye `dependents` de cada cliente. |

- Van bajo el módulo `crm`.
- Si el rubro del tenant no usa dependientes: 403. En `POST /api/customers` la lista se ignora.
- Un cliente de otro tenant responde 404.
- El tipo (`kind`) lo pone el rubro (`pet`), no el formulario.

Archivos: `app/Models/CustomerDependent.php`, `app/Http/Controllers/Api/CustomerDependentController.php`, relación `Customer::dependents()`.

### Pantalla

- La ficha del cliente tiene la sección "Mascotas" (el nombre sale del vocabulario). Al editar un cliente cada cambio se guarda al momento; al crear uno nuevo, las mascotas se envían junto con el cliente.
- La tabla de clientes muestra la columna "Mascotas".

### Cita con dependiente obligatorio

`appointments` tiene la columna nueva `customer_dependent_id`. La validación ("la cita es para una mascota") queda pendiente porque todavía no hay backend ni pantalla de citas.

## 3. Configuración de la empresa (paso 9)

### Regla

La empresa no tiene control total sobre sus módulos:

| Módulo | ¿Lo puede apagar y encender la empresa? |
|---|---|
| Está en su plan y su rubro no lo apaga | Sí |
| Base (`agenda`, `crm`) | No. Siempre activo. |
| No está en su plan | No. |
| Lo apaga su rubro | No. |

Solo el administrador de la empresa puede hacerlo: el dueño (`is_owner`) o quien tenga el rol `owner` o `admin`. El resto del personal recibe 403 y no ve el enlace "Configuración".

### API

| Ruta | Detalle |
|---|---|
| `GET /api/tenant-settings` | Datos de la empresa, lista de módulos con `enabled`, `can_toggle` y el motivo del bloqueo (`core`, `plan`, `business_type`, `no_subscription`), y vocabulario (términos del rubro y lo que la empresa cambió). |
| `PUT /api/tenant-settings` | Recibe `modules_off` y `vocabulary`. |

- `modules_off` solo acepta módulos que la empresa puede cambiar. Cualquier otro responde 422 y no se guarda nada.
- Un término de vocabulario vacío vuelve al del rubro. Claves desconocidas se descartan.
- Lo que la empresa apaga deja de responder en la API (middleware `module`) y sale del menú de todo su equipo.
- `/api/user` agrega `is_tenant_admin`.

Archivos: `app/Http/Controllers/Api/TenantSettingController.php`, `Tenant::toggleableModules()`, `User::isTenantAdmin()`.

### Pantalla: `/settings`

- **Empresa:** nombre, rubro, plan y código de empresa (solo lectura).
- **Módulos:** interruptores para los que puede cambiar; lista de los siempre activos; lista de los no disponibles con su motivo ("No incluido en tu plan", "No aplica a tu rubro").
- **Vocabulario:** seis términos. Los de dependientes solo aparecen si el rubro los usa.

Al guardar se refresca el usuario, así que el menú y los nombres cambian sin recargar.

### Lo que no entra

- Las reglas de operación (`tenant_settings.rules`) no se editan en esta pantalla: todavía nada las aplica.
- El plan y el rubro no se cambian desde la empresa. Eso corresponde al portal del administrador SaaS.

## 4. Colegiatura por rubro

Regla nueva del rubro: `professionals.license`. Está activa en `medical` y `vet`.

- Con la regla: el formulario de profesionales pide "Nro. de Colegiatura" y la tabla muestra la columna "Colegiatura" (antes "Licencia").
- Sin la regla: el campo y la columna no aparecen, y el formulario no envía ese dato.

Dental, psicología y fisioterapia también tienen colegio profesional en Perú. Para activarlos basta agregar la regla a su fila en `seed.sql`.

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| Registro fitness: clases con cupo 12, 10 y 15; servicios privados con cupo 1 | Correcto |
| Registro dental: endodoncia con adelanto, sin reserva en línea y control a 15 días con "Curación con resina" | Correcto |
| Registro médico: "Teleconsulta" marcada como virtual | Correcto |
| Clase grupal en dental (el rubro apaga clases) | 403 |
| Control sin nombre, apuntando a sí mismo o a un servicio de otro tenant | 422 |
| Cliente con dos mascotas en una llamada; alta, edición y baja de mascota | Correcto |
| Mascotas desde un rubro sin dependientes | 403 |
| Mascotas de un cliente de otra veterinaria | 404 |
| Salón con plan Esencial: puede cambiar 6 módulos; 2 base, 10 fuera de plan y 2 del rubro bloqueados | Correcto |
| Apagar `pos`: sale de sus módulos y la ruta con `module:pos` responde 403. Encenderlo: 200 | Correcto |
| Apagar un módulo fuera de plan, apagado por el rubro, base o inexistente | 422 |
| Empleado que no es administrador: ver o guardar la configuración | 403 |
| Admin SaaS en `/api/tenant-settings` | 403 |
| Empresa con suscripción cancelada: no puede cambiar ningún módulo | Correcto |
| `professionals.license`: médico y veterinaria sí; dental y salón no | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En el navegador, con una veterinaria de prueba sobre la base temporal:
  - `/settings` muestra empresa, módulos y vocabulario; apagar "Inventario" y guardar lo quita del menú.
  - La tabla de tutores muestra la columna "Mascotas" y la ficha lista las mascotas con editar, eliminar y agregar.
  - El formulario de servicios muestra "Reserva y seguimiento" con los datos del catálogo y guarda (PUT 200). No ofrece modalidad porque la veterinaria no tiene clases.
  - La tabla de veterinarios muestra "Colegiatura".
- No se revisó en el navegador un rubro sin colegiatura ni el alta de un cliente nuevo con mascotas; ambos casos están cubiertos por las pruebas de la API.

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` a mano. Sin resetear, guardar un servicio falla porque `services` no tiene las columnas nuevas.
