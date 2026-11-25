import { lower, type HelpTopics } from '../types';

export const ventasTopics: HelpTopics = (vocab) => {
  const c = lower(vocab.client);
  const theClient = `${vocab.pick('client', 'el', 'la')} ${c}`;

  return {
    '/pos': {
      summary: `Aquí se cobra todo en una sola venta: las citas del día, servicios, productos y paquetes. Si ${theClient} no paga ahora, la venta queda "por cobrar" para terminarla después.`,
      points: [
        { title: 'Por cobrar', text: 'Las citas de hoy sin pagar, las atendidas sin cobrar y las ventas que quedaron abiertas. "Agregar" pasa una cita al carrito; "Abrir" retoma una venta.' },
        { title: 'Servicios', text: 'Para vender un servicio sin cita. No ocupa un horario en la agenda.' },
        { title: 'Productos', text: 'Muestra el stock de la sede. Un producto sin stock dice "Agotado".', module: 'inventory' },
        { title: 'Paquetes', text: `Para vender un paquete. Si sus sesiones van con fecha, elige primero a ${theClient} y pulsa "Agendar sesiones".`, module: 'packages' },
        { title: 'Agendar para ahora', text: 'Para quien llegó sin cita: lo deja en la agenda ahora mismo o en el próximo horario libre, y lo pasa al carrito.', perm: 'agenda.create', module: 'agenda' },
        { title: 'Carrito', text: `Elige ${theClient} (si no, la boleta sale a nombre de "Cliente varios"), cambia cantidades, indica quién atendió y aplica un cupón.` },
        { title: 'Quién atendió o vendió', text: 'Con Comisiones encendido, cada servicio y producto puede llevar a quién lo atendió o vendió. Si falta, el carrito avisa: esa línea no genera comisión.', module: 'commissions' },
        { title: 'Descuento a mano', text: 'Baja el total con un monto y un motivo, por ejemplo «cliente frecuente». Cada quien descuenta hasta el tope que puso la empresa; para más hace falta el permiso «Descuento alto» (lo tienen el gerente y la administración).', perm: 'pos.create' },
        { title: 'Descuento por línea', text: 'Cada servicio, producto o cita del carrito tiene «Desc. %»: descuenta ese porcentaje solo de esa línea. Suma con el descuento a mano para el tope. Una línea con descuento propio no lleva el beneficio de la membresía.', perm: 'pos.create' },
        { title: 'Pedir autorización', text: 'Si el descuento pasa de tu tope, aparece «Pedir autorización»: quien tiene «Descuento alto» escribe su correo y su contraseña en tu pantalla y el descuento queda autorizado para esa venta por unos minutos. Queda en la auditoría con su nombre.', perm: 'pos.create' },
        { title: 'Grupos y puntos del cliente', text: `Al elegir a ${theClient}, debajo salen sus grupos (VIP, frecuente, nuevo…), sus puntos con su valor y su nivel, y si debe algo.` },
        { title: 'Propina', text: 'Si tu empresa recibe propinas, el carrito tiene el campo «Propina». Se suma a lo que paga el cliente, pero no lleva IGV, no va en la boleta y no cuenta para comisiones.' },
        { title: 'Dejar por cobrar', text: 'Guarda la venta sin cobrarla, para cobrarla después desde aquí o desde Ventas.', perm: 'pos.create' },
        { title: 'Cobrar', text: 'Abre el cobro: medio de pago, monto, vuelto, número de operación y comprobante.', perm: 'sales.create' },
        { title: 'Agendar la próxima cita', text: `Al terminar de cobrar un servicio aparece "Agendar la próxima cita": abre la agenda con la cita nueva ya armada con ${theClient}, el servicio y quien atendió. Si el servicio tiene «Recordar volver en N días», el aviso dice qué día le conviene volver.`, perm: 'agenda.create', module: 'agenda' },
        { title: 'Gift cards', text: 'Para vender una gift card: elige un monto rápido (50 a 300) o escribe otro desde 10 y, si quieres, para quién es. Se cobra como cualquier venta, con su comprobante, y el código se crea al quedar pagada. Si se devuelve la venta y la gift card no se usó, se anula.', module: 'loyalty' },
      ],
      tasks: [
        {
          title: 'Cobrar una cita',
          perm: 'sales.create',
          steps: [
            'En "Por cobrar", pulsa "Agregar" en la cita.',
            'Pulsa "Cobrar".',
            'Elige el medio de pago. En efectivo escribe cuánto te dio y el sistema calcula el vuelto; con tarjeta, transferencia, Yape o Plin, escribe el número de operación.',
            { text: 'Elige boleta o factura. Para factura hacen falta el RUC y la razón social.', module: 'invoicing' },
            'Pulsa "Cobrar". La cita queda pagada.',
          ],
        },
        { title: 'Vender un producto', perm: 'sales.create', module: 'inventory', steps: ['Entra a la pestaña "Productos" y pulsa "Agregar".', `Si quieres, elige a ${theClient} y escribe un cupón.`, 'Pulsa "Cobrar" y termina el cobro.'] },
        { title: 'Vender una gift card', perm: 'sales.create', module: 'loyalty', steps: ['Entra a la pestaña "Gift cards".', 'Escribe el monto y, si quieres, el nombre y el correo de quien la recibe.', 'Pulsa "Agregar al carrito" y cobra.', 'Entrega el código que aparece al terminar.'] },
        { title: 'Agendar la próxima cita al cobrar', perm: 'agenda.create', module: 'agenda', steps: ['Cobra la venta con el servicio.', 'Pulsa "Agendar la próxima cita".', 'Elige el día y la hora y guarda la cita.'] },
        { title: 'Atender a quien llegó sin cita', perm: 'agenda.create', module: 'agenda', steps: ['Pulsa "Agendar para ahora".', 'Elige el servicio y pulsa "Agendar y pasar al carrito".', 'Cobra como cualquier cita.'] },
        { title: 'Enviar un link de pago', perm: 'sales.create', steps: ['Pulsa "Cobrar" y elige "Link de pago".', 'Pulsa "Crear link de pago".', 'Pulsa "Enviar por correo", o "Copiar" para mandarlo por WhatsApp.', 'Cuando pague, la venta queda pagada sola. "¿Ya pagó?" lo revisa al momento.'] },
      ],
      rules: [
        { title: 'Precios con IGV', text: 'Si los precios ya incluyen el IGV, el total no cambia; si no, el IGV se suma al precio.', where: 'Configuración → Ventas y caja' },
        { title: 'Pagos por partes', text: 'Apagado, se cobra el total de una vez. Encendido, se puede cobrar una parte y dejar saldo.', where: 'Configuración → Ventas y caja' },
        { title: 'Tope de descuento a mano', text: 'Qué porcentaje de la venta puede descontar cualquiera que arme ventas. Con 0 (así viene) solo quien tiene «Descuento alto».', where: 'Configuración → Ventas y caja' },
        { title: 'Propinas', text: 'Encendido, aparece el campo «Propina» en el carrito. Algunos rubros, como peluquería y barbería, lo traen encendido.', where: 'Configuración → Ventas y caja' },
        { title: 'Caja abierta para el efectivo', text: 'Para cobrar en efectivo, la caja de la sede debe estar abierta.', where: 'Configuración → Ventas y caja' },
        { title: 'Vencimiento de los links de pago', text: 'Un link de pago deja de valer pasadas unas horas.', where: 'Configuración → Adelantos y links de pago' },
        { title: 'Boleta desde S/ 700 con DNI', text: 'Una boleta de 700 o más necesita el documento de quien compra. Si falta, el cobro no se registra y te pide agregarlo en la ficha del cliente.' },
        { title: 'Comprobantes ante SUNAT', text: 'Si tu empresa conectó Nubefact, las boletas, facturas y notas de crédito se envían solas a SUNAT; si no, salen marcadas como simulación. Sin facturación electrónica se emite un comprobante interno.', where: 'Configuración → Integraciones' },
        { title: 'Links de pago con Culqi o Mercado Pago', text: 'Los links de pago se cobran con la cuenta de Culqi de tu empresa, o con Mercado Pago si es la que conectaste (si están las dos, Culqi primero y Mercado Pago si Culqi no responde). Sin ninguna conectada, no aparece la opción Link de pago. Yape, Plin, tarjeta y transferencia en la sede se cobran fuera del sistema y aquí solo se anota la operación.', where: 'Configuración → Integraciones' },
      ],
    },

    '/sales': {
      summary: 'Es la lista de todas las ventas, con lo pagado, el saldo y su comprobante. Desde una venta se cobra lo que falta, se imprime, se envía por correo, se devuelve o se anula.',
      points: [
        { title: 'Pestañas', text: 'Todas, por cobrar, pagadas, con devolución y anuladas. Si tu empresa cobra al final, aparece además "Atendidas sin cobrar".' },
        { title: 'Tarjetas', text: 'Lo vendido hoy, lo que está por cobrar y lo devuelto en los últimos 30 días.' },
        { title: 'En la venta', text: 'Arriba, la barra muestra en qué va el cobro: por cobrar, pago parcial o pagada. Si es una penalidad por no asistir o cancelar tarde, un aviso explica por qué se cobra. Con Comisiones encendido, se ve cuánto gana cada uno por esa venta.' },
        { title: 'Nueva venta', text: 'Lleva al punto de venta.', perm: 'pos.create' },
        { title: 'Cobrar el saldo', text: 'En una venta por cobrar o con pago parcial, cobra lo que falta.', perm: 'sales.create' },
        { title: 'Imprimir o enviar', text: 'Imprime el comprobante o lo manda por correo a quien compró.' },
        { title: 'Cambiar en el punto de venta', text: 'Una venta sin pagos se puede cambiar en el punto de venta.', perm: 'pos.edit' },
        { title: 'Devolver', text: 'Devuelve todo o una parte de lo pagado, en efectivo desde la caja o por otro medio. Si tenía comprobante, se emite una nota de crédito.', perm: 'sales.delete' },
        { title: 'Devoluciones que se aprueban', text: 'Si tu empresa lo pidió, una devolución de más de cierto monto queda pedida: otra persona con el permiso "Aprobar devoluciones" la aprueba (y se registra en ese momento) o la rechaza con un motivo. Quien la pidió recibe un aviso.', perm: 'sales.delete|sales.approve' },
        { title: 'Anular', text: 'Solo una venta sin dinero cobrado. La cita vuelve a "por cobrar", el cupón recupera su uso y, si una cita de la venta había usado una sesión de un paquete, la sesión vuelve al paquete.', perm: 'sales.delete' },
        { title: 'Exportar', text: 'Descarga a Excel las ventas que estás viendo.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Devolver dinero', perm: 'sales.delete', steps: ['Abre la venta.', 'Pulsa "Devolver".', 'Escribe el monto, el motivo y cómo se devuelve.', 'Pulsa "Devolver". Si es en efectivo, sale de la caja abierta.'] },
        { title: 'Anular una venta sin pagos', perm: 'sales.delete', steps: ['Abre la venta.', 'Pulsa "Anular venta", escribe el motivo y pulsa "Anular la venta".', 'Si ya tenía dinero cobrado, no se anula: se devuelve.'] },
      ],
      rules: [
        { title: 'Plazo para devolver', text: 'Una venta se puede devolver hasta cierta cantidad de días después.', where: 'Configuración → Ventas y caja' },
        { title: 'Aprobar devoluciones grandes', text: 'Desde qué monto una devolución la pide una persona y la aprueba otra. Con 0 (así viene) cada quien devuelve directo.', where: 'Configuración → Ventas y caja' },
        { title: 'Cobrar antes de atender', text: 'Si está apagado, las citas atendidas sin pagar aparecen en "Atendidas sin cobrar".', where: 'Configuración → Ventas y caja' },
      ],
    },

    '/payments': {
      summary: 'Es el registro de cada pago y cada devolución, para saber cuánto entró por cada medio: efectivo, tarjeta, transferencia, Yape o Plin. Solo se consulta.',
      points: [
        { title: 'Pestañas', text: 'Todos, cobros y devoluciones.' },
        { title: 'Tarjetas', text: 'Cobrado hoy, devuelto hoy y neto de hoy.' },
        { title: 'Cobrado hoy por medio de pago', text: 'Arriba de la lista, una barra por cada medio (efectivo, tarjeta, Yape…) con lo cobrado hoy y cuántos pagos fueron.' },
        { title: 'Filtros', text: 'Por medio de pago, sede y fecha. Busca por venta, nombre, referencia o quién lo registró.' },
        { title: 'Exportar', text: 'El Excel incluye quién lo registró, la referencia, la sede y la caja.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Saber cuánto entró por Yape hoy', steps: ['Deja el periodo en hoy.', 'En "Medio" elige Yape.', 'Mira el total en las tarjetas de arriba.'] },
      ],
    },

    '/invoices': {
      summary: 'Todos los comprobantes emitidos en tus ventas: boletas, facturas, tickets y notas de crédito, con su estado ante SUNAT.',
      points: [
        { title: 'Por enviar o rechazados', text: 'Pendiente de envío: el proveedor no respondió y el sistema lo vuelve a intentar solo. Esperando respuesta de SUNAT: ya llegó al proveedor y falta la confirmación. Rechazado: SUNAT dijo que no; lee el motivo, anúlalo y emite otro. Si quieres, ábrelo y pulsa "Volver a enviar". Aceptado con observaciones: SUNAT lo dio por válido pero anotó algo para corregir en los próximos (por ejemplo, la dirección); lee la observación en «Respuesta».' },
        { title: 'PDF, XML y constancia', text: 'Con Nubefact conectado, el detalle del comprobante tiene los enlaces al PDF, al XML y a la constancia de SUNAT (CDR). Por seguridad, cada enlace sirve media hora; si ya no abre, recarga la página.' },
        { title: 'Anular', text: 'Para un comprobante emitido por error (por ejemplo, con el nombre mal escrito). Con Nubefact, dentro de los 7 días siguientes a la emisión se comunica la baja a SUNAT; después, se emite una nota de crédito por el total. La venta y su pago no cambian; luego emites uno nuevo desde la venta. No se anula si la venta ya tiene devoluciones con nota de crédito.', perm: 'sales.delete' },
        { title: 'Prueba', text: 'Mientras no haya un proveedor de facturación conectado, los comprobantes son de prueba y no llegan a SUNAT. Se conecta en Configuración → Integraciones.' },
      ],
      tasks: [
        { title: 'Corregir una boleta con datos mal escritos', perm: 'sales.delete', steps: ['Busca la boleta por su número o el nombre.', 'Ábrela y pulsa "Anular comprobante". Elige o escribe el motivo y confirma.', 'Corrige los datos del cliente y emite el comprobante otra vez desde la venta.'] },
      ],
    },

    '/payments/vouchers': {
      summary: 'Aquí llegan los comprobantes de transferencia, Yape o Plin que mandan los clientes al pagar un link, un adelanto o un paquete en línea. Al aprobarlo, el pago queda registrado como si hubiera entrado por la pasarela.',
      points: [
        { title: 'Por revisar', text: 'El número del menú dice cuántos esperan. También llega un aviso a quien tiene el permiso "Revisar comprobantes".' },
        { title: 'Horario apartado', text: 'En un adelanto, el horario queda apartado mientras revisas, hasta las horas que elegiste en Configuración. En la agenda se ve en ámbar como «Reserva por confirmar» con el nombre del cliente. Si se pasa ese tiempo y alguien toma el horario, no se puede aprobar.' },
        { title: 'Rechazar', text: 'El cliente lee el motivo tal cual y puede enviar otro comprobante o pagar de otra forma.' },
        { title: 'Devoluciones', text: 'Un pago aprobado por comprobante se devuelve a mano: devuélvelo tú al cliente y regístralo como devolución en efectivo o transferencia.' },
      ],
      tasks: [
        { title: 'Aprobar un comprobante', perm: 'payments.review', steps: ['Abre el comprobante de la lista.', 'Revisa en tu banco o app que el abono esté, con el monto y el número de operación.', 'Pulsa "Aprobar: el abono está en la cuenta".'] },
        { title: 'Rechazar un comprobante', perm: 'payments.review', steps: ['Abre el comprobante.', 'Pulsa "Rechazar".', 'Elige o escribe el motivo y pulsa "Rechazar".'] },
      ],
      rules: [
        { title: 'Recibir comprobantes', text: 'Enciéndelo y escribe tus cuentas, Yape o Plin, las horas que se aparta el horario y cuánto demoran en revisar.', where: 'Configuración → Integraciones' },
      ],
    },

    '/cash': {
      summary: 'Lleva el efectivo de cada caja por turno: con cuánto se abre, lo cobrado en efectivo, los gastos y retiros, y el cierre, comparando lo contado con lo que debería haber.',
      points: [
        { title: 'Abrir caja', text: 'Al empezar el turno, con el efectivo que hay para dar vuelto.', perm: 'cash.edit' },
        { title: 'Debe haber en efectivo', text: 'Lo de la apertura más los cobros en efectivo, menos las salidas.' },
        { title: 'Movimiento', text: 'Para poner dinero, pagar un gasto o retirar efectivo, con su motivo. Los cobros a clientes entran solos, no se anotan aquí.', perm: 'cash.edit' },
        { title: 'Cerrar caja', text: 'Cuenta el efectivo y escríbelo, o pulsa «Contar billetes y monedas» y anota cuántos hay de cada uno: el total se suma solo. El sistema dice si cuadra, si falta o si sobra.', perm: 'cash.edit' },
        { title: 'Turno de un día anterior', text: 'Si una caja sigue abierta desde otro día, la tarjeta lo avisa en ámbar: ciérrala con el conteo y abre un turno nuevo.' },
        { title: 'Turnos cerrados', text: 'Cada cierre con su resultado: cuadró, faltaron o sobraron.' },
        { title: 'Cada quien su turno', text: 'Sin el permiso "Turnos de otros", cada persona ve y cierra solo el turno que abrió. Una caja abierta por otra persona sale con su nombre, sin montos. El gerente trae ese permiso de fábrica.' },
        { title: 'Nueva caja', text: 'Para agregar otra caja en una sede.', perm: 'cash.create' },
        { title: 'Cierres en revisión', text: 'Si tu empresa lo pidió, un cierre con una diferencia mayor a cierto monto queda "En revisión". Quien tiene el permiso "Aprobar cierres" recibe un aviso, abre el turno y lo aprueba u observa con una nota. La tarjeta "En revisión" filtra los pendientes.', perm: 'cash.edit|cash.approve' },
      ],
      tasks: [
        { title: 'Abrir la caja', perm: 'cash.edit', steps: ['Pulsa "Abrir caja".', 'Escribe con cuánto efectivo empiezas.', 'Pulsa "Abrir caja".'] },
        { title: 'Pagar un gasto con la caja', perm: 'cash.edit', steps: ['Pulsa "Movimiento".', 'Elige "Gasto", escribe el monto y el motivo.', 'Pulsa "Registrar".'] },
        { title: 'Cerrar la caja', perm: 'cash.edit', steps: ['Cuenta el efectivo.', 'Pulsa "Cerrar caja" y escribe lo contado.', 'Si hay una diferencia mayor a la permitida, explica qué pasó en "Observaciones".', 'Pulsa "Cerrar caja". La administración recibe un aviso si hubo diferencia.'] },
      ],
      rules: [
        { title: 'Diferencia permitida al cerrar', text: 'Hasta cuánto puede faltar o sobrar sin tener que explicarlo.', where: 'Configuración → Ventas y caja' },
        { title: 'Cierre en revisión', text: 'Desde qué diferencia un cierre queda en revisión hasta que alguien lo apruebe. Con 0 (así viene) no se pide revisión.', where: 'Configuración → Ventas y caja' },
        { title: 'Una caja por sede nueva', text: 'Cada sucursal nueva puede recibir su "Caja principal".', where: 'Configuración → Ventas y caja' },
      ],
    },

    '/commissions': {
      summary: `Lo que gana cada ${lower(vocab.pro)} por lo que atiende o vende, mes a mes. Se calcula solo al cobrar una venta y al usar una sesión de un paquete, según las reglas.`,
      points: [
        { title: 'Mes', text: 'Arriba eliges qué mes ver. Cada comisión cuenta en el mes en que se cobró la venta o se usó la sesión.' },
        { title: 'Por aprobar, aprobadas y pagadas', text: 'Cada comisión empieza por aprobar; el administrador la aprueba y, cuando le paga, la marca como pagada. Las tarjetas suman cada estado.' },
        { title: 'Devoluciones', text: 'Si se devuelve dinero de una venta, su comisión baja en la misma proporción: aparece una línea en negativo con "Devolución". Si ya estaba pagada, se descuenta en el mes siguiente.' },
        { title: 'Aprobar y marcar como pagadas', text: 'Marca las comisiones en la lista y usa los botones que aparecen abajo. El pago se hace por fuera del sistema; aquí solo queda anotado.', perm: 'commissions.approve|commissions.pay' },
        { title: 'Recalcular el mes', text: 'Vuelve a calcular, con las reglas de hoy, las comisiones por aprobar del mes. Las aprobadas y pagadas no cambian.', perm: 'commissions.rules' },
        { title: `Resumen por ${lower(vocab.pro)}`, text: 'Cuánto lleva cada uno en el mes. El nombre filtra la lista.' },
        { title: 'Solo lo suyo', text: `Un ${lower(vocab.pro)} con "solo lo suyo" ve solo sus comisiones, sin poder cambiarlas.` },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Pagar las comisiones del mes', perm: 'commissions.pay', steps: ['Elige el mes.', 'En "Por aprobar", marca las comisiones y pulsa "Aprobar".', 'En "Aprobadas", marca las que pagaste y pulsa "Marcar como pagadas".'] },
      ],
      rules: [
        { title: 'Sobre qué monto se calcula', text: 'Lo cobrado sin IGV (por defecto) o el precio de lista.', where: 'Configuración → Comisiones' },
        { title: 'Productos, sesiones de paquetes y aprobar solas', text: 'Si se comisionan los productos y las sesiones de paquetes, y si las comisiones se aprueban solas.', where: 'Configuración → Comisiones' },
      ],
    },

    '/commissions/rules': {
      summary: `Aquí se decide cuánto gana cada ${lower(vocab.pro)}, en cuatro listas que van de lo general a lo particular. Nunca hay dos comisiones para el mismo caso.`,
      points: [
        { title: '¿Cuánto gana?', text: `Elige a un ${lower(vocab.pro)} y un servicio o producto: te dice qué comisión se le aplica, por qué y cuánto gana.` },
        { title: `1. Lo que gana cada ${lower(vocab.pro)}`, text: 'Su porcentaje en todos sus servicios, salvo los de las listas 2, 3 y 4. Vacío, no gana nada por ellos.' },
        { title: '2. Categorías con otra comisión', text: `Para todos los servicios de esa categoría, por ejemplo todos los de Color. Reemplaza el porcentaje de cada ${lower(vocab.pro)}. Si un servicio de la categoría tiene su propia comisión, manda la del servicio. Cada categoría aparece una sola vez.` },
        { title: '3. Servicios con otra comisión', text: `Para todos los que atienden ese servicio. Reemplaza la comisión de su categoría y el porcentaje de cada ${lower(vocab.pro)}. Cada servicio aparece una sola vez.` },
        { title: '4. Excepciones de una persona', text: 'Solo para una persona en un servicio. Reemplaza todo lo demás. Cada persona con cada servicio aparece una sola vez.' },
        { title: 'Porcentaje o monto fijo', text: 'Cada comisión puede dar un porcentaje, un monto fijo por atención o por unidad, o los dos.' },
        { title: 'Productos', text: 'Una comisión para todos los productos y, si hace falta, otra para un producto en particular. La venta debe decir quién lo vendió.', module: 'inventory' },
        { title: 'Apagar o quitar', text: 'Apagada o quitada, se usa lo que sigue en la lista: la comisión del servicio, la de su categoría o su porcentaje.', perm: 'commissions.rules' },
      ],
      tasks: [
        { title: 'Dar otra comisión a una categoría', perm: 'commissions.rules', steps: ['En la lista 2, pulsa "Agregar categoría".', 'Elige la categoría (con el + puedes crear una nueva) y escribe el porcentaje, el monto fijo o los dos.', 'Pulsa "Agregar".'] },
        { title: 'Dar otra comisión a un servicio', perm: 'commissions.rules', steps: ['En la lista 3, pulsa "Agregar servicio".', 'Elige el servicio y escribe el porcentaje, el monto fijo o los dos.', 'Pulsa "Agregar".'] },
        { title: 'Agregar una excepción', perm: 'commissions.rules', steps: ['En la lista 4, pulsa "Agregar excepción".', `Elige al ${lower(vocab.pro)} y el servicio, y escribe cuánto gana.`, 'Pulsa "Agregar".'] },
        { title: `Cambiar el porcentaje de un ${lower(vocab.pro)}`, perm: 'commissions.rules', steps: ['En la lista 1, escribe el nuevo porcentaje.', 'Pulsa Enter o sal del campo: se guarda solo.'] },
      ],
    },

    '/coupons': {
      summary: `Son los códigos de descuento que ${theClient} da al pagar, por ejemplo BIENVENIDA10. Se escriben en el campo "Cupón" del punto de venta o del cobro.`,
      points: [
        { title: 'Pestañas', text: 'Todos, vigentes y los que ya no valen.' },
        { title: 'Estados', text: 'Vigente, aún no empieza, vencido, agotado (ya se usó todas las veces) o desactivado.' },
        { title: 'Nuevo cupón', text: 'Con su código, el descuento en porcentaje o en monto, la compra mínima, las fechas y cuántas veces se puede usar.', perm: 'coupons.create' },
        { title: 'Editar o eliminar', text: 'Cambia un cupón o lo quita. Para pausarlo sin borrarlo, apaga "Activo".', perm: 'coupons.edit|coupons.delete' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Crear un cupón', perm: 'coupons.create', steps: ['Pulsa "Nuevo cupón".', 'Escribe el código y para qué es.', 'Elige si descuenta un porcentaje o un monto, y desde qué compra.', 'Indica las fechas y cuántas veces se puede usar, y guarda.'] },
        { title: 'Usar un cupón al cobrar', steps: ['En el carrito, escribe el código en "Cupón".', 'Pulsa "Aplicar": el total baja.'] },
      ],
    },
  };
};
