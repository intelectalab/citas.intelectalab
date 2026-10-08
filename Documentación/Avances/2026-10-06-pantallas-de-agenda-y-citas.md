# 2026-10-06 — Citas, entrega 3: pantallas de Agenda y Citas

## Contexto

Tercera entrega del módulo de citas. Las dos anteriores dejaron la disponibilidad y la API. Esta deja las dos pantallas con las que el personal trabaja las citas: la agenda del día y la lista de citas, con sus paneles laterales para agendar, ver, cambiar de fase y reprogramar.

No hay cambios de esquema.

## 1. Agenda (`/agenda`)

La agenda de un día en una sede, con una columna por profesional.

| Parte | Qué muestra |
|---|---|
| Cabecera | La fecha, botones de día anterior, "Hoy" y día siguiente, un campo para ir a cualquier fecha, la sede (si hay más de una) y "Nueva cita". |
| Tarjetas | Citas del día, pendientes, confirmadas, en curso (llegó y en atención), atendidas, y canceladas y ausencias. Cada una filtra la grilla con un clic y deja de filtrar con otro. |
| Columnas | Los profesionales que atienden ese día en la sede, o que tienen citas ese día. Bajo el nombre, su horario del día. |
| Grilla | En blanco las horas en que atiende y en gris las que no. Los bloqueos y ausencias aparecen como una franja "No atiende" con su motivo. Una línea roja marca la hora actual cuando el día es hoy. |
| Citas | Un bloque por cita, del color de su fase, con la hora, el cliente (o la mascota, si el rubro la usa), el servicio y la fase. |

Cómo se usa:

- Un clic en un espacio libre abre "Nueva cita" con ese profesional, esa fecha y esa hora (redondeada al intervalo de la empresa, 15 minutos por defecto).
- Un clic en una cita abre su panel.
- Las citas canceladas y las ausencias no se muestran en la grilla, porque ese horario quedó libre. Se ven al hacer clic en su tarjeta.
- Si dos citas se cruzan en una columna (sobre-agenda), se reparten el ancho para que ninguna tape a la otra.
- Si nadie atiende ese día, la pantalla lo dice y enlaza a Profesionales para definir horarios.

## 2. Citas (`/appointments`)

La lista de citas, con la tabla del sistema.

| Parte | Detalle |
|---|---|
| Pestañas | Hoy, Próximos 7 días (por defecto), Próximos 30 días y Últimos 30 días. |
| Tarjetas | Las mismas seis de la agenda, sobre el periodo elegido. Filtran la tabla. |
| Búsqueda | Por código de cita, o por nombre, documento o celular del cliente. |
| Filtros | Fase y profesional. |
| Columnas | Cita (código), fecha y hora, cliente (con su mascota si el rubro la usa), servicio (duración y precio), profesional, fase y acciones. |

El código y el ojo abren el panel de la cita. "Nueva cita" abre el formulario.

## 3. Paneles laterales

### Nueva cita y reprogramar

Un mismo panel para las dos cosas.

| Campo | Detalle |
|---|---|
| Cliente | Se busca escribiendo nombre, documento, celular o código. |
| Mascota o dependiente | Solo en los rubros que los usan. Obligatorio si el rubro lo exige. Si el cliente tiene uno solo, queda elegido. |
| Servicio | Los servicios individuales activos, con su duración. |
| Sede | Solo si hay más de una. |
| Profesional | "Cualquiera disponible" o uno en particular. Si el servicio tiene profesionales asignados, solo aparecen ellos. |
| Fecha y hora | La hora se elige de los horarios libres o se escribe. |
| Horarios libres | Botones con las horas libres de ese día, por profesional. Al elegir uno quedan puestos la hora y el profesional. |
| Notas | Al crear. Al reprogramar se pide en su lugar el motivo del cambio. |

Si el horario no sirve, el panel dice por qué. Cuando el motivo se puede forzar (fuera de horario o choque con una pausa) y quien agenda es el administrador de la empresa, aparece "Agendar de todos modos" con un campo para el motivo.

### Cita

| Parte | Detalle |
|---|---|
| Fase | La fase actual y las cinco del camino normal, con las ya cumplidas marcadas. |
| Acciones | Solo las que la fase permite: Confirmar, Registrar llegada, Iniciar atención, Dar por atendida, Reprogramar, Volver a pendiente, Deshacer llegada, Cancelar cita y No asistió. |
| Cancelar | Pide el motivo antes de cancelar. |
| Datos | Cliente, celular, mascota, servicio, profesional, sede, fecha, hora, precio y, si aplica, motivo de sobre-agenda, motivo de cancelación y veces reprogramada. |
| Notas | Se editan y se guardan desde el panel. |
| Historial | Cada cambio, con fecha, quién lo hizo y el motivo. |

Los nombres de las fases son los que la empresa definió en Configuración.

## 4. Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/Api/AppointmentController.php` | `context()`: sedes, profesionales y servicios que se pueden agendar, si quien consulta puede forzar un horario y el intervalo de la grilla. `day()`: la agenda de un día en una sede (profesionales con su horario y bloqueos, y las citas). |
| `routes/api.php` | `GET /api/appointments/context` y `GET /api/appointments/day`, dentro del módulo `agenda`. |
| `app/Http/Controllers/Api/CustomerController.php` | La búsqueda de clientes ya no distingue mayúsculas, y también busca por nombre completo y por celular. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/appointments/appointments.ts` | Tipos, colores de cada fase, nombres de las acciones y utilidades de fecha y hora. |
| `features/appointments/components/AgendaDay.tsx` | La agenda del día. |
| `features/appointments/components/AppointmentsTable.tsx` | La lista de citas. |
| `features/appointments/components/AppointmentForm.tsx` | Panel para agendar y reprogramar. |
| `features/appointments/components/AppointmentDetail.tsx` | Panel de la cita. |
| `features/appointments/components/useAppointmentPanels.tsx` | Abre y coordina los paneles; lo usan la agenda y la lista. |
| `app/(dashboard)/agenda/page.tsx`, `app/(dashboard)/appointments/page.tsx` | Las dos páginas. |
| `app/components/layout/Sidebar.tsx`, `lib/modules.ts` | "Agenda" y "Citas" del menú ya llevan a sus pantallas. |

Los colores de las fases salen de la paleta del sistema, así que el tema oscuro los alcanza.

## 5. Verificación

En el navegador, contra una base temporal (eliminada al terminar), con el dueño de Centro Médico Integral:

| Prueba | Resultado |
|---|---|
| El menú lleva a la agenda; hoy (martes) muestra una columna y el miércoles dos, cada una con su horario | Correcto |
| Clic en la columna a las 10:20: abre "Nueva cita" con el miércoles, las 10:15 y el médico de esa columna | Correcto |
| Buscar "juliana d" (en minúsculas) y por número de documento | Encuentra al paciente |
| Enviar sin servicio | Aviso "Elige un servicio." |
| Elegir servicio: aparecen los horarios libres; al agendar, la cita sale en la grilla a su hora y la tarjeta de pendientes pasa a 1 | Correcto |
| Clic en la cita: abre su panel. Confirmar, registrar llegada, iniciar atención: cada paso cambia la fase y las acciones disponibles | Correcto |
| Elegir un horario libre de otra médica: quedan puestos la hora y la médica | Correcto |
| Escribir una hora fuera de su horario: aviso con el motivo y el campo "Agendar de todos modos"; con motivo, la cita se crea | Correcto |
| El panel de esa cita muestra "Agendada fuera de horario: Urgencia" | Correcto |
| Reprogramar: elegir otra hora; el panel vuelve con la hora nueva y el historial lo registra; la grilla la mueve | Correcto |
| Cancelar sin motivo: aviso. Con motivo: queda cancelada y sale de la grilla | Correcto |
| Tarjeta "Canceladas y ausencias": muestra solo la cancelada. Tarjeta "En curso": solo la que está en atención | Correcto |
| Lista de citas: dos filas con sus datos; la tarjeta "En curso" deja una; la pestaña "Hoy" queda vacía y "Próximos 30 días" trae las dos | Correcto |
| En la lista: el código y el ojo abren el panel; "Dar por atendida" actualiza la fase y las tarjetas; "Nueva cita" abre el formulario | Correcto |
| Buscar "carlos ram" en la lista | Encuentra la cita |
| Agenda y panel de la cita en tema oscuro | Se leen bien |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó en pantalla: la agenda con más de una sede, un rubro con mascotas, "No asistió", las franjas de bloqueo, dos citas cruzadas en una columna, la línea de la hora actual, los filtros de fase y profesional de la lista, guardar notas, ni la vista de un usuario con rol de profesional. La API de todos esos casos sí está probada.
- Dos ajustes se hicieron después de las pruebas y solo pasaron la verificación de tipos: la última fase se marca como cumplida al llegar a "Atendida", y el subtítulo del panel de reprogramar dice "ahora:" antes de la fecha actual de la cita.

## 6. Para aplicar

Requiere el esquema de la entrega anterior: resetear con `init.sql` y cargar `seed.sql`. El seed no trae citas, así que la agenda empieza vacía.
