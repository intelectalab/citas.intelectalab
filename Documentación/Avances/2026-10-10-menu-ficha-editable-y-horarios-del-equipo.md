# 2026-10-10 — Menú lateral con otra presentación, ficha editable sobre la hoja, horarios del equipo y Clases fuera de la barbería

## Contexto

Cuatro pedidos:

1. Quitar Clases también de la barbería.
2. Que el menú lateral tenga otra presentación: desentonaba con el resto, y los títulos de los grupos se veían "robóticos".
3. Mostrar los profesionales y sus horarios de una forma más clara y elegante, debajo de "Médicos" en el menú.
4. Que la plantilla de ficha se pueda editar directamente sobre la ficha, no solo con el formulario.

Sin cambios de esquema. En `seed.sql` solo cambia la barbería.

## 1. Clases fuera de la barbería

`classes` se agregó a los módulos que el rubro Barbería no usa. Con esto Clases queda solo en fitness (y en el rubro genérico no, que ya lo tenía apagado).

## 2. Menú lateral

Se mantuvo el menú oscuro y su organización (mismos grupos, mismos ítems, mismos iconos, plegado). Cambió la presentación:

| Antes | Ahora |
|---|---|
| Fondo azul distinto al del marco del panel, con una línea divisoria | El mismo fondo que el marco: menú y marco son una sola pieza |
| Títulos de grupo en MAYÚSCULAS espaciadas, en negrita y con icono | Texto normal ("Agenda", "Atención", "Ventas"), más pequeño y en gris, sin icono |
| Ítem activo con barra lateral gruesa y celeste | Píldora suave, con el icono en el turquesa del panel |
| Acentos en celeste (`sky`), que no existe en el resto del panel | El turquesa `#84D0D9` y el azul `#022340` que ya usan las pantallas |
| Ítems anidados con una línea vertical | Sin línea; más aire entre grupos |
| "ADMINISTRACIÓN DE SEDE", "ADMINISTRACIÓN SAAS" | "Tu empresa", "Plataforma" |

**Barra de desplazamiento del menú** (ajuste posterior del mismo día): salía la barra genérica del navegador, blanca y con flechas, porque la clase que el menú usaba (`scrollbar-thin`) no existía. Ahora es delgada, sin fondo ni flechas, y solo aparece al pasar el cursor por el menú (`.sidebar-scroll` en `globals.css`). Se comprobó que el navegador aplica el estilo; no se vio dentro del panel con sesión iniciada.

El logo usa los mismos colores. "Configuración", abajo, ahora también se marca cuando estás en ella.

## 3. Horarios del equipo (`/schedules`)

Nuevo ítem debajo de los profesionales, en "Tu empresa".

| Parte | Detalle |
|---|---|
| Sedes | Con varias sedes, una pestaña por sede con cuántas personas atienden ahí. |
| Tarjetas | Personas en la sede, horas de atención por semana y cuántos atienden hoy. |
| Semana | Una fila por persona y una columna por día. Cada celda muestra una **línea de tiempo** con sus tramos y, debajo, las horas. El fondo gris de la línea marca cuándo abre la sede. |
| Encabezado | Cada día con el horario de la sede; hoy resaltado. |
| Persona | Iniciales con su color, nombre y horas a la semana. |
| Editar | El lápiz abre el panel de horario que ya existía, ahora **en la sede que se está viendo**. Solo el administrador. |
| Aviso | Si alguien no tiene horario en ninguna sede (la agenda no le ofrece citas), una franja lo nombra. |

La pantalla de profesionales no cambió; su panel de horario ahora abre en la primera sede donde la persona ya atiende, en lugar de la primera por orden alfabético.

## 4. Ficha editable sobre la hoja (`/records/templates`)

La hoja de la derecha, que antes era solo una vista previa, ahora se edita en su sitio:

| Qué | Cómo |
|---|---|
| Nombre de la ficha | Se escribe sobre el título. |
| Nombre de cada campo | Se escribe sobre la etiqueta. |
| Obligatorio u opcional | Un clic en la etiqueta "Obligatorio / Opcional" del campo. |
| Tipo de campo | Lista en el propio campo; la hoja muestra al instante cómo queda. |
| Opciones de una lista | Cada opción se escribe en su sitio; "+ opción" agrega y la × quita. |
| Días del próximo control | En el propio campo. |
| Orden | Flechas de subir y bajar. |
| Quitar un campo | Papelera del campo. |
| Agregar un campo | "Agregar campo" al final; se elige el tipo entre los diez disponibles. |
| Consentimiento | El texto se escribe en su sitio; un clic lo vuelve obligatorio u opcional; se puede quitar o agregar. |

- Nada se guarda hasta pulsar **Guardar ficha**. Mientras hay cambios, una barra al pie lo avisa y ofrece **Descartar**.
- Con cambios sin guardar no se puede pasar a otra ficha, crear una ni eliminar: la pantalla pide guardarlos o descartarlos.
- El lápiz de la tarjeta sigue abriendo el panel lateral, que queda para lo que no está en la hoja: los servicios que usan la ficha y su descripción.
- Quien no administra la empresa sigue viendo la vista previa, sin editar.
- Las fichas ya llenadas no cambian (y un borrador puede pasarse a la plantilla nueva con "Actualizar ficha").

## 5. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/seed.sql` | Clases apagado en la barbería. |
| `apps/web/src/app/components/layout/Sidebar.tsx` | Nueva presentación; ítem "Horarios del equipo". |
| `apps/api/app/Http/Controllers/Api/ProfessionalScheduleController.php`, `routes/api.php` | `GET /api/schedules`: horarios de todo el equipo por sede. |
| `apps/web/src/features/professionals/components/TeamSchedules.tsx`, `app/(dashboard)/schedules/page.tsx` | Nuevos. La pantalla de horarios. |
| `apps/web/src/features/professionals/components/ProfessionalSchedule.tsx` | Abre en la sede indicada o en la primera donde la persona atiende. |
| `apps/web/src/features/records/components/RecordSheetEditor.tsx` | Nuevo. La hoja editable. |
| `apps/web/src/features/records/components/RecordTemplates.tsx` | Usa la hoja editable y cuida los cambios sin guardar. |
| `apps/web/src/features/business-types/components/RecordPreview.tsx` | Comparte la vista de cada tipo de campo. |

## 6. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales:

| Prueba | Resultado |
|---|---|
| Menú: títulos "Agenda", "Atención", "Ventas", "Fidelización", "Tu empresa", sin mayúsculas forzadas ni espaciado; fondo igual al del marco | Correcto |
| Menú: el ítem de la página actual queda marcado; "Horarios del equipo" aparece debajo de Médicos | Correcto |
| Menú: plegar un grupo lo cierra y gira su flecha | Correcto (ver nota) |
| Barbería: el menú no tiene Clases y conserva Cola sin cita | Correcto |
| Horarios, Centro Médico: tres pestañas de sede con su número; tarjetas; cada persona con sus tramos por día | Correcto |
| Cambiar a la sede San Borja muestra a sus tres médicos | Correcto |
| Lápiz de una persona: abre su panel de horario en su sede, con sus días encendidos | Correcto |
| Barbería (una sede): sin pestañas, cuatro personas | Correcto |
| Plantillas: la hoja carga editable con sus siete campos | Correcto |
| Renombrar un campo, volverlo obligatorio, subirlo, cambiar el tipo de otro a escala | Correcto; la hoja refleja cada cambio |
| Agregar un campo de lista, nombrarlo, agregar una opción y escribir las tres | Correcto |
| Con cambios sin guardar, elegir otra ficha: avisa y no cambia | Correcto |
| Guardar ficha: la barra vuelve a normal, la tarjeta pasa a 8 campos y la hoja conserva todo | Correcto |
| Cambiar el nombre y quitar un campo, y Descartar: vuelve a lo guardado | Correcto |
| Consentimiento: un clic pasa de opcional a obligatorio | Correcto |
| Ya sin cambios, elegir la otra ficha: la abre | Correcto |

- Se revisó el aspecto del menú, de Horarios y de la hoja editable en una captura a 1440 px.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **Nota sobre el plegado del menú:** el navegador de prueba no ejecuta animaciones, así que se comprobó que el grupo queda cerrado (alto cero y flecha girada), pero no se vio la animación ni la de volver a abrir. El código de plegado no se tocó.
- No se probó: el menú contraído (solo iconos), el menú de la plataforma, el tema oscuro, Horarios como profesional, ni la hoja editable con el odontograma.

## 7. Para aplicar

Menú, Horarios y ficha editable no requieren resetear. Para que la barbería pierda Clases (y por todo lo anterior sin aplicar), resetear con `init.sql`, `seed.sql` y `seed_demo.sql`.
