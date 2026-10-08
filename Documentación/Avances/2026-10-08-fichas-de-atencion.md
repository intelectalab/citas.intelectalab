# 2026-10-08 — Fichas de atención: plantillas de la empresa y ficha por cita

## Contexto

Pregunta de origen: ¿puede una empresa editar su propia ficha? No podía. Cada empresa recibía una copia de la ficha de su rubro al registrarse, pero no tenía pantalla para verla, cambiarla ni llenarla. El módulo "Fichas de atención e historia" ya existía en los planes y llevaba a la página provisional.

Se construyó el módulo en sus dos partes: el editor de la ficha para la empresa, y el llenado de la ficha en cada atención.

Decisión tomada: una ficha llenada conserva los campos con los que se llenó. Editar la plantilla después no cambia la historia.

Los cambios de base están en `init.sql`. No hay migraciones.

## 1. Plantillas de ficha (`/records/templates`)

Las fichas de la empresa. Todo el personal las ve; solo el administrador de la empresa las cambia.

| Parte | Detalle |
|---|---|
| Tarjetas | Una por ficha: nombre, descripción, cuántos campos tiene, cuántos servicios la usan y cuántas fichas se llenaron con ella. |
| Vista previa | A la derecha, la ficha elegida tal como se verá, con el color de la empresa. |
| Nueva ficha y editar | Panel lateral con el nombre, la descripción, los campos, el consentimiento y los servicios. |
| Eliminar | Pide confirmación. Sus servicios pasan a atenderse sin ficha; las fichas ya llenadas se conservan. |

El editor de campos permite agregar, quitar, renombrar y ordenar. Los tipos son los mismos del editor de la plataforma: texto corto, texto largo, número, lista de una opción, lista de varias, sí o no, fecha, escala de 0 a 10, odontograma y próximo control.

Un servicio usa una sola ficha. Al asignarlo a otra, el panel avisa de cuál sale.

## 2. Llenar la ficha en la atención

En el panel de una cita cuyo servicio lleva ficha aparece "Ficha de atención", con su estado: pendiente, borrador o firmada. Abre un panel lateral con los campos.

| Estado | Qué se puede hacer |
|---|---|
| Sin llenar | Llenar y guardar como borrador, o firmar. |
| Borrador | Seguir llenando. No exige los campos obligatorios. |
| Firmada | Corregirla mientras dure el plazo de la empresa (24 horas por defecto). Agregar notas. |
| Bloqueada | Solo agregar notas. La nota queda al final, con su autor y su fecha. |

Para firmar hacen falta los campos obligatorios y, si la ficha lo exige, que el cliente acepte el consentimiento. Lo que falta se marca en el propio campo.

Cómo se llena cada tipo de campo:

- Las listas, el sí o no y la escala son botones.
- El odontograma muestra las 32 piezas; cada clic pasa la pieza al siguiente estado (caries, restaurada, corona, en tratamiento, ausente) y luego vuelve a sana.
- El próximo control es una fecha, con un botón que propone la sugerida por la plantilla.

Al final del panel, "Atenciones anteriores" lista las últimas cinco fichas firmadas de ese cliente (o de esa mascota), que se despliegan para leerlas.

## 3. Reglas que ahora se aplican

| Regla | Efecto | Valor si nadie la define |
|---|---|---|
| `records.require_on_complete` | Una cita cuyo servicio lleva ficha no se puede dar por atendida hasta firmarla. | Apagada |
| `records.lock_hours` | Horas, desde la firma, en que la ficha aún se puede corregir. | 24 |
| `records.private_to_author` | Las fichas nacen privadas: solo las lee quien las escribió. Los demás ven que existe, no su contenido. | Apagada |

El rubro médico trae encendida la ficha obligatoria. Desde ahora, en una empresa de ese rubro, una consulta no pasa a "Atendida" sin su ficha firmada.

Otras reglas:

- Hay una sola ficha por cita.
- Una cita cancelada, o a la que no asistieron, no lleva ficha.
- Un usuario con rol de profesional solo ve y llena las fichas de sus propias citas.
- El próximo control de una ficha firmada queda registrado como control pendiente (`recalls`). Si la ficha se corrige, el control se actualiza o se retira.

## 4. Fichas de atención (`/records`)

La lista de fichas llenadas, con la tabla del sistema. Reemplaza a la página provisional.

| Parte | Detalle |
|---|---|
| Pestañas | Últimos 30 días, últimos 90 días y todas. |
| Tarjetas | Fichas del periodo, borradores, firmadas y bloqueadas. Filtran la tabla. |
| Búsqueda | Por nombre, documento o código del cliente, sin distinguir tildes. |
| Columnas | Fecha y cita, cliente (con su mascota si el rubro la usa), ficha y servicio, profesional, estado y acciones. |
| Botón principal | "Plantillas de ficha". |

El nombre del cliente y el ojo abren la ficha.

## 5. Esquema

| Tabla | Cambio |
|---|---|
| `care_records` | Columnas nuevas: `template_name` y `consent_text` (lo que decía la plantilla al llenar la ficha), `signed_at` y `signed_by`. Índice único por cita. |

`template_snapshot` ya existía y es donde cada ficha guarda su copia de los campos.

## 6. Cambios realizados

### Backend

| Archivo | Contenido |
|---|---|
| `app/Support/RecordFields.php` | Nuevo. Tipos de campo, limpieza de la definición de una plantilla y validación de los valores de una ficha. |
| `app/Models/RecordTemplate.php`, `CareRecord.php`, `CareRecordAddendum.php` | Nuevos. |
| `app/Http/Controllers/Api/RecordTemplateController.php` | Nuevo. Plantillas de la empresa. |
| `app/Http/Controllers/Api/CareRecordController.php` | Nuevo. Ficha de una cita, notas y lista. |
| `app/Http/Controllers/Api/AppointmentController.php` | Cada cita indica si lleva ficha y en qué estado está. Aplica la regla de ficha obligatoria. |
| `app/Models/Appointment.php` | Relación con su ficha. |
| `app/Support/AppointmentFlow.php` | Valores por defecto de las tres reglas de fichas. |
| `routes/api.php` | Rutas nuevas, dentro del módulo `records`. |

### Rutas (personal de la empresa, módulo `records`)

| Ruta | Efecto |
|---|---|
| `GET /api/record-templates` | Plantillas, servicios y si quien consulta puede editarlas. |
| `POST`, `PUT`, `DELETE /api/record-templates/{id}` | Crear, editar y eliminar. Solo el administrador de la empresa. |
| `GET /api/appointments/{id}/record` | La ficha de la cita, o la plantilla en blanco, y las atenciones anteriores del cliente. |
| `PUT /api/appointments/{id}/record` (`data`, `consent_accepted`, `sign`) | Guarda el borrador, firma o corrige. |
| `POST /api/care-records/{id}/addenda` (`note`) | Agrega una nota. |
| `GET /api/care-records` y `GET /api/care-records/{id}` | Lista y detalle. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/records/records.ts` | Tipos, tipos de campo y estados. |
| `features/records/components/RecordTemplates.tsx` | Página de plantillas. |
| `features/records/components/FormRecordTemplate.tsx` | Panel para crear y editar una ficha. |
| `features/records/components/RecordFieldsEditor.tsx` | Editor de campos. |
| `features/records/components/RecordPanel.tsx` | Panel para llenar, firmar y consultar la ficha de una cita. |
| `features/records/components/RecordFieldInput.tsx` | El control de cada tipo de campo. |
| `features/records/components/RecordsTable.tsx` | Lista de fichas. |
| `app/(dashboard)/records/page.tsx`, `records/templates/page.tsx` | Las dos páginas. |
| `features/appointments/components/AppointmentDetail.tsx`, `useAppointmentPanels.tsx` | Acceso a la ficha desde el panel de la cita. |
| `app/components/layout/Sidebar.tsx` | "Fichas de atención" lleva a `/records`. |

La vista previa reutiliza `RecordPreview`, el componente que ya usaba la plataforma en Plantillas de rubros.

## 7. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base, con Centro Médico Integral:

| Caso | Resultado |
|---|---|
| Listar plantillas: el administrador puede editar, el profesional no | Correcto |
| Editar como profesional | 403 |
| Editar desde otra empresa | 404 |
| Agregar dos campos: reciben su clave interna, la versión sube, las opciones repetidas o vacías se descartan | Correcto |
| Lista sin opciones, campo sin nombre, consentimiento obligatorio sin texto, nombre repetido | 422 |
| Asignar a una ficha un servicio que usaba otra: sale de la anterior | Correcto |
| Cita de un servicio con ficha: indica que la lleva | Correcto |
| Guardar borrador con obligatorios vacíos: se guarda; descarta valores que no son de la ficha u opciones inexistentes | Correcto |
| Firmar sin un obligatorio o sin el consentimiento | 422, con el campo que falta |
| Dar por atendida con la ficha en borrador | 422 |
| Firmar completa: queda firmada, con autor, fecha y plazo de 24 horas; el próximo control queda pendiente | Correcto |
| Dar por atendida con la ficha firmada | Correcto |
| Cambiar la plantilla: la ficha firmada conserva sus nueve campos; una cita nueva usa la plantilla nueva | Correcto |
| Corregir dentro del plazo; al quitar el próximo control, el control pendiente se retira | Correcto |
| Corregir dejando vacío un obligatorio | 422 |
| Pasado el plazo: bloqueada; editarla da 422; agregar una nota funciona; nota vacía da 422 | Correcto |
| Lista: no trae el contenido; filtra por estado y busca sin tildes | Correcto |
| Otra cita del mismo cliente muestra su ficha anterior | Correcto |
| Otra empresa lee la ficha o abre la de la cita | 404 |
| Un profesional abre la ficha de la cita de otro | 403 |
| Ficha privada: el administrador la abre y no ve su contenido; no puede editarla | Correcto |
| Ficha en una cita cancelada | 422 |
| Eliminar una plantilla con fichas: la ficha llenada sigue completa; el servicio queda sin ficha y su cita se atiende sin exigirla | Correcto |

- Las pruebas anteriores de citas se repitieron sobre una base limpia: sin errores. El único cambio es el esperado: con el rubro médico, dar por atendida exige la ficha.

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| Plantillas de ficha: la tarjeta con 7 campos y 2 servicios, y la vista previa | Correcto |
| Editar: agregar dos campos, enviar con uno sin nombre (aviso), nombrarlos, elegir odontograma y escala, subir uno de posición | Correcto |
| Encender el consentimiento obligatorio y apagar un servicio; guardar: la tarjeta pasa a 9 campos y 1 servicio, y la vista previa marca "Obligatorio" | Correcto |
| Agenda: abrir una cita en atención; muestra "Ficha de atención · Pendiente" | Correcto |
| "Dar por atendida" sin ficha: aviso de que falta la ficha firmada | Correcto |
| Abrir la ficha: los nueve campos, incluidos los dos nuevos | Correcto |
| Marcar 7 en la escala; clic dos veces en la pieza 16 (restaurada) y una en la 21 (caries) | Correcto |
| Firmar sin diagnóstico ni consentimiento: "Faltan 2 datos para firmar", marcados en su lugar | Correcto |
| Guardar borrador | Correcto |
| Completar y firmar: "Firmada", con el plazo para corregir; el odontograma conserva lo marcado | Correcto |
| Cerrar: vuelve a la cita, que ya dice "Firmada"; "Dar por atendida" funciona | Correcto |
| Agregar una nota: aparece con autor y fecha | Correcto |
| Fichas de atención: la ficha en la lista, las tarjetas, el filtro de borradores, el ojo abre la ficha | Correcto |
| "Plantillas de ficha" lleva a las plantillas | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó en pantalla: crear una ficha nueva desde cero, eliminar una, las atenciones anteriores, la ficha privada, la ficha bloqueada, el botón de fecha sugerida del próximo control, un rubro con mascotas, la vista de un profesional ni el tema oscuro. La API de esos casos sí está probada, salvo el tema oscuro.

## 8. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin resetear, firmar una ficha falla (faltan columnas).
