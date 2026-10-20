# 2026-10-20 — Mensajes de error propios y en español

## Contexto

Con el sistema publicado, los errores se mostraban con pantallas y textos genéricos, varios en inglés: la página por defecto de Next.js cuando una dirección no existe, "Server Error", "Unauthenticated.", "Too Many Attempts.", "Network Error" o "The name field is required.". Se pidió un mensaje propio para todos los errores.

## Qué ve la persona ahora

| Situación | Antes | Ahora |
|---|---|---|
| Dirección que no existe | Página genérica de Next.js | "No encontramos esta página", con **Volver atrás** e **Ir al inicio** |
| Una pantalla falla al mostrarse | Pantalla en blanco o genérica | "Algo salió mal", con **Intentar de nuevo** e **Ir al inicio**. Dentro del panel, el menú sigue visible |
| Sesión vencida | "Unauthenticated." | "Tu sesión terminó. Vuelve a iniciar sesión para continuar." |
| Sin permiso | "This action is unauthorized." | "No tienes permiso para hacer esto. Si lo necesitas, pídeselo al administrador de tu empresa." |
| Algo que ya no existe | "No query results for model…" | "No encontramos lo que buscas. Puede que se haya eliminado o que el enlace esté mal." |
| Demasiados intentos | "Too Many Attempts." | "Hiciste demasiados intentos seguidos. Espera un minuto y vuelve a intentarlo." |
| Falla del servidor | "Server Error" | "Ocurrió un problema en el sistema y no se pudo completar la acción. Inténtalo de nuevo en unos minutos; si sigue pasando, avísale al administrador." |
| Sin internet | "Network Error" | "No hay conexión con el sistema. Revisa tu internet e inténtalo de nuevo." |
| Dato mal llenado | "The name field is required. (and 3 more errors)" | "Falta completar el nombre. (y 3 datos más por revisar)" |

"Ir al inicio" lleva a cada uno a su inicio: el personal a `/home`, el cliente a `/portal` y quien no entró a `/login`.

Los mensajes que el sistema ya tenía en español (por ejemplo "La caja de la sede está cerrada…" o el límite de sedes del plan) **no cambian**: solo se reemplazan los genéricos.

En tu máquina, con `APP_DEBUG=true`, una falla del servidor sigue mostrando el detalle técnico para poder encontrarla. En el servidor sale siempre el mensaje amable.

## Cambios realizados

| Archivo | Cambio |
|---|---|
| `apps/web/src/app/components/common/ErrorScreen.tsx` | Nuevo. Pantalla de error con tu diseño (tarjeta, ícono en tus colores, botones `Button`) |
| `apps/web/src/app/not-found.tsx` | Nuevo. Página "No encontramos esta página" |
| `apps/web/src/app/error.tsx`, `global-error.tsx` | Nuevos. Pantalla "Algo salió mal" para todo el sitio |
| `apps/web/src/app/(dashboard)/error.tsx` | Nuevo. La misma pantalla dentro del panel, con el menú visible |
| `apps/web/src/lib/axios.ts` | Mensaje propio cuando no hay conexión o el servidor tarda demasiado |
| `apps/api/app/Support/ErrorMessages.php` | Nuevo. Reemplaza los mensajes genéricos de error por mensajes claros en español |
| `apps/api/bootstrap/app.php` | La API siempre responde los errores en su formato y pasa por `ErrorMessages` |
| `apps/api/lang/es/validation.php`, `apps/api/lang/es.json` | Nuevos. Mensajes de validación en español, con los nombres de los campos ("el correo", "la sede", "la duración"…) |
| `apps/api/config/app.php`, `.env`, `.env.example` | Idioma por defecto: español (`APP_LOCALE=es`). En el servidor ya se cambió |

## Cómo se probó

- **API** (base temporal, con `APP_DEBUG=false` como en el servidor): sin sesión (401), dirección y registro que no existen (404), acción no permitida (405), falla interna (500), dos formularios con datos mal llenados (422) y doce ingresos seguidos (429). Todos con su mensaje en español. El límite de sedes del plan conservó su mensaje propio.
- **Pantallas** (en tu sitio de desarrollo): una dirección inexistente muestra la página nueva, y **Volver atrás** e **Ir al inicio** funcionan. Una falla provocada dentro del panel muestra "Algo salió mal" con el menú visible; **Intentar de nuevo** vuelve a cargar la pantalla e **Ir al inicio** lleva a `/home`. La página de prueba se borró.
- La web no tiene errores de tipos.
