# 2026-10-13 — Ventas, entrega 2: pantallas Ventas y Pagos, anular, devolver e imprimir el comprobante

## Contexto

Con la entrega 1 se podía cobrar una cita, pero no había dónde ver todas las ventas ni cómo corregir una. Esta entrega agrega eso.

## 1. Anular y devolver: qué es cada cosa

En el prototipo están en la pantalla **Ventas**: al abrir una venta, abajo aparecen "Anular" y "Devolución". Su regla es la misma que se hizo aquí:

| | Anular | Devolver |
|---|---|---|
| **Para qué venta** | Una que **no tiene dinero cobrado** | Una que **ya tiene dinero cobrado** |
| **Caso típico** | Se registró por error, o el cliente no llegó a pagar | El servicio no se hizo, o se cobró de más |
| **Qué pasa con el dinero** | Nada: no hubo | Sale por un medio (efectivo, Yape, transferencia…). Si es efectivo, sale de la caja abierta |
| **Qué queda registrado** | La venta, como "Anulada", con su motivo | Un pago en negativo, la devolución con su motivo y, si había comprobante, una **nota de crédito** |
| **La cita** | Vuelve a estar por cobrar | Sigue con su venta, marcada "Devuelta" o "Devuelta en parte" |
| **El cupón** | Recupera su uso | Sigue usado |

Por qué son dos cosas: una venta cobrada ya movió dinero y, casi siempre, ya emitió un comprobante. Eso no se puede borrar; se corrige con otro registro que lo deshace. Por eso una venta cobrada **nunca se anula**: el sistema lo dice ("no se anula, se devuelve").

Reglas de la devolución:

- Puede ser de todo o de una parte, y puede haber varias hasta completar lo cobrado.
- Tiene plazo: 30 días desde la venta por defecto. Cada empresa lo cambia en Configuración → "Ventas y caja"; con 0 no hay plazo.
- En efectivo exige una caja abierta en la sede y no puede superar lo que hay en ella. Por otro medio, el dinero se devuelve fuera del sistema y aquí queda registrado.
- **Venta a medio cobrar** (por ejemplo, pagó S/ 10 de S/ 180 y se arrepiente): se le devuelve lo que pagó, la venta vuelve a "Por cobrar" sin nota de crédito (aún no había comprobante) y entonces sí se puede anular.
- La nota de crédito sale con serie propia (BC01 para boleta, FC01 para factura, TC01 para el comprobante interno) y, como el comprobante, hoy es una simulación.

Diferencia con el prototipo: allí una devolución puede quedar "solicitada" hasta que un gerente la aprueba. Aquí la hace directamente el administrador, porque hoy no existe otro rol que la solicite.

## 2. Pantalla Ventas (`/sales`)

Ítem "Ventas" en el grupo Ventas del menú (administrador).

| Parte | Detalle |
|---|---|
| Pestañas | Todas, Por cobrar, Pagadas, Con devolución, Anuladas |
| Tarjetas | Vendido hoy, Por cobrar, Devuelto (filtran) |
| Columnas | Código y fecha, cliente (enlace a su página) y sede, detalle y cita, total con lo que falta o lo devuelto, comprobante, estado |
| Filtros y orden | Sede, estado y fecha; ordenar por fecha, total, saldo, cliente y estado |

El código o el ojo abren el **panel de la venta**: total, cliente, detalle con IGV y cupón, pagos y devoluciones de dinero, comprobante y la lista de devoluciones con su motivo y su nota de crédito. Acciones, según el estado:

- **Cobrar S/ …**: abre el mismo panel de cobro de la cita, con el saldo.
- **Imprimir comprobante**: lo abre en otra pestaña.
- **Devolver**: monto (viene con todo lo disponible), motivo y medio.
- **Anular venta**: pide el motivo y explica qué pasará.

Solo aparecen las acciones posibles: una venta pagada no muestra "Anular"; una anulada no muestra ninguna.

## 3. Pantalla Pagos (`/payments`)

Todo el dinero que entró y salió por ventas: cada cobro y cada devolución (en rojo y en negativo).

- Pestañas: Todos, Cobros, Devoluciones. Tarjetas: Cobrado hoy, Devuelto hoy, Neto de hoy.
- Columnas: fecha y quién, medio y número de operación, venta (abre su panel), cliente con sede y caja, monto.
- Filtros por medio, sede y fecha; orden por fecha, monto, medio y cliente.

## 4. Imprimir el comprobante

"Imprimir comprobante" abre una página sin menú, en formato de tira de 80 mm: emisor con su RUC, tipo y número, cliente, detalle, gravada, IGV, total, pagos y lo devuelto. El botón "Imprimir" abre el diálogo de impresión del navegador (desde ahí también se guarda como PDF).

Mientras el comprobante sea simulado, al pie dice: "Documento de prueba: no se envió a SUNAT y no tiene validez tributaria."

## 5. Otros cambios

- La tarjeta "Cobrar" de la cita y el panel de cobro reconocen una venta devuelta: muestran "Devuelta en parte · se devolvieron S/ 15.00" y ya no ofrecen cobrar.
- Lo cobrado por turno de caja descuenta las devoluciones.

## 6. Base de datos (`init.sql`)

- Tabla nueva `sale_refunds`: cada devolución con su monto, medio, motivo, su pago y su nota de crédito.
- `invoices`: columnas `reference_invoice_id` (comprobante que corrige una nota de crédito) y `reason`.

## 7. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/init.sql` | Lo de la sección 6. |
| `apps/api/app/Support/Refunds.php` | Nuevo. Anular y devolver, con sus reglas. |
| `apps/api/app/Support/Invoicing.php` | Nota de crédito. |
| `apps/api/app/Http/Controllers/Api/SalesController.php`, `routes/api.php` | Nuevo. Lista y detalle de ventas, anular, devolver y lista de pagos. |
| `apps/api/app/Support/Sales.php`, `Cash.php`, `AppointmentFlow.php`, `TenantSettingController.php` | Plazo de devolución, lo devuelto en la venta y en el turno. |
| `apps/web/src/features/sales/components/SalesTable.tsx`, `PaymentsTable.tsx`, `SaleDetailPanel.tsx`, `SaleReceipt.tsx` | Nuevos. |
| `apps/web/src/app/(dashboard)/sales/page.tsx`, `payments/page.tsx`, `app/print/sales/[id]/page.tsx` | Las páginas. |
| `apps/web/src/features/sales/components/ChargePanel.tsx`, `sales.ts`, `features/appointments/components/AppointmentDetail.tsx` | Estados de devolución. |
| `apps/web/src/features/settings/components/TenantSettings.tsx`, `app/components/layout/Sidebar.tsx` | Plazo de devolución; ítems Ventas y Pagos. |

## 8. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada).

**Por la API:**

| Caso | Resultado |
|---|---|
| Anular una venta pagada | Rechazado: "no se anula, se devuelve" |
| Devolver más de lo cobrado; sin motivo | Rechazados |
| Venta de S/ 160 pagada en efectivo: devolver 60 en efectivo | "Devuelta en parte", quedan 100 por devolver; nota de crédito BC01-00000001 por 60 (IGV 9.15); pago de −60; la caja baja de 210 a 150 |
| Devolver los 100 restantes por transferencia | "Devuelta"; segunda nota BC01-00000002 |
| Devolver otra vez; volver a cobrar esa cita | Rechazados |
| El cupón de una venta devuelta | Sigue usado |
| Venta con pago parcial de 30: anular | Rechazado mientras tenga dinero |
| Devolver esos 30 | Vuelve a "Por cobrar", sin nota de crédito |
| Anular sin motivo; con motivo | Rechazado; anulada, y su cupón recupera el uso |
| La cita de la venta anulada | Vuelve a estar por cobrar y se cobra de nuevo (venta nueva) |
| Devolver 50 en efectivo con S/ 5 en caja; con la caja cerrada | Rechazados, cada uno con su explicación |
| Venta de hace 40 días | "Pasó el plazo de 30 días" |
| Con el plazo en 0 | Se devuelve |
| Listas de ventas y de pagos | Cada venta con su estado, lo devuelto y su comprobante; 9 movimientos, 4 de ellos devoluciones |
| Un profesional ve ventas o devuelve; otra empresa ve o anula | 403; 404 |

**En el navegador, con clics reales, como dueña de Centro Médico:**

| Prueba | Resultado |
|---|---|
| Menú | Aparecen "Ventas" y "Pagos" |
| Cobrar una cita completa y otra con S/ 10 de S/ 180 | Ventas muestra V-000001 "Pagada" con su boleta y V-000002 "Pago parcial · Falta S/ 170.00"; pestañas 2, 1, 1, 0, 0 |
| Abrir la pagada | Acciones: Imprimir y Devolver (sin "Anular") |
| "Devolver" | El monto viene con S/ 45.00 y el texto anuncia la nota de crédito |
| Devolver 999 | "El monto debe estar entre S/ 0.01 y S/ 45.00…" |
| Devolver 15 en efectivo | "Devolución de S/ 15.00 registrada"; pasa a "Devuelta en parte"; pago de − S/ 15.00; "Nota de crédito BC01-00000001 · simulación" |
| Abrir la de pago parcial | Acciones: Cobrar S/ 170.00 y Devolver |
| "Cobrar S/ 170.00" | Abre el panel de cobro con 170.00 |
| Devolver los 10 por Yape | Vuelve a "Por cobrar", sin nota de crédito; ahora ofrece "Anular venta" |
| "Anular venta" | Explica que la cita vuelve a estar por cobrar; no deja enviar sin motivo |
| Anular con motivo | "La venta quedó anulada"; sin acciones; muestra el motivo |
| Tabla y pestañas | 2, 0, 0, 1, 1; "Anuladas" deja solo V-000002 |
| Pagos | 4 movimientos; pestaña "Devoluciones" deja los 2 en negativo |
| Clic en la venta desde Pagos | Abre su panel |
| "Imprimir comprobante" | Página sin menú con la boleta, sus pagos, lo devuelto y la nota de documento de prueba |
| Configuración: plazo de devolución, escribir "7x" | Queda "7"; se guarda y se conserva |
| La cita de la venta anulada | "Cobrar · S/ 180.00 por cobrar" |
| La cita de la venta devuelta en parte | "Cobro V-000001 · S/ 45.00 · se devolvieron S/ 15.00 · Devuelta en parte"; su panel no ofrece cobrar |

- Se revisó en una captura el comprobante para imprimir.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- La tarjeta de la cita con venta devuelta decía al principio "Pagado S/ 30.00 de S/ 45.00. Falta S/ 0.00"; se corrigió y se volvió a comprobar en pantalla.
- **No se probó en pantalla:** el botón "Imprimir" (abre el diálogo del navegador), abrir el comprobante en pestaña nueva (se abrió en la misma para usar la base temporal), devolver con la caja cerrada o con varias cajas abiertas, una factura con su nota FC01, filtros y orden de las dos tablas, la venta con cupón en el panel, capturas del panel de la venta (el panel del navegador era angosto), el tema oscuro y el celular.

## 9. Para aplicar

Hay una tabla y dos columnas nuevas: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`, en ese orden. Mientras no se resetee, Ventas, Pagos, Caja y "Cobrar" muestran un mensaje que lo pide.

## 10. Ajuste posterior: los pagos por partes dejan de ser lo normal

Pedido: que cobrar por partes no sea el comportamiento por defecto, porque lo habitual es pagar la cita completa.

- La regla "Aceptar pagos por partes" ahora nace **apagada**: la cita se cobra completa, de una vez. El monto del panel de cobro viene con el total y no se edita.
- La empresa que sí cobre por partes (un tratamiento largo, por ejemplo) la enciende en Configuración → "Ventas y caja".
- Las empresas que ya habían guardado esa opción conservan lo que eligieron.

Cambio: `apps/api/app/Support/AppointmentFlow.php` (valor por defecto). Comprobado leyendo la regla vigente de una empresa que no la había tocado: devuelve "no". El comportamiento con la regla apagada ya estaba probado en la entrega 1 (un pago menor que el total se rechaza; el campo de monto queda bloqueado). No se volvió a recorrer en pantalla.

Con esto, el caso "pagó una parte y se arrepintió" de la sección 1 solo aplica a las empresas que enciendan los pagos por partes.
