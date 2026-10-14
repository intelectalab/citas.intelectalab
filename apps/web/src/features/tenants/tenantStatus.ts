export const TENANT_STATUS: Record<string, { label: string; className: string }> = {
  active: { label: 'Activo', className: 'bg-emerald-50 text-emerald-600' },
  suspended: { label: 'Suspendido', className: 'bg-rose-50 text-rose-600' },
  inactive: { label: 'Inactivo', className: 'bg-slate-200 text-slate-600' },
};

export const SUBSCRIPTION_STATUS: Record<string, string> = {
  active: 'Activa',
  trialing: 'En prueba',
  past_due: 'Pago vencido',
  expired: 'Prueba vencida',
  canceled: 'Cancelada',
};
