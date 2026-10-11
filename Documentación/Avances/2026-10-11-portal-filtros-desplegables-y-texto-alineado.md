# 2026-10-11 — Portal: filtros desplegables y texto alineado en las fichas de filtro

## Contexto

Dos observaciones sobre "Mis citas" del portal:

1. El texto dentro de las fichas de filtro ("Estado: Todos") se veía desalineado.
2. Tener los filtros siempre a la vista se veía raro; mejor un desplegable.

Sin cambios de base ni de API.

## 1. Texto alineado

**Causa.** La etiqueta ("Estado:") y el valor ("Todos") estaban en cajas de distinto tipo: el valor quedaba dentro de un contenedor en línea, que reserva un espacio extra debajo del texto, y por eso bajaba un poco respecto de la etiqueta.

**Solución.** El valor de la lista pequeña (`Select` en su versión de ficha) y el del filtro de fecha ya no usan ese contenedor en línea, y etiqueta y valor comparten la misma altura de línea. El arreglo está en las piezas compartidas, así que vale también para las barras "Filtrar por" y "Ordenar por" de todas las tablas del panel.

## 2. Filtros desplegables

"Mis citas" tiene ahora un título y, a su derecha, el botón **Filtrar**:

- Los filtros están guardados; un clic en el botón los despliega y otro los guarda.
- El botón muestra **cuántos filtros hay puestos**, también con la barra guardada, para que no se olvide que la lista está filtrada.
- Guardar la barra **no quita** los filtros: para eso está "Limpiar filtros".

## 3. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/app/components/common/Select.tsx` | Versión de ficha: valor centrado con su etiqueta. |
| `apps/web/src/app/components/ui/table/DateFilter.tsx` | Lo mismo en el filtro de fecha. |
| `apps/web/src/app/components/ui/table/DataTable.tsx` | Etiquetas de las fichas con la misma altura de línea. |
| `apps/web/src/features/portal/components/PortalAppointments.tsx` | Título "Mis citas", botón "Filtrar" y barra desplegable. |

## 4. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales, como clienta de Centro Médico:

| Prueba | Resultado |
|---|---|
| Al entrar a "Mis citas" | Título y botón "Filtrar"; la barra está guardada |
| Clic en "Filtrar" | La barra se despliega con Estado, Servicio, Médico, Sede y Fecha |
| Alineación de las cinco fichas | El centro de la etiqueta, el del valor y el de la ficha coinciden al medio píxel |
| Estado "Confirmada" | Próximas (9); el botón muestra "1" |
| Guardar la barra | El botón sigue mostrando "1" y la lista sigue en 9 |

- Se revisó en una captura el encabezado de esquina a esquina, el botón y la barra desplegada.
- `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** la alineación en las barras del panel (comparten el arreglo, pero no se midió ahí) ni el portal en celular.

## 5. Para aplicar

No requiere resetear la base. Basta recargar la página.
