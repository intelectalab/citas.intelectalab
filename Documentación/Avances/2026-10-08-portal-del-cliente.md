# 2026-10-08 — Portal del cliente: reservar, ver, confirmar, cambiar y cancelar citas

## Contexto

El portal del cliente (`/portal`) solo tenía una pantalla de bienvenida. Ahora el cliente ve sus citas y, si el plan de su empresa incluye "Reserva online y portal", reserva en línea, confirma su asistencia, cambia la fecha y cancela.

No hay cambios de esquema.

## 1. Qué ve el cliente

El portal tiene tres secciones, pensadas primero para celular:

| Sección | Contenido |
|---|---|
| Inicio | Saludo, su próxima cita con sus acciones, el botón "Reservar una cita", y sus datos: nombre, código, celular (lo puede cambiar) y sus mascotas o dependientes si el rubro los usa. |
| Reservar | La reserva paso a paso. Solo aparece si la empresa ofrece reserva en línea. |
| Mis citas | Próximas y anteriores. En cada próxima: "Confirmar asistencia", "Cambiar fecha" y "Cancelar", según lo que aún se pueda hacer. |

### Reservar, paso a paso

| Paso | Cuándo aparece | Detalle |
|---|---|---|
| ¿Para quién es la cita? | Solo en rubros con mascotas o dependientes | Elige una o registra otra ahí mismo (nombre y especie). Si el rubro la exige, no se puede seguir sin elegir. |
| ¿Qué servicio necesitas? | Siempre | Los servicios que se reservan en línea, con duración y precio. Los que piden adelanto se ven, pero no se pueden elegir: indican que se agendan en la sede. |
| ¿En qué sede? | Si hay más de una | |
| ¿Con quién? | Si la empresa deja elegir y hay más de un profesional | "Cualquiera disponible" o uno en particular. |
| ¿Cuándo? | Siempre | Siete días a la vista, con flechas para avanzar. Las horas libres del día, separadas en mañana, tarde y noche. Si el día no tiene horarios, un botón busca el próximo día que sí tenga. |
| Revisa y confirma | Siempre | Resumen, un comentario opcional y el plazo para cambiar o cancelar. |

Al terminar muestra la fecha, el código de la cita y si quedó pendiente o confirmada.

"Cambiar fecha" usa el mismo flujo, solo con los pasos de cuándo y confirmar. Mantiene el servicio, la sede y, si tiene horario, al mismo profesional.

## 2. Reglas de la reserva en línea

| Regla | Efecto | Valor si nadie la define |
|---|---|---|
| Módulo `booking` del plan | Sin él, el cliente solo ve sus citas: no reserva, no cambia ni cancela en línea. | Según el plan |
| `booking.enabled` | La empresa puede apagar la reserva en línea aunque su plan la incluya. | Encendida |
| `booking.min_lead_minutes` | No se ofrecen horarios que empiecen antes de ese margen. | 60 minutos |
| `booking.max_days_ahead` | Hasta cuántos días adelante se puede reservar. | 45 días |
| `booking.choose_professional` | Si se apaga, el cliente no elige: se le asigna el primero libre. | Encendida |
| `booking.auto_confirm` | La reserva nace confirmada; si no, pendiente hasta que el cliente o la sede la confirmen. | Apagada |
| `booking.require_deposit` | Los servicios que piden adelanto no se reservan en línea. | Encendida |
| `cancel.client_allowed` | El cliente puede cancelar desde el portal. | Encendida |
| `cancel.window_hours` | Hasta cuántas horas antes puede cambiar o cancelar. | 12 horas |
| `reschedule.max_times` | Cuántas veces puede cambiar una misma cita. | 2 |

Además rigen las reglas de agenda de siempre: horario del profesional, bloqueos, pausas entre citas, citas que se cruzan, mascota obligatoria y límite de citas del plan.

Lo que el cliente no ve:

- Solo alcanza sus propias citas. La de otra persona, para él, no existe.
- No ve notas internas, tiempos de atención, fichas ni motivos de sobre-agenda.
- Cuando un horario no sirve, el aviso no nombra otras citas ni detalles de la agenda: dice que ya no está disponible, que está muy cerca o que es demasiado lejos.
- Si la empresa llegó al límite de citas de su plan, se le dice que no hay cupos en línea y que llame a la sede.

Una cita reservada en el portal queda con origen "en línea" y su historial lo indica ("Reserva en línea", "Confirmada por el cliente", "Reprogramada por el cliente", o el motivo de la cancelación).

## 3. Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/Api/PortalController.php` | Nuevo. Todo el portal. Extiende al controlador de citas para usar exactamente las mismas reglas de agenda. |
| `app/Http/Middleware/EnsureCustomerAccount.php` | Nuevo. Las rutas del portal solo aceptan cuentas de cliente con su ficha vinculada. |
| `app/Http/Controllers/Api/AppointmentController.php` | Sus ayudantes pasan de privados a protegidos para que el portal los reutilice. No cambia su comportamiento. |
| `app/Support/AppointmentScheduler.php` | Modo "en línea": además exige que el servicio se reserve en línea y aplica la anticipación mínima y máxima. |
| `app/Support/AppointmentFlow.php` | Valores por defecto de las reglas de reserva en línea. |
| `bootstrap/app.php`, `routes/api.php` | Middleware `customer` y rutas `/api/portal/*`. |

### Rutas (cuentas de cliente)

| Ruta | Efecto |
|---|---|
| `GET /api/portal/overview` | Sus datos, las reglas de la reserva, sus mascotas y sus citas. |
| `PUT /api/portal/profile` (`phone`) | Cambia su celular. |
| `POST /api/portal/dependents` | Registra una mascota o dependiente. |
| `GET /api/portal/catalog` | Sedes, servicios y profesionales que se reservan en línea. |
| `GET /api/portal/availability` | Horas libres de un día. |
| `POST /api/portal/appointments` | Reserva. |
| `POST /api/portal/appointments/{id}/confirm`, `/cancel`, `/reschedule` | Confirma, cancela o cambia la fecha de una cita propia. |

Las cuatro últimas filas exigen el módulo `booking`.

### Frontend

| Archivo | Contenido |
|---|---|
| `features/portal/portal.ts` | Tipos del portal. |
| `features/portal/components/Portal.tsx` | El portal: cabecera con la empresa, secciones, inicio y mis citas. |
| `features/portal/components/BookingFlow.tsx` | La reserva paso a paso y el cambio de fecha. |
| `features/portal/components/PortalAppointmentCard.tsx` | La tarjeta de una cita con sus acciones. |
| `app/(portal)/portal/page.tsx` | Usa el portal nuevo en lugar de la bienvenida. |

## 4. Verificación

- Pruebas de la API contra una base temporal (eliminada al terminar), con la cuenta de cliente del seed:

| Caso | Resultado |
|---|---|
| El personal entra al portal; la clienta entra al panel de la empresa | 403 en ambos |
| Resumen de la clienta: sus datos, las reglas y sus citas | Correcto |
| Catálogo: marca el servicio que pide adelanto | Correcto |
| Horarios del lunes; con más de 45 días de anticipación no ofrece ninguno | Correcto |
| Reservar con un profesional: pendiente, origen "en línea", sin campos internos en la respuesta | Correcto |
| Reservar sin elegir profesional: se asigna uno libre | Correcto |
| Mismo horario otra vez, fuera de horario | 409 |
| Dentro de 30 minutos, con 60 días de anticipación, fecha pasada | 409, cada uno con su aviso |
| Servicio que pide adelanto | 422 |
| Sede de otra empresa; su otra cuenta (de otra empresa) reserva aquí | 404 |
| Confirmar; confirmar otra vez | Correcto; 422 |
| Al cambiar la fecha, su propia cita no cuenta como ocupada | Correcto |
| Cambiar la fecha dos veces; un tercer cambio | Correcto; 403 |
| Cambiar a un horario imposible | 409, la cita sigue como estaba |
| Cancelar con motivo: queda "cancelada por el cliente" en la cita y en el historial | Correcto |
| Cancelar o cambiar la cita de otra persona | 404 |
| Cancelar o cambiar a 5 horas de la cita (plazo de 12) | 403 |
| Con la cancelación en línea apagada | 403 |
| Con confirmación automática: nace confirmada | Correcto |
| Sin poder elegir profesional: los horarios vienen sin nombres | Correcto |
| Reserva en línea apagada, o sin el módulo en el plan: no hay catálogo ni reserva; sigue viendo sus citas, sin acciones | Correcto |
| Celular inválido; celular válido | 422; se guarda |
| Registrar mascota en un rubro que no las usa | 403 |
| Rubro con mascota obligatoria: reservar sin ella da 422; registrar una y reservar con ella funciona | Correcto |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| Ingresar con la cuenta de cliente (elige su empresa entre dos) y llegar al portal | Correcto |
| Inicio: saludo, "No tienes citas próximas", sus datos y las tres secciones | Correcto |
| Reservar: elegir servicio, "Cualquiera disponible", ver los días y las horas | Correcto |
| Elegir un domingo: "No hay horarios libres"; el botón encuentra el lunes siguiente con 38 horarios | Correcto |
| Elegir hora, revisar el resumen y reservar: muestra fecha, médico asignado y código | Correcto |
| Mis citas: la cita pendiente con sus tres acciones; "Confirmar asistencia" la deja confirmada | Correcto |
| "Cambiar fecha": dos pasos; al terminar la cita aparece en el nuevo día | Correcto |
| Inicio: muestra esa cita como la próxima | Correcto |
| Cambiar el celular | Correcto |
| "Cancelar": pide confirmar; "No, mantenerla" la conserva; "Sí, cancelar" la pasa a anteriores con su motivo | Correcto |
| El paso de fecha y hora en ancho de celular (390 px): sin desbordes | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó en pantalla: el paso de mascotas, varias sedes, un servicio con adelanto, una empresa sin reserva en línea, ni que la reserva del portal aparezca en la agenda del personal (la agenda lee las mismas citas, pero no se abrió a la vez). Un texto ("Ahora tu cita es el...") se corrigió después de las pruebas.

## 5. Para aplicar

Requiere el esquema de las entregas de citas. Para entrar al portal hace falta una cuenta de cliente: la del seed, o una creada con "Crear cuenta de cliente" y el código de la empresa.
