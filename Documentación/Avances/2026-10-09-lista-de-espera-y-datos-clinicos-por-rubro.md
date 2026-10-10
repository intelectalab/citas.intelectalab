# 2026-10-09 — Lista de espera y datos clínicos del cliente solo en rubros de salud

## Contexto

Dos cosas:

1. "Lista de espera" era la última pantalla del grupo Agenda que seguía en la página provisional. Solo tenía su tabla en la base.
2. Los datos clínicos del cliente (tipo de sangre) se mostraban en todos los rubros. Pedido: que no se vean donde no sirven.

Los cambios de base están en `seed.sql` (la tabla `waitlist_entries` ya existía en `init.sql`). No hay migraciones.

## 1. Lista de espera (`/waitlist`)

Quién quiere una cita y no encontró horario: qué servicio, con quién, entre qué fechas y a qué horas le sirve.

| Parte | Detalle |
|---|---|
| Pestañas | En espera, Con cita y Cerrados. |
| Tarjetas (en En espera) | En espera, Prioritarios, Les quedan 3 días o menos, Esperan a alguien en particular y Ya avisados. Filtran la tabla. |
| Orden | Primero los prioritarios; después, por orden de llegada. La columna "Puesto" lo muestra. |
| Búsqueda | Por nombre, documento, celular o código del cliente, sin distinguir tildes. |
| Filtros | Servicio, profesional y sede (si hay más de una). |
| Columnas | Puesto, cliente (enlace a su página), qué espera, cuándo le sirve, hace cuánto espera, estado y acciones. |
| Botón principal | "Anotar en la lista". |

### Buscar horario

El botón "Buscar horario" de cada persona abre un panel con los horarios libres **que le sirven**: de su servicio, con el profesional que pidió (o con cualquiera), dentro de sus fechas y de su franja horaria. Muestra hasta cinco días con horarios, revisando hasta tres semanas.

Al elegir una hora se abre "Nueva cita" con el cliente, el servicio, el profesional, la fecha y la hora ya puestos. Al guardar, la persona pasa a "Con cita".

Si no hay nada, el panel dice hasta qué fecha se revisó y con qué condiciones.

### Otras acciones

| Acción | Efecto |
|---|---|
| Teléfono | Marca que ya se le avisó (y lo desmarca). |
| Lápiz | Cambia el servicio, el profesional, las fechas, la franja, la prioridad o las notas. |
| X | La retira de la lista. Pide confirmación. Desde "Cerrados" se puede volver a poner en espera. |
| Ojo (en Con cita) | Abre la cita. |

### Panel "Anotar en lista de espera"

Cliente (con buscador), mascota o dependiente si el rubro los usa, servicio, sede (si hay más de una), profesional o "cualquiera", desde y hasta qué fecha, franja horaria (Cualquier hora, Mañana, Tarde, Noche, o las horas a mano), interruptor "Prioritario" y notas.

### Cómo sigue a las citas

| Pasa esto | La persona |
|---|---|
| Se le agenda una cita desde la lista | Pasa a "Con cita". |
| Esa cita se cancela (el personal o el cliente desde su portal) o no asiste | Vuelve a "En espera", en su puesto. |
| Pasa su última fecha sin conseguir horario | Sale sola de la lista como "Venció". |
| Se intenta retirarla teniendo cita | No se puede: primero se cancela la cita. |

### Desde la agenda

- **Al agendar sin horarios.** En "Nueva cita", si el día elegido no tiene horarios libres, aparece "Anotar en lista de espera": deja a la persona en la lista desde esa fecha, sin salir del panel.
- **Al cancelar una cita.** Si el horario que queda libre le sirve a alguien de la lista, el panel de la cita lo avisa: "Este horario le sirve a 2 personas de la lista de espera".

### Reglas

- La misma persona no puede esperar dos veces el mismo servicio en la misma sede.
- Las clases grupales no entran aquí (van por cupos).
- En rubros con mascota obligatoria, hay que indicar para cuál es.
- Un profesional (sin ser administrador) ve a quienes lo esperan a él o a cualquiera, y solo anota en esas condiciones.

## 2. Datos clínicos del cliente solo en rubros de salud

Regla nueva del rubro: `customers.clinical_fields`. Encendida en consultorio médico, dental, estética, fisioterapia, psicología y veterinaria. Apagada en salón, barbería, fitness y genérico.

| Dato | Rubros de salud | Los demás |
|---|---|---|
| Tipo de sangre | Se muestra | No aparece, y la API lo ignora si llega |
| Sexo | "Sexo biológico" | "Sexo" |
| Observaciones | "Antecedentes y observaciones", con ejemplo de alergias | "Observaciones", con ejemplo de preferencias |

Las observaciones se conservan en todos los rubros con un texto neutro, porque sirven también fuera de salud (por ejemplo, una alergia a un tinte). Si se prefiere ocultarlas del todo, es un cambio pequeño.

La regla aparece en el editor de rubros de la plataforma, en el grupo "Atención", para encenderla o apagarla por rubro.

## 3. Esquema

| Tabla | Cambio |
|---|---|
| `waitlist_entries` | Sin cambios de estructura. |
| `business_types.default_rules` (seed) | `customers.clinical_fields: true` en los seis rubros de salud. |

El seed trae dos personas en lista de espera en Centro Médico Integral: una prioritaria que solo puede por la mañana y otra que espera a una doctora en particular.

## 4. Cambios realizados

### Backend

| Archivo | Contenido |
|---|---|
| `app/Models/WaitlistEntry.php` | Nuevo. Estados y relaciones. |
| `app/Support/Waitlist.php` | Nuevo. Horarios que le sirven a una persona, a quiénes les sirve un horario que se libera, vencimiento y enlace con las citas. |
| `app/Http/Controllers/Api/WaitlistController.php` | Nuevo. Lista, alta, edición, estado y búsqueda de horarios. |
| `app/Http/Controllers/Api/AppointmentController.php` | La cita acepta `waitlist_id`; al cancelar informa a cuántos de la lista les sirve el horario. |
| `app/Http/Controllers/Api/PortalController.php` | La cancelación del cliente también lo devuelve a la lista. |
| `app/Http/Controllers/Api/CustomerController.php` | Ignora el tipo de sangre en rubros sin datos clínicos. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `agenda`. |

### Rutas (personal de la empresa, módulo `agenda`)

| Ruta | Efecto |
|---|---|
| `GET /api/waitlist` (`view=open\|booked\|closed`, `search`, `service_id`, `professional_id`, `branch_id`, `customer_id`) | Lista. |
| `POST /api/waitlist` | Anota a alguien. |
| `PUT /api/waitlist/{id}` | Cambia lo que pidió. |
| `POST /api/waitlist/{id}/status` (`status`) | Avisado, en espera o retirado. |
| `GET /api/waitlist/{id}/slots` | Horarios libres que le sirven. |
| `POST /api/appointments` (`waitlist_id`) | La cita es para esa persona de la lista. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/waitlist/waitlist.ts` | Tipos, colores, franjas y textos de fechas. |
| `features/waitlist/components/WaitlistTable.tsx` | La pantalla y el panel de horarios. |
| `features/waitlist/components/FormWaitlist.tsx` | Panel para anotar y editar. |
| `features/customers/components/CustomerPicker.tsx` | Nuevo. Buscador de clientes para paneles laterales. |
| `app/(dashboard)/waitlist/page.tsx` | La página. |
| `features/appointments/components/AppointmentForm.tsx`, `useAppointmentPanels.tsx` | "Nueva cita" acepta a alguien de la lista y ofrece anotar en la lista cuando no hay horarios. |
| `features/appointments/components/AppointmentDetail.tsx` | Aviso al cancelar si el horario le sirve a alguien de la lista. |
| `features/customers/components/FormCustomer.tsx`, `CustomerDetail.tsx` | Datos clínicos según la regla del rubro. |
| `lib/rules.ts` | La regla nueva en el editor de rubros. |
| `app/components/layout/Sidebar.tsx`, `lib/modules.ts` | "Lista de espera" lleva a `/waitlist`. Ya no queda ninguna sección provisional del grupo Agenda. |

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base, con Centro Médico Integral:

| Caso | Resultado |
|---|---|
| Lista en espera: la prioritaria primero, con sus fechas, franja, días esperando y días que le quedan | Correcto |
| Buscar por cliente; filtro por servicio | Correcto |
| Un profesional: quienes lo esperan a él o a cualquiera | Correcto |
| Otra empresa | Lista vacía |
| Una clienta del portal | 403 |
| Anotar a alguien | Correcto |
| Anotarla otra vez por lo mismo | 409 |
| Sin servicio, sede ni fecha; última fecha antes de la primera; hora final antes de la inicial; rango ya pasado | 422, cada uno con su aviso |
| Cliente de otra empresa; otra empresa edita | 404 |
| Editar: prioritaria, cualquier hora y cualquier médico | Correcto |
| Un profesional anota para otro médico, o edita uno de otro médico | 403 |
| Avisada; quitar el aviso; retirar; volver a la lista | Correcto |
| Editar uno retirado; poner "con cita" a mano | 422 |
| Buscar horario para quien solo puede de 08:00 a 12:00: todos los horarios caen dentro de esa franja, de los dos médicos | Correcto |
| Agendar con el registro de otra persona | 422 |
| Agendar en un horario encontrado: pasa a "Con cita", enlazada a la cita | Correcto |
| Buscar horario o retirar a quien ya tiene cita | 422 |
| Se cancela otra cita: informa que a 1 persona de la lista le sirve ese horario | Correcto |
| Se cancela la cita de quien venía de la lista: vuelve a esperar | Correcto |
| Pasa su última fecha: sale de la lista y queda como vencida; no se puede reabrir | Correcto |
| Rubro médico guarda el tipo de sangre; rubro genérico lo ignora | Correcto |

- Las pruebas de bloqueos, cuentas de clientes y controles se repitieron sobre bases limpias después de estos cambios: mismos resultados.

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| El menú abre `/waitlist` con las dos personas del seed, en su puesto | Correcto |
| Tarjetas "Prioritarios" y "Esperan a alguien en particular" filtran; "En espera" muestra todo | Correcto |
| Teléfono: marca "Avisado" y lo desmarca | Correcto |
| "Buscar horario": cinco días con horarios, todos entre 09:00 y 11:30 para quien pidió mañanas | Correcto |
| Elegir una hora: abre "Nueva cita" con cliente, servicio, médico, fecha y hora; guardar la pasa a "Con cita" | Correcto |
| Ojo en "Con cita": abre la cita; cancelarla la devuelve a "En espera" en su puesto | Correcto |
| Retirar: "No, mantenerlo" la conserva; "Sí, retirar" la pasa a "Cerrados"; desde ahí vuelve a la lista | Correcto |
| "Anotar en la lista": sin cliente y sin servicio avisa; buscar y elegir cliente, servicio, médico, franja "Tarde", última fecha, interruptor "Prioritario" y notas; se guarda en el puesto 2 | Correcto |
| Editar: carga sus datos; quitar la prioridad y la franja la baja al puesto 3 | Correcto |
| "Nueva cita" en un domingo sin horarios: aparece "Anotar en lista de espera"; al usarlo confirma y el botón desaparece | Correcto |
| Cancelar una cita cuyo horario le sirve a alguien: "Este horario le sirve a 1 persona de la lista de espera" | Correcto |
| Formulario de cliente en rubro médico: "Sexo biológico", "Tipo de sangre", "Antecedentes y observaciones" | Correcto |
| El mismo formulario en un rubro genérico: "Sexo" y "Observaciones", sin tipo de sangre | Correcto |

- Tras las pruebas se corrigió cómo se escriben las fechas cortas de la lista ("9 oct." en lugar de "09-oct."); ese ajuste no se volvió a ver en pantalla.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se pudo capturar la pantalla. No se probó en pantalla: un rubro con mascotas, varias sedes, la vista de un profesional, el panel de horarios cuando no hay ninguno, la regla nueva en el editor de rubros, ni el tema oscuro.

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` (hace falta de todos modos por los cambios del 8 de octubre). Sin recargar el seed, los rubros de salud no tienen la regla y dejan de mostrar el tipo de sangre.
