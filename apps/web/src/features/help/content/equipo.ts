import { lower, type HelpTopics } from '../types';

export const equipoTopics: HelpTopics = (vocab) => {
  const c = lower(vocab.client);
  const cs = lower(vocab.clients);
  const p = lower(vocab.pro);
  const ps = lower(vocab.pros);
  const aPro = `${vocab.pick('pro', 'un', 'una')} ${p}`;

  return {
    '/services': {
      summary: 'Es el catálogo de lo que ofreces, con su duración, su precio y si está activo. La agenda, la reserva en línea, los adelantos y los controles leen sus datos de aquí.',
      points: [
        { title: 'Pestañas y filtros', text: 'Todos, activos e inactivos. Filtra por categoría o estado.' },
        { title: 'Nuevo servicio', text: 'Con nombre, categoría, duración, tiempos de preparación antes y después, precio y estado.', perm: 'services.create' },
        { title: 'Editar o eliminar', text: 'Cambia un servicio o lo quita. Un servicio inactivo deja de ofrecerse sin perder su historia.', perm: 'services.edit|services.delete' },
        { title: 'Reserva en línea y adelanto', text: 'En "Reserva y seguimiento" eliges si se reserva desde el portal y si pide un adelanto, en porcentaje o en monto fijo.' },
        { title: 'Videollamada', text: 'Un servicio por videollamada no ocupa sala ni equipo.' },
        { title: 'Sedes donde se da', text: 'En "Reserva y seguimiento", "Todas las sedes" o "Solo en algunas". En las demás no se puede agendar ni vender en el punto de venta, ni aparece en el portal, el kiosko o la cola sin cita. En la lista dice "solo en 2 sedes".' },
        { title: 'Control de seguimiento', text: `Si el servicio necesita que ${vocab.pick('client', 'el', 'la')} ${c} vuelva (por ejemplo, en 30 días), el control se crea solo al dar por atendida la cita.` },
        { title: 'Insumos que consume', text: 'Los productos que se gastan en cada atención, para descontarlos del stock.', module: 'inventory' },
        { title: 'Exportar', text: 'Descarga a Excel los servicios que estás viendo.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Crear un servicio', perm: 'services.create', steps: ['Pulsa "Nuevo Servicio".', 'Escribe el nombre y elige o crea su categoría.', 'Indica cuántos minutos dura y su precio.', 'Pulsa "Crear servicio".'] },
        { title: 'Pedir un adelanto al reservar', perm: 'services.edit', steps: ['Edita el servicio y abre "Reserva y Seguimiento".', 'Marca "Se puede reservar en línea" y "Exige adelanto".', 'Elige "Un porcentaje" o "Un monto fijo" y escribe el valor.', 'Pulsa "Actualizar servicio".'] },
        { title: 'Programar un control', perm: 'services.edit', steps: ['Edita el servicio y abre "Reserva y Seguimiento".', 'Escribe el nombre del control y en cuántos días.', 'Guarda. Desde ahora, cada atención de ese servicio crea su control.'] },
      ],
      rules: [
        { title: 'Plazos del adelanto', text: 'Cuántos minutos hay para pagar el adelanto y hasta cuándo se devuelve si cancela.', where: 'Configuración → Adelantos y links de pago' },
        { title: 'Descontar insumos al atender', text: 'Si está encendido, los insumos salen del stock al dar por atendida la cita.', module: 'inventory', where: 'Configuración → Inventario y almacenes' },
      ],
    },

    '/professionals': {
      summary: `Es tu equipo que atiende: cada ${p} tiene su cuenta para entrar, sus especialidades, sus servicios, sus sedes y su horario. Su página muestra su agenda y sus números.`,
      points: [
        { title: 'Uso del plan', text: `La tarjeta dice cuántos ${ps} o cuentas permite tu plan y cuántos tienes. Al llegar al tope, el botón de nuevo se bloquea y dice por qué.` },
        { title: `Nuevo ${p}`, text: 'Con documento, nombres, correo de acceso, especialidades, firma y estado. Su documento es su primera contraseña.', perm: 'professionals.create' },
        { title: 'Firma', text: 'Se sube como imagen y sale en las fichas firmadas.' },
        { title: 'Horario y ausencias', text: 'El ícono de calendario abre su horario semanal por sede y sus vacaciones o permisos.', perm: 'schedules.edit' },
        { title: 'Documento y celular', text: 'La lista muestra el documento y el celular de cada uno. La colegiatura, si tu empresa la pide, está en su formulario y su página.' },
        { title: 'Su página', text: `El ojo abre sus citas de hoy y próximas, sus atenciones, su horario y sus ausencias. Las tarjetas filtran sus citas. Con un rol de "solo lo suyo", cada ${p} abre solo la suya.` },
        { title: 'Editar o eliminar', text: `Cambia los datos ${vocab.pick('pro', 'del', 'de la')} ${p} o lo quita del equipo.`, perm: 'professionals.edit|professionals.delete' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: `Registrar ${aPro}`, perm: 'professionals.create', steps: [`Pulsa "${vocab.pick('pro', 'Nuevo', 'Nueva')} ${vocab.pro}".`, 'Llena sus datos y su correo de acceso.', 'Elige sus especialidades y, si quieres, sube su firma.', 'Guarda. Entra con su correo y su número de documento.'] },
        { title: 'Darle su horario', perm: 'schedules.edit', steps: ['Pulsa el calendario "Horario y ausencias" en su fila.', 'Elige la sede.', 'En cada día, pulsa "Agregar tramo" y pon las horas.', 'Pulsa "Guardar horario". Sin horario, la agenda no le ofrece citas.'] },
        { title: 'Registrar sus vacaciones', perm: 'schedules.edit', steps: ['Abre "Horario y ausencias".', 'En ausencias elige "Vacaciones", las fechas y el motivo.', 'Pulsa "Agregar ausencia".'] },
      ],
    },

    '/schedules': {
      summary: `Muestra, por sede, quién atiende cada día de la semana y en qué horas. ${vocab.pick('pro', 'Un', 'Una')} ${p} atiende en una sede cuando tiene horario ahí.`,
      points: [
        { title: 'Sedes', text: 'Si tienes varias, cambia de sede con las pestañas.' },
        { title: 'Tarjetas', text: `${vocab.pros} en la sede, horas de atención por semana y quiénes atienden hoy.` },
        { title: 'Sin horario', text: 'Un aviso lista a quienes no tienen horario en ninguna sede: la agenda no les ofrece citas.' },
        { title: 'Cambiar un horario', text: 'El lápiz junto a cada persona abre su horario.', perm: 'schedules.edit' },
        { title: 'Horario de la sede', text: 'El fondo de cada día muestra cuándo abre la sede. Lo que quede fuera de ese horario no se ofrece.' },
      ],
      tasks: [
        { title: 'Corregir las horas de alguien', perm: 'schedules.edit', steps: ['Pulsa el lápiz junto a su nombre.', 'Cambia los tramos del día.', 'Pulsa "Guardar horario".'] },
      ],
      rules: [
        { title: 'Ausencias y vacaciones', text: 'No se ven aquí: están en Bloqueos y ausencias.' },
      ],
    },

    '/rooms': {
      summary: 'Son las salas (consultorio, cabina, sillón) y los equipos de cada sede, y si están en uso o fuera de uso.',
      points: [
        { title: 'Pestañas y tarjetas', text: 'Todos, salas y equipos. Las tarjetas cuentan lo que está en uso y lo que está fuera de uso.' },
        { title: 'Nueva sala o equipo', text: 'Con su tipo, su sede, su nombre y, para un equipo, la sala donde está. Hace falta al menos una sucursal activa.', perm: 'rooms.edit' },
        { title: 'Fuera de uso', text: 'Apaga "En uso" cuando está en mantenimiento o ya no se usa. Si una sala quedó fuera de uso por su equipo, lo dice.', perm: 'rooms.edit' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Registrar una sala', perm: 'rooms.edit', steps: ['Pulsa "Nueva sala o equipo".', 'Elige "Sala", la sede y escribe el nombre.', 'Pulsa "Registrar sala".'] },
        { title: 'Poner un equipo fuera de uso', perm: 'rooms.edit', steps: ['Abre el equipo.', 'Apaga "En uso" y pulsa "Guardar cambios".'] },
      ],
      rules: [
        { title: 'Si un equipo deja de funcionar', text: 'Puede no pasar nada, mostrarse como observación en su sala o dejar la sala fuera de uso.', where: 'Configuración → Salas y equipos' },
      ],
    },

    '/branches': {
      summary: `Son los locales de tu empresa: su dirección, teléfono, zona horaria y horario de atención. Cada sede muestra quién atiende ahí y sus salas.`,
      points: [
        { title: 'Uso del plan', text: 'La tarjeta dice cuántas sucursales activas permite tu plan. Para abrir más hace falta un plan con Multisede o desactivar una.' },
        { title: 'Tarjetas', text: 'Abren hoy, sin horario definido, sin nadie que atienda y citas de hoy.' },
        { title: 'Nueva sucursal', text: 'Con nombre, dirección, teléfono y zona horaria. Empieza con el horario de la primera sede.', perm: 'branches.edit' },
        { title: 'Horario de atención', text: 'El reloj abre los días y horas en que abre. Si ningún día está marcado, la sede no limita la agenda.', perm: 'branches.edit' },
        { title: 'Desactivar', text: 'Cierra una sede sin perder su historia. No se puede si alguien del equipo depende de ella.', perm: 'branches.edit' },
        { title: 'Su página', text: `El ojo abre sus citas de hoy, su horario, sus ${ps} y sus salas.` },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Abrir una sede nueva', perm: 'branches.edit', steps: ['Pulsa "Nueva sucursal".', 'Llena sus datos y pulsa "Crear sucursal".', 'Revisa su horario con el reloj y guárdalo.'] },
        { title: 'Cambiar el horario de una sede', perm: 'branches.edit', steps: ['Pulsa el reloj "Horario de atención".', 'Cambia los tramos de cada día.', 'Pulsa "Guardar horario". Si alguien del equipo queda fuera, lo avisa.'] },
      ],
    },

    '/companies': {
      summary: 'Son los datos de tu empresa que salen en los comprobantes, lo que usas de tu plan y tu código de empresa.',
      points: [
        { title: 'Editar los datos', text: 'Nombre comercial, razón social, RUC, teléfono y dirección fiscal. La dirección de cada local va en Sucursales.', perm: 'company.edit' },
        { title: 'Uso del plan', text: `Sucursales, ${ps}, usuarios y citas del mes frente al tope de tu plan.` },
        { title: 'Tu plan', text: 'Cuánto pagas, hasta cuándo vale y la tarjeta con la que se cobra solo cada periodo. El rubro lo cambia el equipo de la plataforma.' },
        { title: 'Si vence sin pagarse', text: 'Tienes unos días de gracia en los que todo funciona igual. Después la empresa pasa a solo lectura: se puede ver y exportar, pero no agendar ni vender, y el portal no recibe reservas.' },
        { title: 'Pagos del plan', text: '"Ver pagos" muestra los periodos pagados y cada intento con Mercado Pago.', admin: true },
        { title: 'Código de empresa', text: `Tus ${cs} lo usan para registrarse y reservar en línea. "Copiar" lo deja listo para enviarlo.` },
      ],
      tasks: [
        { title: 'Actualizar el RUC o la dirección', perm: 'company.edit', steps: ['Pulsa el lápiz.', 'Cambia los datos.', 'Pulsa "Guardar cambios".'] },
        { title: 'Autorizar o cambiar tu tarjeta', admin: true, steps: ['En "Tu plan", pulsa "Autorizar tarjeta" o "Cambiar tarjeta".', 'En Mercado Pago, escribe los datos de tu tarjeta y confirma.', 'Vuelves solo a esta pantalla. Desde ahí el plan se cobra solo en cada periodo.'] },
        { title: 'Pagar tu plan ahora', admin: true, steps: ['En "Tu plan", pulsa "Pagar ahora".', 'Paga en Mercado Pago con tarjeta u otro medio.', 'Al volver, tu plan queda activo por un periodo más.'] },
        { title: 'Cambiar de plan', admin: true, steps: ['Pulsa "Cambiar plan".', 'Elige el plan y si lo pagas cada mes o cada año.', 'Pulsa "Confirmar plan". Si ya pagaste el periodo, el nuevo empieza en el siguiente.'] },
      ],
    },

    '/users': {
      summary: `Son las cuentas del personal y los roles que deciden qué puede hacer cada persona, pantalla por pantalla. Los ${ps} se registran en su propia pantalla.`,
      points: [
        { title: 'Usuarios', text: 'Cada cuenta con su rol y su sede. Las tarjetas cuentan las del plan, los activos, quienes tienen permisos cambiados y a quién le falta su sede.' },
        { title: 'Nuevo usuario', text: 'Con nombres, correo, rol y sede. Con sede, esa persona ve solo lo de su sede.', perm: 'users.create' },
        { title: 'Contraseña nueva', text: 'La llave le da una contraseña nueva. Se muestra una sola vez y la anterior deja de valer. Si la olvidó, también puede pedir un enlace con "¿Olvidaste tu contraseña?" al entrar.', perm: 'users.edit' },
        { title: 'Código al entrar', text: 'En la página de cada persona ves si entra con un código por correo. Los administradores lo tienen por defecto; a otros se les puede pedir siempre.', admin: true },
        { title: 'Cuenta bloqueada', text: 'Tras 5 contraseñas erradas la cuenta se bloquea 15 minutos. En su página aparece "Desbloquear" para que entre antes.', perm: 'users.edit' },
        { title: 'Qué puede hacer una persona', text: 'Al abrirla ves lo que ve en su menú y puedes darle o quitarle permisos solo a ella. "Volver a lo de su rol" deshace esos cambios.', perm: 'users.edit' },
        { title: 'Roles', text: 'Los de fábrica y los propios de tu empresa, con qué ve cada uno: toda la empresa, solo su sede o solo lo suyo.' },
        { title: 'Nuevo rol', text: 'Empieza copiando los permisos de otro rol y ajústalo.', perm: 'users.create' },
        { title: 'Permisos especiales', text: 'Forzar horario, acceso de emergencia a fichas, perdonar faltas, registrar uso a mano y exportar.' },
        { title: 'Historial de cambios', text: 'Quién cambió qué permiso y cuándo.' },
        { title: 'Últimas acciones', text: 'En la página de cada persona, lo último que hizo en el sistema. "Ver todo" abre la auditoría con solo lo suyo.', perm: 'audit.view' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Dar acceso a una recepcionista', perm: 'users.create', steps: ['Pulsa "Nuevo usuario".', 'Llena sus datos, elige el rol Recepción y su sede.', 'Pulsa "Crear usuario".'] },
        { title: 'Crear un rol propio', perm: 'users.create', steps: ['Entra a "Roles" y pulsa "Nuevo rol".', 'Escribe el nombre y elige de qué rol copiar.', 'Crea el rol y apaga lo que no necesita.'] },
        { title: 'Darle un permiso solo a una persona', perm: 'users.edit', steps: ['Abre a la persona.', 'Enciende el permiso en su pantalla.', 'El cambio queda en su historial.'] },
      ],
    },

    '/audit': {
      summary: 'Es el registro de quién hizo qué y cuándo en tu empresa: cobros, anulaciones, caja, citas, fichas, clientes, servicios, inventario, campañas, cambios de configuración y de permisos, eliminaciones e ingresos al sistema. Sirve para responder, por ejemplo, "¿quién anuló esta venta?". Solo se consulta: nada se puede borrar.',
      points: [
        { title: 'Pestañas y tarjetas', text: 'Cada área tiene su pestaña. Las tarjetas filtran lo de hoy, el dinero, las eliminaciones y anulaciones, y los ingresos fallidos.' },
        { title: 'Filtros', text: 'Por persona, por sede y por fecha. Por defecto se ven los últimos 30 días.' },
        { title: 'El ojo', text: 'Abre el detalle: quién, cuándo, desde qué dispositivo y qué cambió, con el valor de antes y el de ahora.' },
        { title: 'Soporte de la plataforma', text: 'Si alguien del soporte hizo un cambio dentro de tu panel, sale marcado con su nombre.' },
        { title: 'Nombre de la persona', text: 'Lleva a su página en Usuarios y roles, o a la ficha del cliente.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Saber quién anuló una venta', steps: ['Pulsa la tarjeta "Eliminaciones y anulaciones".', 'Busca el código de la venta.', 'Pulsa el ojo para ver quién fue, cuándo y el motivo.'] },
        { title: 'Ver qué hizo una persona ayer', steps: ['En "Persona" elige a esa persona.', 'En "Fecha" elige ayer.'] },
        { title: 'Saber quién reprogramó una cita', steps: ['Entra a la pestaña "Citas".', 'Busca el código de la cita.', 'Pulsa el ojo para ver la fecha de antes y la de ahora.'] },
      ],
      rules: [
        { title: 'Quién la ve', text: 'El administrador la ve completa. Se le puede dar a otra persona con el permiso "Ver auditoría"; quien tiene un rol de una sede ve solo lo de su sede.', where: 'Usuarios y roles' },
      ],
    },

    '/personalization': {
      summary: 'Aquí se cambia cómo se ve tu empresa y qué dicen sus textos: logo, colores, documentos, códigos, palabras, portal y correos. Cómo funciona se cambia en Configuración.',
      points: [
        { title: 'Marca y colores', text: 'Sube tu logo; el sistema sugiere colores a partir de él. Elige el color principal, el de acento y el del menú.' },
        { title: 'Documentos', text: 'Logo, pie, firma y sello, y condiciones de los presupuestos, fichas y comprobantes.' },
        { title: 'Códigos', text: 'Las letras de los códigos nuevos, por ejemplo PAC en vez de CLI. Lo ya creado no cambia.' },
        { title: 'Vocabulario', text: `Cómo llamas a quien atiendes y a quien atiende, por ejemplo "${vocab.client}" y "${vocab.pro}".` },
        { title: 'Portal de tus clientes', text: 'Los textos de bienvenida, cómo contactarte y tu política de privacidad.' },
        { title: 'Correos', text: 'El diseño de los correos y qué avisos se envían a tus clientes, con su texto.' },
        { title: 'Guardar y deshacer', text: 'Nada cambia hasta pulsar "Guardar". "Deshacer cambios" vuelve a lo guardado.', perm: 'settings.edit' },
      ],
      tasks: [
        { title: 'Poner tu logo y tus colores', perm: 'settings.edit', steps: ['En "Marca y colores", pulsa "Subir logo".', 'Pulsa "Usar estos colores" o elígelos tú.', 'Pulsa "Guardar".'] },
        { title: `Llamar "${vocab.clients}" a quienes atiendes`, perm: 'settings.edit', steps: ['Entra a "Vocabulario".', 'Escribe la palabra en singular y en plural, y si se dice "el" o "la".', 'Revisa la vista previa y pulsa "Guardar".'] },
      ],
    },

    '/settings': {
      summary: 'Aquí se decide cómo trabaja tu empresa. Cada sección se guarda con su propio botón, y solo aparecen las que aplican a tus módulos.',
      points: [
        { title: 'Tu empresa', text: 'Datos y código, módulos que usas, moneda y autoayuda.' },
        { title: 'Módulos', text: 'Enciende o apaga lo que usas. Algunos empiezan apagados por tu rubro y puedes encenderlos si tu plan los incluye.' },
        { title: 'Agenda y reservas', text: 'Nombres de las fases de la cita, plazos para confirmar y cancelar, qué pasa si reserva en línea y no viene (y si cancelar a última hora cuenta como falta), y salas y equipos.' },
        { title: 'Ventas y cobros', text: 'IGV, caja, pagos por partes, devoluciones, presupuestos, inventario, puntos, adelantos e integraciones.' },
        { title: 'Mensajes', text: 'Con qué correo se envían los mensajes de tu empresa, y un correo de prueba.' },
        { title: 'Clientes', text: `Desde cuándo ${vocab.pick('client', 'un', 'una')} ${c} se considera inactivo, frecuente o VIP, y a quiénes envía campañas el gerente de sede.` },
        { title: 'Seguridad y registros', text: 'Tiempo sin actividad antes de cerrar la sesión (con un tiempo distinto por rol si quieres), el código por correo para los administradores al entrar y el registro de descargas de datos.' },
        { title: 'Autoayuda', text: 'Mostrar u ocultar el botón ? y Autoayuda a todo tu equipo.', admin: true },
        { title: 'Solo ver', text: 'Si tu cuenta puede ver la configuración pero no cambiarla, el botón Guardar queda bloqueado.' },
      ],
      tasks: [
        { title: 'Encender un módulo', perm: 'settings.edit', steps: ['Entra a "Módulos".', 'Enciende el interruptor del módulo.', 'Pulsa "Guardar". Aparece en el menú de tu equipo.'] },
        { title: 'Cambiar el plazo para confirmar citas', perm: 'settings.edit', steps: ['Entra a "Reglas de reserva".', 'Cambia las horas y qué pasa si no se confirma.', 'Pulsa "Guardar".'] },
        { title: 'Cobrar en línea con Mercado Pago', perm: 'settings.edit', steps: ['Entra a "Integraciones" y elige Mercado Pago.', 'Elige el modo (Pruebas o Producción) y pega la Public Key y el Access Token de tu aplicación de Mercado Pago.', 'Copia la dirección de avisos en los Webhooks de tu aplicación y pega aquí su clave secreta.', 'Pulsa "Guardar y conectar". Desde ahí, los links de pago, los adelantos y los paquetes en línea se cobran con tu cuenta.'] },
        { title: 'Dar más tiempo sin actividad a un rol', perm: 'settings.edit', steps: ['Entra a "Cierre de sesión".', 'En "Tiempo distinto por rol", elige el tiempo del rol (por ejemplo, Profesional: 4 horas).', 'Pulsa "Guardar". Se aplica la próxima vez que esas personas entren.'] },
      ],
    },
  };
};
