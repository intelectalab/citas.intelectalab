# 2026-10-10 — La tabla de profesionales se actualiza al editar, y revisión de las demás pantallas

## Contexto

Al editar un profesional la tabla seguía mostrando los datos anteriores hasta recargar la página. Pedido: que se actualice sola, y revisar si otras pantallas tienen el mismo problema.

## Causa

El formulario de profesional avisaba a la tabla solo al **crear**. Al **editar** guardaba, se cerraba y no avisaba, así que la tabla no volvía a pedir los datos.

## Revisión de las demás pantallas

Se revisó en el código qué hace cada pantalla después de guardar, editar o eliminar.

| Pantalla | Resultado |
|---|---|
| Profesionales: editar | **Fallaba. Corregido.** |
| Servicios: categoría creada desde el formulario de servicio | **Fallaba a medias. Corregido.** La categoría nueva no llegaba al filtro "Categoría" de la tabla ni al formulario al abrirlo otra vez, hasta recargar. |
| Clientes (tabla y página del cliente), mascotas, acceso al portal | Ya se actualizaban |
| Servicios: crear, editar, eliminar | Ya se actualizaban |
| Sucursales (tabla, página y horario) | Ya se actualizaban |
| Citas y agenda | Ya se actualizaban |
| Lista de espera, controles, bloqueos y feriados | Ya se actualizaban |
| Plantillas de ficha | Ya se actualizaban |
| Horarios del equipo | Ya se actualizaba al cerrar el panel |
| Empresa | Ya se actualizaba |
| Plataforma: planes, empresas (tabla y página), rubros | Ya se actualizaban |

Sigue como estaba (ya anotado en pendientes): un servicio creado con "+" desde lista de espera o controles queda elegido en el formulario, pero la pantalla de atrás no lo recibe hasta recargar.

## Cambios

| Archivo | Contenido |
|---|---|
| `apps/web/src/features/professionals/components/FormProfessional.tsx` | Al editar también avisa a la tabla. |
| `apps/web/src/features/services/components/ServicesTable.tsx` | Vuelve a pedir las categorías al cerrar el formulario de servicio. |

Sin cambios de base ni de API.

## Verificación

- En el navegador, contra una base temporal (ya eliminada), con clics reales: en Centro Médico, lápiz de "Dr. Jorge Lazo", cambiar el nombre y "Guardar Cambios". El panel se cierra y la fila pasa a "Dr. Jorge Lazo Prueba" sin recargar.
- `tsc --noEmit` sin errores en `src`.
- No se probó en pantalla el cambio de Servicios (categoría nueva en el filtro). La revisión de las demás pantallas fue de código, no un recorrido en el navegador.

## Para aplicar

No requiere resetear la base. Basta recargar la página una vez.
