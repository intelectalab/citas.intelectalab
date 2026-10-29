export interface AuditChange {
  field: string;
  from: string | null;
  to: string | null;
}

export interface AuditRow {
  id: string;
  at: string;
  action: string;
  label: string;
  area: string;
  area_label: string;
  actor: { id: string | null; name: string; type: 'staff' | 'customer' | 'platform' | 'system' };
  impersonator: { id: string; name: string } | null;
  tenant: { id: string; name: string } | null;
  entity_label: string | null;
  ip: string | null;
  device: string | null;
  changes: AuditChange[];
  branch?: { id: string; name: string } | null;
}

export const ACTOR_TYPES: Record<AuditRow['actor']['type'], string> = {
  platform: 'Equipo de la plataforma',
  staff: 'Personal de la empresa',
  customer: 'Cliente',
  system: 'El sistema',
};

export const ALERT_ACTIONS = ['auth.failed', 'auth.blocked'];

export const changeSummary = (row: AuditRow): string => {
  const first = row.changes[0];
  if (!first) return row.entity_label ?? '';
  const text = first.from && first.to ? `${first.field}: ${first.from} → ${first.to}` : `${first.field}: ${first.to ?? first.from}`;
  return row.changes.length > 1 ? `${text} y ${row.changes.length - 1} más` : text;
};

export const isDeletion = (row: AuditRow) => /\.(deleted|voided)$/.test(row.action);
