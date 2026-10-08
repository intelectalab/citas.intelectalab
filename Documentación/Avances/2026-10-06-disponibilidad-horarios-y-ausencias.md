# 2026-10-06 — Citas, entrega 1: disponibilidad (horario semanal y ausencias)

## Contexto

Primera de tres entregas del módulo de citas: disponibilidad, API de citas y pantalla de agenda. Sin saber cuándo atiende cada profesional no se puede validar una cita.

Las tablas `professional_schedules` y `availability_blocks` ya existían en el esquema, pero no tenían modelo, rutas ni pantalla. `Professional::schedules()` apuntaba a un modelo que no existía, así que `GET /api/professionals/{id}` fallaba.

No hay cambios de esquema. `seed.sql` agrega horarios de ejemplo.

## Decisiones tomadas para las entregas siguientes

- **Fases de la cita.** Son siete y fijas: pendiente, confirmada, llegó, en atención, atendida, cancelada y no asistió. La empresa no agrega ni quita fases; solo podrá cambiarles el nombre. La cita guardará la clave de la fase (`appointments.status`), no una referencia a `statuses`, y el nombre propio de cada empresa irá en su configuración, como el vocabulario.
- **`status_modules` y `statuses` se quedan** para clientes, servicios y profesionales. Solo las citas dejan de usarlas.
- **Vista inicial de la agenda:** el día, con una columna por profesional.

## Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Models/ProfessionalSchedule.php` | Nuevo. Un tramo del horario semanal de un profesional en una sede. |
| `app/Models/AvailabilityBlock.php` | Nuevo. Un rango en que no se atiende. |
| `app/Http/Controllers/Api/ProfessionalScheduleController.php` | Nuevo. Ver y guardar el horario. |
| `app/Http/Controllers/Api/AvailabilityBlockController.php` | Nuevo. Listar, crear y quitar bloqueos. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `agenda`. |
| `infrastructure/docker/postgres/seed.sql` | Horario de los dos profesionales de Centro Médico: Dr. Pérez de lunes a viernes en dos tramos; Dra. Gómez lunes, miércoles, viernes y sábado por la mañana. |

### Rutas (personal de la empresa, módulo `agenda`)

| Ruta | Efecto |
|---|---|
| `GET /api/professionals/{id}/schedule` | Sedes activas de la empresa y todos los tramos del profesional. |
| `PUT /api/professionals/{id}/schedule` (`branch_id`, `ranges`) | Reemplaza el horario del profesional en esa sede. Cada tramo lleva día de la semana (0 = domingo), inicio y fin (`HH:MM`). Una lista vacía deja la sede sin horario. |
| `GET /api/availability-blocks` (`professional_id`, `branch_id`, `from`, `to`) | Bloqueos de la empresa. Sin rango, solo los que aún no terminan. |
| `POST /api/availability-blocks` | Crea un bloqueo para un profesional, para una sede o para ambos. Pide inicio, fin y motivo. |
| `DELETE /api/availability-blocks/{id}` | Quita el bloqueo. |

Reglas del horario:

- Un día puede tener varios tramos (mañana y tarde).
- Los tramos de un mismo día no pueden cruzarse.
- Un profesional no puede tener tramos que se crucen entre dos sedes.
- Si algo falla, no se guarda nada.

Las fechas de los bloqueos se reciben y se devuelven en la zona horaria de la empresa (`tenants.timezone`) y se guardan con zona horaria.

### Frontend

| Archivo | Cambio |
|---|---|
| `features/professionals/components/ProfessionalSchedule.tsx` | Nuevo. Panel lateral "Horario de {nombre}". |
| `features/professionals/components/ProfessionalsTable.tsx` | Nueva acción en cada fila, "Horario y ausencias". |

El panel tiene dos partes:

- **Horario semanal.** Siete días, de lunes a domingo, cada uno con un interruptor. Al encender un día toma el horario del día anterior que atiende, o de 09:00 a 18:00 si es el primero. "Agregar tramo" suma otro rango al día. Si la empresa tiene más de una sede, una lista permite elegir de cuál se edita el horario. Se guarda con "Guardar horario".
- **Ausencias.** Lista de las próximas, con su motivo y su rango, y un formulario para agregar otra. Se guardan y se quitan al instante.

## Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal; quedan 14 tramos de horario. La temporal se eliminó.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| Ver el horario del seed: 10 tramos y una sede | Correcto |
| Guardar 3 tramos: reemplazan a los 10 | Correcto |
| Tramos que se cruzan el mismo día, fin antes del inicio, hora mal escrita | 422, y el horario anterior se conserva |
| Segunda sede con un tramo que se cruza con la primera | 422 |
| Segunda sede con un tramo en el hueco | Correcto |
| Vaciar una sede: la otra conserva su tramo | Correcto |
| Crear ausencia: las 09:00 de Lima se guardan como 14:00 UTC | Correcto |
| Ausencia con fin antes del inicio, sin motivo, sin profesional ni sede | 422 |
| Listar ausencias: no incluye las que ya terminaron | Correcto |
| Otra empresa: ver o guardar el horario, crear o quitar la ausencia, sede ajena | 404; su lista de ausencias sale vacía |

- En el navegador, contra la base temporal, con el dueño de Centro Médico Integral:

| Prueba | Resultado |
|---|---|
| La acción de la fila abre el panel "Horario de Dr. Carlos Pérez" | Correcto |
| Encender lunes: 09:00 a 18:00. Encender martes: copia el lunes. Apagar lunes: queda "No atiende" | Correcto |
| "Agregar tramo" y quitar un tramo | Correcto |
| Guardar con tramos cruzados: aviso "El martes tiene tramos que se cruzan." | Correcto |
| Corregir la hora y guardar: "Horario guardado." | Correcto |
| Cambiar de sede: muestra el horario de la otra sede | Correcto |
| Guardar en la segunda sede un tramo que se cruza con la primera: aviso del cruce | Correcto |
| Ausencia con fin antes del inicio: aviso; corregida: aparece en la lista y el formulario se limpia | Correcto |
| Quitar la ausencia: la lista vuelve a "No tiene ausencias próximas." | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se revisó el panel en tema oscuro ni con el rol de soporte SaaS.

## Para aplicar

No hace falta resetear el esquema: las tablas ya existían. Para tener los horarios de ejemplo, volver a cargar `seed.sql` tras un reseteo.
