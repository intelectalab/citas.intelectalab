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

    '/modules/memberships': {
      summary: 'Servirá para planes de pago mensual con beneficios. Esta pantalla está en construcción: todavía no se puede usar.',
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
        { title: 'Anular paquete', text: 'Termina un paquete vendido y devuelve dinero si corresponde.', perm: 'packages.delete' },
      ],
      tasks: [
        { title: 'Crear un paquete', perm: 'packages.create', steps: ['Entra a "Catálogo" y pulsa "Nuevo paquete".', 'Elige los servicios, las sesiones y cuántos días vale.', 'Elige cómo se agenda y sus condiciones.', 'Guarda.'] },
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
      ],
      tasks: [
        { title: 'Vender una gift card', perm: 'loyalty.create', steps: ['Pulsa "Emitir gift card".', 'Escribe el monto y, si quieres, para quién es.', 'Emítela y pulsa "Copiar código" para enviarlo.'] },
        { title: 'Pagar con una gift card', perm: 'sales.create', steps: ['En el cobro, elige "Gift card".', 'Escribe su código y el monto a usar.'] },
      ],
      rules: [
        { title: 'Gift cards encendidas y su vigencia', text: 'Si se emiten y cobran gift cards, y cuántos días valen por defecto.', where: 'Configuración → Puntos y gift cards' },
      ],
    },

    '/campaigns': {
      summary: `Envía un correo de promoción a un grupo de ${cs}, ahora o programado, y muestra los resultados: cuántos lo recibieron y cuántos agendaron después.`,
      points: [
        { title: 'Nueva campaña', text: 'Con su nombre, a quiénes va, el asunto, el mensaje con formato, un cupón opcional y cuándo sale.', perm: 'campaigns.create' },
        { title: '¿A quiénes?', text: `Todos, nuevos, frecuentes, VIP, en riesgo de no volver, inactivos, registrados sin visitas, cumpleaños del mes, o quienes se hicieron un servicio o se atendieron en una sede.` },
        { title: 'Ver cómo le llega', text: 'Muestra el correo tal como lo recibirá.' },
        { title: 'Ver resultados', text: 'Enviados, omitidos (sin correo o sin permiso para promociones) y quiénes agendaron después.' },
        { title: 'Duplicar', text: 'Crea una campaña nueva con lo mismo, para repetirla.', perm: 'campaigns.create' },
        { title: 'Cambiar o cancelar', text: 'Una campaña programada se puede cambiar o cancelar antes de que salga.', perm: 'campaigns.edit' },
        { title: 'Permiso para promociones', text: `Solo la reciben ${cs} que aceptaron promociones por correo; se marca en su ficha.` },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: `Recuperar a ${cs} inactivos`, perm: 'campaigns.create', steps: ['Pulsa "Nueva campaña".', 'En "¿A quiénes?" elige "Inactivos".', 'Escribe el asunto y el mensaje; agrega un cupón si quieres.', 'Elige "Ahora" y pulsa "Enviar ahora".'] },
        { title: 'Programar una campaña', perm: 'campaigns.create', steps: ['Arma la campaña.', 'En "¿Cuándo sale?" elige "Programar", el día y la hora.', 'Pulsa "Programar campaña".'] },
      ],
      rules: [
        { title: 'Grupos de clientes', text: 'Cuántos días sin venir es inactivo, cuántas visitas es frecuente y cuánto gasto es VIP.', where: 'Configuración → Grupos de clientes' },
        { title: 'Correo de tu empresa', text: 'Sin configurar no se envía nada; en "Probar sin enviar" los correos se arman pero no salen.', where: 'Configuración → Correo de tu empresa' },
      ],
    },

    '/messages': {
      summary: `Es la bandeja de salida de todos los correos a tus ${cs}: avisos automáticos, campañas y mensajes sueltos. También se escribe un correo a una persona.`,
      points: [
        { title: 'Tarjetas', text: 'Enviados, programados, por enviar y con problemas.' },
        { title: 'Nuevo mensaje', text: `Elige ${theClient}, empieza en blanco o desde un modelo, y envíalo ahora o prográmalo.`, perm: 'messages.create' },
        { title: 'Ver el mensaje', text: 'Muestra lo que se envió y si llegó o falló.' },
        { title: 'Cambiar, cancelar o reintentar', text: 'Cambia el texto o la fecha de uno programado, cancélalo, o reintenta uno que falló.', perm: 'messages.edit' },
        { title: 'Revisar ahora', text: 'Envía al momento los mensajes que ya deberían haber salido.' },
        { title: 'Sin permiso para promociones', text: 'Los avisos de citas, pagos y paquetes y los mensajes sueltos se envían igual; ese permiso solo cuenta para campañas.' },
        { title: 'Exportar', text: 'Descarga a Excel o CSV lo que estás viendo, en el orden de la lista. Eliges cuántas filas y qué columnas.', perm: 'data.export' },
      ],
      tasks: [
        { title: 'Escribirle a una persona', perm: 'messages.create', steps: ['Pulsa "Nuevo mensaje".', `Elige ${theClient} y, si quieres, un modelo como "Resultados listos".`, 'Escribe el asunto y el mensaje, y revisa "Ver cómo le llega".', 'Pulsa "Enviar ahora" o prográmalo.'] },
        { title: 'Arreglar un correo que falló', perm: 'messages.edit', steps: ['Pulsa la tarjeta "Con problemas".', 'Abre el mensaje y pulsa "Reintentar".'] },
      ],
      rules: [
        { title: 'Con qué correo se envía', text: 'Gmail, Outlook, Brevo o el servidor de tu empresa. Hay un botón para enviar un correo de prueba. WhatsApp todavía no está conectado.', where: 'Configuración → Correo de tu empresa' },
        { title: 'Qué avisos se envían', text: 'Qué avisos automáticos salen y con qué texto.', where: 'Personalización → Mensajes al cliente' },
      ],
    },
  };
};
