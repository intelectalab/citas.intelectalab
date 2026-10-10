# 2026-10-09 — Sucursales (con horario de sede y salas), varias sedes por empresa en el seed y Controles en el módulo de agenda

## Contexto

Tres cosas:

1. "Sucursales" estaba en el menú y su ruta no existía.
2. El seed tenía una sola sede por empresa. Pedido: que cada empresa tenga entre dos y cinco, para ver cómo se asignan los profesionales a las sedes.
3. Controles y seguimiento pasa del módulo de fichas al de agenda.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones. **Requiere resetear la base.**

## 1. Cómo se asigna un profesional a una sede

No hay una asignación aparte: **un profesional atiende en una sede cuando tiene horario ahí.** El horario se define por sede, en Profesionales → "Horario y ausencias" (con varias sedes aparece el selector de sede).

| Caso | Cómo queda |
|---|---|
| Atiende en una sola sede | Tiene horario solo ahí. |
| Reparte su semana entre dos sedes | Tiene horario en las dos, en días u horas distintos. No puede tener el mismo tramo en dos sedes. |
| La agenda | Se ve por sede: cada día muestra a quienes tienen horario ese día en esa sede. |
| Agendar | Solo se ofrece a quien atiende ese día en la sede elegida. Agendarlo en otra sede responde que ese día no atiende ahí. |
| Ausencias y vacaciones | Son de la persona: rigen en todas sus sedes. |
| Feriados y cierres | Son de la sede (o de todas). |

## 2. Sucursales (`/branches`)

| Parte | Detalle |
|---|---|
| Pestañas | Activas e Inactivas. |
| Tarjetas | Sucursales activas frente al tope del plan ("3 de 3"), Abren hoy, Sin horario definido, Sin nadie que atienda y Citas de hoy. Las tres del medio filtran la tabla. |
| Búsqueda | Por nombre, dirección o distrito, sin distinguir tildes. |
| Columnas | Sucursal y teléfono, dirección, horario de hoy, cuántos atienden, citas de hoy y por atender, y acciones. |
| Botón principal | "Nueva sucursal". |

El nombre y el ojo llevan a la página de la sucursal. El reloj abre su horario; el lápiz, sus datos; el botón de encendido la desactiva o la vuelve a activar (pide confirmación).

### Página de la sucursal (`/branches/{id}`)

| Tarjeta | Contenido |
|---|---|
| Sucursal | Nombre, estado, dirección, departamento, teléfono y zona horaria. Un lápiz abre el panel para editar. |
| Números | Citas de hoy, por atender, cuántos atienden ahí y salas y equipos. |
| Horario de atención | Cada día con sus tramos, o "Cerrada". "Cambiar" abre el panel del horario. |
| Quiénes atienden aquí | Cada persona con sus días y horas en esa sede. Los tramos que caen fuera del horario de la sede salen marcados. |
| Salas y equipos | Lista con tipo (sala o equipo) y descripción; agregar, editar y eliminar. Solo si el plan incluye "Salas y equipos". |

Arriba, un enlace grande de regreso a Sucursales.

### Horario de atención de la sede

Es nuevo. Antes, el horario de un profesional no tenía tope.

| Regla | Detalle |
|---|---|
| Qué hace | Fuera del horario de la sede, la agenda no ofrece citas ahí, aunque alguien tenga horario. Tampoco el portal. |
| Sobre-agenda | El administrador puede agendar igual, indicando un motivo (como con el horario del profesional). |
| Sede sin horario | No limita nada: vale solo el horario de cada persona. La tabla la marca como "Sin horario definido". |
| Al guardarlo | Si deja fuera horarios del equipo, el panel los lista para ajustarlos. |
| Sede nueva | Abre con el mismo horario que la primera sede. |

### Límites del plan

- Sin el módulo Multisede, una sola sucursal.
- Con Multisede, hasta el número de sucursales activas del plan.
- Al llegar al tope, "Nueva sucursal" lo explica en lugar de abrir el formulario. Es el primer límite del plan que se aplica además del de citas del mes.

### Activar y desactivar

Las sucursales no se eliminan, se desactivan: conservan su historia y dejan de ofrecerse para agendar y para definir horarios.

- No se puede desactivar la única sucursal activa.
- No se puede desactivar una con citas próximas: primero hay que reprogramarlas o cancelarlas.
- Volver a activarla respeta el tope del plan.

### Quién puede qué

Todo el personal ve las sucursales. Las cambia solo el administrador de la empresa.

## 3. Varias sedes por empresa en el seed

| Empresa | Sedes | Cómo se reparten |
|---|---|---|
| Centro Médico Integral | 3 | Dr. Pérez en la central; Dra. Gómez lunes, miércoles, viernes y sábado en la central, y martes y jueves en San Borja; Dr. Lazo en Surco. |
| Clínica Odontológica DentalPlus | 5 | Una dentista en San Isidro; otro repartido entre San Isidro y Miraflores; uno en cada una de las otras tres. |
| Consultorio Psicológico Mente Sana | 2 | Una psicóloga en Lima Centro; otro repartido entre las dos. |
| Centro Fisioterapia Vital | 3 | Uno en la principal; otra repartida entre dos; uno en Comas. |
| Laboratorio Clínico BioAnálisis | 2 | Una técnica en Los Olivos; otro repartido entre las dos. |
| Policlínico San Judas | 4 | La sede de Lince sigue inactiva; los cuatro profesionales atienden en las otras tres. |

- Todas las sedes tienen dirección, teléfono y horario de atención (las más pequeñas cierran antes).
- Algunas sedes tienen salas y equipos.
- Las citas de demostración se generan en la sede donde cada profesional atiende ese día. Quedan entre 150 y 341 por empresa, sin cruces.
- **Mente Sana y BioAnálisis pasan del plan Esencial al Profesional**, porque el Esencial permite una sola sede. Ya no queda ninguna empresa del seed en el plan Esencial.

## 4. Controles y seguimiento, en el módulo de agenda

La pantalla y sus rutas dependían de "Fichas de atención e historia". Ahora dependen de la agenda, que tienen todas las empresas: un salón o una barbería sin fichas ya ve Controles y recibe los controles que piden sus servicios.

## 5. Esquema

| Tabla | Cambio |
|---|---|
| `branches` | Columnas nuevas: `address`, `district`, `department` y `phone`. |
| `branch_hours` | Nueva: sede, día de la semana, apertura y cierre. |
| `rooms` | Columna nueva `kind` (`room` o `equipment`). |

## 6. Cambios realizados

### Backend

| Archivo | Contenido |
|---|---|
| `app/Http/Controllers/Api/BranchController.php` | Nuevo. Lista, detalle, alta, edición, activar o desactivar y horario. |
| `app/Http/Controllers/Api/RoomController.php` | Nuevo. Salas y equipos. |
| `app/Support/BranchHours.php` | Nuevo. Lee y guarda el horario de una sede, y dice si un tramo cae dentro. |
| `app/Support/AppointmentScheduler.php` | Fuera del horario de la sede responde `BRANCH_CLOSED`, que se puede forzar. |
| `app/Models/Branch.php` | Datos nuevos. |
| `app/Support/Recalls.php` | El control del servicio ya no exige el módulo de fichas. |
| `routes/api.php` | Rutas de sucursales y salas; las de controles pasan al grupo de la agenda. |

### Rutas

| Ruta | Efecto |
|---|---|
| `GET /api/branches` | Lista con sus números, el tope del plan y si se puede abrir otra. |
| `GET /api/branches/{id}` | Detalle: horario, quiénes atienden y salas. |
| `POST /api/branches`, `PUT /api/branches/{id}` | Crear y editar. |
| `POST /api/branches/{id}/status` (`status`) | Activar o desactivar. |
| `PUT /api/branches/{id}/hours` (`ranges`) | Reemplaza el horario; devuelve los horarios del equipo que quedan fuera. |
| `POST /api/branches/{id}/rooms`, `PUT`/`DELETE /api/rooms/{id}` | Salas y equipos (módulo `resources`). |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/branches/branches.ts` | Tipos y textos. |
| `features/branches/components/BranchesTable.tsx` | La tabla. |
| `features/branches/components/BranchDetail.tsx` | La página de la sucursal, con sus salas y equipos. |
| `features/branches/components/FormBranch.tsx` | Panel para crear y editar. |
| `features/branches/components/BranchHoursPanel.tsx` | Panel del horario de atención. |
| `app/(dashboard)/branches/page.tsx`, `branches/[id]/page.tsx` | Las dos páginas. |
| `app/components/layout/Sidebar.tsx` | Controles depende de la agenda. |

## 7. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- En el seed: 19 sedes; ninguna cita cruzada (ni de un profesional ni de un cliente); ningún horario fuera del horario de su sede; ninguna cita en una sede donde su profesional no atiende.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| Lista de Centro Médico: tres sedes, tope de tres, no puede abrir otra | Correcto |
| Detalle de San Borja: horario, la Dra. Gómez martes y jueves, sus salas | Correcto |
| Un profesional las ve y no puede cambiarlas; otra empresa; una clienta | Correcto; 404; 403 |
| Cuarta sede con plan de tres | 422, con el motivo |
| DentalPlus crea su sexta sede: abre con el horario de la primera | Correcto |
| Nombre repetido; sin nombre; zona horaria inválida | 422 |
| Editar; otra empresa edita; un profesional edita o crea | Correcto; 404; 403 |
| Desactivar una sede con 25 citas próximas | 422 |
| Desactivar una sin citas: deja de ofrecerse para agendar; volver a activarla | Correcto |
| Empresa nueva con plan Esencial: una sede, no puede abrir otra ni desactivar la única | Correcto |
| Horario: cierre antes de la apertura; tramos que se cruzan; un profesional lo cambia | 422; 422; 403 |
| Sede de 08:00 a 17:00: lista los seis tramos del equipo que quedan fuera | Correcto |
| Horarios libres de quien atiende hasta las 19:00: el último es 16:30; en el portal igual | Correcto |
| Agendar a las 18:00: sede cerrada, se puede forzar; con motivo se agenda | Correcto |
| Quitar el horario: la sede deja de limitar | Correcto |
| Martes: en San Borja atiende la Dra. Gómez; en la central, el Dr. Pérez | Correcto |
| Agendar a la Dra. Gómez un martes en la central | 409: ese día no atiende en esa sede |
| El mismo martes en San Borja; la agenda de San Borja la muestra | Correcto |
| Salas: agregar, nombre repetido en la misma sede, mismo nombre en otra, editar, desactivar, eliminar | Correcto |
| Otra empresa edita una sala; un profesional la elimina | 404; 403 |

- Las pruebas de bloqueos, cuentas de clientes, controles, lista de espera y feriados se repitieron después de estos cambios: mismos resultados.

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| El menú abre `/branches` con las tres sedes de Centro Médico y la tarjeta "Sucursales activas de 3" | Correcto |
| "Nueva sucursal" con el tope alcanzado: explica el límite y no abre el formulario | Correcto |
| Reloj: el panel carga el horario; apagar el sábado quita sus horas; encender el domingo copia las del día anterior | Correcto |
| Cerrar el lunes a las 17:00 y guardar: avisa de dos horarios del equipo que quedan fuera | Correcto |
| Desactivar una sede con citas próximas: pide confirmar y luego explica por qué no se puede | Correcto |
| Clic en el nombre: página de la sucursal con sus datos, cuatro números, horario, equipo (con los tramos fuera marcados) y salas | Correcto |
| Editar el teléfono desde la página | Correcto |
| Agregar un equipo, verlo en la lista y eliminarlo con confirmación | Correcto |
| Enlace de regreso a Sucursales | Correcto |
| DentalPlus: cinco sedes; crear "Sede Barranco"; tarjeta "Sin nadie que atienda" la muestra; desactivarla la pasa a "Inactivas" | Correcto |
| Agenda de DentalPlus: muestra a quien atiende en la sede elegida | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se pudo capturar la pantalla. No se probó en pantalla: cambiar de sede en la agenda (el selector ya existía), el panel de horario de un profesional con varias sedes, volver a activar una sede, la vista de un profesional, una empresa sin el módulo de salas ni el tema oscuro.

## 8. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin las columnas nuevas, la pantalla de Sucursales falla; sin la tabla de horarios, ninguna sede limita la agenda.
