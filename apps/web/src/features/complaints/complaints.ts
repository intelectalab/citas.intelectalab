export type ComplaintState = 'open' | 'soon' | 'late' | 'answered';

export type Issuer = { name: string; legal_name: string | null; tax_id: string | null; address: string | null };

export type Complaint = {
  id: string;
  code: string;
  kind: 'reclamo' | 'queja';
  kind_label: string;
  consumer: { name: string; document_type: string; document_number: string; address: string; phone: string; email: string; is_minor: boolean };
  guardian: { name: string; document_type: string; document_number: string } | null;
  good_type: 'producto' | 'servicio';
  good_description: string;
  amount: number | null;
  currency: string;
  detail: string;
  request: string;
  company: Issuer;
  created_on: string;
  created_at: string;
  due_on: string;
  days_left: number | null;
  state: ComplaintState;
  status: 'open' | 'answered';
  response: string | null;
  responded_at: string | null;
  responder: string | null;
};

export const KIND_HELP = {
  reclamo: 'No estás de acuerdo con el producto o servicio que recibiste. Por ejemplo: te cobraron algo que no te hicieron o el resultado no fue el acordado.',
  queja: 'Tu malestar no es por el producto o servicio, sino por la atención. Por ejemplo: te trataron mal o te hicieron esperar mucho.',
};

export const STATE: Record<ComplaintState, { label: string; tone: string }> = {
  open: { label: 'Pendiente', tone: 'bg-slate-100 text-slate-700' },
  soon: { label: 'Por vencer', tone: 'bg-amber-50 text-amber-800' },
  late: { label: 'Vencido', tone: 'bg-rose-50 text-rose-700' },
  answered: { label: 'Respondido', tone: 'bg-emerald-50 text-emerald-700' },
};

export const longDate = (date: string) =>
  new Date(`${date.slice(0, 10)}T12:00:00`).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });

export const deadline = (c: Complaint) => {
  if (c.state === 'answered') return `Respondido el ${longDate(c.responded_at ?? c.due_on)}`;
  if (c.state === 'late') return `Venció el ${longDate(c.due_on)}`;
  if (c.days_left === 0) return 'Vence hoy';
  return `Vence el ${longDate(c.due_on)} · ${c.days_left === 1 ? 'queda 1 día hábil' : `quedan ${c.days_left} días hábiles`}`;
};

export const issuerLine = (issuer: Issuer) =>
  [issuer.legal_name, issuer.tax_id ? `RUC ${issuer.tax_id}` : null, issuer.address].filter(Boolean).join(' · ');
