# 2026-10-09 — Feriados (pestaña en Bloqueos y ausencias) y citas de demostración en todas las empresas

## Contexto

Dos pedidos:

1. Que Bloqueos y ausencias tenga también una pestaña de feriados, como en el prototipo.
2. Que el seed traiga citas en todas las empresas, pasadas y futuras, para ver cómo conviven con los bloqueos, las ausencias y los feriados.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones. **Requiere resetear la base.**

## 1. Feriados

### Cómo quedan

Igual que en el prototipo, los feriados son una lista propia de la empresa, no un tipo de bloqueo:

| Tema | Detalle |
|---|---|
| A qué sedes aplica | A todas, o a una sola. |
| Qué hace | Si "la sede cierra", ese día la agenda no ofrece horarios ni deja agendar, ni desde el panel ni desde el portal. No se puede forzar. Si no cierra, solo se recuerda en la agenda. |
| De dónde salen | Al crear una empresa se le cargan los 14 feriados nacionales del Perú de este año y del siguiente. Los demás (Semana Santa, aniversarios) los agrega la empresa. |
| Quién los cambia | El administrador de la empresa. El resto del personal los ve. |

El tipo "Feriado" que tenían los bloqueos desde ayer se retiró: una sede completa ahora solo se bloquea por "Cierre de la sede" o "Bloqueo de horario".

### La pestaña (`/blocks` → Feriados)

Bloqueos y ausencias tiene ahora tres pestañas: Vigentes y próximos, Anteriores y Feriados (con el número de feriados que vienen).

| Parte | Detalle |
|---|---|
| Lista | Los feriados que vienen. El filtro "Año completo" muestra todos los de un año, también los pasados. |
| Tarjetas | Feriados que vienen, En los próximos 30 días, Con citas ese día y Se atiende igual. Filtran la tabla. |
| Columnas | Fecha (con cuánto falta), feriado, sedes (si hay más de una), si la sede cierra, citas ese día y acciones. |
| Botón principal | "Agregar feriado". |
| Aviso | Si faltan los feriados nacionales de este año o del siguiente, una franja lo dice y ofrece cargarlos con un clic. |

El nombre y el lápiz abren el panel para editar. La papelera pide confirmación.

Panel "Agregar feriado": nombre, fecha, sedes (si hay más de una) y el interruptor "La sede cierra todo el día".

### Citas en un feriado

Si un feriado en que la sede cierra tiene citas ya agendadas, la tabla muestra "N por resolver". Al hacer clic se listan y cada una abre el panel de la cita, igual que en los bloqueos.

### En la agenda

Al ver un día feriado, la agenda muestra un aviso arriba ("Feriado: Navidad. La sede cierra este día...") y la columna de cada profesional sale como "No atiende" con el nombre del feriado.

## 2. Citas de demostración

El seed ahora deja a las seis empresas con datos para probar. Todo va con fechas relativas a hoy, así que tras cada reseteo hay historia reciente y citas próximas.

| Qué | Detalle |
|---|---|
| Feriados | Los nacionales de este año y del siguiente en todas las empresas. En Centro Médico, además, un "Aniversario de la sede" que no cierra. |
| Catálogo | Las cinco empresas que solo tenían a su dueño reciben una categoría, dos o tres servicios de su rubro, dos profesionales con horario y cuatro clientes (con acceso al portal por su documento). |
| Ausencias | Un "Descanso médico" de dos días la semana siguiente para un profesional de cada una de esas cinco empresas. Centro Médico conserva las vacaciones de la Dra. Gómez y la capacitación del Dr. Pérez. |
| Citas | Unas 150 por empresa: las últimas tres semanas (atendidas con sus tiempos reales, canceladas y ausencias) y las próximas cuatro (pendientes y confirmadas), según el horario de cada profesional. |

Las citas no se agendan en feriados, pero **sí dentro de las ausencias y vacaciones**: así cada empresa tiene bloqueos con "citas por resolver". Y la última cita de cada empresa cae a propósito en su próximo feriado laborable, para que la pestaña de feriados también tenga una.

No hay citas cruzadas: ni dos del mismo profesional a la vez, ni un cliente en dos lugares.

Las empresas quedan así:

| Empresa | Rubro | Servicios | Profesionales |
|---|---|---|---|
| Centro Médico Integral | Médico | Los que ya tenía | Dr. Carlos Pérez, Dra. María Gómez |
| Clínica Odontológica DentalPlus | Dental | Consulta y diagnóstico, Profilaxis dental, Curación con resina | Dra. Lucía Fernández, Dr. Andrés Salas |
| Consultorio Psicológico Mente Sana | Psicología | Primera consulta, Sesión de terapia individual | Ps. Valeria Rojas, Ps. Diego Montoya |
| Centro Fisioterapia Vital | Fisioterapia | Evaluación fisioterapéutica, Sesión de terapia física, Masaje descontracturante | Lic. Renato Castillo, Lic. Paola Vega |
| Laboratorio Clínico BioAnálisis | Genérico | Toma de muestra, Entrega y lectura de resultados | Tec. Miriam Soto, Tec. Jorge Huamán |
| Policlínico San Judas | Médico | Consulta de medicina general, Control de enfermería | Dra. Carmen Ruiz, Dr. Luis Tapia |

Policlínico San Judas tiene la suscripción cancelada en el seed: sus datos están, pero su panel no muestra módulos hasta reactivarla.

## 3. Esquema

| Tabla | Cambio |
|---|---|
| `holidays` | Nueva: empresa, sede (vacía = todas), fecha, nombre y si la sede cierra. Un solo feriado por día y alcance. |
| `availability_blocks.kind` | Se retira el valor `holiday`. |

## 4. Cambios realizados

### Backend

| Archivo | Contenido |
|---|---|
| `app/Models/Holiday.php` | Nuevo. |
| `app/Support/Holidays.php` | Nuevo. Lista de feriados nacionales, carga por año y consulta de los feriados de un día en una sede. |
| `app/Http/Controllers/Api/HolidayController.php` | Nuevo. Lista, detalle con citas, alta, edición, eliminación y carga de los nacionales. |
| `app/Support/AppointmentScheduler.php` | Un día feriado en que la sede cierra responde `HOLIDAY`, que no se puede forzar. |
| `app/Http/Controllers/Api/AppointmentController.php` | La agenda del día informa sus feriados y bloquea la columna de cada profesional. |
| `app/Support/TenantProvisioner.php` | Una empresa nueva recibe los feriados nacionales de este año y del siguiente. |
| `app/Models/AvailabilityBlock.php`, `AvailabilityBlockController.php` | Sin el tipo feriado. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `agenda`. |

### Rutas (personal de la empresa, módulo `agenda`)

| Ruta | Efecto |
|---|---|
| `GET /api/holidays` (`year`, `branch_id`) | Los que vienen, o los de un año. Indica qué años faltan cargar. |
| `GET /api/holidays/{id}` | Un feriado con las citas de ese día. |
| `POST`, `PUT`, `DELETE /api/holidays/{id}` | Agregar, editar y eliminar. Solo el administrador de la empresa. |
| `POST /api/holidays/national` (`year`) | Carga los nacionales de ese año que falten. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/blocks/components/HolidaysTable.tsx` | Nuevo. La pestaña de feriados. |
| `features/blocks/components/FormHoliday.tsx` | Nuevo. Panel para agregar y editar. |
| `features/blocks/components/BlocksTable.tsx` | Tercera pestaña. |
| `features/blocks/components/FormBlock.tsx`, `features/blocks/blocks.ts` | Sin el tipo feriado; tipos del feriado. |
| `features/appointments/components/AgendaDay.tsx` | Aviso de feriado sobre la agenda del día. |

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- El seed genera entre 124 y 150 citas por empresa, del 18 de setiembre al 8 de diciembre; ninguna cruzada.
- Pruebas de la API contra esa base, con Centro Médico Integral:

| Caso | Resultado |
|---|---|
| Lista de los que vienen, con cuánto falta, si cierran y las citas de ese día | Correcto |
| Año completo: incluye los pasados | Correcto |
| Un profesional los ve y no puede cambiarlos | Correcto |
| Otra empresa ve solo los suyos; una clienta del portal | Correcto; 403 |
| Feriado con una cita ese día: la lista | Correcto |
| Agregar | Correcto |
| Otro el mismo día para todas las sedes | 422 |
| El mismo día, pero solo una sede y sin cerrar | Se acepta |
| Sin nombre y con fecha inválida; sede de otra empresa | 422; 404 |
| Un profesional agrega o elimina; otra empresa edita | 403; 404 |
| Horarios libres en un feriado, en el panel y en el portal | Ninguno |
| Agendar en un feriado; forzarlo con motivo | 409, no se puede forzar |
| Agenda del día: informa el feriado y bloquea la columna | Correcto |
| El feriado deja de cerrar la sede: se puede agendar; vuelve a cerrar: esa cita queda por resolver | Correcto |
| Eliminar: vuelve a haber horarios | Correcto |
| Cargar los nacionales de un año ya cargado; con dos faltantes; de un año nuevo | 0; 2; 14 |
| Un profesional carga los nacionales | 403 |
| Sin los del año siguiente: la lista los marca como faltantes | Correcto |
| Empresa nueva: recibe 14 de este año y 14 del siguiente | Correcto |

- Las pruebas de bloqueos, cuentas de clientes, controles y lista de espera se repitieron (sin las citas de demostración, que ocupan los horarios que esas pruebas usan): mismos resultados, salvo lo esperado por el retiro del tipo feriado.

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| Bloqueos y ausencias: tres pestañas; las vacaciones de la Dra. Gómez con "3 por resolver" | Correcto |
| Pestaña Feriados: los cinco que quedan del año, con su fecha y cuánto falta; el del 8 de diciembre con "1 por resolver" | Correcto |
| Franja "Aún no tienes los feriados nacionales de 2027": al cargarlos avisa que agregó 14, la franja desaparece y la pestaña pasa a 19 | Correcto |
| Tarjetas "Con citas ese día" y "Se atiende igual" filtran | Correcto |
| "Agregar feriado": el interruptor cambia su explicación; un día ya ocupado avisa; otro día se guarda | Correcto |
| Clic en "1 por resolver": lista la cita; abrirla y cancelarla deja "Ya no quedan citas" y la fila en "Ninguna" | Correcto |
| Editar por el nombre: cambiar el nombre y apagar el cierre pasa la fila a "Se atiende" | Correcto |
| Eliminar: "No, mantenerlo" lo conserva; "Sí, eliminar" lo quita | Correcto |
| Volver a "Vigentes y próximos": la tabla de bloqueos y su botón "Nuevo bloqueo" | Correcto |
| Agenda del 8 de diciembre: aviso de feriado y columna "No atiende · Inmaculada Concepción" | Correcto |
| Agenda de un feriado que no cierra: aviso "Se atiende con normalidad", sin bloqueo | Correcto |
| Agenda de un día de vacaciones: "No atiende · Vacaciones" con una cita dentro | Correcto |
| Agenda de hoy: seis citas | Correcto |
| Citas, últimos 30 días: 78 citas | Correcto |
| Tiempos de atención: 64 atenciones medidas, 31 minutos en promedio | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se pudo capturar la pantalla. No se probó en pantalla: las otras cinco empresas (sus datos se revisaron en la base), una empresa con varias sedes, el filtro "Año completo", la vista de un profesional ni el tema oscuro.

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin la tabla `holidays` la API sigue funcionando (trata todos los días como laborables), pero la pestaña no carga.

Las citas de demostración solo se generan en una empresa que no tenga ninguna: volver a cargar el seed sobre una base con citas no las duplica.
