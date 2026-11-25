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
        { title: 'Buscar y filtrar', text: 'Busca por nombre, documento, celular (con o sin +51) o código. Las tarjetas "Hombres" y "Mujeres" filtran la lista, y en los filtros puedes elegir una etiqueta.' },
        { title: 'Celular ordenado', text: 'Escribe el celular como quieras: se guarda con el código del país (+51 en Perú) y se muestra en grupos de tres números.' },
        { title: 'Alerta, sede y preferido', text: `En el formulario, la sección 4 guarda una alerta corta (por ejemplo, una alergia), su sede habitual y su ${vocab.pro.toLowerCase()} preferido. La alerta se ve en rojo en su página.` },
        { title: 'Etiquetas', text: `Con "Etiquetas" creas grupos propios con color (por ejemplo, "Prefiere WhatsApp"). Se ponen y quitan desde la página ${ofClient} y sirven para filtrar la lista y elegir a quién va una campaña.`, perm: 'customers.edit' },
        { title: 'Importar', text: `Con "Importar" cargas ${cs} desde un Excel o CSV usando la plantilla. Antes de guardar ves fila por fila qué se registra, qué se actualiza o se salta (si ya existe el mismo documento o celular) y qué tiene errores.`, perm: 'customers.import' },
        { title: 'Agendar y escribir desde el tablero', text: 'Cada tarjeta del tablero tiene "Agendar" (abre la nueva cita con la persona ya elegida) y "Escribir" (abre el mensaje por correo).' },
        { title: 'Su página completa', text: `Muestra gasto total, saldo pendiente, puntos, los grupos de campañas en los que está y sus etiquetas. Abajo, las pestañas Compras (cada venta abre su detalle), Notas y Consentimientos (cuándo aceptó o retiró cada permiso, quién lo cambió y desde qué dirección de internet).` },
        { title: 'Notas privadas', text: 'Al escribir una nota puedes marcarla como privada: solo la ven quienes tienen el permiso "Notas privadas" (de fábrica, administración y gerencia).', perm: 'customers.notes' },
        { title: 'Ver su página', text: `El ojo o su nombre abren la página ${ofClient}: datos, citas, fichas, paquetes y mensajes.` },
        { title: 'Editar o eliminar', text: `El lápiz cambia sus datos. El tacho lo quita de la lista.`, perm: 'customers.edit|customers.delete' },
        { title: 'Documento sin repetir', text: `No se pueden tener dos fichas con el mismo documento: el sistema te dice cuál ya existe.` },
        { title: 'Fichas repetidas', text: `Si la misma persona quedó registrada dos veces, en su página usa "Unir con otra ficha": sus citas, ventas y paquetes pasan a una sola.`, perm: 'customers.delete' },
        { title: 'Borrar datos personales', text: `Si ${aClient} pide que borren sus datos, en su página usa "Borrar datos personales". Se conservan sus citas, ventas y fichas de atención sin su nombre.`, perm: 'customers.delete' },
        { title: 'Vista de tablero', text: `Muestra a tus ${cs} en columnas por etapa, con su última visita y su próxima cita. Cada uno se ubica solo según sus citas: Nuevo contacto, Primera cita agendada, Activo, Fidelizado o Por recuperar.` },
        { title: 'Mover en el tablero', text: 'Arrastra la tarjeta a otra columna o usa "Mover a…". Lo que mueves a mano se queda ahí hasta su próxima cita (Perdido, hasta que vuelva a reservar). El cambio queda en su historial.', perm: 'customers.edit' },
        { title: 'Editar columnas', text: 'Cambia el nombre, la descripción y el color de las columnas del tablero, o agrega nuevas.', perm: 'settings.edit' },
        { title: 'Exportar', text: `Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas. La lista de ${cs} pide además el permiso «Exportar» de ${cs}, y cada descarga le llega como aviso al dueño.`, perm: 'data.export' },
        { title: 'Segmentos', text: `Las pestañas de arriba separan a tus ${cs} en nuevos, frecuentes, VIP, en riesgo, inactivos, sin visitas, cumpleaños del mes y con saldo pendiente, con cuántos hay en cada uno. Se calculan con las reglas de Configuración → Grupos de ${cs}.` },
        { title: 'Columnas de la lista', text: `Cada fila muestra sus segmentos, cuántas veces vino y hace cuántos días, su próxima cita, lo que gastó en 12 meses, sus puntos y su origen (por dónde llegó: recepción, reserva en línea, sin cita o importación).` },
        { title: `Buscar por ${lower(vocab.dependent)}`, text: `Si tu empresa registra ${ds}, también puedes buscar por su nombre.` },
        { title: 'Sede habitual y última visita', text: 'En los filtros eliges la sede habitual, y en el orden puedes poner primero a quienes vinieron hace menos.' },
        { title: 'Tablero con más datos', text: 'Cada tarjeta muestra sus visitas, lo gastado y sus segmentos, y cada columna suma lo gastado en 12 meses. Si mueves a alguien sin cita a «Primera cita agendada», se abre la nueva cita para agendarle.' },
        { title: 'Botones de su página', text: 'Arriba tienes «Nueva cita», «Presupuesto» y «Vender» (abre la caja con la persona ya elegida).' },
        { title: 'Línea de tiempo', text: 'En su página, la pestaña «Línea de tiempo» junta citas, fichas, ventas, presupuestos, notas, mensajes y puntos. Haz clic en una línea para abrirla.' },
        { title: 'Preferencias y controles', text: `Su página muestra el servicio que más usa, quién lo atiende más, su sede, su cumpleaños y su origen, y sus controles pendientes con «Agendar».` },
        { title: 'Presupuestos, membresías y puntos', text: 'Las pestañas muestran sus presupuestos, sus membresías y sus puntos y gift cards. Con «Ajustar» sumas o restas puntos con un motivo.', perm: 'loyalty.create|loyalty.edit' },
        { title: 'Escribir por WhatsApp y registrar llamadas', text: 'En «Mensajes» eliges si le escribes por correo o por WhatsApp. WhatsApp solo se puede si tu empresa lo conectó y la persona aceptó recibirlo. Con «Registrar llamada» anotas cómo terminó y de qué hablaron.', perm: 'messages.create' },
        { title: 'Datos en el mensaje', text: 'Al escribir puedes agregar su nombre, tu empresa, su próxima cita o su sede: se completan solos al enviar.', perm: 'messages.create' },
      ],
      tasks: [
        { title: `Registrar ${aClient}`, perm: 'customers.create', steps: [`Pulsa "${vocab.pick('client', 'Nuevo', 'Nueva')} ${vocab.client}".`, 'Llena nombres y apellidos; lo demás puedes completarlo después.', `Si tu empresa registra ${ds}, agrégalos en su sección.`, `Pulsa "Crear ${c}".`] },
        { title: 'Darle acceso al portal', perm: 'customers.edit', steps: [`Abre la página ${ofClient}.`, 'Revisa que tenga correo y documento.', 'En "Acceso al portal" enciende el interruptor.', 'Su primera contraseña es su número de documento; al entrar debe crear una nueva.'] },
        { title: 'Si olvidó su contraseña del portal', perm: 'customers.edit', steps: [`En la página ${ofClient}, en "Acceso al portal", pulsa "Olvidó su contraseña".`, 'Confirma con "Sí, restablecer". Vuelve a ser su número de documento.'] },
        { title: 'Cambiar de etapa en el tablero', perm: 'customers.edit', steps: ['En el tablero, arrastra su tarjeta a otra columna.', `O en su página, en "Etapa en el tablero", elige "Mover a…".`, 'Se queda ahí hasta su próxima cita. Para que vuelva a ubicarse solo, en su página pulsa "Que se ubique solo desde ahora".'] },
        { title: `Importar ${cs} desde Excel`, perm: 'customers.import', steps: ['Pulsa "Importar" y descarga la plantilla de Excel.', `Copia tus ${cs} en ella, uno por fila, y guárdala.`, 'Pulsa "Elegir archivo" y revisa la vista previa: las tarjetas filtran nuevos, actualizados, saltados y con error.', 'Elige si a quien ya existe se le salta o se le actualizan los datos.', 'Pulsa "Importar". Las filas con error no se guardan; corrígelas y vuelve a cargar el archivo.'] },
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
        { title: 'Ver', text: 'Abre el presupuesto con su cronograma de cuotas, su historial y sus botones. Arriba, una barra muestra en qué paso va: borrador, enviado, aprobado, en tratamiento o terminado.' },
        { title: 'Tasa de aceptación y por cobrar', text: 'Las tarjetas dicen qué parte de los presupuestos decididos se aprobó y cuánto falta cobrar de los planes aprobados.' },
        { title: `${vocab.dependent} y ${lower(vocab.pro)}`, text: `Si tu empresa registra ${ds}, eliges para cuál es el presupuesto. La lista muestra a quién es y quién atiende.` },
        { title: 'Descuento sobre el tope', text: 'Si una línea tiene más descuento que el tope de tu empresa, el editor te avisa para que lo revise alguien con el permiso «Descuento alto».' },
        { title: 'Agendar la siguiente sesión', text: 'En un presupuesto aprobado, «Agendar la siguiente sesión» abre la cita con la persona y el servicio que sigue.', perm: 'agenda.create', module: 'agenda' },
        { title: 'Piezas dentales', text: 'En clínicas dentales, las piezas escritas en cada línea (por ejemplo 36, 37) se marcan en un dibujo de la boca.' },
        { title: 'Aprobar o rechazar', text: 'Aprobado ya no se edita; solo se puede anular. Un presupuesto vencido no se aprueba: reábrelo como borrador y queda con una nueva fecha de validez. Uno anulado no se reabre. Para rechazarlo se escribe el motivo, por ejemplo «le pareció caro».', perm: 'quotes.edit' },
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
        { title: 'Periodos y tarjetas', text: 'Últimos 30 o 90 días, o todas. Las tarjetas muestran borradores y firmadas.' },
        { title: 'Ver la ficha', text: 'El ojo abre la ficha para leerla, completarla, firmarla o descargarla.' },
        { title: '¿Dónde se crea?', text: `Se abre desde la cita, en su tarjeta "Ficha de atención". Si atendiste sin cita, en la página ${ofClient} usa «Nueva ficha sin cita».` },
        { title: 'Copiar de la última ficha', text: 'Al llenar una ficha, «Copiar de la última ficha» trae lo que se anotó la vez anterior para que solo cambies lo nuevo.', perm: 'records.edit' },
        { title: `Filtrar por ${lower(vocab.dependent)}`, text: `En la página ${ofClient}, si tiene ${ds}, puedes ver solo las fichas de cada uno.` },
        { title: 'Plantilla confidencial o inactiva', text: 'Una plantilla confidencial deja privadas todas sus fichas. Una inactiva ya no se abre al atender ni se ofrece para fichas sin cita.', perm: 'record_templates.edit' },
        { title: 'Guardar borrador y firmar', text: '"Guardar borrador" guarda sin terminar. "Firmar ficha" la cierra; si falta algo obligatorio, lo dice.', perm: 'records.edit' },
        { title: 'Una ficha firmada ya no se cambia', text: 'Al firmarla queda cerrada. Si falta algo o hay que corregir, se agrega una nota: guarda quién la escribió y cuándo, y lo original no se toca.', perm: 'records.edit' },
        { title: 'Archivos adjuntos', text: 'Puedes adjuntar PDF, fotos o Word de hasta 10 MB (por ejemplo un examen), también después de firmar. Se guardan fuera de la parte pública, se abren con un enlace que vence a los pocos minutos y cada apertura queda anotada. Una vez firmada la ficha, sus archivos ya no se quitan.', perm: 'records.edit' },
        { title: 'Fichas de otros profesionales', text: 'Un profesional ve solo las fichas de sus citas, salvo que su rol tenga el permiso «Todas las fichas». Las privadas las sigue viendo solo quien las escribió.' },
        { title: 'Descargar', text: 'El PDF de la ficha, con el logo y la firma.' },
        { title: 'Atenciones anteriores', text: `Las fichas pasadas ${ofClient}, para comparar.` },
        { title: 'Fichas privadas', text: 'El ojo tachado indica que solo la ve quien la escribió.' },
        { title: 'Acceso de emergencia', text: 'Abre una ficha privada en solo lectura por 30 minutos, con un motivo. Queda registrado y su autor lo ve.', perm: 'records.emergency' },
        { title: 'Plantillas de ficha', text: 'Define qué campos tiene cada ficha y qué servicios la usan. Lo ya llenado no cambia.', perm: 'record_templates.edit' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Llenar y firmar una ficha', perm: 'records.edit', steps: ['Abre la cita y pulsa su "Ficha de atención", o abre la ficha con el ojo.', 'Llena los campos; los obligatorios llevan *.', `Marca el consentimiento si ${theClient} lo aceptó.`, 'Pulsa "Guardar borrador" si vas a seguir luego, o "Firmar ficha" al terminar.'] },
        { title: 'Corregir una ficha firmada', perm: 'records.edit', steps: ['Ábrela con el ojo.', 'En "Agregar una nota", escribe lo que falta o la corrección.', 'Pulsa "Agregar nota": la nota queda debajo con tu nombre y la fecha, y lo firmado no cambia.'] },
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
