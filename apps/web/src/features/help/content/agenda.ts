import { lower, type HelpTopics } from '../types';

export const agendaTopics: HelpTopics = (vocab) => {
  const c = lower(vocab.client);
  const cs = lower(vocab.clients);
  const p = lower(vocab.pro);
  const ps = lower(vocab.pros);
  const theClient = `${vocab.pick('client', 'el', 'la')} ${c}`;

  return {
    '/reports': {
      summary: `Cómo va tu negocio en el periodo que elijas: ventas, citas y ocupación, ${cs}, ${ps} y servicios. Con Analítica avanzada, cada número se compara con el periodo anterior. Si atiendes como ${p}, ves "Mis números": solo lo tuyo.`,
      points: [
        { title: 'Periodo y sede', text: 'Arriba eliges los últimos 7, 30 o 90 días, el mes en curso, el mes anterior o tus propias fechas, y la sede.' },
        { title: 'Ventas', text: 'Ingresos (cobros menos devoluciones), ventas, ticket promedio, descuentos, propinas y devoluciones; por día, por medio de pago, por tipo y por sede.' },
        { title: 'Citas y ocupación', text: `Citas, atendidas, ocupación (qué parte del horario del equipo tuvo citas), cancelaciones, inasistencias y reservas por el portal. El mapa muestra qué días y horas se piden más.` },
        { title: `${vocab.clients}, ${vocab.pros} y servicios`, text: `Quiénes vuelven, los que más gastaron, cómo le fue a cada ${p} y qué se vende más. Para cada ${p} y cada servicio verás la Producción (precio de lo atendido) y lo Cobrado (lo que de verdad se pagó, ya restados los descuentos). Vienen con Analítica avanzada.` },
        { title: 'Mes en curso', text: 'Se compara con los mismos días del mes anterior: del 1 al 2 de noviembre contra el 1 al 2 de octubre.' },
        { title: 'Si eres gerente', text: `Todo es de tu sede, también los ${cs} nuevos (su primera cita en tu sede) y los que volvieron.` },
        { title: 'Montos', text: 'Los ingresos y gastos solo se ven con el permiso "Ver montos".' },
        { title: 'Exportar', text: 'Cada tabla se descarga a Excel con su botón.', perm: 'data.export' },
        { title: 'Mis números', text: `Tus citas atendidas, tu producción (el precio de lo que atendiste, no lo cobrado), tu ocupación, tus inasistencias, tu tiempo promedio, tus servicios y tus ${cs} más frecuentes, comparados con el periodo anterior.`, perm: 'reports.own' },
        { title: 'Tu meta del mes', text: `Está en la tarjeta "Comisiones y meta" de tu página de ${p}. Cuenta la misma producción de aquí: el precio de las citas que atendiste este mes, también las sesiones de paquetes ya pagados, más los productos que vendiste si tu empresa vende productos. No cuentan las citas canceladas ni las inasistencias.`, module: 'commissions', perm: 'reports.own' },
      ],
      tasks: [
        { title: 'Ver mis números', perm: 'reports.own', steps: ['Entra a Reportes, o pulsa "Ver mis números" en Inicio.', 'Elige el periodo y, si atiendes en varias sedes, la sede.', 'La flecha de cada número dice si subió o bajó frente al periodo anterior.'] },
        { title: 'Ver cómo te fue el mes pasado', steps: ['En el periodo elige "Mes anterior".', 'Revisa Ventas y luego Citas y ocupación.', 'Con Analítica avanzada, la flecha de cada número dice si subió o bajó frente al mes de antes.'] },
        { title: `Comparar a tus ${ps}`, steps: [`Entra a la pestaña ${vocab.pros}.`, 'Mira atendidas, ocupación e inasistencias de cada uno.', 'Descarga la tabla con "Exportar" si la necesitas.'] },
      ],
    },

    '/home': {
      summary: `Es el resumen de tu día: las citas de hoy, lo que está pasando ahora mismo, lo que queda por resolver y cómo te fue en los últimos 30 días. Si atiendes como ${p}, ves solo lo tuyo.`,
      points: [
        { title: 'Ahora mismo', text: 'Cuántas personas esperan, cuántas están en atención y cuántas citas faltan confirmar.' },
        { title: 'Por resolver', text: 'Citas por confirmar, citas pasadas que nadie cerró, citas dentro de un bloqueo o feriado, controles vencidos y fichas sin firmar. Haz clic en cada una para ir a la lista.' },
        { title: 'Acciones rápidas', text: `Atajos para agendar una cita, registrar ${vocab.pick('client', 'un', 'una')} ${c} nuevo y abrir las pantallas que más se usan.` },
        { title: 'Números del mes', text: `Asistencia, ${cs} nuevos y el valor de lo atendido. Ese valor es el precio de los servicios atendidos, no lo que se cobró. Si atiendes como ${p}, dice "Tu producción" y "Ver mis números" lleva a tus reportes.` },
      ],
      tasks: [
        { title: 'Empezar el día', steps: ['Mira "Por resolver" y resuelve lo pendiente: confirma citas, cierra las de ayer.', 'Revisa "Ahora mismo" para saber quién está esperando.', 'Pulsa "Ir a la agenda" para ver el día completo.'] },
      ],
    },

    '/agenda': {
      summary: `Es el trabajo de una sede por día, semana o mes. En Día hay una columna por cada ${p}. Aquí se agenda, se mueve y se atiende cada cita. Se actualiza sola, así que lo que hace otra persona aparece sin recargar.`,
      points: [
        { title: 'Día, Semana y Mes', text: `Los botones junto a la fecha cambian la vista. Semana muestra los 7 días con sus horas; Mes, cada día con sus citas y su ocupación. Recuerda la última vista que usaste.` },
        { title: 'Cambiar de fecha', text: 'Las flechas avanzan un día, una semana o un mes según la vista; "Hoy" vuelve a hoy, o elige una fecha en el calendario.' },
        { title: `Un solo ${p}`, text: `En Semana y Mes puedes elegir a un ${p} para ver solo su agenda. Cada cita lleva el color de su ${p}.` },
        { title: 'Ir a un día', text: 'En Semana pulsa el nombre del día, y en Mes pulsa el día, para abrirlo en la vista Día.' },
        { title: 'Cambiar de sede', text: 'Si tu empresa tiene varias sedes, elige cuál ver arriba.' },
        { title: 'Tarjetas de arriba', text: 'Filtran la agenda: pendientes, confirmadas, en curso, atendidas o canceladas.' },
        { title: 'Espacios grises', text: `Son horas en que ${vocab.pick('pro', 'el', 'la')} ${p} no atiende: pausas, vacaciones, bloqueos o un feriado. No se pueden usar para agendar.` },
        { title: 'Nueva cita', text: 'Con el botón de arriba o haciendo clic en un espacio libre de la columna, a la hora que quieres.', perm: 'agenda.create' },
        { title: 'Mover una cita', text: `Arrástrala a otra hora o a otra columna y confirma con "Sí, mover". En Semana se puede mover a otro día, con el mismo ${p}. Solo se mueven las citas que aún no empiezan.`, perm: 'agenda.edit' },
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
            'Al elegirlo, debajo aparecen sus avisos si los tiene: saldo por cobrar, faltas recientes, un paquete o membresía que cubre el servicio, o su alerta personal.',
            `Elige el servicio y, si quieres, ${vocab.pick('pro', 'el', 'la')} ${p}. Con "Cualquiera disponible" el sistema busca a quien esté libre.`,
            'Elige uno de los "Horarios libres" y pulsa "Agendar cita". Si alguien tomó ese horario mientras llenabas, se vuelven a mostrar los horarios libres sin perder lo que escribiste.',
            { text: 'Si no queda ningún horario, pulsa "Anotar en lista de espera" para avisarle cuando se libere uno.', perm: 'waitlist.create' },
          ],
        },
        {
          title: 'Atender una cita, paso a paso',
          perm: 'agenda.start',
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
          perm: 'agenda.cancel',
          steps: [
            'Haz clic en la cita y abre "Más acciones".',
            'Para cancelar, pulsa "Cancelar cita", escribe el motivo y pulsa "Cancelar la cita".',
            'Si reservó en línea, falta poco y lo pide el cliente, marca "Lo pidió…": el panel te dice con cuántas faltas queda para que se lo avises. Sale solo si tu empresa cuenta la cancelación a última hora como falta.',
            'Si la persona no llegó, pulsa "No asistió". Se puede marcar pasados unos minutos de la hora de la cita.',
            { text: 'Si alguien de la lista de espera puede usar ese horario, la cita te lo avisa.', perm: 'waitlist.view' },
          ],
        },
        {
          title: 'Cambiar la hora de una cita',
          perm: 'agenda.edit',
          steps: ['Arrastra la cita a la nueva hora y pulsa "Sí, mover".', 'O ábrela, entra a "Más acciones", pulsa "Reprogramar", elige el nuevo horario y escribe el motivo del cambio.'],
        },
        {
          title: 'Enviar un recordatorio',
          perm: 'agenda.confirm',
          steps: ['Abre una cita pendiente o confirmada que aún no empieza.', 'Pulsa "Enviar recordatorio": le llega el mismo recordatorio de siempre a su correo.', 'Si no tiene correo o falta configurar el envío, la cita te lo dice.'],
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
        { title: 'Confirmar las citas de mañana', perm: 'agenda.confirm', steps: ['Elige "Próximos 7 días" y la tarjeta "Pendientes".', 'Abre cada cita y pulsa "Confirmar" cuando la persona lo confirme.'] },
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
        { title: 'Avisar a todos o de uno en uno', text: 'Por defecto se avisa a todos a los que les sirve el horario. Tu empresa puede elegir avisar solo al primero en orden: debajo de su estado ves hasta cuándo tiene para tomarlo; si no lo toma, se avisa al siguiente.', where: 'Configuración → Lista de espera' },
        { title: 'Apagar la lista', text: 'Si se apaga, nadie recibe avisos y en el portal ya no aparece. Lo anotado se conserva.', where: 'Configuración → Lista de espera' },
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

    '/walkin': {
      summary: `Para ${cs} que llegan sin cita y esperan su turno, como en una barbería. Cada uno recibe un turno del día (T-001, T-002...) y ves cuánto le falta para pasar.`,
      points: [
        { title: 'Pestañas', text: 'En espera, En atención, Atendidos y Se retiraron son de hoy. En Días anteriores ves los turnos de los últimos 90 días.' },
        { title: 'Tarjetas', text: `Filtran a quienes esperan a un ${p} en particular, a quienes llevan más de 30 minutos y a quienes se anotaron en el kiosko.` },
        { title: 'La espera', text: `Se calcula con lo mismo que usa la agenda: el horario de cada ${p}, sus citas, sus pausas y ausencias, y quienes están antes en la cola.` },
        { title: 'Atender', text: 'Crea su cita desde ese momento y empieza la atención. Se cobra y se da por atendida desde la cita, como cualquier otra; al terminarla, el turno pasa solo a Atendidos.' },
        { title: 'Kiosko', text: 'Se enciende en Configuración, Cola sin cita. Desde ahí también se abre en la tableta de la entrada.' },
      ],
      tasks: [
        { title: 'Anotar a alguien que llega sin cita', steps: ['Pulsa "Anotar en la cola".', `Busca ${theClient} por nombre o celular. Si es nuevo, pulsa "+" para registrarlo ahí mismo.`, `Elige el servicio. En "¿Con qué ${p}?" aparecen solo quienes trabajan hoy y hacen ese servicio, con la espera de cada uno.`, 'Pulsa "Anotar en la cola".'] },
        { title: 'Pasarlo a atención', steps: ['En "En espera", pulsa "Atender".', `Elige a un ${p} libre. Si pidió a alguien, aparece marcado.`, 'Pulsa "Empezar la atención". Si tu empresa cobra antes de atender, se abre la cita para cobrarla primero.'] },
        { title: 'Si se fue sin atenderse', steps: ['Pulsa la X de su fila y confirma.', 'Si vuelve el mismo día, en "Se retiraron" pulsa la flecha para ponerlo otra vez en espera.'] },
        { title: 'Cambiar la letra del turno', steps: ['Ve a Personalización, Códigos.', 'Cambia la letra de "Turnos de la cola sin cita" y guarda. El cambio rige desde el siguiente turno.'] },
      ],
    },

    '/classes': {
      summary: `Clases grupales con cupos, como yoga o pilates. Cada clase tiene su ${p}, su sala, su fecha y hora y un número de cupos; ${cs} se inscriben hasta llenarla y luego quedan en lista de espera.`,
      points: [
        { title: 'Pestañas', text: 'Próximas muestra lo que viene y lo que está en curso. Pasadas y canceladas guarda lo que ya pasó.' },
        { title: 'Tarjetas', text: 'Filtran las clases de los próximos 7 días, las que están completas y las que tienen gente esperando. La asistencia del último mes es el porcentaje de inscritos que vino.' },
        { title: 'En la agenda', text: `La clase ocupa ${vocab.pick('pro', 'al', 'a la')} ${p} y a su sala. Mientras dura, la agenda no deja poner una cita encima, y no se puede programar una clase encima de una cita.` },
        { title: 'Lista de espera', text: 'Si la clase se llena, quien quiere entrar queda en espera. Cuando alguien sale, el primero de la lista entra solo y recibe un aviso. Ambas cosas se cambian en Configuración, Clases.' },
        { title: 'Cobro', text: `Cada inscrito se cobra aparte: "Cobrar" crea su venta en el punto de venta con el precio del servicio. Si tiene una membresía que incluye la clase, la venta sale sin cobro; si tiene un paquete con esa clase, "Usar su paquete" descuenta una sesión.` },
        { title: 'Página de cada clase', text: 'Muestra sus inscritos (y cómo llegó cada uno: panel, portal o reserva en línea), si ya pagaron y con qué, la lista de espera y las siguientes de la serie. Desde ahí se cambia la fecha, la sala o el cupo: si subes el cupo, entran los primeros en espera.' },
        { title: 'Portal y reservas en línea', text: `Si la clase tiene encendida la inscripción en línea, ${cs} la ven en su portal y en la página de reservas y se inscriben solos. Pueden salir desde el portal hasta unas horas antes (Configuración, Clases).` },
      ],
      tasks: [
        { title: 'Programar una clase cada semana', steps: ['Primero crea el servicio en Servicios y marca que es grupal, con su cupo.', 'Pulsa "Programar clase".', `Elige la clase, ${vocab.pick('pro', 'el', 'la')} ${p}, la sala, la fecha y la hora.`, 'En "Repetir", elige cuántas semanas. Las fechas que chocan con otra cosa se saltan y te avisamos cuáles.'] },
        { title: 'Inscribir a alguien', steps: ['Abre la clase con su nombre o con el ojo.', `En "Inscritos", busca ${theClient}. Si es nuevo, pulsa "+".`, 'Pulsa "Inscribir". Si ya no hay cupos, queda en la lista de espera.'] },
        { title: 'Tomar asistencia', steps: ['Cuando la clase empieza, abre la clase.', 'Marca "Asistió" o "No asistió" en cada inscrito, o "Todos asistieron".'] },
        { title: 'Cancelar una clase', steps: ['Abre la clase y pulsa "Cancelar clase".', 'Escribe el motivo: va en el mensaje que reciben los inscritos y quienes esperaban.', 'Las clases descontadas de un paquete se devuelven solas. Lo cobrado en una venta se devuelve desde Ventas.'] },
      ],
    },

    '/notifications': {
      summary: `Son tus avisos: citas reservadas o canceladas desde el portal, plazos de confirmación vencidos, horarios que se liberaron y lo que pasa con las citas de tus ${cs}. La campana de arriba muestra los últimos.`,
      points: [
        { title: 'Bloqueos y feriados', text: `Si se registra una ausencia, un cierre o un feriado sobre citas ya agendadas, le avisa ${vocab.pick('pro', 'al', 'a la')} ${p} y a quien maneja la agenda de esa sede.` },
        { title: 'Controles', text: 'Una vez al día, los controles vencidos y los que vencen en los próximos 3 días.' },
        { title: 'Fichas sin terminar', text: `${vocab.pick('pro', 'Al', 'A la')} ${p}, las fichas que siguen en borrador 24 horas después de atender.` },
        { title: 'Presupuestos y paquetes', text: `Cuando ${theClient} acepta o rechaza un presupuesto, o compra un paquete en el portal.` },
        { title: 'Acceso de emergencia', text: 'Al autor de una ficha privada y a los administradores, cuando alguien la abre por emergencia.' },
        { title: 'Si eres gerente de sede', text: 'También te llegan los cierres de caja con diferencia, las salas o equipos fuera de uso y las citas canceladas de tu sede.' },
        { title: 'Tu plan y tu suscripción', text: 'Al administrador, cuando se llega al 80 % o al 100 % de un tope del plan, y 7, 3 y 1 día antes de que venza la prueba o la suscripción.', admin: true },
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
