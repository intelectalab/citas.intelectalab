# 2026-10-10 — Portal del cliente: filtros en "Mis citas", sección "Mis datos" y encabezado de esquina a esquina

## Contexto

Tres pedidos sobre el portal del cliente:

1. Un filtro en sus citas.
2. Que el nombre de la empresa quede en una esquina y "Cerrar sesión" en la otra (el menú estaba bien).
3. Una sección del menú solo para sus datos.

Y una duda: si todas las sedes ofrecen los mismos servicios, porque en el portal se elige el servicio antes que la sede.

Sin cambios de base ni de API.

## 1. Filtros en "Mis citas"

Una barra "Filtrar por" con el mismo aspecto que la de las tablas del panel:

| Filtro | Opciones |
|---|---|
| Estado | Las fases que tienen sus propias citas (Pendiente, Confirmada, Atendida, Cancelada…) |
| Servicio | Los servicios de sus citas |
| Profesional | Quienes lo han atendido o lo atenderán (con la palabra del rubro: Médico, Barbero…) |
| Sede | Las sedes de sus citas |
| Fecha | Los mismos periodos y el mismo calendario que en el panel, incluidos los que miran hacia adelante |

- Las opciones salen de las citas de la persona: no se le ofrece filtrar por algo que no tiene.
- Una lista con una sola opción no se muestra (por ejemplo, Sede en una empresa de una sola sede).
- Los filtros se combinan y valen para "Próximas" y "Anteriores" a la vez; los títulos muestran cuántas quedan.
- "Limpiar filtros" aparece cuando hay alguno puesto.
- Las citas se muestran **de a 6**, con su paginación, igual que en el panel. Al cambiar un filtro se vuelve a la primera página.

## 2. Encabezado

El nombre de la empresa queda pegado a la esquina izquierda de la pantalla y "Cerrar sesión" a la derecha. El menú sigue centrado, alineado con el contenido.

## 3. Sección "Mis datos"

Nueva pestaña en el menú. La tarjeta "Tus datos", que estaba al final del inicio, pasó ahí:

- Nombre, código y **correo con el que ingresa** (antes no se mostraba).
- Celular, con "Cambiar".
- Contraseña, con "Cambiar".
- Sus mascotas o dependientes, en los rubros que los usan.
- Una línea que explica que para corregir nombre, correo o documento debe comunicarse con la empresa.

El inicio queda con el saludo, la próxima cita y el botón para reservar.

## 4. La duda: servicios y sedes

Hoy **un servicio no se asigna a una sede**: al crear un servicio queda disponible en todas las sedes de la empresa. Por eso el portal puede pedir primero el servicio y luego la sede.

Lo que sí distingue a una sede de otra es **quién atiende ahí**: cada profesional tiene su horario en una sede. Al elegir el servicio y la sede, el portal ofrece los horarios de los profesionales que atienden en esa sede. Si en una sede nadie tiene horario libre, simplemente no aparecen horas para ese día.

Limitar un servicio a ciertas sedes ("la ecografía solo en Miraflores") no existe todavía. Está anotado en pendientes como "Servicios por sede".

## 5. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/web/src/features/portal/components/PortalAppointments.tsx` | Nuevo. "Mis citas" con sus filtros y su paginación. |
| `apps/web/src/features/portal/components/Portal.tsx` | Encabezado de esquina a esquina, pestaña "Mis datos" y uso de la lista nueva. |

Se reutilizan las piezas del panel: la lista `Select`, el filtro de fecha y la paginación.

## 6. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales, entrando como una clienta de Centro Médico:

| Prueba | Resultado |
|---|---|
| Encabezado a 1440 px | El nombre queda a 32 px del borde izquierdo y "Cerrar sesión" pegado al derecho |
| Menú | Inicio, Reservar, Mis citas, Mis datos |
| Inicio | Ya no trae la tarjeta de datos |
| Mis datos | Nombre, código, correo, celular y contraseña; "Cambiar" del celular abre el campo |
| Mis citas | Próximas (16) y Anteriores (17), 6 tarjetas cada una, "Página 1 de 3" |
| Flecha siguiente en Próximas | "Página 2 de 3"; Anteriores sigue en la 1 |
| Filtros disponibles | Estado, Servicio, Médico, Sede y Fecha |
| Estado "Pendiente" | Próximas (8), todas pendientes; Anteriores (0) |
| Además, Fecha "Próxima semana" | Próximas (6) |
| "Limpiar filtros" | Vuelven 16 y 17 |
| Sede "Sede Central - Miraflores" | Próximas (5) y Anteriores (6), sin paginación porque caben |

- Se revisó en una captura el encabezado y la barra de filtros (la captura salió recortada: no se vio la esquina derecha, que se comprobó por su posición).
- `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** filtrar por servicio y por profesional (usan la misma lista que estado y sede), guardar el celular y cambiar la contraseña desde la pestaña nueva (no cambiaron, solo se movieron), un rubro con mascotas, una empresa de una sola sede, y la vista en celular.

## 7. Para aplicar

No requiere resetear la base. Basta recargar la página.
