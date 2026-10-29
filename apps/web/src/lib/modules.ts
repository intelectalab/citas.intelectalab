export interface ModuleGroup {
  key: string;
  title: string;
  items: { key: string; label: string; hint: string }[];
}

export const MODULE_GROUPS: ModuleGroup[] = [
  {
    key: 'agenda',
    title: 'Agenda',
    items: [
      { key: 'booking', label: 'Reserva online y portal', hint: 'Sus clientes reservan, cambian o cancelan sus citas desde internet, sin llamar' },
      { key: 'walkin', label: 'Cola sin cita', hint: 'Atender por orden de llegada a quien viene sin reservar, como en una barbería' },
      { key: 'classes', label: 'Clases', hint: 'Sesiones grupales con cupos, como yoga o pilates' },
    ],
  },
  {
    key: 'care',
    title: 'Atención',
    items: [
      { key: 'quotes', label: 'Presupuestos', hint: 'Preparar presupuestos para que el cliente los apruebe antes de atenderse' },
      { key: 'records', label: 'Fichas de atención', hint: 'Llenar la ficha de cada atención y ver la historia del cliente' },
    ],
  },
  {
    key: 'catalog',
    title: 'Equipo y catálogo',
    items: [
      { key: 'resources', label: 'Salas y equipos', hint: 'Registrar consultorios, cabinas y equipos de cada sede' },
    ],
  },
  {
    key: 'sales',
    title: 'Ventas',
    items: [
      { key: 'pos', label: 'Punto de venta y caja', hint: 'Cobrar citas, servicios y paquetes, abrir y cerrar caja, y usar cupones. Para vender productos hace falta Inventario' },
      { key: 'invoicing', label: 'Facturación electrónica', hint: 'Emitir boletas y facturas en cada venta' },
      { key: 'commissions', label: 'Comisiones', hint: 'Calcular lo que gana cada profesional por sus atenciones o ventas' },
    ],
  },
  {
    key: 'inventory',
    title: 'Inventario',
    items: [
      { key: 'inventory', label: 'Inventario', hint: 'Registrar y vender productos, y llevar su stock por sede, con kárdex, compras a proveedores y traslados entre sedes' },
    ],
  },
  {
    key: 'loyalty',
    title: 'Fidelización',
    items: [
      { key: 'memberships', label: 'Membresías', hint: 'Cobrar un pago mensual que da beneficios al cliente' },
      { key: 'packages', label: 'Paquetes', hint: 'Vender varias sesiones juntas, por ejemplo 10 sesiones de fisioterapia, y descontar cada visita' },
      { key: 'loyalty', label: 'Puntos y gift cards', hint: 'Premiar con puntos a los clientes que vuelven y vender tarjetas de regalo' },
    ],
  },
  {
    key: 'marketing',
    title: 'Marketing',
    items: [
      { key: 'marketing', label: 'Campañas', hint: 'Enviar correos de promoción a un grupo de clientes' },
      { key: 'automations', label: 'Automatizaciones', hint: 'Mensajes que salen solos, por ejemplo en el cumpleaños o cuando un cliente deja de venir' },
    ],
  },
  {
    key: 'company',
    title: 'Tu empresa',
    items: [
      { key: 'multi_branch', label: 'Multisede', hint: 'Atender en más de una sede' },
    ],
  },
  {
    key: 'platform',
    title: 'Plataforma',
    items: [
      { key: 'reports_adv', label: 'Analítica avanzada', hint: 'Reportes más detallados para comparar sedes, profesionales y servicios' },
      { key: 'api', label: 'API y webhooks', hint: 'Conectar otros sistemas de la empresa, como su web o su contabilidad' },
    ],
  },
];

export const CORE_MODULE_LABELS: Record<string, string> = {
  agenda: 'Agenda y citas',
  crm: 'Clientes',
};

export const OPTIONAL_MODULE_KEYS: string[] = MODULE_GROUPS.flatMap((group) =>
  group.items.map((item) => item.key)
);

export const PENDING_SECTIONS: Record<string, { label: string; module: string }> = {};

export const MODULE_LABELS: Record<string, string> = {
  ...CORE_MODULE_LABELS,
  ...Object.fromEntries(MODULE_GROUPS.flatMap((group) => group.items.map((item) => [item.key, item.label]))),
};

export const MODULE_HINTS: Record<string, string> = Object.fromEntries(
  MODULE_GROUPS.flatMap((group) => group.items.map((item) => [item.key, item.hint]))
);
