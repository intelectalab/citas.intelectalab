# Pendientes

Lista viva de pendientes detectados. Cuando uno se resuelve, se retira de aquí y se registra en `Documentación/Avances`.

## Detectados el 2026-10-05 (revisión inicial)

- **Aislamiento por tenant en otros controladores.** `PipelineController`, `PipelineStageController`, `TenantController` y `TypeDocumentController` no filtran por `tenant_id`. Definir cuáles son catálogos globales y cuáles deben limitarse al tenant.
- **Rutas del menú lateral sin página.** `/appointments`, `/opportunities`, `/quotes`, `/branches`, `/settings`, `/subscribe`.
- **Archivos duplicados en frontend.** `ContactsTable1.tsx` y `FormContact1.tsx`.
- **Esquema en SQL crudo.** No hay migraciones Laravel que reconstruyan la base; el esquema vive en `apps/api/infrastructure/docker/postgres/init.sql`.
- **ESLint y configuración de Next de plantilla.** Sin convenciones de equipo definidas.
- **Repositorio sin commits.**
- **Vista de clientes para el admin SaaS.** Tras el aislamiento por tenant, un usuario sin tenant no ve clientes. Decidir si se requiere una vista global.

## Detectados el 2026-10-05 (clientes y módulos nuevos)

- **Tablas nuevas sin backend ni pantallas.** Las 45 tablas de inventario, fidelización, marketing, presupuestos, fichas, dependientes y lista de espera solo existen en el esquema. Faltan modelos, controladores, rutas y vistas.
- **Ruta `/api/contacts` duplicada.** `ContactController` ahora opera sobre `customers` igual que `/api/customers`. Su único consumidor es `ContactsTable1.tsx`, que ninguna página usa. Decidir si se elimina.
- **Columnas clínicas en `customers`.** `blood_type` y `medical_notes` se conservaron. Para rubros no clínicos conviene evaluar moverlas a las fichas configurables.
- **Código de cliente sin restricción única.** `customers` no tiene `UNIQUE (tenant_id, code)`. Dos altas simultáneas podrían recibir el mismo código.
- **Búsqueda sensible a mayúsculas.** Los controladores usan `like`, que en PostgreSQL distingue mayúsculas. Evaluar `ilike`.
- **`seed.sql` no se carga solo.** `docker-compose.yml` monta únicamente `init.sql`.
- **Páginas vacías.** `forgot-password`, `reset-password`, `users` y `subscription` tienen archivos de 0 bytes y rompen la verificación de tipos de Next.
- **Permisos gruesos.** El seed tiene 19 permisos. El prototipo usa permisos `módulo.acción` sobre 22 módulos y siete roles con alcance.

## Detectados el 2026-10-05 (login multi-tenant y registro de clientes)

- **Clientes con ficha pero sin cuenta.** Si el personal registró a un cliente con su correo, ese correo no puede crear su cuenta con el código: recibe "ya tienes una cuenta" y no tiene credenciales. No se enlaza automáticamente porque, sin verificar el correo, cualquiera con el código podría tomar la ficha de otra persona. Falta un flujo de activación: invitación por correo o contraseña inicial puesta por el personal.
- **Verificación de correo.** El registro de cliente no confirma que el correo pertenezca a quien se registra. No hay envío de correos configurado.
- **Portal de cliente.** Solo tiene la pantalla de bienvenida. Faltan reserva y consulta de citas (tampoco hay backend de `appointments`), y las rutas `/api/portal/*` propias del cliente.
- **Recuperación de contraseña con correo repetido.** Cuando se construya `forgot-password`, debe resolver a qué empresa corresponde la cuenta.
- **El registro revela si un correo ya es cliente.** Con el código de la empresa, la respuesta 409 confirma que ese correo está registrado ahí. El límite de intentos lo reduce; evaluar un mensaje neutro cuando exista verificación por correo.

## Detectados el 2026-10-05 (módulos por plan y rubro)

- **Módulos sin pantallas.** `walkin`, `classes`, `records`, `pos`, `inventory`, `packages`, `memberships`, `loyalty` y `marketing` tienen ítem de menú que lleva a la página provisional `/modules/<clave>`. `booking`, `invoicing`, `commissions`, `automations`, `resources`, `multi_branch`, `reports_adv` y `api` no tienen ítem ni pantalla.
- **Rutas nuevas deben declarar su módulo.** Todo endpoint de un módulo por plan va con `->middleware('module:<clave>')`. Hoy solo están protegidas las rutas de `agenda` y `crm`.
- **Límites del plan sin aplicar.** `users`, `branches`, `professionals`, `appointments_month` y `whatsapp_month` se guardan en el plan pero nada impide superarlos.
- **Reglas del rubro sin uso.** `/api/user` entrega `tenant.rules` (adelanto, ventana de cancelación, ficha obligatoria, etc.), pero ninguna pantalla ni endpoint las aplica todavía.
- **Catálogo de módulos duplicado.** Las etiquetas están en la tabla `saas_modules` y en `src/lib/modules.ts`. Existe `GET /api/modules` para unificarlo.
- **Género del vocabulario.** Los textos asumen masculino ("Nuevo Paciente", "Todos los clientes"). Si una empresa define un término femenino, la frase no concuerda.
- **Características del plan (`features`).** Ya no deciden el menú. Quedan como datos informativos del plan; definir si se eliminan.

## Detectados el 2026-10-05 (rubro en el registro y catálogo inicial)

- **Resto de la plantilla del rubro.** El prototipo también trae salas y equipos, profesionales de ejemplo, paquetes y membresías por rubro. Solo se copian categorías, servicios y la plantilla de ficha.
- **Empresas del seed sin catálogo de su rubro.** La plantilla se aplica al registrarse. DentalPlus, Mente Sana y las demás del seed siguen sin servicios; solo Centro Médico tiene datos de ejemplo.
- **Plantillas de ficha sin pantalla.** `record_templates` ya se llena al registrar la empresa, pero no hay modelo, endpoints ni vista para usarla o editarla.
- **Registro de empresa sin límite de intentos.** `/api/register-tenant` no tiene `throttle`, a diferencia del login y del registro de clientes.
- **Departamentos del formulario de registro.** La lista solo tiene Lima, Arequipa y Trujillo.

## Detectados el 2026-10-05 (servicios, dependientes y configuración de la empresa)

- **Cita con dependiente obligatorio.** `appointments.customer_dependent_id` existe, pero no hay backend de citas. Cuando se construya, debe exigir la mascota si `tenant.dependents.required` es verdadero.
- **Datos nuevos del servicio sin uso.** Adelanto, reserva en línea, videollamada, cupo y control de seguimiento se guardan y se editan, pero ningún flujo los aplica todavía (citas, portal, clases, `recalls`).
- **Recurso que ocupa el servicio y color de categoría.** El prototipo los define; siguen sin columnas.
- **Reglas de operación sin pantalla.** `tenant_settings.rules` no se edita en `/settings`. Conviene hacerlo cuando alguna regla ya se aplique.
- **Resto de la configuración de la empresa.** Color, logo, permitir registro de clientes y regenerar el código de empresa no tienen pantalla.
- **Colegiatura en otros rubros de salud.** La regla `professionals.license` solo está en `medical` y `vet`. Dental, psicología y fisioterapia también tienen colegio profesional; decidir si se activa.
- **Colegiatura solo se oculta.** La API de profesionales sigue aceptando `license_number` en cualquier rubro.
- **Rol `admin` del tenant.** `User::isTenantAdmin()` reconoce los roles `owner` y `admin`, pero el seed solo crea `owner`. Falta la gestión de roles y usuarios de la empresa.
- **Módulos apagados y datos existentes.** Apagar un módulo solo lo oculta y bloquea; no hay aviso de qué deja de funcionar ni de datos que quedan sin acceso.

## Detectados el 2026-10-05 (consola de la plataforma)

- **Entrar al panel de una empresa, sin probar en pantalla.** La API está probada; falta recorrer en el navegador el cambio de sesión, la franja de aviso y el regreso a la consola.
- **Bitácora sin pantalla.** `audit_events` registra las acciones de la plataforma, pero no hay dónde consultarlas. Tampoco se registran las acciones hechas dentro de una sesión de soporte.
- **Sesión de soporte con permisos completos.** Quien entra al panel de una empresa actúa como su dueño. Definir si el soporte debe quedar en solo lectura.
- **Usuarios de la plataforma.** No hay pantalla para crear cuentas de administrador o soporte SaaS; hoy salen del seed.
- **Planificador de Laravel.** `subscriptions:expire` está programado cada hora, pero no hay nada que ejecute `schedule:run` en desarrollo ni en despliegue.
- **Avisos de vencimiento.** No se avisa a la empresa antes de que venza su prueba o su suscripción.
- **Autoservicio de plan sin cobro.** `POST /api/subscriptions/subscribe` deja activa la suscripción sin registrar pago. Ahora se limita al administrador de la empresa y a planes públicos, pero sigue sin pasarela.
- **Cobros.** Solo se registran pagos a mano. Faltan cobros pendientes, comprobante, anulación y pasarela de pago.
- **Editor del catálogo inicial, sin probar el guardado en pantalla.** El editor visual carga y edita; falta guardar un rubro desde el navegador. Las fichas con odontograma o escala no tienen vista previa.
- **Volver a aplicar la plantilla.** Al cambiar de rubro, el catálogo solo se copia si la empresa no tiene servicios. No hay forma de agregarlo a una empresa que ya los tiene.
- **Eliminar tenant.** `DELETE /api/tenants/{id}` sigue borrando la empresa con todos sus datos y sin bitácora. Evaluar dejar solo la suspensión.
- **Planes a medida en la tabla de planes.** La tabla de Planes SaaS no distingue los privados ni muestra a qué empresas están asignados.
- **`/api/statuses` y `/api/users`.** Siguen abiertas a cualquier personal; `statuses` permite escribir estados globales.
- **Reglas del rubro: solo una se aplica.** De las 22 reglas editables, hoy solo `professionals.license` cambia algo en el producto. Las demás se guardan para cuando existan citas, caja, fichas e inventario.

## Detectados el 2026-10-06 (copia del rubro por empresa)

- **Vista previa sin detalle de vocabulario y reglas.** Antes de aplicar se ve qué partes cambian y qué módulos gana o pierde cada empresa, pero no qué palabras o reglas cambian exactamente.
- **Aplicar por partes solo en la API.** `parts` permite aplicar solo el vocabulario o solo los módulos; la pantalla aplica todo.
- **Aviso a la empresa.** Cuando la plataforma le aplica un cambio del rubro (por ejemplo, pierde un módulo), la empresa no recibe ningún aviso.
- **Deshacer.** La bitácora guarda la copia anterior, pero no hay acción para volver a ella.
- **Página de la empresa: acciones sin probar en pantalla.** Cambiar plan, cambiar rubro, mover vencimiento, cancelar, "Entrar al panel" y la vista del soporte SaaS. La API de todas está probada.
- **Límites sin datos reales.** Citas y WhatsApp del mes marcan 0 porque esos módulos aún no existen; los anillos de uso se llenarán cuando haya datos.

## Detectados el 2026-10-06 (configuración de la plataforma, perfil y tema)

- **Tema oscuro sin revisar en todas las pantallas.** Se revisó en configuración, tabla de Tenants y página de empresa. Faltan clientes, servicios, profesionales, planes, formularios laterales, modales y el portal del cliente. El login y el registro no tienen tema oscuro.
- **Correo de soporte sin uso.** Se guarda, pero ninguna pantalla lo muestra (por ejemplo, en el aviso de empresa suspendida).
- **Nombre del producto.** Solo cambia en el menú lateral; el login y el registro siguen con su texto fijo.
- **Contraseña temporal del administrador.** Al crear un tenant desde la consola la contraseña se muestra una vez y no se obliga a cambiarla en el primer ingreso. Tampoco se envía por correo (no hay envío de correos).
- **Datos de ejemplo al crear un tenant.** El prototipo ofrece cargar equipo, clientes y citas de muestra y el horario de la sede. No se incluyó: faltan las tablas de horario de sede y el backend de citas.
- **Dirección de la sede.** `branches` no tiene dirección; la del alta se guarda en la razón social (`companies`).
- **Configuración de plataforma por definir.** Moneda e impuestos, pasarela de pago, días de gracia tras el vencimiento, avisos de vencimiento, política de contraseñas y segundo factor, aviso global o modo mantenimiento, plantillas de correo, retención de la bitácora, rubro y plan por defecto.
- **Cambiar de cuenta sin volver a ingresar.** Hoy cierra la sesión y pide la contraseña. Un cambio directo entre empresas exigiría una forma segura de probar que las cuentas son de la misma persona.
- **Preferencia de tema por cuenta.** El tema se guarda en el navegador, no en la cuenta: no viaja entre dispositivos.
- **Notificaciones y buscador de la barra superior.** Son solo visuales.
