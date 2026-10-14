# 2026-10-13 — Usuarios y roles, entrega 2: cada pantalla oculta lo que la persona no puede usar, y permisos especiales

## Contexto

Con la entrega 1 los permisos ya mandaban en el menú y en la API, pero en varias pantallas los botones se veían igual para todos: quien no podía, recibía el mensaje recién al pulsar. Además, tres cosas delicadas seguían siendo solo del administrador, sin poder dárselas a nadie más.

## 1. Los botones siguen al permiso

Cada pantalla muestra ahora solo lo que la persona puede hacer:

| Pantalla | Qué se oculta sin el permiso |
|---|---|
| Clientes | "Nuevo", el lápiz y el tacho; en la página del cliente, el lápiz |
| Servicios | "Nuevo servicio", el lápiz y el tacho |
| Cupones | "Nuevo cupón", editar y eliminar |
| Citas y Agenda | "Nueva cita"; en la agenda, el clic en un espacio libre ya no abre nada |
| Panel de una cita | Los botones de paso (confirmar, registrar llegada, atender, cancelar…), "Reprogramar" y "Guardar notas"; la cita tampoco se puede arrastrar |
| Lista de espera | "Anotar en la lista", avisar, editar, retirar y volver a poner en espera; "Buscar horario" pide poder agendar |
| Controles | "Nuevo control", avisar, dar por realizado, editar y descartar; "Agendar" pide poder agendar |
| Bloqueos y ausencias | "Nuevo bloqueo", editar y eliminar |
| Profesionales | "Nuevo", horario, editar y eliminar; en su página, el lápiz y "Horario y ausencias" |
| Caja | "Abrir caja", "Movimiento" y "Cerrar caja" para quien no opera la caja |
| Panel de una venta | "Cobrar" para quien no cobra |
| Ficha de atención | Se abre solo para leer si la persona no puede llenar fichas |
| Configuración | Quien puede verla pero no cambiarla tiene "Guardar cambios" bloqueado, con su motivo |
| El "+" de servicio en los formularios | Solo para quien puede crear servicios |

Ejemplo: Karina, de caja, entra a Pacientes y ve la lista con el ojo, sin "Nuevo", lápiz ni tacho. Abre una cita y solo encuentra la tarjeta "Cobrar".

## 2. Escribir la dirección de una pantalla sin permiso

Antes la pantalla abría y mostraba un error. Ahora aparece un aviso: "Tu cuenta no tiene acceso a esta pantalla. Tu rol (Caja) no la incluye. Si la necesitas para tu trabajo, pídesela a quien administra tu empresa…", con un enlace para volver al inicio.

La página de un profesional sigue abriéndose desde su nombre para todo el personal, como antes.

## 3. Permisos especiales

Tres permisos nuevos, para cosas delicadas que no son ver, crear, editar ni eliminar. En la rejilla van en una columna aparte, **"Especial"**, con su nombre debajo del interruptor.

| Pantalla | Permiso | Qué deja hacer | Quién lo tiene al nacer |
|---|---|---|---|
| Agenda y citas | Forzar horario | Agendar aunque el horario no esté disponible: fuera del horario de atención, en una pausa o sobre un bloqueo. Nunca permite dos citas a la misma hora | Administrador y Gerente de sede |
| Clientes | Perdonar faltas | Perdonar las inasistencias de quien reservó en línea, para que pueda volver a reservar | Administrador y Gerente de sede |
| Fichas de atención | Acceso de emergencia | Abrir por una emergencia una ficha privada que escribió otra persona. Queda registrado quién la abrió y por qué | Solo el Administrador |

Se dan y se quitan como cualquier otro: a todo un rol o a una persona. "Forzar horario" sigue dependiendo además de la regla del rubro que lo permite.

Con estos tres, los permisos pasan de 60 a **63**.

## 4. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Access.php` | Permisos especiales (`SPECIAL`), sus textos y a qué rol le tocan. |
| `apps/api/app/Http/Controllers/Api/AppointmentController.php` | Forzar horario pide su permiso; la cita no ofrece pasos ni reprogramar a quien no puede cambiarla. |
| `apps/api/app/Http/Controllers/Api/CareRecordController.php`, `CustomerController.php`, `routes/api.php` | Acceso de emergencia y perdonar faltas piden su permiso. |
| `apps/web/src/app/components/layout/ScreenGate.tsx`, `app/(dashboard)/layout.tsx` | Nuevo. El aviso al abrir una pantalla sin permiso. |
| `apps/web/src/features/access/components/PermissionGrid.tsx`, `access.ts` | La columna "Especial". |
| Tablas y páginas de clientes, servicios, cupones, citas, agenda, lista de espera, controles, bloqueos y profesionales | Botones según permiso. |
| `AppointmentDetail.tsx`, `AppointmentForm.tsx`, `FormWaitlist.tsx`, `FormRecall.tsx`, `RecordPanel.tsx`, `CashBoard.tsx`, `SaleDetailPanel.tsx`, `TenantSettings.tsx` | Lo mismo en paneles y formularios. |

Sin cambios en `init.sql`, `seed.sql` ni `seed_demo.sql`.

## 5. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API:**

| Caso | Resultado |
|---|---|
| Permisos en total | 63 |
| Especiales de fábrica | Gerente: forzar horario y perdonar faltas. Recepción, Caja y Profesional: ninguno |
| Recepción perdona inasistencias | Rechazado, con su explicación |
| El gerente perdona | Permitido |
| El gerente pide acceso de emergencia; la dueña | Rechazado; permitido |
| ¿Puede forzar horario? Recepción; gerente | No; sí |
| Encenderle a Rosa "Perdonar faltas" | Ya puede |
| Una cita confirmada vista por la dueña y por recepción | Con sus pasos y "reprogramar" |
| La misma cita vista por caja | Sin pasos y sin "reprogramar" |
| Caja intenta cambiar el paso | Rechazado |
| Caja abre la pantalla Caja | Puede operar; no puede agregar cajas |

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Entrar como Karina (caja) | Menú: Inicio, Agenda, Citas, Pacientes, Ventas, Pagos, Caja y Cupones |
| Pacientes | La lista con el ojo; sin "Nuevo", lápiz ni tacho |
| Página de un paciente | Carga sin errores; sin el lápiz |
| Citas | Sin "Nueva cita" |
| Abrir una cita | Solo la tarjeta "Cobrar"; sin botones de paso |
| Agenda | Sin "Nueva cita"; las citas no se arrastran; el espacio libre ya no invita a agendar |
| Cupones | Sin "Nuevo cupón", editar ni eliminar |
| Caja | Con "Abrir caja", sin "Nueva caja" |
| Ir a `/users` por la dirección | "Tu cuenta no tiene acceso a esta pantalla. Tu rol (Caja) no la incluye…" |
| Entrar como la dueña y recorrer Pacientes, Servicios, Cupones, Citas, Lista de espera, Controles, Bloqueos y Profesionales | Todos sus botones siguen ahí, sin errores |
| Página de Rosa | La rejilla tiene la columna "Especial"; en Agenda, "Forzar horario" apagado |
| Encenderle "Forzar horario" | Marca "Agregado"; pasa de 22 a 23 de 63 |
| Tocar "Agenda y citas" | Se lee qué permite "Forzar horario" |

- Se revisó en una captura la rejilla con la columna "Especial".
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** entrar como recepción, gerente y profesional tras estos cambios (sus permisos se probaron por la API); Lista de espera, Controles, Bloqueos, Servicios y Profesionales vistos por alguien sin permiso (se vieron Pacientes, Citas, Agenda, Cupones y Caja); la ficha en solo lectura; el panel de una venta sin permiso de cobrar; Configuración con "ver" y sin "cambiar"; "Perdonar faltas" y "Acceso de emergencia" desde sus botones; forzar un horario como gerente; tema oscuro y celular.

## 6. Para aplicar

No hay cambios de base en esta entrega. Sigue pendiente el reset de la entrega 1 (`init.sql`, `seed.sql`, `seed_demo.sql`).

Una empresa cuyo rol Gerente de sede ya existía antes de esta entrega no recibe sola los dos permisos especiales: se le encienden en la página del rol, o con "Volver a como nació". Al resetear la base de ejemplo no hace falta nada.
