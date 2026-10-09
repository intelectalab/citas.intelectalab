# Pendientes

Lista viva de pendientes detectados. Cuando uno se resuelve, se retira de aquí y se registra en `Documentación/Avances`.

## Detectados el 2026-10-05 (revisión inicial)

- **Aislamiento por tenant en otros controladores.** `PipelineController`, `PipelineStageController`, `TenantController` y `TypeDocumentController` no filtran por `tenant_id`. Definir cuáles son catálogos globales y cuáles deben limitarse al tenant.
- **Rutas del menú lateral sin página.** `/opportunities`, `/quotes`, `/branches`, `/subscribe`.
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
- **Búsqueda en servicios y profesionales.** Siguen con `like`: distinguen mayúsculas y tildes. Clientes y citas ya usan `App\Support\Search`.
- **`seed.sql` no se carga solo.** `docker-compose.yml` monta únicamente `init.sql`.
- **Páginas vacías.** `forgot-password`, `reset-password`, `users` y `subscription` tienen archivos de 0 bytes y rompen la verificación de tipos de Next.
- **Permisos gruesos.** El seed tiene 19 permisos. El prototipo usa permisos `módulo.acción` sobre 22 módulos y siete roles con alcance.

## Detectados el 2026-10-05 (login multi-tenant y registro de clientes)

- **Verificación de correo.** El registro de cliente no confirma que el correo pertenezca a quien se registra. No hay envío de correos configurado.
- **Recuperación de contraseña con correo repetido.** Cuando se construya `forgot-password`, debe resolver a qué empresa corresponde la cuenta.
- **El registro revela si un correo ya es cliente.** Con el código de la empresa, la respuesta 409 confirma que ese correo está registrado ahí. El límite de intentos lo reduce; evaluar un mensaje neutro cuando exista verificación por correo.

## Detectados el 2026-10-05 (módulos por plan y rubro)

- **Módulos sin pantallas.** `walkin`, `classes`, `pos`, `inventory`, `packages`, `memberships`, `loyalty` y `marketing` tienen ítem de menú que lleva a la página provisional `/modules/<clave>`. `booking`, `invoicing`, `commissions`, `automations`, `resources`, `multi_branch`, `reports_adv` y `api` no tienen ítem ni pantalla.
- **Rutas nuevas deben declarar su módulo.** Todo endpoint de un módulo por plan va con `->middleware('module:<clave>')`. Hoy solo están protegidas las rutas de `agenda` y `crm`.
- **Límites del plan sin aplicar.** `users`, `branches`, `professionals` y `whatsapp_month` se guardan en el plan pero nada impide superarlos. El de citas del mes ya se aplica.
- **Reglas del rubro sin pantalla.** La API de citas ya aplica las de agenda, cancelación e inasistencia, pero ninguna pantalla las muestra todavía.
- **Catálogo de módulos duplicado.** Las etiquetas están en la tabla `saas_modules` y en `src/lib/modules.ts`. Existe `GET /api/modules` para unificarlo.
- **Género del vocabulario.** Los textos asumen masculino ("Nuevo Paciente", "Todos los clientes"). Si una empresa define un término femenino, la frase no concuerda.
- **Características del plan (`features`).** Ya no deciden el menú. Quedan como datos informativos del plan; definir si se eliminan.

## Detectados el 2026-10-05 (rubro en el registro y catálogo inicial)

- **Resto de la plantilla del rubro.** El prototipo también trae salas y equipos, profesionales de ejemplo, paquetes y membresías por rubro. Solo se copian categorías, servicios y la plantilla de ficha.
- **Empresas del seed sin catálogo de su rubro.** La plantilla se aplica al registrarse. DentalPlus, Mente Sana y las demás del seed siguen sin servicios; solo Centro Médico tiene datos de ejemplo.
- **Registro de empresa sin límite de intentos.** `/api/register-tenant` no tiene `throttle`, a diferencia del login y del registro de clientes.
- **Departamentos del formulario de registro.** La lista solo tiene Lima, Arequipa y Trujillo.

## Detectados el 2026-10-05 (servicios, dependientes y configuración de la empresa)

- **Datos nuevos del servicio sin uso.** La reserva en línea ya se aplica en el portal y el control de seguimiento crea su control al atender. Adelanto, videollamada y cupo se guardan y se editan, pero ningún flujo los aplica todavía.
- **Recurso que ocupa el servicio y color de categoría.** El prototipo los define; siguen sin columnas.
- **Reglas de operación sin pantalla.** `tenant_settings.rules` no se edita en `/settings`. Conviene hacerlo cuando alguna regla ya se aplique.
- **Resto de la configuración de la empresa.** Color, logo, permitir registro de clientes y regenerar el código de empresa no tienen pantalla.
- **Colegiatura en otros rubros de salud.** La regla `professionals.license` solo está en `medical` y `vet`. Dental, psicología y fisioterapia también tienen colegio profesional; decidir si se activa.
- **Colegiatura solo se oculta.** La API de profesionales sigue aceptando `license_number` en cualquier rubro.
- **Rol `admin` del tenant.** `User::isTenantAdmin()` reconoce los roles `owner` y `admin`, pero el seed solo crea `owner`. Falta la gestión de roles y usuarios de la empresa.
- **Módulos apagados y datos existentes.** Apagar un módulo solo lo oculta y bloquea; no hay aviso de qué deja de funcionar ni de datos que quedan sin acceso.

## Detectados el 2026-10-05 (consola de la plataforma)

- **Entrar al panel de una empresa, sin probar en pantalla.** La API está probada; falta recorrer en el navegador el cambio de sesión, la franja de aviso y el regreso a la consola.
- **Bitácora sin pantalla.** `audit_events` registra las acciones de la plataforma y los accesos de emergencia a fichas privadas, pero no hay dónde consultarlas. Tampoco se registran las acciones hechas dentro de una sesión de soporte.
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
- **Reglas del rubro que aún no se aplican.** De las editables, ya se usan `professionals.license`, `cancel.window_hours`, `agenda.checkin_step` y `agenda.respect_buffers`. Las de adelanto, cargos, fichas, caja e inventario esperan a esos módulos.

## Detectados el 2026-10-06 (copia del rubro por empresa)

- **Vista previa sin detalle de vocabulario y reglas.** Antes de aplicar se ve qué partes cambian y qué módulos gana o pierde cada empresa, pero no qué palabras o reglas cambian exactamente.
- **Aplicar por partes solo en la API.** `parts` permite aplicar solo el vocabulario o solo los módulos; la pantalla aplica todo.
- **Aviso a la empresa.** Cuando la plataforma le aplica un cambio del rubro (por ejemplo, pierde un módulo), la empresa no recibe ningún aviso.
- **Deshacer.** La bitácora guarda la copia anterior, pero no hay acción para volver a ella.
- **Página de la empresa: acciones sin probar en pantalla.** Cambiar plan, cambiar rubro, mover vencimiento, cancelar, "Entrar al panel" y la vista del soporte SaaS. La API de todas está probada.
- **Límites sin datos reales.** WhatsApp del mes marca 0 porque ese módulo aún no existe. Las citas del mes ya se cuentan.

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

## Detectados el 2026-10-06 (disponibilidad: horarios y ausencias)

- **Horario de atención de la sede.** No existe tabla. Hoy el horario de un profesional no se limita al de su sede.
- **El ojo de la tabla de profesionales no abre nada.** Guarda el profesional elegido, pero ninguna vista lo muestra. El prototipo tiene una página del profesional con horario, perfil, ausencias y rendimiento.
- **Mensajes de validación en inglés.** Los que genera Laravel por defecto ("The reason field is required") no están traducidos; solo salen en español los escritos a mano.
- **Panel de horario sin revisar en tema oscuro.**

## Detectados el 2026-10-06 (API de citas y menú Agenda)

- **Pantallas del grupo Agenda.** Solo falta Lista de espera: está en el menú, lleva a la página provisional y no tiene API. Agenda, Citas, Bloqueos y ausencias y Controles y seguimiento ya tienen pantalla.
- **Cargos por cancelar tarde y por no asistir.** La API avisa de la cancelación tardía, pero no cobra nada. Depende de la caja.
- **Adelanto para reservar.** `requires_deposit` del servicio y las reglas de adelanto no se aplican. Depende de los pagos.
- **Salas y equipos en la cita.** No se valida que haya una sala libre ni se asigna `room_id`.
- **Permisos por acción.** El prototipo separa confirmar, registrar llegada, atender, cancelar, reprogramar y forzar. Hoy cualquier personal con el módulo puede hacerlo todo, salvo forzar (solo el administrador) y las citas ajenas de un profesional.
- **Avisos al cliente.** Confirmación de reserva, recordatorio y aviso de reprogramación no se envían: no hay envío de mensajes.
- **Citas recurrentes y clases grupales.** El prototipo las tiene; no se incluyeron.
- **Aviso a la lista de espera al liberarse un horario** (`agenda.waitlist_auto`).
- **Reglas de agenda sin editor.** `agenda.require_confirmation`, `agenda.client_overlap`, `agenda.allow_override`, `agenda.slot_interval`, `cancel.require_reason` y `noshow.grace_minutes` usan su valor por defecto; no están en el editor de reglas de la plataforma (`src/lib/rules.ts`) ni en `/settings`.
- **Citas de ejemplo en el seed.** No hay; la agenda se verá vacía tras un reseteo hasta crear citas.
- **Asignación de servicios a profesionales.** La tabla `service_professionals` existe y la API la respeta, pero no hay pantalla para llenarla.
- **Bitácora de citas.** Los cambios quedan en el historial de la cita, no en `audit_events`.

## Detectados el 2026-10-06 (pantallas de Agenda y Citas)

- **Vistas de semana y mes de la agenda.** Solo existe la vista de día.
- **Lista de citas sin paginar.** Trae hasta 500 citas del periodo de una vez.
- **Sin rango de fechas libre en la lista.** Solo los cuatro periodos fijos.
- **Horas sin atención en tema oscuro.** Se ven algo más claras que las horas de atención; en tema claro es al revés. Conviene revisar el contraste.
- **Pausas del servicio en la grilla.** El prototipo dibuja el tiempo de preparación y limpieza junto a la cita; aquí no se ve, aunque la API sí lo respeta.
- **Color por profesional o por categoría.** Las citas se pintan por fase. `professionals.color_code` existe pero no se usa.
- **Pruebas en pantalla que faltan.** Varias sedes, rubro con mascotas, "No asistió", franjas de bloqueo, citas cruzadas, filtros de la lista, notas y la vista de un profesional.
- **Reglas de ESLint.** Los archivos nuevos repiten patrones que el resto del proyecto ya usa y que la configuración de plantilla marca como error (`any`, cambios de estado dentro de efectos).

## Detectados el 2026-10-06 (arrastrar citas, refresco y cliente nuevo)

- **Refresco instantáneo.** La agenda consulta cada 20 segundos. Si se quiere ver los cambios al momento, hace falta una conexión en vivo (WebSockets, por ejemplo Laravel Reverb).
- **Arrastrar en pantallas táctiles.** El arrastre usa el del navegador, que en tabletas y celulares no funciona igual. Sin probar.
- **Forzar un horario al arrastrar.** Si el horario se puede forzar (fuera de horario o choque con pausa), la ventana solo muestra el motivo; para forzarlo hay que usar "Reprogramar" en el panel.
- **Cambiar la duración estirando la cita.** No existe.
- **Pruebas en pantalla que faltan de esta entrega.** Refresco de la lista de Citas y al volver a la pestaña, el botón "Actualizar ahora", el botón "+" en un rubro con mascotas, y el ajuste de las capas de la grilla hecho tras las pruebas.

## Detectados el 2026-10-06 (cronómetro y tiempos de atención)

- **Los tiempos dependen de marcar cada paso.** Si el personal no marca "Iniciar atención" y "Dar por atendida" cuando ocurren, la atención queda sin medir. No hay recordatorio ni cierre automático de una cita que quedó abierta.
- **Empresas sin el paso de llegada.** Con `agenda.checkin_step` apagado se puede dar por atendida una cita pendiente o confirmada sin iniciarla, y esa atención no se mide.
- **Corregir un tiempo mal tomado.** No hay forma de editar la hora de inicio o de fin de una atención.
- **Atención que se alarga.** El cronómetro se pone en rojo, pero no se avisa al siguiente cliente ni se mueve su cita.
- **Tiempos de atención: más filtros.** Solo hay tres periodos fijos. Faltan rango libre, sede, y ver un profesional o un servicio en detalle.
- **Exportar los tiempos.** No hay descarga a Excel.
- **"Más ágil" es una comparación simple.** Compara la diferencia frente al tiempo asignado, sin considerar cuántas atenciones tiene cada quien ni qué servicios hace.
- **Pruebas en pantalla que faltan.** La página de tiempos en tema oscuro, con varias sedes y con un usuario profesional.

## Detectados el 2026-10-08 (fichas de atención)

- **Ficha sin cita.** Solo se puede llenar una ficha desde una cita. No hay forma de registrar una atención que no pasó por la agenda.
- **Firma del cliente.** El consentimiento se acepta con una casilla; no hay firma dibujada. El PDF trae las líneas para firmar a mano.
- **Fotos y archivos en la ficha.** No hay campo para adjuntar (el rubro de estética registra fotos de antes y después).
- **Dos editores de campos.** El de la plataforma (`TemplateEditor`) y el de la empresa (`RecordFieldsEditor`) hacen lo mismo con código separado. Conviene unificarlos.
- **Consentimiento obligatorio tras eliminar la plantilla.** Si se elimina una plantilla, sus borradores ya no exigen el consentimiento al firmar.
- **Reglas de fichas sin pantalla para la empresa.** Ficha obligatoria, plazo de corrección y fichas privadas se definen en el rubro; la empresa no puede cambiarlas en `/settings`.
- **Cambios en la ficha sin bitácora.** Una corrección dentro del plazo reemplaza los valores; no se guarda qué decía antes.
- **Odontograma básico.** Marca un estado por pieza; no distingue caras del diente ni dentición temporal.
- **Pruebas en pantalla que faltan.** Crear y eliminar una ficha, atenciones anteriores, ficha privada y bloqueada, fecha sugerida del próximo control, rubro con mascotas, vista de un profesional y tema oscuro.

## Detectados el 2026-10-08 (acceso de emergencia, PDF e historia del cliente)

- **Revisar el diseño del PDF.** Se genera y es válido, pero no se pudo ver. Falta el logo de la empresa (no hay dónde subirlo todavía).
- **Aviso al autor cuando abren su ficha.** Lo ve al abrir la ficha; no recibe ninguna notificación.
- **Lista de accesos de emergencia para el administrador.** Quedan en la bitácora, que no tiene pantalla.
- **Soporte SaaS y fichas privadas.** Quien entra al panel de una empresa actúa como su dueño, así que también puede pedir acceso de emergencia. Definir si debe impedirse.
- **Descargar la historia completa de un cliente.** Solo se descarga ficha por ficha.
- **Nueva cita desde la página del cliente.** No hay botón; hay que ir a la agenda y buscarlo.
- **Página del cliente sin embudo ni ventas.** Muestra datos, citas y fichas. Faltan su etapa en el embudo, compras, paquetes y saldo cuando existan esos módulos.
- **Vulnerabilidades en una dependencia anterior.** `composer audit` avisa de dos (una alta) en `league/commonmark`, que viene con Laravel. Se resuelve con `composer update league/commonmark`.
- **Pruebas en pantalla que faltan.** Editar al cliente desde su página, abrir una cita desde ahí, cliente con mascotas, aviso de emergencia visto por el autor y tema oscuro.

## Detectados el 2026-10-08 (portal del cliente)

- **Adelanto en línea.** Los servicios que piden adelanto no se pueden reservar desde el portal: falta la pasarela de pago.
- **Avisos al cliente.** No recibe confirmación, recordatorio ni aviso de cambio: no hay envío de mensajes.
- **Aviso a la sede.** El personal no recibe ninguna notificación cuando un cliente reserva, cambia o cancela; lo ve en la agenda.
- **Reserva pública sin cuenta y kiosko.** El prototipo los tiene. Hoy solo reserva quien ya tiene cuenta de cliente.
- **Lo que el portal aún no muestra.** Sus controles pendientes, sus fichas o indicaciones, sus paquetes, membresías, puntos y pagos.
- **Datos del cliente.** Solo puede cambiar su celular. Ya cambia su contraseña. No edita correo, dirección ni a sus mascotas ya registradas.
- **Reglas de la reserva sin editor.** `booking.enabled`, `booking.min_lead_minutes`, `booking.choose_professional`, `cancel.client_allowed` y `reschedule.max_times` usan su valor por defecto; no están en el editor de reglas ni en `/settings`.
- **Exigir adelanto tras varias inasistencias** (`noshow.block_after`). Sin aplicar.
- **Logo y color de la empresa en el portal.** Usa el color del rubro; la empresa aún no tiene dónde subir su logo ni elegir su color.
- **Portal sin tema oscuro.**
- **El portal no se actualiza solo.** Si la sede cambia la cita, el cliente lo ve al volver a entrar.
- **Pruebas en pantalla que faltan.** Paso de mascotas, varias sedes, servicio con adelanto, empresa sin reserva en línea, y ver la reserva del portal en la agenda del personal.

## Detectados el 2026-10-08 (bloqueos y ausencias)

- **Resolver varias citas a la vez.** Las citas dentro de un bloqueo se abren una por una. No hay "reprogramar todas" ni "cancelar todas con aviso".
- **Aviso al cliente cuando su cita queda dentro de un bloqueo.** Depende del envío de mensajes.
- **Bloqueos que se repiten.** Un feriado anual o una reunión semanal se registran cada vez.
- **Feriados nacionales precargados.** No hay calendario de feriados; cada empresa los registra.
- **Bloqueos anteriores sin límite de fecha.** La pestaña trae los últimos 200.
- **Tipo de bloqueo en la agenda.** La franja dice "No atiende" y el motivo; no distingue con color el bloqueo que se puede forzar.
- **Bitácora de bloqueos.** Se guarda quién lo creó, pero editar o eliminar no queda en `audit_events`.
- **Solapes entre bloqueos.** Se pueden registrar dos bloqueos sobre el mismo rango.
- **Pruebas en pantalla que faltan.** Forzar una cita sobre un bloqueo de horario desde la agenda, empresa con varias sedes, la vista de un profesional, tema oscuro y el aspecto del panel "Nuevo bloqueo".

## Detectados el 2026-10-08 (clientes fuera de `users`)

- **Recuperar la contraseña sin pasar por la empresa.** Hoy el cliente que la olvida debe pedir a la sede que la restablezca. Depende del envío de correos.
- **Avisar al cliente que ya tiene acceso.** Al registrarlo no recibe nada: la sede debe decirle que entra con su correo y su documento.
- **Contraseña inicial predecible.** Es el número de documento hasta el primer ingreso. Se exige cambiarla y el login tiene límite de intentos, pero mientras no ingrese, quien conozca su correo y su documento puede entrar por él. Evaluar un enlace de activación cuando haya envío de mensajes.
- **Correo repetido entre clientes de una misma empresa.** Se permite (familias que comparten correo). Si además comparten contraseña, el selector de ingreso muestra la empresa dos veces sin decir de quién es cada cuenta.
- **Correo del cliente sin verificar.** El personal puede escribirlo mal y el acceso queda a nombre de otra dirección.
- **Reglas de contraseña.** Solo se exigen 8 caracteres y que no sea el documento.
- **`created_by` de las reservas en línea queda vacío.** El autor está en el historial de la cita (`actor_customer_id`).
- **Bitácora del acceso al portal.** Encender, apagar y restablecer el acceso no quedan en `audit_events`.
- **Mensaje del primer ingreso.** Si escribe mal su documento al crear la contraseña, el aviso dice "contraseña actual".
- **Pruebas en pantalla que faltan.** Registrar un cliente desde el formulario y entrar con él, registro con código de empresa, cliente sin correo, aviso de acceso apagado al ingresar, tema oscuro y el aspecto de "Crea tu contraseña" y del bloque "Acceso al portal".

## Detectados el 2026-10-08 (controles y seguimiento)

- **Avisar al cliente de su control.** "Avisado" es una marca manual; no se envía nada. Depende del envío de mensajes.
- **Controles en la página del cliente y en su portal.** Solo se ven en la pantalla de Controles (la API ya filtra por cliente).
- **Empresas sin el módulo de fichas.** Controles depende de "Fichas de atención e historia". Una peluquería sin ese módulo no ve la pantalla ni recibe los controles que piden sus servicios (retoques). Evaluar pasarlo al módulo de agenda.
- **Reprogramar una cita no mueve la fecha del control.** El control sigue enlazado a la cita, que es lo que cuenta.
- **Controles repetidos.** Se puede registrar a mano el mismo control dos veces para un cliente.
- **Lista sin paginar.** Trae hasta 500 controles por pestaña. Los cerrados no tienen filtro de fechas.
- **Dar por realizado sin confirmar.** El check cierra el control de inmediato; se deshace desde "Cerrados".
- **Controles de un cliente eliminado.** Dejan de listarse, pero siguen en la base.
- **Pruebas en pantalla que faltan.** Rubro con mascotas, vista de un profesional, un control nacido de una ficha o de un servicio, tema oscuro y el aspecto general de la pantalla.
