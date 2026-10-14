export interface RecordField {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  options?: string[];
  placeholder?: string;
  days?: number | '';
  step?: number;
}

export interface RecordTemplate {
  id: string;
  name: string;
  description: string | null;
  fields: RecordField[];
  consent_text: string | null;
  require_consent: boolean;
  is_default: boolean;
  version: number;
  service_ids: string[];
  records_count: number;
}

export interface TemplateService {
  id: string;
  name: string;
  category: string | null;
  template_id: string | null;
}

export type RecordValue = string | number | boolean | string[] | Record<string, string> | { date: string } | null | undefined;
export type RecordData = Record<string, RecordValue>;

export interface CareRecord {
  id: string;
  status: 'draft' | 'signed' | 'locked';
  template_name: string | null;
  is_private: boolean;
  hidden: boolean;
  can_edit: boolean;
  can_add_note: boolean;
  can_request_access?: boolean;
  reading_by_emergency?: boolean;
  emergency_accesses?: { by: string | null; at: string; reason: string | null }[];
  created_at: string;
  signed_at: string | null;
  locked_at: string | null;
  author: string | null;
  appointment_id: string | null;
  appointment: { code: string; starts_at: string; service: string | null } | null;
  customer: { id: string; code: string; name: string } | null;
  dependent: { id: string; name: string } | null;
  professional: string | null;
  professional_id?: string | null;
  consent_accepted: boolean;
  consent_text?: string | null;
  require_consent?: boolean;
  fields?: RecordField[];
  data?: RecordData;
  addenda?: { note: string; by: string | null; at: string }[];
}

export interface AppointmentRecord {
  appointment: {
    id: string;
    code: string;
    status: string;
    starts_at: string;
    customer: string | null;
    dependent: string | null;
    service: string | null;
    professional: string | null;
  };
  uses_record: boolean;
  record: CareRecord | null;
  template: { name: string; fields: RecordField[]; consent_text: string | null; require_consent: boolean } | null;
  private_by_default: boolean;
  required_to_complete: boolean;
  lock_hours: number;
  previous: CareRecord[];
  template_update?: { name: string; fields: RecordField[]; consent_text: string | null; require_consent: boolean } | null;
}

export const FIELD_TYPES: { value: string; label: string }[] = [
  { value: 'text', label: 'Texto corto' },
  { value: 'textarea', label: 'Texto largo' },
  { value: 'number', label: 'Número' },
  { value: 'select', label: 'Lista (una opción)' },
  { value: 'multiselect', label: 'Lista (varias opciones)' },
  { value: 'boolean', label: 'Sí / No' },
  { value: 'date', label: 'Fecha' },
  { value: 'scale', label: 'Escala 0 a 10' },
  { value: 'odontogram', label: 'Odontograma' },
  { value: 'next_control', label: 'Próximo control' },
];

export const FIELD_TYPE_LABELS: Record<string, string> = Object.fromEntries(FIELD_TYPES.map((type) => [type.value, type.label]));

export const TOOTH_STATES: { key: string; label: string; className: string }[] = [
  { key: 'caries', label: 'Caries', className: 'bg-rose-200 border-rose-200 text-rose-700' },
  { key: 'restored', label: 'Restaurada', className: 'bg-sky-200 border-sky-200 text-sky-700' },
  { key: 'crown', label: 'Corona', className: 'bg-amber-200 border-amber-200 text-amber-800' },
  { key: 'treatment', label: 'En tratamiento', className: 'bg-violet-100 border-violet-100 text-violet-600' },
  { key: 'missing', label: 'Ausente', className: 'bg-slate-200 border-slate-300 text-slate-500 line-through' },
];

export const TEETH_ROWS: string[][] = [
  ['18', '17', '16', '15', '14', '13', '12', '11', '21', '22', '23', '24', '25', '26', '27', '28'],
  ['48', '47', '46', '45', '44', '43', '42', '41', '31', '32', '33', '34', '35', '36', '37', '38'],
];

export const RECORD_STATUS: Record<string, { label: string; badge: string }> = {
  draft: { label: 'Borrador', badge: 'bg-amber-50 text-amber-700' },
  signed: { label: 'Firmada', badge: 'bg-emerald-50 text-emerald-700' },
  locked: { label: 'Bloqueada', badge: 'bg-slate-100 text-slate-600' },
};

export const isEmptyValue = (value: RecordValue): boolean =>
  value === null || value === undefined || value === '' ||
  (Array.isArray(value) && value.length === 0) ||
  (typeof value === 'object' && !Array.isArray(value) && Object.keys(value as object).length === 0);

export const NEW_FIELD: RecordField = { key: '', label: '', type: 'text', required: false };
