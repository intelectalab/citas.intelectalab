# 2026-10-13 — Usuarios y roles, entrega 1: usuarios que no atienden citas, roles con permisos y excepciones por persona

## Contexto

Solo se podían crear profesionales: no había forma de dar una cuenta a recepción o a caja, y todo lo que no era de un profesional lo veía únicamente el administrador. Los permisos que estaban en la base no decidían nada.

Decidido antes de programar:

- Usuarios y roles va **antes** del carrito (entrega 3 de Ventas), en el grupo "Tu empresa" del menú.
- **Una persona, un rol.** A una persona concreta se le puede encender algo que su rol no trae o apagar algo que sí.
- Roles de fábrica: **Administrador, Gerente de sede, Recepción, Caja y Profesional.**
- El gerente de sede existe desde ya, con un aviso: por ahora ve todas las sedes.
- En esta entrega algunos botones se siguen viendo aunque la persona no pueda usarlos: al pulsarlos, el sistema responde que no tiene permiso.
- Recepción nace pudiendo cobrar y operar la caja.

## 1. Pantalla "Usuarios y roles" (`/users`)

Ítem nuevo en "Tu empresa". Tiene dos pestañas.

### Usuarios

| Parte | Detalle |
|---|---|
| Tarjetas | Cuentas usadas frente al tope del plan, Activos, Con permisos cambiados, Desactivados (las tres últimas filtran) |
| Columnas | Persona (su nombre lleva a su página) con "Dueño" y "Tú", rol con lo agregado y lo quitado ("1 agregado · 1 quitado"), sede, estado |
| Filtros y orden | Rol, estado y fecha de registro; ordenar por nombre, rol, sede, permisos cambiados y fecha |
| Acciones | Ojo (su página), lápiz (editar), llave (contraseña nueva) |

Están todas las cuentas de la empresa, también las de los profesionales ("Atiende citas" lleva a su página de profesional).

**Nuevo usuario** (panel lateral): nombres, apellidos, correo, celular, rol y sede. Es para quien **no** atiende citas; un profesional se sigue registrando desde su pantalla y su cuenta se crea sola.

- Al crear la cuenta se muestra **una sola vez** su contraseña temporal.
- Junto a la lista de roles hay un "+" para crear un rol ahí mismo; queda elegido.
- Con el tope del plan alcanzado el botón sale bloqueado con su motivo.
- El correo se guarda en minúsculas y no se repite dentro de la empresa.

**Editar:** los mismos datos, y el interruptor **"Cuenta activa"**. Una cuenta desactivada deja de entrar (aunque tuviera la sesión abierta), libera su lugar en el plan y no se borra nada de lo que hizo.

**Contraseña nueva** (la llave): la anterior deja de servir, se cierra su sesión y la nueva se muestra una vez.

### Roles

Los cinco de fábrica y los propios. Columnas: rol y para qué es, personas que lo tienen, cuánto puede ("22 de 60" con su barra) y tipo (de fábrica o propio).

**Nuevo rol:** nombre, para qué es y "empezar con los permisos de" otro rol. Al crearlo se abre su página.

## 2. Página de una persona (`/users/<id>`)

Es el "ver con un clic a qué puede acceder".

- **Su tarjeta:** rol, estado, correo, celular, sede, enlace a su rol y, si atiende citas, a su página de profesional.
- **Cuatro números que filtran la rejilla:** Puede, No puede, Agregado a esta persona, Quitado a esta persona.
- **A qué pantallas entra y a cuáles no**, en dos listas cortas.
- **La rejilla:** una fila por pantalla y un interruptor por acción (Ver, Crear, Editar, Eliminar). De a 6 pantallas, con paginación. Al tocar el nombre de una pantalla se lee con palabras qué permite cada acción ("Editar: confirmar, registrar la llegada, atender, reprogramar y cancelar citas").

Cada interruptor vale **solo para esa persona**:

| Marca | Significa | Ejemplo |
|---|---|---|
| Sin marca | Lo tiene (o no) por su rol | Recepción registra clientes |
| "Quitado" | Su rol lo trae y a ella se le apagó | Rosa es de recepción pero no agenda citas |
| "Agregado" | Su rol no lo trae y a ella se le encendió | Rosa también elimina clientes |

"Volver a lo de su rol" borra lo agregado y lo quitado. Si se le cambia el rol, también se borran (eran respecto del rol anterior).

## 3. Página de un rol (`/users/roles/<id>`)

La misma rejilla, pero cada interruptor vale **para todas las personas con ese rol, al instante**. Muestra quiénes lo tienen (cada nombre lleva a su página).

- Un rol de fábrica se puede cambiar y devolver a como nació ("Volver a como nació").
- Un rol propio se puede renombrar y eliminar; solo se elimina si nadie lo tiene.
- El **Administrador puede todo y no se recorta**.

## 4. Qué permisos hay

Sesenta, sobre diecinueve pantallas. Solo se muestran los de los módulos que la empresa tiene activos. Los nombres usan las palabras del rubro (pacientes, médicos).

| Pantalla | Acciones |
|---|---|
| Agenda y citas · Lista de espera · Controles y seguimiento | Ver, crear, editar |
| Bloqueos y ausencias · Feriados | Ver, crear, editar, eliminar |
| Tiempos de atención | Ver |
| Clientes · Servicios · Profesionales y horarios · Salas y equipos · Cupones | Ver, crear, editar, eliminar |
| Fichas de atención | Ver, editar (llenar, firmar y corregir) |
| Plantillas de ficha | Crear, editar, eliminar |
| Ventas y pagos | Ver, crear (**cobrar**), eliminar (**anular una venta o devolver dinero**) |
| Caja | Ver, crear (agregar o cambiar una caja), editar (**abrir, anotar movimientos y cerrar**) |
| Sucursales | Ver, crear, editar |
| Empresa · Configuración | Ver, editar |
| Usuarios y roles | Ver, crear, editar, eliminar (un rol propio) |

## 5. Roles de fábrica, como nacen

| Rol | Qué puede |
|---|---|
| Administrador | Todo. No se recorta |
| Gerente de sede | Todo lo del día a día (agenda, clientes, equipo, servicios, salas, ventas, caja, cupones, devoluciones). No entra a Empresa, Configuración ni Usuarios y roles; no llena fichas, solo las ve |
| Recepción | Agenda, lista de espera, controles y clientes (ver, crear y editar; no elimina). Cobra y opera la caja. Ve ventas, pagos y cupones. No devuelve dinero ni ve fichas |
| Caja | Cobra y opera la caja; ve ventas, pagos y cupones; ve la agenda y los clientes sin cambiarlos. No devuelve dinero |
| Profesional | Lo mismo que hasta hoy: solo sus citas, sus fichas, sus controles y sus ausencias; cobra sus propias citas |

Dos decisiones que conviene revisar:

- **Devolver dinero y anular** nacen solo en el Administrador y el Gerente de sede. Recepción y Caja no devuelven; se les enciende con un clic.
- **El profesional ya no elimina clientes ni cambia servicios.** Antes la API se lo dejaba a cualquier cuenta; ahora necesita el permiso.

Cada empresa recibe sus roles de fábrica sola: al crearse, y las que ya existen, la primera vez que hace falta. Lo que la empresa cambie después a un rol se respeta.

## 6. Los permisos ya mandan

- **El menú** muestra a cada quien solo las pantallas que puede ver. Recepción, por ejemplo, ya no ve Fichas de atención, Tiempos de atención, Sucursales, Empresa, Usuarios y roles ni Configuración; sí ve Ventas, Pagos, Caja y Cupones.
- **La API rechaza** lo que la persona no puede, con una explicación: "Tu cuenta no tiene permiso para esto: eliminar pacientes. Pídeselo a quien administra tu empresa."
- En la cita, la tarjeta "Cobrar" solo aparece a quien puede cobrar o ver ventas, y la de la ficha a quien puede ver fichas.
- Caja, Ventas, Sucursales, Salas, Feriados, Horarios y Plantillas de ficha ya ocultan sus botones de cambio según el permiso (usaban "es administrador"; ahora usan el permiso).

**Lo que aún no:** en las demás pantallas (Clientes, Servicios, Cupones, Agenda…) los botones se ven igual para todos; quien no puede recibe el mensaje al pulsar. Ocultarlos es la entrega 2.

**Un caso a tener presente:** las listas que otros formularios necesitan para elegir (servicios, profesionales, sedes, salas, feriados, bloqueos y plantillas) las sigue entregando la API a todo el personal. En esas pantallas, apagar "Ver" quita el ítem del menú, pero no impide leer la lista.

## 7. Protecciones

- El dueño siempre puede todo: su cuenta no cambia de rol, no se desactiva ni se le cambia la contraseña desde aquí.
- Nadie se cambia a sí mismo el rol, los permisos ni el estado.
- Nadie da ni quita un permiso que no tiene.
- Solo un administrador nombra a otro administrador.
- Quien atiende citas conserva el rol Profesional; lo demás se le enciende en su página.
- El gerente de sede necesita una sede.

## 8. Base de datos

| Archivo | Cambio |
|---|---|
| `init.sql` | Tabla nueva `user_permissions` (lo agregado y lo quitado a cada persona). Columna `roles.permissions_seeded_at` (cuándo recibió el rol sus permisos de fábrica). Columna `users.branch_id` (sede de la cuenta) |
| `seed.sql` | Se retiran los 16 permisos gruesos de empresa (`customers.manage`, `pos.transact`…), que nunca decidieron nada. Quedan los tres de la plataforma. Los permisos nuevos no se escriben en SQL: los registra la API desde su catálogo |
| `seed_demo.sql` | Se retiran los permisos que se asignaban a mano a cada rol. Roles Gerente de sede, Recepción y Caja en todas las empresas. Seis cuentas de ejemplo: en Centro Médico, Rosa Medina (recepción), Karina Lozano (caja) y Gustavo Paredes (gerente de San Borja); en DentalPlus, una recepcionista y un gerente; en Bella Aura, una recepcionista. Don Filo y Huellitas quedan como estaban, por el tope de su plan |

Las tablas `roles`, `permissions`, `role_permissions` y `user_roles` se conservan tal cual.

## 9. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Access.php` | Nuevo. Catálogo de permisos, roles de fábrica, lo que puede cada persona, la rejilla y el mensaje de rechazo. |
| `apps/api/app/Http/Middleware/EnsurePermission.php`, `bootstrap/app.php` | Nuevo. `->middleware('perm:customers.delete')`; con `a\|b` basta uno. |
| `apps/api/app/Http/Controllers/Api/AccessController.php` | Nuevo. Usuarios, roles, permisos por persona y por rol. |
| `apps/api/routes/api.php` | Cada ruta con su permiso; rutas `/access/...`. |
| `apps/api/app/Models/User.php`, `Role.php`, `Tenant.php` | `allows()`, la sede de la cuenta y el uso del plan contando solo cuentas activas. |
| `apps/api/app/Http/Middleware/EnsureStaffAccount.php` | Una cuenta desactivada deja de entrar. |
| `apps/api/app/Http/Controllers/AuthController.php` | La sesión trae los permisos de la persona. |
| `apps/api/app/Support/TenantProvisioner.php`, `ProfessionalController.php` | Roles de fábrica al crear la empresa y al registrar un profesional. |
| Controladores de caja, cupones, empresa, sucursales, bloqueos, feriados, horarios, plantillas, salas, ventas y configuración | "Es administrador" pasa a ser el permiso correspondiente. |
| `apps/api/infrastructure/docker/postgres/init.sql`, `seed.sql`, `seed_demo.sql` | Lo de la sección 8. |
| `apps/web/src/features/access/` | Nuevo. Tabla, paneles, rejilla y las dos páginas. |
| `apps/web/src/app/(dashboard)/users/` | La página (estaba vacía), `[id]` y `roles/[id]`. |
| `apps/web/src/hooks/useAuth.tsx` | `can('customers.delete')`. |
| `apps/web/src/app/components/layout/Sidebar.tsx` | Cada ítem con su permiso; ítem "Usuarios y roles". |
| `apps/web/src/features/appointments/components/AppointmentDetail.tsx`, `app/components/common/PersonLink.tsx` | Tarjetas según permiso; `UserLink`. |

## 10. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API:**

| Caso | Resultado |
|---|---|
| Roles de Centro Médico | Administrador (todo), Gerente 49 de 60, Recepción 22, Caja 7, Profesional 23 |
| Recepción: ver clientes, ventas y caja | Permitido |
| Recepción: eliminar un cliente, anular una venta, ver fichas, configuración, usuarios, crear sucursal, eliminar un servicio | Rechazado, cada uno con su explicación |
| Caja: ver citas y caja; agendar, registrar un cliente, lista de espera | Permitido; rechazados |
| Profesional: sus citas y fichas; ventas, caja, eliminar un cliente, cambiar la empresa | Permitido; rechazados |
| Gerente: eliminar un cliente, anular una venta; configuración y usuarios | Permitido; rechazados |
| Encenderle a Rosa "eliminar clientes" | Queda como agregado y ya puede |
| Apagarle "registrar clientes" | Queda como quitado y ya no puede |
| Encender lo que su rol ya trae | La excepción desaparece |
| "Volver a lo de su rol" | Sin excepciones; vuelve a no poder eliminar |
| La dueña se cambia un permiso o el rol; recortar al Administrador; eliminar un rol de fábrica; contraseña del dueño | Rechazados |
| Un gerente con permiso sobre usuarios: da un permiso que no tiene; nombra administradora a otra; se cambia a sí mismo | Rechazados |
| Crear sin rol; gerente sin sede; rol Profesional a quien no atiende; correo repetido (también con mayúsculas) | Rechazados |
| Crear una recepcionista | Creada, con contraseña temporal que sirve para entrar |
| Cambiar de rol a un profesional | Rechazado, con su explicación |
| Cambiarle el rol a alguien con excepciones | Rol nuevo, sin excepciones |
| Desactivar una cuenta | El uso del plan baja de 12 a 11; su siguiente petición recibe "Tu cuenta está desactivada" |
| Don Filo (5 de 5 cuentas) crea un usuario | Rechazado por el tope del plan |
| Otra empresa pide a un usuario | 404 |
| Rol propio copiando Recepción; apagarle "cobrar"; eliminarlo con una persona; sin nadie | 22 permisos; quien lo tiene ya no cobra; rechazado; eliminado |
| Encender "eliminar clientes" a toda Recepción; volver el rol a como nació | Rosa puede; vuelve a 22 y ya no puede |
| La dueña recorre 17 pantallas | Todas responden igual que antes |
| Con la base actual de `erp_saas` (solo lectura) | La dueña conserva todo y el profesional lo de siempre |

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Menú de la dueña | Aparece "Usuarios y roles" |
| La pantalla | 12 usuarios en 2 páginas, 5 roles, "11 de 20 cuentas" |
| "Nuevo usuario": celular "987abc 654 321" | Queda "987 654 321" |
| Guardar sin rol | "Elige el rol de esta persona." |
| Lista de roles del panel | No ofrece "Profesional" |
| Elegir Recepción | Debajo aparece para qué es el rol |
| Crear a Lucía (gerente, sede Miraflores) | Ventana con su correo en minúsculas y una contraseña de 10 caracteres; pasan a ser 13 |
| Nombre de la dueña | Su página: "Todo", interruptores bloqueados, "siempre puede todo" |
| Buscar "rosa" y abrir su nombre | "22 de 60", lista de pantallas a las que no entra, rejilla de a 6 ("Página 1 de 4") |
| Apagarle "Crear" en Agenda | Marca "Quitado"; pasa a 21 de 60 |
| Tocar "Agenda y citas" | Se lee qué permite cada acción |
| Página 2: encenderle "Eliminar" en Pacientes | Marca "Agregado"; 22 de 60, 1 agregado y 1 quitado |
| Tarjetas "Agregado" y "Quitado" | Dejan solo esa fila cada una |
| Entrar como Rosa | Menú sin Fichas, Tiempos, Sucursales, Empresa, Usuarios ni Configuración; con Ventas, Pagos, Caja y Cupones |
| Rosa en Caja | Carga, sin el botón "Nueva caja" |
| Rosa crea un cupón | "Tu cuenta no tiene permiso para esto: crear cupones…" |
| Tarjeta "Con permisos cambiados" | Deja solo a Rosa, "1 agregado · 1 quitado" |
| "Nuevo rol": Asistente, copiando Recepción | Se abre su página: rol propio, 22 de 60, nadie lo tiene |
| Apagarle "Crear" en Agenda; renombrarlo; tarjeta "No permite" | 21 de 60; "Asistente de recepción"; filtra |
| Editar a Lucía y pasarla a ese rol | "Los cambios se guardaron." |
| Eliminar ese rol | "Una persona tiene este rol. Cámbiale el rol antes de eliminarlo." |
| Editar a un profesional; a la dueña | Rol bloqueado con su explicación; sin interruptor de cuenta ni llave |
| "+" junto a Rol, crear "Cajera principal" | Queda elegido y el panel del usuario sigue abierto |
| Apagar "Cuenta activa" de Lucía | Cuentas del plan 12 → 11; Desactivados 1 |
| La llave de Rosa (desde la tabla y desde su página) | Pide confirmar y muestra la contraseña nueva |
| "Volver a lo de su rol" | 22 de 60, sin agregados ni quitados; el botón desaparece |
| Eliminar "Cajera principal" (página 2 de roles) | "El rol Cajera principal se eliminó." |
| Recepción: encender "Crear" en Feriados; "Volver a como nació" | 23 de 60; vuelve a 22 |
| Entrar como profesional | El mismo menú que tenía |

- Se revisaron en capturas la tabla de usuarios, la página de una persona y su rejilla.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salieron dos fallos, ya corregidos y vueltos a probar: un correo repetido escrito con mayúsculas daba error del servidor, y un permiso inexistente respondía en inglés.
- **No se probó en pantalla:** entrar como gerente de sede y como caja (se probaron por la API), el gerente sin sede en el panel (se probó por la API), reactivar una cuenta, el tope del plan con el botón bloqueado (se probó por la API en Don Filo), abrir `/users` escribiendo la dirección sin tener permiso, agendar con el permiso quitado (se probó por la API), el filtro de fecha y el orden de las dos tablas, capturas de la pestaña Roles y de la página de un rol, el ancho de la rejilla en una pantalla grande (se ajustó después de la captura), el tema oscuro y el celular.

## 11. Para aplicar

Hay una tabla y dos columnas nuevas: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`, en ese orden.

Mientras no se resetee: "Usuarios y roles" muestra "Falta actualizar la base de datos…", y el resto funciona como antes (el administrador todo, el profesional lo suyo).

Después de resetear, para probar: `rosa.medina@luxuryspa.pe` (recepción), `karina.lozano@luxuryspa.pe` (caja) y `gustavo.paredes@luxuryspa.pe` (gerente de sede), con la misma contraseña de prueba de las demás cuentas.
