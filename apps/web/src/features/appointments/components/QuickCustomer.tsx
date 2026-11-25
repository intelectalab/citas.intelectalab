'use client';

import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useRef, useState } from 'react';
import { UserPlus, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { apiError } from '../appointments';
import type { CustomerOption } from './AppointmentForm';

interface Duplicate extends CustomerOption {
  name: string;
  by: string[];
}

interface QuickCustomerProps {
  isOpen: boolean;
  query: string;
  onClose: () => void;
  onChosen: (customer: CustomerOption) => void;
  onFullForm: () => void;
}

const empty = { first_name: '', last_name: '', phone: '', email: '', type_document_id: '', document_number: '', birth_date: '', marketing: false };

export const splitQuery = (query: string) => {
  const text = query.trim();
  const digits = text.replace(/[\s\-+()]/g, '');
  if (/^\d{6,15}$/.test(digits)) {
    return digits.length === 8 ? { document_number: digits } : { phone: digits };
  }
  const found: Partial<typeof empty> = {};
  const words: string[] = [];
  text.split(/\s+/).filter(Boolean).forEach((word) => {
    const number = word.replace(/[\-+()]/g, '');
    if (word.includes('@') && !found.email) found.email = word.toLowerCase();
    else if (/^\d{6,15}$/.test(number) && number.length === 8 && !found.document_number) found.document_number = number;
    else if (/^\d{6,15}$/.test(number) && !found.phone) found.phone = number;
    else if (!/^[\d\-+()]+$/.test(word)) words.push(word);
  });
  if (words.length === 1) return { ...found, first_name: words[0] };
  if (words.length === 2) return { ...found, first_name: words[0], last_name: words[1] };
  if (words.length > 2) return { ...found, first_name: words.slice(0, words.length - 2).join(' '), last_name: words.slice(-2).join(' ') };
  return found;
};

export default function QuickCustomer({ isOpen, query, onClose, onChosen, onFullForm }: QuickCustomerProps) {
  const vocab = useVocabulary();
  const [form, setForm] = useState(empty);
  const [documentTypes, setDocumentTypes] = useState<{ value: string; label: string }[]>([]);
  const [duplicates, setDuplicates] = useState<Duplicate[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const checked = useRef('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setDuplicates([]);
    checked.current = '';
    setForm({ ...empty, ...splitQuery(query) });
    api.get('/type-documents')
      .then((res) => {
        const list = (Array.isArray(res.data) ? res.data : []).map((d: { id: string; name?: string; display_name?: string }) => ({ value: d.id, label: d.name || d.display_name || '' }));
        setDocumentTypes(list);
        setForm((current) => ({ ...current, type_document_id: current.type_document_id || list[0]?.value || '' }));
      })
      .catch(() => setDocumentTypes([]));
  }, [isOpen, query]);

  const keyOf = (values: typeof form) => [values.phone.replace(/\D/g, ''), values.email.trim().toLowerCase(), values.document_number.trim()].join('|');

  useEffect(() => {
    if (!isOpen) return;
    const key = keyOf(form);
    if (key === '||' || (form.phone.replace(/\D/g, '').length < 6 && !form.email.includes('@') && form.document_number.trim().length < 6)) {
      setDuplicates([]);
      return;
    }
    const timer = setTimeout(() => {
      api.get('/appointments/customer-duplicates', { params: { phone: form.phone || undefined, email: form.email.includes('@') ? form.email : undefined, document_number: form.document_number || undefined } })
        .then((res) => { checked.current = key; setDuplicates(res.data?.matches ?? []); })
        .catch(() => setDuplicates([]));
    }, 400);
    return () => clearTimeout(timer);
  }, [isOpen, form.phone, form.email, form.document_number]);

  const set = (changes: Partial<typeof form>) => setForm((current) => ({ ...current, ...changes }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    if (!form.first_name.trim() || !form.last_name.trim()) return setError('Escribe los nombres y los apellidos.');

    try {
      setSaving(true);
      const res = await api.post('/customers', {
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        type_document_id: form.document_number.trim() ? form.type_document_id || null : null,
        document_number: form.document_number.trim() || null,
        birth_date: form.birth_date || null,
      });
      const created = res.data?.customer;
      if (!created?.id) throw new Error();
      if (form.marketing) {
        await api.post('/appointments/customer-consent', { customer_id: created.id }).catch(() => undefined);
      }
      onChosen({ ...created, dependents: created.dependents ?? [] });
      onClose();
    } catch (err) {
      setError(apiError(err, `No se pudo registrar ${vocab.pick('client', 'al', 'a la')} ${vocab.client.toLowerCase()}.`));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'quick-customer-form';
  const byLabel = (by: string[]) => by.length ? `mismo ${by.join(' y ')}` : '';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Registrar ${vocab.client.toLowerCase()}`}
      subtitle="Solo lo necesario para agendar. Lo demás se completa después en su ficha."
      icon={<UserPlus size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-4 overflow-y-auto flex-1 pr-1 pb-4" data-quick-customer>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="qc-first">Nombres *</label>
              <input id="qc-first" required maxLength={255} className={inputClass} value={form.first_name} onChange={(e) => set({ first_name: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="qc-last">Apellidos *</label>
              <input id="qc-last" required maxLength={255} className={inputClass} value={form.last_name} onChange={(e) => set({ last_name: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="qc-phone">Celular</label>
              <input id="qc-phone" type="tel" inputMode="tel" maxLength={50} placeholder="987 654 321" className={inputClass} value={form.phone} onChange={(e) => set({ phone: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="qc-email">Correo</label>
              <input id="qc-email" type="email" maxLength={255} className={inputClass} value={form.email} onChange={(e) => set({ email: e.target.value })} />
            </div>
            <div>
              <Select
                label="Documento"
                clearable={false}
                value={form.type_document_id}
                onChange={(e) => set({ type_document_id: String(e.target.value) })}
                options={documentTypes}
              />
            </div>
            <div>
              <label className={labelClass} htmlFor="qc-doc">Número</label>
              <input id="qc-doc" maxLength={50} className={inputClass} value={form.document_number} onChange={(e) => set({ document_number: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="qc-birth">Fecha de nacimiento</label>
              <DateField id="qc-birth" value={form.birth_date} onChange={(value) => set({ birth_date: value })} />
            </div>
          </div>

          <label className="flex items-start gap-2.5 px-3 py-2.5 border border-slate-200 rounded-lg cursor-pointer" data-marketing>
            <input type="checkbox" checked={form.marketing} onChange={(e) => set({ marketing: e.target.checked })} className="mt-0.5 rounded border-slate-300 text-brand focus:ring-brand" />
            <span>
              <span className="block text-sm font-semibold text-slate-800">Acepta recibir promociones</span>
              <span className="block text-xs text-slate-500">Por correo y WhatsApp. Marca solo si la persona lo autoriza (Ley 29733); puede retirarlo cuando quiera.</span>
            </span>
          </label>

          {duplicates.length > 0 && (
            <div className="border border-amber-200 bg-amber-50 rounded-xl p-3 space-y-2" data-duplicates={duplicates.length}>
              <p className="flex items-start gap-2 text-sm font-semibold text-amber-800">
                <Users size={16} className="mt-0.5 shrink-0" />
                <span>Ya {duplicates.length === 1 ? 'hay una ficha parecida' : `hay ${duplicates.length} fichas parecidas`}. Usa la existente para no repetir.</span>
              </p>
              <ul className="space-y-1.5">
                {duplicates.map((dup) => (
                  <li key={dup.id} className="flex items-center justify-between gap-3 bg-white border border-amber-200 rounded-lg px-3 py-2">
                    <span className="min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{dup.name}</span>
                      <span className="block text-xs text-slate-500 truncate">{[dup.code, dup.phone, dup.document_number, byLabel(dup.by)].filter(Boolean).join(' · ')}</span>
                    </span>
                    <button type="button" data-use-existing={dup.id} onClick={() => { onChosen(dup); onClose(); }} className="shrink-0 px-3 py-1.5 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover">
                      Usar ficha existente
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <ErrorAlert message={error} />

          <button type="button" onClick={onFullForm} className="text-xs font-semibold text-brand-accent hover:text-brand-ink">
            Prefiero llenar la ficha completa
          </button>
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
          <Button type="submit" form={formId} disabled={saving} variant="classic">
            {saving ? 'Guardando...' : duplicates.length > 0 ? 'Registrar de todos modos' : 'Registrar'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
