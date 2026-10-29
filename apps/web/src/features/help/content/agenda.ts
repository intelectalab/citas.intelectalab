import { lower, type HelpTopics } from '../types';

export const agendaTopics: HelpTopics = (vocab) => {
  const c = lower(vocab.client);
  const cs = lower(vocab.clients);
  const p = lower(vocab.pro);
  const ps = lower(vocab.pros);
  const theClient = `${vocab.pick('client', 'el', 'la')} ${c}`;

  return {
    '/home': {
      summary: `Es el resumen de tu día: las citas de hoy, lo que está pasando ahora mismo, lo que queda por resolver y cómo te fue en los últimos 30 días. Si atiendes como ${p}, ves solo lo tuyo.`,
      points: [
        { title: 'Ahora mismo', text: 'Cuántas personas esperan, cuántas están en atención y cuántas citas faltan confirmar.' },
        { title: 'Por resolver', text: 'Citas por confirmar, citas pasadas que nadie cerró, citas dentro de un bloqueo o feriado, controles vencidos y fichas sin firmar. Haz clic en cada una para ir a la lista.' },
        { title: 'Acciones rápidas', text: `Atajos para agendar una cita, registrar ${vocab.pick('client', 'un', 'una')} ${c} nuevo y abrir las pantallas que más se usan.` },
        { title: 'Números del mes', text: `Asistencia, ${cs} nuevos y el valor de lo atendido. Ese valor es el precio de los servicios atendidos, no lo que se cobró.` },
      ],
      tasks: [
        { title: 'Empezar el día', steps: ['Mira "Por resolver" y resuelve lo pendiente: confirma citas, cierra las de ayer.', 'Revisa "Ahora mismo" para saber quién está esperando.', 'Pulsa "Ir a la agenda" para ver el día completo.'] },
      ],
    },

    '/agenda': {
      summary: `Es el día de trabajo de una sede, con una columna por cada ${p}. Aquí se agenda, se mueve y se atiende cada cita. Se actualiza sola, así que lo que hace otra persona aparece sin recargar.`,
      points: [
        { title: 'Cambiar de día', text: 'Usa "Día anterior", "Hoy" y "Día siguiente", o elige una fecha en el calendario.' },
        { title: 'Cambiar de sede', text: 'Si tu empresa tiene varias sedes, elige cuál ver arriba.' },
        { title: 'Tarjetas de arriba', text: 'Filtran la agenda: pendientes, confirmadas, en curso, atendidas o canceladas.' },
        { title: 'Espacios grises', text: `Son horas en que ${vocab.pick('pro', 'el', 'la')} ${p} no atiende: pausas, vacaciones, bloqueos o un feriado. No se pueden usar para agendar.` },
        { title: 'Nueva cita', text: 'Con el botón de arriba o haciendo clic en un espacio libre de la columna, a la hora que quieres.', perm: 'agenda.create' },
        { title: 'Mover una cita', text: 'Arrástrala a otra hora o a otra columna y confirma con "Sí, mover". Solo se mueven las citas que aún no empiezan.', perm: 'agenda.edit' },
        { title: 'Abrir una cita', text: 'Haz clic en ella para ver sus datos, su historial y los botones para avanzarla.' },
        { title: 'En el celular', text: `Elige "Por ${p}" para ver la agenda de una persona, o "Lista" para ver todas las citas del día una debajo de otra.` },
      ],
      tasks: [
        {
          title: 'Agendar una cita',
          perm: 'agenda.create',
          steps: [
            'Pulsa "Nueva cita" o haz clic en un espacio libre.',
            `Busca ${theClient} por nombre, documento, celular o código. Si es nuevo, pulsa "+" para registrarlo ahí mismo.`,
            `Elige el servicio y, si quieres, ${vocab.pick('pro', 'el', 'la')} ${p}. Con "Cualquiera disponible" el sistema busca a quien esté libre.`,
            'Elige uno de los "Horarios libres" y pulsa "Agendar cita".',
            { text: 'Si no queda ningún horario, pulsa "Anotar en lista de espera" para avisarle cuando se libere uno.', perm: 'waitlist.create' },
          ],
        },
        {
          title: 'Atender una cita, paso a paso',
          perm: 'agenda.edit',
          steps: [
            'Haz clic en la cita.',
            'Pulsa "Confirmar" cuando la persona confirme que vendrá.',
            'Pulsa "Registrar llegada" cuando llegue: empieza a contar su tiempo de espera.',
            'Pulsa "Iniciar atención" cuando pase con su profesional.',
            'Pulsa "Dar por atendida" al terminar.',
            { text: 'Si tu empresa cobra antes de atender, cobra la cita con "Cobrar" antes de iniciar la atención.', module: 'pos' },
          ],
        },
        {
          title: 'Cancelar una cita o marcar que no vino',
          perm: 'agenda.edit',
          steps: [
            'Haz clic en la cita.',
            'Para cancelar, pulsa "Cancelar cita", escribe el motivo y pulsa "Cancelar la cita".',
            'Si la persona no llegó, pulsa "No asistió". Se puede marcar pasados unos minutos de la hora de la cita.',
            { text: 'Si alguien de la lista de espera puede usar ese horario, la cita te lo avisa.', perm: 'waitlist.view' },
          ],
        },
        {
          title: 'Cambiar la hora de una cita',
          perm: 'agenda.edit',
          steps: ['Arrastra la cita a la nueva hora y pulsa "Sí, mover".', 'O ábrela, pulsa "Reprogramar", elige el nuevo horario y escribe el motivo del cambio.'],
        },
      ],
      rules: [
        { title: 'Plazo para confirmar', text: 'Una cita pendiente debe confirmarse antes de cierto tiempo. Si vence, la cita lo muestra en rojo y, según tu empresa, solo avisa o libera el horario.', where: 'Configuración → Reglas de reserva' },
        { title: 'Cancelar con anticipación', text: 'Si se cancela muy cerca de la hora, la cita queda marcada como cancelada fuera de plazo.', where: 'Configuración → Reglas de reserva' },
        { title: 'Cobrar antes de atender', text: 'Con esta regla encendida, una cita con precio no se puede iniciar ni dar por atendida hasta cobrarla.', module: 'pos', where: 'Configuración → Ventas y caja' },
        { title: 'Ficha firmada para terminar', text: 'En algunos servicios, la cita solo se da por atendida si su ficha de atención está firmada.', module: 'records' },
        { title: 'Agendar en un horario ocupado', text: 'Quien tiene ese permiso puede forzar una cita fuera de horario o en un bloqueo, siempre con un motivo. Nunca dos citas a la misma hora con la misma persona.', perm: 'agenda.override' },
      ],
    },

    '/appointments': {
      summary: 'Es la lista de todas las citas, para buscarlas por fecha, fase o profesional. Sirve para revisar el día, la semana o el mes, y para exportar.',
      points: [
        { title: 'Periodos', text: 'Las pestañas muestran hoy, los próximos 7 o 30 días, o los últimos 30 días. Con "Fecha de la cita" eliges otro periodo.' },
        { title: 'Filtros', text: `Filtra por fase o por ${p}, o usa las tarjetas de arriba para ver solo pendientes, confirmadas o canceladas.` },
        { title: 'Ver la cita', text: 'El ojo abre la cita con sus mismos botones que en la agenda: confirmar, registrar llegada, atender, cancelar o cobrar.' },
        { title: 'Plazo vencido', text: 'Una cita que no se confirmó a tiempo lo dice en su fila.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
        { title: 'Nueva cita', text: 'Abre el mismo formulario que en la agenda.', perm: 'agenda.create' },
      ],
      tasks: [
        { title: 'Encontrar una cita', steps: [`Escribe en el buscador el nombre ${vocab.pick('client', 'del', 'de la')} ${c} o el código de la cita.`, 'Si no aparece, cambia el periodo o la fecha.', 'Pulsa el ojo para abrirla.'] },
        { title: 'Confirmar las citas de mañana', perm: 'agenda.edit', steps: ['Elige "Próximos 7 días" y la tarjeta "Pendientes".', 'Abre cada cita y pulsa "Confirmar" cuando la persona lo confirme.'] },
      ],
    },

    '/waitlist': {
      summary: `Son las personas que quieren una cita pero no encontraron horario. Cuando se libera uno que les sirve, el sistema te avisa para ofrecérselo.`,
      points: [
        { title: 'Pestañas', text: 'En espera, Con cita (ya consiguieron horario) y Cerrados (retirados o vencidos).' },
        { title: 'Tarjetas', text: `Prioritarios, a quienes les quedan 3 días o menos, quienes esperan a un ${p} en particular y quienes ya fueron avisados.` },
        { title: 'Buscar horario', text: 'Muestra los horarios libres que le sirven a esa persona. Al elegir uno se abre la cita ya llenada.', perm: 'agenda.create' },
        { title: 'Ya se le avisó', text: 'El teléfono marca que ya la llamaste o le escribiste.', perm: 'waitlist.edit' },
        { title: 'Retirar de la lista', text: 'Para quien ya no necesita la cita. Se puede volver a poner en espera.', perm: 'waitlist.edit' },
        { title: 'Vence sola', text: 'Cuando pasa la última fecha que le servía, deja la lista sola.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        {
          title: 'Anotar a alguien',
          perm: 'waitlist.create',
          steps: ['Pulsa "Anotar en la lista".', `Elige el servicio, la sede y, si lo pide, ${vocab.pick('pro', 'el', 'la')} ${p}.`, 'Indica qué fechas y a qué hora le sirven. Marca "Prioritario" si es urgente.', 'Guarda.'],
        },
        { title: 'Darle una cita', perm: 'agenda.create', steps: ['Pulsa "Buscar horario" en su fila.', 'Elige un horario.', 'Revisa la cita y pulsa "Agendar cita". Pasa a la pestaña "Con cita".'] },
      ],
    },

    '/recalls': {
      summary: `Son los controles: recordatorios para que ${theClient} vuelva en una fecha, por ejemplo un control al mes o una limpieza cada seis meses. Se crean a mano, desde una ficha o solos al atender ciertos servicios.`,
      points: [
        { title: 'Pestañas', text: 'Por agendar, Con cita y Cerrados (realizados o descartados).' },
        { title: 'Tarjetas', text: 'Vencidos, de hoy a 7 días, más adelante y ya avisados.' },
        { title: 'Agendar', text: 'Abre una cita ya llenada para ese control.', perm: 'agenda.create' },
        { title: 'Ya se le avisó', text: 'El teléfono marca que ya lo contactaste.', perm: 'recalls.edit' },
        { title: 'Ya se atendió', text: 'El check lo da por realizado si vino sin agendarse desde aquí.', perm: 'recalls.edit' },
        { title: 'Descartar', text: 'Para un control que ya no hace falta. Pide el motivo.', perm: 'recalls.edit' },
        { title: 'Origen', text: 'La columna dice si vino de una ficha, del servicio o se registró a mano.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Registrar un control', perm: 'recalls.create', steps: ['Pulsa "Nuevo control".', 'Escribe para qué es y elige para cuándo; hay atajos como "En 1 mes" o "En 6 meses".', 'Elige el servicio con el que se agendará y guarda.'] },
        { title: 'Agendar los vencidos', perm: 'agenda.create', steps: ['Pulsa la tarjeta "Vencidos".', 'En cada fila pulsa "Agendar" y elige un horario.', 'Cuando esa cita se da por atendida, el control queda realizado solo.'] },
      ],
    },

    '/blocks': {
      summary: `Son las horas en que no se atiende: vacaciones o permisos de un ${p}, cierres de la sede y feriados. En esas horas no se ofrecen horarios para agendar.`,
      points: [
        { title: 'Pestañas', text: 'Vigentes y próximos, Anteriores y Feriados.' },
        { title: 'Con citas dentro', text: 'Si ya había citas en ese tiempo, la fila lo dice. Ábrelas desde ahí para moverlas o cancelarlas.' },
        { title: 'Nuevo bloqueo', text: `Para ${vocab.pick('pro', 'un', 'una')} ${p} o para toda la sede, por días completos o por horas.`, perm: 'blocks.create' },
        { title: 'Editar o eliminar', text: 'Cambia las fechas de un bloqueo o quítalo si ya no aplica.', perm: 'blocks.edit|blocks.delete' },
        { title: 'Feriados', text: 'Carga de una vez los feriados del país con el botón del aviso, o agrega los tuyos. Un feriado puede cerrar la sede o no.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        {
          title: 'Registrar unas vacaciones',
          perm: 'blocks.create',
          steps: [`Pulsa "Nuevo bloqueo" y elige ${vocab.pick('pro', 'al', 'a la')} ${p}.`, 'En "Tipo" elige Vacaciones y marca "Bloquear días completos".', 'Indica desde y hasta cuándo, escribe el motivo y guarda.', 'Si avisa "Con citas dentro", abre esas citas y reprográmalas.'],
        },
        { title: 'Cargar los feriados del año', steps: ['Entra a la pestaña "Feriados".', 'Pulsa el botón "Cargar los de…" del aviso.', 'Revisa la lista y edita los que tu sede sí atiende.'] },
      ],
    },

    '/attention-times': {
      summary: `Muestra cuánto duran de verdad las atenciones frente al tiempo reservado, y cuánto esperan ${cs}. Sirve para ajustar la duración de los servicios y repartir mejor la agenda.`,
      points: [
        { title: 'Periodo', text: 'Elige los últimos 7, 30 o 90 días.' },
        { title: 'Tarjetas', text: 'Atenciones medidas, tiempo promedio, tiempo asignado, diferencia con lo asignado y espera promedio.' },
        { title: 'Tablas', text: `Los mismos números por ${p}, por servicio y por ${c}. La fila más rápida dice "Más ágil".` },
        { title: '¿De dónde salen?', text: 'De los botones de la cita: "Registrar llegada", "Iniciar atención" y "Dar por atendida". Si nadie los pulsa, la cita sale "Sin medir".' },
      ],
      tasks: [
        { title: 'Saber si un servicio necesita más tiempo', steps: ['Elige "Últimos 30 días".', 'En "Por servicio", compara el tiempo promedio con el asignado.', 'Si siempre se pasa, cambia su duración en Servicios.'] },
      ],
    },

    '/modules/walkin': {
      summary: `Servirá para atender a quien llega sin cita, por orden de llegada, como en una barbería. Esta pantalla está en construcción: todavía no se puede usar.`,
    },

    '/modules/classes': {
      summary: 'Servirá para clases grupales con cupos, como yoga o pilates. Esta pantalla está en construcción: todavía no se puede usar.',
    },

    '/notifications': {
      summary: `Son tus avisos: citas reservadas o canceladas desde el portal, plazos de confirmación vencidos, horarios que se liberaron y lo que pasa con las citas de tus ${cs}. La campana de arriba muestra los últimos.`,
      points: [
        { title: 'Sin leer', text: 'La campana muestra cuántos avisos tienes sin leer. "Marcar todo como leído" los limpia.' },
        { title: 'Ir al detalle', text: 'Haz clic en un aviso para abrir lo que lo causó.' },
      ],
    },

    '/help': {
      summary: 'Es la guía de las pantallas de tu menú. Cada pantalla explica para qué sirve, qué hace cada botón y cómo se hacen las tareas de todos los días. Solo ves lo que tu cuenta puede hacer.',
      points: [
        { title: 'Buscar', text: 'Escribe lo que necesitas hacer, por ejemplo "cancelar" o "cobrar", y quedan solo las pantallas que hablan de eso.' },
        { title: 'Ir a esta pantalla', text: 'Te lleva directo a la pantalla que estás leyendo.' },
        { title: 'El botón ?', text: 'Arriba, junto a la campana, abre la ayuda de la pantalla donde estás, sin salir de ella.' },
        { title: 'Encender o apagar', text: 'El administrador puede apagar la autoayuda para todo el equipo.', admin: true },
      ],
      rules: [
        { title: 'Mostrar la ayuda al equipo', text: 'Apagada, el botón ? y esta página dejan de verse para todos.', admin: true, where: 'Configuración → Autoayuda' },
      ],
    },
  };
};
