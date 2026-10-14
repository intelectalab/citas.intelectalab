# Pendientes

Lista viva de pendientes detectados. Cuando uno se resuelve, se retira de aquí y se registra en `Documentación/Avances`.

## Detectados el 2026-10-05 (revisión inicial)

- **Aislamiento por tenant en otros controladores.** `PipelineController`, `PipelineStageController`, `TenantController` y `TypeDocumentController` no filtran por `tenant_id`. Definir cuáles son catálogos globales y cuáles deben limitarse al tenant.
- **Rutas del menú lateral sin página.** `/opportunities`, `/quotes`, `/subscribe`.
- **Archivos duplicados en frontend.** `ContactsTable1.tsx` y `FormContact1.tsx`.
- **Esquema en SQL crudo.** No hay migraciones Laravel que reconstruyan la base; el esquema vive en `apps/api/infrastructure/docker/postgres/init.sql`.
- **ESLint y configuración de Next de plantilla.** Sin convenciones de equipo definidas.
- **Repositorio sin commits.**
- **Vista de clientes para el admin SaaS.** Tras el aislamiento por tenant, un usuario sin tenant no ve clientes. Decidir si se requiere una vista global.

## Detectados el 2026-10-05 (clientes y módulos nuevos)

- **Tablas nuevas sin backend ni pantallas.** Las 45 tablas de inventario, fidelización, marketing, presupuestos, fichas, dependientes y lista de espera solo existen en el esquema. Faltan modelos, controladores, rutas y vistas.
- **Ruta `/api/contacts` duplicada.** `ContactController` ahora opera sobre `customers` igual que `/api/customers`. Su único consumidor es `ContactsTable1.tsx`, que ninguna página usa. Decidir si se elimina.
- **Columnas clínicas en `customers`.** `blood_type` ya solo se muestra y se guarda en los rubros de salud (regla `customers.clinical_fields`). `medical_notes` se usa en todos como observaciones. Queda evaluar moverlas a las fichas configurables.
- **Código de cliente sin restricción única.** `customers` no tiene `UNIQUE (tenant_id, code)`. Dos altas simultáneas podrían recibir el mismo código.
- **Búsqueda en servicios y profesionales.** Siguen con `like`: distinguen mayúsculas y tildes. Clientes y citas ya usan `App\Support\Search`.
- **Los seeds no se cargan solos.** `docker-compose.yml` monta únicamente `init.sql`; `seed.sql` y `seed_demo.sql` se cargan a mano, en ese orden.
- **Páginas vacías.** `forgot-password`, `reset-password` y `subscription` tienen archivos de 0 bytes y rompen la verificación de tipos de Next.

## Detectados el 2026-10-05 (login multi-tenant y registro de clientes)

- **Verificación de correo.** El registro de cliente no confirma que el correo pertenezca a quien se registra. No hay envío de correos configurado.
- **Recuperación de contraseña con correo repetido.** Cuando se construya `forgot-password`, debe resolver a qué empresa corresponde la cuenta.
- **El registro revela si un correo ya es cliente.** Con el código de la empresa, la respuesta 409 confirma que ese correo está registrado ahí. El límite de intentos lo reduce; evaluar un mensaje neutro cuando exista verificación por correo.

## Detectados el 2026-10-05 (módulos por plan y rubro)

- **Módulos sin pantallas.** `walkin`, `classes`, `pos`, `inventory`, `packages`, `memberships`, `loyalty` y `marketing` tienen ítem de menú que lleva a la página provisional `/modules/<clave>`. `booking`, `invoicing`, `commissions`, `automations`, `resources`, `multi_branch`, `reports_adv` y `api` no tienen ítem ni pantalla.
- **Rutas nuevas deben declarar su módulo.** Todo endpoint de un módulo por plan va con `->middleware('module:<clave>')`. Hoy solo están protegidas las rutas de `agenda` y `crm`.
- **Límites del plan sin aplicar.** Falta `whatsapp_month` (ese módulo aún no existe). Ya se aplican citas del mes, sucursales activas, profesionales y cuentas de usuario.
- **Reglas del rubro sin pantalla.** La API de citas ya aplica las de agenda, cancelación e inasistencia, pero ninguna pantalla las muestra todavía.
- **Catálogo de módulos duplicado.** Las etiquetas están en la tabla `saas_modules` y en `src/lib/modules.ts`. Existe `GET /api/modules` para unificarlo.
- **Género del vocabulario.** Los textos asumen masculino ("Nuevo Paciente", "Todos los clientes"). Si una empresa define un término femenino, la frase no concuerda.
- **Características del plan (`features`).** Ya no deciden el menú. Quedan como datos informativos del plan; definir si se eliminan.

## Detectados el 2026-10-05 (rubro en el registro y catálogo inicial)

- **Resto de la plantilla del rubro.** El prototipo también trae salas y equipos, profesionales de ejemplo, paquetes y membresías por rubro. Solo se copian categorías, servicios y la plantilla de ficha.
- **Registro de empresa sin límite de intentos.** `/api/register-tenant` no tiene `throttle`, a diferencia del login y del registro de clientes.
- **Departamentos del formulario de registro.** La lista solo tiene Lima, Arequipa y Trujillo.

## Detectados el 2026-10-05 (servicios, dependientes y configuración de la empresa)

- **Datos nuevos del servicio sin uso.** La reserva en línea ya se aplica en el portal y el control de seguimiento crea su control al atender. Adelanto, videollamada y cupo se guardan y se editan, pero ningún flujo los aplica todavía.
- **Recurso que ocupa el servicio y color de categoría.** El prototipo los define; siguen sin columnas.
- **Reglas de operación sin pantalla.** `tenant_settings.rules` no se edita en `/settings`. Conviene hacerlo cuando alguna regla ya se aplique.
- **Resto de la configuración de la empresa.** Color, logo, permitir registro de clientes y regenerar el código de empresa no tienen pantalla.
- **Colegiatura en otros rubros de salud.** La regla `professionals.license` solo está en `medical` y `vet`. Dental, psicología y fisioterapia también tienen colegio profesional; decidir si se activa.
- **Colegiatura solo se oculta.** La API de profesionales sigue aceptando `license_number` en cualquier rubro.
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
- **Configuración de plataforma por definir.** Moneda e impuestos, pasarela de pago, días de gracia tras el vencimiento, avisos de vencimiento, política de contraseñas y segundo factor, aviso global o modo mantenimiento, plantillas de correo, retención de la bitácora, rubro y plan por defecto.
- **Cambiar de cuenta sin volver a ingresar.** Hoy cierra la sesión y pide la contraseña. Un cambio directo entre empresas exigiría una forma segura de probar que las cuentas son de la misma persona.
- **Preferencia de tema por cuenta.** El tema se guarda en el navegador, no en la cuenta: no viaja entre dispositivos.
- **Buscador de la barra superior.** Es solo visual.

## Detectados el 2026-10-06 (disponibilidad: horarios y ausencias)

- **Mensajes de validación en inglés.** Los que genera Laravel por defecto ("The reason field is required") no están traducidos; solo salen en español los escritos a mano.
- **Panel de horario sin revisar en tema oscuro.**

## Detectados el 2026-10-06 (API de citas y menú Agenda)

- **Cargos por cancelar tarde y por no asistir.** La API avisa de la cancelación tardía, pero no cobra nada. Depende de la caja.
- **Adelanto para reservar.** `requires_deposit` del servicio y las reglas de adelanto no se aplican. Depende de los pagos.
- **Salas y equipos en la cita.** Ya se registran por sucursal, pero la cita no valida que haya una sala libre ni asigna `room_id`.
- **Permisos por acción en la agenda.** El prototipo separa confirmar, registrar llegada, atender, cancelar y reprogramar. Aquí "Editar" de Agenda y citas los reúne a todos. Forzar un horario ya es un permiso aparte.
- **Avisos al cliente.** Confirmación de reserva, recordatorio y aviso de reprogramación no se envían: no hay envío de mensajes.
- **Citas recurrentes y clases grupales.** El prototipo las tiene; no se incluyeron.
- **Reglas de agenda sin editor.** `agenda.require_confirmation`, `agenda.client_overlap`, `agenda.allow_override`, `agenda.slot_interval`, `cancel.require_reason` y `noshow.grace_minutes` usan su valor por defecto; no están en el editor de reglas de la plataforma (`src/lib/rules.ts`) ni en `/settings`.
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
- **Bloqueos que se repiten.** Una reunión semanal se registra cada vez. Los feriados nacionales sí se cargan por año con un clic.
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
- **Reprogramar una cita no mueve la fecha del control.** El control sigue enlazado a la cita, que es lo que cuenta.
- **Controles repetidos.** Se puede registrar a mano el mismo control dos veces para un cliente.
- **Lista sin paginar.** Trae hasta 500 controles por pestaña. Los cerrados no tienen filtro de fechas.
- **Dar por realizado sin confirmar.** El check cierra el control de inmediato; se deshace desde "Cerrados".
- **Controles de un cliente eliminado.** Dejan de listarse, pero siguen en la base.
- **Pruebas en pantalla que faltan.** Rubro con mascotas, vista de un profesional, un control nacido de una ficha o de un servicio, tema oscuro y el aspecto general de la pantalla.

## Detectados el 2026-10-09 (lista de espera y datos clínicos por rubro)

- **Avisar a la lista cuando se libera un horario.** Al cancelar se informa al personal cuántas personas podrían tomarlo, pero no se les envía nada ni se les ofrece el horario. Depende del envío de mensajes (regla `agenda.waitlist_auto`).
- **Lista de espera en la página del cliente.** No se ve si la persona está esperando algo (la API ya filtra por cliente).
- **Buscar horario revisa tres semanas.** Para quien no puso última fecha no hay "seguir buscando más adelante".
- **Franja que cruza varios días.** Solo se puede pedir una franja horaria, igual para todos los días; no "lunes por la mañana y jueves por la tarde".
- **Reabrir un registro vencido.** Hay que anotarlo de nuevo; no se puede extender su fecha desde "Cerrados".
- **Tres buscadores de cliente.** `CustomerPicker` es nuevo; "Nueva cita" y "Nuevo control" siguen con su copia. Conviene que usen el mismo.
- **Sexo y contacto de emergencia en rubros no clínicos.** Se dejaron visibles. Las tarjetas de Clientes siguen filtrando por sexo en todos los rubros.
- **Empresas ya creadas y la regla de datos clínicos.** Reciben la regla al recargar el seed o cuando la plataforma aplique el rubro; una empresa creada antes y no actualizada deja de ver el tipo de sangre.
- **Pruebas en pantalla que faltan.** Rubro con mascotas, varias sedes, vista de un profesional, panel de horarios vacío, la regla nueva en el editor de rubros, tema oscuro y el aspecto general de la pantalla.

## Detectados el 2026-10-09 (feriados y citas de demostración)

- **Feriados que cambian de fecha.** Semana Santa y los feriados que el gobierno declara cada año no están en la lista nacional; cada empresa los agrega a mano.
- **Feriados de otros países.** La lista nacional es la del Perú, para todas las empresas.
- **Feriado en que se atiende medio día.** Solo hay dos casos: la sede cierra todo el día o se atiende normal. Para medio día hay que usar un cierre de sede por horas.
- **Aviso al crear un feriado con citas.** El panel no avisa antes de guardar; las citas se ven después, en la columna "Citas ese día".
- **Feriados y clientes.** El portal no dice que un día es feriado; solo no ofrece horarios.
- **Feriados anteriores.** No se pueden eliminar varios a la vez ni limpiar años pasados.
- **Citas de demostración sin ficha.** En los rubros que exigen ficha para atender, las citas atendidas del seed no la tienen (la regla solo se aplica al cambiar de fase).
- **Pruebas en pantalla que faltan.** Las otras cinco empresas del seed, varias sedes, el filtro "Año completo", la vista de un profesional, tema oscuro y el aspecto de la pestaña.

## Detectados el 2026-10-09 (sucursales)

- **Horario del profesional frente al de la sede.** Al definir el horario de una persona no se avisa si queda fuera del horario de su sede; se ve después, en la página de la sucursal.
- **Agenda: sede inicial.** Con varias sedes la agenda abre en la primera por orden alfabético, no en la principal ni en la última usada.
- **Agenda de todas las sedes a la vez.** Solo se ve una sede por vez.
- **Sede principal.** No hay forma de marcar cuál es la principal.
- **Servicios por sede.** Todos los servicios se ofrecen en todas las sedes; el prototipo permite limitarlos.
- **Zona horaria.** La lista ofrece siete zonas; las ausencias y los feriados usan la zona de la empresa, no la de cada sede.
- **Reportes por sede.** Tiempos de atención y la lista de citas no filtran por sede.
- **Eliminar una sucursal.** Solo se desactiva. Una creada por error queda en "Inactivas".
- **Sucursal en el portal.** El cliente ve el nombre de la sede al reservar, pero no su dirección ni su horario.
- **Pruebas en pantalla que faltan.** Cambiar de sede en la agenda, horario de un profesional con varias sedes, volver a activar una sede, vista de un profesional, empresa sin el módulo de salas, tema oscuro y el aspecto de la página de la sucursal.

## Detectados el 2026-10-09 (una empresa por rubro)

- **Clases grupales sin datos.** El Estudio Prana tiene tres clases grupales en su catálogo, pero ninguna sesión ni inscrito: esperan al módulo de Clases. Sus citas de demostración son solo clases privadas y evaluaciones.
- **Cola sin cita sin datos.** La Barbería Don Filo tiene el módulo, pero nadie en cola: espera a ese módulo.
- **Empresas nuevas sin controles ni lista de espera de ejemplo.** Solo Centro Médico los trae en el seed.
- **Especialidades: solo se crean.** Se agregan desde el formulario del profesional; no hay dónde renombrarlas ni eliminarlas.
- **Clientes de demostración sin sexo ni distrito.** Los cuatro primeros de cada empresa (los escritos a mano) no los tienen; los catorce agregados después sí.

## Detectados el 2026-10-09 (más servicios y equipo; actualizar ficha)

- **Ya no hay nadie en dos sedes en el seed.** La API y las pantallas lo siguen permitiendo, pero para probarlo hay que darle a alguien horario en otra sede desde Profesionales.
- **Servicios propios sin ficha ni control.** Los servicios agregados a cada empresa (los que no vienen del rubro) no están asignados a una plantilla de ficha ni piden control de seguimiento.
- **Laboratorio sin plantilla de ficha.** BioAnálisis está en el rubro genérico y su catálogo se escribe en el seed; no tiene ficha.
- **Actualizar una ficha firmada.** No se permite, por decisión: solo los borradores pasan a la plantilla actual. Dentro del plazo de corrección una ficha firmada se corrige con sus campos originales.
- **Aviso al editar una plantilla con borradores abiertos.** Quien edita la plantilla no ve cuántos borradores quedan con la versión anterior; cada autor lo ve al abrir el suyo.
- **Borradores cuyo servicio se quedó sin plantilla.** Siguen con sus campos y se pueden firmar; no hay aviso.
- **Versión de la plantilla en la ficha.** La ficha guarda los campos, no el número de versión con que se llenó.
- **Pruebas en pantalla que faltan.** Actualizar una ficha desde la agenda (se probó desde Fichas de atención), la vista de un profesional, una ficha privada y tema oscuro.

## Detectados el 2026-10-09 (inicio de la empresa y página Empresa)

- **Ingresos en el inicio.** "Valor de lo atendido" suma el precio de las citas atendidas; no es lo cobrado. Cuando exista la caja debe mostrar lo cobrado, el ticket promedio y los ingresos por día.
- **Abrir "Nueva cita" desde el inicio.** El botón lleva a la agenda; no abre el panel de la cita directamente. Las próximas citas también llevan a la agenda, no a su detalle.
- **Ocupación por profesional.** El inicio da la ocupación total de hoy; el prototipo la muestra además por persona.
- **Periodo del resumen.** Los números son de los últimos 30 días, sin poder cambiar el periodo ni filtrar por sede.
- **Inicio de la plataforma.** El administrador SaaS ve solo el saludo y sus accesos; no hay números de la plataforma (empresas activas, pruebas por vencer, ingresos).
- **Zona horaria de la empresa.** Se muestra, pero no se puede cambiar desde la página.
- **Logo, color y redes de la empresa.** La página Empresa no los tiene; `tenant_settings` ya guarda color y logo.
- **Componente anterior sin uso.** `features/companies/components/CompaniesTable.tsx` (la tabla de ejemplo) ya no se usa en ninguna página; se puede eliminar.
- **Rutas del inicio anterior.** Los botones viejos apuntaban a `/orders`, `/products/new`, `/tickets/new` y `/activities`, que no existen; ya no se usan.
- **Pruebas en pantalla que faltan.** El botón "Copiar" del código de empresa, el tema oscuro, la vista en celular y el inicio de una empresa sin citas.

## Detectados el 2026-10-09 (seed separado)

- **Contraseña de las cuentas de la plataforma.** `seed.sql` crea `admin@tusaas.com` y `soporte@tusaas.com` con la contraseña de prueba. Hay que cambiarla al desplegar; conviene que el despliegue la pida en vez de traerla escrita.
- **Catálogo del rubro y feriados, escritos dos veces.** La copia del catálogo y la lista de feriados nacionales están en la API (para las empresas reales) y otra vez en `seed_demo.sql` (para las de ejemplo). Si cambia el formato de la plantilla de un rubro hay que tocar los dos. Se evitaría creando las empresas de ejemplo desde la API en lugar de SQL.
- **Embudos en lo esencial.** Los tres embudos de `seed.sql` son de clínica y laboratorio; revisar si son los que debe traer el producto para todos los rubros.
- **Guía de despliegue.** No hay un documento con los pasos para el servidor (qué archivos cargar, qué cambiar, qué variables definir).
- **Prueba en pantalla con solo lo esencial.** Se probó por la API; falta recorrer el registro de una empresa y su primer día en el navegador.

## Detectados el 2026-10-09 (cuentas de profesionales y topes del plan)

- **Igualar el botón de Sucursales.** "Nueva sucursal" con el tope alcanzado muestra un aviso al pulsarlo; "Nuevo profesional" ya sale bloqueado con su motivo. Conviene que Sucursales use el mismo botón bloqueado de la tabla.
- **Botón bloqueado sin revisar a la vista.** Su aspecto y el del globo con el motivo se comprobaron por estilos, no en una captura. Falta también el tema oscuro y verlo habilitarse tras eliminar un profesional.
- **Motivo del bloqueo en pantallas táctiles.** El globo aparece al pasar el cursor o con el teclado; en un celular no hay cursor. La tarjeta de arriba sí dice que se alcanzó el tope.
- **Empresas que ya superan su plan.** Los topes impiden agregar, pero si a una empresa se le baja de plan no se le avisa ni se le pide reducir.
- **Correo del profesional único en toda la plataforma.** Al registrar un profesional el correo no puede existir en ninguna otra empresa, a diferencia del resto de cuentas (único por empresa).
- **Pruebas en pantalla que faltan.** Entrar como un profesional de cada rubro de ejemplo y el aviso del tope de profesionales (se probó en pantalla el de usuarios).

## Detectados el 2026-10-10 (menú, ficha editable y horarios del equipo)

- **Menú claro como alternativa.** Se mantuvo el menú oscuro con otra presentación. Si aún desentona, la otra opción es un menú claro; exige revisar el marco del panel y el tema oscuro.
- **Menú sin probar en todos sus estados.** Contraído (solo iconos), el de la plataforma y la animación de plegado.
- **Dos formas de editar una ficha.** La hoja edita nombre, campos y consentimiento; el panel lateral sigue editando lo mismo además de servicios y descripción. Conviene dejar en el panel solo servicios y descripción.
- **Texto de ejemplo y decimales en la hoja.** El texto de ejemplo de un campo y los decimales de un número solo se cambian desde el panel lateral.
- **Arrastrar para ordenar.** En la hoja los campos se ordenan con flechas; no se arrastran.
- **Crear una ficha desde la hoja.** "Nueva ficha" sigue abriendo el panel lateral.
- **Editor de la plataforma.** La plantilla de ficha de cada rubro (consola de la plataforma) sigue con su editor anterior; podría usar la misma hoja editable.
- **Horarios: semana tipo, no fechas.** La pantalla muestra el horario semanal; no marca las ausencias, vacaciones ni feriados de una semana concreta.
- **Horarios: editar en la misma grilla.** Los tramos se cambian en el panel de la persona; no se pueden estirar ni mover sobre la línea de tiempo.
- **Pruebas en pantalla que faltan.** Tema oscuro de las tres pantallas, Horarios con una sede sin horario definido y la hoja editable con odontograma.

## Detectados el 2026-10-10 (Select propio y "+" en formularios)

- **Listas de la plataforma sin probar en pantalla.** Planes, empresas y rubros ya usan el Select propio; falta abrir cada formulario, cambiar sus listas y guardar.
- **Registro público de empresa con lista genérica.** `RegisterModal` conserva sus dos `<select>`: tiene otro estilo de campos. Para cambiarlo hace falta una variante del Select con ese estilo y validar a mano que se eligió un rubro.
- **Listas obligatorias sin validación del navegador.** El Select propio no bloquea el envío si queda vacío; en el alta de empresa de la consola el rubro y el plan ya no los valida el navegador, los valida la API al guardar.
- **Select sin teclado.** El componente se abre y se elige con el ratón; no responde a flechas, Enter ni Escape, y no se puede buscar dentro de una lista larga.
- **"+" de profesional y de sede.** No se agregaron: crear un profesional consume plan y pide más datos, y una sede tiene su propio flujo.
- **"+" de categoría.** El formulario de servicio conserva su enlace "Nueva categoría"; no usa el "+" junto a la lista como los demás.
- **Mascota rápida con pocos datos.** El "+" pide nombre y especie; raza, fecha de nacimiento y sexo se completan en la ficha del cliente.
- **Servicio creado desde lista de espera o controles.** Queda elegido en ese formulario, pero la lista de la pantalla de atrás no se entera hasta recargar.
- **Pruebas en pantalla que faltan.** Guardar cliente y servicio tras cambiar sus listas, los "+" como profesional y el tema oscuro del Select en su versión pequeña.

## Detectados el 2026-10-10 (tablas que se actualizan al guardar)

- **Categoría nueva en el filtro de Servicios, sin probar en pantalla.** La tabla vuelve a pedir las categorías al cerrar el formulario de servicio; falta comprobarlo en el navegador.
- **Revisión de las demás pantallas hecha en el código.** Falta un recorrido en el navegador editando un registro en cada tabla.

## Detectados el 2026-10-10 (filtro por fecha, ordenar por y listas flotantes)

- **Pantallas sin probar en el navegador.** Feriados, Contactos y las tablas de la plataforma (empresas, planes, rubros) con sus filtros de fecha y su orden; el filtro de fecha de Fichas; y cada criterio de orden uno por uno (se probaron Clientes, Citas y Servicios).
- **Menú flotante en el resto de formularios.** Se probó en los de cliente y servicio y en las barras de las tablas; falta recorrer los demás paneles y el tema oscuro.
- **El filtro y el orden no se recuerdan.** Al salir de la pantalla o recargar, vuelven a quedar sin filtro y sin orden.
- **Ordenar por un solo criterio.** No se puede ordenar por dos a la vez (por ejemplo, profesional y luego fecha).
- **Ordenar desde el encabezado de la columna.** Solo se ordena desde la barra "Ordenar por".
- **Botones de la barra de filtros que aún no hacen nada.** "+" (agregar filtro) y "Filtros avanzados"; también "Exportar", la vista de panel, la paginación y las casillas de selección de la tabla.
- **Tabla sin mensaje cuando no hay filas.** Si los filtros no dejan nada, la tabla queda vacía sin decir por qué.
- **Clientes: lista completa hasta 500.** La tabla ya no se queda en los 10 primeros, pero sigue sin paginación real; con más de 500 clientes haría falta.
- **Fechas de registro en zona del navegador.** Los filtros de fecha de registro usan la zona horaria del navegador, no la de la empresa.

## Detectados el 2026-10-10 (página del profesional y nombres con enlace)

- **Página del profesional vista por otro profesional.** Cualquier persona del equipo puede abrirla; un profesional ve los datos de un colega pero no sus citas (la API solo le entrega las propias). Definir si debe poder abrirla.
- **Periodo fijo en la página del profesional.** Números y citas son de 30 días hacia atrás y 30 hacia adelante; no se puede cambiar el periodo.
- **Lo que la página del profesional aún no tiene.** Fichas que escribió, controles a su cargo, ingresos y comisiones (cuando exista la caja), color y foto.
- **Asignar servicios desde la página.** Muestra los servicios asignados, pero sigue sin haber dónde asignarlos.
- **Nombres sin enlace.** Las tarjetas de cita de la agenda y las próximas citas del inicio (toda la tarjeta ya es un botón o un enlace) y los textos de confirmación.
- **Pruebas en pantalla que faltan.** Fichas de atención con enlaces (la base de ejemplo no trae fichas), página de la sucursal, vista de un profesional, profesional con dos sedes o con ausencias, tema oscuro y celular.

## Detectados el 2026-10-10 (paginación de a seis y tarjetas que filtran)

- **Elegir cuántas filas ver.** Todas las tablas y listas muestran 6; no hay selector de 6, 12 o 24.
- **La página no se recuerda.** Al abrir un registro y volver, la tabla regresa a la página 1.
- **Paginación hecha en el navegador.** La tabla recibe todas las filas y muestra 6. Con listas muy grandes (más de 500) haría falta paginar en la API.
- **Refresco automático y página.** Citas se actualiza sola cada 30 segundos; la página se conserva, pero si llegan filas nuevas las de la página visible pueden desplazarse.
- **Tarjeta "Tiempo promedio" sin filtro.** Podría dejar solo las atenciones medidas.
- **Pruebas en pantalla que faltan.** La paginación en cada tabla (se probó Clientes), la tarjeta "Fichas" y la historia paginada con fichas reales, tema oscuro y celular.

## Detectados el 2026-10-10 (notificaciones, primera entrega)

- **Segunda entrega de avisos (acordada).** Citas dentro de un bloqueo o feriado recién creado; lista de espera que puede tomar un horario liberado; controles vencidos o por vencer; apertura de emergencia de una ficha privada; ficha en borrador de una cita ya atendida; tope del plan cerca; prueba o suscripción por vencer.
- **Avisos que esperan a su módulo.** Cierre de caja con diferencia, stock mínimo, presupuesto aceptado, turno sin cita.
- **Solo dentro del sistema.** No se envía correo, WhatsApp ni aviso del navegador; quien no tiene el panel abierto no se entera.
- **Elegir qué avisos recibir.** Nadie puede apagar un tipo de aviso ni cambiar los 3 días de anticipación de las ausencias.
- **Ausencias próximas dependen de que alguien abra el panel.** Se generan al consultar la campana. Con envío de mensajes haría falta una tarea diaria (y hoy nada ejecuta las tareas programadas).
- **Avisos antiguos.** No se borran nunca; la página muestra los últimos 300.
- **Recepción y otros roles.** Hoy solo existen dueño, administradores y profesionales; cuando haya recepción habrá que decidir qué recibe.
- **Avisos del portal sin probar desde su pantalla.** La regla está probada; falta reservar, cambiar y cancelar desde el portal y ver llegar el aviso.
- **La agenda no se entera de lo hecho desde la campana.** Si se cambia una cita abierta desde un aviso, la agenda de atrás se actualiza en su siguiente refresco (20 segundos).
- **Pruebas en pantalla que faltan.** Actualización sola a los 30 segundos, administrador que no es dueño, tema oscuro y celular.

## Detectados el 2026-10-10 (portal: filtros, Mis datos y encabezado)

- **CRM opcional (en consulta).** Hoy `crm` es módulo base y protege también el registro de clientes, del que depende la agenda. Para que un plan pueda no incluir CRM hay que separar "Clientes" (básico) de "CRM" (embudos, oportunidades, contactos). Geraldine lo consultará con su jefe; no cambiar hasta entonces.
- **Servicios por sede en el portal.** El portal pide el servicio antes que la sede porque todos los servicios se ofrecen en todas. Si se limitan por sede, el paso de sede debe ir antes o filtrar las sedes según el servicio.
- **Sedes sin quien atienda un servicio.** El portal deja elegir una sede aunque ahí nadie atienda ese servicio; se nota recién al no encontrar horarios.
- **Mis datos: solo se cambian celular y contraseña.** Nombre, correo, documento, dirección y mascotas ya registradas los cambia la empresa.
- **El portal trae las últimas 100 citas.** Los filtros trabajan sobre esas.
- **Pruebas en pantalla que faltan.** Portal en celular, rubro con mascotas, empresa de una sede, filtros por servicio y profesional.

## Detectados el 2026-10-11 (portal: filtros desplegables; plazo para confirmar)

- **Alineación de las fichas en el panel, sin medir.** El arreglo es compartido; falta mirarlo en las barras de filtros y de orden de las tablas.

## Detectados el 2026-10-11 (plazo para confirmar y reglas de reserva)

- **El cliente no recibe ningún mensaje.** Ni recordatorio para confirmar ni aviso de que su cita se liberó: lo ve solo si entra al portal. Depende del envío de mensajes; con "liberar" activo conviene tenerlo antes de usarlo con clientes reales.
- **La revisión depende de que alguien tenga el sistema abierto.** Sin nadie con el panel o el portal abierto, una cita vencida se avisa o se libera recién cuando alguien entra. Con envío de mensajes haría falta una tarea programada.
- **Marca en la agenda.** "Sin confirmar a tiempo" se ve en la tabla de Citas y en el panel de la cita; las tarjetas de la agenda no la muestran.
- **Filtro de citas sin confirmar.** El aviso agrupado lleva a Citas, pero no hay un filtro o tarjeta que deje solo las vencidas.
- **Resto de reglas en manos de la empresa.** "Reglas de reserva" cubre el plazo para confirmar y la ventana para cancelar. Siguen solo en el rubro: reserva en línea, anticipación mínima y máxima, elegir profesional, confirmación automática, veces que se puede reprogramar, tolerancia de inasistencia.
- **Aviso al cambiar las reglas.** Si la empresa acorta el plazo o pasa a "liberar", las citas ya agendadas quedan sujetas a la regla nueva sin aviso previo.
- **Opción "qué pasa si no se confirma" por rubro.** El editor de reglas del rubro solo fija las horas del plazo; la opción (avisar o liberar) la elige cada empresa.
- **Pruebas en pantalla que faltan.** Ver liberarse una cita y su aviso, el editor de reglas del rubro, tema oscuro y celular.

## Detectados el 2026-10-11 (lista de espera desde el portal)

- **El aviso al cliente es solo dentro del portal.** Quien espera un horario se entera al entrar a su portal; no recibe correo ni WhatsApp. Es lo primero que conviene conectar cuando exista el envío de mensajes.
- **Nadie tiene preferencia sobre el horario liberado.** Lo toma el primero que lo reserva, sea de la lista de espera o no. No se le reserva unos minutos a quien esperaba, ni se respeta el orden o la prioridad de la lista.
- **Reprogramar no avisa.** Si una cita se mueve a otra hora, el horario que deja libre no genera el aviso "Se liberó un horario"; solo cancelar, no asistir o liberarse por falta de confirmación.
- **Aviso también a quien cancela.** El administrador que cancela la cita recibe igual el aviso de la campana (además del mensaje que ya ve al cancelar).
- **Franjas simples en el portal.** El cliente elige mañana, tarde o cualquier hora; no un rango de horas ni días concretos de la semana. El personal sí puede afinarlo desde su Lista de espera.
- **Anotarse cuando sí hay horarios.** El recuadro aparece siempre en el paso de día y hora, también si hay horas libres; en ese caso el registro nace ya con horarios para reservar.
- **Pruebas en pantalla que faltan.** "Salir de la lista" con su confirmación, el aviso en la campana del personal, rubro con mascotas y celular.

## Detectados el 2026-10-12 (Equipo y catálogo; Salas y equipos)

- **Las salas y equipos aún no participan en las citas.** Se registran y se listan, pero una cita no reserva una sala ni un equipo, y un servicio no puede exigir uno. En el prototipo la cita solo se agenda si hay uno libre del tipo requerido. Es el siguiente paso natural de este módulo.
- **Lo que el prototipo trae y aquí falta.** Capacidad simultánea (sala grupal), servicios que usan cada recurso, uso del día en minutos y poner un recurso "en mantenimiento" por un periodo (hoy solo se apaga a mano).
- **Tipos de recurso.** Solo hay Sala y Equipo; el prototipo distingue más (cabina, sillón, consultorio…).
- **"Profesionales y horarios" en una sola pantalla.** El prototipo los une; aquí siguen siendo dos ítems (Médicos y Horarios del equipo).
- **Sede bloqueada al editar.** Una sala no se puede pasar a otra sede; hay que registrarla de nuevo.
- **Pruebas en pantalla que faltan.** Menú como profesional, empresa sin el módulo de salas, eliminar algo con citas, filtro de sede, tema oscuro y celular.

## Detectados el 2026-10-12 (equipos instalados en una sala)

- **Un equipo, una sala.** Un equipo compartido entre varias salas se deja sin sala fija, y entonces no afecta a ninguna.
- **Motivo y fecha de vuelta.** Al apagar un equipo no se anota por qué ni hasta cuándo (mantenimiento, avería).
- **Salas y equipos sigue sin intervenir en las citas** (decisión del 2026-10-12: queda como inventario por ahora). La propuesta de que el servicio diga qué necesita quedó explicada y sin hacer.
- **Aviso de salas solo al cambiar el equipo.** Si la empresa cambia la opción en Configuración con equipos ya fuera de uso, las salas cambian en pantalla pero no llega ningún aviso.
- **Eliminar un equipo instalado en una sala.** Si se elimina (no solo se apaga), la sala vuelve a estar en uso sin aviso.

## Detectados el 2026-10-12 (campos de fecha y hora propios)

- **Sin teclado.** La fecha y la hora se eligen con el ratón; no se pueden escribir ni recorrer con flechas (igual que el Select propio).
- **Minutos de 5 en 5.** El campo de hora no deja escribir un minuto intermedio (por ejemplo 10:12).
- **Calendario escrito dos veces.** El filtro de fecha de las tablas (`DateFilter`) y el campo de los formularios (`DateField`) dibujan el mes con código separado; conviene que compartan la grilla.
- **Registro público de empresa.** `RegisterModal` conserva sus dos listas genéricas del navegador (no tiene fechas).
- **Pruebas en pantalla que faltan.** Lista de espera, nuevo control, fichas de atención, dependientes y mascotas, horario de la sede, vencimiento en la consola, días futuros bloqueados en la fecha de nacimiento, tema oscuro y celular.

## Detectados el 2026-10-12 (ventas, entrega 1: caja y cobrar la cita)

- **Entregas que siguen (acordadas).** 3: punto de venta con carrito (vender sin cita, varios ítems, descuentos con tope, propina). 4: pasarela de pago simulada (link de pago, página de pago de prueba, aviso de confirmación, vencimiento del link, adelanto al reservar en el portal) y pantalla Integraciones con las pasarelas conocidas (Culqi, Niubiz, Izipay, Mercado Pago) y el proveedor de facturación. 5: Paquetes. 6: Marketing.
- **Enviar el comprobante al cliente.** Se imprime desde la venta (y se guarda como PDF desde el diálogo del navegador), pero no se envía por correo ni se ve en el portal.
- **Comprobante real.** El envío a SUNAT es una simulación. Falta el proveedor real, la pantalla para elegirlo y guardar sus llaves, reintentar un comprobante pendiente o rechazado, y la baja.
- **Datos del emisor.** El comprobante toma razón social, RUC y dirección de la página Empresa; si el RUC está vacío dice "RUC sin registrar" y se emite igual.
- **Series por sede.** Hay una serie por tipo para toda la empresa (B001, F001, T001); SUNAT suele pedir una por punto de emisión. No hay pantalla para cambiarlas.
- **Boletas de monto alto sin documento.** No se exige el DNI del cliente a partir de cierto monto (S/ 700 en el prototipo).
- **Descuento global, propina y canje de puntos.** El prototipo los trae en el carrito; por decisión del 2026-10-13 no se agregan por ahora. El cupón ya se aplica al cobrar.
- **Cita cobrada, en la agenda y en la tabla de Citas.** El estado del cobro solo se ve al abrir la cita; no hay marca ni filtro "por cobrar".
- **El inicio sigue mostrando "valor de lo atendido".** Debe pasar a lo realmente cobrado.
- **Cancelar una cita ya cobrada.** Se puede cancelar o marcar "no asistió" sin que la venta cambie ni se avise: la devolución hay que hacerla aparte, desde Ventas.
- **Cambiar el precio de una cita con venta abierta.** La venta conserva el monto con que nació.
- **Quién ve cada turno de caja.** Quien opera la caja ve todas las cajas y turnos de su sede (o de la empresa, según su rol). Falta decidir si cada quien debe ver solo su propio turno.
- **Aprobar un cierre con diferencia.** El administrador recibe el aviso, pero no hay un paso de "aprobar"; tampoco se puede reabrir un turno cerrado.
- **Arqueo por billetes y monedas.** Se escribe el total contado; el prototipo cuenta por denominación.
- **Medios que no son efectivo.** Se muestran por turno para cuadrarlos, pero no se concilian contra el banco o el POS.
- **Ventas, pagos y turnos de ejemplo.** `seed_demo.sql` no trae ninguno: Caja nace vacía en las empresas de ejemplo.
- **Reglas de ventas en el editor de rubros.** Las nuevas (`pos.prices_include_tax`, `pos.tax_rate`, `pos.require_open_cash`, `pos.partial_payments`, `cash.tolerance`) las cambia cada empresa, pero no están en el editor de reglas del rubro de la plataforma.
- **Moneda.** Todo está en soles, con "S/" fijo.
- **Pruebas en pantalla que faltan.** Aviso de cierre con diferencia en la campana, editar o poner fuera de uso una caja, comprobante interno de una empresa sin facturación, "Emitir comprobante" de una venta pagada sin él, filtro de fecha y orden de los turnos, cobrar desde la agenda, vista de un profesional, tema oscuro y celular.

## Detectados el 2026-10-13 (cupones y caja por sucursal)

- **Cupón por cliente.** Los usos se cuentan en total; no hay "una vez por cliente" ni cupones solo para clientes nuevos o para un servicio o una sede.
- **Cupón que deja el total en cero.** No se acepta: una venta gratuita necesita otro tratamiento en el comprobante.
- **El cupón se fija con el primer pago.** Después no se puede agregar ni cambiar. Al anular la venta el cupón recupera su uso; al devolverla, no.
- **Cupones dentro de Marketing.** La pantalla está en el grupo Ventas, bajo el módulo de punto de venta. Al hacer Marketing habrá que enlazarla desde Campañas y decidir si se mueve.
- **Reparto del cupón.** No hay forma de enviar un cupón al cliente ni de ver quién lo usó (los usos quedan guardados, sin pantalla).
- **Yape con QR o código de aprobación.** Hoy es un cobro manual con número de operación. Con la pasarela (entrega 4): QR por cobro con el monto, o celular y código de aprobación, con confirmación automática.
- **QR fijo del negocio.** No hay dónde subir la imagen del QR de Yape o Plin de la empresa para mostrarla al cobrar.
- **Sucursal creada con la caja automática apagada.** No se avisa al crear la sucursal que quedó sin caja; se nota al intentar cobrar en efectivo.
- **Pruebas en pantalla que faltan.** Crear una sucursal y ver su caja, editar un cupón, un cupón de monto fijo desde el formulario, filtro de fecha y orden de Cupones, tema oscuro y celular.

## Detectados el 2026-10-13 (ventas, entrega 2: ventas, pagos, anular y devolver)

- **Devolución que alguien solicita y otro aprueba.** El prototipo lo tiene; aquí la hace directamente el administrador. Volver a verlo cuando existan recepción o caja como roles.
- **Devolver por el mismo medio del pago.** Se elige cualquier medio; no se sugiere el del pago original ni se impide devolver en efectivo algo cobrado con tarjeta.
- **Devolución de un pago en línea.** Cuando haya pasarela (entrega 4), devolver un pago hecho por link o QR debe pedirse a la pasarela.
- **Cita con venta devuelta por completo.** No se puede volver a cobrar: su tarjeta queda en "Devuelta". Si el servicio se va a prestar otro día, hay que agendar otra cita.
- **Imprimir la nota de crédito.** Solo se imprime el comprobante de la venta; la nota de crédito se ve en la lista de devoluciones.
- **Anular una boleta o factura sin devolver.** No existe la "comunicación de baja" (anular el comprobante dentro del plazo de SUNAT); toda corrección pasa por nota de crédito.
- **Exportar ventas y pagos.** No hay descarga a Excel.
- **Ventas y pagos traen los últimos 500.** Los filtros trabajan sobre esos; con más volumen haría falta paginar en la API.
- **Ventas y pagos en la página del cliente y en el portal.** No se muestran.
- **Reporte del día.** No hay un cierre diario que junte ventas, medios de pago y devoluciones de todas las cajas.
- **Detalle de la venta en un panel.** Si la venta crece (varios ítems, comisiones, puntos), convendría pasarla a su propia página.
- **Pruebas en pantalla que faltan.** El botón "Imprimir" y la pestaña nueva del comprobante, devolver con la caja cerrada o con varias cajas, una factura con su nota FC01, filtros y orden de Ventas y Pagos, una venta con cupón en su panel, tema oscuro y celular.

## Detectados el 2026-10-13 (cobrar antes de atender; inasistencias en línea)

- **Rubros que cobran al final.** "Cobrar antes de atender" nace encendido para todos. Un salón o una barbería suele cobrar al terminar: hoy lo apaga cada empresa; se podría dejar apagado desde el rubro.
- **Atender sin pago por excepción.** Con la regla encendida no hay forma de saltarla para un caso puntual (una urgencia, un convenio); ni siquiera el administrador.
- **Pago de una teleconsulta.** Se le dice al cliente que coordine el pago con la sede; no puede pagar en línea hasta que exista la pasarela (entrega 4).
- **Pagar en línea al reservar.** Con la pasarela, quien reserva desde el portal debería poder pagar ahí mismo y no tener que llegar antes.
- **La falta solo es "No asistió".** No cuenta llegar tarde, cancelar a última hora ni llegar sin tiempo para pagar. Tampoco las citas que la sede liberó por no confirmarse.
- **Faltas a citas agendadas por el personal.** No generan nota ni veto: la regla es solo para la reserva en línea. Evaluar una nota general de inasistencias.
- **Aviso al cliente de su falta.** Se entera al entrar al portal; no recibe un mensaje (no hay envío de mensajes).
- **Aviso a la administración.** Cuando un cliente llega al límite y queda sin reserva en línea, nadie recibe un aviso en la campana.
- **Quién perdonó y cuándo.** Perdonar no deja registro en la bitácora, y no se puede deshacer.
- **Veto que también impida anotarse en la lista de espera.** Hoy solo impide reservar.
- **Atendidas sin cobrar anteriores.** Al encender la regla, las citas atendidas que nadie cobró dejan de listarse (la pestaña desaparece), aunque sigan sin cobrar.
- **Marca de "pagada" en la agenda.** Con la regla encendida ayudaría ver en la agenda qué citas de hoy ya pagaron.
- **Pruebas en pantalla que faltan.** El botón "Perdonar", el aviso de pago de una cita presencial en la tarjeta del portal, "Dar por atendida" sin pagar, la opción "No pasa nada", tema oscuro y celular.

## Detectados el 2026-10-13 (usuarios y roles, entrega 1)

- **Listas que todo el personal puede leer.** Servicios, profesionales, horarios, sedes, salas, feriados, bloqueos y plantillas de ficha: apagar "Ver" quita la pantalla del menú, pero la API sigue entregando la lista porque otros formularios la necesitan para elegir.
- **El profesional ya no elimina clientes ni cambia servicios.** Antes la API se lo permitía a cualquier cuenta. Si una empresa lo necesita, se le enciende al rol o a la persona.
- **Un profesional con "Ver ventas" encendido ve todas las ventas**, no solo las de sus citas.
- **Quien atiende citas no cambia de rol.** Un profesional que además administra necesita que se le enciendan los permisos uno por uno; no puede ser Administrador.
- **La contraseña temporal no obliga a cambiarla.** Quien recibe su cuenta entra con ella y la conserva; el personal no tiene dónde cambiar su propia contraseña. Tampoco se envía por correo (no hay envío de mensajes).
- **Bitácora de permisos.** Queda guardado quién le dio o quitó un permiso a una persona, pero no hay pantalla que lo muestre; los cambios a un rol no guardan quién los hizo.
- **Avisos de la campana para los roles nuevos.** Recepción, caja y gerencia no reciben ningún aviso todavía: la campana sigue repartiendo solo a profesionales y administradores.
- **Roles por rubro.** Todos los rubros nacen con los mismos cinco roles y los mismos permisos; el editor de rubros de la plataforma no los cambia.
- **Catálogo de permisos en la API.** Los permisos y los roles de fábrica están en `App\Support\Access`; agregar un módulo nuevo (inventario, paquetes) exige agregarlo ahí y poner `perm:` en sus rutas.
- **Dos cuentas de la misma persona.** El correo no se repite dentro de la empresa, pero el de un profesional no puede existir en ninguna otra empresa y el de un usuario sí.
- **Sesión de soporte de la plataforma.** Quien entra al panel de una empresa actúa como su dueño: puede crear usuarios y cambiar permisos.
- **Pruebas en pantalla que faltan.** Entrar como gerente de sede y como caja, reactivar una cuenta, el botón bloqueado por el tope del plan, filtros de fecha y orden de las dos tablas, la página de un rol y la rejilla en una pantalla grande, tema oscuro y celular.

## Detectados el 2026-10-13 (usuarios y roles, entrega 2)

- **Anular y devolver, en un solo permiso.** "Eliminar" de Ventas y pagos cubre las dos cosas; no se puede dar una sin la otra.
- **Roles que ya existían no reciben los permisos nuevos.** Al agregar un permiso al catálogo (como los tres especiales), los roles de fábrica ya creados en una empresa no lo reciben solos: hay que encenderlo o usar "Volver a como nació".
- **Botones que aún no siguen al permiso.** Dependientes y mascotas dentro del formulario del cliente, las categorías de servicio, el acceso al portal del cliente, y el nombre de un cupón (abre su panel aunque no se pueda guardar).
- **Mapa de pantallas escrito dos veces.** Qué permiso pide cada pantalla está en el menú (`Sidebar`) y en el aviso de acceso (`ScreenGate`); al agregar una pantalla hay que tocar los dos.
- **La página de un profesional la abre todo el personal.** Desde su nombre, aunque la persona no tenga permiso sobre Profesionales; ve sus datos y su horario.
- **Ficha en solo lectura sin aviso.** Quien puede ver fichas pero no llenarlas la ve sin campos editables, sin un texto que le diga por qué.
- **Pruebas en pantalla que faltan.** Entrar como recepción, gerente y profesional; Lista de espera, Controles, Bloqueos, Servicios y Profesionales sin permiso; la ficha en solo lectura; Configuración con "ver" y sin "cambiar"; los botones de perdonar faltas y de acceso de emergencia; forzar un horario como gerente; tema oscuro y celular.

## Detectados el 2026-10-13 (usuarios y roles, entrega 3: por sede)

- **Una persona en dos sedes.** Cada cuenta tiene una sola sede. Un gerente que lleva Miraflores y Surco necesita hoy el rol con "toda la empresa".
- **Avisos de la campana por sede.** Gerencia, recepción y caja no reciben avisos; los de la administración llegan de todas las sedes.
- **Servicios, precios, cupones y plantillas los cambia el gerente** (decisión del 2026-10-13: "por ahora sí"). Un cambio suyo rige en todas las sedes. Revisar cuando haya varias sedes con gerentes distintos.
- **Controles y seguimiento son de toda la empresa.** Quien ve solo su sede ve los controles de todos los clientes, también los atendidos en otra sede.
- **Página del cliente.** Sus citas se ven solo de la propia sede; sus datos y su historia de fichas, según el permiso de fichas (las de otra sede no se abren).
- **Un profesional que atiende en dos sedes** aparece para los dos gerentes, y cualquiera de ellos puede cambiar sus datos; su horario, solo el de su sede.
- **Profesionales sin horario los ven todos los gerentes**, para poder darles horario en su sede.
- **Reportes comparando sedes.** No existen; el inicio y los tiempos de atención muestran una sede o toda la empresa.
- **Mensajes de otra sede en pantalla.** Si alguien llega a algo de otra sede (por un enlace de un aviso, por ejemplo), la pantalla muestra el mensaje de la API como error, no una página propia.
- **Pruebas en pantalla que faltan.** Entrar como Rosa y Karina; Ventas, Fichas, Inicio, Lista de espera y Bloqueos como gerente; cambiar la sede de alguien; un rol propio copiado; "Volver a como nació"; tema oscuro y celular.

## Detectados el 2026-10-13 (sede obligatoria)

- **Desactivar una sede con turnos de caja abiertos.** Se impide si tiene personal o citas próximas, pero no si queda una caja abierta en ella.
- **Quien ya estaba sin sede.** Se marca con "Falta su sede" en Usuarios y roles; nadie recibe un aviso en la campana.
- **Mover a varias personas de sede a la vez.** Se hace una por una, desde el lápiz de cada una.
