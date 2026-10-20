# 2026-10-18 — Ventas, entrega 5: Paquetes

## Contexto

Un paquete son sesiones que el cliente paga por adelantado. Ejemplo: Juliana compra "5 controles pediátricos" a S/ 250 (sueltos serían S/ 300); cada vez que viene no paga, se le descuenta una sesión, y el paquete vale 180 días. Hasta hoy solo existían las tablas, sin pantallas.

Antes de programar se revisó el prototipo (pantalla Paquetes con "Paquetes de clientes" y "Catálogo", venta desde el carrito, consumo al cobrar, anulación) y se acordó el alcance en tres rondas. Lo decidido:

| Pregunta | Respuesta |
|---|---|
| ¿Dónde se configura cómo se usa? | En cada paquete del catálogo. Se copia al venderlo: cambiar el catálogo no toca lo ya vendido |
| ¿Cómo se agenda? | **Libre** (por defecto: el cliente reserva cada sesión cuando quiere) o **con fechas acordadas al venderlo** |
| Frecuencia de las fechas acordadas | Cada semana, 2 por semana, cada 15 días, cada mes o **Personalizado**: días de la semana, cada uno con su hora (opcional), cada 1 o 2 semanas |
| ¿Quién atiende? | Se elige al profesional (por defecto) o "el que esté libre" |
| Si cancela o no llega | Si avisa a tiempo, se reprograma; **si avisa tarde o no llega, la sesión se pierde** (por defecto). La otra opción: siempre se reprograma. "A tiempo" usa el plazo de cancelación de la empresa |
| Si pide su dinero | **Se devuelve lo no usado, cobrando lo usado a precio normal** (por defecto), lo no usado a precio del paquete, o no se devuelve |
| Si vence con sesiones | Se pierden, salvo que el administrador o el gerente le dé más días con un motivo |
| Si se agota | El servicio se cobra normal |
| ¿En qué sede vale? | En todas las sedes de la empresa |
| Reservar en el portal teniendo sesiones | Sin adelanto: la cita usa el paquete |
| Fecha sin espacio | Se muestran **todas las horas libres de ese día**; si el día está completo, los siguientes días con espacio ("no lo vamos a tener jugando al tino") |

Ella aprobó los valores por defecto "hasta que me digan lo contrario".

## 1. Pantalla Paquetes (`/packages`, grupo Fidelización)

El ítem "Paquetes" del menú ya existía y llevaba a una página vacía; ahora lleva a la pantalla nueva y sigue el permiso "Ver paquetes".

- **Paquetes de clientes:** paquete (nombre con enlace a su página, código, libre o con fechas), cliente (enlace a su página), barra de uso ("2 de 10 usadas", la parte agendada en celeste), vigencia ("Vence en 47 días", "Venció el 8 oct 2026"), precio con su moneda, estado (Vigente, Agotado, Vencido, Anulado). De a 6, con filtro de estado, filtros de fecha de compra y vencimiento, y orden.
- **Tarjetas que filtran:** Vigentes, Sesiones por usar, **Por atender (ya cobrado)** (el dinero de las sesiones que aún no se dan) y Vencen en 30 días.
- **Vender paquete** lleva al punto de venta, abierto en la pestaña Paquetes.
- **Catálogo:** paquete, qué incluye, cómo se agenda, precio, ahorro, vigencia, vendidos y estado. Lápiz para editar y papelera: si ya se vendió, se quita del catálogo y lo vendido sigue igual; si está en una venta por cobrar, no se elimina.

## 2. Panel "Nuevo paquete"

Panel lateral en cinco partes:

1. **Qué incluye:** nombre, descripción, servicios con su número de sesiones (Select con "+" para crear el servicio ahí mismo), precio y "Vale por (días)" (90 por defecto). Muestra en vivo "6 sesiones · por separado costarían S/ 420.00. Ahorro: S/ 60.00 (14 %)".
2. **Cómo se agenda:** Libre o Con fechas acordadas. Con fechas: frecuencia, días de la semana con su hora (interruptores y el campo de hora propio), "Cada semana / Cada 2 semanas" y quién atiende. Si las sesiones no caben en la vigencia avisa sin impedir: "14 sesiones con esta frecuencia necesitan unos 49 días y el paquete vale 40". Con fechas acordadas el paquete lleva un solo servicio.
3. **Si cancela o no llega.**
4. **Si quiere su dinero de vuelta**, cada opción con su ejemplo ("pagó S/ 450 por 10, usó 3: se devuelven S/ 270"), y un recordatorio sobre la ley de protección al consumidor.
5. **Texto que ve el cliente:** se arma solo con lo elegido y se puede escribir a mano. Ejemplo: "6 sesiones de Punción seca, válidas 40 días desde la compra. Las fechas se acuerdan al comprarlo (martes 10:00 a. m. y jueves, cada semana). Si no puedes venir, avisa con 12 h de anticipación para reprogramar; si no, la sesión se pierde. Puedes pedir la devolución de lo no usado mientras esté vigente: las sesiones usadas se cobran a su precio normal (Punción seca a S/ 70.00)."

Más el interruptor "Se vende".

## 3. Vender en el punto de venta

- Pestaña nueva **Paquetes** en el catálogo, de a 6: nombre, qué incluye, cómo se agenda, precio y ahorro.
- Un paquete exige cliente: "Elige al cliente: el paquete queda a su nombre."
- **Con fechas acordadas**, la línea del carrito muestra "Sin fechas todavía · Agendar sesiones" o "6 de 6 con fecha · Cambiar fechas".
- Al cobrarlo se emite la boleta como en cualquier venta, el paquete queda a nombre del cliente y **se crean las citas** ya confirmadas. Aviso: "Venta V-000001 cobrada. Paquete PQ-0006 (Punción seca x6): 6 sesiones agendadas."
- Si entre que se armó el carrito y se cobró alguien tomó uno de los horarios, el paquete se crea igual, esa sesión queda sin fecha y se avisa para agendarla desde el paquete. También funciona si se cobra con link de pago.

## 4. Panel "Agendar sesiones"

Se abre desde el carrito o desde la página del paquete ("Agendar sesiones", para las que quedan sin fecha).

1. Profesional (obligatorio si el paquete es "con la misma persona"), frecuencia (viene del paquete y se puede cambiar para este cliente), primera sesión y hora, con los campos propios de fecha y hora.
2. **"Ver las fechas"** arma la lista y revisa cada fecha con las mismas reglas de la agenda: horario del profesional, sus otras citas, pausas, bloqueos y vacaciones, feriados, sede cerrada, el vencimiento del paquete, otra cita del mismo cliente a esa hora y las otras fechas de la misma lista.
3. Cada fecha sale con ✓ y el profesional, o en ámbar con el motivo ("El cliente ya tiene la cita C-0196 a esa hora").
4. **"Cambiar"** abre las horas libres de ese día como botones ("10:00 a. m.", "11:00 a. m."…). Se puede elegir otro día con el calendario. Si el día está completo: "jueves 22 oct: Lic. Bruno Alarcón no tiene horas libres. Estos días sí tienen espacio:", con botones de los próximos días y cuántas horas tienen. Las horas en que el cliente ya tiene otra cita no se ofrecen.
5. No se puede guardar mientras haya una fecha en ámbar; una fecha se puede quitar y esa sesión queda para después.

## 5. Usar el paquete en una cita

- En el cobro de la cita (desde la cita o la página del cliente): "Noemí Delgado Vega tiene un paquete con sesiones de este servicio · Drenaje linfático · PQ-0004 · quedan 3 sin fecha de 5 · vence en 77 días · **Usar paquete**".
- La cita queda "Se paga con el paquete PQ-0004" y no se cobra. Con "cobrar antes de atender" encendido, cuenta como pagada. **"Quitar del paquete y cobrar aparte"** la devuelve al cobro normal.
- La tarjeta de la cita dice "Paquete PQ-0004 · Con paquete", y "o con su paquete PQ-0004" cuando todavía no lo usa.
- La sesión **se descuenta sola al dar la cita por atendida**. Si se cancela a tiempo, vuelve al paquete; si se cancela tarde o no llega, se pierde o vuelve, según el paquete. Si la sede la libera por no confirmarse, vuelve.
- Una cita que ya estaba "por cobrar" sola en una venta sin pagos: esa venta se anula al usar el paquete.
- Las citas con paquete no aparecen en "Por cobrar" del punto de venta.

## 6. Página del paquete (`/packages/[id]`)

- **Ficha:** cliente, precio, venta, compra, vigencia, sede donde se vendió y quién lo atiende.
- **Tarjetas:** Usadas, Con cita, Sin fecha, Por atender (ya cobrado).
- **Sesiones** por servicio con su barra; **Citas por atender** (se abren en su panel); **Sesiones usadas** (atendida, perdida por faltar, registrada a mano, con su cita y profesional); **Condiciones** con el texto del cliente; **Historial** (venta, citas agendadas, sesión liberada, más días, anulado, devolución). Las listas van de a 6.
- **Registrar uso sin cita:** con motivo obligatorio. Ejemplo: "sesión atendida en una campaña, sin cita". Las sesiones con cita no se pueden descontar así.
- **Dar más días:** días y motivo. Se suman al vencimiento; si ya venció, se cuentan desde hoy y el paquete vuelve a estar vigente.
- **Anular paquete:** pregunta qué pasó con el dinero. "Devolver lo que no usó (recomendado)", con el cálculo ("Pagó S/ 360.00. Usó 3 sesiones a su precio normal: S/ 210.00. Se devuelve el resto"), el monto editable y el medio; o "Sin devolución". Avisa qué citas pendientes se cancelan. La devolución pasa por Ventas, con su nota de crédito. Si la caja está cerrada, sugiere otro medio que no sea efectivo.
- Si la venta del paquete se devuelve por completo desde Ventas, el paquete se anula solo y sus citas pendientes se cancelan.

## 7. Página del cliente y portal

- **Página del cliente:** tarjeta "Paquetes" ("1 vigente: 3 sesiones por usar."), de a 6, con su barra de uso, vigencia y estado.
- **Portal → "Mis paquetes"** (aparece si el cliente tiene alguno): uso, vigencia y el texto de condiciones.
- **Al reservar:** "Con tu paquete Drenaje linfático: te quedan 2 sesiones por reservar" y "Esta cita usa tu paquete: no pagas nada ahora". **No se pide adelanto** y la cita queda con el paquete.
- **En la cita:** "Con tu paquete… Si no puedes venir, cancela a tiempo para no perder la sesión". Al cancelar: "la sesión vuelve a tu paquete" o "Faltan menos de 12 horas: la sesión de tu paquete se pierde".

## 8. Vacaciones y feriados

La advertencia de citas afectadas ya existía en Bloqueos y Feriados: al registrar unas vacaciones o un feriado se ve qué citas caen dentro. Ahora cada cita de paquete lleva la marca "Paquete PQ-0006" para reprogramarla hablando con el cliente. No se mueven solas.

## 9. Permisos

Pantalla nueva **Paquetes** en Usuarios y roles:

| Permiso | Qué permite | Nace en |
|---|---|---|
| Ver | Ver los paquetes de los clientes y el catálogo | Administrador, Gerente, Recepción, Caja |
| Crear | Crear paquetes en el catálogo | Administrador, Gerente |
| Editar | Cambiar el catálogo y dar más días a un paquete vendido | Administrador, Gerente |
| Eliminar | Anular un paquete vendido, devolver lo no usado y quitar paquetes del catálogo | Administrador, Gerente |
| **Registrar uso a mano** (especial) | Descontar una sesión sin cita (queda registrado quién y por qué) | Administrador, Gerente, Recepción |

Vender un paquete usa el permiso del carrito, usarlo en una cita el de cobrar, y agendar sesiones el de agendar citas.

## 10. Base de datos

- `package_plans` rehecha: moneda, vigencia, `booking_mode`, `frequency`, `custom_days`, `repeat_weeks`, `professional_mode`, `late_policy`, `refund_policy`, `terms`, estado y borrado lógico.
- `customer_packages` rehecha: código PQ-0001, nombre, precio, moneda, sede, venta, **`conditions`** (copia de las reglas al venderlo), profesional, vencimiento, motivo de anulación y monto devuelto.
- `customer_package_items`: `unit_price` (precio normal del servicio al comprar, para calcular la devolución).
- `package_usages`: `kind` (atendida, no asistió, canceló tarde, a mano) y `note`. Una cita descuenta una sola vez.
- Nueva `customer_package_events` (historial).
- `appointments.customer_package_id` (la cita usa el paquete) y `sale_items.details` (las fechas elegidas en el carrito).
- `seed_demo.sql`: 9 paquetes en 8 empresas y 21 vendidos, entre vigentes, agotados y vencidos. Las sesiones usadas se enlazan a citas atendidas reales cuando las hay. No trae paquetes con fechas ya agendadas: aparecen al vender uno.
- **Corrección del mismo día (a pedido de Geraldine):** los paquetes de ejemplo se veían "ya cobrados" en la tarjeta "Por atender", pero no aparecían en Ventas ni en Pagos porque se habían creado sin venta. Ahora cada uno trae su venta pagada el día de la compra (V-000001…), su pago (Yape, Plin, transferencia o tarjeta, con número de operación; efectivo no, porque necesitaría un turno de caja), su boleta simulada B001 y "Vendido en la venta V-000004" en su historial. Así "Anular" puede devolver dinero: en Fisioterapia Vital, PQ-0001 sugiere S/ 360 y PQ-0004 S/ 240. Los vencidos dicen "Pasó el plazo de 30 días para devolver esta venta", que es la regla de devoluciones de la empresa.

## 11. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Packages.php` | Nuevo. Condiciones y texto del cliente; vencer; saldo por servicio (usadas, con cita, sin fecha); usar el paquete en una cita y quitarlo; descontar al atender, faltar o cancelar tarde; uso a mano; más días; cálculo de la devolución; anular; crear los paquetes al pagarse la venta; armar las fechas por frecuencia; revisar fechas; horas libres de un día y próximos días con espacio; crear las citas. |
| `apps/api/app/Http/Controllers/Api/PackageController.php` | Nuevo. Lista, detalle, catálogo, uso a mano, más días, anular, agendar, revisar fechas y horas libres. |
| `SaleController.php` | El paquete en el cobro de la cita; usar y quitar el paquete. |
| `PosController.php`, `Cart.php` | Paquetes en el catálogo y en el carrito, con sus fechas; revisión al guardar; las citas con paquete fuera de "Por cobrar". |
| `Sales.php`, `PaymentLinks.php` | Crear el paquete al pagarse la venta, en la sede o por link; una cita con paquete cuenta como pagada. |
| `Refunds.php` | Anular el paquete si su venta se devuelve por completo. |
| `AppointmentController.php`, `Confirmations.php` | Reaccionar a la cita atendida, cancelada, no asistida o liberada. |
| `PortalController.php` | "Mis paquetes", el paquete en el catálogo y en cada cita, reservar sin adelanto. |
| `AvailabilityBlockController.php`, `HolidayController.php` | El código del paquete en las citas afectadas. |
| `Access.php`, `routes/api.php`, `Appointment.php` | Permisos, rutas con `module:packages` y la columna nueva. |
| `apps/web/src/features/packages/*` | Nuevo. Pantalla, panel del catálogo, página del paquete, panel de agendar, tarjeta del cliente. |
| `app/(dashboard)/packages/*` | Nuevo. Páginas. |
| `PointOfSale.tsx`, `pos.ts` | Pestaña Paquetes, línea del carrito con sus fechas, aviso al cobrar. |
| `ChargePanel.tsx`, `sales.ts`, `AppointmentDetail.tsx` | Usar o quitar el paquete; tarjeta de la cita. |
| `CustomerDetail.tsx` | Tarjeta Paquetes. |
| `Portal.tsx`, `PortalPackages.tsx`, `BookingFlow.tsx`, `PortalAppointmentCard.tsx`, `portal.ts` | Portal. |
| `blocks.ts`, `BlocksTable.tsx`, `FormBlock.tsx`, `HolidaysTable.tsx` | Marca "Paquete" en las citas afectadas. |
| `Sidebar.tsx`, `ScreenGate.tsx` | Menú y aviso de acceso. |
| `init.sql`, `seed_demo.sql` | Esquema y datos de ejemplo. |

Código sin comentarios, como el resto del proyecto.

## 12. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API** (Fisioterapia Vital):

| Caso | Resultado |
|---|---|
| Crear "6 sesiones de Punción seca", martes 10:00 y jueves 11:00 | Ahorro S/ 60 (14 %) y el texto del cliente bien armado |
| Paquete con fechas y dos servicios | Rechazado con su explicación |
| Revisar fechas | 6 fechas: martes 20, jueves 22, martes 27… con profesionales libres |
| Vender sin cliente | "Elige al cliente: el paquete queda a su nombre." |
| Cobrar con tarjeta | PQ-0006 creado y 6 citas confirmadas (C-0493 a C-0498) |
| Cobrar una cita del paquete | "Esta cita se paga con el paquete PQ-0006: no se cobra aparte." |
| Cancelar a tiempo | La sesión vuelve: 0 usadas, 5 con cita, 1 sin fecha |
| Atender | 1 usada ("Atendida C-0493"); "cobrar antes de atender" no la frenó |
| Cancelar con 3 horas de anticipación (plazo de 12 h) | "Canceló tarde: la sesión se perdió" |
| Uso a mano, más días | Descontado / vence 30 días después |
| Devolución sugerida tras usar 3 | S/ 150 = 360 − 3 × 70; al anular se cancelaron C-0496, C-0497 y C-0498 y la venta quedó "Devuelta en parte" |
| Devolución total | Un solo historial: venta, anulado, devolución. Venta "Devuelta" |
| Usar un paquete libre en una cita y quitarlo | Pagada con paquete / vuelve a cobrarse |
| Portal | Servicio con "PQ-0001, 8 libres" y sin adelanto; reserva C-0500 con el paquete; "Mis paquetes" con el saldo |

**En pantalla, con clics reales** (navegador integrado sobre la base temporal): la pantalla y sus tarjetas que filtran (Vigentes: 5 → 2 → 5); el panel "Nuevo paquete" completo (servicio, sesiones, precio, con fechas, Personalizado, interruptores de martes y jueves, hora del martes con el reloj propio, aviso con 14 sesiones que desaparece con 6) y su edición; vender desde el punto de venta (aviso sin cliente, elegir clienta, "Agendar sesiones", profesional, "Ver las fechas", "Cambiar" con las horas libres, el cruce con otra cita de la clienta marcado en ámbar, guardar, cobrar con tarjeta); la página del paquete; "Dar más días"; "Anular" con devolución por tarjeta; la tarjeta de la clienta; "Usar paquete" y "Quitar del paquete" en el cobro de una cita; el portal ("Mis paquetes", la nota en la cita y en la reserva).

**Arreglado durante la prueba:**
- Las horas libres ofrecían horas en que la clienta ya tenía otra cita: ahora no se ofrecen.
- La línea del carrito decía "Paquete ·" antes de elegir al cliente.
- El medio de devolución sugerido era efectivo con la caja cerrada.
- Al devolver todo, el paquete se anulaba dos veces en el historial.
- El portal decía "3 por usar" y "te quedan 2" a la vez: ahora dice "por reservar" y cuántas ya tienen cita.

**No se probó en pantalla:** el día completo con "Estos días sí tienen espacio" (se probó por la API), "Registrar uso sin cita" con clics, un paquete con varios servicios en el cobro, Recepción y Caja, tema oscuro y celular.

## 13. Para aplicar

Columnas y tablas nuevas: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`.
