# 2026-10-12 — Equipos instalados en una sala, y qué pasa con la sala si el equipo queda fuera de uso

## Contexto

Salas y equipos queda por ahora como inventario: no interviene en las citas. Pedido: que la empresa decida, en su configuración, qué pasa con una sala cuando un equipo de esa sala queda fuera de uso. Por defecto, no pasa nada.

Para eso hacía falta algo que no existía: saber **en qué sala está** cada equipo.

## 1. Sala donde está un equipo

En el panel de "Nueva sala o equipo", al elegir el tipo **Equipo** aparece la lista **"Sala donde está"**, con las salas de la sede elegida. Es opcional: un equipo que se mueve entre consultorios se deja como "No está en una sala fija".

En la tabla, el equipo muestra debajo de su nombre "En Consultorio 201".

## 2. La regla, en Configuración

Sección nueva **"Salas y equipos"** en la configuración de la empresa (solo para empresas con ese módulo). Tres opciones:

| Opción | Qué pasa con la sala cuando un equipo suyo queda fuera de uso |
|---|---|
| **No pasa nada** (por defecto) | La sala sigue en uso, sin ningún aviso. |
| **Mostrarlo como observación** | La sala sigue en uso, con una nota: "Observación: Ecógrafo fuera de uso". |
| **Dejar la sala fuera de uso** | La sala pasa sola a "Fuera de uso", con la nota "Por Ecógrafo fuera de uso". Cuando el equipo vuelve a estar en uso, la sala vuelve sola. |

Cómo funciona "dejar la sala fuera de uso": la sala no se apaga de verdad en la base. Se **muestra** fuera de uso mientras tenga un equipo fuera de uso. Por eso regresa sola cuando el equipo vuelve, y si la empresa cambia de opción todo se ve de inmediato con la regla nueva, sin tener que corregir nada a mano. Una sala que alguien apagó a mano sigue apagada pase lo que pase con sus equipos.

Las tarjetas (Salas en uso, Fuera de uso), el filtro de estado y el orden usan ese estado que se muestra.

## 3. Base de datos

`init.sql`: columna nueva `rooms.parent_id` (la sala donde está instalado un equipo). `seed_demo.sql`: tres equipos de ejemplo quedan en una sala de su sede (Ecógrafo en Consultorio 201, Equipo de rayos X en Sillón 1, Equipo láser en Cabina 1).

La regla se guarda en `tenant_settings.rules`, que ya existía.

Mientras `erp_saas` no se resetee, la pantalla sigue funcionando: solo no aparece la lista "Sala donde está".

## 4. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/init.sql`, `seed_demo.sql` | Columna `parent_id` y equipos de ejemplo en su sala. |
| `apps/api/app/Http/Controllers/Api/RoomController.php` | Guarda la sala del equipo; la lista trae cómo queda cada sala según la regla. |
| `apps/api/app/Support/AppointmentFlow.php`, `TenantSettingController.php` | La regla, con "no pasa nada" por defecto, y su lectura y guardado. |
| `apps/web/src/features/rooms/components/RoomsTable.tsx`, `FormRoom.tsx` | "Sala donde está", la nota y el estado que se muestra. |
| `apps/web/src/features/settings/components/TenantSettings.tsx` | La sección "Salas y equipos". |

## 5. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada); los tres equipos de ejemplo quedan enlazados a su sala.

En el navegador, con clics reales, como dueña de Centro Médico:

| Prueba | Resultado |
|---|---|
| Salas y equipos | El Ecógrafo dice "En Consultorio 201"; tarjetas 3, 1 y 0 |
| Apagar el Ecógrafo, con la opción por defecto | El Consultorio 201 sigue "En uso", sin nota; Salas en uso 3 |
| Configuración | La sección "Salas y equipos" aparece con "No pasa nada" marcada |
| Elegir "Mostrarlo como observación" y guardar | El Consultorio 201 sigue "En uso" con "Observación: Ecógrafo fuera de uso"; Salas en uso 3 |
| Elegir "Dejar la sala fuera de uso" y guardar | El Consultorio 201 pasa a "Fuera de uso" con "Por Ecógrafo fuera de uso"; Salas en uso 2, Fuera de uso 2 |
| Volver a encender el Ecógrafo | El Consultorio 201 vuelve solo a "En uso", sin nota; tarjetas 3, 1 y 0 |
| Nuevo: tipo Sala | No aparece "Sala donde está" |
| Nuevo: tipo Equipo, sin sede | "Sala donde está" bloqueada: "Elige primero la sede" |
| Elegir Sede Miraflores | La lista ofrece Consultorio 101 y 102 |
| Registrar un equipo en el Consultorio 102 | Aparece con "En Consultorio 102" |

- Se revisó en una captura la tabla con una sala fuera de uso por su equipo.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la primera pasada de la prueba, mi guion apagó por error la sala en lugar del equipo (buscaba la fila por un texto que también aparecía en la nota de la sala). Se restauró la base temporal y se repitió buscando por el nombre exacto; los resultados de arriba son de esa segunda pasada.
- **No se probó en pantalla:** la sección de salas dentro de la página de la sucursal (sigue mostrando el estado tal cual, sin la nota), una empresa sin el módulo, el tema oscuro y el celular.

## 6. Ampliación del mismo día: la página de la sucursal y el aviso al administrador

### La página de la sucursal queda igual que Salas y equipos

La tarjeta "Salas y equipos" de cada sucursal ahora:

- Aplica la regla de la empresa: una sala se ve "fuera de uso" con la nota "Por Ecógrafo fuera de uso", o en uso con "Observación: …", según lo elegido.
- Muestra en qué sala está cada equipo ("Equipo · en Consultorio 201").
- Usa **el mismo panel** que la pantalla Salas y equipos para agregar y editar, con la lista "Sala donde está". Antes tenía un formulario propio, más corto. Al agregar desde ahí, la sede ya viene puesta.
- La tarjeta de números cuenta lo que está en uso según la regla.

La lógica quedó en un solo lugar en la API (`App\Support\Rooms`), que usan las dos pantallas.

### Aviso al administrador

Cuando un equipo instalado en una sala pasa de "en uso" a "fuera de uso" (o vuelve), los administradores lo reciben en la campana, según la opción de la empresa:

| Opción | Al apagar el equipo | Al encenderlo |
|---|---|---|
| No pasa nada | Sin aviso | Sin aviso |
| Mostrarlo como observación | "Equipo fuera de uso en una sala" | "Equipo de vuelta en uso" |
| Dejar la sala fuera de uso | "Consultorio 201 quedó fuera de uso" | "Consultorio 201 volvió a estar en uso" |

- El aviso dice el equipo, la sala y la sede, y lleva a Salas y equipos.
- Lo recibe **también quien hizo el cambio**. En las citas nadie recibe aviso de lo que hizo él mismo; aquí sí, porque que la sala quede fuera de uso es una consecuencia que puede no tener presente.
- Guardar el equipo sin cambiar su estado no avisa. Tampoco avisa si la sala ya estaba apagada a mano o ya estaba fuera de uso por otro equipo: para la sala no cambió nada.
- Un equipo sin sala asignada no genera avisos.

### Archivos de esta ampliación

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Rooms.php` | Nuevo. Cómo se muestra cada sala según la regla, y el aviso. |
| `apps/api/app/Http/Controllers/Api/RoomController.php`, `BranchController.php` | Usan esa lógica; el cambio de un equipo dispara el aviso. |
| `apps/web/src/features/branches/components/BranchDetail.tsx`, `branches.ts` | La tarjeta aplica la regla y usa el panel compartido. |
| `apps/web/src/features/rooms/components/FormRoom.tsx`, `RoomsTable.tsx` | Comparten el tipo de la sala con la página de la sucursal. |
| `apps/web/src/features/notifications/notifications.tsx` | Iconos de los avisos nuevos. |

### Verificación de esta ampliación

En el navegador, con clics reales, como dueña y con la opción "Dejar la sala fuera de uso":

| Prueba | Resultado |
|---|---|
| Página de la Sede San Borja | El Ecógrafo dice "Equipo · en Consultorio 201" |
| Lápiz del Ecógrafo | Abre "Editar: Ecógrafo" con la sede bloqueada y "Sala donde está = Consultorio 201" |
| Apagarlo y guardar | El Consultorio 201 pasa a "fuera de uso" con "Por Ecógrafo fuera de uso"; la tarjeta de números baja a 0 |
| La campana | Sube de 4 a 5 con "Consultorio 201 quedó fuera de uso … Sede San Borja" |
| Encenderlo | El consultorio vuelve a estar en uso; llega "Consultorio 201 volvió a estar en uso" |
| "Agregar" desde la sucursal | El panel abre con la Sede San Borja ya elegida |

Por la API:

| Caso | Resultado |
|---|---|
| "No pasa nada": apagar y encender el equipo | Ningún aviso |
| "Observación": apagarlo | "Equipo fuera de uso en una sala" |
| Guardar otra vez sin cambiar el estado | No se repite |
| Encenderlo | "Equipo de vuelta en uso" |
| "Dejar fuera de uso", con la sala ya apagada a mano | No avisa |
| Un profesional intenta cambiar un equipo | 403; no recibe avisos |

- Se revisó en una captura la tarjeta de la sucursal con la sala fuera de uso.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.

## 7. Para aplicar

Hay una columna nueva: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`, en ese orden.
