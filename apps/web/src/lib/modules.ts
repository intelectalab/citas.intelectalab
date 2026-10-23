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
      { key: 'booking', label: 'Reserva online y portal', hint: 'El portal donde sus clientes reservan, y sus reglas en Configuración' },
      { key: 'walkin', label: 'Cola sin cita', hint: 'En el menú: Cola sin cita' },
      { key: 'classes', label: 'Clases', hint: 'En el menú: Clases' },
    ],
  },
  {
    key: 'care',
    title: 'Atención',
    items: [
      { key: 'records', label: 'Fichas de atención', hint: 'En el menú: Fichas de atención, con sus plantillas e historia' },
    ],
  },
  {
    key: 'catalog',
    title: 'Equipo y catálogo',
    items: [
      { key: 'resources', label: 'Salas y equipos', hint: 'En el menú: Salas y equipos' },
    ],
  },
  {
    key: 'sales',
    title: 'Ventas',
    items: [
      { key: 'pos', label: 'Punto de venta y caja', hint: 'En el menú: Punto de venta, Ventas, Pagos, Caja, Cupones y Productos' },
      { key: 'invoicing', label: 'Facturación electrónica', hint: 'Boletas y facturas en cada venta; sin este módulo, solo comprobante interno' },
      { key: 'quotes', label: 'Presupuestos', hint: 'En el menú: Presupuestos' },
      { key: 'commissions', label: 'Comisiones', hint: 'Comisiones del personal por cada atención o venta' },
      { key: 'inventory', label: 'Inventario', hint: 'En el menú: Inventario' },
    ],
  },
  {
    key: 'loyalty',
    title: 'Fidelización',
    items: [
      { key: 'packages', label: 'Paquetes', hint: 'En el menú: Paquetes' },
      { key: 'memberships', label: 'Membresías', hint: 'En el menú: Membresías' },
      { key: 'loyalty', label: 'Puntos y gift cards', hint: 'En el menú: Puntos y gift cards' },
      { key: 'marketing', label: 'Campañas', hint: 'En el menú: Campañas' },
      { key: 'automations', label: 'Automatizaciones', hint: 'Mensajes que salen solos: cumpleaños, clientes inactivos, primera atención' },
    ],
  },
  {
    key: 'company',
    title: 'Tu empresa',
    items: [
      { key: 'multi_branch', label: 'Multisede', hint: 'Permite tener más de una sede' },
    ],
  },
  {
    key: 'platform',
    title: 'Plataforma',
    items: [
      { key: 'reports_adv', label: 'Analítica avanzada', hint: 'Reportes y comparaciones más detallados' },
      { key: 'api', label: 'API y webhooks', hint: 'Llaves de API y webhooks para conectar otros sistemas' },
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
