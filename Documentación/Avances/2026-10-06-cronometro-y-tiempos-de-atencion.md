# 2026-10-06 — Cronómetro de la cita y tiempos de atención

## Contexto

Pedido: ver el tiempo que va pasando en una cita, como un cronómetro, y usar ese tiempo real para medir cuánto dura cada atención, qué profesional es más ágil, el promedio por servicio y el promedio de cada cliente. La cita tiene un tiempo asignado, pero la atención puede terminar antes o después.

No hay cambios de esquema. Los tiempos salen de las marcas que la cita ya guardaba al cambiar de fase: llegó (`checked_in_at`), en atención (`started_at`) y atendida (`completed_at`).

## 1. Qué se mide

| Tiempo | De dónde a dónde |
|---|---|
| Espera | Desde "Registrar llegada" hasta "Iniciar atención". |
| Atención | Desde "Iniciar atención" hasta "Dar por atendida". |
| Asignado | La duración con que se agendó la cita. |

Una atención no entra en los promedios cuando su tiempo no es fiable:

- Duró menos de un minuto: se pasaron las fases de corrido, sin esperar a que ocurrieran.
- Quedó abierta más de cuatro veces su tiempo asignado (y más de cuatro horas): alguien olvidó cerrarla.

Esas citas se cuentan como atenciones, pero aparecen como "sin medir".

## 2. Cronómetro

| Dónde | Qué muestra |
|---|---|
| Agenda, en el bloque de la cita | Mientras el cliente espera o está en atención, el tiempo corriendo (`12:34`). En atención, pasa a rojo al superar el tiempo asignado. |
| Panel de la cita, sección "Tiempos" | En espera: "Esperando hace 04:42". En atención: "En atención hace 12:35" y el tiempo asignado. Atendida: cuánto esperó, cuánto duró la atención y cuánto más o menos que lo asignado. |
| Panel de la cita | El promedio de ese cliente en sus atenciones anteriores. |

El cronómetro parte del tiempo que calcula el servidor y avanza solo, así que no depende de que el reloj del equipo esté en hora.

## 3. Si la atención termina antes

Cuando una cita se da por atendida antes de su hora de fin, el resto de su horario queda libre: los horarios libres lo ofrecen y se puede agendar ahí. En la agenda, el bloque de esa cita llega hasta donde terminó de verdad.

Si la atención se alarga, el horario siguiente no se bloquea solo: el cronómetro en rojo es el aviso.

## 4. Tiempos de atención (`/attention-times`)

Página nueva en el grupo Agenda del menú.

| Parte | Contenido |
|---|---|
| Periodo | Últimos 7, 30 (por defecto) o 90 días. |
| Tarjetas | Atenciones, tiempo promedio, tiempo asignado, diferencia frente a lo asignado y espera promedio. |
| Aviso | Cuántas atenciones quedaron sin medir y por qué. |
| Por profesional | Atenciones, tiempo promedio (con una barra para comparar), asignado, diferencia y espera promedio. Marca "Más ágil" a quien mejor queda frente a su tiempo asignado. |
| Por servicio | Lo mismo. Sirve para ajustar la duración de un servicio que siempre dura más o menos de lo asignado. |
| Por cliente | Atenciones, tiempo promedio, asignado, diferencia y tiempo total del periodo. |

La diferencia se pinta en verde cuando terminan antes y en rojo cuando se pasan. Un usuario con rol de profesional solo ve sus propios tiempos.

## 5. Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Support/AppointmentTiming.php` | Nuevo. Tiempos de una cita, hasta cuándo ocupa al profesional y promedios de un grupo de citas. |
| `app/Http/Controllers/Api/AppointmentController.php` | Cada cita entrega `timing` y `occupied_until`. Al abrir una cita se agrega `customer_timing`. Método nuevo `metrics()`. |
| `app/Support/AppointmentScheduler.php` | Una cita atendida antes de tiempo ocupa al profesional solo hasta que terminó. |
| `routes/api.php` | `GET /api/appointments/metrics` (`from`, `to`, `branch_id`), dentro del módulo `agenda`. |

### Frontend

| Archivo | Cambio |
|---|---|
| `features/appointments/components/Stopwatch.tsx` | Nuevo. El cronómetro. |
| `features/appointments/components/AttentionTimes.tsx` | Nuevo. La página de tiempos. |
| `app/(dashboard)/attention-times/page.tsx` | Nuevo. |
| `features/appointments/components/AppointmentDetail.tsx` | Sección "Tiempos". |
| `features/appointments/components/AgendaDay.tsx` | Cronómetro en el bloque de la cita; el bloque de una cita atendida antes llega hasta su fin real. |
| `features/appointments/appointments.ts` | Tipos y formato de tiempos. |
| `app/components/layout/Sidebar.tsx` | Ítem "Tiempos de atención" en el grupo Agenda. |

## 6. Verificación

- Pruebas de la API contra una base temporal (eliminada al terminar), con siete atenciones de tiempos conocidos:

| Caso | Resultado |
|---|---|
| Resumen de 30 días: 6 atenciones, 4 medidas, promedio 28,8 min, asignado 30, diferencia −1,3, espera promedio 10 | Coincide con los datos cargados |
| La atención que duró 0 minutos y la que quedó abierta 5 horas | No entran en los promedios |
| Por profesional, por servicio y por cliente | Coinciden |
| 90 días: suma la atención de hace 60 días | Correcto |
| Otra empresa | 0 atenciones |
| Usuario con rol de profesional | Solo se ve a sí mismo |
| Fecha inválida | 422 |
| Cita recién creada: sin tiempos. Llegó hace 7 min: espera corriendo. En atención hace 12 min: atención corriendo | Correcto |
| Cita de 10:00 a 10:30 atendida a las 10:10: ocupa hasta las 10:11 y el horario de las 10:30 pasa a estar libre (antes, el primero libre era 10:45) | Correcto |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| Agenda con tres citas en curso: cada bloque muestra su cronómetro y avanza segundo a segundo | Correcto |
| La cita que lleva 45 minutos de 30 asignados lo muestra en rojo | Correcto |
| Panel de una cita en atención: "En atención hace 12:35", avanza; muestra lo que esperó y el promedio del paciente | Correcto |
| "Dar por atendida": pasa a "La atención duró 13 min · 17 min menos de lo asignado (30 min)" | Correcto |
| Panel de una cita en espera: "Esperando hace 04:42". "Iniciar atención": el cronómetro de atención arranca en 00:02 | Correcto |
| El menú muestra "Tiempos de atención" y abre la página | Correcto |
| La página muestra las cinco tarjetas, el aviso de atenciones sin medir y las tres tablas; cambiar a 7 y a 90 días cambia las fechas y los totales | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó: la página de tiempos en tema oscuro, con varias sedes ni con un usuario profesional en pantalla. Un ajuste de texto ("1 medida" en singular) se hizo después de las pruebas.

## 7. Para aplicar

Nada adicional a las entregas anteriores. Los tiempos empiezan a acumularse desde que el personal marca "Registrar llegada", "Iniciar atención" y "Dar por atendida" en su momento.
