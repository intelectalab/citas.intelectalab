# 2026-10-10 — Paginación de a seis, listas del mismo alto y tarjetas que filtran en las páginas de cliente y profesional

## Contexto

Cuatro pedidos:

1. En la página del cliente, las listas "Próximas" y "Anteriores" medían distinto: que midan lo mismo.
2. Que la paginación funcione en todas partes, mostrando 6 a la vez.
3. Que las tarjetas de números de las páginas de cliente y de profesional (Atenciones, Citas próximas, Canceladas y ausencias, Fichas) funcionen como filtro dentro de la misma página.
4. Que "Historia de atenciones" quede arriba, pegada a las tarjetas, y no en medio del espacio vacío.

Sin cambios de base ni de API.

## 1. Paginación de a seis

**En todas las tablas.** El pie de cada tabla ya funciona: muestra 6 filas, "1–6 · Página 1 de 3" y las flechas, que se desactivan en la primera y en la última página. El contador sigue diciendo el total ("17 pacientes registrados"). Al buscar, filtrar, ordenar o cambiar de pestaña se vuelve a la primera página. El filtro y el orden se aplican sobre todas las filas, no solo sobre la página visible.

**En las listas de las páginas de cliente y de profesional.** Citas próximas, citas anteriores e historia de atenciones se muestran de a 6, con su "Página 1 de 3" al pie. Ya no tienen barra de desplazamiento propia.

## 2. Listas del mismo alto

"Próximas" y "Anteriores" miden lo mismo: cada una muestra hasta 6 citas y su paginación queda siempre al pie, alineada con la de al lado.

## 3. Tarjetas que filtran

Un clic en una tarjeta deja en la lista de citas, de esa misma página, solo las que la tarjeta cuenta, y lleva la vista hasta la lista. La tarjeta queda marcada. Otro clic en la misma tarjeta, o "Ver todas las citas", vuelve a las dos listas de siempre.

| Página | Tarjeta | Qué deja en la lista |
|---|---|---|
| Cliente y profesional | Atenciones | Las citas atendidas, de la más reciente a la más antigua |
| Cliente y profesional | Citas próximas | Las que vienen |
| Cliente y profesional | Canceladas y ausencias | Las canceladas y las que no asistió |
| Profesional | Citas de hoy | Las de hoy |
| Cliente | Fichas | Lleva a "Historia de atenciones" |
| Cliente y profesional | Tiempo promedio | No filtra: es un promedio, no una lista |

## 4. Historia de atenciones arriba

Las tarjetas de números y la historia (en el profesional, el horario) ahora forman una sola columna: la historia queda justo debajo de las tarjetas. Antes la tarjeta de datos, a la izquierda, estiraba la primera fila y dejaba un hueco.

## 5. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/app/components/common/Pager.tsx` | Nuevo. Tamaño de página (6), reparto en páginas, las flechas y la lista paginada. |
| `apps/web/src/app/components/ui/table/DataTable.tsx` | Paginación en el pie de la tabla. |
| `apps/web/src/features/customers/components/CustomerDetail.tsx` | Listas paginadas, tarjetas que filtran, historia pegada a las tarjetas. |
| `apps/web/src/features/professionals/components/ProfessionalDetail.tsx` | Lo mismo en la página del profesional. |

## 6. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales en Centro Médico:

| Prueba | Resultado |
|---|---|
| Clientes: 17 en total; la tabla muestra 6 y "1–6 · Página 1 de 3"; flecha anterior desactivada | Correcto |
| Siguiente, dos veces | "7–12 · Página 2 de 3" y "13–17 · Página 3 de 3" (5 filas); flecha siguiente desactivada |
| Anterior | Vuelve a la página 2 |
| Buscar estando en la página 2 | Vuelve a la página 1 |
| Página de una clienta: Próximas (16) y Anteriores (17) | 6 filas cada una, "Página 1 de 3" en ambas y el mismo alto |
| Tarjeta "Atenciones" | Queda marcada; la lista pasa a "Atenciones (14)", todas en fase Atendida, de a 6; la flecha lleva a la página 2 |
| Tarjeta "Canceladas y ausencias" | "Canceladas y ausencias (3)" |
| "Ver todas las citas" | Vuelven Próximas y Anteriores |
| La historia de atenciones | Queda a 20 px debajo de las tarjetas |
| Página de una médica: Próximas (22) y Anteriores (42) | 6 y 6, mismo alto, "Página 1 de 4" y "Página 1 de 7" |
| Tarjeta "Citas de hoy", y otro clic en ella | Filtra y vuelve a las dos listas |
| El horario | Queda a 20 px debajo de las tarjetas |

- Se revisó en una captura la página del profesional con sus dos listas.
- `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** la paginación en las demás tablas una por una (todas usan la misma tabla; se probó Clientes), la tarjeta "Fichas" y la historia paginada (la base de ejemplo no trae fichas), el aspecto de la página del cliente en una captura (la captura falló), el tema oscuro y el celular.

## 7. Para aplicar

No requiere resetear la base. Basta recargar la página.
