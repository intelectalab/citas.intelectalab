# 2026-10-08 — Fichas: acceso de emergencia, descarga en PDF e historia del cliente

## Contexto

Tres pedidos tras la entrega de las fichas de atención:

1. Que el administrador de la empresa pueda abrir una ficha privada en una emergencia, y que quede registrado.
2. Que la ficha se pueda descargar.
3. Que la historia de un cliente se vea desde Clientes, entrando por el ojo o por su nombre.

No hay cambios de esquema. Se agregó una dependencia a la API: `dompdf/dompdf`, para generar los PDF.

## 1. Acceso de emergencia a una ficha privada

En las empresas cuyas fichas son privadas, solo su autor las lee. Ahora el administrador de la empresa puede abrir una ficha ajena cuando hay una razón que lo justifique.

| Paso | Qué pasa |
|---|---|
| El administrador abre una ficha privada ajena | Ve que existe y quién la escribió, no su contenido. Aparece el bloque "Acceso de emergencia". |
| Escribe el motivo | Obligatorio, de al menos 10 caracteres. El botón "Abrir la ficha" no se habilita antes. |
| Abre la ficha | La lee durante 30 minutos. No puede cambiarla ni agregarle notas. También puede descargarla. |
| Pasados los 30 minutos | Vuelve a quedar oculta. Para abrirla otra vez hay que dar un motivo de nuevo. |

Queda registrado en la bitácora (`audit_events`, acción `care_record.emergency_access`) con quién, cuándo, desde qué dirección, el motivo, el cliente y el autor de la ficha.

El autor lo ve: al abrir su ficha aparece un aviso "Abierta por acceso de emergencia" con el nombre de quien la abrió, la fecha y el motivo. El administrador ve el mismo aviso mientras la lee.

Solo el administrador de la empresa puede pedirlo. El resto del personal sigue sin acceso.

## 2. Descargar la ficha en PDF

El panel de la ficha tiene un botón "Descargar". Genera un PDF en A4 con:

- Cabecera con el nombre de la ficha, la empresa y el color de la empresa.
- Cliente, servicio, cita, fecha de la atención, profesional, mascota (si aplica) y fecha de firma.
- Cada campo con su valor. Las listas, el odontograma y el próximo control salen en texto.
- El consentimiento y si fue aceptado.
- Las notas agregadas.
- Dos líneas de firma: profesional y cliente.
- Pie con la empresa, la fecha y quién generó el documento.

Un borrador se puede descargar y lleva un aviso de que aún no está firmado.

Quien no puede leer una ficha tampoco puede descargarla. El archivo se llama, por ejemplo, `Ficha C-0001 Juliana Díaz.pdf`.

## 3. Historia del cliente (`/customers/{id}`)

Página nueva de cada cliente. En la tabla de Clientes, el nombre es ahora un enlace y hay un ojo en Acciones; los dos llevan ahí.

| Tarjeta | Contenido |
|---|---|
| Cliente | Nombre, desde cuándo es cliente, código, documento, celular, correo, edad y sexo, dirección, contacto de emergencia, observaciones y sus mascotas o dependientes (si el rubro los usa). Un lápiz abre el formulario de siempre para editarlo. |
| Números | Atenciones y fecha de la última, citas próximas y cuál es la siguiente, canceladas y ausencias, tiempo promedio de atención, y fichas. |
| Historia de atenciones | Sus fichas, de la más reciente a la más antigua, con servicio, ficha, profesional y estado. Un clic abre la ficha. Solo si la empresa tiene el módulo de fichas. |
| Citas | Próximas a la izquierda y anteriores a la derecha. Un clic abre el panel de la cita. |

Arriba, un enlace grande de regreso a Clientes.

## 4. Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/Api/CareRecordController.php` | `emergencyAccess()` y `pdf()`. Una ficha privada se lee si es propia o si hay un acceso de emergencia vigente de quien consulta. Editarla y agregarle notas sigue reservado a su autor. Cada ficha indica si se puede pedir acceso, si se está leyendo por emergencia y quién la abrió. |
| `resources/views/records/pdf.blade.php` | Nuevo. El diseño del PDF. |
| `routes/api.php` | `POST /api/care-records/{id}/emergency-access` (`reason`) y `GET /api/care-records/{id}/pdf`, dentro del módulo `records`. |
| `composer.json`, `composer.lock` | Dependencia `dompdf/dompdf` 3.1. |

### Frontend

| Archivo | Cambio |
|---|---|
| `features/records/components/RecordPanel.tsx` | Bloque de acceso de emergencia, aviso de quién abrió la ficha y botón "Descargar". |
| `features/records/records.ts` | Datos nuevos de la ficha. |
| `features/customers/components/CustomerDetail.tsx` | Nuevo. La página del cliente. |
| `app/(dashboard)/customers/[id]/page.tsx` | Nuevo. |
| `features/customers/components/CustomersTable.tsx` | El nombre es un enlace y hay un ojo en Acciones. |

La página del cliente no necesitó rutas nuevas en la API: usa las de clientes, citas y fichas que ya existían.

## 5. Verificación

- Pruebas de la API contra una base temporal (eliminada al terminar):

| Caso | Resultado |
|---|---|
| El administrador abre una ficha privada ajena: oculta, sin campos, y puede pedir acceso | Correcto |
| Descargarla sin acceso | 403 |
| Pedir acceso sin motivo o con un motivo de tres letras | 422 |
| Pedir acceso desde otra empresa | 404 |
| Pedir acceso con motivo: se ve el contenido; no es editable ni admite notas | Correcto |
| Volver a abrirla dentro de los 30 minutos: sigue visible y lista el acceso | Correcto |
| El administrador intenta editarla o agregarle una nota | 403 |
| El autor abre su ficha: ve quién la abrió, cuándo y por qué | Correcto |
| La bitácora guarda la acción, el motivo, el cliente y el autor | Correcto |
| Un profesional pide acceso de emergencia | 403 |
| Pasados 30 minutos: vuelve a estar oculta y no se puede descargar | Correcto |
| El autor descarga el PDF: `application/pdf`, 31 KB, nombre `Ficha C-0001 Juliana Diaz.pdf` | Correcto |
| Otra empresa descarga | 404 |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| Clientes: cada nombre es un enlace y cada fila tiene su ojo | Correcto |
| Clic en el nombre: abre la página del cliente con sus datos, sus cinco tarjetas de números, su ficha en "Historia de atenciones" y su cita en "Próximas" | Correcto |
| Clic en la ficha (privada, de otro autor): "Esta ficha es privada" y el bloque de acceso de emergencia, con el botón deshabilitado | Correcto |
| Escribir el motivo: el botón se habilita; al abrir, se ven los siete campos, en solo lectura, y el aviso con el nombre, la fecha y el motivo | Correcto |
| "Descargar": se genera un PDF válido con el nombre del cliente | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se pudo revisar a la vista cómo queda el PDF (la herramienta de prueba no lo muestra). Se comprobó que se genera, que es un PDF válido y que respeta los permisos; conviene abrir uno y revisar su diseño.
- No se probó en pantalla: editar al cliente desde su página, abrir una cita desde ahí, un cliente con mascotas, el aviso de emergencia visto por el autor, ni el tema oscuro.

## 6. Para aplicar

Ejecutar `composer install` en `apps/api` si el proyecto se abre en otro equipo, para instalar la librería de PDF. No hay cambios de esquema respecto a la entrega de fichas.
