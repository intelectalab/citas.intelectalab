# 2026-10-13 — Se quitaron los comentarios del código

## Contexto

Pedido: quitar del proyecto todos los comentarios, también los que explican, sin alterar nada de lo que hace el sistema.

## Qué se hizo

Se leyó cada archivo con el analizador de su propio lenguaje (el de PHP, el compilador de TypeScript y un lector de SQL) y se quitó solo lo que ese analizador marca como comentario. Así un `//` dentro de un texto, de una dirección web o de un texto de pantalla no se toca. Las líneas que quedaban vacías se quitaron, sin dejar dos líneas en blanco seguidas.

| Tipo de archivo | Archivos con cambios | Comentarios quitados |
|---|---|---|
| PHP (API, rutas, configuración, base, pruebas) | 113 | 819 |
| TypeScript y JavaScript (web y configuración) | 141 | 851, de ellos 110 `{/* … */}` de JSX |
| SQL (`init.sql`, `seed.sql`, `seed_demo.sql`), incluidos los de los bloques `DO` | 3 | Todos los `--` |
| CSS | 5 | Todos los `/* … */` |
| Blade (PDF de la ficha y página de bienvenida) | 2 | `{{-- … --}}` y `<!-- … -->` |
| `docker-compose.yml` | 1 | 2 |

En total: 258 archivos, unas 3.700 líneas menos.

## Lo que se dejó a propósito

No son explicaciones: el sistema o sus herramientas los necesitan.

- **5 comentarios `eslint-disable-next-line`** (`DataTable`, `FormBlock`, `BookingFlow`, `FormRecall`, `FormWaitlist`): le dicen al revisor de código que ignore un aviso. Sin ellos, la revisión de código marca errores.
- **`apps/web/next-env.d.ts`**: lo genera Next.js y sus líneas `/// <reference>` hacen falta.
- **El aviso de licencia de Tailwind** (`/*! tailwindcss … MIT License */`) en `welcome.blade.php`: la licencia exige conservarlo.
- **Los textos de `$tpl$` en `seed.sql`** (el catálogo de cada rubro en JSON): son datos, no comentarios.

## Cómo se comprobó que no cambió nada

| Comprobación | Resultado |
|---|---|
| PHP: el código de cada archivo antes y ahora, sin comentarios ni espacios, con el analizador de PHP | 113 de 113 idénticos |
| Web: el JavaScript que produce cada archivo, antes y ahora | 134 de 134 idénticos (los 7 restantes ya se habían comparado en la primera pasada) |
| SQL: cargar los tres archivos de antes en una base y los de ahora en otra | Mismo esquema; las 99 tablas con la misma cantidad de filas |
| CSS, Blade y YAML: contenido de antes sin comentarios frente al de ahora | Iguales |
| `php -l` en todos los PHP cambiados | Sin errores |
| `tsc --noEmit` | Sin errores en `src` |

Las bases temporales se eliminaron. `erp_saas` no se tocó.

## A tener en cuenta

- **Las explicaciones que estaban en el código ya no están ahí.** Lo importante de cada módulo sigue en `Documentación/Avances` y `Documentación/Pendientes`.
- **El aviso de `seed.sql` sobre la contraseña de las cuentas de la plataforma** (que hay que cambiarla al desplegar) era un comentario y se quitó. Sigue anotado en `pendientes.md` ("Contraseña de las cuentas de la plataforma").
- **El código nuevo se escribirá sin comentarios**, para que el proyecto se mantenga así.
- Git avisa de finales de línea (`CRLF`/`LF`) en algunos archivos al hacer commit: es el aviso de siempre de Windows. Cada archivo conserva los finales de línea que tenía.
