'use client';

import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useState } from 'react';
import { FileText, Plus } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

export interface CatalogTemplate {
  id: string;
  name: string;
  language: string;
  status: 'APPROVED' | 'PENDING' | 'REJECTED' | 'PAUSED' | 'DISABLED' | 'DELETED' | 'UNKNOWN';
  status_label: string;
  category: string | null;
  body: string;
  params: number;
  reason: string | null;
  submitted_at: string | null;
  synced_at: string | null;
}

export interface MetaTemplatesInfo {
  ready: boolean;
  verifiable: boolean;
  catalog: CatalogTemplate[];
  synced_at: string | null;
  categories: { value: string; label: string }[];
}

export const TEMPLATE_TONES: Record<CatalogTemplate['status'], string> = {
  APPROVED: 'bg-emerald-50 text-emerald-700',
  PENDING: 'bg-sky-50 text-sky-700',
  REJECTED: 'bg-rose-50 text-rose-700',
  PAUSED: 'bg-amber-50 text-amber-800',
  DISABLED: 'bg-rose-50 text-rose-700',
  DELETED: 'bg-slate-100 text-slate-500',
  UNKNOWN: 'bg-slate-100 text-slate-500',
};

const EMPTY = { name: '', language: 'es', category: 'UTILITY', body: '', examples: [] as string[] };

const countParams = (body: string) => {
  const found = [...body.matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map((m) => Number(m[1]));
  return found.length ? Math.max(...found) : 0;
};

export default function WhatsAppTemplatesCatalog({ info, canSave, simulated, onChange }: {
  info: MetaTemplatesInfo;
  canSave: boolean;
  simulated: boolean;
  onChange: (catalog: CatalogTemplate[], notice: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (!info.ready) return null;

  const params = countParams(form.body);

  const submit = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/messaging/whatsapp/templates', { ...form, examples: form.examples.slice(0, params) });
      onChange(res.data.catalog, res.data.message);
      setOpen(false);
      setForm(EMPTY);
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar la plantilla.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-2" data-wa-catalog>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className={labelClass}>Tus plantillas en {simulated ? 'esta prueba' : 'Meta'}</span>
        {canSave && (
          <button type="button" data-new-template onClick={() => { setOpen(true); setError(''); }} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
            <Plus size={14} /> Enviar una plantilla a revisión
          </button>
        )}
      </div>
      <p className="text-xs text-slate-500">
        {info.verifiable
          ? 'Antes de enviar un aviso fuera de las 24 horas, el sistema comprueba que su plantilla esté aprobada por Meta. Si no lo está, el aviso sale por correo y se explica por qué.'
          : simulated ? 'En "Probar sin enviar" las plantillas que envíes quedan aprobadas al instante, para probar.' : 'Para comprobar el estado de cada plantilla falta el identificador de la cuenta de WhatsApp Business. Sin él, los avisos se mandan y Meta decide si los acepta.'}
        {info.synced_at ? ` Última lectura: ${momentOf(info.synced_at)}.` : ''}
      </p>
      <div className="rounded-xl border border-slate-200 overflow-hidden">
        <PagedList
          items={info.catalog}
          empty="Aún no hay plantillas guardadas. Pulsa “Traer las plantillas aprobadas” o envía una a revisión."
          renderRow={(t) => (
            <li key={t.id} className="px-4 py-3 space-y-1" data-catalog-template={t.name}>
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-bold text-slate-800 font-mono break-all">{t.name}</span>
                <span className="text-xs text-slate-400">{t.language}</span>
                <Badge tone={TEMPLATE_TONES[t.status] ?? TEMPLATE_TONES.UNKNOWN} nowrap data-template-status={t.status}>{t.status_label}</Badge>
              </div>
              {t.body && <p className="text-xs text-slate-600 whitespace-pre-line">{t.body}</p>}
              {t.reason && <p className="text-xs text-rose-700">Motivo de Meta: {t.reason}</p>}
            </li>
          )}
        />
      </div>

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title="Enviar una plantilla a revisión" subtitle="Meta la revisa y responde en minutos u horas" icon={<FileText size={18} />}>
        <div className="space-y-4">
          <label className="block space-y-1">
            <span className={labelClass}>Nombre</span>
            <input aria-label="Nombre de la plantilla" maxLength={120} className={`${inputClass} font-mono`} placeholder="recordatorio_cita" value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '') })} />
            <span className="block text-xs text-slate-500">En minúsculas y con guion bajo, sin espacios.</span>
          </label>
          <div className="grid grid-cols-1 sm:grid-cols-[1fr_7rem] gap-3">
            <div className="space-y-1">
              <span className={labelClass}>Para qué es</span>
              <Select ariaLabel="Categoría de la plantilla" value={form.category} options={info.categories} onChange={(e) => setForm({ ...form, category: String(e.target.value) })} />
            </div>
            <label className="block space-y-1">
              <span className={labelClass}>Idioma</span>
              <input aria-label="Idioma de la plantilla" maxLength={10} className={`${inputClass} font-mono`} value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value.replace(/[^a-zA-Z_]/g, '') })} />
            </label>
          </div>
          <label className="block space-y-1">
            <span className={labelClass}>Texto</span>
            <textarea aria-label="Texto de la plantilla" rows={5} maxLength={1024} className={inputClass} placeholder="Hola {{1}}, te recordamos tu cita de {{2}} el {{3}}." value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
            <span className="block text-xs text-slate-500">Los datos que cambian van como {'{{1}}'}, {'{{2}}'}… en orden. Para los avisos automáticos usa el mismo orden que indica cada aviso.</span>
          </label>
          {params > 0 && (
            <div className="space-y-2" data-template-examples>
              <span className={labelClass}>Un ejemplo de cada dato (Meta lo pide)</span>
              {Array.from({ length: params }, (_, i) => (
                <input key={i} aria-label={`Ejemplo del dato ${i + 1}`} maxLength={200} className={inputClass} placeholder={`Ejemplo de {{${i + 1}}}`} value={form.examples[i] ?? ''}
                  onChange={(e) => { const examples = [...form.examples]; examples[i] = e.target.value; setForm({ ...form, examples }); }} />
              ))}
            </div>
          )}
          <ErrorAlert message={error} />
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setOpen(false)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl">Cancelar</button>
            <button type="button" data-submit-template disabled={busy || !form.name || form.body.trim().length < 10} onClick={submit} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm disabled:opacity-50">
              {busy ? 'Enviando...' : 'Enviar a revisión'}
            </button>
          </div>
        </div>
      </FormDrawer>
    </div>
  );
}
