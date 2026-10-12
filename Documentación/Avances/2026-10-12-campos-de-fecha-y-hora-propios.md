# 2026-10-12 — Campos de fecha y de hora propios en todos los formularios

## Contexto

Los filtros de las tablas ya tenían calendario propio, pero los formularios seguían abriendo el calendario y el reloj genéricos del navegador. Pedido: quitarlos.

Sin cambios de base ni de API: los campos entregan el mismo valor que antes (`AAAA-MM-DD` y `HH:MM`).

## 1. Campo de fecha (`DateField`)

- Se ve como los demás campos del formulario: la fecha escrita ("18 nov 2026") y un icono de calendario.
- Al abrirlo muestra el mes, con el día de hoy marcado y el día elegido resaltado.
- **El título del calendario lleva a elegir mes y año**: un clic en "Octubre de 2026" muestra los doce meses; otro clic, los años de doce en doce. Sirve para llegar rápido a una fecha lejana, como un nacimiento.
- Los días que no se pueden elegir salen tachados: en "Hasta", los anteriores a "Desde"; en fecha de nacimiento, los futuros.
- Abajo: "Hoy" y, si la fecha es opcional, "Quitar fecha".
- Si la fecha es obligatoria, el formulario no se envía sin ella, igual que antes.

## 2. Campo de hora (`TimeField`)

- Dos columnas: horas (00 a 23) y minutos (de 5 en 5). Al abrir, cada columna se ubica en lo que ya está elegido.
- Elegir la hora deja el panel abierto para elegir los minutos; elegir los minutos lo cierra.
- "Quitar hora" cuando la hora es opcional.
- Versión angosta para las filas de horarios.

## 3. Dónde se cambió

| Pantalla | Campos |
|---|---|
| Nueva cita / reprogramar | Fecha y hora |
| Agenda | "Ir a una fecha" de la barra |
| Nuevo bloqueo | Desde, Hasta, hora de inicio y de fin |
| Nuevo feriado | Fecha |
| Nuevo control | Fecha del control |
| Lista de espera | Desde, Hasta y franja horaria |
| Cliente y sus dependientes o mascotas | Fecha de nacimiento |
| Fichas de atención | Campos de tipo fecha y "próximo control" |
| Horario de un profesional | Tramos de cada día y las ausencias |
| Horario de una sede | Tramos de cada día |
| Consola de la plataforma, página de una empresa | Nueva fecha de vencimiento |

**Ausencias en el horario del profesional:** antes era un solo campo de fecha y hora del navegador. Ahora son una fecha y una hora, en una fila "Desde" y otra "Hasta". Si se elige solo el día, toma el día completo (00:00 en "Desde" y 23:59 en "Hasta"); la hora se puede cambiar después.

No queda ningún campo de fecha ni de hora del navegador en el proyecto.

## 4. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/app/components/common/DateField.tsx` | Nuevo. |
| `apps/web/src/app/components/common/TimeField.tsx` | Nuevo. |
| `features/appointments/components/AppointmentForm.tsx`, `AgendaDay.tsx` | Usan los campos nuevos. |
| `features/blocks/components/FormBlock.tsx`, `FormHoliday.tsx` | Igual. |
| `features/recalls/components/FormRecall.tsx`, `features/waitlist/components/FormWaitlist.tsx` | Igual. |
| `features/customers/components/FormCustomer.tsx`, `CustomerDependents.tsx` | Igual; la fecha de nacimiento no admite días futuros. |
| `features/records/components/RecordFieldInput.tsx` | Igual. |
| `features/professionals/components/ProfessionalSchedule.tsx`, `features/branches/components/BranchHoursPanel.tsx` | Igual; las ausencias pasan a fecha y hora separadas. |
| `features/tenants/components/TenantDetail.tsx` | Igual. |

## 5. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales, como dueña de Centro Médico:

| Prueba | Resultado |
|---|---|
| Nuevo bloqueo: abrir "Desde", pasar a noviembre y elegir el 18 | Queda "18 nov 2026" y "Hasta" lo sigue |
| Abrir "Hasta" | El 17 de noviembre sale bloqueado y no se puede elegir; el 20 sí |
| Clic en el título, dos veces | Pasa a meses ("2026") y luego a años ("2016 – 2027") |
| Elegir 2027, febrero y el día 10 | "Hasta" queda en "10 feb 2027" |
| Apagar "Días completos" | Aparecen las horas, 09:00 y 13:00 |
| Abrir la hora de inicio | 24 horas y 12 opciones de minutos, ubicada en 09 |
| Elegir 14 y luego 35 | Tras la hora sigue abierto ("14:00"); tras los minutos se cierra con "14:35" |
| Nuevo feriado, sin fecha | El formulario no es válido; al elegir "Hoy" pasa a serlo |
| Agenda: "Ir a una fecha", elegir el 3 de noviembre | La agenda cambia de día; "Nueva cita" abre con esa fecha |
| Nueva cita: elegir 10:30 y luego "Quitar hora" | "10:30" y vuelve a "--:--" |
| Horario de un profesional | 24 campos de hora propios; cambiar el primer tramo a 07:30 |
| Ausencia: elegir solo el día en "Hasta" | Toma 23:59 |
| Ausencia: elegir solo la hora en "Desde" | Toma la fecha de hoy |
| Registrar esa ausencia | Se guarda: "12 oct. 2026, 08:00 a. m. — 12 oct. 2026, 11:59 p. m." |
| Nuevo paciente: fecha de nacimiento, ir a 1980–1991, elegir 1990, mayo y 23 | "23 may 1990" |
| "Quitar fecha" | Vuelve a "Elige una fecha" |
| En todas las pantallas abiertas | Ningún campo de fecha u hora del navegador |

- Se revisaron en capturas el calendario y el selector de hora abiertos en "Nuevo bloqueo", y las ausencias del horario.
- `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** lista de espera, nuevo control, fichas de atención, dependientes y mascotas, horario de la sede y la página de una empresa en la consola (usan el mismo componente; se cambiaron y compilan). Tampoco que los días futuros salgan bloqueados en la fecha de nacimiento (se comprobó el bloqueo en "Hasta", que es el mismo mecanismo), el tema oscuro ni el celular.

## 6. Para aplicar

No requiere resetear la base. Basta recargar la página.
