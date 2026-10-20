# 2026-10-18 — Ventas, entrega 6a: mensajes al cliente por correo

## Contexto

Hasta hoy el sistema no le enviaba nada al cliente: todo aviso quedaba dentro del portal y los links de pago se copiaban a mano. Antes de programar se revisó el prototipo. Trae tres pantallas: "Mensajes y notificaciones", "Campañas y segmentos" y "Automatizaciones". Se acordó hacer Marketing en tres partes: **6a mensajes** (esta), 6b campañas y 6c automatizaciones.

Lo decidido:

| Pregunta | Respuesta |
|---|---|
| ¿Desde qué correo se envía? | **Cada empresa configura el suyo** (Gmail, Outlook, Brevo o el de su dominio) antes de que salga cualquier correo. No hay un correo de la plataforma |
| ¿Qué medio pedirán los jefes? | No se sabe: queda configurable. Casi todos los servicios se conectan igual (SMTP), así que sirve para cualquiera |
| Mientras no se configure | No sale nada. Los avisos de un hecho quedan como "No enviado: tu empresa aún no configura su correo" y no salen después (para no mandar avisos atrasados) |
| WhatsApp | Listo para conectar; siempre necesita un proveedor con costo |
| ¿Mensajes programados? | Sí: "Nuevo mensaje" a un cliente, para ahora o para un día y una hora |
| Probar en local sin riesgo | Modo "Probar sin enviar": los mensajes se arman y se ven, pero no salen |

## 1. Configuración → Correo de tu empresa

Tres opciones:
- **Sin configurar** (por defecto): no sale ningún correo.
- **Probar sin enviar**: los mensajes se arman y quedan en la bandeja como "Simulado".
- **Con mi correo**: los correos salen de verdad.

Con "Con mi correo":
- **Botones de ayuda** Gmail, Outlook y Brevo: llenan el servidor y el puerto y explican qué contraseña usar. Ejemplo: "Usa una contraseña de aplicación de tu cuenta de Google, no tu contraseña normal".
- **Datos:** servidor, puerto, seguridad (TLS 587 o SSL 465), usuario, contraseña (guardada cifrada, se muestra como "••••••••ta"), "Se envía desde", "Nombre que ve el cliente" y "Las respuestas llegan a".
- **"Enviar prueba"** a un correo que elige la empresa. Si falla, lo dice sin jerga: "No se pudo conectar con el servidor de correo. Revisa el servidor y el puerto, y que haya internet."
- **Estado visible arriba:** "Sin configurar", "Probar sin enviar", "Configurado, falta enviar un correo de prueba", "Funcionando: los correos salen desde…" o "La última prueba falló".

En la misma tarjeta, WhatsApp aparece como "Listo para conectar".

## 2. Configuración → Mensajes al cliente

Los avisos que salen solos, de a 6. Cada uno ya viene escrito, con su interruptor y un lápiz para cambiarlo:

| Aviso | Cuándo sale |
|---|---|
| Confirmación de reserva | Al agendar (también desde el portal). No sale si la cita empieza dentro de la próxima hora |
| Recordatorio de cita | 24 h antes, configurable. Hay un segundo de 2 h antes, apagado. No sale si la cita se agendó dentro de ese plazo |
| Cita cancelada / reprogramada | Al momento. Si se cancela, el recordatorio pendiente se cancela |
| Comprobante de pago | Al emitirse la boleta o factura |
| Link de pago | Cuando el personal pulsa "Enviar por correo" |
| Te queda 1 sesión | Al descontarse la penúltima sesión de un paquete |
| Tu paquete vence pronto | 7 días antes, si tiene sesiones sin usar |
| Se liberó un horario | A quien está en la lista de espera y le sirve |
| Control por vencer | 3 días antes del control |
| Acceso al portal | Al darle acceso o restablecer su contraseña |

El panel de cada aviso:
- **Interruptor** para encenderlo o apagarlo, y las horas o días cuando corresponde.
- **Asunto y mensaje** editables.
- **Botones "+ Nombre del cliente", "+ Fecha", "+ Hora"…**: insertan el dato donde está el cursor, entre corchetes ([Nombre]). No hay que escribir códigos.
- **Vista "Así le llega (con datos de ejemplo)"**, que se actualiza al escribir.
- **"Volver al texto original"** si el texto fue cambiado.

Ejemplo de confirmación ya armada:

> Hola Gloria:
>
> Tu cita de Punción seca quedó agendada para el viernes 23 de octubre a las 8:30 a. m. con Lic. Paola Vega, en Sede SMP - Universitaria.
>
> Código de tu cita: C-0493.

## 3. Bandeja "Mensajes" (grupo Fidelización)

- **Arriba:** el estado del correo de la empresa, con "Configurar el correo" y **"Revisar ahora"**.
- **Tarjetas que filtran:** Enviados, Programados, Por enviar, Con problemas.
- **Tabla de a 6:** fecha, cliente (con enlace), mensaje y tipo, estado (Programado, Por enviar, Enviado, Simulado, Falló, No enviado, Cancelado) con el motivo cuando hay problema. Filtros por tipo, estado y fecha, y orden.
- **Detalle de cada mensaje:** el texto completo, a quién, por qué y quién lo escribió. Botones según el caso: "Cambiar texto o fecha", "Cancelar envío", "Reintentar".
- **Si un envío falla**, se reintenta solo dos veces más, a los 5 y a los 10 minutos. Después queda "Falló" con el motivo.

## 4. Nuevo mensaje (ahora o programado)

Desde la bandeja o desde la página del cliente:
- **Cliente:** muestra a qué correo se envía, o avisa si no tiene.
- **"Empezar desde":** En blanco, Resultados listos, Te toca tu control, Cambio de horario.
- **Asunto y mensaje.**
- **"¿Cuándo sale?":** Ahora o Programar, con los campos propios de día y hora.

Un mensaje programado se puede cambiar o cancelar mientras no salga. Sale a su hora en el servidor; en local, cuando alguien usa el panel o con "Revisar ahora".

## 5. Envíos desde el cobro y las ventas

- **Panel de cobro → link de pago → "Enviar por correo"**: le llega "Puedes pagar S/ 60.00 por… desde este link".
- **Ventas → detalle → "Enviar comprobante por correo"** (reenvío manual).

El aviso dice qué pasó: "Se envió el comprobante a…", "Simulado: … no salió porque tu empresa está en Probar sin enviar", o el motivo si no se pudo.

## 6. Promociones: consentimiento del cliente

- **Página del cliente:** tarjeta **Mensajes** con "Nuevo mensaje", los interruptores "Acepta recibir promociones por correo" y "…por WhatsApp" (con la fecha en que aceptó o lo retiró, y si fue desde el portal) y sus últimos mensajes de a 6.
- **Portal → Mis datos:** "Promociones", con los mismos dos interruptores.

Los avisos de su cita, pagos y paquetes le llegan igual: el consentimiento cuenta solo para las campañas (6b), como pide la ley de protección de datos personales.

## 7. Cuándo salen los mensajes

- **Al momento:** al guardarse algo (una cita, un pago), el mensaje se envía cuando termina la respuesta, sin hacer esperar a quien lo hizo.
- **En el servidor:** una tarea `messages:send` corre cada 5 minutos (ya registrada en `routes/console.php`). Arma los recordatorios, los vencimientos de paquetes y los controles, y envía lo pendiente y lo programado. Hay que activar el programador de tareas del servidor (cron de Laravel).
- **En local**, sin esa tarea: se revisa al usar el panel (con la campana) y con "Revisar ahora".
- **Los avisos programados se arman solo con un correo configurado.** Así, si la empresa configura su correo hoy a las 3 p. m., las citas de mañana igual reciben su recordatorio.

## 8. Correos de ejemplo

`seed_demo.sql` tenía tres clientes con correos que podían ser reales: `juliana.diaz@gmail.com`, `sofia.valverde@outlook.com` y `carlos.ramirez@yahoo.es`. Ahora son `@example.com`, que no llega a nadie, para que una prueba en local con un correo real no le escriba a nadie de verdad.

**La clienta de prueba del portal ahora entra con `juliana.diaz@example.com`.**

## 9. Permisos

Pantalla nueva **Mensajes a clientes**:

| Permiso | Qué permite | Nace en |
|---|---|---|
| Ver | La bandeja y los mensajes de cada cliente | Administrador, Gerente, Recepción, Caja |
| Crear | Escribir un mensaje, ahora o programado | Administrador, Gerente, Recepción |
| Editar | Cambiar o cancelar un programado y reintentar | Administrador, Gerente, Recepción |

Configurar el correo y los avisos usa el permiso de Configuración. No depende de ningún módulo del plan: los avisos de citas son básicos.

## 10. Base de datos

- Nueva `messages`: cada mensaje, con su estado, intentos, error, programación y una clave para no repetirlo.
- Nueva `message_settings`: lo que cada empresa cambia de cada aviso.
- `tenant_integrations` admite `email` y `whatsapp`.
- Se usa `customer_consents`, que ya existía.

## 11. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Messaging.php` | Nuevo. Avisos y textos por defecto; armar, guardar y enviar por SMTP; simulación; reintentos; errores sin jerga; avisos de cita, comprobante, paquete, lista de espera y portal; recordatorios y vencimientos programados. |
| `apps/api/app/Http/Controllers/Api/MessageController.php` | Nuevo. Bandeja, nuevo/cambiar/cancelar/reintentar, revisar ahora, configuración del correo y de los avisos, prueba, enviar link y comprobante, consentimientos. |
| `AppointmentController.php`, `PaymentLinks.php`, `Invoicing.php`, `Packages.php`, `Waitlist.php`, `CustomerController.php` | Avisos al agendar, cancelar, reprogramar, pagar adelanto, emitir comprobante, descontar la penúltima sesión, liberar un horario y dar acceso al portal. |
| `NotificationController.php`, `routes/console.php` | Revisión al usar el panel; tarea `messages:send` cada 5 minutos. |
| `PortalController.php`, `routes/api.php`, `Access.php` | Consentimientos en el portal, rutas y permisos. |
| `Packages.php` | El vencimiento del paquete se guardaba a las 23:59 en hora UTC, no a las 23:59 de Lima: corregido. |
| `apps/web/src/features/messages/*` | Nuevo. Bandeja, detalle, nuevo mensaje y tarjeta del cliente. |
| `features/settings/components/MessagingSettings.tsx` | Nuevo. Correo de tu empresa y Mensajes al cliente. |
| `TenantSettings.tsx`, `IntegrationsSettings.tsx` | Las tarjetas nuevas en Configuración. |
| `ChargePanel.tsx`, `SaleDetailPanel.tsx` | "Enviar por correo" del link y del comprobante. |
| `CustomerDetail.tsx`, `Portal.tsx`, `portal.ts` | Tarjeta Mensajes del cliente; Promociones en el portal. |
| `Sidebar.tsx`, `ScreenGate.tsx`, `app/(dashboard)/messages` | Menú y página. |
| `init.sql`, `seed_demo.sql` | Esquema y correos de ejemplo. |

Código sin comentarios.

## 12. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó. **No se configuró ningún correo real**: se probó con "Probar sin enviar" y con un servidor inventado para ver los errores.

**Por la API** (Fisioterapia Vital):
- **Sin correo configurado:** la cita C-0493 dejó su confirmación "No enviado: tu empresa aún no configura su correo".
- **Con simulación:**
  - La confirmación salió "Simulado" con el texto armado.
  - Al cancelar la cita llegó "Tu cita del sábado 24 de octubre se canceló".
  - El recordatorio se armó para una cita a 20 horas y no se repitió al revisar de nuevo.
  - El aviso apagado no generó mensaje y el texto propio se usó.
- **Programado:** se creó, se cambió y se canceló ("Lo canceló Jorge Salas"). La hora pasada se rechaza; al vencer, salió.
- **Con un servidor inventado:** la prueba responde "No se pudo conectar con el servidor de correo…". El envío quedó "Por enviar" con 1 intento y el motivo.
- **Comprobante:** el reenvío armó "Tu comprobante B001-00000001" con el detalle y el total.
- **Consentimientos:** se guardan con la fecha y si fueron del personal o del portal.

**En pantalla, con clics reales:**
- **Configuración:** "Con mi correo" → Gmail (llenó servidor y puerto, y mostró la ayuda) → "Probar sin enviar" → Guardar. El recordatorio se editó: 20 h, un dato insertado con su botón, vista previa con datos de ejemplo, "Volver al texto original". Funcionaron el interruptor de un aviso y la paginación de la lista.
- **Mensajes:** "Revisar ahora" (21 recordatorios simulados). "Nuevo mensaje" con clienta, "Resultados listos" y programado con el calendario propio; después se cambió el asunto desde su detalle y se canceló.
- **Página de la clienta:** la tarjeta Mensajes y el interruptor de promociones.
- **Ventas:** "Enviar comprobante por correo".

**Arreglado durante la prueba:**
- Los recordatorios armados antes de configurar el correo quedaban "No enviado" para siempre: ahora se arman solo con correo configurado.
- Un mensaje programado a las 9:00 se guardaba como 9:00 UTC (salía a las 4:00 a. m.). Lo mismo pasaba con el vencimiento de los paquetes: corregido.
- El aviso de "Revisar ahora" decía "enviados" en modo de prueba: ahora dice "simulados (no salieron)".

**No se probó:**
- Un envío por un correo real.
- El link de pago por correo con clics (sí por la API).
- "Mis datos" del portal en pantalla.
- La tarea programada corriendo en un servidor.
- Tema oscuro y celular.

## 13. Para aplicar

Tablas nuevas y correos de ejemplo cambiados: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`. Para probar sin enviar nada: Configuración → Correo de tu empresa → "Probar sin enviar" → Guardar.
