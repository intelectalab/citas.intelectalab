# 2026-10-19 — Limpieza de código repetido (sin cambiar el funcionamiento)

## Contexto

Antes de seguir con la entrega 6b (campañas), Geraldine preguntó si había código repetido que convenía ordenar, porque el proyecto "ya pesa 3 GB". Pidió que no cambiara nada en el funcionamiento.

**Sobre el peso:** del total (3.6 GB), **3.1 GB son `apps/web/.next`**, la caché temporal que arma Next.js al trabajar. Las librerías ocupan 461 MB (`node_modules`) y 74 MB (`vendor`). El código del proyecto pesa unos 3 MB. `.next` se puede borrar sin perder nada: se vuelve a armar al arrancar la web.

**Regla de esta limpieza:** reemplazar solo copias **idénticas**. Donde un archivo tenía una variante distinta (otro color, otro tamaño), se dejó como estaba, para que el diseño no cambie ni un píxel.

## 1. Web: piezas compartidas nuevas

| Pieza | Reemplaza | Veces |
|---|---|---|
| `app/components/ui/forms/styles.ts` (`inputClass`, `labelClass`) | El estilo de los campos y de sus etiquetas, copiado en cada formulario | 25 y 25 archivos |
| `app/components/common/Alert.tsx` → `ErrorAlert` | El recuadro rojo de error | 55 |
| `Alert.tsx` → `SuccessAlert` | El recuadro verde de "listo" | 16 |
| `app/components/common/BackLink.tsx` | El enlace "← Volver" de las páginas de detalle | 7 |
| `lib/dates.ts` | Las listas de meses y de letras de los días | 4 |

En total se cambiaron 57 archivos. Ejemplo de cómo queda (página de la sucursal):

- antes: `{error && <p role="alert" className="text-sm text-red-600 bg-red-50 …">{error}</p>}`
- ahora: `<ErrorAlert message={error} />`

**Ventaja:** cambiar el estilo de un aviso o de los campos ahora se hace en un solo lugar.

## 2. API: piezas compartidas nuevas

| Pieza | Reemplaza | Veces |
|---|---|---|
| `App\Support\Person::name($persona)` | Armar "nombre apellido" a mano | 44 |
| `$tenant->zone()` | La zona horaria con su valor por defecto (`?: 'America/Lima'`) | 27 |
| **`App\Support\AppointmentEvents::record()`** | Registrar cada paso de una cita: historial, aviso al personal, links de pago, paquetes y mensajes | 4 lugares: la agenda, el adelanto pagado, las sesiones de un paquete y la anulación de un paquete |
| `AppointmentEvents::monthLimit()` | El tope de citas por mes del plan | 2 lugares |

**`AppointmentEvents::record()` es la más importante.** Antes, el adelanto pagado y los paquetes copiaban a mano parte de lo que hace la agenda, y cada regla nueva había que agregarla en cada copia (pasó con los mensajes). Ahora hay una sola pieza.

Cada lugar conserva exactamente lo que hacía, gracias a la opción de "saltar" partes:
- **Sesiones de un paquete:** no envía la confirmación de cada sesión, para no mandar 10 correos.
- **Adelanto pagado:** no anula su propio link de pago.
- **Anulación de un paquete:** cancela sus citas en silencio, como antes.

## 3. Lo que no se hizo, y por qué

- **El pie de los paneles laterales (Cancelar + Guardar) y las tarjetas de opción.** Tienen muchas variantes (textos, permisos, marcas de prueba): unificarlos exige revisar uno por uno. Queda en pendientes.
- **Quitar las revisiones de "base vieja"** (`ready()`, `Schema::hasColumn`). La primera migración se salta si la base ya tiene tablas. Si `erp_saas` local no se resetea, no recibe las tablas nuevas, y esas revisiones evitan que la pantalla falle. Se quitarán cuando todas las bases estén al día.
- **La función `tenant()` de cada controlador** (16 copias): no son idénticas; algunas exigen además un módulo o una tabla.

**Sobre el tamaño:** el número de líneas casi no cambió (la web bajó unas 9,500 letras de 1.8 millones). Cada reemplazo cambió una línea larga por una corta y sumó una importación. Antes había estimado entre 1,500 y 2,500 líneas menos, contando el pie de los paneles, las tarjetas de opción y la función común para cargar datos, que quedaron fuera por tener variantes. Lo ganado ahora es que cada pieza vive en un solo lugar.

## 4. Verificación

- **Base temporal:** armada con el método nuevo (`php artisan db:demo`, migraciones y datos de muestra), ya eliminada. `erp_saas` no se tocó.
- **Tipos y estilo:** la web compila sin errores de tipos. El lint no tiene ningún aviso nuevo: los 26 "sin uso" que muestra ya existían. La API no tiene errores de sintaxis en ningún archivo.
- **Paquetes por la API, repetida completa:** crear, revisar fechas, vender, cobrar, cancelar a tiempo y tarde, atender, uso a mano, más días, devolver S/ 150, usar y quitar el paquete. Resultados iguales a los de antes, y el historial de cada cita sigue registrándose con su autor ("Sesión del paquete PQ-0006", "Se anuló el paquete PQ-0006").
- **Portal:** catálogo con el paquete, reserva sin adelanto y "Mis paquetes", iguales.
- **Mensajes:** todos los casos, iguales. Además el mensaje programado guarda bien la hora (9:00 de Lima).
- **En pantalla:**
  - Diez pantallas abren sin errores: Sucursales, Paquetes, Mensajes, Configuración, Punto de venta, Productos, Cupones, Caja, Usuarios y Clientes.
  - Los enlaces "←" del cliente, el paquete, la sucursal y el usuario llevan a su lista.
  - El aviso verde de Configuración y el rojo del panel de paquetes ("Ya tienes un paquete con ese nombre.") tienen exactamente las mismas clases que antes.

## 5. Para el código nuevo

Usar `ErrorAlert` / `SuccessAlert`, `BackLink`, `inputClass` / `labelClass` de `ui/forms/styles`, `Person::name()`, `$tenant->zone()` y `AppointmentEvents::record()` en lugar de copiar.

## 6. Segunda ronda: componentes con parámetros

A pedido de Geraldine ("así como hice con el formulario, que acepte parámetros para reutilizarlo aunque haya cambios"), se agregaron cuatro componentes que reciben como parámetro lo que cambia entre una pantalla y otra. Cada uno produce exactamente las mismas clases que había antes.

| Componente | Parámetros | Reemplaza |
|---|---|---|
| `common/SectionTitle.tsx` | El contenido (ícono y texto) | 36 títulos de tarjeta (también los que usaban la misma clase guardada en una constante) |
| `common/Badge.tsx` | `tone` (los colores de cada estado), `bold` (seminegrita), `nowrap` (no partir la línea), `className` (lo propio de cada uso) | 44 insignias de estado |
| `common/Button.tsx` | `variant`: `primary` (azul redondeado), `classic` (azul de esquinas más rectas) y `secondary` (el blanco con borde de "Cancelar"); recibe todo lo demás de un botón (`type`, `form`, `disabled`, `onClick`, marcas) | 39 botones |
| `common/OptionCard.tsx` | `selected`, y `className` con la base de cada tarjeta (sus medidas y su disposición) | 17 tarjetas de opción ("Libre / Con fechas acordadas", "¿Para qué es?", el modo del correo…) y la función `optionButton` del panel de paquetes |

**Lo que quedó fuera a propósito:**
- Los botones azules que son casos únicos (con otros tamaños), que se dejaron como estaban: unificarlos cambiaría su aspecto.
- El detalle de rubros, que ya tenía su propio `SectionTitle` con otros parámetros.

**Verificación:** 64 archivos cambiados. La web compila sin errores de tipos. El lint tiene exactamente los mismos avisos que antes. El script se detiene si las clases finales de una insignia no son iguales a las originales. En pantalla, con una copia de la web apuntada a una base temporal, se abrieron 12 pantallas sin errores y se comprobó:
- las tarjetas de "¿Para qué es?" del producto conservan sus clases, elegida y no elegida, y cambian al hacer clic;
- "Cancelar" y el botón de crear conservan sus clases, su tipo y su formulario, y "Cancelar" cierra el panel;
- la insignia "Vigente" de Paquetes conserva sus clases;
- los títulos de la página del paquete son los mismos.

**`.next` borrada.** Con la web detenida se borró la caché de 3.1 GB. El proyecto bajó de 3.6 GB a 549 MB. Al arrancar la copia de prueba se volvió a crear una caché nueva de unos 450 MB: es normal y crece con el uso.
