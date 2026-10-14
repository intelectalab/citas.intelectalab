# 2026-10-13 — Cupones al cobrar, caja por sucursal configurable y textos más claros en Caja

## Contexto

Tras probar la entrega 1 de Ventas:

- Los textos del panel "Movimiento de caja" confundían ("entra efectivo que no es un cobro: sencillo, un reembolso").
- Duda: ¿se guarda la hora del movimiento?
- Pedido: que cada sucursal nueva nazca con su caja por defecto, y que la empresa pueda apagarlo en su configuración sin que se borren las cajas ya creadas.
- Pedido: agregar el **cupón** al cobrar, como en el prototipo. Descuento global, propina y canje de puntos **no** se agregan.
- Duda: si al elegir Yape debería generarse un código QR.

## 1. Movimiento de caja: textos

| Antes | Ahora |
|---|---|
| Ingreso · "Entra efectivo que no es un cobro: sencillo, un reembolso." | **Ingreso: se pone dinero en la caja** · "Dinero que entra y no es el pago de un cliente. Por ejemplo, agregar monedas y billetes chicos para poder dar vuelto." |
| Gasto · "Se paga algo con la caja: movilidad, útiles, un proveedor." | **Gasto: se paga algo con la caja** · "Por ejemplo, un taxi, útiles de limpieza o un pedido a un proveedor." |
| Retiro · "Se saca efectivo para guardarlo o depositarlo." | **Retiro: se saca dinero de la caja** · "Para guardarlo en otro lugar o llevarlo al banco. No es un gasto: el dinero sigue siendo de la empresa." |

Al pie del panel: "Los cobros a clientes no se anotan aquí: entran solos a la caja al cobrar. Cada movimiento guarda la fecha, la hora y quién lo registró; se ven en «Ver turno»."

Eso ya se guardaba desde la entrega 1; ahora el panel lo dice.

## 2. Una caja en cada sucursal nueva

- Al **crear una sucursal**, nace con su "Caja principal" en ese momento (antes aparecía recién al abrir la pantalla Caja).
- Opción nueva en Configuración → "Ventas y caja": **"Crear una caja en cada sucursal nueva"**, encendida por defecto.
- Apagada, las sucursales nuevas no reciben caja: la empresa la crea con "Nueva caja". **Las cajas que ya existen no se tocan.**

## 3. Cupones

### Al cobrar

En el panel de cobro hay un campo **"Cupón"** con su botón "Aplicar":

- Se escribe el código que trae el cliente (pasa solo a mayúsculas). Enter también lo aplica.
- Si vale, aparece la línea "Cupón PROMO15 (15 %) − S/ 6.75", y el total, el IGV y el monto a cobrar se recalculan. "Quitar" lo retira.
- Si no vale, dice por qué: no existe, está desactivado, ya venció, todavía no empieza, agotó sus usos o la compra no llega al mínimo.
- El cupón se fija al registrar el **primer pago**: ahí gasta un uso y queda en la venta. Después ya no se cambia.
- El descuento sale también en el comprobante (se emite por el total ya descontado).
- Un cupón que dejaría el total en cero no se acepta.

### Pantalla Cupones (`/coupons`)

Ítem nuevo "Cupones" en el grupo Ventas del menú; lo ve el administrador.

| Parte | Detalle |
|---|---|
| Pestañas | Todos, Vigentes, Ya no valen |
| Tarjetas | Vigentes, Veces usados, Ya no valen (filtran) |
| Columnas | Código y para qué es, cuánto descuenta y compra mínima, vigencia, usos ("1 de 50"), estado |
| Estados | Vigente, Aún no empieza, Vencido, Agotado, Desactivado |
| Filtros y orden | Tipo de descuento, fecha de vencimiento; ordenar por registro, código, veces usado y vencimiento |
| Nuevo cupón | Código, para qué es, porcentaje o monto fijo, compra mínima, vigencia (desde y hasta, con el calendario propio), cuántas veces se puede usar y si está activo |
| Eliminar | Si ya se usó en una venta no se elimina: se desactiva |

Un cupón descuenta como máximo 99 %.

### Dónde quedó

Los cupones están bajo el módulo de punto de venta, porque se usan al cobrar. Cuando se haga Marketing (campañas que reparten un cupón), esta misma pantalla se enlazará desde ahí.

## 4. Lo que no se agregó

Descuento global, propina y canje de puntos del prototipo quedan fuera por decisión.

## 5. Sobre Yape y el código QR

Hoy "Yape" es un cobro manual: el cliente paga al número o al QR fijo del negocio y quien cobra escribe el número de operación. No hay QR generado por el sistema porque aún no hay pasarela.

Con una pasarela conectada (entrega 4) hay dos formas reales de cobrar con Yape:

1. **QR por cobro:** el sistema pide a la pasarela un QR con el monto exacto; el cliente lo escanea y el pago se confirma solo.
2. **Código de aprobación:** el cliente da su celular y un código que genera su aplicación de Yape; la pasarela cobra y confirma.

En ambos el sistema recibe la confirmación sin que nadie escriba el número de operación. Se dejará simulado en la entrega 4 junto con el link de pago.

## 6. Base de datos

`seed_demo.sql`: tres cupones de ejemplo por empresa (BIENVENIDA10 vigente, VUELVE20 vigente con compra mínima y 50 usos, ANIVERSARIO vencido).

Sin cambios en `init.sql`: las tablas `coupons` y `coupon_redemptions` ya existían.

## 7. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Coupons.php` | Nuevo. Revisar un cupón, calcular su descuento y registrar su uso. |
| `apps/api/app/Http/Controllers/Api/CouponController.php`, `routes/api.php` | Nuevo. Listar, crear, editar y eliminar cupones. |
| `apps/api/app/Support/Sales.php`, `SaleController.php` | El cupón en la vista previa y en la venta. |
| `apps/api/app/Support/Cash.php`, `AppointmentFlow.php`, `BranchController.php`, `TenantSettingController.php` | La regla "crear una caja en cada sucursal nueva" y la caja al crear la sucursal. |
| `apps/api/infrastructure/docker/postgres/seed_demo.sql` | Cupones de ejemplo. |
| `apps/web/src/features/coupons/`, `app/(dashboard)/coupons/page.tsx` | Nuevo. La pantalla y su panel. |
| `apps/web/src/features/sales/components/ChargePanel.tsx`, `sales.ts` | El campo "Cupón" y la línea del descuento. |
| `apps/web/src/features/cash/components/CashPanels.tsx` | Textos del movimiento. |
| `apps/web/src/features/settings/components/TenantSettings.tsx`, `app/components/layout/Sidebar.tsx` | El interruptor nuevo y el ítem "Cupones". |

## 8. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada); cada empresa queda con sus tres cupones.

**Por la API:**

| Caso | Resultado |
|---|---|
| Cita de S/ 180 con BIENVENIDA10 (vista previa) | Descuento 18, IGV 24.71, total 162 |
| Código inexistente; vencido; compra menor que el mínimo | Rechazados, cada uno con su explicación |
| Cobrar con un cupón vencido | Rechazado; no queda venta |
| Cobrar con VUELVE20 | Subtotal 180, descuento 20, total 160, pagada, boleta por 160; el cupón pasa a 1 uso |
| Crear con espacio en el código; con 100 %; con código repetido | Rechazados |
| Crear "unavez" con 1 uso y vigencia | Queda como UNAVEZ, vigente, con sus fechas |
| Usarlo; intentar usarlo otra vez | Aplicado; luego "ya agotó sus usos" |
| Eliminar un cupón usado; uno sin usos | Se desactiva; se elimina |
| Un profesional lista cupones; otra empresa edita uno | 403; 404 |
| Apagar "crear caja en cada sucursal nueva" | Las 3 cajas siguen ahí |
| Con la opción apagada, una sede sin caja | No recibe caja |
| Con la opción encendida | La recibe |
| Crear una sucursal (opción encendida) | Nace con su "Caja principal" |

**En el navegador, con clics reales, como dueña de Centro Médico:**

| Prueba | Resultado |
|---|---|
| Menú | Aparece "Cupones" |
| Cupones | Los tres de ejemplo con su estado |
| "Nuevo cupón": escribir "promo 15!" | Queda "PROMO15" |
| Elegir "Un monto fijo" y volver a "Un porcentaje" | La etiqueta cambia a "Monto (S/)" y vuelve |
| Vigencia "Hasta" con el calendario propio | "30 nov 2026"; sin campos de fecha del navegador |
| Crear con 15 % y 1 uso | Aparece "Desde ya – 30 nov 2026 · 0 de 1 · Vigente" |
| Pestaña "Ya no valen" | Deja solo ANIVERSARIO |
| Cobrar una cita de S/ 45: escribir "aniversario" y Aplicar | Pasa a mayúsculas; "Ese cupón ya venció."; el total no cambia |
| Aplicar PROMO15 | Línea "Cupón PROMO15 (15 %) − S/ 6.75"; total S/ 38.25, IGV S/ 5.83; el botón dice "Cobrar S/ 38.25" |
| "Quitar" | Vuelve a S/ 45.00 |
| Aplicarlo de nuevo y cobrar con Yape | Venta pagada por S/ 38.25, boleta B001-00000001; el campo de cupón ya no aparece y la línea del descuento se conserva |
| Cupones | PROMO15 pasa a "1 de 1 · Agotado" |
| Eliminar PROMO15 | "Ya se usó en ventas: se desactivó en lugar de eliminarse." |
| Caja → Movimiento | Los tres textos nuevos y la nota del pie |
| Configuración → "Crear una caja en cada sucursal nueva" | Encendido; se apaga con un clic, se guarda y se conserva |
| Caja con la opción apagada | Siguen las 3 cajas |

- Se revisó en una captura la venta pagada con su cupón.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** crear una sucursal y ver su caja (se probó por la API), crear una sucursal con la opción apagada (el plan de la empresa de prueba ya estaba en su tope de sucursales; la regla se probó sobre una sede sin caja), editar un cupón, un cupón de monto fijo desde el formulario, el filtro de fecha y el orden de la tabla, el tema oscuro y el celular.

## 9. Para aplicar

No hay tablas nuevas. Para ver los cupones de ejemplo hay que volver a cargar `seed_demo.sql` (reseteando `erp_saas` con los tres archivos); sin eso todo funciona y la pantalla Cupones empieza vacía.

## 10. Ajuste posterior del mismo día

El aviso al dar por atendida una cita sin ficha firmada decía el nombre del servicio entre paréntesis. Se quitó: queda "Este servicio exige la ficha de atención firmada antes de darla por atendida." (`AppointmentController.php`). Solo cambia el texto; `php -l` sin errores, no se volvió a ver en pantalla.
