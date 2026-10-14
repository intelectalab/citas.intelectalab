# 2026-10-14 — Moneda: tabla propia, moneda por empresa y cambio de registros anteriores

## Contexto

Todo estaba en soles, con "S/" escrito fijo en las pantallas. Las ventas, los pagos y la caja no guardaban en qué moneda se hicieron. No se sabe si las empresas serán solo del Perú.

Decidido:

| Pregunta | Respuesta |
|---|---|
| ¿Qué hace "cambiar registros anteriores"? | Se le pregunta a la empresa qué pasó y se le muestran los dos casos. Por defecto, y recomendado: **corregir la moneda** sin tocar los montos |
| ¿Las ventas con comprobante emitido? | Quedan fuera del cambio |
| ¿Una moneda por empresa a la vez? | Sí, por ahora. Más adelante, cajas por moneda y por sede |
| ¿Qué monedas? | Las 10 propuestas |
| ¿Comprobante de otros países? | Se sigue simulando SUNAT; la API queda lista para conectar un proveedor |

## 1. Tabla de monedas

Tabla `currencies` con código, nombre, símbolo y decimales. Nacen 10:

| Código | Moneda | Símbolo |
|---|---|---|
| PEN | Sol peruano | S/ |
| USD | Dólar estadounidense | US$ |
| EUR | Euro | € |
| MXN | Peso mexicano | MX$ |
| COP | Peso colombiano | COL$ |
| CLP | Peso chileno (sin decimales) | CLP$ |
| ARS | Peso argentino | AR$ |
| BOB | Boliviano | Bs |
| BRL | Real brasileño | R$ |
| GBP | Libra esterlina | £ |

**Configuración de la plataforma → Monedas** (administrador SaaS): una lista de a 6, con un interruptor por moneda y cuántas empresas y planes la usan. Una moneda apagada ya no la pueden elegir las empresas. No se apaga una moneda que alguna empresa usa ni una que está en el precio de un plan. El sol peruano no se apaga: es la moneda de quien no eligió otra.

**El precio de cada plan** se elige ahora de esa lista, en lugar de escribirlo. Las páginas públicas de planes muestran el símbolo de la moneda del plan.

## 2. Cada registro guarda su moneda

Las ventas, los pagos, las devoluciones, los turnos de caja, los comprobantes y las citas guardan la moneda en que se hicieron. Cambiar la moneda de la empresa **no altera** nada de lo ya registrado: cada registro se sigue mostrando con su moneda.

Todas las pantallas de dinero muestran el símbolo de cada registro: Caja, Ventas, Pagos, el cobro de la cita, el panel de la venta, el comprobante para imprimir, la lista de citas y el portal del cliente. Los totales de Ventas, Pagos e Inicio suman solo lo que está en la moneda de la empresa; si hay registros en otra moneda, no se mezclan.

## 3. Configuración → Moneda

Sección nueva: "Tu empresa cobra en Sol peruano (S/)", con dos botones.

**"Cambiar moneda"** (panel lateral):

- Se elige la moneda nueva y se explica qué pasa: lo nuevo se registra en la moneda nueva y lo anterior se queda como está.
- Avisa que los servicios y cupones de monto fijo conservan sus números (un servicio de 150 pasa a costar US$ 150), para revisarlos.
- **No se puede cambiar con una caja abierta ni con ventas a medio cobrar**, para que cada turno y cada venta queden en una sola moneda. Lo dice: "La Caja principal de Sede Central - Miraflores está abierta: ciérrala antes…".
- Al terminar pregunta: "¿Hay registros anteriores en S/ que también quieras cambiar?", con "Sí, cambiar registros anteriores" y "No, dejarlos así".

**"Cambiar registros anteriores"** (panel lateral), primero pregunta **"¿Qué pasó?"**:

| Opción | Qué hace | Ejemplo en pantalla |
|---|---|---|
| **Me equivoqué de moneda** (Recomendado, viene marcada) | Los montos se quedan igual; solo cambia la moneda | S/ 100.00 → US$ 100.00 |
| **Quiero pasar los montos a la otra moneda** | Recalcula los montos con un tipo de cambio. Avisa que dejarán de decir lo que el cliente pagó | S/ 370.00 → US$ 100.00, con 1 US$ = 3.70 S/ |

Después:

- **Registros en:** la moneda de los registros que se cambian; pasan a la moneda de la empresa.
- **¿Cuáles?:** "Todos" o "De una fecha a otra", con el calendario propio. Las ventas cuentan por el día en que se hicieron; las citas, por el día en que son.
- **Tipo de cambio** (solo en la segunda opción): se escribe como "1 US$ = 3.75 S/" o como "1 S/ = 0.27 US$", lo que le resulte más natural a la empresa.
- **Vista previa en vivo:** "Se cambiarán 12 ventas (con sus 15 pagos), 30 citas, 2 turnos de caja", con un ejemplo real ("S/ 180.00 → US$ 48.00").
- **Lo que queda fuera:** las ventas con comprobante emitido, y sus citas y turnos de caja: "una boleta o factura no cambia de moneda; se corrige con una nota de crédito".
- Al confirmar se pide una última confirmación; con la segunda opción, en rojo.

**Historial de cambios de moneda** en la misma sección (de a 6): "Cambió la moneda de S/ a US$", "Corrigió la moneda de 166 citas…", "Pasó 557 citas de S/ a US$ con 1 US$ = 3.75 S/", con fecha y quién lo hizo. Una venta convertida guarda también de qué moneda vino y con qué tipo de cambio.

**Una cita agendada en la moneda anterior** no se cobra en la nueva sin más: el cobro lo explica ("El precio de esta cita está en S/ y tu empresa ahora cobra en US$. Pásala a US$ en Configuración → Moneda → «Cambiar registros anteriores»…").

Cambiar la moneda pide el permiso de cambiar la configuración. Verla, el de ver la configuración.

## 4. Comprobante

Sigue simulado ("no se envió a SUNAT"). La API ya estaba preparada para conectar un proveedor real por empresa (`InvoiceProvider`, `Invoicing::PROVIDERS` y la tabla `tenant_integrations`). Ahora además cada comprobante guarda su moneda y se la envía al proveedor, que es lo que pide cualquier sistema tributario.

## 5. Base de datos

| Archivo | Cambio |
|---|---|
| `init.sql` | Tabla `currencies`. Columna `currency` en `sales`, `payments`, `cash_sessions`, `invoices` y `appointments` (por defecto PEN). `sales.converted_from` y `sales.exchange_rate`. Tabla `currency_changes` (el historial) |
| `seed.sql` | Las 10 monedas |

La moneda de cada empresa se guarda en su configuración (`currency.code`); por defecto, soles.

## 6. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Currency.php` | Nuevo. Monedas, moneda de la empresa, qué impide cambiarla, qué registros cambian, el cambio y el historial. |
| `apps/api/app/Http/Controllers/Api/CurrencyController.php`, `routes/api.php` | Nuevo. `/currency`, `/currency/records/preview`, `/currency/records` y, para la plataforma, `/admin/currencies`. |
| `Sales.php`, `Refunds.php`, `Invoicing.php`, `Cash.php`, `CashController.php`, `AppointmentController.php`, `PortalController.php`, `Appointment.php` | Cada registro nuevo guarda su moneda. |
| `SaleController.php`, `SalesController.php`, `DashboardController.php` | Cita en otra moneda; la moneda en las listas; el inicio suma solo la moneda de la empresa. |
| `AuthController.php`, `SaaSPlanController.php`, `SaaSPlan.php` | La moneda viaja con la sesión; el plan valida su moneda y muestra su símbolo. |
| `apps/web/src/lib/currency.ts` | Nuevo. Símbolo y formato de cada moneda. |
| `apps/web/src/features/settings/components/CurrencySettings.tsx`, `TenantSettings.tsx` | Nuevo. La sección Moneda y sus dos paneles. |
| `apps/web/src/features/platform/components/PlatformCurrencies.tsx`, `PlatformSettings.tsx`, `plans/components/FormSaaSPlan.tsx` | Nuevo. Monedas de la plataforma; moneda del plan desde la lista. |
| Caja, ventas, pagos, cobro, comprobante, citas, portal, cupones, inicio y páginas de planes | El símbolo de cada registro en lugar del "S/" fijo. |

## 7. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API** (Centro Médico):

| Caso | Resultado |
|---|---|
| Moneda de fábrica | PEN, con 10 monedas; la sesión trae la moneda de la empresa |
| Cobrar una cita en efectivo | Venta, pago y boleta en PEN |
| Cambiar a dólares con la caja abierta | Rechazado: "La Caja principal… está abierta" |
| Moneda que no existe | Rechazado |
| Cerrar la caja y cambiar a dólares | Hecho; la venta anterior sigue en PEN; el historial anota el cambio |
| Cobrar una cita agendada en soles | Bloqueado, con la explicación de cómo pasarla a US$ |
| Vista previa "corregir la moneda", todo | 556 citas; queda fuera la venta con boleta |
| Lo mismo de hoy a 14 días | 166 citas |
| Desde la moneda que ya es la de la empresa; fechas al revés | Rechazados |
| Aplicar | Las citas quedan en USD con el mismo precio (50.00); la venta con boleta sigue en PEN |
| Abrir la caja y cobrar la cita | Turno, venta y pago en USD |
| "Pasar los montos" con 1 US$ = 3.75 S/ | Una cita de S/ 50.00 queda en US$ 13.33; el historial anota el tipo de cambio |
| Sin tipo de cambio; tipo de cambio cero; sin nada que cambiar | Rechazados, cada uno con su explicación |
| Recepción cambia o ve la moneda | Rechazado (no tiene permiso de configuración) |
| Monedas de la plataforma | PEN: 10 empresas y 3 planes; USD: 1 empresa |
| Apagar la libra; el sol; el dólar (en uso) | Apagada; rechazado; rechazado |
| La empresa después de apagar la libra | Ya no la ofrece |
| Un plan con moneda inexistente | Rechazado; los planes públicos traen su símbolo (S/) |

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Configuración (dueña) | "Tu empresa cobra en Sol peruano (S/)" |
| "Cambiar moneda" | Lista de las 10 monedas; elegir Dólar muestra qué pasa y el aviso de servicios y cupones |
| Cambiar | "Listo: desde ahora tu empresa cobra en Dólar estadounidense (US$)…" y la pregunta por los registros anteriores |
| "Sí, cambiar registros anteriores" | "Me equivoqué de moneda" marcado; registros en Sol peruano; vista previa: 557 citas, S/ 180.00 → US$ 180.00 |
| "Quiero pasar los montos" y escribir "3,7x5" | Queda 3.75; ejemplo S/ 180.00 → US$ 48.00 |
| Cambiar a "1 S/ = … US$" | Ejemplo S/ 180.00 → US$ 675.00; al volver, US$ 48.00 |
| "De una fecha a otra" sin fechas | Aparecen los dos calendarios; el botón queda bloqueado |
| "Todos" → "Cambiar 557 registros" → confirmar | Confirmación en rojo; "Listo: se cambiaron 0 ventas, 557 citas, 0 turnos de caja." |
| Historial | "Pasó 557 citas de S/ a US$ con 1 US$ = 3.75 S/." y "Cambió la moneda de S/ a US$.", con fecha y nombre |
| Abrir una cita | "Cobrar US$ 16.00 por cobrar" |
| Configuración de la plataforma → Monedas | 10 monedas de a 6, con su uso ("10 empresas cobran en ella · 3 planes…") |
| Apagar el dólar | "Una empresa cobra en esta moneda: no se puede apagar."; sigue encendido |
| Apagar y encender el euro | "Euro ya no se ofrece a las empresas."; vuelve a encenderse |
| Editar un plan → Moneda | Las 10 monedas con su símbolo; se elige Dólar |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salieron un texto en plural para una sola caja, un mensaje en inglés en el plan, el ejemplo vacío de la vista previa cuando no había ventas, y dos "S/" fijos (lista de citas y tolerancia de caja). Se corrigieron; los dos primeros no se volvieron a ver en pantalla.
- **No se probó en pantalla:** elegir fechas en el calendario del panel, una venta convertida en el panel de la venta y en el comprobante, el aviso de caja abierta en el panel (se probó por la API), guardar un plan en dólares, tema oscuro y celular.

## 8. Para aplicar

Hay una tabla de monedas, columnas nuevas y el historial: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`. Mientras no se resetee, la sección Moneda pide actualizar la base y todo sigue en soles como hasta hoy.
