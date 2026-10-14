# 2026-10-13 — Cobrar antes de atender, y qué pasa con quien reserva en línea y no asiste

## Contexto

Lo habitual en una clínica es pagar la cita completa antes de la atención: a quien saca cita le piden llegar un rato antes para pagar, y le advierten qué pasa si no viene. El sistema dejaba atender sin cobrar y no decía nada a quien reservaba desde el portal.

Decidido:

- Por defecto **se cobra antes de atender**. Cada empresa puede apagarlo.
- La lista de **citas atendidas sin cobrar** solo existe si esa regla está apagada.
- A quien reserva en línea se le pide **llegar antes para pagar**.
- La empresa elige qué pasa con quien reserva en línea y no asiste: nada, una nota, o nota y dejar de reservar en línea tras cierto número de faltas, por un tiempo o sin fecha de fin.

## 1. Cobrar antes de atender

Regla nueva en Configuración → "Ventas y caja": **"Cobrar antes de atender"**, encendida por defecto.

- Con la regla encendida, una cita no pasa a "En atención" ni se da por atendida si no está pagada. El panel dice: "Esta cita aún no está pagada. Cóbrala antes de iniciar la atención." Registrar la llegada sí se puede: es justo cuando el cliente pasa por recepción a pagar.
- Las citas con precio cero no necesitan pago.
- Solo rige en empresas con punto de venta y caja: sin ese módulo no hay cómo cobrar.
- Con la regla apagada se atiende primero y se cobra después, y en **Ventas** aparece la pestaña **"Atendidas sin cobrar"**: cada cita atendida que nadie cobró, con su cliente, servicio y monto. Un clic abre el cobro.

Junto a la regla: **"Pedir al cliente que llegue __ minutos antes de su hora, para pagar"** (15 por defecto; con 0 solo se le avisa que se paga antes).

## 2. Lo que ve quien reserva en línea

- **Al reservar**, en el resumen final: "El pago se hace en la sede, antes de la atención. Llega 15 minutos antes de tu hora para pagar en recepción."
- **En cada cita próxima que aún no ha pagado:** "Llega 15 minutos antes de tu hora para pagar en recepción: la atención empieza con la cita pagada."
- **Si la cita es por videollamada**, no se le pide llegar a recepción: "Esta atención es por videollamada y se paga antes de empezar: comunícate con la sede para coordinar el pago." (El pago en línea llegará con la pasarela.)

## 3. Si reserva en línea y no asiste

Sección nueva en Configuración (solo con reserva en línea): **"Si reserva en línea y no asiste"**.

| Opción | Qué pasa |
|---|---|
| No pasa nada | La cita queda como "No asistió" y nada más |
| **Dejar una nota** (por defecto) | La ficha del cliente y la lista de clientes muestran cuántas veces faltó a una cita reservada en línea |
| Nota y dejar de reservar en línea | Además, al llegar a cierto número de faltas ya no puede reservar por el portal, durante los días que la empresa indique |

Con la tercera opción se eligen dos números: **a cuántas faltas** (2 por defecto) y **durante cuántos días** (30 por defecto; con **0 no vuelve a reservar en línea hasta que la empresa se lo permita**).

Cómo funciona:

- Cuenta como falta una cita **reservada desde el portal** que terminó en "No asistió". Las citas que agenda el personal no cuentan.
- Los días se cuentan desde la última falta. Si después vuelve a faltar, el plazo empieza de nuevo.
- El veto es **solo para reservar en línea**: el personal puede seguir agendándole.
- Nada se guarda como "vetado": se calcula de sus citas. Si la empresa cambia la opción, rige al instante para todos.
- **Perdonar:** en la ficha del cliente, el administrador tiene el botón "Perdonar inasistencias" (o "Perdonar y permitirle reservar"). Las faltas anteriores dejan de contar.

Dónde se ve:

- **Lista de clientes:** bajo el nombre, "1 inasistencia en línea" (ámbar) o "Sin reserva en línea" (rojo); al pasar el cursor, el detalle.
- **Ficha del cliente:** un recuadro con cuántas faltas, hasta cuándo no puede reservar y la fecha de la última.
- **Portal del cliente:** si aún puede reservar, un aviso: "Tienes 2 inasistencias… Al llegar a 3 ya no podrás reservar por aquí". Si ya no puede: "Por ahora no puedes reservar en línea… hasta el 09/11/2026. Comunícate con la sede"; desaparecen la pestaña y el botón "Reservar". Sus citas ya agendadas siguen ahí.
- **Al reservar:** se le advierte antes de confirmar ("Si no asistes a 2 citas reservadas por aquí, no podrás volver a reservar en línea durante 30 días").

## 4. Base de datos

- `init.sql`: columna `customers.booking_strikes_reset_at` (desde cuándo cuentan las faltas, para poder perdonar).
- `seed_demo.sql`: una parte de las inasistencias de ejemplo pasa a ser de citas reservadas en línea, para que haya clientes con nota.
- Las reglas van en `tenant_settings.rules`, que ya existía.

## 5. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/OnlineBooking.php` | Nuevo. Faltas de cada cliente y si puede reservar en línea. |
| `apps/api/app/Support/Sales.php`, `AppointmentFlow.php` | Reglas nuevas y si una cita está pagada. |
| `apps/api/app/Http/Controllers/Api/AppointmentController.php` | No pasa a atención sin pago. |
| `apps/api/app/Http/Controllers/Api/PortalController.php` | Avisos de pago, estado de faltas y bloqueo de la reserva. |
| `apps/api/app/Http/Controllers/Api/CustomerController.php`, `routes/api.php` | Faltas en la lista y en la ficha; perdonar. |
| `apps/api/app/Http/Controllers/Api/SalesController.php`, `TenantSettingController.php` | Atendidas sin cobrar; guardar las reglas. |
| `apps/api/infrastructure/docker/postgres/init.sql`, `seed_demo.sql` | Lo de la sección 4. |
| `apps/web/src/features/settings/components/TenantSettings.tsx` | Interruptor, minutos y la sección de inasistencias. |
| `apps/web/src/features/portal/` | Avisos en la cita, al reservar y en el inicio. |
| `apps/web/src/features/customers/` | Nota en la lista y en la ficha, con el botón de perdonar. |
| `apps/web/src/features/sales/components/SalesTable.tsx`, `sales.ts` | Pestaña "Atendidas sin cobrar". |

## 6. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada); quedan 137 inasistencias de ejemplo como reservadas en línea.

**Por la API:**

| Caso | Resultado |
|---|---|
| Valores por defecto | Cobrar antes: sí; llegar 15 min antes; pagos por partes: no; inasistencias: nota, 2 faltas, 30 días |
| Iniciar atención sin pagar | Rechazado: "Esta cita aún no está pagada…" |
| Registrar la llegada sin pagar | Permitido |
| Cobrarla e iniciar la atención | Pasa a "En atención" |
| Ventas con la regla encendida | No trae la lista de atendidas sin cobrar |
| Apagar la regla e iniciar atención sin pagar | Permitido; Ventas trae 297 citas atendidas sin cobrar |
| Portal con "solo nota", clienta con 2 faltas | 2 faltas, sin bloqueo |
| Su próxima cita | Marcada para pagar al llegar |
| Ficha y lista de clientes | La ficha trae sus 2 faltas; 10 clientes de la empresa tienen nota |
| Veto a la 3.ª falta (tiene 2) | No bloqueada |
| Veto a la 2.ª por 30 días | Bloqueada hasta el 09/11/2026, con su mensaje |
| Reservar estando bloqueada | 403 |
| Veto de 2 días (faltó hace 3) | Ya no está bloqueada |
| Veto sin fecha de fin | Bloqueada: "ya no puedes reservar por aquí" |
| Un profesional intenta perdonar | 403 |
| La dueña perdona | Sin faltas; la clienta reserva de nuevo |
| "No pasa nada" | El portal no muestra nada |
| Reglas inválidas | Rechazadas |

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Configuración → "Ventas y caja" | "Cobrar antes de atender" encendido, 15 minutos; "pagos por partes" apagado |
| Apagar "Cobrar antes de atender" | El campo de minutos desaparece; al encenderlo vuelve |
| "Si reserva en línea y no asiste" | Marcada "Dejar una nota"; sin la fila del veto |
| Elegir "Nota y dejar de reservar en línea" | Aparecen 2 faltas y 30 días |
| Escribir 0 días | "Con 0 días no vuelve a reservar en línea hasta que tú se lo permitas desde su ficha." |
| Guardar | "Configuración guardada."; la opción se conserva |
| Ventas | Sin la pestaña "Atendidas sin cobrar" |
| Cita confirmada sin pagar → "Iniciar atención" | Sigue en "Confirmada" con el aviso de pago |
| Cobrarla con Yape (el monto no se puede editar) y volver a "Iniciar atención" | Pasa a "En atención" |
| Lista de clientes | Etiquetas "Sin reserva en línea" y "1 inasistencia en línea", con su detalle al pasar el cursor |
| Ficha de la clienta de prueba | "2 inasistencias a citas reservadas en línea · No puede reservar en línea hasta el 9 nov 2026 · El personal sí puede agendarle", con el botón "Perdonar y permitirle reservar" |
| Apagar la regla → Ventas | Pestaña "Atendidas sin cobrar 296", de a 6; un clic en "Cobrar C-0531" abre su cobro con S/ 20.00 |
| Portal de la clienta, bloqueada | Aviso rojo con la fecha; sin la pestaña ni el botón "Reservar" |
| Su próxima cita (teleconsulta) | "Esta atención es por videollamada y se paga antes de empezar…" |
| Con el veto a la 3.ª falta | Aviso ámbar: "Tienes 2 inasistencias… Al llegar a 3 ya no podrás reservar por aquí" |
| Reservar hasta el resumen | "El pago se hace en la sede… Llega 15 minutos antes…" y "Si no asistes a 3 citas reservadas por aquí, no podrás volver a reservar en línea durante 30 días." |

- Se revisó en una captura el inicio del portal con el aviso de bloqueo.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salió que la teleconsulta pedía "llegar antes para pagar en recepción"; se corrigió y se volvió a comprobar.
- **No se probó en pantalla:** el botón "Perdonar" (se probó por la API), el aviso de pago de una cita presencial en la tarjeta del portal (se vio el de teleconsulta y el del resumen al reservar), "Dar por atendida" sin pagar (se probó "Iniciar atención"), la opción "No pasa nada", capturas de la configuración y de la ficha, el tema oscuro y el celular.

## 7. Para aplicar

Hay una columna nueva (y las tablas de la entrega 2 de Ventas): resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`. Mientras no se resetee, la regla de cobrar antes de atender no se aplica (el cobro mismo pide actualizar la base) y "Perdonar" tampoco funciona; las notas de inasistencias sí se ven.

**Ojo al probar:** con "Cobrar antes de atender" encendido por defecto, en las empresas con punto de venta ya no se puede iniciar una atención sin cobrarla antes.

## 8. Ajuste posterior: salón y barbería cobran al final

Los rubros **salón** y **barbería** nacen con "Cobrar antes de atender" apagado (`seed.sql`, reglas por defecto del rubro): ahí lo normal es cobrar al terminar. Cada empresa lo puede encender. Comprobado cargando los tres archivos en una base temporal (ya eliminada): Salón Bella Aura y Barbería Don Filo quedan con la regla apagada; las otras nueve empresas, encendida. No se recorrió en pantalla.

Una empresa de esos rubros creada antes de este cambio conserva su copia del rubro: recibe el valor al recargar el seed o cuando la plataforma aplique el rubro.
