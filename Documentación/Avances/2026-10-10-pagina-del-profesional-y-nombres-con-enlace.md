# 2026-10-10 — Página propia de cada profesional y nombres que llevan a la página de la persona

## Contexto

Dos pedidos:

1. Que cada profesional tenga su propia página, como ya la tienen los clientes.
2. Que en cada lugar donde aparece el nombre de un profesional o de un cliente se pueda hacer clic para ir a su página, sin tener que buscarlo en la lista de Profesionales o de Clientes.

Sin cambios de base.

## 1. Página del profesional (`/professionals/[id]`)

Sigue el mismo diseño que la página del cliente (tarjetas de distinto tamaño, mismo ancho, enlace grande de regreso).

| Parte | Qué muestra |
|---|---|
| Datos | Iniciales, nombre, desde cuándo está, estado, correo de acceso, celular, documento, colegiatura (solo en los rubros que la usan) y la sede o sedes donde atiende. |
| Especialidades y servicios | Sus especialidades; y los servicios que atiende, si tiene servicios asignados. |
| Números | Citas de hoy, citas de los próximos 30 días (y cuál sigue), atenciones de los últimos 30 días (y a cuántos clientes distintos), canceladas y ausencias, y tiempo promedio de atención. |
| Horario de la semana | Cada día con sus tramos; hoy resaltado; total de horas a la semana. Con más de una sede, cada tramo dice en cuál. |
| Ausencias | Las vigentes y las próximas. |
| Citas | Próximas y anteriores (30 días hacia cada lado). La fecha y la fase abren la cita; el nombre del cliente lleva a su página. |

- El lápiz abre el formulario de siempre, y "Horario y ausencias" el panel de horario; al guardar, la página se actualiza sola. Solo los ve el administrador.
- En la tabla de Profesionales, **el nombre y el ojo llevan a esta página** (el ojo antes no abría nada).

## 2. Nombres con enlace

Un nombre con enlace se ve igual que antes; al pasar el cursor cambia de color y se subraya.

| Pantalla | Profesional | Cliente |
|---|---|---|
| Citas (tabla) | Sí | Sí |
| Panel de una cita (desde cualquier pantalla) | Sí | Sí |
| Agenda | En el encabezado de cada columna | — (la tarjeta abre la cita, y ahí está el enlace) |
| Lista de espera | Sí | Ya lo tenía |
| Controles y seguimiento | Sí | Ya lo tenía |
| Bloqueos y ausencias | "Ver la página de…" bajo el nombre (el nombre abre el bloqueo) | — |
| Fichas de atención | Sí | Sí (el código, debajo, abre la ficha) |
| Tiempos de atención | Sí | Sí |
| Horarios del equipo | Sí | — |
| Página de la sucursal | Sí | — |
| Página del cliente, sus citas | Sí | — |
| Página del profesional, sus citas | — | Sí |
| Inicio, actividad reciente | — | Sí |
| Profesionales (tabla) | Nombre y ojo | — |

**Sin enlace, a propósito:** las tarjetas de cita de la agenda y las próximas citas del inicio (toda la tarjeta ya es un botón o un enlace), los textos de confirmación ("¿Eliminar…?") y el portal del cliente.

## 3. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/features/professionals/components/ProfessionalDetail.tsx`, `app/(dashboard)/professionals/[id]/page.tsx` | Nuevos. La página del profesional. |
| `apps/web/src/app/components/common/PersonLink.tsx` | Nuevo. `ProfessionalLink` y `CustomerLink`: el nombre que lleva a la página. |
| `features/professionals/components/ProfessionalsTable.tsx` | Nombre y ojo llevan a la página. |
| `features/appointments/components/AppointmentsTable.tsx`, `AppointmentDetail.tsx`, `AgendaDay.tsx`, `AttentionTimes.tsx`, `features/waitlist/components/WaitlistTable.tsx`, `features/recalls/components/RecallsTable.tsx`, `features/blocks/components/BlocksTable.tsx`, `features/records/components/RecordsTable.tsx`, `features/professionals/components/TeamSchedules.tsx`, `features/branches/components/BranchDetail.tsx`, `features/customers/components/CustomerDetail.tsx`, `features/home.tsx` | Nombres con enlace. |
| `apps/api/app/Http/Controllers/Api/CareRecordController.php`, `DashboardController.php`, `features/records/records.ts` | La lista de fichas y la actividad del inicio traen el identificador de la persona, para poder enlazarla. |

La página usa lo que la API ya entregaba (profesional, horario, citas, ausencias); no hubo rutas nuevas.

## 4. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales en Centro Médico:

| Prueba | Resultado |
|---|---|
| Citas: clic en el nombre de la médica de la primera fila | Abre su página |
| La página: datos, sede, 5 números, horario de lunes a sábado con hoy resaltado, 27 citas próximas y 54 anteriores | Correcto |
| Lápiz: el formulario abre con sus datos; cambiar el nombre y "Guardar Cambios" | El título de la página cambia sin recargar |
| "Horario y ausencias" | Abre el panel de horario de esa persona |
| Clic en el nombre de un paciente de sus citas | Abre la página del paciente |
| En la página del paciente, clic en el nombre de la médica | Vuelve a la página de la médica |
| Profesionales: el nombre y el ojo de una fila | Ambos abren la página |
| Panel de una cita | El paciente y la médica son enlaces |
| Agenda, Lista de espera, Controles, Bloqueos, Horarios del equipo, Tiempos de atención | Los nombres son enlaces |
| Inicio, actividad reciente | Los nombres de clientes son enlaces |

- Se revisó el aspecto de la página en una captura a 1440 px.
- `tsc --noEmit` sin errores en `src`. `php -l` sin errores.
- **No se probó en pantalla:** Fichas de atención (la base de ejemplo no trae fichas), la página de la sucursal, la página vista por un profesional (sin lápiz ni botón de horario), un profesional con dos sedes o con ausencias, el tema oscuro y la vista en celular.

## 5. Para aplicar

No requiere resetear la base. Basta recargar la página.
