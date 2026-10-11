# 2026-10-11 — El cliente se anota solo en la lista de espera y ve los horarios que se liberan

## Contexto

El portal solo muestra horarios libres. Si ninguno le servía al cliente, la página no podía ayudarlo: la lista de espera solo la llenaba el personal. Además, cuando se liberaba un horario nadie avisaba a quienes esperaban.

Pedido: que el cliente pueda anotarse él mismo y que se le avise cuando haya un horario para él.

Sin cambios de base: se usa la tabla `waitlist_entries`, que ya existía.

## 1. Anotarse desde el portal

En "Reservar", en el paso de elegir día y hora, aparece siempre un recuadro: **"¿Ningún horario te sirve? Anótate en la lista de espera"**. Al abrirlo pregunta dos cosas:

| Pregunta | Opciones |
|---|---|
| ¿A qué hora te sirve? | A cualquier hora · Por la mañana (hasta la 1 p. m.) · Por la tarde (desde la 1 p. m.) |
| ¿Hasta cuándo te sirve? | Sin fecha límite · Solo esta semana · Las próximas 2 semanas · El próximo mes |

El servicio, la sede, el profesional (o "cualquiera") y la mascota ya vienen de los pasos anteriores. Al anotarse va a "Mis citas", donde ve su registro.

- No puede anotarse dos veces para el mismo servicio en la misma sede.
- El registro aparece también en la **Lista de espera del personal**, con la nota "Se anotó desde el portal", y se maneja ahí como cualquier otro.

## 2. Cómo se le avisa

En "Mis citas", arriba, hay una sección **"En lista de espera"** con cada cosa que espera. El portal revisa **en vivo** qué horarios libres le sirven:

- Si todavía no hay ninguno: "Todavía no hay un horario libre que te sirva. Cuando se libere uno, lo verás aquí."
- Si hay: la tarjeta se pone en verde y muestra hasta tres días con sus horas. **Un clic en una hora la reserva** (tras confirmar) y la persona sale de la lista.
- En el **Inicio** aparece además un aviso: "Se liberó un horario que estabas esperando", que lleva a "Mis citas".
- Puede **salir de la lista** cuando quiera.

No se le ofrecen horas en las que él mismo ya tiene otra cita.

Como los horarios se calculan al abrir el portal, nunca se le ofrece una hora que ya tomó otra persona; si alguien se le adelanta en el instante, la reserva lo dice y la lista se actualiza.

**Límite importante:** el aviso es dentro del portal. No se envía correo ni WhatsApp, porque el sistema aún no tiene envío de mensajes; la persona se entera cuando entra a su portal.

## 3. Aviso a la empresa

Cuando una cita futura se cancela, no asiste o se libera por falta de confirmación, y ese horario le sirve a alguien de la lista, los administradores reciben en la campana: **"Se liberó un horario"**, con el servicio, la fecha, el profesional y cuántas personas de la lista pueden tomarlo. Lleva a la Lista de espera. Antes ese dato aparecía un instante al cancelar y se perdía.

## 4. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Http/Controllers/Api/PortalController.php`, `routes/api.php` | Ver lo que espera (con sus horarios), anotarse y salir de la lista; reservar desde la lista. |
| `apps/api/app/Support/Waitlist.php`, `Notifier.php` | Aviso a la administración al liberarse un horario que le sirve a alguien. |
| `apps/web/src/features/portal/components/PortalWaitlist.tsx` | Nuevo. La sección "En lista de espera". |
| `apps/web/src/features/portal/components/BookingFlow.tsx` | El recuadro para anotarse. |
| `apps/web/src/features/portal/components/Portal.tsx`, `portal.ts` | Carga de la lista, aviso en el inicio y tipos. |

## 5. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada).

**Por la API:**

| Caso | Resultado |
|---|---|
| Una clienta se anota para un servicio, sede y profesional | 201; queda "En espera", sin autor del personal |
| Se anota otra vez a lo mismo | 409, con su explicación |
| El personal abre su Lista de espera | La ve |
| El personal cancela la cita de otra persona en un horario que a ella le sirve | La dueña recibe "Se liberó un horario … Una persona de la lista de espera puede tomarlo" |
| La clienta consulta su lista | Trae días con horarios, y entre ellos el que se acaba de liberar |
| Reserva ese horario desde la lista | Cita creada; su registro pasa a "Con cita" y deja de figurar como espera |
| Se anota de nuevo "por la mañana" y sin fecha límite | Guardado hasta las 13:00, sin última fecha y con cualquier profesional |
| Otro cliente intenta sacarla de la lista | Rechazado |
| Ella sale de la lista | Queda "Retirado" |
| El personal intenta usar las rutas del portal | 403 |
| Horas ofrecidas un día en que ella ya tiene dos citas | No incluyen las que chocan con esas citas |

**En el navegador, con clics reales, como clienta:**

| Prueba | Resultado |
|---|---|
| Reservar: elegir servicio, sede y médico, y llegar al paso de día y hora | Aparece el recuadro de la lista de espera |
| Abrirlo | Dos listas con las opciones descritas |
| Elegir "Por la tarde" y "Anotarme" | Pasa a "Mis citas" con el aviso de que quedó anotada |
| La sección "En lista de espera" | Su registro, en verde, con tres días de horarios desde las 15:00 |
| Inicio | Muestra "Se liberó un horario que estabas esperando"; el clic lleva a "Mis citas" |
| Clic en una hora en la que ya tenía otra cita | La reserva lo rechaza con su explicación (luego se corrigió para no ofrecer esas horas) |
| Clic en otra hora y "Sí, reservar" | "Listo: tu cita quedó reservada y saliste de la lista de espera"; Próximas pasa de 17 a 18 y la sección desaparece |

- Se revisó en una captura la sección "En lista de espera" con sus horarios.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** "Salir de la lista" con su confirmación (se probó por la API), el aviso "Se liberó un horario" en la campana del personal (se comprobó que se crea), un rubro con mascotas, una empresa sin elección de profesional, y el celular.

## 6. Para aplicar

No requiere resetear la base por este cambio. Basta recargar la página.
