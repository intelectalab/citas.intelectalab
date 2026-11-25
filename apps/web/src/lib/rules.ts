export type RuleValue = string | number | boolean;

export interface RuleDefinition {
  key: string;
  label: string;
  hint: string;
  type: 'boolean' | 'number' | 'choice';
  unit?: string;
  options?: { value: string; label: string }[];
}

export interface RuleGroup {
  title: string;
  rules: RuleDefinition[];
}

export const RULE_GROUPS: RuleGroup[] = [
  {
    title: 'Reservas',
    rules: [
      { key: 'booking.require_deposit', label: 'Pedir adelanto para reservar', hint: 'La reserva en línea se confirma al pagar el adelanto.', type: 'boolean' },
      { key: 'booking.deposit_percent', label: 'Adelanto', hint: 'Porcentaje con que nacen los servicios que exigen adelanto. Cada empresa puede cambiarlo en cada servicio.', type: 'number', unit: '%' },
      { key: 'booking.auto_confirm', label: 'Confirmar reservas automáticamente', hint: 'Si no, el personal confirma cada reserva en línea.', type: 'boolean' },
      { key: 'booking.max_days_ahead', label: 'Anticipación máxima', hint: 'Hasta cuántos días adelante se puede reservar.', type: 'number', unit: 'días' },
      { key: 'confirm.deadline_hours', label: 'Plazo para confirmar la cita', hint: 'Horas antes de la cita. Cada empresa puede cambiarlo y elegir qué pasa si no se confirma. 0 = sin plazo.', type: 'number', unit: 'horas' },
      { key: 'reminders.hours_before', label: 'Recordatorio de cita', hint: 'Con cuánta anticipación se envía.', type: 'number', unit: 'horas' },
    ],
  },
  {
    title: 'Cancelaciones e inasistencias',
    rules: [
      { key: 'cancel.window_hours', label: 'Plazo para cancelar sin costo', hint: 'Antes de la cita.', type: 'number', unit: 'horas' },
      { key: 'cancel.late_fee_percent', label: 'Cargo por cancelar tarde', hint: 'Porcentaje del precio del servicio.', type: 'number', unit: '%' },
      { key: 'noshow.fee_percent', label: 'Cargo por no asistir', hint: 'Porcentaje del precio del servicio.', type: 'number', unit: '%' },
      { key: 'noshow.block_after', label: 'Bloquear reserva en línea tras inasistencias', hint: 'Número de inasistencias que la bloquean.', type: 'number', unit: 'veces' },
    ],
  },
  {
    title: 'Atención',
    rules: [
      { key: 'agenda.checkin_step', label: 'Registrar la llegada del cliente', hint: 'Agrega el paso "llegó" antes de iniciar la atención.', type: 'boolean' },
      { key: 'agenda.respect_buffers', label: 'Respetar pausas entre citas', hint: 'No permite agendar encima de la pausa de un servicio.', type: 'boolean' },
      { key: 'walkin.self_checkin', label: 'Autorregistro en la cola sin cita', hint: 'El cliente se anota solo desde una tableta.', type: 'boolean' },
      { key: 'walkin.max_wait', label: 'Espera máxima aceptada en la cola', hint: 'Si la espera estimada supera esto en el kiosko, sugiere reservar una cita.', type: 'number', unit: 'min' },
      { key: 'classes.waitlist', label: 'Lista de espera en clases llenas', hint: 'Si la clase se llena, quien quiere entrar queda anotado en espera.', type: 'boolean' },
      { key: 'classes.auto_promote', label: 'Dar el cupo libre al primero en espera', hint: 'Cuando alguien sale de una clase llena, el primero de la lista de espera entra solo y se le avisa.', type: 'boolean' },
      { key: 'classes.portal_leave_hours', label: 'Salir de una clase desde el portal', hint: 'Hasta cuántas horas antes de la clase el cliente puede salir por su cuenta.', type: 'number', unit: 'horas' },
      { key: 'professionals.license', label: 'Pedir número de colegiatura', hint: 'Muestra ese dato en el formulario y la tabla de profesionales.', type: 'boolean' },
      { key: 'customers.clinical_fields', label: 'Datos clínicos del cliente', hint: 'Muestra el tipo de sangre y trata las observaciones como antecedentes. Para rubros de salud.', type: 'boolean' },
    ],
  },
  {
    title: 'Lista de espera',
    rules: [
      { key: 'waitlist.enabled', label: 'Usar la lista de espera', hint: 'Si un día está lleno, se anota a quien quiere un horario y se le avisa cuando se libera uno.', type: 'boolean' },
      { key: 'waitlist.notify_mode', label: 'A quién se avisa cuando se libera un horario', hint: 'A todos los que encajan a la vez, o solo al primero de la lista y luego al siguiente.', type: 'choice', options: [{ value: 'all', label: 'A todos los que encajan' }, { value: 'first', label: 'Solo al primero, en orden' }] },
      { key: 'waitlist.reply_hours', label: 'Tiempo para responder el aviso', hint: 'Si avisa solo al primero: cuántas horas tiene para reservar antes de avisar al siguiente.', type: 'number', unit: 'horas' },
    ],
  },
  {
    title: 'Fichas y seguimiento',
    rules: [
      { key: 'records.require_on_complete', label: 'Ficha obligatoria para completar la cita', hint: 'En los servicios que se atienden con ficha.', type: 'boolean' },
      { key: 'records.private_to_author', label: 'Ficha visible solo para quien la escribió', hint: 'Para notas confidenciales, como en psicología.', type: 'boolean' },
      { key: 'recalls.notify_days', label: 'Avisar del próximo control', hint: 'Con cuántos días de anticipación.', type: 'number', unit: 'días' },
    ],
  },
  {
    title: 'Ventas e inventario',
    rules: [
      { key: 'pos.tips_enabled', label: 'Aceptar propinas', hint: 'Muestra la propina al cobrar.', type: 'boolean' },
      { key: 'pos.pay_before_service', label: 'Cobrar antes de atender', hint: 'La cita no se inicia ni se completa hasta que esté pagada. Apagado: se cobra al final.', type: 'boolean' },
      { key: 'pos.arrive_minutes', label: 'Llegar antes para pagar', hint: 'Cuántos minutos antes de la cita se pide llegar cuando se cobra antes de atender.', type: 'number', unit: 'min' },
      { key: 'pos.require_open_cash', label: 'Exigir caja abierta para cobrar en efectivo', hint: 'Sin un turno de caja abierto no se registra efectivo.', type: 'boolean' },
      { key: 'cash.auto_register', label: 'Crear la caja de cada sede sola', hint: 'Al abrir el primer turno, si la sede no tiene caja, se crea una.', type: 'boolean' },
      { key: 'cash.tolerance', label: 'Diferencia aceptada al cerrar caja', hint: 'Monto de sobrante o faltante que no se marca como descuadre.', type: 'number' },
      { key: 'pos.partial_payments', label: 'Aceptar pagos a cuenta', hint: 'Se puede pagar una parte y dejar saldo pendiente.', type: 'boolean' },
      { key: 'pos.prices_include_tax', label: 'Los precios ya incluyen el IGV', hint: 'Apagado: el impuesto se suma al precio al cobrar.', type: 'boolean' },
      { key: 'pos.tax_rate', label: 'Impuesto (IGV)', hint: 'Porcentaje de impuesto de las ventas.', type: 'number', unit: '%' },
      { key: 'invoice.default_type', label: 'Comprobante que sale por defecto', hint: 'El que se propone al cobrar. Se puede cambiar en cada venta.', type: 'choice', options: [{ value: 'boleta', label: 'Boleta' }, { value: 'factura', label: 'Factura' }] },
      { key: 'pos.refund_days', label: 'Plazo para devolver', hint: 'Días después de la venta en que se acepta una devolución.', type: 'number', unit: 'días' },
      { key: 'pos.refund_approval_over', label: 'Devoluciones que necesitan aprobación', hint: 'Desde este monto una devolución la aprueba un administrador. 0 = ninguna.', type: 'number' },
      { key: 'pos.max_discount', label: 'Descuento máximo en caja', hint: 'Sin autorización de un administrador.', type: 'number', unit: '%' },
      { key: 'loyalty.points_enabled', label: 'Acumular y canjear puntos', hint: 'Los clientes ganan puntos con sus compras y los canjean al pagar.', type: 'boolean' },
      { key: 'loyalty.amount_per_point', label: 'Monto por punto', hint: 'Cuánto debe pagar el cliente para ganar 1 punto.', type: 'number' },
      { key: 'loyalty.point_value', label: 'Valor de cada punto', hint: 'Cuánto descuenta cada punto al canjearlo.', type: 'number' },
      { key: 'loyalty.min_redeem_points', label: 'Mínimo de puntos para canjear', hint: 'Puntos acumulados necesarios para habilitar el canje.', type: 'number', unit: 'pts' },
      { key: 'membership.grace_days', label: 'Días de gracia de las membresías', hint: 'Después de vencer, la membresía sigue dando beneficios estos días.', type: 'number', unit: 'días' },
      { key: 'loyalty.gift_cards_enabled', label: 'Usar gift cards', hint: 'Permite emitir tarjetas de regalo y cobrar con ellas.', type: 'boolean' },
      { key: 'quotes.validity_days', label: 'Vigencia de presupuestos', hint: 'Días de validez por defecto al armar un presupuesto.', type: 'number', unit: 'días' },
      { key: 'quotes.allow_installments', label: 'Permitir cuotas en presupuestos', hint: 'Habilita cronogramas de cuotas en los planes de tratamiento.', type: 'boolean' },
      { key: 'quotes.auto_create_sale', label: 'Crear venta al aprobar presupuesto', hint: 'Genera la venta por cobrar automáticamente al aprobar el plan.', type: 'boolean' },
      { key: 'quotes.portal_accept', label: 'Aceptar presupuestos desde el portal', hint: 'El cliente ve los presupuestos enviados en su portal y puede aceptarlos o rechazarlos.', type: 'boolean' },
      { key: 'quotes.track_clinical_zones', label: 'Pieza o zona en planes de tratamiento', hint: 'Muestra el campo de pieza dental, zona o etapa en cada línea.', type: 'boolean' },
      { key: 'quotes.plan_validity_days', label: 'Duración del plan de tratamiento', hint: 'Días que dura el plan que se crea al aprobar un presupuesto. La regla de anulación la elige cada empresa en Configuración.', type: 'number', unit: 'días' },
      { key: 'inventory.consume_on_service', label: 'Descontar insumos al completar el servicio', hint: 'Según los insumos definidos en cada servicio.', type: 'boolean' },
      { key: 'inventory.allow_negative', label: 'Permitir stock negativo', hint: 'Vender aunque el sistema no registre existencias.', type: 'boolean' },
      { key: 'inventory.transfer_two_step', label: 'Transferencias entre sedes en dos pasos', hint: 'La sede destino debe confirmar la recepción para sumar el stock.', type: 'boolean' },
      { key: 'inventory.alert_min_stock', label: 'Avisar cuando un producto baje del stock mínimo', hint: 'Envía una alerta interna al equipo cuando queden pocas unidades.', type: 'boolean' },
    ],
  },
];

export const KNOWN_RULE_KEYS: string[] = RULE_GROUPS.flatMap((group) => group.rules.map((rule) => rule.key));
