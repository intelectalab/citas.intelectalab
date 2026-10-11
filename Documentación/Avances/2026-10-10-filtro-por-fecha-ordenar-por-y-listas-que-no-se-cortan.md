# 2026-10-10 — Filtro por fecha, "Ordenar por" y listas que ya no se cortan

## Contexto

Tres pedidos sobre las tablas:

1. Que los botones **Filtro** y **Ordenar por** funcionen en todas las pantallas, teniendo en cuenta que cada una tiene distintas cosas por las que filtrar u ordenar.
2. Un filtro de **fecha** con periodos listos (como en la referencia enviada) y que permita elegir una fecha específica.
3. "Ordenar por" con el **mismo diseño que la barra de filtros**; con ambas abiertas, la de orden queda debajo.

Y un problema: el menú de las listas de la barra de filtros **se cortaba** al llegar al borde de la barra.

## 1. El menú de las listas ya no se corta

El menú se dibujaba dentro de la barra, y la barra recorta lo que sobresale. Ahora el menú de **todas** las listas (el componente `Select`) flota sobre la página:

- No lo recorta la barra de filtros, una tabla ni un panel lateral.
- Si abajo no hay lugar, se abre hacia arriba; si hay poco, se acorta y se desplaza.
- Sigue a lo que lo abrió si la página o el panel se desplazan, y se cierra con un clic fuera o con Escape.

El aspecto del `Select` no cambió. Esto resuelve también el pendiente de los menús que quedaban fuera de la vista al final de un panel largo.

## 2. Filtro por fecha

En la barra "Filtrar por" aparece una ficha por cada fecha de la pantalla (por ejemplo "Fecha de la cita: Todas"). Al abrirla:

| Parte | Detalle |
|---|---|
| Buscador | Filtra la lista de periodos. |
| Periodos listos | Hoy, Ayer, Esta semana, Semana pasada, Últimos 7 días, Este mes, Mes pasado, Últimos 30 días, Este año; cada uno con su explicación debajo. |
| Periodos hacia adelante | Mañana, Próxima semana, Próximos 7 días, Próximo mes: solo en fechas que pueden ser futuras (citas, controles, bloqueos, feriados). |
| Una fecha específica | Abre un calendario propio (no el del navegador); un clic en el día lo aplica. |
| Entre dos fechas | El mismo calendario; se elige el primer día y luego el último. Si se eligen al revés, los ordena. |
| Todas las fechas | Quita el filtro. |

El botón **Filtro** muestra cuántos filtros hay puestos, y la barra tiene **Limpiar filtros** cuando hay alguno.

## 3. Ordenar por

El botón abre una barra igual a la de filtros: "Ordenar por: Campo: …" y, al elegir un campo, dos opciones para el sentido. El sentido se nombra según lo que se ordena:

| Tipo de dato | Sentidos |
|---|---|
| Fecha | Más reciente / Más antiguo |
| Texto | A → Z / Z → A |
| Número | Mayor primero / Menor primero |

- Lo que no tiene dato va siempre al final.
- El botón pasa a decir "Orden: Apellido" mientras hay un orden puesto; **Quitar orden** vuelve al orden original.
- Con las dos barras abiertas, la de orden queda debajo de la de filtros.
- El orden ignora tildes y mayúsculas.

## 4. Qué tiene cada pantalla

| Pantalla | Filtro por fecha | Ordenar por |
|---|---|---|
| Citas | Fecha de la cita | Fecha y hora, código, cliente, servicio, profesional, fase, precio, duración |
| Clientes | Fecha de registro | Fecha de registro, código, nombre, apellido, documento, distrito |
| Profesionales | Fecha de registro | Nombre, correo, colegiatura (si el rubro la usa), especialidades, estado, fecha de registro |
| Servicios | Fecha de creación | Nombre, categoría, precio, duración, estado, fecha de creación |
| Lista de espera | Anotado el; Le sirve desde | Días en espera, anotado el, le sirve desde, cliente, servicio, profesional |
| Controles | Para cuándo | Para cuándo, cliente, control, servicio, profesional, estado |
| Bloqueos y ausencias | Cuándo (entra si el bloqueo toca el rango) | Inicio, fin, a quién aplica, motivo, citas dentro |
| Feriados | Fecha | Fecha, feriado, sede, citas ese día |
| Fichas de atención | Fecha de la ficha | Fecha, cliente, ficha, profesional, estado |
| Sucursales | — | Nombre, distrito, profesionales, citas de hoy, citas próximas |
| Contactos | — | Nombre, apellido, correo |
| Plataforma: empresas | Fecha de alta | Fecha de alta, nombre, rubro, plan, estado |
| Plataforma: planes | Fecha de creación | Nombre, precios, empresas suscritas, estado, fecha de creación |
| Plataforma: rubros | — | Nombre, empresas, módulos que no usa, estado |

Los filtros de lista que ya tenía cada pantalla (fase, profesional, servicio, sede, estado…) siguen igual.

- Una pantalla sin nada que filtrar ya no muestra el botón **Filtro** (Sucursales, Contactos).
- **Citas y Fichas** piden sus filas por periodo (las pestañas). Mientras hay un filtro de fecha puesto, manda el filtro: se piden las de ese rango, aunque la pestaña diga otra cosa.

## 5. Clientes: la tabla recibía solo 10

Al probar el orden se vio que la tabla de Clientes mostraba solo los 10 más recientes (la API los entrega de 10 en 10 y la tabla no pasa de página). Ordenar habría ordenado solo esos 10. Ahora la tabla pide la lista completa (hasta 500), como las demás. Los buscadores de cliente de los formularios siguen pidiendo 10.

## 6. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/app/components/common/Floating.tsx` | Nuevo. Panel flotante sobre la página. |
| `apps/web/src/app/components/common/Select.tsx` | El menú usa el panel flotante. |
| `apps/web/src/app/components/ui/table/dateFilters.ts` | Nuevo. Periodos, rangos y comparación de fechas. |
| `apps/web/src/app/components/ui/table/DateFilter.tsx` | Nuevo. Ficha de fecha con periodos y calendario propio. |
| `apps/web/src/app/components/ui/table/DataTable.tsx`, `DataTable.module.css` | `dateFilters` y `sortOptions`; barra de orden; contador y limpiar filtros. |
| Las 14 tablas de `features/*/components` | Sus fechas y sus criterios de orden. |
| `apps/web/src/features/appointments/components/AppointmentsTable.tsx`, `features/records/components/RecordsTable.tsx` | El filtro de fecha manda sobre el periodo de la pestaña. |
| `apps/api/app/Http/Controllers/Api/CustomerController.php`, `features/customers/components/CustomersTable.tsx` | `per_page` (hasta 500); la tabla pide la lista completa. |

Sin cambios de base.

## 7. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales:

| Prueba | Resultado |
|---|---|
| Citas: abrir "Fase" en la barra de filtros | El menú flota fuera de la barra, completo; elegir "Pendiente" pasa de 132 a 65 filas y el botón muestra "1" |
| Clientes: ahora llegan 17 (antes 10) | Correcto |
| Clientes: ordenar por Apellido | Aguirre, Castillo, Chávez, Córdova, Delgado…; "Z → A" lo invierte y queda marcado |
| Clientes: las dos barras abiertas | "Ordenar por" queda debajo de "Filtrar por" |
| Clientes: Sexo "Masculino" | 7 filas |
| Clientes: fecha "Hoy" | La ficha dice "Hoy" |
| Clientes: "Una fecha específica", clic en el día de hoy | La ficha dice "10 oct 2026" y el calendario se cierra |
| Clientes: "Entre dos fechas", clic en el 8 y luego en el 3 | Queda "3 oct 2026 – 8 oct 2026"; el botón muestra "2" filtros |
| Clientes: "Limpiar filtros" | Vuelven todas las filas y la ficha dice "Todas" |
| Citas: fecha "Mes pasado" (la pestaña era "Próximos 7 días") | Trae las 201 citas de setiembre |
| Citas: ordenar por "Fecha y hora" | "Más reciente" primero el 30 de setiembre; "Más antiguo" primero el 19 |
| Citas: "Quitar orden" y "Limpiar filtros" | Vuelve "Sin ordenar" y las 132 citas |
| Servicios: ordenar por Precio | 200, 180, 150, 80…; sentidos "Mayor primero / Menor primero" |
| Formulario de cliente: lista "Tipo de documento" (al pie del panel) | El menú se abre hacia arriba, con el ancho del campo; elegir una opción la deja puesta y el panel sigue abierto |
| Formulario de servicio: lista "Categoría" | El menú se ve completo sobre el panel |
| Controles, Lista de espera, Bloqueos, Fichas, Sucursales, Profesionales | Cargan con sus botones (Sucursales, solo "Ordenar por") y sin errores |

- Se revisó en capturas la barra de orden, el panel de periodos y el menú dentro de un panel lateral.
- `tsc --noEmit` sin errores en `src`. `php -l` sin errores.
- El navegador de prueba no ejecuta animaciones: la apertura de las barras se comprobó por su contenido, no viendo la animación.
- **No se probó en pantalla:** Feriados, Contactos y las tres tablas de la plataforma (empresas, planes, rubros); cada criterio de orden y cada filtro de fecha uno por uno (se probaron los de Clientes, Citas y Servicios); el filtro de fecha en Fichas; el tema oscuro; y las listas del resto de formularios con el menú flotante (se probaron cliente y servicio).

## 8. Corrección del mismo día: el menú "viajaba" desde la esquina

Al abrir una lista o el filtro de fecha, el panel se veía moverse desde la esquina superior izquierda de la pantalla hasta su sitio.

**Causa.** El panel nace en la esquina (oculto) mientras se calcula dónde va, y enseguida se coloca. Sus clases incluían una duración (`duration-150`, pensada para la aparición suave); una duración sin indicar qué propiedad anima hace que el navegador anime **todas**, también la posición. Por eso el salto de la esquina a su sitio se veía como un recorrido de 150 ms. Antes no pasaba porque el menú no cambiaba de posición al abrirse.

**Solución.** El panel flotante (`Floating.tsx`) ya no admite transiciones: se coloca en su sitio antes de pintarse. La aparición suave se mantiene.

**Verificación.** En el navegador, contra una base temporal (ya eliminada), en Profesionales: al abrir "Fecha de registro", "Estado" y "Ordenar por", 20 ms después del clic el panel ya está exactamente en su posición final y no tiene ninguna transición en curso (antes, a los 400 ms todavía iba de camino). No se pudo ver el movimiento a simple vista: el navegador de prueba no ejecuta animaciones.

## 9. Para aplicar

No requiere resetear la base. Basta recargar la página.
