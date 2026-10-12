# 2026-10-12 — Grupo "Equipo y catálogo" en el menú y pantalla "Salas y equipos"

## Contexto

En el prototipo, servicios, profesionales (con sus horarios) y salas y equipos forman un mismo grupo del menú: "Equipo y catálogo". Aquí estaban repartidos entre "Atención" y "Tu empresa", y Salas y equipos no tenía pantalla propia: solo se veían dentro de la página de cada sucursal.

Pedido: mover Servicios, Médicos (profesionales) y Horarios del equipo a ese grupo, y crear Salas y equipos.

Sin cambios de base: la tabla `rooms` ya existía.

## 1. El menú

| Grupo | Antes | Ahora |
|---|---|---|
| Atención | Pacientes, Servicios, Fichas de atención | Pacientes, Fichas de atención |
| **Equipo y catálogo** (nuevo) | — | Servicios, Médicos, Horarios del equipo, Salas y equipos |
| Tu empresa | Médicos, Horarios del equipo, Sucursales, Empresa | Sucursales, Empresa |

- "Médicos" sigue usando la palabra de cada rubro (Barberos, Estilistas…).
- **Quién ve qué no cambió:** Médicos y Horarios del equipo siguen siendo solo para el administrador; Servicios lo sigue viendo todo el equipo. Salas y equipos lo ve todo el equipo, y solo el administrador lo cambia.
- Salas y equipos aparece solo en las empresas que tienen ese módulo en su plan.
- Las direcciones de las pantallas no cambiaron: solo se movieron en el menú.

## 2. Salas y equipos (`/rooms`)

Reúne en una sola tabla las salas y los equipos de **todas las sedes**.

| Parte | Detalle |
|---|---|
| Pestañas | Todos, Salas, Equipos, con su cantidad |
| Tarjetas | Salas en uso, Equipos en uso, Fuera de uso; las tres filtran la tabla |
| Columnas | Nombre y descripción, tipo, sede (lleva a la página de la sucursal), estado |
| Filtros | Sede (si hay más de una) y estado |
| Ordenar por | Nombre, tipo, sede, estado, fecha de registro |
| Buscar | Por nombre, descripción o sede, sin importar tildes |
| Nueva sala o equipo | Panel lateral: tipo, sede, nombre, descripción y si está en uso |
| Editar | El nombre o el lápiz abren el mismo panel. La sede no se cambia: si algo se mudó, se registra en la otra sede y se desactiva este. |
| Eliminar | Pide confirmar. Si ya tiene citas registradas no se elimina: queda fuera de uso. |

Quien no administra la empresa ve la tabla sin el botón ni las acciones. Las salas se siguen pudiendo manejar también desde la página de cada sucursal, como antes.

## 3. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/app/components/layout/Sidebar.tsx` | Grupo nuevo; ítems que solo ve el administrador dentro de un grupo que ve todo el equipo. |
| `apps/web/src/features/rooms/components/RoomsTable.tsx`, `FormRoom.tsx`, `app/(dashboard)/rooms/page.tsx` | Nuevos. La pantalla y su panel. |
| `apps/api/app/Http/Controllers/Api/RoomController.php`, `routes/api.php` | `GET /api/rooms`: todas las salas y equipos de la empresa con su sede. |

## 4. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales, como dueña de Centro Médico:

| Prueba | Resultado |
|---|---|
| Menú | "Equipo y catálogo" con Servicios, Médicos, Horarios del equipo y Salas y equipos; "Atención" con Pacientes y Fichas; "Tu empresa" con Sucursales y Empresa |
| Salas y equipos | 4 registros de dos sedes; pestañas Todos 4, Salas 3, Equipos 1; tarjetas 3, 1 y 0 |
| "Nueva sala o equipo", elegir Equipo | El ejemplo del nombre pasa a "Ej. Ecógrafo 1" |
| Guardar sin elegir sede | "Elige la sede donde está." |
| Elegir Sede Surco, nombre y descripción, "Registrar equipo" | Aparece en la tabla; Equipos en uso pasa a 2 |
| Clic en su nombre | Abre "Editar: Láser de prueba" con la sede bloqueada |
| Apagar "En uso" y guardar | La fila pasa a "Fuera de uso" y la tarjeta a 1 |
| Tarjeta "Fuera de uso" | Deja solo ese registro |
| Pestaña "Salas" | 3 filas |
| Eliminar, con su confirmación | "Láser de prueba se eliminó."; vuelven 4 |

- Se revisó el aspecto del menú y de la pantalla en una captura.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** el menú como profesional (no debe ver Médicos ni Horarios del equipo), una empresa sin el módulo de salas, eliminar algo con citas registradas, el filtro de sede, el tema oscuro y el celular.

## 5. Para aplicar

No requiere resetear la base. Basta recargar la página.
