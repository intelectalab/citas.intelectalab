import { lower, type HelpTopics } from '../types';

export const catalogoTopics: HelpTopics = (vocab) => {
  const c = lower(vocab.client);
  const cs = lower(vocab.clients);
  const theClient = `${vocab.pick('client', 'el', 'la')} ${c}`;
  const aClient = `${vocab.pick('client', 'un', 'una')} ${c}`;

  return {
    '/products': {
      summary: 'Es el catálogo de productos: los que vendes, los que usas en las atenciones, o ambos. Muestra el stock de cada sede y avisa cuando algo baja del mínimo o se agota.',
      points: [
        { title: 'Nuevo producto', text: 'Con nombre, precio, código, para qué es (se vende, uso interno o ambos), categoría, costo y stock mínimo.', perm: 'products.create' },
        { title: 'Stock desde la primera entrada', text: 'Un producto sin entradas dice "Sin control". Empieza a llevar stock al registrar su primera entrada.' },
        { title: 'Tarjetas', text: 'Con stock al día, bajo el mínimo, agotados y valor en almacén.' },
        { title: 'Registrar movimiento', text: 'Entrada de unidades, salida o merma.', perm: 'inventory.create' },
        { title: 'Conteo físico', text: 'Escribe lo que contaste en el estante y el sistema ajusta la diferencia.', perm: 'inventory.edit' },
        { title: 'Ver su kárdex', text: 'Abre todos los movimientos de ese producto.' },
        { title: 'Editar o eliminar', text: 'Para dejar de venderlo sin perder su historia, apaga "Activo". Si ya se vendió, eliminarlo solo lo quita de la lista.', perm: 'products.edit|products.delete' },
      ],
      tasks: [
        { title: 'Crear un producto', perm: 'products.create', steps: ['Pulsa "Nuevo producto".', 'Escribe el nombre y el precio, y elige para qué es.', 'Pulsa "Crear producto".'] },
        { title: 'Cargar el stock inicial', perm: 'inventory.create', steps: ['Pulsa "Registrar movimiento" y elige "Entrada de unidades".', 'Elige la sede y el producto, y el motivo "Carga inicial de inventario".', 'Escribe la cantidad y el costo unitario.', 'Pulsa "Registrar movimiento".'] },
        { title: 'Contar lo que hay en el estante', perm: 'inventory.edit', steps: ['Pulsa "Conteo físico".', 'Elige la sede y el producto, y escribe las unidades contadas.', 'Pulsa "Aplicar conteo".'] },
      ],
      rules: [
        { title: 'Vender sin stock', text: 'Si está permitido, se puede vender aunque el sistema diga que no hay.', where: 'Configuración → Inventario y almacenes' },
        { title: 'Aviso de stock mínimo', text: 'Avisa cuando un producto baja de su mínimo.', where: 'Configuración → Inventario y almacenes' },
        { title: 'Insumos de los servicios', text: 'Lo que cada servicio gasta se define en el servicio, y se descuenta al dar por atendida la cita.', where: 'Configuración → Inventario y almacenes' },
      ],
    },

    '/inventory/kardex': {
      summary: 'Es el registro de cada movimiento de stock: entradas, compras, ventas, consumo en atenciones, ajustes y traslados, con el saldo que quedó y quién lo hizo.',
      points: [
        { title: 'Pestañas', text: 'Todos, entradas, salidas y consumos, y traslados (si tienes varias sedes).' },
        { title: 'Filtros', text: 'Por sede, producto, tipo de movimiento y fecha.' },
        { title: 'Registrar movimiento', text: 'Una entrada, una salida o una merma.', perm: 'inventory.create' },
        { title: 'Exportar', text: 'Descarga a Excel los movimientos que estás viendo.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Ver la historia de un producto', steps: ['Elige el producto en el filtro "Producto".', 'O, en Productos y stock, pulsa "Ver su kárdex" en su fila.'] },
        { title: 'Registrar algo que se rompió o venció', perm: 'inventory.create', steps: ['Pulsa "Registrar movimiento" y elige "Salida o merma".', 'Elige el tipo "Merma, vencimiento o ajuste" y escribe el motivo.', 'Pulsa "Registrar movimiento".'] },
      ],
    },

    '/inventory/transfers': {
      summary: 'Sirve para mover productos de una sede a otra. Si tu empresa lo usa en dos pasos, quedan "en tránsito" hasta que la sede que recibe lo confirma.',
      points: [
        { title: 'Pestañas', text: 'Todos, en tránsito, recibidos y cancelados.' },
        { title: 'Nuevo traslado', text: 'Con la sede de origen, la de destino, los productos y una nota o guía de remisión.', perm: 'inventory.create' },
        { title: 'Confirmar recepción', text: 'La sede que recibe confirma lo que llegó; puede ser menos de lo enviado.', perm: 'inventory.create|inventory.edit' },
        { title: 'Cancelar traslado', text: 'Mientras está en tránsito. Las unidades vuelven a la sede de origen.', perm: 'inventory.delete' },
      ],
      tasks: [
        { title: 'Enviar productos a otra sede', perm: 'inventory.create', steps: ['Pulsa "Nuevo traslado".', 'Elige la sede de origen y la de destino.', 'Agrega los productos y sus cantidades.', 'Pulsa "Enviar traslado".'] },
        { title: 'Recibir un traslado', perm: 'inventory.create|inventory.edit', steps: ['En "En tránsito", pulsa "Confirmar recepción".', 'Ajusta lo recibido si llegó menos.', 'Pulsa "Confirmar recepción".'] },
      ],
      rules: [
        { title: 'Traslados en dos pasos', text: 'Encendido, el stock queda en tránsito hasta que se confirma. Apagado, pasa de una sede a otra al momento.', where: 'Configuración → Inventario y almacenes' },
      ],
    },

    '/inventory/purchases': {
      summary: 'Son las órdenes de compra a tus proveedores. Al recibir una compra, entra el stock y se actualiza el costo del producto. Aquí también están los proveedores.',
      points: [
        { title: 'Pestañas', text: 'Todas, por recibir, recibidas y canceladas.' },
        { title: 'Nueva orden de compra', text: 'Con el proveedor, la sede que recibe, los productos y el comprobante. Puede ingresar al stock en ese momento o después.', perm: 'inventory.create' },
        { title: 'Recibir', text: 'Ingresa lo que llegó de una orden pendiente.', perm: 'inventory.edit' },
        { title: 'Cancelar orden', text: 'Para una orden que ya no llegará.', perm: 'inventory.delete' },
        { title: 'Proveedores', text: 'Con su RUC, teléfono, contacto y correo. Uno que ya tiene órdenes queda inactivo en vez de borrarse.' },
      ],
      tasks: [
        { title: 'Registrar una compra que ya llegó', perm: 'inventory.create', steps: ['Pulsa "Nueva orden de compra".', 'Elige el proveedor y la sede, y agrega los productos.', 'Enciende "Ingresar mercadería al stock ahora mismo".', 'Pulsa "Registrar compra e ingresar".'] },
        { title: 'Pedir ahora y recibir después', perm: 'inventory.create', steps: ['Crea la orden con el interruptor apagado.', 'Cuando llegue, pulsa "Recibir" en su fila.', 'Revisa las cantidades y pulsa "Confirmar ingreso".'] },
        { title: 'Agregar un proveedor', perm: 'inventory.create', steps: ['Pulsa "Proveedores".', 'Pulsa "Nuevo", llena sus datos y guarda.'] },
      ],
      rules: [
        { title: 'Costo al recibir', text: 'Se usa el último costo de compra, el costo promedio, o no se cambia.', where: 'Configuración → Inventario y almacenes' },
      ],
    },

    '/memberships': {
      summary: `Son planes que ${theClient} paga cada mes, trimestre, semestre o año y que le dan beneficios mientras estén vigentes: un descuento permanente y servicios incluidos en cada periodo. En "Planes" se definen; en "Socios" están las membresías vendidas.`,
      points: [
        { title: 'Tarjetas', text: 'Socios vigentes, ingreso recurrente al mes, las que vencen en 7 días y las vencidas.' },
        { title: 'Vender membresía', text: `Lleva al punto de venta, pestaña Membresías. Hay que elegir a ${theClient}: la membresía queda a su nombre al cobrarla.`, perm: 'pos.create', module: 'pos' },
        { title: 'Nuevo plan', text: 'Nombre, precio, cada cuánto se paga y sus beneficios: un descuento (solo en servicios o también en productos) y servicios incluidos por periodo.', perm: 'memberships.create' },
        { title: 'Beneficios al cobrar', text: `En el punto de venta, si ${theClient} tiene una membresía vigente, el carrito aplica solo el servicio incluido o el descuento. En cada línea puedes elegir "Cobrar sin membresía".` },
        { title: 'Todo incluido', text: 'Si la membresía cubre todo el carrito, el botón dice "Registrar (lo cubre la membresía)" y la venta queda registrada sin cobro.' },
        { title: 'Servicios incluidos', text: 'Se cuentan por periodo y se reinician al empezar el siguiente. Las sesiones no usadas no se acumulan.' },
        { title: 'Renovar', text: 'Vender el mismo plan a quien ya lo tiene vigente lo renueva: la fecha de vencimiento se corre un periodo más. También se renueva desde la página de la membresía.' },
        { title: 'En gracia', text: 'Cuando vence, sigue dando sus beneficios unos días más y se ve como "En gracia". Si la renueva en ese tiempo, el nuevo periodo cuenta desde su vencimiento. Pasados esos días queda "Vencida".', where: 'Configuración → Membresías' },
        { title: 'Pausar', text: 'Por un viaje o por salud. Mientras está pausada no da beneficios; al reactivarla, los días pausada se suman a su vencimiento.', perm: 'memberships.edit' },
        { title: 'Cancelar', text: 'Con un motivo. Deja de dar beneficios desde hoy y ya no se renueva. No devuelve dinero: para eso se devuelve la venta.', perm: 'memberships.delete' },
        { title: 'Cambiar de plan', text: `Vende el plan nuevo en el punto de venta: la línea dice "Cambio de plan" y, al cobrarla, la membresía anterior queda cancelada desde hoy. Lo que quedaba del plan anterior no se descuenta solo.` },
        { title: 'Devolver la venta', text: 'Si se devuelve la venta de una membresía, se cancela; si era una renovación, vuelve a su fecha anterior; si era un cambio de plan, la membresía anterior vuelve a valer.' },
        { title: 'Avisos', text: `La campana avisa cuando una membresía vence en 7 días o entra en gracia. A ${theClient} le llega el correo "Tu membresía vence pronto" si está encendido en Mensajes.` },
        { title: 'En el portal', text: `En su portal, pestaña "Membresías", ${theClient} ve hasta cuándo vale y lo que le queda en el periodo.` },
        { title: 'Lo vendido no cambia', text: 'Si editas un plan, las membresías ya vendidas conservan los beneficios con que se vendieron.' },
      ],
      tasks: [
        { title: 'Crear un plan', perm: 'memberships.create', steps: ['Entra a "Planes" y pulsa "Nuevo plan".', 'Escribe el nombre, el precio y elige cada cuánto se paga.', 'Pon el descuento y agrega los servicios incluidos por periodo.', 'Guarda.'] },
        { title: 'Vender una membresía', perm: 'pos.create', steps: ['Pulsa "Vender membresía" o abre el punto de venta, pestaña Membresías.', 'Elige al cliente y agrega el plan.', 'Cobra. La membresía queda vigente desde hoy.'] },
        { title: 'Cobrar con la membresía', steps: ['Agrega la cita o el servicio al carrito del punto de venta.', 'El beneficio se aplica solo y se ve en la línea.', 'Cobra o registra si quedó en cero.'] },
        { title: 'Ver los usos de una membresía', steps: ['En "Socios", pulsa el código o el ojo de la fila.', 'Ahí ves lo que le queda en el periodo, cada uso y su historial.'] },
        { title: 'Pausar y reactivar', perm: 'memberships.edit', steps: ['Abre la membresía y pulsa "Pausar".', 'Escribe el motivo, por ejemplo "viaja por un mes", y confirma.', 'Cuando vuelva, abre la membresía y pulsa "Reactivar".'] },
        { title: 'Cancelar una membresía', perm: 'memberships.delete', steps: ['Abre la membresía y pulsa "Cancelar membresía".', 'Escribe el motivo y confirma.'] },
        { title: 'Cambiar de plan', perm: 'pos.create', steps: ['En el punto de venta, elige al cliente y agrega el plan nuevo.', 'La línea dice "Cambio de plan" y qué membresía reemplaza.', 'Cobra. La anterior queda cancelada desde hoy.'] },
      ],
      rules: [
        { title: 'Días de gracia', text: 'Cuántos días sigue dando beneficios una membresía después de vencer. Por defecto, 5.', where: 'Configuración → Membresías' },
      ],
    },

    '/packages': {
      summary: `Son paquetes de sesiones pagadas por adelantado, por ejemplo 10 sesiones de fisioterapia. En "Catálogo" se definen; en "Paquetes de clientes" están los vendidos, con lo que le queda a cada ${c}.`,
      points: [
        { title: 'Tarjetas', text: 'Vigentes, sesiones por usar, por atender ya cobradas y los que vencen en 30 días.' },
        { title: 'Vender paquete', text: 'Lleva al punto de venta, donde se cobra.', perm: 'pos.create', module: 'pos' },
        { title: 'Nuevo paquete', text: 'Qué servicios y cuántas sesiones incluye, cuántos días vale y cómo se agenda.', perm: 'packages.create' },
        { title: 'Cómo se agenda', text: `Libre (${theClient} reserva cada sesión cuando quiere) o con fechas acordadas al venderlo, con su frecuencia.` },
        { title: 'Condiciones', text: 'Quién atiende, qué pasa si cancela tarde o no llega, y si se devuelve dinero. El texto que ve el cliente se arma solo o lo escribes tú.' },
        { title: 'Agendar sesiones', text: 'En un paquete con fechas, agenda de una vez las sesiones que faltan.', perm: 'agenda.create' },
        { title: 'Registrar uso sin cita', text: 'Descuenta una sesión atendida sin cita, con su motivo.', perm: 'packages.use' },
        { title: 'Dar más días', text: 'Amplía la vigencia de un paquete, con su motivo.', perm: 'packages.edit' },
        { title: 'Anular paquete', text: 'Termina un paquete vendido y devuelve dinero si corresponde, hasta lo que se pagó por él.', perm: 'packages.delete' },
        { title: 'Se vende en línea', text: `Un paquete de uso libre con esta casilla aparece en el portal: ${theClient} elige la sede, paga en línea y el paquete queda a su nombre. Te llega un aviso a la campana. Los de fechas acordadas se venden solo en la sede.`, perm: 'packages.create|packages.edit' },
        { title: 'Lo vendido no cambia', text: 'Si editas un paquete del catálogo, los que ya se vendieron, o están esperando su pago, conservan las sesiones y condiciones con que se vendieron.' },
      ],
      tasks: [
        { title: 'Crear un paquete', perm: 'packages.create', steps: ['Entra a "Catálogo" y pulsa "Nuevo paquete".', 'Elige los servicios, las sesiones y cuántos días vale.', 'Elige cómo se agenda y sus condiciones.', 'Si quieres venderlo en el portal, enciende "Se vende en línea".', 'Guarda.'] },
        { title: 'Usar una sesión del paquete', steps: ['Agenda la cita del servicio.', 'Al cobrar, elige "Usar paquete".', 'La sesión se descuenta al dar por atendida la cita.'] },
        { title: 'Anular un paquete vendido', perm: 'packages.delete', steps: ['Abre el paquete.', 'Pulsa "Anular paquete" y escribe el motivo.', 'Elige qué pasa con el dinero, el monto y cómo se devuelve.', 'Confirma. Si es en efectivo, hace falta la caja abierta.'] },
      ],
    },

    '/loyalty/points': {
      summary: `Son los puntos que ganan tus ${cs} al comprar y que luego canjean como descuento. Aquí ves el saldo de cada uno y toda su historia.`,
      points: [
        { title: 'Pestañas', text: 'Todos, con puntos, listos para canjear e historial.' },
        { title: 'Movimiento de puntos', text: 'Sumar, canjear, ajustar a mano o dar un premio por referido, con su motivo.', perm: 'loyalty.create' },
        { title: '+ Puntos y Canjear', text: `Atajos en la fila de cada ${c}.`, perm: 'loyalty.create|loyalty.edit' },
        { title: 'Historial', text: `Todo lo que ganó, canjeó o se le venció a ${aClient}.` },
        { title: 'Al cobrar', text: 'Los puntos se ganan solos al pagar y se canjean como medio de pago en el cobro.' },
        { title: 'Puntos vencidos', text: 'Cuando unos puntos llegan a su fecha de vencimiento dejan de sumar en el saldo: en el historial aparece "Vencimiento de puntos" y la compra que los dio se marca como "Vencidos". Se gastan primero los que vencen antes.' },
        { title: 'En el portal', text: `En su portal, pestaña "Mis beneficios", ${theClient} ve su saldo, sus movimientos y los puntos por vencer.` },
      ],
      tasks: [
        { title: 'Premiar a quien trajo a alguien', perm: 'loyalty.create', steps: ['Pulsa "Movimiento de puntos".', `Elige "Premio por referido", ${theClient} y los puntos.`, 'Escribe el motivo y pulsa "Guardar movimiento".'] },
        { title: 'Pagar con puntos', perm: 'sales.create', steps: ['En el cobro, elige "Puntos".', 'Se descuenta el valor de los puntos del total.'] },
      ],
      rules: [
        { title: 'Cómo se ganan y cuánto valen', text: 'Cada cuánto se gana un punto, cuánto vale al canjear, el mínimo para canjear y cuándo vencen.', where: 'Configuración → Puntos y gift cards' },
      ],
    },

    '/loyalty/gift-cards': {
      summary: 'Son tarjetas de regalo con saldo pagado por adelantado. Quien la recibe paga con su código en el cobro.',
      points: [
        { title: 'Pestañas', text: 'Todas, vigentes, por vencer, cerradas y movimientos.' },
        { title: 'Emitir gift card', text: 'Con el monto, un código (o uno automático), los días que vale, quién la compra y para quién es.', perm: 'loyalty.create' },
        { title: 'Copiar código', text: 'Para enviarlo a quien la recibe.' },
        { title: 'Descontar', text: 'Rebaja un monto del saldo a mano.', perm: 'loyalty.edit' },
        { title: 'Ampliar', text: 'Le da más días de vigencia.', perm: 'loyalty.edit' },
        { title: 'Anular', text: 'Deja la tarjeta sin uso.', perm: 'loyalty.delete' },
        { title: 'Vendidas en el punto de venta', text: 'Las que se venden en el punto de venta salen aquí con su venta. Se activan al pagarse y, si se devuelve la venta sin haberlas usado, se anulan solas.' },
      ],
      tasks: [
        { title: 'Vender una gift card con comprobante', perm: 'sales.create', steps: ['En el punto de venta, entra a la pestaña "Gift cards".', 'Escribe el monto y, si quieres, para quién es, y agrégala al carrito.', 'Cobra la venta: el código aparece al terminar.'] },
        { title: 'Regalar una gift card sin cobro', perm: 'loyalty.create', steps: ['Pulsa "Emitir gift card".', 'Escribe el monto y, si quieres, para quién es.', 'Emítela y pulsa "Copiar código" para enviarlo.'] },
        { title: 'Pagar con una gift card', perm: 'sales.create', steps: ['En el cobro, elige "Gift card".', 'Escribe su código y el monto a usar.'] },
      ],
      rules: [
        { title: 'Gift cards encendidas y su vigencia', text: 'Si se emiten y cobran gift cards, y cuántos días valen por defecto.', where: 'Configuración → Puntos y gift cards' },
      ],
    },

    '/automations': {
      summary: `Correos que salen solos cuando pasa algo con ${aClient}: su cumpleaños, su primera atención, si no asistió, si dejó de venir o si llegó a fidelizado.`,
      points: [
        { title: 'Encender o apagar', text: 'Vienen apagadas con un texto de ejemplo. El interruptor de cada fila las enciende. Solo les llega a quienes lleguen a esa situación desde que la enciendes.', perm: 'automations.edit' },
        { title: 'Editar', text: 'El lápiz abre su panel: hora o cuánto después sale, cuántas veces a la misma persona, cupón, asunto y mensaje con formato.', perm: 'automations.edit' },
        { title: 'Sin saturar', text: `Cada ${c} recibe como máximo una automatización por día, y solo si aceptó promociones por correo.` },
        { title: 'Resultados', text: 'Cuántos correos salieron este mes y cuántos agendaron una cita en los 7 días siguientes.' },
        { title: 'Dónde se ven', text: 'Cada correo enviado queda en Mensajes, como "Automatización".' },
      ],
      tasks: [
        { title: 'Encender el saludo de cumpleaños', perm: 'automations.edit', steps: ['Pulsa el lápiz de "Cumpleaños".', 'Revisa el asunto y el mensaje; elige un cupón si quieres regalar algo.', 'Enciende "Encendida" y pulsa "Guardar".'] },
        { title: `Escribirle a ${aClient} que no asistió`, perm: 'automations.edit', steps: ['Pulsa el lápiz de "No asistió".', 'Elige cuánto después sale (por ejemplo, 2 horas).', 'Enciéndela y pulsa "Guardar".'] },
      ],
    },

    '/campaigns': {
      summary: `Envía una promoción por correo, por WhatsApp o por los dos a un grupo de ${cs}, ahora o programada, y muestra los resultados: cuántos la recibieron y cuántos agendaron después.`,
      points: [
        { title: 'Nueva campaña', text: 'Con su nombre, por dónde sale, a quiénes va, el mensaje (o la plantilla de WhatsApp), un cupón opcional y cuándo sale.', perm: 'campaigns.create' },
        { title: 'Por WhatsApp', text: `Sale con una plantilla aprobada en Meta: escribes su nombre, su idioma y qué dato va en cada {{1}}, {{2}}… Solo llega a ${cs} con celular que aceptaron mensajes por WhatsApp, y nunca a quien pidió no recibir promociones.` },
        { title: 'A cuántos les llega', text: 'Antes de enviarla ves cuántos la reciben por cada canal y por qué se deja fuera a los demás (sin correo, sin celular, sin permiso o de baja).' },
        { title: 'Tope de tu plan', text: 'Tu plan puede tener un tope de WhatsApp y de correos de campañas al mes. Si la campaña no cabe en lo que queda, no sale y te dice cuánto falta. Al 80 % te llega un aviso. El uso se ve en Empresa, frente al límite.' },
        { title: '¿A quiénes?', text: `Todos, nuevos, frecuentes, VIP, en riesgo de no volver, inactivos, registrados sin visitas, cumpleaños del mes, o quienes se hicieron un servicio o se atendieron en una sede.` },
        { title: 'Ver cómo le llega', text: 'Muestra el correo tal como lo recibirá.' },
        { title: 'Ver resultados', text: 'Enviados, omitidos (sin correo o sin permiso para promociones) y quiénes agendaron después.' },
        { title: 'Duplicar', text: 'Crea una campaña nueva con lo mismo, para repetirla.', perm: 'campaigns.create' },
        { title: 'Cambiar o cancelar', text: 'Una campaña programada se puede cambiar o cancelar antes de que salga.', perm: 'campaigns.edit' },
        { title: 'Permiso para promociones', text: `Solo la reciben ${cs} que aceptaron promociones por correo; se marca en su ficha.` },
        { title: 'Si eres gerente de sede', text: `Tus campañas llegan a ${cs} de tu sede: quienes tuvieron una cita ahí en los últimos 12 meses. Si el administrador lo permite, puedes elegir enviar a toda la empresa.` },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: `Recuperar a ${cs} inactivos`, perm: 'campaigns.create', steps: ['Pulsa "Nueva campaña".', 'En "¿A quiénes?" elige "Inactivos".', 'Escribe el asunto y el mensaje; agrega un cupón si quieres.', 'Elige "Ahora" y pulsa "Enviar ahora".'] },
        { title: 'Programar una campaña', perm: 'campaigns.create', steps: ['Arma la campaña.', 'En "¿Cuándo sale?" elige "Programar", el día y la hora.', 'Pulsa "Programar campaña".'] },
      ],
      rules: [
        { title: 'Grupos de clientes', text: 'Cuántos días sin venir es inactivo, cuántas visitas es frecuente y cuánto gasto es VIP.', where: 'Configuración → Grupos de clientes' },
        { title: 'Correo de tu empresa', text: 'Sin configurar no se envía nada; en "Probar sin enviar" los correos se arman pero no salen.', where: 'Configuración → Correo de tu empresa' },
        { title: 'WhatsApp', text: 'Sin conectar no salen campañas por WhatsApp; en "Probar sin enviar" se arman pero no salen.', where: 'Configuración → Mensajes → WhatsApp y asistente' },
      ],
    },

    '/messages': {
      summary: `Es la bandeja de salida de todos los correos y WhatsApp a tus ${cs}: avisos automáticos, campañas y mensajes sueltos. También se escribe un correo a una persona y, en "Conversaciones de WhatsApp", se ve y se responde lo que te escriben por WhatsApp.`,
      points: [
        { title: 'Tarjetas', text: 'Enviados, programados, por enviar y con problemas.' },
        { title: 'Nuevo mensaje', text: `Elige ${theClient}, empieza en blanco o desde un modelo, y envíalo ahora o prográmalo.`, perm: 'messages.create' },
        { title: 'Ver el mensaje', text: 'Muestra lo que se envió y si llegó o falló.' },
        { title: 'Cambiar, cancelar o reintentar', text: 'Cambia el texto o la fecha de uno programado, cancélalo, o reintenta uno que falló.', perm: 'messages.edit' },
        { title: 'Revisar ahora', text: 'Envía al momento los mensajes que ya deberían haber salido.' },
        { title: 'Estado de cada mensaje', text: 'Por WhatsApp se ve además si el mensaje fue entregado y si lo leyó.' },
        { title: 'Conversaciones de WhatsApp', text: 'Lo que te escriben al WhatsApp de tu empresa. Cada número se une solo al cliente que tiene ese celular. Las tarjetas muestran las que necesitan una persona, las que no has leído y las que aún se pueden responder.' },
        { title: 'Responder por WhatsApp', text: 'Solo dentro de las 24 horas después del último mensaje del cliente; luego WhatsApp solo deja enviar plantillas aprobadas.', perm: 'messages.reply' },
        { title: 'Necesita una persona', text: 'El asistente pasa a una persona las consultas de salud y lo que no sabe responder; quien puede responder recibe un aviso. Al terminar, pulsa "Marcar como atendida".', perm: 'messages.reply' },
        { title: 'Sin permiso para promociones', text: 'Los avisos de citas, pagos y paquetes y los mensajes sueltos se envían igual; ese permiso solo cuenta para campañas.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Escribirle a una persona', perm: 'messages.create', steps: ['Pulsa "Nuevo mensaje".', `Elige ${theClient} y, si quieres, un modelo como "Resultados listos".`, 'Escribe el asunto y el mensaje, y revisa "Ver cómo le llega".', 'Pulsa "Enviar ahora" o prográmalo.'] },
        { title: 'Arreglar un correo que falló', perm: 'messages.edit', steps: ['Pulsa la tarjeta "Con problemas".', 'Abre el mensaje y pulsa "Reintentar".'] },
        { title: 'Responder a un cliente por WhatsApp', perm: 'messages.reply', steps: ['Pulsa "Conversaciones de WhatsApp".', 'Abre la conversación con el ojo o el último mensaje.', 'Escribe tu respuesta y pulsa "Enviar".'] },
      ],
      rules: [
        { title: 'Con qué correo se envía', text: 'Gmail, Outlook, Brevo o el servidor de tu empresa. Hay un botón para enviar un correo de prueba.', where: 'Configuración → Correo de tu empresa' },
        { title: 'WhatsApp y asistente', text: 'Conectar el WhatsApp de tu empresa con las claves de Meta, las plantillas aprobadas, si los avisos salen por correo, por WhatsApp o por ambos (por defecto, solo correo), a quién se le escribe y el asistente (apagado por defecto).', where: 'Configuración → WhatsApp y asistente' },
        { title: 'Qué avisos se envían', text: 'Qué avisos automáticos salen y con qué texto.', where: 'Personalización → Mensajes al cliente' },
      ],
    },
  };
};
