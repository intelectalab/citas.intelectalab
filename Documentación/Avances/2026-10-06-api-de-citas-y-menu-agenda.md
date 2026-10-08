# 2026-10-06 — Citas, entrega 2: API de citas, fases y menú Agenda

## Contexto

Segunda entrega del módulo de citas. La primera dejó la disponibilidad (horario semanal y ausencias). Esta deja la API completa de citas, tomando como referencia el prototipo, y el grupo "Agenda" en el menú lateral. Las pantallas de Agenda no se construyeron todavía: sus enlaces llevan a la página provisional.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. Qué se tomó del prototipo

| Tema | Cómo queda |
|---|---|
| Fases | Siete, fijas: pendiente, confirmada, llegó, en atención, atendida, cancelada, no asistió. |
| Pasos permitidos | Pendiente → confirmada, llegó, cancelada, no asistió. Confirmada → llegó, en atención, cancelada, no asistió, o de vuelta a pendiente. Llegó → en atención, atendida, o de vuelta a confirmada. En atención → atendida. Atendida, cancelada y no asistió son finales. |
| Por qué un horario no sirve | Servicio o profesional inactivo, el profesional no realiza el servicio, horario pasado, fuera de su horario, bloqueo o ausencia, otra cita en ese intervalo, o choque con la pausa de otra cita. |
| Sobre-agenda | Solo se puede forzar "fuera de horario" y "choque con pausa", con un motivo. Nunca una doble reserva, un bloqueo ni un horario pasado. |
| Reprogramar | Solo citas pendientes o confirmadas. Si el nuevo horario no sirve, la cita original se mantiene. |
| Menú Agenda | Agenda, Citas, Cola sin cita, Lista de espera, Controles y seguimiento, Clases, Bloqueos y ausencias. |

Lo que el prototipo tiene y esta entrega no incluye está en `Documentación/Pendientes/pendientes.md`.

## 2. Esquema

| Tabla | Cambio |
|---|---|
| `appointments` | `status_id` se reemplaza por `status` (una de las siete claves, por defecto `pending`). Columnas nuevas: `code` (correlativo por empresa, `C-0001`), `price`, `reschedule_count`, `override_reason`, `cancel_reason`, `cancelled_by`, `confirmed_at`, `checked_in_at`, `started_at`, `completed_at`, `cancelled_at`, `created_by`. Índice por cliente. |
| `appointment_status_history` | `from_status_id` y `to_status_id` pasan a `from_status` y `to_status` (claves). Se agrega `reason`. Se borra junto con la cita. |
| `tenant_settings` | `appointment_statuses` (jsonb): el nombre propio de cada fase, solo de las que la empresa cambió. |
| `seed.sql` | Se retiran el módulo de estados `appointments` y sus dos estados ("Programada", "Completada"). |

`statuses` y `status_modules` siguen igual para clientes, servicios, profesionales, ventas y pagos.

## 3. Backend

| Archivo | Cambio |
|---|---|
| `app/Support/AppointmentFlow.php` | Nuevo. Las siete fases, sus nombres por defecto, los pasos permitidos y el valor por defecto de las reglas de agenda. |
| `app/Support/AppointmentScheduler.php` | Nuevo. Decide si un profesional puede atender un servicio en un horario y por qué no. Lo usan el alta, la reprogramación y la lista de horarios libres. |
| `app/Models/Appointment.php`, `AppointmentStatusHistory.php` | Nuevos. |
| `app/Http/Controllers/Api/AppointmentController.php` | Nuevo. |
| `app/Models/Tenant.php` | El uso de citas del mes ya no cuenta las canceladas. |
| `app/Models/TenantSetting.php`, `TenantSettingController.php` | Guardan y entregan el nombre propio de las fases. |
| `app/Http/Controllers/AuthController.php` | `/api/user` entrega `tenant.appointment_statuses`: las siete fases con el nombre que usa la empresa. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `agenda`. |

### Rutas (personal de la empresa, módulo `agenda`)

| Ruta | Efecto |
|---|---|
| `GET /api/appointments` | Lista. Filtros: `date`, o `from` y `to`; `branch_id`, `professional_id`, `customer_id`, `status` (varias separadas por coma) y `search` (código, nombre, documento o teléfono del cliente). Sin filtros entrega las de hoy. Máximo 500. |
| `GET /api/appointments/{id}` | La cita con su historial. |
| `GET /api/appointments/availability` (`service_id`, `branch_id`, `date`, `professional_id` opcional) | Horarios de inicio libres de ese día, por profesional. |
| `POST /api/appointments` | Crea la cita. |
| `PUT /api/appointments/{id}` | Cambia solo las notas. |
| `POST /api/appointments/{id}/status` (`status`, `reason`) | Cambia la fase. |
| `POST /api/appointments/{id}/reschedule` (`starts_at`, y opcionales `professional_id`, `branch_id`, `reason`, `override_reason`) | Mueve la cita. |

Las horas se reciben y se entregan en la hora de la sede (`2026-10-19T09:00`) y se guardan con zona horaria.

### Reglas que aplica

| Regla | Qué hace | Valor si nadie la define |
|---|---|---|
| Mascota o dependiente | Si el rubro lo exige, la cita no se crea sin él. Debe ser del mismo cliente y estar activo. | — |
| Límite del plan (`appointments_month`) | No se crea ni se mueve una cita a un mes que ya llegó al límite. Las canceladas no cuentan. | Sin límite |
| `agenda.require_confirmation` | La cita nace pendiente; si se apaga, nace confirmada. | Encendida |
| `agenda.checkin_step` | Si se apaga, una cita pendiente o confirmada se puede dar por atendida directamente. | Encendida |
| `agenda.respect_buffers` | Las pausas antes y después del servicio también ocupan al profesional. | Encendida |
| `agenda.client_overlap` | Un cliente no puede tener dos citas que se crucen. | Encendida |
| `agenda.allow_override` | Permite la sobre-agenda con motivo. Hoy solo al administrador de la empresa. | Encendida |
| `agenda.slot_interval` | Cada cuántos minutos se ofrece un horario libre. | 15 |
| `cancel.require_reason` | Cancelar exige un motivo. | Encendida |
| `noshow.grace_minutes` | No se puede marcar "no asistió" antes de esa tolerancia desde el inicio. | 15 |
| `cancel.window_hours` | Al cancelar más tarde que ese plazo, la respuesta lo indica (`late_cancel`). Todavía no se cobra nada. | 12 |

Otras reglas:

- Un usuario con rol de profesional (que no administra la empresa) solo ve y gestiona sus propias citas.
- Si un servicio tiene profesionales asignados, solo ellos lo atienden; si no tiene ninguno, lo atiende cualquiera.
- Las clases grupales no se reservan como cita.
- Dos personas no pueden tomar el mismo horario a la vez: el alta y la reprogramación se procesan de una en una por empresa.

## 4. Frontend

| Archivo | Cambio |
|---|---|
| `app/components/layout/Sidebar.tsx` | Grupo nuevo "AGENDA", antes de "ATENCIÓN": Agenda, Citas, Cola sin cita, Lista de espera, Controles y seguimiento, Clases y Bloqueos y ausencias. "Citas", "Cola sin cita" y "Clases" se movieron desde "ATENCIÓN". |
| `lib/modules.ts` | `PENDING_SECTIONS`: las secciones del menú que aún no tienen pantalla. |
| `app/(dashboard)/modules/[key]/page.tsx` | La página provisional también muestra esas secciones. |
| `features/settings/components/TenantSettings.tsx` | Tarjeta nueva "Fases de la cita": un campo por fase para cambiarle el nombre. |
| `hooks/useAuth.tsx` | Tipo de `tenant.appointment_statuses`. |

Cada ítem del menú se muestra solo si la empresa tiene su módulo:

| Ítem | Módulo |
|---|---|
| Agenda, Citas, Lista de espera, Bloqueos y ausencias | `agenda` (base) |
| Cola sin cita | `walkin` |
| Controles y seguimiento | `records` |
| Clases | `classes` |

"Citas" apuntaba a `/appointments`, que no tenía página; ahora lleva a la provisional.

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base, con Centro Médico Integral:

| Caso | Resultado |
|---|---|
| Crear una cita el lunes a las 09:00: código `C-0001`, pendiente, termina 09:30, precio del servicio, guardada como 14:00 UTC | Correcto |
| Mismo horario con otro cliente, también con motivo de sobre-agenda | 409, no se puede forzar |
| 09:30, pegada a la anterior (pausas de 5 minutos) | 409, se puede forzar |
| La misma con motivo: un profesional | 409 |
| La misma con motivo: el administrador | Se crea y guarda el motivo |
| Fuera de horario, terminando fuera del tramo y domingo | 409 |
| Fecha pasada y dentro de un bloqueo | 409, no se puede forzar |
| El mismo cliente a la misma hora con otro profesional | 409 |
| Cliente de otra empresa | 404 |
| Servicio grupal | 422 |
| Horarios libres del lunes: no ofrece los ocupados, los de pausa ni los bloqueados; domingo vacío | Correcto |
| Pendiente → confirmada → llegó → en atención → atendida, con historial y autor | Correcto |
| Pendiente → en atención, atendida → cancelada, reprogramar una atendida | 422 |
| Cancelar sin motivo; "no asistió" antes de la cita | 422 |
| Cancelar con motivo: el horario queda libre y se vuelve a agendar | Correcto |
| "No asistió" en una cita que ya pasó | Correcto |
| Reprogramar a otro día, 15 minutos después y con otro profesional | Correcto; cuenta las veces y lo deja en el historial |
| Reprogramar a un horario ocupado | 409, la cita no cambia |
| Lista por día, por fase, por nombre del cliente y sin filtros | Correcto |
| Otra empresa: lista vacía; ver o cambiar una cita ajena | 404 |
| Un profesional solo ve las suyas; cambiar la de otro | 403 |
| Con el plan en su límite | 422; con una disponible se crea |
| Mascota obligatoria: sin mascota y con la de otro cliente | 422; con la suya se crea |
| Renombrar "Llegó" a "En sala": la cita lo muestra | Correcto |
| Nombre de más de 30 caracteres; personal que no administra | 422 y 403 |
| Sin el paso de llegada: pendiente → atendida | Correcto; con el paso, 422 |
| Sin confirmación previa: la cita nace confirmada | Correcto |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| El menú muestra "AGENDA" con Agenda, Citas, Lista de espera, Controles y seguimiento y Bloqueos y ausencias. No muestra Cola sin cita ni Clases (el rubro médico no las usa) | Correcto |
| Cada enlace abre la página provisional con su nombre | Correcto |
| En Configuración aparecen las siete fases; se escribe un nombre para "Pendiente", se vacía el de "Llegó" y se guarda | Correcto |
| Apagar "Fichas de atención e historia" y guardar: "Controles y seguimiento" desaparece del menú | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó: la cancelación tardía (`late_cancel` verdadero), dos altas simultáneas reales, ni la tarjeta "Fases de la cita" en tema oscuro.

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin resetear, las rutas de citas fallan (falta la columna `status`); el resto del sistema sigue funcionando y la configuración se ve, pero no guarda los nombres de las fases.
