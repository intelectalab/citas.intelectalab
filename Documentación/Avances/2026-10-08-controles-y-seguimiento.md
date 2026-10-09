# 2026-10-08 — Controles y seguimiento: pantalla, agendar desde un control y control por servicio

## Contexto

"Controles y seguimiento" estaba en el menú y llevaba a la página provisional. Los datos ya se guardaban: al firmar una ficha con "próximo control", quedaba un control pendiente en `recalls`. No había dónde verlos ni cómo convertirlos en cita.

Ahora tiene su pantalla (`/recalls`), y los controles siguen a las citas: se agendan desde ahí, se cierran solos al atender y vuelven a la lista si la cita se cae.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. La pantalla (`/recalls`)

| Parte | Detalle |
|---|---|
| Pestañas | Por agendar, Con cita y Cerrados. |
| Tarjetas (en Por agendar) | Por agendar, Vencidos, Hoy y próximos 7 días, Más adelante y Ya avisados. Filtran la tabla. |
| Búsqueda | Por el control o por nombre, documento, celular o código del cliente, sin distinguir tildes. |
| Filtros | Servicio y profesional. |
| Columnas | Cliente (enlace a su página), control con su servicio y notas, para cuándo ("Venció hace 6 días", "En 4 días"), origen, estado y acciones. |
| Botón principal | "Nuevo control". |

### Acciones de un control por agendar

| Acción | Efecto |
|---|---|
| Agendar | Abre "Nueva cita" con el cliente, el servicio, el profesional y la fecha del control ya puestos. Si el control ya venció, propone hoy. Al guardar, el control pasa a "Con cita". |
| Teléfono | Marca que ya se le avisó (y lo desmarca). Sirve mientras no haya envío de mensajes. |
| Check | Lo da por realizado: el cliente ya se atendió sin pasar por aquí. |
| Lápiz | Cambia para qué es, la fecha, el servicio, el profesional o las notas. |
| X | Lo descarta, con un motivo opcional. Pide confirmación. |

En "Con cita", el ojo abre la cita. En "Cerrados", la flecha lo devuelve a "Por agendar".

### Panel "Nuevo control"

Cliente (con buscador), mascota o dependiente si el rubro los usa, para qué es, para cuándo (con atajos: 1 semana, 1 mes, 3 meses, 6 meses, 1 año), servicio con el que se agenda, profesional y notas.

## 2. De dónde salen los controles

| Origen | Cuándo se crea |
|---|---|
| Ficha de atención | Al firmar una ficha con el campo "próximo control" lleno. Ya existía. |
| Lo pide el servicio | **Nuevo.** Al dar por atendida una cita cuyo servicio tiene "control de seguimiento" (por ejemplo, "Profilaxis en 180 días"). Antes ese dato del servicio se guardaba sin usarse. |
| Registrado a mano | Desde "Nuevo control". |

Una atención deja un solo control: si el servicio y la ficha indican uno cada uno, queda el de la ficha (con su fecha).

## 3. Cómo siguen a las citas

| Pasa esto | El control |
|---|---|
| Se agenda una cita desde el control | Queda "Agendado" y enlazado a la cita. |
| La cita se atiende | Queda "Realizado". |
| La cita se cancela (el personal, o el cliente desde su portal) o el cliente no asiste | Vuelve a "Por agendar". |
| Se intenta descartar o cambiar un control con cita | No se puede: primero se atiende o se cancela la cita. |

## 4. Quién ve qué

| Usuario | Ve |
|---|---|
| Administrador y personal de la empresa | Todos los controles. |
| Profesional (sin ser administrador) | Solo los suyos. Los que registra quedan a su nombre. |

La pantalla depende del módulo "Fichas de atención e historia", igual que su ítem del menú.

## 5. Esquema

| Tabla | Cambio |
|---|---|
| `recalls` | Columnas nuevas: `source_appointment_id` (la atención que lo originó), `notes`, `closed_reason` y `created_by`. |

El seed trae tres controles de ejemplo en Centro Médico Integral, con fechas relativas: uno vencido, uno para esta semana y uno más adelante.

## 6. Cambios realizados

### Backend

| Archivo | Contenido |
|---|---|
| `app/Models/Recall.php` | Nuevo. Estados y relaciones. |
| `app/Support/Recalls.php` | Nuevo. Cómo se mueven los controles con las citas y el control que deja el servicio. |
| `app/Http/Controllers/Api/RecallController.php` | Nuevo. Lista, alta, edición y cambio de estado. |
| `app/Http/Controllers/Api/AppointmentController.php` | La cita acepta `recall_id`; al cambiar de fase avisa a los controles. |
| `app/Http/Controllers/Api/PortalController.php` | La cancelación del cliente también libera el control. |
| `app/Http/Controllers/Api/CareRecordController.php` | El control de la ficha guarda la cita de origen y reutiliza el del servicio en vez de duplicarlo. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `records`. |

### Rutas (personal de la empresa, módulo `records`)

| Ruta | Efecto |
|---|---|
| `GET /api/recalls` (`view=open\|booked\|closed`, `search`, `service_id`, `professional_id`, `customer_id`) | Lista. |
| `POST /api/recalls` | Registra un control a mano. |
| `PUT /api/recalls/{id}` | Cambia uno que sigue abierto. |
| `POST /api/recalls/{id}/status` (`status`, `reason`) | Avisado, por agendar, realizado o descartado. |
| `POST /api/appointments` (`recall_id`) | La cita cumple ese control. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/recalls/recalls.ts` | Tipos, colores y textos de vencimiento. |
| `features/recalls/components/RecallsTable.tsx` | La pantalla. |
| `features/recalls/components/FormRecall.tsx` | Panel para registrar y editar. |
| `app/(dashboard)/recalls/page.tsx` | La página. |
| `features/appointments/components/AppointmentForm.tsx`, `useAppointmentPanels.tsx` | "Nueva cita" puede abrir con el cliente y el servicio ya elegidos. |
| `app/components/layout/Sidebar.tsx`, `lib/modules.ts` | El ítem lleva a `/recalls` y sale de las secciones provisionales. |

## 7. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base, con Centro Médico Integral:

| Caso | Resultado |
|---|---|
| Lista por agendar: los tres del seed, con los días que faltan o que llevan vencidos | Correcto |
| Buscar "presion" (sin tilde) y por nombre del cliente; filtro por profesional | Correcto |
| Un profesional: solo los suyos | Correcto |
| Otra empresa | Lista vacía |
| Una clienta del portal | 403 |
| Registrar a mano | Correcto |
| Sin para qué, fecha inválida, sin cliente | 422, cada uno con su aviso |
| Cliente de otra empresa; otra empresa edita | 404 |
| Editar fecha, nombre y servicio | Correcto |
| Un profesional registra: queda a su nombre; no edita uno ajeno | Correcto; 403 |
| Avisado; volver a por agendar; descartar con motivo; reabrir | Correcto |
| Editar uno cerrado; poner "agendado" a mano | 422 |
| Agendar con el control de otra persona | 422 |
| Agendar desde el control: queda agendado y enlazado | Correcto |
| Agendar otra vez el mismo control; descartarlo teniendo cita | 422 |
| Se cancela la cita: el control vuelve a por agendar | Correcto |
| Se agenda de nuevo y se atiende: el control queda realizado | Correcto |
| La clienta cancela desde su portal: su control vuelve a por agendar | Correcto |
| Cita de un servicio con control, atendida: nace "Control de crecimiento" a 30 días, una sola vez | Correcto |
| Luego la ficha de esa atención indica otra fecha: se actualiza ese mismo control, sin crear otro | Correcto |

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| El menú abre `/recalls` con los tres controles y su urgencia | Correcto |
| Tarjetas "Vencidos", "Más adelante" y "Ya avisados" filtran; "Por agendar" muestra todo | Correcto |
| Teléfono: marca "Avisado" y lo desmarca | Correcto |
| Buscar "presion" | Encuentra "Control de presión arterial" |
| "Agendar": abre "Nueva cita" con el cliente, el servicio, el profesional y hoy como fecha (estaba vencido); muestra horarios libres | Correcto |
| Elegir una hora y guardar: el control sale de "Por agendar" y aparece en "Con cita" con la fecha y el código | Correcto |
| Ojo en "Con cita": abre la cita; cancelarla con motivo devuelve el control a "Por agendar" | Correcto |
| Descartar: "No, mantenerlo" lo conserva; con motivo y "Sí, descartar" pasa a "Cerrados" | Correcto |
| Check: lo da por realizado y pasa a "Cerrados" | Correcto |
| Reabrir desde "Cerrados" | Vuelve a "Por agendar" |
| "Nuevo control": sin cliente avisa; buscar "sofia", elegirla, atajo "En 3 meses", servicio y notas; se guarda | Correcto |
| Editar: carga sus datos, el título dice de quién es; cambiar nombre y fecha | Correcto |

- Las pruebas de bloqueos y de cuentas de clientes de hoy se repitieron sobre bases limpias después de este cambio: mismos resultados.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se pudo capturar la pantalla. No se probó en pantalla: un rubro con mascotas, la vista de un profesional, un control nacido de una ficha o de un servicio (la API sí), ni el tema oscuro.

## 8. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` (hace falta de todos modos por el cambio de clientes de hoy). Sin las columnas nuevas, firmar una ficha con próximo control y abrir la pantalla fallan.
