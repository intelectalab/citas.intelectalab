# 2026-10-11 — Plazo para confirmar una cita y "Reglas de reserva" en manos de la empresa

## Contexto

No había plazo para confirmar: el cliente podía hacerlo hasta la hora de la cita, y una cita que nadie confirmaba se quedaba "Pendiente" sin avisar a nadie. La empresa no podía saber si la persona vendría.

Decidido:

- Por defecto, la cita debe estar confirmada **24 horas antes**, y si no lo está **se avisa**.
- Cada empresa decide su plazo y qué pasa al vencer: solo avisar, avisar y luego liberar, o liberar de inmediato.
- Eso se elige en una sección nueva, "Reglas de reserva", de la configuración de la empresa.

## 1. Cómo funciona el plazo

La cita debe estar confirmada cierto número de horas antes de su inicio. Si llega ese momento y sigue "Pendiente", pasa lo que la empresa eligió:

| Opción | Qué hace |
|---|---|
| **Solo avisar** (por defecto) | La cita se mantiene. Se avisa al equipo para que llame a confirmar. |
| **Avisar y luego liberar** | Primero se avisa. Si más cerca de la hora (las horas que la empresa indique) sigue sin confirmar, la cita se cancela y el horario queda libre. |
| **Liberar de inmediato** | Al vencer el plazo la cita se cancela y el horario queda libre. |

Reglas:

- **Una cita agendada cuando el plazo ya había pasado no cuenta** (por ejemplo, una cita para hoy mismo): nadie tuvo tiempo de confirmarla. Ni se avisa ni se libera.
- Con el plazo en **0** no hay plazo: todo queda como antes.
- Confirmar la cita, sea el cliente desde el portal o el personal, la saca de todo esto.
- Una cita liberada queda **Cancelada** con el motivo "No se confirmó a tiempo", y en su historial figura como liberada automáticamente, sin autor. Los controles y la lista de espera reaccionan igual que ante cualquier cancelación.
- Los rubros que no usan el paso de confirmación no tienen plazo.

### Quién se entera

| Qué pasó | Profesional de la cita | Dueño y administradores |
|---|---|---|
| Venció el plazo y la cita sigue pendiente | "Cita sin confirmar", una por cita | **Un solo aviso por día** con cuántas son ("3 citas siguen sin confirmar"), que lleva a Citas |
| La cita se liberó | "Cita liberada por falta de confirmación" | Igual, una por cita |

El aviso agrupado para la administración es a propósito: así no se le llena la campana con una notificación por cada cita pendiente. En "avisar y luego liberar", el aviso dice además a cuántas horas se liberará.

### Dónde se ve

- **Citas (tabla):** bajo la fase, "Sin confirmar a tiempo" en rojo.
- **Panel de la cita:** "Debe confirmarse antes del…" o, si ya venció, "Venció el plazo: debía confirmarse antes del…".
- **Portal del cliente:** en cada cita pendiente, "Confirma tu asistencia antes del viernes 16 de octubre, 21:30"; si la empresa libera, agrega "o tu cita se cancelará". Si ya se le pasó el plazo: "Aún no confirmas tu asistencia…".

### Cuándo se revisa

No hay tarea programada. Las citas vencidas se revisan cuando alguien de la empresa tiene el panel abierto (la campana consulta cada 30 segundos) y cuando el cliente abre su portal.

## 2. Reglas de reserva (`/settings`)

Sección nueva en la configuración de la empresa; solo la ve su administrador.

| Control | Qué define |
|---|---|
| "La cita debe estar confirmada __ horas antes de su inicio" | El plazo. 0 = sin plazo. |
| "Si llega el plazo y sigue sin confirmar" | Tres tarjetas para elegir: Solo avisar, Avisar y luego liberar, Liberar de inmediato. |
| "Liberar la cita si sigue sin confirmar __ horas antes" | Solo con "Avisar y luego liberar". Debe ser menor que el plazo. |
| "El cliente puede cancelar o cambiar su cita hasta __ horas antes" | La ventana para cancelar, que antes solo podía fijar la plataforma en el rubro. |

- Los campos de horas solo aceptan dígitos; el máximo es 168 horas (una semana).
- Lo que la empresa no cambie sigue tomando el valor de su rubro. La plataforma puede fijar el plazo por defecto de cada rubro desde su editor de reglas.

## 3. Base de datos

Sin tablas ni columnas nuevas: las reglas se guardan en `tenant_settings.rules`, que ya existía. Solo cambió un comentario en `init.sql` (`cancelled_by` admite ahora `system`).

## 4. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Confirmations.php` | Nuevo. El plazo, qué cita está vencida y la revisión que avisa o libera. |
| `apps/api/app/Support/AppointmentFlow.php` | Valores por defecto: 24 horas, solo avisar, liberar a 6 horas. |
| `apps/api/app/Support/Notifier.php` | Avisos de cita sin confirmar (uno por cita al profesional, uno por día a la administración) y de cita liberada. |
| `apps/api/app/Http/Controllers/Api/NotificationController.php`, `PortalController.php` | La revisión corre al consultar la campana y al abrir el portal; el portal recibe el plazo de cada cita. |
| `apps/api/app/Http/Controllers/Api/AppointmentController.php` | Cada cita trae su plazo y si ya venció. |
| `apps/api/app/Http/Controllers/Api/TenantSettingController.php` | Leer y guardar las reglas de reserva. |
| `apps/web/src/features/settings/components/TenantSettings.tsx` | La sección "Reglas de reserva". |
| `apps/web/src/features/appointments/components/AppointmentsTable.tsx`, `AppointmentDetail.tsx`, `appointments.ts` | La marca y el plazo en la tabla y en el panel. |
| `apps/web/src/features/portal/` | El aviso en cada cita pendiente. |
| `apps/web/src/features/notifications/notifications.tsx`, `lib/rules.ts` | Iconos de los avisos nuevos; el plazo en el editor de reglas del rubro. |

## 5. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada).

**Por la API**, con cinco citas preparadas (vencida a 10 h, vencida a 3 h, agendada con el plazo ya pasado, confirmada, y pendiente a 30 h):

| Caso | Resultado |
|---|---|
| Por defecto | 24 horas y solo avisar |
| Solo avisar | Ninguna cambia de fase; los profesionales reciben 2 avisos (uno por cita vencida); la dueña, uno solo: "2 citas siguen sin confirmar" |
| Revisar otra vez | No se repite ningún aviso |
| Lo que trae cada cita | La vencida, marcada y con su plazo; la agendada tarde, sin plazo ni marca; la confirmada, sin marca; la de 30 h, con plazo y sin marca |
| Avisar y luego liberar (a 6 h) | La de 3 h se libera; la de 10 h sigue pendiente y su aviso advierte que se liberará |
| La liberada | Cancelada, motivo "No se confirmó a tiempo", en el historial sin autor; avisos al profesional y a la dueña |
| Liberar de inmediato | Las dos vencidas se cancelan; las otras tres, intactas; no hay avisos de "sin confirmar" |
| Confirmada antes de revisar | No se libera |
| Plazo en 0 | No se avisa ni se libera nada |
| Guardar las reglas desde la configuración | Se guardan y rigen de inmediato |
| Liberar a las mismas horas que el plazo; plazo de 500 horas; opción inexistente | Rechazados con 422 |
| Un profesional intenta cambiarlas | 403 |
| Guardar otra parte de la configuración | No borra las reglas |
| Otra empresa | Conserva sus 24 horas y solo avisar |

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Como dueña: la campana | Trae "Una cita sigue sin confirmar", generada sola |
| Citas | La cita vencida muestra "Sin confirmar a tiempo" |
| Su panel | "Venció el plazo: debía confirmarse antes del 10 oct. 2026, 11:48" |
| Configuración, "Reglas de reserva" | Carga con 24 horas, solo avisar y 12 horas para cancelar |
| Elegir "Avisar y luego liberar" | Queda marcada y aparece el campo de horas para liberar |
| Escribir "30x" en ese campo | Queda "30" (solo dígitos); al guardar, el mensaje dice que debe ser menor que el plazo |
| Poner 12, 4 y 8 y guardar | "Configuración guardada."; en la base quedan los cuatro valores |
| Plazo en 0 | Desaparecen las tres opciones y aparece la explicación |
| Como clienta, en el portal | Su cita vencida dice "Aún no confirmas tu asistencia: tu cita puede cancelarse. Confírmala ahora." |
| "Confirmar asistencia" | Pasa a Confirmada y el aviso desaparece |
| Otra cita pendiente | "Confirma tu asistencia antes del viernes, 16 de octubre, 00:00, o tu cita se cancelará." |

- Se revisaron en capturas la sección de reglas y el portal.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** ver una cita liberarse sola (se probó por la API), el aviso de liberación en la campana, la marca en la agenda (no la tiene: solo la tabla de Citas y el panel), el editor de reglas del rubro en la plataforma, el tema oscuro y el celular.

## 6. Para aplicar

No hay tablas nuevas por este cambio. Si `erp_saas` ya se reseteó para las notificaciones, basta recargar. Si no, hay que resetear con `init.sql`, `seed.sql` y `seed_demo.sql`: sin la tabla de notificaciones el plazo se marca en pantalla, pero los avisos no llegan.
