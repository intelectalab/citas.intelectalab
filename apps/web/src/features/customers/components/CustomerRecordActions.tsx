'use client';

import { useEffect, useState } from 'react';
import { Eraser, GitMerge, Loader2, Search, ShieldAlert } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';

interface Props {
  customer: { id: string; first_name: string; last_name: string; code?: string | null; anonymized_at?: string | null };
  onChanged: () => void;
}

interface Found { id: string; first_name: string; last_name: string; code: string | null; document_number: string | null; phone: string | null; email: string | null }

interface Preview {
  duplicate: { id: string; code: string | null; name: string; document_number: string | null; phone: string | null; email: string | null };
  counts: { appointments: number; sales: number; packages: number; records: number; quotes: number };
  fills: string[];
}

const FIELD_LABELS: Record<string, string> = {
  type_document_id: 'tipo de documento', document_number: 'documento', email: 'correo', phone: 'celular', birth_date: 'fecha de nacimiento',
  biological_sex: 'sexo', blood_type: 'grupo sanguíneo', address: 'dirección', district: 'distrito', department: 'departamento',
  postal_code: 'código postal', emergency_contact_name: 'contacto de emergencia', emergency_contact_phone: 'teléfono de emergencia', medical_notes: 'notas médicas',
};

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs';

export default function CustomerRecordActions({ customer, onChanged }: Props) {
  const vocab = useVocabulary();
  const client = vocab.client.toLowerCase();
  const [mode, setMode] = useState<'merge' | 'erase' | null>(null);
  const [search, setSearch] = useState('');
  const [found, setFound] = useState<Found[]>([]);
  const [searching, setSearching] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    setSearch(''); setFound([]); setPreview(null); setConfirm(''); setError('');
  }, [mode]);

  useEffect(() => {
    if (mode !== 'merge' || search.trim().length < 2) { setFound([]); return; }
    const timer = setTimeout(() => {
      setSearching(true);
      api.get('/customers', { params: { search: search.trim(), per_page: 10 } })
        .then((res) => setFound(((res.data.data ?? res.data) as Found[]).filter((row) => row.id !== customer.id)))
        .catch(() => setFound([]))
        .finally(() => setSearching(false));
    }, 300);
    return () => clearTimeout(timer);
  }, [search, mode, customer.id]);

  const pick = async (row: Found) => {
    try {
      setError('');
      const res = await api.post(`/customers/${customer.id}/merge-preview`, { duplicate_id: row.id });
      setPreview(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo revisar esa ficha.'));
    }
  };

  const merge = async () => {
    if (!preview) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.post(`/customers/${customer.id}/merge`, { duplicate_id: preview.duplicate.id });
      setNotice(res.data.message);
      setMode(null);
      onChanged();
    } catch (err) {
      setError(apiError(err, 'No se pudieron unir las fichas.'));
    } finally {
      setBusy(false);
    }
  };

  const erase = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post(`/customers/${customer.id}/anonymize`, { confirm });
      setNotice(res.data.message);
      setMode(null);
      onChanged();
    } catch (err) {
      setError(apiError(err, 'No se pudieron borrar los datos.'));
    } finally {
      setBusy(false);
    }
  };

  const name = `${customer.first_name} ${customer.last_name}`.trim();

  return (
    <section className={`${card} p-5 space-y-3`} data-card="customer-record-actions">
      <h2 className="text-sm font-extrabold text-brand-ink uppercase tracking-wider">Ficha</h2>
      <SuccessAlert message={notice} />
      {customer.anonymized_at ? (
        <p className="text-sm text-slate-600">Los datos personales de esta ficha se borraron. Sus citas, ventas y fichas de atención se conservan sin su nombre.</p>
      ) : (
        <div className="flex flex-wrap gap-2">
          <button type="button" data-action="merge-customer" onClick={() => setMode('merge')} className="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50">
            <GitMerge size={15} className="text-brand-accent" /> Unir con otra ficha
          </button>
          <button type="button" data-action="erase-customer" onClick={() => setMode('erase')} className="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50">
            <Eraser size={15} /> Borrar datos personales
          </button>
        </div>
      )}

      <FormDrawer isOpen={mode === 'merge'} onClose={() => setMode(null)} title="Unir con otra ficha" subtitle={`Se queda la ficha de ${name}`} icon={<GitMerge size={18} />}>
        <div className="space-y-4" data-merge-panel>
          <p className="text-sm text-slate-600">Para cuando la misma persona quedó registrada dos veces. Elige la ficha repetida: sus citas, ventas, paquetes y fichas de atención pasan a esta, y la repetida se elimina.</p>
          {!preview ? (
            <>
              <label className="relative block">
                <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Busca por nombre, documento o celular" aria-label="Buscar ficha repetida" className={`${inputClass} pl-9`} />
              </label>
              {searching && <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={14} className="animate-spin" /> Buscando...</p>}
              <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200 empty:hidden">
                {found.map((row) => (
                  <li key={row.id}>
                    <button type="button" data-merge-candidate={row.id} onClick={() => pick(row)} className="w-full text-left px-4 py-3 hover:bg-slate-50">
                      <span className="block text-sm font-bold text-slate-800">{row.first_name} {row.last_name}{row.code ? <span className="font-medium text-slate-400"> · {row.code}</span> : null}</span>
                      <span className="block text-xs text-slate-500">{[row.document_number, row.phone, row.email].filter(Boolean).join(' · ') || 'Sin documento ni contacto'}</span>
                    </button>
                  </li>
                ))}
              </ul>
              {search.trim().length >= 2 && !searching && found.length === 0 && <p className="text-sm text-slate-500">No encontramos otra ficha con eso.</p>}
            </>
          ) : (
            <div className="space-y-3" data-merge-preview>
              <div className="rounded-2xl border border-slate-200 p-4 space-y-1">
                <p className="text-sm font-bold text-slate-800">{preview.duplicate.name}{preview.duplicate.code ? ` · ${preview.duplicate.code}` : ''}</p>
                <p className="text-xs text-slate-500">{[preview.duplicate.document_number, preview.duplicate.phone, preview.duplicate.email].filter(Boolean).join(' · ') || 'Sin documento ni contacto'}</p>
              </div>
              <p className="text-sm text-slate-700">
                Pasan a la ficha de {name}: {preview.counts.appointments} citas, {preview.counts.sales} ventas, {preview.counts.packages} paquetes, {preview.counts.records} fichas de atención y {preview.counts.quotes} presupuestos.
              </p>
              {preview.fills.length > 0 && (
                <p className="text-sm text-slate-700">Se completan los datos que le faltan a esta ficha: {preview.fills.map((field) => FIELD_LABELS[field] ?? field).join(', ')}.</p>
              )}
              <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex gap-2"><ShieldAlert size={15} className="shrink-0 mt-0.5" /> No se puede deshacer. Revisa que sean la misma persona.</p>
              <ErrorAlert message={error} />
              <div className="flex gap-2">
                <button type="button" data-merge-confirm disabled={busy} onClick={merge} className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">{busy ? 'Uniendo...' : 'Unir fichas'}</button>
                <button type="button" onClick={() => setPreview(null)} className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl">Elegir otra</button>
              </div>
            </div>
          )}
          {!preview && <ErrorAlert message={error} />}
        </div>
      </FormDrawer>

      <FormDrawer isOpen={mode === 'erase'} onClose={() => setMode(null)} title="Borrar datos personales" subtitle={name} icon={<Eraser size={18} />}>
        <div className="space-y-4" data-erase-panel>
          <p className="text-sm text-slate-600">Para cuando {vocab.pick('client', 'el', 'la')} {client} pide que borren sus datos (Ley 29733). Se borran su nombre, documento, celular, correo, dirección, fecha de nacimiento y notas, su acceso al portal y sus permisos de mensajes.</p>
          <p className="text-sm text-slate-600">Se conservan sus citas, ventas, comprobantes y fichas de atención, sin su nombre, porque la empresa debe guardarlos por ley.</p>
          <p className="text-xs text-rose-800 bg-rose-50 border border-rose-200 rounded-xl px-4 py-3 flex gap-2"><ShieldAlert size={15} className="shrink-0 mt-0.5" /> No se puede deshacer.</p>
          <label className="block">
            <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Escribe BORRAR para confirmar</span>
            <input value={confirm} onChange={(e) => setConfirm(e.target.value.toUpperCase())} aria-label="Confirmación" className={inputClass} />
          </label>
          <ErrorAlert message={error} />
          <button type="button" data-erase-confirm disabled={busy || confirm !== 'BORRAR'} onClick={erase} className="px-4 py-2 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{busy ? 'Borrando...' : 'Borrar datos personales'}</button>
        </div>
      </FormDrawer>
    </section>
  );
}
