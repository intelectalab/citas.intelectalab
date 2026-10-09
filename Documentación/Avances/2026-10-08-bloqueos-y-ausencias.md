# 2026-10-08 — Bloqueos y ausencias: pantalla, tipos de bloqueo y citas afectadas

## Contexto

"Bloqueos y ausencias" estaba en el menú y llevaba a la página provisional. La API ya guardaba ausencias de un profesional y cierres de una sede, pero solo había pantalla para las ausencias, dentro del panel de horario de cada profesional.

Ahora tiene su pantalla (`/blocks`), con todos los bloqueos de la empresa, su tipo y las citas que quedaron dentro de cada uno.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. La pantalla (`/blocks`)

| Parte | Detalle |
|---|---|
| Pestañas | Vigentes y próximos, y Anteriores. |
| Tarjetas | Bloqueos vigentes, En curso ahora, Próximos, Cierres de sede y Con citas dentro. Filtran la tabla. |
| Búsqueda | Por motivo, persona, sede o tipo, sin distinguir tildes. |
| Filtros | Tipo, profesional y sede (si hay más de una). |
| Columnas | A quién aplica, motivo y tipo, cuándo y cuánto dura, estado, citas dentro y acciones. |
| Botón principal | "Nuevo bloqueo". |

El nombre y el lápiz abren el panel para editar. La papelera pide confirmación.

### Panel "Nuevo bloqueo"

| Campo | Detalle |
|---|---|
| ¿A quién aplica? | Un profesional o toda la sede. Con varias sedes se puede elegir "Todas las sedes" (registra un bloqueo por sede). |
| Tipo | Para una persona: vacaciones, permiso o descanso médico, bloqueo de horario. Para una sede: feriado, cierre de la sede, bloqueo de horario. |
| Días completos | Interruptor. Encendido, solo se piden las fechas; apagado, también las horas. |
| Motivo | Obligatorio. Es lo que se ve en la agenda sobre las horas bloqueadas. |

Mientras se llena, el panel avisa cuántas citas quedan dentro del rango y las lista. El bloqueo no las mueve ni las cancela.

### Citas dentro de un bloqueo

Cuando un bloqueo tiene citas dentro, la tabla muestra "N por resolver". Al hacer clic se abre un panel con esas citas; cada una abre el panel de la cita de siempre, para reprogramarla o cancelarla. Al volver, la lista ya está al día.

Cuentan las citas pendientes, confirmadas, con llegada registrada o en atención que aún no terminan.

## 2. Tipos de bloqueo y sobre-agenda

| Tipo | Aplica a | ¿Se puede agendar encima? |
|---|---|---|
| Vacaciones | Una persona | No |
| Permiso o descanso médico | Una persona | No |
| Feriado | Una sede | No |
| Cierre de la sede | Una sede | No |
| Bloqueo de horario (reunión, capacitación, mantenimiento) | Una persona o una sede | Sí: el administrador, con un motivo |

Antes ningún bloqueo se podía forzar. La sobre-agenda sigue las reglas de siempre: la permite `agenda.allow_override`, la pide el administrador de la empresa y nunca sobre otra cita.

Una ausencia de una persona rige en todas las sedes donde atiende.

## 3. Quién puede qué

| Usuario | Puede |
|---|---|
| Administrador y personal de la empresa | Ver, registrar, editar y eliminar todos los bloqueos. |
| Profesional (sin ser administrador) | Ver sus ausencias y los cierres de sede. Registrar, editar y eliminar solo las suyas. No puede cerrar una sede. |

## 4. Panel de horario del profesional

El bloque "Ausencias" del panel de horario (en Profesionales) sigue funcionando. Ahora pide el tipo, lo muestra en cada ausencia y pide confirmación antes de quitar una.

## 5. Esquema

| Tabla | Cambio |
|---|---|
| `availability_blocks` | Columnas nuevas: `kind` (`vacation`, `leave`, `holiday`, `closure`, `other`; por defecto `other`), `created_by` y `created_at`. |

El seed trae tres bloqueos de ejemplo en Centro Médico Integral, con fechas relativas: vacaciones de la Dra. Gómez, una capacitación del Dr. Pérez el miércoles de la semana siguiente (se puede forzar) y el feriado de Año Nuevo.

Si la base aún no tiene la columna `kind`, la API sigue funcionando: los bloqueos se guardan sin tipo y ninguno se puede forzar, como antes.

## 6. Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/Api/AvailabilityBlockController.php` | Tipo de bloqueo, editar, ver uno con sus citas, vista previa de citas afectadas, opciones del formulario, pestaña de anteriores y permisos del profesional. Cada bloqueo indica su estado, cuántas citas tiene dentro y si quien consulta puede cambiarlo. |
| `app/Models/AvailabilityBlock.php` | Tipos, cuáles son de persona y cuáles de sede, y cuál admite sobre-agenda. |
| `app/Support/AppointmentScheduler.php` | Un bloqueo de horario responde `BLOCKED_SOFT`, que se puede forzar. Los demás siguen respondiendo `BLOCKED`. |
| `app/Http/Controllers/Api/AppointmentController.php` | La agenda del día incluye el tipo de cada bloqueo. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `agenda`. |

### Rutas (personal de la empresa, módulo `agenda`)

| Ruta | Efecto |
|---|---|
| `GET /api/availability-blocks` (`period=current\|past`, `professional_id`, `branch_id`) | Lista. |
| `GET /api/availability-blocks/options` | Sedes, profesionales y tipos para el formulario, y si quien consulta puede cerrar una sede. |
| `GET /api/availability-blocks/affected` (`professional_id` o `branch_id`, `starts_at`, `ends_at`) | Citas que quedarían dentro de un rango. |
| `GET /api/availability-blocks/{id}` | Un bloqueo con sus citas dentro. |
| `POST`, `PUT`, `DELETE /api/availability-blocks/{id}` | Registrar, editar y eliminar. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/blocks/blocks.ts` | Tipos, colores y textos de fecha y duración. |
| `features/blocks/components/BlocksTable.tsx` | La pantalla, el panel de citas dentro de un bloqueo y la confirmación al eliminar. |
| `features/blocks/components/FormBlock.tsx` | Panel para registrar y editar. |
| `app/(dashboard)/blocks/page.tsx` | La página. |
| `features/professionals/components/ProfessionalSchedule.tsx` | Tipo de ausencia y confirmación al quitar. |
| `app/components/layout/Sidebar.tsx`, `lib/modules.ts` | "Bloqueos y ausencias" lleva a `/blocks` y sale de las secciones provisionales. |

## 7. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base, con Centro Médico Integral:

| Caso | Resultado |
|---|---|
| Opciones del administrador: dos profesionales, tipos de persona y de sede, puede cerrar sede | Correcto |
| Opciones de un profesional: solo él, no puede cerrar sede | Correcto |
| Lista del administrador: los tres del seed con tipo, estado y citas dentro | Correcto |
| Lista de un profesional: sus ausencias y los cierres de sede (estos, sin poder cambiarlos) | Correcto |
| Lista de otra empresa | Vacía |
| Cita sobre un bloqueo de horario | 409, se puede forzar |
| La misma con motivo, como administrador | Se agenda |
| Un profesional intenta forzarla | 409 |
| Vista previa de citas afectadas de un rango | Lista la cita con cliente, hora y fase |
| Crear vacaciones sobre una cita: indica 1 cita dentro | Correcto |
| Ver el bloqueo: trae sus citas | Correcto |
| Forzar una cita sobre vacaciones | 409, no se puede forzar |
| Tipo de sede en una persona; fin igual al inicio; sin motivo; sin a quién | 422, cada uno con su aviso |
| Sede inexistente | 404 |
| Cierre de sede: cuenta las citas de todos los profesionales de esa sede | Correcto |
| Agendar en la sede cerrada, con motivo | 409, no se puede forzar |
| Editar fechas y motivo; pasar de vacaciones a bloqueo de horario | Correcto |
| Profesional: registra y elimina su permiso | Correcto |
| Profesional: ausencia de otra persona, cerrar la sede, eliminar un cierre | 403 |
| Otra empresa edita o elimina | 404 |
| Agenda del día: cada bloqueo trae su tipo | Correcto |
| Alta sin tipo (como la hacía el panel de horario) | Se guarda como bloqueo de horario |

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| El menú "Bloqueos y ausencias" abre `/blocks` con los tres bloqueos del seed | Correcto |
| "Nuevo bloqueo": cambiar entre profesional y sede cambia los tipos y el texto de ayuda | Correcto |
| Elegir profesional en el selector | Correcto |
| Interruptor "Días completos": apagarlo muestra las horas; encenderlo las oculta | Correcto |
| Elegir fechas con dos citas dentro: el panel avisa y las lista | Correcto |
| Enviar sin motivo: no deja; con motivo se guarda y la fila aparece con "2 por resolver" | Correcto |
| Clic en "2 por resolver": lista las dos citas | Correcto |
| Abrir una cita desde ahí, cancelarla con motivo y cerrar: la lista queda con una y la tabla dice "1 por resolver" | Correcto |
| Tarjetas "Con citas dentro" y "Cierres de sede" filtran; la primera vuelve a mostrar todo | Correcto |
| Buscar "capacitacion" (sin tilde) | Encuentra "Capacitación interna" |
| Pestaña Anteriores (vacía) y volver | Correcto |
| Editar por el nombre: carga sus datos, el título dice de quién es; cambiar tipo y pasar a horas | Correcto |
| Editar con hora de fin anterior a la de inicio | Aviso, no guarda |
| Eliminar: "No, mantenerlo" lo conserva; "Sí, eliminar" lo quita | Correcto |
| Cierre de sede de un día con una cita dentro | Se guarda, "1 por resolver" |
| Panel de horario de un profesional: muestra el tipo, registra un permiso, y al quitarlo pide confirmar | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se pudo capturar la pantalla (la herramienta de prueba no respondió), así que el aspecto se revisó solo en una captura inicial de la tabla. Conviene mirar el panel "Nuevo bloqueo".
- No se probó en pantalla: forzar una cita sobre un bloqueo de horario desde la agenda (la API sí), una empresa con varias sedes ("Todas las sedes" y el filtro de sede), la vista de un profesional, ni el tema oscuro.

## 8. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin resetear, la pantalla funciona, pero los bloqueos se guardan sin tipo y ninguno se puede forzar.
