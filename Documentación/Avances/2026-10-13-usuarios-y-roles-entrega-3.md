# 2026-10-13 — Usuarios y roles, entrega 3: cada quien ve su sede, y el gerente maneja a su personal

## Contexto

Con las entregas 1 y 2 el gerente de sede ya podía cambiar horarios, salas, servicios, caja y ventas, pero veía todas las sedes y no podía manejar a la gente de la suya: para dar de alta a una recepcionista había que ir al dueño.

Decidido antes de programar:

| Pregunta | Respuesta |
|---|---|
| ¿Recepción y Caja también ven solo su sede? | Sí |
| ¿Los clientes son de toda la empresa? | Sí. Dentro de cada cliente se ven solo las citas y ventas de la propia sede |
| ¿El gerente maneja al personal de su sede? | Sí |
| ¿El gerente cambia servicios, precios, cupones y plantillas de ficha (valen para todas las sedes)? | Por ahora sí, como hasta hoy |

## 1. Qué tanto ve cada rol

En la página de cada rol hay una sección nueva, **"Qué ve quien tiene este rol"**:

| Opción | Para quién, al nacer | Ejemplo |
|---|---|---|
| Toda la empresa | Administrador | Ve y trabaja con todas las sedes |
| Solo su sede | Gerente de sede, Recepción, Caja | Rosa, recepcionista de Miraflores, ve la agenda y la caja de Miraflores |
| Solo lo suyo | Profesional | Sus propias citas, fichas y controles, como hasta hoy |

- El cambio rige al instante para todas las personas con ese rol.
- La sede de cada persona es la que se elige en su usuario. Si el rol trabaja solo con su sede, la sede pasa a ser obligatoria.
- Un rol propio nace viendo lo mismo que el rol del que se copió.
- "Volver a como nació" también devuelve lo que ve.
- El Administrador siempre ve toda la empresa, y "Solo lo suyo" es solo para el rol Profesional.
- En la tabla de roles hay una columna nueva, "Qué ve"; en la página de cada persona, el dato "Qué ve: Solo su sede: Sede San Borja".
- Una empresa con una sola sede no nota ningún cambio.

## 2. Lo que se limita a su sede

Para quien ve "solo su sede", cada pantalla muestra y deja cambiar únicamente lo de su sede:

| Pantalla | Qué ve | Qué no puede |
|---|---|---|
| Agenda y Citas | Las citas de su sede; el formulario ofrece solo su sede y quienes atienden ahí | Abrir, cobrar o reprogramar una cita de otra sede |
| Lista de espera | Quienes esperan en su sede | Anotar a alguien en otra sede |
| Bloqueos y ausencias | Los cierres de su sede y las ausencias de quienes atienden ahí | Cerrar otra sede |
| Feriados | Los nacionales y los de su sede | Cargar o cambiar los nacionales, que valen para todas |
| Profesionales y Horarios del equipo | Quienes atienden en su sede y quienes aún no tienen horario (los recién registrados) | Cambiar a quien atiende en otra sede |
| Salas y equipos | Los de su sede | Registrar o cambiar en otra sede |
| Caja | Las cajas y los turnos de su sede | Abrir, mover o cerrar una caja de otra sede, o agregar una ahí |
| Ventas y Pagos | Los de su sede, también "Atendidas sin cobrar" | Anular o devolver una venta de otra sede |
| Fichas de atención | Las de las citas de su sede | Abrir una ficha de otra sede |
| Tiempos de atención e Inicio | Los números de su sede | — |
| Sucursales | Solo la suya; así toda lista de sedes del sistema le ofrece solo esa | Abrir la página de otra sede |

Si intenta algo de otra sede, el sistema lo dice: "Esto es de otra sede. Tu cuenta trabaja solo con Sede San Borja."

**Siguen siendo de toda la empresa:** Clientes, Servicios y precios, Cupones, Plantillas de ficha y Controles.

**Sin sede asignada:** quien debe ver solo su sede y no tiene una no ve nada de sedes (ni agenda ni caja ni ventas), en vez de verlo todo por error. Lo dice su página ("no verá agenda, caja ni ventas hasta que se la asignes") y también el aviso al elegir "Solo su sede" en un rol, que dice cuántas personas quedaron sin sede.

## 3. El gerente maneja al personal de su sede

El Gerente de sede nace con permiso para ver, crear y cambiar usuarios. En Usuarios y roles:

- Ve solo a la gente de su sede: quienes tienen esa sede asignada y los profesionales que atienden ahí. La pantalla lo dice: "Ves al personal de tu sede, Sede San Borja. Lo que registres queda en esa sede."
- **Crea cuentas** de Gerente de sede, Recepción o Caja; la sede viene puesta y queda en la suya.
- **Desactiva, da una contraseña nueva y enciende o apaga permisos**, solo los que él mismo tiene.
- **No puede:** dar el rol Administrador ni un rol que ve toda la empresa, tocar a gente de otra sede, ni crear, cambiar o eliminar roles. Los roles valen para todas las sedes: los cambia quien administra la empresa. Puede ver las páginas de los roles, con sus interruptores bloqueados.

Ejemplo: Gustavo, gerente de San Borja, registra a Elena como recepcionista (queda en San Borja), le apaga "Agendar citas" y le da su contraseña temporal. Si intenta abrir a Rosa, de Miraflores, el sistema le responde "Esta persona no es de tu sede."

## 4. Base de datos

`init.sql`: columna `roles.scope` (`tenant`, `branch` u `own`; por defecto `tenant`). Sin cambios en `seed.sql` ni en `seed_demo.sql`: cada rol de fábrica recibe lo que ve junto con sus permisos, la primera vez que hace falta.

## 5. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/app/Support/Access.php` | Qué ve cada rol (`SCOPES`, `scopeOf`), la sede a la que se limita cada persona (`branchLimit`), el rechazo de lo de otra sede (`assertBranch`) y quiénes atienden en una sede. El gerente nace con permisos sobre usuarios. |
| `apps/api/app/Http/Controllers/Api/AppointmentController.php` | Citas, agenda del día, formulario, disponibilidad, tiempos, agendar y reprogramar, solo en su sede. Vale también para cobrar una cita. |
| `WaitlistController.php`, `AvailabilityBlockController.php`, `HolidayController.php` | Lista de espera, bloqueos y feriados por sede. |
| `ProfessionalController.php`, `ProfessionalScheduleController.php`, `RoomController.php` | Profesionales, horarios y salas por sede. |
| `CashController.php`, `SalesController.php` | Cajas, turnos, ventas y pagos por sede. |
| `CareRecordController.php`, `DashboardController.php`, `BranchController.php` | Fichas, inicio y sucursales por sede. |
| `AccessController.php`, `routes/api.php` | El personal de su sede, roles que puede dar, roles solo para quien ve toda la empresa; nuevo `PUT /access/roles/{id}/scope`. |
| `apps/api/app/Models/User.php`, `Role.php` | Lo que ve, calculado una vez por petición. |
| `apps/api/infrastructure/docker/postgres/init.sql` | La columna `roles.scope`. |
| `apps/web/src/features/access/` | "Qué ve" en la página del rol, en la tabla de roles y en la página de cada persona; sede obligatoria según el rol; el aviso del personal de su sede. |

## 6. Verificación

Los tres archivos cargan sin errores en una base temporal (ya eliminada). `erp_saas` no se tocó.

**Por la API** (Centro Médico; Gustavo es gerente de San Borja; Rosa y Karina trabajan en Miraflores):

| Caso | Resultado |
|---|---|
| Lo que ve cada rol al nacer | Administrador: toda la empresa; gerente, recepción y caja: su sede; profesional: lo suyo |
| La dueña: citas de 60 días | 500, de las tres sedes |
| Gustavo: sucursales; citas | Solo San Borja; 230 citas, todas de San Borja |
| Gustavo: abrir o cobrar una cita de Miraflores; agenda del día de Miraflores | Rechazados: "Esto es de otra sede…" |
| Gustavo: agenda de San Borja; formulario de cita | Permitido; ofrece solo San Borja y sus 3 profesionales |
| Gustavo: caja | Solo la de San Borja; abrir la de Miraflores o agregar una ahí, rechazado |
| Gustavo: salas | Solo las de San Borja; registrar una en Miraflores, rechazado |
| Gustavo: horarios; profesionales | Solo San Borja; 3 de 8; editar uno de Miraflores, rechazado |
| Gustavo: feriados | Ve los nacionales; cargarlos, rechazado ("vale para todas las sedes") |
| Gustavo: clientes | 17, los mismos que la dueña |
| Gustavo en Usuarios y roles | 4 personas de San Borja; puede dar Gerente, Recepción y Caja |
| Gustavo: ver o cambiar a Rosa; crear a alguien en Miraflores; crear un administrador | Rechazados |
| Gustavo: crear una recepcionista sin elegir sede | Queda en San Borja; le apaga "cobrar" y le da contraseña nueva |
| Gustavo: crear un rol; cambiar permisos o lo que ve un rol | Rechazados |
| Rosa: citas | 189, solo de Miraflores |
| Karina: caja | Solo Miraflores |
| La dueña pone Recepción en "toda la empresa" | Rosa pasa a ver 500 citas de las tres sedes; al volver, solo Miraflores |
| "Solo lo suyo" para Recepción; cambiar lo que ve el Administrador | Rechazados, con su explicación |
| Karina sin sede asignada | 0 citas; la agenda le explica que no tiene sede |
| Elegir "solo su sede" con alguien sin sede | El aviso dice cuántas personas quedaron sin sede |
| Carlos (profesional) | Solo sus 68 citas, como antes |

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Entrar como Gustavo | Menú con Usuarios y roles; agenda de San Borja sin errores |
| Horarios, Salas, Caja y Sucursales | Solo San Borja |
| Usuarios y roles | "Ves al personal de tu sede, Sede San Borja…"; 4 personas |
| "Nuevo usuario" | Roles: Gerente de sede, Recepción y Caja; la sede viene puesta en San Borja |
| Elegir Recepción | "Verá solo lo de esta sede…" |
| Crear a Elena | Ventana con su contraseña; aparece en la lista, en San Borja |
| Su página | "Qué ve: Solo su sede: Sede San Borja" |
| Apagarle "Crear" en Agenda | Marca "Quitado" |
| "Ver el rol Recepción" | Opciones de "qué ve" e interruptores bloqueados; en "quiénes lo tienen", solo Elena |
| Entrar como la dueña: tabla de roles | Columna "Qué ve" con lo de cada rol |
| Recepción: elegir "Toda la empresa" y volver a "Solo su sede" | "Listo: quien tiene este rol ahora ve toda la empresa." y luego "…solo su sede."; quiénes lo tienen: Elena y Rosa |

- Se revisó en una captura la sección "Qué ve quien tiene este rol".
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En la prueba salió un mensaje que se repetía ("Esto es de otra sede. Esto es de otra sede…"); se corrigió y no se volvió a ver en pantalla.
- **No se probó en pantalla:** las pantallas de Rosa y Karina (se probaron por la API), Ventas, Fichas, Inicio, Lista de espera y Bloqueos como gerente (se probaron por la API), los mensajes de otra sede en pantalla, cambiar la sede de alguien, un rol propio copiado, "Volver a como nació" con lo que ve, tema oscuro y celular.

## 7. Para aplicar

Hay una columna nueva: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`. Mientras no se resetee, cada rol de fábrica ve lo de fábrica (gerente, recepción y caja su sede; el profesional lo suyo) y la sección "Qué ve" no se puede cambiar.
