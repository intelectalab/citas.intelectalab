# 2026-10-08 — Los clientes ya no están en `users`: su ficha es su cuenta

## Contexto

Un cliente con acceso al portal existía dos veces: su ficha en `customers` y una cuenta en `users`, con el nombre repetido en ambas. Además, los clientes que registraba el personal no podían ingresar: nadie les creaba la cuenta.

Decisión: los clientes salen de `users`. La ficha del cliente guarda también su contraseña, y el ingreso sigue siendo uno solo (correo y contraseña) para personal y clientes.

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones. **Requiere resetear la base.**

## 1. Cómo queda

| Tema | Antes | Ahora |
|---|---|---|
| Dónde está el cliente | `customers` y, si tenía portal, también `users` | Solo `customers` |
| Su nombre, correo y celular | Repetidos en las dos tablas | Una sola vez |
| `users` | Personal y clientes (`account_type`) | Solo personal; la columna `account_type` desaparece |
| Pantalla de ingreso | Correo y contraseña | Igual. Busca el correo en el personal y en los clientes |
| Cliente registrado por el personal | Sin acceso | Con acceso desde que tiene correo y documento |

### Acceso del cliente a su portal

| Situación | Qué pasa |
|---|---|
| El personal registra a un cliente con correo y documento | Ya puede ingresar: su usuario es su correo y su contraseña inicial es su número de documento. |
| Primer ingreso | El portal solo le muestra "Crea tu contraseña". Hasta que la cree no puede reservar ni cambiar citas. La nueva no puede ser su número de documento. |
| Le falta el correo o el documento | No tiene acceso. Al completarlo, lo recibe. |
| Corrigen su documento antes de que ingrese | Su contraseña inicial pasa a ser el documento nuevo. |
| Corrigen su documento después de que creó su contraseña | Su contraseña no cambia. |
| Olvidó su contraseña | En su página, "Olvidó su contraseña" la devuelve a su número de documento y cierra sus sesiones. |
| La empresa le quita el acceso | Interruptor en su página. Se cierran sus sesiones y no puede ingresar. |
| Se registra solo con el código de empresa | Elige su contraseña; no tiene que cambiarla. |
| Se registra con un correo que ya es cliente | No se crea otra ficha: se le indica que ingrese con su correo. |
| Se elimina al cliente o se suspende la empresa | Sus sesiones se cierran. |

El paso de crear la contraseña en el primer ingreso se agregó porque un número de documento es fácil de conseguir: sin ese paso, cualquiera que lo supiera entraría al portal de otra persona.

### En la página del cliente

Nuevo bloque "Acceso al portal", dentro de la tarjeta de datos, con su estado (Activo, Aún no ingresa, Apagado, Falta su correo, Falta su documento), una explicación, el interruptor y "Olvidó su contraseña".

El formulario del cliente explica, bajo el correo, que con correo y documento podrá ingresar.

### En el portal

- Primer ingreso: pantalla "Crea tu contraseña".
- En "Tus datos": fila "Contraseña" con "Cambiar".

## 2. Lo que se revisó para que nada se rompa

| Función | Qué dependía de `users` | Resultado |
|---|---|---|
| Ingreso del personal y del equipo de la plataforma | — | Sin cambios |
| Selector de empresa al ingresar | Listaba cuentas de `users` | Lista personal y clientes juntos |
| Panel de la empresa | Rechazaba cuentas `customer` | Solo deja pasar al personal; un cliente recibe 403 |
| Consola de la plataforma | Preguntaba el rol al usuario | Un cliente recibe 403 |
| Portal (resumen, catálogo, reservar, confirmar, cambiar, cancelar, celular) | Tomaba la ficha desde la cuenta | La sesión ya es la ficha |
| Historial de la cita | Guardaba al cliente como usuario | Columna nueva `actor_customer_id`; el historial sigue mostrando su nombre |
| `created_by` de la cita | Guardaba la cuenta del cliente | Queda vacío en reservas en línea (el origen "en línea" y el historial lo indican) |
| Módulos del plan en las rutas del portal | Leía la empresa del usuario | El cliente también tiene su empresa |
| Suspender una empresa | Cerraba las sesiones de `users` | Cierra también las de sus clientes |
| Sesión de soporte (entrar al panel de una empresa) | Filtraba por tipo de cuenta | Ya no hace falta: en `users` solo hay personal |
| Conteo de usuarios del plan, lista de usuarios, detalle de la empresa en la consola | Filtraban por tipo de cuenta | Ajustados; las cuentas de clientes se cuentan en `customers` |
| Lista y detalle de clientes | — | La contraseña nunca viaja en la respuesta |
| Profesionales, fichas, bitácora | Apuntan a `users` del personal | Sin cambios |

## 3. Esquema

| Tabla | Cambio |
|---|---|
| `users` | Se quita `account_type`. |
| `customers` | Se quita `user_id`. Columnas nuevas: `password_hash`, `must_change_password`, `portal_enabled`, `password_changed_at`, `last_login_at`. Índice por correo para el ingreso. |
| `appointment_status_history` | Columna nueva `actor_customer_id`. |

Seed: Juliana Díaz ya no tiene filas en `users`; sus dos fichas (Centro Médico y DentalPlus) conservan la contraseña de las cuentas de prueba, para seguir probando el selector de empresa. Los demás clientes con correo y documento entran con su número de documento.

## 4. Cambios realizados

### Backend

| Archivo | Cambio |
|---|---|
| `app/Models/Customer.php` | Es también la cuenta: inicia sesión, tiene tokens, oculta su contraseña, informa su estado de acceso (`portal_access`) y sabe dar o restablecer el acceso inicial. |
| `app/Models/User.php` | Sin `account_type` ni relación con la ficha. |
| `app/Http/Controllers/Auth/LoginController.php` | Busca en personal y en clientes. |
| `app/Http/Controllers/Auth/RegisterCustomerController.php` | Crea solo la ficha, con su contraseña. |
| `app/Http/Controllers/AuthController.php` | `/api/user` responde con la misma forma para un cliente. |
| `app/Http/Middleware/EnsureCustomerAccount.php`, `EnsureStaffAccount.php` | Distinguen por el tipo de sesión. El de clientes exige crear la contraseña antes de usar el portal. |
| `app/Http/Controllers/Api/PortalController.php` | La sesión es el cliente; `PUT /api/portal/password`. |
| `app/Http/Controllers/Api/CustomerController.php` | Da el acceso al crear o editar; encender o apagar el acceso; restablecer la contraseña. |
| `app/Http/Controllers/Api/AppointmentController.php`, `app/Models/AppointmentStatusHistory.php` | El historial acepta al cliente como autor de un paso. |
| `TenantController`, `ImpersonationController`, `UserController`, `app/Models/Tenant.php` | Sin filtros por tipo de cuenta; suspender cierra las sesiones de los clientes. |
| `routes/api.php` | Rutas nuevas. |

### Rutas nuevas

| Ruta | Efecto |
|---|---|
| `PUT /api/portal/password` (`current_password`, `password`, `password_confirmation`) | El cliente crea o cambia su contraseña. |
| `PUT /api/customers/{id}/portal-access` (`enabled`) | El personal enciende o apaga su acceso. |
| `POST /api/customers/{id}/portal-access/reset` | Su contraseña vuelve a ser su número de documento. |

### Frontend

| Archivo | Cambio |
|---|---|
| `features/portal/components/PortalPassword.tsx` | Nuevo. Crear o cambiar la contraseña. |
| `features/portal/components/Portal.tsx`, `features/portal/portal.ts` | Pantalla del primer ingreso y fila "Contraseña". |
| `features/customers/components/CustomerPortalAccess.tsx` | Nuevo. Bloque "Acceso al portal". |
| `features/customers/components/CustomerDetail.tsx`, `FormCustomer.tsx` | Muestran el bloque y la explicación. |
| `hooks/useAuth.tsx` | Dato `must_change_password`. |

La pantalla de ingreso no cambió.

## 5. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base, con tokens reales:

| Caso | Resultado |
|---|---|
| Ingreso del administrador de empresa y del administrador SaaS; `/api/user`; consola; lista de usuarios | Sin cambios |
| Lista de clientes: trae el estado de acceso y no trae la contraseña | Correcto |
| Juliana ingresa: elige entre sus dos empresas y entra al portal de una | Correcto |
| `/api/user` de un cliente: sus datos, su empresa y su código | Correcto |
| Un cliente en el panel de la empresa, la consola o la configuración | 403 |
| El personal en el portal | 403 |
| Portal: catálogo, reservar, confirmar, cambiar fecha, cambiar celular | Correcto |
| Historial de la cita visto por el personal: los pasos del cliente llevan su nombre; los del personal, el suyo | Correcto |
| Cliente del seed entra con su número de documento | Correcto, debe crear su contraseña |
| Sin crearla intenta ver el catálogo | 403 |
| Contraseña actual incorrecta, nueva igual al documento, muy corta, no coinciden | 422, cada una con su aviso |
| Crea su contraseña: ya reserva; el documento deja de servir; entra con la nueva, también escribiendo el correo con mayúsculas | Correcto |
| El personal registra un cliente con correo y documento: entra con su documento | Correcto |
| Corrigen su documento antes de que ingrese: vale el nuevo, no el anterior | Correcto |
| Alta sin correo, alta sin documento: sin acceso; al agregar el correo, lo recibe | Correcto |
| Restablecer a un cliente sin correo | 422 |
| Cambian el documento de quien ya creó su contraseña: su contraseña sigue igual | Correcto |
| Restablecer: vuelve a ser su documento y su sesión anterior se cierra | Correcto |
| Apagar el acceso: se cierra su sesión y no puede ingresar; encenderlo lo devuelve | Correcto |
| Eliminar al cliente: ya no ingresa | Correcto |
| Otra empresa restablece a un cliente ajeno | 404 |
| Registro con código de empresa: crea la ficha, entra al portal, no crea nada en `users` | Correcto |
| Registro con un correo que ya es cliente | 409, y no toma la ficha |
| Suspender la empresa: la sesión del cliente se cierra y no puede ingresar; sin elegir empresa entra a la otra | Correcto |
| Cerrar sesión; contraseña incorrecta | Correcto; 401 |

- Las pruebas de bloqueos y citas se repitieron sobre una base limpia: mismos resultados.

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| Juliana ingresa por la pantalla de siempre, elige "Centro Médico Integral" y llega a su portal | Correcto |
| Portal: sus datos, las tres secciones, el primer paso de la reserva y "Mis citas" | Correcto |
| "Cambiar" contraseña: abre el formulario; "Cancelar" lo cierra | Correcto |
| Carlos ingresa con su número de documento: solo ve "Crea tu contraseña", sin secciones | Correcto |
| Documento equivocado; contraseñas distintas | Aviso en cada caso |
| Crea su contraseña: aparece el aviso y el portal completo | Correcto |
| Página del cliente: bloque "Acceso al portal · Activo" | Correcto |
| "Olvidó su contraseña": pide confirmar; al aceptar pasa a "Aún no ingresa" | Correcto |
| Interruptor: apagarlo deja "Apagado"; encenderlo lo devuelve | Correcto |
| Formulario del cliente: muestra la explicación bajo el correo | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó en pantalla: registrar un cliente nuevo desde el formulario y entrar con él (la API sí), el registro con código de empresa, un cliente sin correo, el aviso de acceso apagado al ingresar, ni el tema oscuro. No se pudo capturar la pantalla, así que conviene mirar "Crea tu contraseña" y el bloque "Acceso al portal".

## 6. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`. **Sin resetear, el ingreso falla**: el código ya no usa `users.account_type` y busca la contraseña de los clientes en `customers`.

Las sesiones abiertas de clientes dejan de valer tras el reseteo; vuelven a ingresar.
