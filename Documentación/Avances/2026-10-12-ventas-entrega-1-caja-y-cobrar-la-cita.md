# 2026-10-12 — Ventas, entrega 1: Caja y "Cobrar" en la cita, con comprobante simulado

## Contexto

Ventas va antes que Paquetes y Marketing: en el prototipo los paquetes se venden y se consumen al cobrar, y los cupones se validan ahí. Se acordó hacerlo en entregas:

| Entrega | Qué incluye | Estado |
|---|---|---|
| **1** | Caja (abrir, movimientos, cierre con arqueo), cobrar una cita, comprobante | **Esta** |
| 2 | Pantallas Ventas y Pagos, anular, devolver, imprimir el comprobante | Pendiente |
| 3 | Punto de venta con carrito (vender sin cita, varios ítems, descuentos) | Pendiente |
| 4 | Pasarela de pago simulada (link de pago, adelanto en el portal) e Integraciones | Pendiente |
| 5 | Paquetes · 6 Marketing | Pendiente |

Decisiones de esta entrega:

- **Los precios ya incluyen el IGV** (18 %) por defecto; cada empresa lo puede cambiar en su configuración.
- **Una caja por sede, creada sola**; la empresa puede agregar más.
- **El comprobante queda listo para SUNAT y por ahora es una simulación.** Lo mismo se hará con la pasarela de pago en la entrega 4.
- La pasarela será para que los clientes le paguen a cada empresa (adelantos, links de pago), no para la suscripción a la plataforma.

## 1. Caja (`/cash`)

Ítem nuevo "Caja" en el grupo Ventas del menú. Lo ve el administrador de la empresa.

**Arriba, una tarjeta por caja:**

- Cerrada: botón "Abrir caja". Pide con cuánto efectivo empieza el turno (puede ser 0).
- Abierta: muestra cuánto efectivo **debe haber** ahora, quién la abrió y cuándo, y tres números (apertura, cobros, salidas). Botones: "Movimiento", "Ver turno" y "Cerrar caja".

**Movimiento:** ingreso, gasto o retiro de efectivo, con su monto y su motivo. No se puede sacar más efectivo del que hay.

**Cerrar caja (arqueo):** muestra el cálculo de lo que debe haber, se escribe lo contado y dice al instante si cuadra, falta o sobra. Si la diferencia supera la tolerancia de la empresa, hay que explicarla para cerrar y los demás administradores reciben un aviso en la campana ("Cierre de caja con diferencia").

**Ver turno:** quién abrió y cerró, los números, lo cobrado en la sede durante el turno por cada medio de pago (también tarjeta, Yape, etc., para cuadrar lo que no es efectivo) y cada movimiento, de a 6.

**Abajo, la tabla "Turnos cerrados":** caja y sede, apertura, cierre, lo que debía haber, lo contado y el resultado ("Cuadró", "Faltaron S/ 5.00"). Tarjetas que filtran (Turnos cerrados, Cuadraron, Con diferencia), filtros por sede, caja y fecha de cierre, "Ordenar por" y paginación de a 6.

**Nueva caja** (solo el administrador): sede y nombre. Una caja se puede renombrar o poner fuera de uso; no se elimina, para conservar sus turnos.

## 2. Cobrar una cita

En el panel de la cita aparece una tarjeta nueva, **"Cobrar"**, con lo que falta por cobrar. Al tocarla se abre el panel de cobro:

| Parte | Detalle |
|---|---|
| Lo que se cobra | El servicio, la operación gravada, el IGV y el total |
| Medio de pago | Efectivo, Tarjeta, Transferencia, Yape o Plin |
| Monto | Viene con el saldo. Se puede cobrar una parte (si la empresa lo permite) |
| Efectivo | "Recibido" calcula el vuelto. Exige una caja abierta en la sede; si está cerrada lo dice y ofrece "Abrir caja" |
| Los demás medios | Piden el número de operación o voucher |
| Comprobante | Boleta (a nombre del cliente) o Factura (RUC, razón social, dirección). Se elige con el pago que completa el total |

- La venta nace al registrar el primer pago, con su código (`V-000001`, correlativo por empresa que no se repite aunque dos cajas cobren a la vez).
- Con pagos parciales la venta queda en "Pago parcial" y muestra el saldo. Al llegar a cero pasa a "Pagada" y **se emite el comprobante**.
- Los datos de la factura se revisan **antes** de cobrar: no queda una venta pagada sin comprobante por un RUC mal escrito.
- Un doble clic o un reintento no cobra dos veces.
- Una cita cancelada o a la que no asistieron no se cobra. Tampoco una con precio cero.
- Un profesional puede cobrar sus propias citas; no ve la pantalla Caja.
- La tarjeta de la cita pasa a "Cobrada · V-000001 · Factura F001-00000001".

## 3. Comprobante: listo para SUNAT, hoy simulado

- Las empresas con el módulo de facturación emiten **boleta** (serie B001) o **factura** (F001). Las que no lo tienen emiten un **comprobante interno** (T001), que no va a SUNAT.
- Cada tipo lleva su correlativo por empresa.
- El envío pasa por un "proveedor de facturación". Hoy el único es la **simulación**: acepta el comprobante sin enviarlo a ningún lado, y el comprobante lo dice en pantalla ("Simulación: este comprobante no se envió a SUNAT").
- **Para conectar uno real** (Nubefact u otro) se agrega una clase que cumpla el contrato `InvoiceProvider` y se registra en `Invoicing::PROVIDERS`. Ventas, caja y pantallas no cambian. Las llaves de cada empresa irán en la tabla `tenant_integrations` (ya creada), que se administrará desde "Integraciones" en la entrega 4.
- Si un proveedor real no responde, el comprobante queda emitido y "pendiente de envío": el cobro no se pierde.

## 4. Configuración: "Ventas y caja"

Sección nueva en la configuración de la empresa (solo con el módulo de punto de venta):

| Opción | Por defecto |
|---|---|
| Los precios ya incluyen el IGV | Sí. Apagado, el IGV se suma encima del precio |
| El IGV es de __ % | 18 |
| Exigir caja abierta para cobrar en efectivo | Sí |
| Aceptar pagos por partes | Sí |
| Tolerancia de diferencia al cerrar caja | S/ 0 |

Cada venta guarda con qué regla se calculó: cambiar la configuración no altera las ventas ya creadas.

## 5. Base de datos (`init.sql`)

Las tablas de ventas existían solo como esquema, sin uso. Se rehicieron:

| Tabla | Cambio |
|---|---|
| `sales` | Código, estado, pagado, saldo, propina, tasa y modo del impuesto, notas, quién la creó |
| `sale_items` | Descuento, profesional y cita |
| `payments` | Estado, vuelto, sede, cliente, cita, turno de caja, proveedor, enlace y vencimiento (para la pasarela). Puede existir sin venta (adelanto) |
| `cash_sessions` | Caja, estado, esperado, diferencia y observaciones. Una caja no admite dos turnos abiertos |
| `cash_movements` | Tipo validado, quién lo registró |
| `cash_registers` | Nueva. Cajas por sede |
| `invoice_series`, `invoices` | Nuevas. Series y comprobantes |
| `tenant_sequences` | Nueva. Correlativos por empresa |
| `tenant_integrations` | Nueva. Proveedor de pagos, facturación y mensajería de cada empresa |

Sin cambios en `seed.sql` ni `seed_demo.sql`: la caja de cada sede se crea sola al abrir la pantalla.

## 6. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/init.sql` | Lo de la sección 5. |
| `apps/api/app/Support/Sales.php` | Nuevo. Totales con impuesto, correlativo, venta de una cita y registro de pagos. |
| `apps/api/app/Support/Cash.php` | Nuevo. Cajas, turnos y lo que debe haber en efectivo. |
| `apps/api/app/Support/Invoicing.php`, `Billing/InvoiceProvider.php`, `Billing/SimulatedInvoiceProvider.php` | Nuevos. Comprobantes y el contrato del proveedor. |
| `apps/api/app/Http/Controllers/Api/CashController.php`, `SaleController.php`, `routes/api.php` | Nuevos. Rutas bajo el módulo `pos`. |
| `apps/api/app/Support/AppointmentFlow.php`, `TenantSettingController.php` | Reglas por defecto y su lectura y guardado. |
| `apps/web/src/features/sales/` | Nuevo. Panel de cobro (`ChargePanel`) y tipos. |
| `apps/web/src/features/cash/`, `app/(dashboard)/cash/page.tsx` | Nuevo. La pantalla Caja y sus paneles. |
| `apps/web/src/features/appointments/components/AppointmentDetail.tsx` | La tarjeta "Cobrar". |
| `apps/web/src/features/settings/components/TenantSettings.tsx` | La sección "Ventas y caja". |
| `apps/web/src/app/components/layout/Sidebar.tsx`, `features/notifications/notifications.tsx` | El ítem "Caja" y el icono del aviso. |

## 7. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada).

**Por la API:**

| Caso | Resultado |
|---|---|
| Abrir la pantalla Caja | Crea sola una "Caja principal" en cada una de las 3 sedes |
| Cita de S/ 60 con IGV incluido | Gravada 50.85, IGV 9.15, total 60 |
| Efectivo con la caja cerrada | 409 con su explicación; no queda venta |
| Abrir la caja con 100; abrirla otra vez | Abierta; el segundo intento se rechaza |
| Efectivo 20, recibe 50 | Vuelto 30, venta en pago parcial, saldo 40, sin comprobante todavía |
| Mismo cobro repetido (misma clave) | No se duplica |
| Tarjeta sin voucher; más que el saldo; factura con RUC inválido | Rechazados con 422 |
| Tarjeta por el saldo | Venta pagada `V-000001`, boleta `B001-00000001` a nombre del cliente, "Simulación: el comprobante no se envió a SUNAT" |
| Cobrar una venta ya pagada | Rechazado |
| Segunda cita con factura | `V-000002`, `F001-00000001` con la razón social |
| Gasto de 10; retiro mayor que la caja | Esperado 110; el retiro se rechaza |
| Detalle del turno | Movimientos y lo cobrado por medio (tarjeta 40, efectivo 20) |
| Cerrar con faltante de 5, sin nota; con nota | Rechazado; cerrado con diferencia −5 |
| Mover en un turno cerrado | Rechazado |
| Precios sin IGV incluido (regla cambiada) | Cita de S/ 50: IGV 9, total 59 |
| Pagos parciales apagados | Un pago menor que el total se rechaza |
| Caja abierta no exigida | El efectivo se cobra sin caja |
| Un profesional abre Caja; intenta cobrar una cita ajena | 403 en ambos |
| Otra empresa pide la venta o el turno | 404 |

**En el navegador, con clics reales, como dueña de Centro Médico:**

| Prueba | Resultado |
|---|---|
| Menú | Aparece "Caja" |
| Caja | 3 cajas cerradas, "0 de 3 cajas abiertas" |
| "Abrir caja" con 100 (escribir "100abc.509" deja "100.50") | La tarjeta pasa a "Abierta", debe haber S/ 100.00 |
| Citas → abrir una cita de Miraflores | Tarjeta "Cobrar · S/ 60.00 por cobrar" |
| Tocar "Cobrar" | Panel con gravada 50.85, IGV 9.15, total 60, boleta marcada a nombre del cliente |
| Monto 20, recibido 50 | "Vuelto: S/ 30.00"; el botón dice "Cobrar S/ 20.00"; el comprobante se oculta (aún no completa) |
| Cobrar | "Pago registrado. Saldo por cobrar: S/ 40.00"; título "Cobro V-000001" |
| Elegir Tarjeta | Cambia "Recibido" por "N.º de operación", obligatorio |
| Factura con RUC "2012x" (queda "2012") | "La factura necesita un RUC válido de 11 dígitos." |
| RUC y razón social correctos, cobrar | "La venta V-000001 quedó pagada"; aparece la factura `F001-00000001` con la nota de simulación y los dos pagos |
| Cerrar el panel | La tarjeta de la cita dice "Cobrada · V-000001 · Factura F001-00000001" |
| Caja | Debe haber S/ 120.00, cobros S/ 20.00 |
| Movimiento: retiro de 500 | "En la caja no hay tanto efectivo." |
| Movimiento: gasto de 15 | Debe haber S/ 105.00 |
| "Ver turno" | Tarjeta 40, efectivo 20 y los dos movimientos |
| "Cerrar caja": contar 105 | "La caja cuadra." |
| Contar 100 | "Faltan S/ 5.00"; la observación pasa a ser obligatoria |
| Cerrar con observación | La caja vuelve a "Cerrada"; el turno aparece en la tabla con "Faltaron S/ 5.00" |
| Tarjetas "Cuadraron" y "Con diferencia" | Dejan 0 y 1 fila |
| Nombre de la caja en la tabla | Abre el turno cerrado con su observación |
| "Nueva caja" sin sede; con Sede Surco | "Elige la sede de la caja."; aparece "Caja recepción" |
| Cobrar en efectivo en una sede con la caja cerrada | Aviso con "Abrir caja" y botón bloqueado; al elegir Yape se habilita |
| Configuración → "Ventas y caja" | Carga con los tres interruptores encendidos, 18 % y tolerancia 0 |
| Apagar "pagos por partes", tolerancia "2x.5" (queda "2.5"), guardar | "Configuración guardada."; se conserva |

- Se revisaron en capturas la pantalla Caja con una caja abierta, el panel de cobro, la venta pagada con su factura y la sección de Configuración.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- Tras la prueba se cambió un texto: el movimiento de un cobro decía "Cobro · Cobro V-000001"; ahora dice "Cobro · V-000001". Ese cambio no se volvió a ver en pantalla.
- **No se probó en pantalla:** el aviso de cierre con diferencia en la campana (solo hay un administrador en la empresa de prueba y quien cierra no lo recibe), editar o poner fuera de uso una caja, el comprobante interno de una empresa sin facturación, "Emitir comprobante" de una venta pagada sin él, el filtro de fecha y el orden de la tabla, cobrar desde la agenda (se probó desde Citas), la vista de un profesional, el tema oscuro y el celular.

## 8. Para aplicar

Hay tablas nuevas: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`, en ese orden. Mientras no se resetee, Caja y la tarjeta "Cobrar" muestran un mensaje que lo pide; el resto del sistema funciona igual.
