# 2026-10-14 — Ventas, entrega 3: punto de venta con carrito, productos y "Agendar para ahora"

## Contexto

Hasta hoy solo se cobraba una cita a la vez, desde la propia cita. Faltaba vender sin cita, cobrar varias cosas juntas y dejar una venta por cobrar. Antes de programar se revisó el prototipo (pantalla "Punto de venta": catálogo en pestañas a la izquierda y carrito a la derecha) y se acordó el alcance.

Decidido:

| Pregunta | Respuesta |
|---|---|
| ¿Se venden productos? | Sí, con una lista simple (nombre, código, precio, categoría, activo). El stock llega con Inventario |
| ¿Descuento por línea? | No. Solo el cupón, como ya funcionaba |
| ¿Se puede cambiar una venta dejada por cobrar? | Sí, mientras no tenga ningún pago |
| ¿Qué citas aparecen en "Por cobrar"? | Las de hoy sin pagar (no canceladas ni "no asistió") y las atendidas sin cobrar de los últimos 3 días |
| ¿Un servicio vendido sin cita crea una cita? | No. Para quien llega sin reserva y lo van a atender está **"Agendar para ahora"**: queda en la agenda y pasa al carrito |
| "Agendar para ahora" | Muestra si cada profesional está libre en este momento y sus próximos horarios libres de hoy, para elegir |

Quedan fuera, como se decidió antes: descuento global, propina y canje de puntos. Paquetes, membresías y gift cards esperan a sus módulos.

## 1. Pantalla "Punto de venta" (`/pos`, grupo Ventas)

**A la izquierda, el catálogo** en tres pestañas, con buscador y de a 6 con paginación:

- **Por cobrar:** primero las ventas que alguien dejó por cobrar (en ámbar, con "Abrir") y luego las citas de hoy sin pagar y las atendidas sin cobrar de los últimos 3 días (con "Agregar"). Ejemplo: "Hoy 08:00 · Pendiente · Hernán Córdova Campos · Control Pediátrico · Dra. María Gómez · S/ 60.00". Los nombres del cliente y del profesional llevan a su página.
- **Servicios:** para vender un servicio que no ocupa la agenda (un certificado médico, una copia de resultados). Un aviso explica que si alguien va a atender al cliente se use "Agendar para ahora".
- **Productos:** los que se venden al público.

**A la derecha, el carrito:**

- Cliente opcional, con el buscador y el "+" de siempre (el "+" solo aparece a quien puede registrar clientes). Si el carrito tiene una cita, el cliente es el de la cita y no se cambia. Sin cliente, la boleta sale a nombre de "Cliente varios".
- Cada línea con su precio. Servicios y productos tienen cantidad (− y +). Un servicio deja elegir "¿Quién atendió?" (opcional). Las citas muestran su código, hora y profesional.
- Cupón para toda la venta.
- Totales: ítems, cupón, operación gravada, IGV y total, en la moneda de la empresa.
- **"Dejar por cobrar"** guarda la venta pendiente. **"Cobrar"** la guarda y abre el mismo panel de cobro que ya se usaba (medio de pago, vuelto, boleta o factura simulada).
- Al abrir una venta por cobrar, el carrito dice "Venta V-000001 · por cobrar"; se cambia y se guarda con "Guardar cambios", o se cobra.
- Si se cierra el cobro sin pagar, la venta queda por cobrar y el carrito sigue abierto con ella.
- **Un cliente por venta:** si se agrega una cita de otra persona, pregunta "El carrito es de Juliana Díaz. Una venta es de un solo cliente: ¿vaciarlo para cobrarle a Ximena Aguirre Medina?".
- Con varias sedes, se elige la sede arriba (solo con el carrito vacío). Quien trabaja en una sede ve solo la suya.
- El carrito se conserva si se sale a otra pantalla y se vuelve (en esa pestaña del navegador).

## 2. "Agendar para ahora"

Botón arriba del punto de venta (y enlace en el aviso de Servicios). Abre un panel lateral:

1. Cliente (el del carrito, si ya hay uno).
2. Servicio.
3. Cada profesional que trabaja en la sede y hace ese servicio, con "Libre ahora" o el motivo ("Ya tiene una cita en ese intervalo (C-0559 10:03–10:18)", "No atiende a esa hora (su horario es 09:00–13:00)") y sus próximos 6 horarios libres de hoy. Se elige "Ahora (10:03)" o una hora.
4. "Agendar y pasar al carrito": crea la cita con las mismas reglas de la agenda (no permite dos citas a la vez) y la agrega al carrito.

Si la cita es para ahora, queda como "Llegó" (o "Confirmada" si la empresa no usa el paso de llegada), con la nota "Llegó sin cita: se agendó desde el punto de venta". Así el profesional aparece ocupado en la agenda, la atención cuenta en los tiempos y se puede llenar su ficha.

## 3. Pantalla "Productos" (`/products`, grupo Ventas)

Tabla de a 6 con filtros (para qué es, categoría), orden, tarjetas (activos, unidades vendidas, apagados) y panel lateral:

- Nombre, precio, código (si se deja vacío se pone solo: P-0001), categoría con "+" para crearla ahí mismo, y activo.
- "¿Para qué es?": **Se vende al público** (ej. un shampoo), **Solo se usa en las atenciones** (ej. guantes: no aparece en el punto de venta) o **Se vende y se usa**.
- Un aviso dice que el stock llegará con Inventario.
- Eliminar un producto que ya se vendió lo quita de la lista y lo conserva en sus ventas.

## 4. Ventas

- Una venta puede tener varias líneas y varias citas. La lista muestra todas sus citas y las cantidades ("Control Pediátrico, 2 × Termómetro digital").
- En el panel de una venta por cobrar aparecen **"Cobrar"** y **"Cambiar en el punto de venta"**.
- Una cita cobrada dentro de una venta del carrito cuenta como pagada: con "cobrar antes de atender" ya se puede atender. Abrir el cobro desde la cita muestra la venta completa.
- Anular una venta por cobrar devuelve sus citas a "Por cobrar".
- Los mensajes de cupones, pagos y devoluciones que tenían "S/" fijo usan ahora la moneda de la venta.

## 5. Permisos

Dos pantallas nuevas en Usuarios y roles:

| Pantalla | Permisos | Al nacer los tienen |
|---|---|---|
| Punto de venta | Ver (abrir y ver lo que falta cobrar), Crear (armar ventas y dejarlas por cobrar), Editar (cambiar una venta por cobrar sin pagos) | Administrador, Gerente de sede, Recepción, Caja |
| Productos | Ver, Crear, Editar, Eliminar | Administrador y Gerente: todo. Recepción y Caja: ver |

El profesional no ve el punto de venta; sigue cobrando sus citas desde la cita. Cobrar sigue pidiendo "Cobrar y emitir el comprobante" (Ventas y pagos). "Agendar para ahora" pide "Agendar citas": Caja no lo tiene de fábrica, y el aviso de Servicios le dice que pida a recepción que lo agende.

## 6. Base de datos

| Archivo | Cambio |
|---|---|
| `init.sql` | `sale_items.position` (orden de las líneas) e índice por cita en `sale_items` |
| `seed_demo.sql` | Productos de venta al público para las once empresas (2 a 4 cada una, con precios reales: un shampoo en Bella Aura, cera para peinar en Don Filo, alimento para perro en Huellitas) |

## 7. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Cart.php` | Nuevo. Valida y calcula el carrito (citas, servicios, productos, cupón, un solo cliente, una cita en una sola venta, moneda), guarda o cambia la venta, la carga en el carrito, y arma "Por cobrar". |
| `apps/api/app/Http/Controllers/Api/PosController.php` | Nuevo. `/pos`, `/pos/preview`, `/pos/sales`, `/pos/walk-in`, y el cobro de cualquier venta: `/sales/{id}/charge`, `/pay`, `/invoice`. |
| `apps/api/app/Http/Controllers/Api/ProductController.php` | Nuevo. `/products` y `/product-categories`. |
| `Sales.php`, `SalesController.php`, `Currency.php` | La cita se busca por las líneas de la venta (una venta puede tener varias citas). |
| `SaleController.php` | El contexto de cobro se comparte entre la cita y la venta. |
| `Access.php`, `routes/api.php` | Permisos y rutas nuevas. |
| `Coupons.php`, `Refunds.php` | Mensajes con la moneda de la venta. |
| `apps/web/src/features/pos/*` | Nuevo. `PointOfSale`, `WalkInPanel` y tipos. |
| `apps/web/src/features/products/*` | Nuevo. `ProductsTable`, `FormProduct`. |
| `ChargePanel.tsx` | Cobra una cita o una venta (`saleId`). |
| `SaleDetailPanel.tsx`, `sales.ts` | "Cobrar" para toda venta por cobrar, "Cambiar en el punto de venta", varias citas. |
| `CustomerPicker.tsx` | Puede ser opcional o quedar fijo; el "+" solo con permiso de registrar clientes. |
| `Sidebar.tsx`, `ScreenGate.tsx` | "Punto de venta" y "Productos" en el menú y en el aviso de acceso. |

## 8. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API** (Centro Médico):

| Caso | Resultado |
|---|---|
| Abrir el punto de venta | 3 sedes, 22 citas por cobrar, 11 servicios, 4 productos de venta |
| 2 termómetros + un servicio, sin cliente | Total S/ 100.00 con IGV incluido S/ 15.25 |
| Con BIENVENIDA10 / carrito vacío | Descuenta S/ 5.00 / "Agrega al menos algo al carrito." |
| Dejar por cobrar una cita + un termómetro | V-000001 por cobrar, cliente tomado de la cita; la cita sale de "Por cobrar" y entra la venta |
| La misma cita en otra venta | "La cita C-0402 ya está en la venta V-000001, que quedó por cobrar…" |
| Cita de otro cliente | "Una venta es de un solo cliente: la cita C-0043 es de Ximena Aguirre Medina…" |
| Cambiar la venta con cupón y luego quitarlo | S/ 121.50 con el cupón usado 1 vez; al quitarlo vuelve a 0 usos |
| Atender la cita sin pagar / pagada | Bloqueado: "Esta cita aún no está pagada…" / "En atención" |
| Pago parcial con la regla apagada | "…cobra el total de S/ 135.00." |
| Cobrar todo en efectivo con S/ 200 | Pagada, vuelto S/ 65.00, boleta B001-00000001 |
| Cambiar una venta pagada | "Esta venta ya tiene pagos: no se cambia…" |
| Anular una venta por cobrar | Su cita vuelve a "Por cobrar" |
| Agendar para ahora (simulando las 10:02) | Libres: Dr. Pérez y Dra. Gómez; la Dra. Núñez "no atiende a esa hora" con sus horarios de las 12:30. Cita C-0559 a las 10:03 como "Llegó" y en "Por cobrar"; otra a la misma hora con la misma persona se rechaza |
| A una hora que ya pasó | "Esa hora ya pasó. Elige otra." |
| Recepción / Caja / Profesional / Gerente | Entra en su sede / entra sin "Agendar para ahora" / sin acceso / entra en su sede y no puede agendar en otra |
| Productos | Código automático P-0001; código repetido rechazado; categoría nueva; uno vendido se conserva al eliminarlo |

**En el navegador, con clics reales** (dueña y Caja):

| Prueba | Resultado |
|---|---|
| Abrir el punto de venta | Las citas de hoy primero ("Hoy 08:00…") |
| Agregar una cita | Línea "Control Pediátrico · Cita C-0402 · 08:00 · Dra. María Gómez"; cliente fijo con "Es el cliente de la cita"; el botón pasa a "En el carrito" |
| Agregar dos veces un termómetro y "+" | Cantidad 3; total S/ 135.00 |
| Cupón BIENVENIDA10 | − S/ 13.50; total S/ 121.50 |
| Dejar por cobrar | "La venta V-000001 quedó por cobrar…"; aparece arriba en "Por cobrar" |
| Abrir, "−" un termómetro, agregar Certificado médico y elegir quién atendió | "Venta V-000001 · por cobrar"; total S/ 144.00 |
| Cobrar con Yape | Panel "Cobro V-000001" con las 3 líneas y el cupón; boleta B001-00000001; al cerrar: "Venta V-000001 cobrada." y carrito vacío |
| Agendar para ahora (01:00) | Los tres "Ahora no" con su horario y horas libres; elegir 15:00 → "Cita C-0558 agendada para las 15:00 con Dr. Carlos Pérez… Ya está en el carrito." |
| Agregar una cita de otro cliente | Pregunta si vaciar el carrito; al aceptar queda la cita de Ximena |
| Ventas → venta por cobrar | Botones "Cobrar S/ 50.00", "Cambiar en el punto de venta", "Anular venta"; el segundo abre la venta en el carrito |
| Productos → Nuevo producto con categoría "+" | "Jarabe para la tos 120 ml · P-0001 · Farmacia · Se vende y se usa" |
| Entrar como Caja | Menú con Punto de venta y Productos; sin "Agendar para ahora" ni "+" de cliente |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salieron dos cosas que se corrigieron: las citas de días anteriores salían antes que las de hoy, y el motivo repetía el nombre del profesional ("Dr. Carlos Pérez … Dr. Carlos Pérez no atiende"). El aviso de Servicios para Caja se ajustó después de la prueba y no se volvió a ver en pantalla.
- **No se probó en pantalla:** "Ahora" con alguien libre (a la hora de la prueba nadie atendía; se probó por la API simulando las 10:02), cobrar en efectivo desde el carrito con la caja abierta, que el carrito se conserve al ir a otra pantalla y volver, una factura, el carrito con varias sedes cambiando de sede, tema oscuro y celular.

## 9. Para aplicar

Columna nueva y productos de ejemplo: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`. Ese mismo reset trae también la tabla de monedas, que la base aún no tiene.
