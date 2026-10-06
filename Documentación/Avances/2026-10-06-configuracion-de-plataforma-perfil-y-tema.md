# 2026-10-06 — Configuración de la plataforma, menú de perfil y tema oscuro

## Contexto

Tres pedidos:

1. Una configuración que solo el administrador SaaS vea y cambie, empezando por el color de los interruptores y de los anillos de uso (el verde queda como predeterminado).
2. El enlace a esa configuración en la parte baja del menú lateral.
3. Un menú de perfil con cambio de tema (claro u oscuro), cambio de cuenta y cierre de sesión.

Los cambios de base están en `init.sql`. No hay migraciones.

## 1. Configuración de la plataforma

Página `/admin/configuration`, solo para el administrador SaaS. El soporte SaaS y el personal de las empresas reciben 403.

| Ajuste | Qué hace | Por defecto |
|---|---|---|
| Color de acento | Color de lo que está encendido o va bien: interruptores y anillos de uso. Se ve en todas las pantallas, también en las de las empresas. Ocho colores sugeridos o cualquier otro, con vista previa antes de guardar. | Verde `#10B981` |
| Avisos de uso | Desde qué porcentaje del límite del plan el uso de una empresa pasa a ámbar y a rojo. | 70 % y 90 % |
| Nombre del producto | El nombre que se ve en el menú lateral. | CoreERP |
| Correo de soporte | Se guarda y se entrega a las pantallas; todavía ninguna lo muestra. | Vacío |
| Registro de empresas | Abierto o cerrado. Cerrado, `POST /api/register-tenant` responde 403 `SIGNUP_CLOSED`. | Abierto |
| Sesión de soporte | Horas que dura la sesión al entrar al panel de una empresa. | 2 horas |

Los colores de aviso (ámbar) y alerta (rojo) son fijos para que siempre se lean como advertencia.

### Esquema

Tabla nueva `platform_settings` (`key`, `value` jsonb). Una fila por clave; lo que no está guardado usa su valor por defecto. Si la tabla no existe (base sin resetear), la API usa los valores por defecto y no falla.

### API

| Ruta | Quién | Detalle |
|---|---|---|
| `GET /api/platform` | Cualquiera, sin sesión | Marca y apariencia: nombre, correo de soporte, color de acento, umbrales y si el registro está abierto. No expone la duración de la sesión de soporte. |
| `GET /api/admin/platform-settings` | Administrador SaaS | Valores y valores por defecto. |
| `PUT /api/admin/platform-settings` | Administrador SaaS | Guarda todo o nada. Valida el color (`#RRGGBB`), el correo, las horas (1 a 24) y que el aviso sea menor que la alerta. Queda en la bitácora como `platform.settings_changed`. |

Archivos: `app/Models/PlatformSetting.php`, `app/Http/Controllers/Api/PlatformSettingController.php`.

### Frontend

| Archivo | Cambio |
|---|---|
| `src/hooks/usePlatform.tsx` | Nuevo. Lee `/api/platform` y pone el color en la variable CSS `--accent`. |
| `src/features/platform/components/PlatformSettings.tsx` | Nuevo. La página de configuración. |
| `src/app/components/common/Switch.tsx` | El encendido usa `--accent`. |
| `src/features/tenants/components/TenantDetail.tsx` | El anillo "va bien" usa `--accent` y los umbrales configurados. |
| `src/features/settings/components/TenantSettings.tsx` | Sus interruptores usan el componente `Switch`. |
| `src/app/components/layout/Sidebar.tsx` | Muestra el nombre del producto. Abajo, "Configuración" lleva a `/admin/configuration` para el administrador SaaS y a `/settings` para el administrador de una empresa. |

## 2. Menú de perfil

En la barra superior, al hacer clic en el nombre:

- Nombre, correo y qué es la cuenta (Administrador SaaS, Soporte SaaS, Dueño, Administrador o Personal) con su empresa.
- **Tema:** alterna claro y oscuro. Se guarda en el navegador de cada persona.
- **Cambiar de cuenta:** cierra la sesión y abre el ingreso con el mismo correo ya escrito. Si ese correo tiene cuenta en varias empresas, al ingresar se elige a cuál entrar. No se cambia de empresa sin volver a poner la contraseña.
- **Cerrar sesión.**
- En una sesión de soporte, "Cambiar de cuenta" se reemplaza por "Salir de la sesión de soporte".

Se cierra con clic fuera o con Escape.

Archivos: `src/app/components/layout/Navbar.tsx`, `src/hooks/useTheme.ts`, `src/app/(auth)/login/page.tsx`.

## 3. Tema oscuro

Se aplica al panel (barra superior y contenido), no al menú lateral, que ya es oscuro. No se reescribió cada pantalla: en `globals.css`, cuando el documento tiene `data-theme="dark"`, se redefine la paleta que usan las utilidades de Tailwind dentro de `.theme-scope`.

| Qué | Cómo |
|---|---|
| Blancos y grises (`bg-white`, `bg-slate-50`, `border-slate-200`, `text-slate-800`...) | La escala se invierte: lo claro pasa a superficie oscura y los textos oscuros a claros. |
| Tonos pastel de avisos y etiquetas (verde, ámbar, rojo, celeste...) | Fondo translúcido y texto en un tono claro. |
| Colores de marca escritos como valor fijo (`text-[#022340]`, `bg-[#022340]`...) | Reglas puntuales que los pasan a un tono legible. |
| `DataTable.module.css` | Sus 106 colores fijos pasaron a variables con el mismo valor de respaldo, así que la tabla también cambia. En tema claro se ve igual. |
| Campos nativos (fecha, lista) | `color-scheme: dark`. |

Para que el tema alcance a las pantallas nuevas, sus colores deben salir de las clases de la paleta o de variables, no de valores fijos.

## 4. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| `GET /api/platform` sin sesión devuelve los valores por defecto y no expone la duración de la sesión de soporte | Correcto |
| Dueño de empresa y soporte SaaS: ver y guardar | 403 |
| Administrador SaaS guarda color, nombre, umbrales, horas y cierra el registro | Correcto |
| Color que no es `#RRGGBB`, aviso mayor que la alerta, 48 horas, correo inválido | 422 |
| Con 4 horas configuradas, la sesión de soporte dura 4 horas | Correcto |
| Registro de empresa con el registro cerrado | 403 `SIGNUP_CLOSED` |
| Guardar el correo de soporte vacío y reabrir el registro | Correcto. Falló en la primera prueba y se corrigió. |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| "Configuración" está en la parte baja del menú y lleva a `/admin/configuration` | Correcto |
| El menú de perfil muestra nombre, correo, rol, tema, cambiar de cuenta y cerrar sesión | Correcto |
| Cambiar tema: el panel pasa a oscuro, el menú lateral no cambia y la elección queda guardada | Correcto |
| Elegir "Celeste" y guardar: los interruptores de toda la app pasan a celeste sin recargar | Correcto |
| Tabla de Tenants y página de una empresa en tema oscuro | Se leen bien |
| "Cambiar de cuenta": cierra la sesión y el ingreso abre con el correo puesto | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- El tema oscuro se revisó en tres pantallas: configuración de la plataforma, tabla de Tenants y página de una empresa. El resto (clientes, servicios, profesionales, planes, formularios laterales, modales, portal) no se revisó en oscuro.

## 5. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. Sin resetear, la configuración se ve con sus valores por defecto pero no se puede guardar.
