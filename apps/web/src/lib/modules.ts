// Módulos del producto que se activan por plan. Las claves coinciden con la
// tabla saas_modules. Los módulos base (agenda y crm) van en todos los planes
// y por eso no se eligen aquí.
export interface ModuleGroup {
  key: string;
  title: string;
  items: { key: string; label: string }[];
}

export const MODULE_GROUPS: ModuleGroup[] = [
  {
    key: 'agenda',
    title: 'Agenda y atención',
    items: [
      { key: 'booking', label: 'Reserva online y portal' },
      { key: 'resources', label: 'Salas y equipos' },
      { key: 'classes', label: 'Clases grupales' },
      { key: 'walkin', label: 'Cola de llegada sin cita' },
    ],
  },
  {
    key: 'sales',
    title: 'Ventas y caja',
    items: [
      { key: 'pos', label: 'Punto de venta y caja' },
      { key: 'invoicing', label: 'Facturación electrónica' },
      { key: 'quotes', label: 'Presupuestos y planes de tratamiento' },
      { key: 'commissions', label: 'Comisiones' },
    ],
  },
  {
    key: 'loyalty',
    title: 'Fidelización',
    items: [
      { key: 'packages', label: 'Paquetes' },
      { key: 'memberships', label: 'Membresías' },
      { key: 'loyalty', label: 'Puntos y gift cards' },
    ],
  },
  {
    key: 'marketing',
    title: 'Marketing',
    items: [
      { key: 'marketing', label: 'Marketing y WhatsApp' },
      { key: 'automations', label: 'Automatizaciones' },
    ],
  },
  {
    key: 'operations',
    title: 'Operación',
    items: [
      { key: 'inventory', label: 'Inventario' },
      { key: 'records', label: 'Fichas de atención e historia' },
      { key: 'multi_branch', label: 'Multisede' },
    ],
  },
  {
    key: 'platform',
    title: 'Plataforma',
    items: [
      { key: 'reports_adv', label: 'Analítica avanzada' },
      { key: 'api', label: 'API e integraciones' },
    ],
  },
];

export const CORE_MODULE_LABELS: Record<string, string> = {
  agenda: 'Agenda y citas',
  crm: 'Clientes / CRM 360',
};

export const OPTIONAL_MODULE_KEYS: string[] = MODULE_GROUPS.flatMap((group) =>
  group.items.map((item) => item.key)
);

// Secciones del menú que aún no tienen pantalla: nombre y módulo del que dependen.
// La página provisional /modules/<clave> las muestra igual que a un módulo.
// Hoy no queda ninguna: las del grupo Agenda ya tienen su pantalla.
export const PENDING_SECTIONS: Record<string, { label: string; module: string }> = {};

export const MODULE_LABELS: Record<string, string> = {
  ...CORE_MODULE_LABELS,
  ...Object.fromEntries(MODULE_GROUPS.flatMap((group) => group.items.map((item) => [item.key, item.label]))),
};
