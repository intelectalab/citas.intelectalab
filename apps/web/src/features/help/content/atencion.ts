import { lower, type HelpTopics } from '../types';

export const atencionTopics: HelpTopics = (vocab) => {
  const c = lower(vocab.client);
  const cs = lower(vocab.clients);
  const ds = lower(vocab.dependents);
  const theClient = `${vocab.pick('client', 'el', 'la')} ${c}`;
  const ofClient = `${vocab.pick('client', 'del', 'de la')} ${c}`;
  const aClient = `${vocab.pick('client', 'un', 'una')} ${c}`;

  return {
    '/customers': {
      summary: `Es el registro de tus ${cs}: sus datos, sus citas, sus fichas y sus paquetes. Se ve como tabla o como tablero de columnas, para seguir en qué etapa está cada uno. Al abrir ${aClient} ves toda su historia en una sola página.`,
      points: [
        { title: `Nuevo ${c}`, text: `Registra a ${aClient} con sus datos personales, documento, contacto y observaciones.`, perm: 'customers.create' },
        { title: 'Buscar y filtrar', text: 'Busca por nombre, documento, celular o código. Las tarjetas "Hombres" y "Mujeres" filtran la lista.' },
        { title: 'Ver su página', text: `El ojo o su nombre abren la página ${ofClient}: datos, citas, fichas, paquetes y mensajes.` },
        { title: 'Editar o eliminar', text: `El lápiz cambia sus datos. El tacho lo quita de la lista.`, perm: 'customers.edit|customers.delete' },
        { title: 'Vista de tablero', text: `Muestra a tus ${cs} en columnas por etapa, con su última visita y su próxima cita. Cada uno se ubica solo según sus citas: Nuevo contacto, Primera cita agendada, Activo, Fidelizado o Por recuperar.` },
        { title: 'Mover en el tablero', text: 'Arrastra la tarjeta a otra columna o usa "Mover a…". Lo que mueves a mano se queda ahí hasta su próxima cita (Perdido, hasta que vuelva a reservar). El cambio queda en su historial.', perm: 'customers.edit' },
        { title: 'Editar columnas', text: 'Cambia el nombre, la descripción y el color de las columnas del tablero, o agrega nuevas.', perm: 'settings.edit' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: `Registrar ${aClient}`, perm: 'customers.create', steps: [`Pulsa "${vocab.pick('client', 'Nuevo', 'Nueva')} ${vocab.client}".`, 'Llena nombres y apellidos; lo demás puedes completarlo después.', `Si tu empresa registra ${ds}, agrégalos en su sección.`, `Pulsa "Crear ${c}".`] },
        { title: 'Darle acceso al portal', perm: 'customers.edit', steps: [`Abre la página ${ofClient}.`, 'Revisa que tenga correo y documento.', 'En "Acceso al portal" enciende el interruptor.', 'Su primera contraseña es su número de documento; al entrar debe crear una nueva.'] },
        { title: 'Si olvidó su contraseña del portal', perm: 'customers.edit', steps: [`En la página ${ofClient}, en "Acceso al portal", pulsa "Olvidó su contraseña".`, 'Confirma con "Sí, restablecer". Vuelve a ser su número de documento.'] },
        { title: 'Cambiar de etapa en el tablero', perm: 'customers.edit', steps: ['En el tablero, arrastra su tarjeta a otra columna.', `O en su página, en "Etapa en el tablero", elige "Mover a…".`, 'Se queda ahí hasta su próxima cita. Para que vuelva a ubicarse solo, en su página pulsa "Que se ubique solo desde ahora".'] },
        { title: 'Ver su historia', steps: [`Abre la página ${ofClient}.`, 'Las tarjetas de números filtran sus citas: atenciones, próximas, canceladas y ausencias.', 'Haz clic en una atención para abrir su ficha.'] },
      ],
      rules: [
        { title: 'Inasistencias en reservas en línea', text: `Si ${theClient} reserva por el portal y no viene varias veces, puede quedar sin reserva en línea. Su página muestra "Perdonar inasistencias".`, module: 'booking', where: 'Configuración → Si reserva en línea y no asiste' },
      ],
    },

    '/quotes': {
      summary: `Sirve para armar presupuestos o planes de tratamiento con sesiones, productos y cuotas. Al aprobarlo, ${theClient} recibe un plan de tratamiento y cada sesión se descuenta al atenderla.`,
      points: [
        { title: 'Nuevo presupuesto', text: 'Con título, servicios, productos, paquetes o conceptos libres, sesiones, descuentos y cuotas.', perm: 'quotes.create' },
        { title: 'Pestañas y tarjetas', text: 'Por aprobar, aprobados, en tratamiento y cerrados. La tarjeta "Con cuotas vencidas" muestra quién está atrasado.' },
        { title: 'Ver', text: 'Abre el presupuesto con su cronograma de cuotas, su historial y sus botones.' },
        { title: 'Aprobar o rechazar', text: 'Aprobado ya no se edita; solo se puede anular. Un presupuesto vencido no se aprueba: reábrelo como borrador y queda con una nueva fecha de validez. Uno anulado no se reabre.', perm: 'quotes.edit' },
        { title: 'Respuesta desde el portal', text: `Si lo marcas como "Enviado", a ${theClient} le llega un correo y lo ve en su portal, donde puede aceptarlo o decir por qué no le interesa. Te llega un aviso a la campana con su respuesta.` },
        { title: 'Descargar PDF', text: 'Para enviarlo o imprimirlo con el logo de tu empresa.' },
        { title: 'Cobrar', text: 'Pasa el presupuesto a cobro o cobra cada cuota en su fecha.', perm: 'sales.create', module: 'pos' },
        { title: 'Anular', text: 'Termina un presupuesto. Si ya estaba aprobado, calcula cuánto devolver o cobrar.', perm: 'quotes.delete' },
        { title: 'Exportar', text: 'Descarga a Excel los presupuestos que estás viendo.', perm: 'data.export' },
      ],
      tasks: [
        {
          title: 'Crear y aprobar un presupuesto',
          perm: 'quotes.create',
          steps: [
            'Pulsa "Nuevo presupuesto".',
            `Escribe el título, elige ${theClient} y agrega las líneas con sus sesiones.`,
            'Si se paga por partes, marca "Fraccionar en cuotas de pago" y elige cuántas.',
            'Pulsa "Crear presupuesto". Si no marcas "Enviado al cliente", queda como borrador.',
            { text: 'Cuando acepte, ábrelo con "Ver" y pulsa "Aprobar plan".', perm: 'quotes.edit' },
          ],
        },
        { title: 'Cobrar una cuota', perm: 'sales.create', module: 'pos', steps: ['Abre el presupuesto con "Ver".', 'Si aún no tiene venta, pulsa "Pasar a cobro".', 'En "Cronograma de cuotas", pulsa "Cobrar cuota" y termina el cobro.'] },
        { title: 'Usar una sesión del plan', steps: ['Agenda la cita del servicio.', 'Al cobrar, elige "Usar el plan".', 'La sesión se descuenta cuando la cita se da por atendida.', { text: 'Si atendiste sin cita, usa "Descontar una sesión sin cita" y escribe el motivo.', perm: 'quotes.edit' }] },
        {
          title: 'Anular un presupuesto aprobado',
          perm: 'quotes.delete',
          steps: [
            'Abre el presupuesto y pulsa "Anular".',
            'Revisa lo que ya usó, lo que pagó y cuánto se le devuelve o cuánto falta cobrar.',
            'Escribe por qué se anula.',
            { text: 'Si se le devuelve dinero, indica el monto, cómo se devuelve y de qué caja.', perm: 'sales.delete' },
            'Si usó más de lo que pagó, elige "Cobrarle los … que faltan" (con el monto) o "No cobrarle el resto".',
            'Pulsa "Anular presupuesto". Las citas del plan que aún no se atendieron se cancelan.',
          ],
        },
      ],
      rules: [
        { title: 'Validez y cuotas', text: 'Cuántos días vale un presupuesto, si se permiten cuotas y si se crea la venta al aprobar. Sin la venta por cobrar no se crea el plan de sesiones: se crea al pasarlo a cobro.', where: 'Configuración → Presupuestos y planes' },
        { title: 'Responder desde el portal', text: `Si ${theClient} puede aceptar o rechazar sus presupuestos en su portal. Viene encendido.`, where: 'Configuración → Presupuestos y planes' },
        { title: 'Si se anula uno aprobado', text: 'Lo usado se calcula al precio del presupuesto, a precio normal, o no se devuelve dinero. Los productos siempre se cobran completos.', where: 'Configuración → Presupuestos y planes' },
        { title: 'Vencimientos', text: 'Un presupuesto sin aprobar vence solo al pasar su fecha, y una cuota sin pagar queda vencida.' },
      ],
    },

    '/records': {
      summary: `Son las fichas que se llenan al atender: lo que se observó, lo que se hizo y lo que se indicó, con su consentimiento. Cada ficha se llena desde la cita y se firma al terminar.`,
      points: [
        { title: 'Periodos y tarjetas', text: 'Últimos 30 o 90 días, o todas. Las tarjetas muestran borradores, firmadas y bloqueadas.' },
        { title: 'Ver la ficha', text: 'El ojo abre la ficha para leerla, completarla, firmarla o descargarla.' },
        { title: '¿Dónde se crea?', text: 'No hay botón de nueva ficha: se abre desde la cita, en su tarjeta "Ficha de atención".' },
        { title: 'Guardar borrador y firmar', text: '"Guardar borrador" guarda sin terminar. "Firmar ficha" la cierra; si falta algo obligatorio, lo dice.', perm: 'records.edit' },
        { title: 'Agregar una nota', text: 'Después de firmar, y sobre todo cuando ya está bloqueada, se agrega una nota en vez de cambiarla.', perm: 'records.edit' },
        { title: 'Descargar', text: 'El PDF de la ficha, con el logo y la firma.' },
        { title: 'Atenciones anteriores', text: `Las fichas pasadas ${ofClient}, para comparar.` },
        { title: 'Fichas privadas', text: 'El ojo tachado indica que solo la ve quien la escribió.' },
        { title: 'Acceso de emergencia', text: 'Abre una ficha privada en solo lectura por 30 minutos, con un motivo. Queda registrado y su autor lo ve.', perm: 'records.emergency' },
        { title: 'Plantillas de ficha', text: 'Define qué campos tiene cada ficha y qué servicios la usan. Lo ya llenado no cambia.', perm: 'record_templates.edit' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Llenar y firmar una ficha', perm: 'records.edit', steps: ['Abre la cita y pulsa su "Ficha de atención", o abre la ficha con el ojo.', 'Llena los campos; los obligatorios llevan *.', `Marca el consentimiento si ${theClient} lo aceptó.`, 'Pulsa "Guardar borrador" si vas a seguir luego, o "Firmar ficha" al terminar.'] },
        { title: 'Corregir una ficha firmada', perm: 'records.edit', steps: ['Ábrela y cambia lo necesario; guarda con "Guardar cambios".', 'Si ya está bloqueada, usa "Agregar una nota".'] },
        { title: 'Crear una plantilla', perm: 'record_templates.edit', steps: ['Pulsa "Plantillas de ficha" y luego "Nueva ficha".', 'Escribe el nombre y agrega los campos con su tipo.', 'Si hace falta, escribe el consentimiento informado.', 'Elige los servicios que la usan y pulsa "Crear ficha".'] },
      ],
      rules: [
        { title: 'Ficha obligatoria para terminar la cita', text: 'Con esta regla, una cita solo se da por atendida con su ficha firmada.' },
        { title: 'Bloqueo de la edición', text: 'Pasado un tiempo desde la firma, la ficha ya no se cambia: solo se agregan notas.' },
        { title: 'Fichas privadas', text: 'En algunas empresas cada ficha la ve solo quien la escribe.' },
      ],
    },
  };
};
