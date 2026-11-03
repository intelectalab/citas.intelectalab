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
        { title: 'Dejar por cobrar', text: 'Guarda la venta sin cobrarla, para cobrarla después desde aquí o desde Ventas.', perm: 'pos.create' },
        { title: 'Cobrar', text: 'Abre el cobro: medio de pago, monto, vuelto, número de operación y comprobante.', perm: 'sales.create' },
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
        { title: 'Atender a quien llegó sin cita', perm: 'agenda.create', module: 'agenda', steps: ['Pulsa "Agendar para ahora".', 'Elige el servicio y pulsa "Agendar y pasar al carrito".', 'Cobra como cualquier cita.'] },
        { title: 'Enviar un link de pago', perm: 'sales.create', steps: ['Pulsa "Cobrar" y elige "Link de pago".', 'Pulsa "Crear link de pago".', 'Pulsa "Enviar por correo", o "Copiar" para mandarlo por WhatsApp.', 'Cuando pague, la venta queda pagada sola. "¿Ya pagó?" lo revisa al momento.'] },
      ],
      rules: [
        { title: 'Precios con IGV', text: 'Si los precios ya incluyen el IGV, el total no cambia; si no, el IGV se suma al precio.', where: 'Configuración → Ventas y caja' },
        { title: 'Pagos por partes', text: 'Apagado, se cobra el total de una vez. Encendido, se puede cobrar una parte y dejar saldo.', where: 'Configuración → Ventas y caja' },
        { title: 'Caja abierta para el efectivo', text: 'Para cobrar en efectivo, la caja de la sede debe estar abierta.', where: 'Configuración → Ventas y caja' },
        { title: 'Vencimiento de los links de pago', text: 'Un link de pago deja de valer pasadas unas horas.', where: 'Configuración → Adelantos y links de pago' },
        { title: 'Comprobantes simulados', text: 'Las boletas y facturas todavía no se envían a SUNAT: salen marcadas como simulación. Sin facturación electrónica se emite un comprobante interno.' },
        { title: 'Links de pago con Mercado Pago', text: 'Los links de pago se cobran con la cuenta de Mercado Pago de tu empresa. Sin ella conectada, no aparece la opción Link de pago. Yape, Plin, tarjeta y transferencia en la sede se cobran fuera del sistema y aquí solo se anota la operación.', where: 'Configuración → Integraciones' },
      ],
    },

    '/sales': {
      summary: 'Es la lista de todas las ventas, con lo pagado, el saldo y su comprobante. Desde una venta se cobra lo que falta, se imprime, se envía por correo, se devuelve o se anula.',
      points: [
        { title: 'Pestañas', text: 'Todas, por cobrar, pagadas, con devolución y anuladas. Si tu empresa cobra al final, aparece además "Atendidas sin cobrar".' },
        { title: 'Nueva venta', text: 'Lleva al punto de venta.', perm: 'pos.create' },
        { title: 'Cobrar el saldo', text: 'En una venta por cobrar o con pago parcial, cobra lo que falta.', perm: 'sales.create' },
        { title: 'Imprimir o enviar', text: 'Imprime el comprobante o lo manda por correo a quien compró.' },
        { title: 'Cambiar en el punto de venta', text: 'Una venta sin pagos se puede cambiar en el punto de venta.', perm: 'pos.edit' },
        { title: 'Devolver', text: 'Devuelve todo o una parte de lo pagado, en efectivo desde la caja o por otro medio. Si tenía comprobante, se emite una nota de crédito.', perm: 'sales.delete' },
        { title: 'Anular', text: 'Solo una venta sin dinero cobrado. La cita vuelve a "por cobrar" y el cupón recupera su uso.', perm: 'sales.delete' },
        { title: 'Exportar', text: 'Descarga a Excel las ventas que estás viendo.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Devolver dinero', perm: 'sales.delete', steps: ['Abre la venta.', 'Pulsa "Devolver".', 'Escribe el monto, el motivo y cómo se devuelve.', 'Pulsa "Devolver". Si es en efectivo, sale de la caja abierta.'] },
        { title: 'Anular una venta sin pagos', perm: 'sales.delete', steps: ['Abre la venta.', 'Pulsa "Anular venta", escribe el motivo y pulsa "Anular la venta".', 'Si ya tenía dinero cobrado, no se anula: se devuelve.'] },
      ],
      rules: [
        { title: 'Plazo para devolver', text: 'Una venta se puede devolver hasta cierta cantidad de días después.', where: 'Configuración → Ventas y caja' },
        { title: 'Cobrar antes de atender', text: 'Si está apagado, las citas atendidas sin pagar aparecen en "Atendidas sin cobrar".', where: 'Configuración → Ventas y caja' },
      ],
    },

    '/payments': {
      summary: 'Es el registro de cada pago y cada devolución, para saber cuánto entró por cada medio: efectivo, tarjeta, transferencia, Yape o Plin. Solo se consulta.',
      points: [
        { title: 'Pestañas', text: 'Todos, cobros y devoluciones.' },
        { title: 'Tarjetas', text: 'Cobrado hoy, devuelto hoy y neto de hoy.' },
        { title: 'Filtros', text: 'Por medio de pago, sede y fecha. Busca por venta, nombre, referencia o quién lo registró.' },
        { title: 'Exportar', text: 'El Excel incluye quién lo registró, la referencia, la sede y la caja.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Saber cuánto entró por Yape hoy', steps: ['Deja el periodo en hoy.', 'En "Medio" elige Yape.', 'Mira el total en las tarjetas de arriba.'] },
      ],
    },

    '/cash': {
      summary: 'Lleva el efectivo de cada caja por turno: con cuánto se abre, lo cobrado en efectivo, los gastos y retiros, y el cierre, comparando lo contado con lo que debería haber.',
      points: [
        { title: 'Abrir caja', text: 'Al empezar el turno, con el efectivo que hay para dar vuelto.', perm: 'cash.edit' },
        { title: 'Debe haber en efectivo', text: 'Lo de la apertura más los cobros en efectivo, menos las salidas.' },
        { title: 'Movimiento', text: 'Para poner dinero, pagar un gasto o retirar efectivo, con su motivo. Los cobros a clientes entran solos, no se anotan aquí.', perm: 'cash.edit' },
        { title: 'Cerrar caja', text: 'Cuenta el efectivo y escríbelo. El sistema dice si cuadra, si falta o si sobra.', perm: 'cash.edit' },
        { title: 'Turnos cerrados', text: 'Cada cierre con su resultado: cuadró, faltaron o sobraron.' },
        { title: 'Cada quien su turno', text: 'Sin el permiso "Turnos de otros", cada persona ve y cierra solo el turno que abrió. Una caja abierta por otra persona sale con su nombre, sin montos. El gerente trae ese permiso de fábrica.' },
        { title: 'Nueva caja', text: 'Para agregar otra caja en una sede.', perm: 'cash.create' },
      ],
      tasks: [
        { title: 'Abrir la caja', perm: 'cash.edit', steps: ['Pulsa "Abrir caja".', 'Escribe con cuánto efectivo empiezas.', 'Pulsa "Abrir caja".'] },
        { title: 'Pagar un gasto con la caja', perm: 'cash.edit', steps: ['Pulsa "Movimiento".', 'Elige "Gasto", escribe el monto y el motivo.', 'Pulsa "Registrar".'] },
        { title: 'Cerrar la caja', perm: 'cash.edit', steps: ['Cuenta el efectivo.', 'Pulsa "Cerrar caja" y escribe lo contado.', 'Si hay una diferencia mayor a la permitida, explica qué pasó en "Observaciones".', 'Pulsa "Cerrar caja". La administración recibe un aviso si hubo diferencia.'] },
      ],
      rules: [
        { title: 'Diferencia permitida al cerrar', text: 'Hasta cuánto puede faltar o sobrar sin tener que explicarlo.', where: 'Configuración → Ventas y caja' },
        { title: 'Una caja por sede nueva', text: 'Cada sucursal nueva puede recibir su "Caja principal".', where: 'Configuración → Ventas y caja' },
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
