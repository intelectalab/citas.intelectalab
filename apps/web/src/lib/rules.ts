export type RuleValue = string | number | boolean;

export interface RuleDefinition {
  key: string;
  label: string;
  hint: string;
  type: 'boolean' | 'number';
  unit?: string;
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
      { key: 'professionals.license', label: 'Pedir número de colegiatura', hint: 'Muestra ese dato en el formulario y la tabla de profesionales.', type: 'boolean' },
      { key: 'customers.clinical_fields', label: 'Datos clínicos del cliente', hint: 'Muestra el tipo de sangre y trata las observaciones como antecedentes. Para rubros de salud.', type: 'boolean' },
    ],
  },
  {
    title: 'Fichas y seguimiento',
    rules: [
      { key: 'records.require_on_complete', label: 'Ficha obligatoria para completar la cita', hint: 'En los servicios que se atienden con ficha.', type: 'boolean' },
      { key: 'records.lock_hours', label: 'Bloquear la edición de la ficha', hint: 'Horas después de la atención. Luego solo se agregan notas.', type: 'number', unit: 'horas' },
      { key: 'records.private_to_author', label: 'Ficha visible solo para quien la escribió', hint: 'Para notas confidenciales, como en psicología.', type: 'boolean' },
      { key: 'recalls.notify_days', label: 'Avisar del próximo control', hint: 'Con cuántos días de anticipación.', type: 'number', unit: 'días' },
    ],
  },
  {
    title: 'Ventas e inventario',
    rules: [
      { key: 'pos.tips_enabled', label: 'Aceptar propinas', hint: 'Muestra la propina al cobrar.', type: 'boolean' },
      { key: 'pos.max_discount', label: 'Descuento máximo en caja', hint: 'Sin autorización de un administrador.', type: 'number', unit: '%' },
      { key: 'loyalty.points_enabled', label: 'Acumular y canjear puntos', hint: 'Los clientes ganan puntos con sus compras y los canjean al pagar.', type: 'boolean' },
      { key: 'loyalty.amount_per_point', label: 'Monto por punto', hint: 'Cuánto debe pagar el cliente para ganar 1 punto.', type: 'number' },
      { key: 'loyalty.point_value', label: 'Valor de cada punto', hint: 'Cuánto descuenta cada punto al canjearlo.', type: 'number' },
      { key: 'loyalty.min_redeem_points', label: 'Mínimo de puntos para canjear', hint: 'Puntos acumulados necesarios para habilitar el canje.', type: 'number', unit: 'pts' },
      { key: 'loyalty.gift_cards_enabled', label: 'Usar gift cards', hint: 'Permite emitir tarjetas de regalo y cobrar con ellas.', type: 'boolean' },
      { key: 'quotes.validity_days', label: 'Vigencia de presupuestos', hint: 'Días de validez por defecto al armar un presupuesto.', type: 'number', unit: 'días' },
      { key: 'quotes.allow_installments', label: 'Permitir cuotas en presupuestos', hint: 'Habilita cronogramas de cuotas en los planes de tratamiento.', type: 'boolean' },
      { key: 'quotes.auto_create_sale', label: 'Crear venta al aprobar presupuesto', hint: 'Genera la venta por cobrar automáticamente al aprobar el plan.', type: 'boolean' },
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
