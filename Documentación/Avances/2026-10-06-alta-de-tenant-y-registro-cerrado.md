# 2026-10-06 — Alta completa de tenant desde la consola, registro cerrado y nombres en tema oscuro

## Contexto

Tres ajustes:

1. Cuando el registro de empresas está cerrado, la página de registro debe decirlo en lugar de dejar llenar el formulario.
2. En tema oscuro, los nombres de la tabla de Tenants se leían mal.
3. El formulario "Nuevo Tenant" solo pedía nombre, slug, zona horaria y estado. Faltaban el rubro, el plan, los datos de la empresa, la sede principal y el administrador. Se mantiene el diseño: panel lateral.

No hay cambios de esquema.

## 1. Registro cerrado

`/register` consulta `GET /api/platform`. Si `allow_tenant_signup` es falso, en lugar de los planes muestra "El registro está cerrado por ahora", el correo de soporte si está configurado y un botón para ir a iniciar sesión.

El botón "Registrar mi empresa" del login sigue llevando a `/register`, que es donde aparece el aviso. Si la consulta falla no se bloquea: la API decide al enviar.

Archivo: `src/app/(auth)/register/page.tsx`.

## 2. Nombres en tema oscuro

El nombre de cada fila tiene un color para cuando se pasa el cursor. La regla del tema oscuro lo aplicaba siempre, no solo al pasar el cursor, y por eso los nombres salían en un tono apagado. Ahora el color normal es el claro del tema y el de cursor solo aparece al pasar el cursor.

Afectaba a cualquier enlace con color de cursor, no solo a Tenants. Archivo: `src/app/globals.css`.

## 3. Alta de tenant

### Qué pasaba

`POST /api/tenants` creaba solo la fila de la empresa: sin plan, sin sede, sin administrador y sin catálogo. Esa empresa no tenía módulos ni forma de iniciar sesión.

### Qué hace ahora

El alta desde la consola deja la empresa igual que el registro público. Las dos usan la misma rutina, `app/Support/TenantProvisioner.php`:

- Tenant con la copia de su rubro y su código de empresa.
- Catálogo inicial del rubro (servicios y ficha).
- Razón social, sede principal y cuenta del dueño con su rol.
- Suscripción: en prueba (con los días de su plan) o activa desde hoy.

Diferencias del alta desde la consola:

- Puede elegir cualquier plan activo, también los privados.
- Puede empezar sin periodo de prueba, con ciclo mensual o anual.
- La contraseña del dueño se genera (12 caracteres) y se devuelve una sola vez en la respuesta.
- Queda en la bitácora como `tenant.created`.

### Formulario

Mismo panel lateral y mismos estilos. Al crear pide:

| Bloque | Campos |
|---|---|
| Empresa | Nombre, rubro (lista), plan (lista), empezar en prueba, ciclo de cobro si no hay prueba |
| Datos de la empresa | Razón social, RUC, teléfono |
| Sede principal | Nombre de la sede, dirección, distrito, departamento |
| Administrador | Nombres, apellidos, correo |
| Sistema | Zona horaria, estado |

Al elegir el rubro muestra con qué empieza ("6 servicios y la ficha Preferencias de corte"). Al elegir el plan muestra sus días de prueba.

Al editar sigue pidiendo solo nombre, slug, zona horaria y estado; el plan y el rubro se cambian desde la página de la empresa. El título del panel dice qué empresa se edita.

Tras crear, una ventana muestra el correo y la contraseña temporal del administrador. Los errores de validación se muestran dentro del formulario; antes solo iban a la consola del navegador.

Archivos: `features/tenants/components/FormTenant.tsx`, `TenantsTable.tsx`, `app/Http/Controllers/Api/TenantController.php`, `app/Http/Controllers/Auth/RegisterTenantController.php`.

## 4. Verificación

- Pruebas de la API contra una base temporal (eliminada al terminar):

| Caso | Resultado |
|---|---|
| Registro público tras el cambio: empresa con copia de rubro, 10 servicios, sede, dueño con rol y 30 días de prueba (Empresarial) | Igual que antes |
| Dueño de otra empresa y soporte SaaS intentan crear un tenant | 403 |
| Admin SaaS crea una veterinaria sin prueba y con ciclo anual: 7 servicios, sede "Sede Surco", suscripción activa por 365 días, 16 módulos | Correcto |
| El dueño entra con la contraseña temporal | Correcto |
| Con periodo de prueba y plan Empresarial | 30 días de prueba |
| Sin los datos obligatorios | 422 y no se crea nada |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| Con el registro cerrado, "Registrar mi empresa" lleva al aviso "El registro está cerrado por ahora", con el correo de soporte y sin planes | Correcto |
| En tema oscuro, el nombre de un tenant se pinta en el tono claro del tema | Correcto |
| "Nuevo Tenant" abre el panel lateral con los quince campos y la lista de rubros | Correcto |
| Llenar y enviar: aparece "¡Empresa creada!" con correo y contraseña, y la empresa sale en la tabla | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó en el navegador la edición de un tenant ni un envío con errores de validación.
