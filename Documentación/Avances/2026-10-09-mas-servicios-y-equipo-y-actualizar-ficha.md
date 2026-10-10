# 2026-10-09 — Más servicios y más equipo en todas las empresas del seed, y "Actualizar ficha" en los borradores

## Contexto

Tres pedidos:

1. Más servicios en todas las empresas de ejemplo.
2. Más profesionales, y que nadie esté repartido entre dos sucursales (resultaba poco real).
3. Qué pasa con una ficha ya llenada cuando la plantilla cambia. Respuesta: no cambia. Se acordó agregar "Actualizar ficha" para pasar un borrador a la plantilla actual.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones. **Requiere resetear la base.**

## 1. Servicios

| Antes | Ahora |
|---|---|
| Las seis empresas originales tenían dos o tres servicios escritos a mano. | Todas reciben el **catálogo completo de su rubro** (categorías, servicios con código, adelanto, reserva en línea y control, y la plantilla de ficha enlazada), igual que una empresa que se registra. |
| Las cinco nuevas tenían solo el catálogo del rubro. | Todas suman además **cuatro a seis servicios propios** (por ejemplo: Electrocardiograma, Corona de porcelana, Terapia familiar, Punción seca, Uñas acrílicas, Tinte de barba, Cavitación, Consulta de urgencia). |

Quedan entre 8 y 14 servicios por empresa.

- Centro Médico conserva sus dos servicios y su ficha (los usan sus controles, su lista de espera y su paquete de ejemplo) y suma los del rubro.
- El laboratorio BioAnálisis está en el rubro genérico, cuyo catálogo es neutro: sus ocho servicios están escritos en el seed.
- El Estudio Prana suma una cuarta clase grupal (Stretching).

## 2. Equipo

- **Cada profesional atiende en una sola sede.** Se quitaron los seis que repartían su semana entre dos sedes, y los martes y jueves de la Dra. Gómez en San Borja.
- **Cada sede activa tiene de dos a cuatro personas**, con turnos distintos: dos tramos (09:00–13:00 y 15:00–18:00 o 19:00), corrido (09:00–17:00), mañana (08:00–14:00) o tarde (12:00–18:00). El turno se recorta al horario de la sede: quien atiende por la mañana trabaja el sábado solo donde la sede abre ese día.

| Empresa | Sedes activas | Profesionales |
|---|---|---|
| Centro Médico Integral | 3 | 8 |
| Clínica Odontológica DentalPlus | 5 | 12 |
| Consultorio Psicológico Mente Sana | 2 | 5 |
| Centro Fisioterapia Vital | 3 | 7 |
| Laboratorio Clínico BioAnálisis | 2 | 5 |
| Policlínico San Judas | 3 | 7 |
| Salón, Barbería, Estética, Fitness y Veterinaria | 1 cada una | 4 cada una |

Para que tanta gente tenga a quién atender, cada empresa tiene ahora **18 clientes** (los cuatro de antes y catorce más, con sexo y distrito). En la veterinaria todos los tutores tienen mascota. Quedan entre 240 y 860 citas por empresa, y las citas usan todos los servicios individuales.

La API y las pantallas siguen permitiendo que alguien atienda en dos sedes; solo dejó de haber un ejemplo en el seed.

## 3. Fichas y cambios de plantilla

### Lo que ya pasaba (sin cambios)

Al guardar la ficha de una cita por primera vez, aunque sea como borrador, la ficha se queda con su copia de los campos, del nombre de la plantilla y del texto de consentimiento. Editar la plantilla, asignar otra al servicio o eliminarla no cambia las fichas ya guardadas. Las citas que aún no tienen ficha usan la plantilla actual.

### Lo nuevo: "Actualizar ficha"

| Estado de la ficha | Qué se ofrece |
|---|---|
| Borrador, y la plantilla del servicio ya no es la misma | Un aviso "La plantilla de este servicio cambió" con el botón **Actualizar ficha**. |
| Firmada o bloqueada | Nada: conserva los campos con que se llenó. Se le agregan notas. |

Al pulsar el botón, antes de cambiar nada el panel muestra:

- los campos nuevos;
- los campos en que **se pierde lo escrito** (los que la plantilla nueva ya no tiene, o tiene con otro tipo), o que no se pierde nada;
- si hay que volver a aceptar el consentimiento porque su texto cambió.

Con "Sí, actualizar" el borrador toma los campos, el nombre y el consentimiento de la plantilla actual, y conserva lo escrito en los campos que siguen existiendo, incluido lo que estaba en pantalla sin guardar. Con "No, mantenerla" queda como estaba.

Solo puede hacerlo quien puede editar la ficha (en una ficha privada, su autor).

### Corrección: consentimiento obligatorio

Antes, si el consentimiento era obligatorio se consultaba en la plantilla al momento de firmar: cambiar ese interruptor afectaba a los borradores ya empezados, y si la plantilla se eliminaba dejaban de exigirlo. Ahora la ficha guarda ese dato cuando nace, como ya hacía con el texto. El panel muestra "Obligatorio" también en una ficha ya guardada (antes solo en una nueva).

## 4. Esquema

| Tabla | Cambio |
|---|---|
| `care_records` | Columna nueva `require_consent`: si la plantilla exigía el consentimiento cuando se empezó la ficha. |

## 5. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/seed.sql` | Catálogo del rubro para todas las empresas, servicios propios, un profesional por sede, equipo adicional por sede, catorce clientes más por empresa y mascotas para todos los tutores. |
| `apps/api/infrastructure/docker/postgres/init.sql` | Columna `care_records.require_consent`. |
| `apps/api/app/Http/Controllers/Api/CareRecordController.php` | `refreshTemplate` (pasa un borrador a la plantilla actual), aviso `template_update` en la ficha de la cita y consentimiento obligatorio leído de la ficha. |
| `apps/api/app/Models/CareRecord.php` | Dato nuevo. |
| `apps/api/routes/api.php` | Ruta nueva, en el módulo de fichas. |
| `apps/web/src/features/records/components/RecordPanel.tsx` | Aviso, confirmación y botón "Actualizar ficha". |
| `apps/web/src/features/records/records.ts` | Tipos. |

### Ruta

| Ruta | Efecto |
|---|---|
| `POST /api/appointments/{id}/record/refresh-template` (`data` opcional) | Pasa el borrador de esa cita a la plantilla actual de su servicio y devuelve la ficha. |
| `GET /api/appointments/{id}/record` | Ahora incluye `template_update`: la plantilla actual, solo si el borrador tiene otra. |

## 6. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- En la base: ningún profesional en más de una sede ni sin horario; ningún horario fuera del horario de su sede; ninguna cita cruzada (de profesional o de cliente) ni fuera del horario de quien atiende; ninguna cita de la veterinaria sin mascota; ningún servicio, correo o código de cliente repetido en una empresa; todos los clientes con acceso al portal.
- Pruebas de la API, con DentalPlus:

| Caso | Resultado |
|---|---|
| Cita sin ficha: entrega la plantilla del servicio, sin aviso | Correcto |
| Guardar un borrador: queda con sus campos y con "exige consentimiento" | Correcto |
| Plantilla sin cambios: sin aviso | Correcto |
| La empresa quita un campo, cambia el tipo de otro, agrega uno y deja de exigir consentimiento: el borrador no cambia y se ofrece la plantilla actual | Correcto |
| Firmar ese borrador sin consentimiento | 422: sigue exigiéndolo, como cuando se empezó |
| Actualizar ficha: toma los campos nuevos; conserva lo escrito (también lo no guardado); descarta el campo quitado y el que cambió de tipo; el consentimiento queda sin aceptar | Correcto |
| Firmarla ya actualizada, sin consentimiento | Se firma: la plantilla actual no lo exige |
| Firmada, y la plantilla vuelve a cambiar: sin aviso y con sus campos | Correcto |
| Actualizar una firmada | 422 |
| Actualizar una cita sin ficha | 404 |
| Borrador cuyo servicio se quedó sin plantilla: sigue igual, sin aviso; actualizar | Correcto; 422 |
| Otra empresa | 404 |
| Cambia solo "exigir consentimiento": se ofrece actualizar | Correcto |

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| DentalPlus: Sucursales muestra dos o tres personas por sede; la agenda de una sede tiene a sus dos profesionales con citas de servicios variados; Servicios lista doce | Correcto |
| Fichas de atención: abrir un borrador cuya plantilla cambió muestra el aviso y el botón | Correcto |
| Escribir en un campo que la plantilla nueva quita y pulsar "Actualizar ficha": lista "Campos nuevos: Nivel de dolor" y "Se pierde lo escrito en: Presión arterial" | Correcto |
| "No, mantenerla": vuelve a mostrar el botón y los campos siguen igual | Correcto |
| "Sí, actualizar": cambian los campos, se conserva el motivo escrito sin guardar, desaparece el aviso y avisa "Ficha actualizada a la plantilla actual" | Correcto |
| Firmar sin aceptar el consentimiento: lo pide y lo marca como obligatorio; al aceptarlo se firma | Correcto |
| Firmada: ya no aparece el aviso | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src` (siguen los cuatro avisos de las páginas vacías ya conocidas).
- No se probó en pantalla: actualizar la ficha abriéndola desde la agenda, la vista de un profesional, una ficha privada ni el tema oscuro.

## 7. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin la columna nueva la API sigue funcionando como antes (lee el consentimiento obligatorio de la plantilla).
