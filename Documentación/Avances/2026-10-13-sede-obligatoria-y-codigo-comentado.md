# 2026-10-13 — Nadie queda sin sede, tres arreglos de sede y código comentado

## Contexto

Tras la entrega 3 de Usuarios y roles, al revisar qué pasa con quien debe ver "solo su sede" y no tiene una asignada, salió que:

- Esa persona no veía agenda, caja ni ventas, pero sí veía y podía cambiar clientes, servicios, cupones y controles, porque son de toda la empresa.
- Se podía llegar a ese estado de dos formas: pasando un rol a "solo su sede" con gente sin sede, o quitándole la sede a alguien de recepción o caja sin cambiarle el rol (la API solo lo impedía para el gerente).
- Había tres huecos más: desactivar una sede con personal asignado, cambiar de sede a quien tiene su caja abierta, y una lista de usuarios que no marcaba a quien le falta la sede.

Se eligió la opción A: **que nadie con un rol de "solo su sede" quede sin sede**, más los tres arreglos. La otra opción, que sin sede viera todas las sedes solo para consultar, se descartó: una cajera sin sede vería la caja y las ventas de todas.

## 1. La sede es obligatoria

- Para cualquier rol de "solo su sede" (Gerente, Recepción, Caja o uno propio), no solo para el gerente. Si se intenta guardar sin sede: "Quien tiene el rol Recepción trabaja solo con su sede: elige cuál es."
- **Al pasar un rol a "Solo su sede"**, si alguien con ese rol no tiene sede, el cambio no se aplica todavía. Aparece un recuadro: "Una persona con este rol no tiene sede asignada. Elige la sede de cada una…", con una lista por persona y el botón "Asignar y aplicar". Al confirmar se asignan las sedes y se aplica el cambio en el mismo paso.

## 2. La lista de usuarios marca a quien le falta la sede

Si a alguien le falta su sede, en la columna Sede aparece "Falta su sede" en ámbar, con su explicación al pasar el cursor. Arriba aparece una tarjeta "Falta su sede" que filtra la tabla; solo se muestra si hay alguien en ese caso. Con lo anterior no debería pasar, pero puede quedar alguien así de antes.

## 3. Una sede con personal no se desactiva

Al desactivar una sucursal donde trabaja gente: "En esta sucursal trabajan 2 personas: Karina Lozano Vera, Rosa Medina Paz. Pásalas a otra sede (o desactiva sus cuentas) en Usuarios y roles antes de desactivarla." Es igual a como ya se hacía con las citas próximas.

## 4. Quien tiene su caja abierta no cambia de sede ni se desactiva

"Karina tiene abierta la Caja principal de Sede Central - Miraflores. Que cierre su turno en Caja antes de cambiarle la sede." Lo mismo al desactivar su cuenta: su turno quedaría sin nadie que pudiera cerrarlo. Cambiarle otros datos (el celular, por ejemplo) sí se puede.

## 5. Código comentado

Se revisaron todos los archivos del proyecto (API, rutas, base de datos, web y estilos) buscando código desactivado con comentarios. Solo había uno: `// User::factory(10)->create();` en `apps/api/database/seeders/DatabaseSeeder.php`, que venía con Laravel y no se usa. Se quitó.

Los demás comentarios son explicaciones (qué hace cada parte y por qué) y se dejaron tal cual.

## 6. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Http/Controllers/Api/AccessController.php` | Sede obligatoria en todo rol de "solo su sede"; asignar la sede al pasar un rol a "solo su sede"; caja abierta; lo que ve cada rol en la lista de usuarios. |
| `apps/api/app/Http/Controllers/Api/BranchController.php` | Una sucursal con personal no se desactiva. |
| `apps/web/src/features/access/components/RoleDetail.tsx`, `UsersRolesBoard.tsx`, `access.ts` | El recuadro para asignar sedes; "Falta su sede" y su tarjeta. |
| `apps/api/database/seeders/DatabaseSeeder.php` | Se quitó la línea comentada. |

Sin cambios de base de datos.

## 7. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API:**

| Caso | Resultado |
|---|---|
| Quitarle la sede a Rosa (recepción) o a Karina (caja) sin cambiarles el rol | Rechazado, con su explicación |
| Pasar Caja a "solo su sede" con Karina sin sede | No se aplica; responde quién no tiene sede y las 3 sedes para elegir; el rol sigue igual |
| Lo mismo con una sede que no existe | Rechazado |
| Asignándole Miraflores | Karina queda en Miraflores y el rol en "solo su sede" |
| Karina abre la caja de Miraflores; pasarla a San Borja; desactivarla | Abre; los otros dos, rechazados con su explicación |
| Cambiarle solo el celular | Permitido |
| Cierra su turno y luego pasarla a San Borja | Permitido |
| Desactivar Miraflores, donde trabaja Rosa | Rechazado: "En esta sucursal trabaja Rosa Medina Paz. Pásala a otra sede…" |
| Desactivar Surco, sin personal ni citas próximas | Permitido |

**En el navegador, con clics reales** (con Karina sin sede a propósito):

| Prueba | Resultado |
|---|---|
| Usuarios y roles | Tarjeta "Falta su sede · 1" |
| Tocar la tarjeta | Deja solo a Karina, con "Falta su sede" y su explicación al pasar el cursor |
| Rol Caja: "Toda la empresa" | "Listo: quien tiene este rol ahora ve toda la empresa." |
| Volver a "Solo su sede" | Aparece el recuadro con Karina; "Asignar y aplicar" bloqueado hasta elegir sede |
| Elegir Miraflores y "Asignar y aplicar" | "Listo: … ahora ve solo su sede. Se le asignó su sede a una persona."; el recuadro se cierra |
| Sucursales: desactivar Miraflores | "En esta sucursal trabajan 2 personas: Karina Lozano Vera, Rosa Medina Paz…" |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salieron dos textos que se corrigieron: "Pásalas" cuando era una sola persona, y "cambiarla de sede", que suponía el género de la persona (ahora "cambiarle la sede"). El segundo no se volvió a ver en pantalla.
- **No se probó en pantalla:** el aviso de caja abierta (se probó por la API), quitar la sede desde el panel de un usuario (el panel no deja vaciarla en esos roles), tema oscuro y celular.

## 8. Para aplicar

Nada que resetear por estos cambios.
