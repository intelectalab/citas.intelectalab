# 2026-10-14 — Ventas, entrega 4: pasarela de pago simulada, adelantos e Integraciones

## Contexto

Hasta hoy el cliente solo podía pagar en el mostrador. Los servicios marcados "Exige adelanto" no se podían reservar en el portal ("Comunícate con la sede"). Tu jefe pidió simular la pasarela; todavía no hay proveedor elegido.

Antes de programar se revisó el prototipo: link de pago que vence en 24 horas, aviso automático de la pasarela al pagar, adelanto en porcentaje al reservar en línea y la pantalla Integraciones. Lo acordado:

| Pregunta | Respuesta |
|---|---|
| ¿Cuánto es el adelanto? | Lo elige la empresa en cada servicio: un porcentaje (30 % por defecto) o un monto fijo |
| Si reserva en línea y no paga | **Sin adelanto no hay cita.** El horario no se aparta: el primero que paga se lo queda. El link dura lo que elija la empresa (30 minutos por defecto). Si otra persona toma ese horario, el link se anula al instante y no se cobra nada. Se avisa antes al cliente |
| Si cancela con adelanto pagado | Se le devuelve si cancela con anticipación; si cancela tarde o no asiste, queda para la empresa. El plazo lo elige la empresa: 24 horas por defecto |
| QR de Yape | Ahora, el QR fijo del negocio. El QR con el monto exacto, cuando se elija proveedor |

## 1. Link de pago (personal)

En el panel de cobro (desde la cita, el punto de venta o Ventas) hay un medio más: **"Link de pago"**.

- "Crear link de pago" crea un enlace por lo que falta cobrar, que vence en 24 horas (configurable).
- El panel lo muestra con **Copiar**, **Abrir**, **"¿Ya pagó?"** y **Anular link**, y dice "Esperando pago · vence 15 oct, 02:02".
- El panel revisa solo cada 10 segundos; cuando entra el pago, la venta pasa a pagada y se emite la boleta.
- A quien creó el link le llega a la campana: "Pago recibido por link · Venta V-000001 · S/ 130.00 · Santiago Flores Torres".
- **Para no cobrar dos veces:** si se cobra en la sede mientras hay un link esperando, el link se anula ("Se cobró en la sede"). Lo mismo si se cambia la venta en el carrito o se anula.
- Solo un link esperando por venta; para hacer otro hay que anular el anterior.

## 2. Página de pago (la que abre el cliente)

`/pay/…`, fuera del panel, con el nombre de la empresa, el monto y el concepto. Como la pasarela es simulada dice **"Pago de prueba: no se cobra dinero"** y trae dos botones:

- **Simular pago aprobado:** "¡Pago recibido!".
- **Simular pago rechazado:** "El pago fue rechazado. Puedes intentarlo con otra tarjeta u otro medio." El link sigue vigente.

Un link vencido o anulado muestra el motivo y "No se te cobró nada".

## 3. Adelanto al reservar en el portal

- El servicio dice "Se reserva pagando un adelanto de S/ 50.00".
- Al revisar la reserva se le explica: cuánto paga ahora y cuánto en la sede; que su horario se reserva recién al pagar y que tiene 30 minutos; que si otra persona toma el horario el link se anula sin cobrarle; y desde cuándo se le devuelve si cancela.
- El botón dice **"Pagar adelanto de S/ 50.00"** y lleva a la página de pago, con el detalle de la reserva (servicio, día, profesional, sede, precio y lo que paga en la sede).
- **Hasta que paga no existe la cita.** Al pagar se crea ya confirmada, con una venta que muestra el adelanto y el saldo por cobrar en la sede.
- Si sale sin pagar, el portal muestra "Tienes un adelanto por pagar · S/ 50.00 · vence 02:23" con **Pagar** y **Ya no quiero**.
- Si recepción u otra persona agenda ese horario antes, el link se anula al instante: "Ese horario se acaba de ocupar. Elige otro." y el botón "Elegir otro horario".
- Caso muy raro: si dos personas pagan el mismo horario a la vez, al segundo pago se le devuelve el dinero automáticamente.
- En "Mis citas": "Pagaste S/ 50.00 de adelanto. Falta S/ 150.00, que pagas en la sede. Si cancelas con al menos 24 horas de anticipación, se te devuelve."
- **Antes de cancelar**, el aviso dice "Se te devolverá el adelanto de S/ 50.00" o "Faltan menos de 24 horas para tu cita: el adelanto de S/ 50.00 no se devuelve".

## 4. Qué pasa con el adelanto si la cita se cancela

| Caso | Qué hace el sistema |
|---|---|
| Cancela a tiempo | La venta queda "Adelanto por devolver" y la administración recibe un aviso: "Andrea Lozano Vera canceló a tiempo la cita C-0248. Devuélvele S/ 50.00 desde la venta V-000003." En la venta, "Devolver" viene con **"Por la pasarela"** elegido; al devolverlo, la venta se anula sola: "La cita se canceló a tiempo: se devolvió el adelanto." |
| Cancela tarde o no asiste | La venta se cierra con una línea "Adelanto retenido: Coloración completa (cita C-0245, canceló tarde)" por el monto del adelanto, pagada y con su boleta |

Vale tanto si cancela el cliente en el portal como si cancela o marca "No asistió" el personal.

## 5. Configuración

**Sección nueva "Adelantos y links de pago":**
- Un link de pago vence ___ horas después de crearlo (24).
- Pedir el adelanto al reservar en línea (encendido; solo en los servicios que lo exigen).
- El cliente tiene ___ minutos para pagar el adelanto (30), con la explicación de que el horario no se aparta.
- Se le devuelve el adelanto si cancela con al menos ___ horas de anticipación (24), con un ejemplo.

**Sección nueva "Integraciones":**
- **Pasarela de pagos:** Simulación (en uso), Culqi, Niubiz, Izipay y Mercado Pago ("Lista para conectar"). Se pueden guardar las llaves de una pasarela real: quedan cifradas y se muestran como "••••••••5b4a". Se explica que todavía no se cobra con ella hasta conectarla con su documentación y una cuenta de pruebas. Tiene "Probar conexión".
- **Facturación electrónica:** simulación de SUNAT, lista para conectar.
- **WhatsApp y correo:** llega con Marketing; por ahora los links se copian a mano.
- **QR de tu negocio:** se sube la imagen del QR de Yape y de Plin, con el nombre del titular. Al cobrar con Yape o Plin, el panel lo muestra: "Que el cliente escanee este QR · A nombre de Salón Bella Aura SAC · Debe pagar S/ 130.00".
- **Avisos recibidos de la pasarela**, de a 6: "Registrado · Pago aprobado · Adelanto: Coloración completa · Adelanto pagado: se reservó la cita C-0248".

Solo quien puede cambiar la configuración cambia la pasarela y los QR.

## 6. Servicio

Al marcar "Exige adelanto" aparece **"¿Cuánto es el adelanto?"**: "Un porcentaje" o "Un monto fijo", con un ejemplo en vivo: "Con el precio de S/ 200.00, el cliente paga S/ 60.00 al reservar en línea y S/ 140.00 en la sede". No se acepta un porcentaje mayor a 100 ni un monto mayor que el precio.

Los servicios nacen con el porcentaje del rubro (30 %, o 50 % en dental).

El formulario del servicio ahora **muestra los errores de la API**; antes los escribía solo en la consola y parecía que no pasaba nada.

## 7. Cómo queda preparado para una pasarela real

Cada pasarela es un "adaptador" con cuatro tareas: crear el cobro, devolver, leer su aviso y decir si es simulada. Solo existe el simulado. El aviso de una pasarela real llegaría a `/api/webhooks/payments/{pasarela}` firmado; la firma se comprueba y un aviso repetido no se registra dos veces. Para conectar Culqi (u otra) hay que escribir su adaptador con su documentación y probarlo con su cuenta de pruebas.

## 8. Base de datos

| Archivo | Cambio |
|---|---|
| `init.sql` | `services.deposit_type` y `deposit_value`; `sales.deposit_outcome`; tablas `payment_links` (links y adelantos), `payment_events` (avisos de la pasarela) y `payment_qr_codes` (QR de Yape y Plin) |
| `seed_demo.sql` | Los servicios con adelanto toman el porcentaje del rubro (30 % o 50 %) |

## 9. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/PaymentLinks.php` | Nuevo. Crear, anular y vencer links; página pública; aviso de la pasarela (aprobado, rechazado, repetido, tardío); adelanto que crea la cita; anular links cuyo horario se ocupó; devolver o retener el adelanto; devolver por la pasarela. |
| `apps/api/app/Support/Payments/*` | Nuevo. El adaptador de pasarela y el simulado (con firma de los avisos). |
| `apps/api/app/Http/Controllers/Api/PaymentController.php` | Nuevo. Links del personal, Integraciones, QR, página de pago y aviso de la pasarela. |
| `PortalController.php` | Reservar con adelanto, pagos pendientes del cliente, el adelanto en cada cita. |
| `SaleController.php`, `SalesController.php` | Link desde la cita; datos del link y del QR en el cobro; devolver "Por la pasarela"; "Adelanto por devolver". |
| `Sales.php`, `Cart.php`, `Refunds.php`, `AppointmentController.php` | Anular el link al cobrar en la sede, al cambiar o anular la venta; reaccionar a la cita cancelada o agendada. |
| `ServiceController.php`, `Service.php`, `Tenant.php` | Cuánto es el adelanto de cada servicio. |
| `TenantSettingController.php`, `AppointmentFlow.php`, `config/app.php` | Reglas `payments.link_hours`, `booking.deposit_minutes`, `booking.deposit_refund_hours`; dirección de la web para los links. |
| `apps/web/src/features/payments/components/PayPage.tsx`, `app/pay/[token]` | Nuevo. La página de pago. |
| `apps/web/src/features/settings/components/IntegrationsSettings.tsx`, `TenantSettings.tsx` | Nuevo. Integraciones y la sección de adelantos. |
| `ChargePanel.tsx`, `SaleDetailPanel.tsx`, `sales.ts` | Link de pago, QR, adelanto por devolver. |
| `BookingFlow.tsx`, `Portal.tsx`, `PortalAppointmentCard.tsx`, `portal.ts` | Adelanto en el portal. |
| `FormService.tsx`, `PointOfSale.tsx`, `pos.ts`, `rules.ts` | Adelanto del servicio; adelanto en "Por cobrar"; texto del editor de rubros. |

## 10. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API:**

| Caso | Resultado |
|---|---|
| Link desde una cita | "Esperando pago", S/ 60.00, vence en 24 h; otro link para la misma venta se rechaza |
| Simular rechazado / aprobado | Sigue esperando con el aviso / venta pagada con link y boleta B001; aviso a quien lo creó |
| Pagar otra vez / el mismo aviso repetido | "Este link ya no está esperando pago." / "Este aviso ya se había recibido" |
| Aviso de la pasarela con firma falsa / firmado | Rechazado (400) / venta pagada |
| Cobrar en la sede con un link esperando | Pagada; el link queda anulado: "Se cobró en la sede." |
| Pago que llega para un link anulado | Se devuelve automáticamente |
| Link vencido | "Vencido"; se puede crear otro; anularlo deja el motivo "Lo anuló Carlos Mendoza." |
| Portal: servicio con adelanto | S/ 60.00 (30 %); reservar responde con el link y **no crea la cita** |
| Recepción agenda ese horario | El link de la clienta queda anulado: "Ese horario se acaba de ocupar." |
| Pagar el adelanto | Cita confirmada, origen "en línea", venta con S/ 60 pagado y S/ 140 por cobrar; aviso a la administración |
| Cancelar a tiempo / devolver por la pasarela | "Adelanto por devolver" y aviso / venta anulada: "se devolvió el adelanto" |
| Cancelar tarde (con 120 h configuradas) | Venta pagada de S/ 60 con "Adelanto retenido…" y boleta |
| Servicio con 150 % o con monto mayor que el precio | Rechazado con su explicación |
| Integraciones | Culqi sin llaves se rechaza; con llaves quedan cifradas y enmascaradas; la pasarela en uso sigue siendo la simulación |
| QR | PNG aceptado; un archivo que no es imagen se rechaza; el cobro trae el QR; Recepción no puede cambiar la pasarela |

**En el navegador, con clics reales** (dueña de Bella Aura y la clienta Andrea):

| Prueba | Resultado |
|---|---|
| Configuración: Culqi con llaves | "Guardamos las llaves de Culqi. Todavía no se cobra con Culqi…"; placeholders "••••••••5b4a" |
| Volver a Simulación | "Listo: los links de pago usan la simulación." |
| Subir un QR de Yape con titular | Se ve la imagen; "QR de Yape guardado" |
| Cambiar los minutos para pagar a 20 y guardar | "Configuración guardada." |
| Servicio: monto fijo | El ejemplo pasa a "paga S/ 50.00… y S/ 150.00 en la sede" |
| Servicio: adelanto de S/ 250 | "El adelanto no puede ser mayor que el precio del servicio." (se ve en el formulario) |
| Cobro con Yape | Muestra el QR con el titular y el monto |
| Link de pago → Crear → Abrir | La página de pago de prueba con S/ 130.00 |
| Simular rechazado y luego aprobado | "El pago fue rechazado…" / "¡Pago recibido!"; la cita queda "Cobrada · Boleta B001-00000001" |
| Portal: reservar Coloración | "Se reserva pagando un adelanto de S/ 50.00"; aviso de los 20 minutos; botón "Pagar adelanto de S/ 50.00" |
| Salir sin pagar → Inicio | "Tienes un adelanto por pagar · S/ 50.00…"; "Pagar" lleva a la página; al aprobar: "Tu cita C-0245 quedó reservada y confirmada" |
| "Ya no quiero" | "Listo: no se reservó esa cita y no se te cobró nada." |
| Otra persona toma el horario y la clienta intenta pagar | "Este link ya no está disponible · Ese horario se acaba de ocupar · No se te cobró nada" |
| Cancelar una cita de hoy | "El adelanto de S/ 50.00 quedó para la sede…" |
| Cancelar una del martes | El aviso previo dice "Se te devolverá el adelanto de S/ 50.00"; después, "la sede te devolverá el adelanto" |
| Empresa: campana y Ventas | "Adelanto por devolver…"; la venta dice "Adelanto por devolver"; "Devolver" con "Por la pasarela" ya elegido → "Anulada: La cita se canceló a tiempo: se devolvió el adelanto." |
| Avisos de la pasarela | Los cuatro avisos, con su resultado |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salieron y se corrigieron: el link no se anulaba al instante cuando otra persona agendaba el horario (solo al abrirlo), por una diferencia de zona horaria; el motivo se repetía dos veces en la página de pago; el aviso de cancelar no decía antes si se pierde el adelanto; el formulario de servicios no mostraba los errores; la lista de Ventas decía "Pago parcial" en vez de "Adelanto por devolver"; el "link actual" a veces mostraba uno vencido.
- **No se probó en pantalla:** la página de pago en celular, "Anular link" desde el panel, el adelanto en la pestaña "Por cobrar" del punto de venta (sí por la API), "No asistió" con adelanto (se probó "cancelar tarde"), tema oscuro.

## 11. Lo que la simulación no prueba

| | ¿Probado? |
|---|---|
| Qué hace el sistema con un pago aprobado, rechazado, repetido, tardío o de un link vencido | Sí |
| Que el dinero llegue a la cuenta de la empresa y que la devolución vuelva a la tarjeta | No: hace falta elegir proveedor, su documentación y una cuenta de pruebas |
| Que el link llegue al celular del cliente | No: se copia y se pega en WhatsApp hasta la entrega 6 (Mensajes) |

## 12. Para aplicar

Columnas y tablas nuevas: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`. Ese reset trae también la moneda y el carrito.
