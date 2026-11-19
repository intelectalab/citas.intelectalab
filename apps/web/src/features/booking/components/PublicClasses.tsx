'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { CheckCircle2, MapPin, User, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { PagedList } from '@/app/components/common/Pager';
import Select from '@/app/components/common/Select';
import GuestFields, { emptyGuest, guestReady, type GuestData } from '@/features/portal/components/GuestFields';
import { portalError } from '@/features/portal/portal';
import type { PortalClass } from '@/features/portal/components/PortalClasses';

interface PublicClassesProps {
  slug: string;
  documentTypes: { id: string; name: string; value: string | null }[];
  privacyHtml: string | null;
  dependentLabel: string;
}

const dayLabel = (date: string) => {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
};

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now().toString(16)}0000-0000-4000-8000-000000000000`.slice(0, 36));

export default function PublicClasses({ slug, documentTypes, privacyHtml, dependentLabel }: PublicClassesProps) {
  const [classes, setClasses] = useState<PortalClass[] | null>(null);
  const [kind, setKind] = useState('');
  const [chosen, setChosen] = useState<PortalClass | null>(null);
  const [guest, setGuest] = useState<GuestData>(emptyGuest);
  const [requestKey, setRequestKey] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState('');

  const load = useCallback(() => {
    api.get(`/public/booking/${slug}/classes`)
      .then((res) => setClasses(res.data.classes ?? []))
      .catch(() => setClasses([]));
  }, [slug]);

  useEffect(() => { load(); }, [load]);

  const kinds = useMemo(() => [...new Set((classes ?? []).map((c) => c.title))].sort(), [classes]);

  if (!classes || classes.length === 0) return null;

  const rows = classes.filter((c) => !kind || c.title === kind);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!chosen) return;
    setError('');
    try {
      setBusy(true);
      const res = await api.post(`/public/booking/${slug}/classes/${chosen.id}/enroll`, { ...guest, type_document_id: guest.type_document_id || null, request_key: requestKey });
      setDone(res.data?.message ?? 'Listo.');
      setChosen(null);
      setGuest(emptyGuest);
      load();
    } catch (err) {
      setError(portalError(err, 'No se pudo completar la inscripción.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 overflow-hidden" data-public-classes>
      <div className="px-5 sm:px-6 pt-5 pb-3 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-base font-black text-brand-ink"><Users size={18} className="text-brand-accent" /> Clases grupales</h2>
          <p className="text-xs text-slate-500 mt-1">Elige una clase e inscríbete con tus datos. Si está llena, quedas en la lista de espera.</p>
        </div>
        {kinds.length > 1 && (
          <div className="sm:w-56">
            <Select ariaLabel="Filtrar por clase" placeholder="Todas las clases" value={kind} onChange={(e) => setKind(String(e.target.value))} options={kinds.map((k) => ({ value: k, label: k }))} />
          </div>
        )}
      </div>

      {done && <p className="mx-5 sm:mx-6 mb-3 flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-3" data-public-class-done><CheckCircle2 size={16} /> {done}</p>}

      {chosen ? (
        <form onSubmit={submit} className="px-5 sm:px-6 pb-6 space-y-4" data-public-class-form>
          <div className="p-3 rounded-xl bg-slate-50 border border-slate-200 text-sm">
            <span className="block font-bold text-brand-ink">{chosen.title}</span>
            <span className="block text-xs text-slate-600 first-letter:uppercase">{dayLabel(chosen.date)} · {chosen.time}–{chosen.end_time}{chosen.branch ? ` · ${chosen.branch.name}` : ''}</span>
          </div>
          <GuestFields value={guest} onChange={setGuest} documentTypes={documentTypes} dependents={{ enabled: false, required: false }} dependentLabel={dependentLabel} privacyHtml={privacyHtml} />
          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}
          <div className="flex justify-end gap-3">
            <button type="button" onClick={() => setChosen(null)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Volver</button>
            <button type="submit" data-public-class-submit disabled={busy || !guestReady(guest, false)} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
              {busy ? 'Guardando...' : chosen.free > 0 ? 'Inscribirme' : 'Anotarme en espera'}
            </button>
          </div>
        </form>
      ) : (
        <PagedList
          items={rows}
          empty="No hay clases en las próximas semanas."
          renderRow={(c) => (
            <li key={c.id} className="flex flex-col sm:flex-row sm:items-center gap-3 px-5 sm:px-6 py-3.5" data-public-class={c.id}>
              <div className="flex-1 min-w-0 space-y-0.5">
                <span className="block text-sm font-bold text-brand-ink">{c.title}</span>
                <span className="block text-xs text-slate-600 first-letter:uppercase">{dayLabel(c.date)} · {c.time}–{c.end_time}</span>
                <span className="flex flex-wrap gap-x-3 text-xs text-slate-500">
                  {c.professional && <span className="inline-flex items-center gap-1"><User size={12} /> {c.professional.display_name}</span>}
                  {(c.room || c.branch) && <span className="inline-flex items-center gap-1"><MapPin size={12} /> {[c.room?.name, c.branch?.name].filter(Boolean).join(' · ')}</span>}
                  <span className="inline-flex items-center gap-1"><Users size={12} /> {c.free > 0 ? `${c.free} ${c.free === 1 ? 'cupo libre' : 'cupos libres'}` : 'Completa'}</span>
                </span>
              </div>
              <button type="button" data-public-class-pick onClick={() => { setDone(''); setError(''); setRequestKey(newKey()); setChosen(c); }} className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors shrink-0">
                {c.free > 0 ? 'Inscribirme' : 'Anotarme en espera'}
              </button>
            </li>
          )}
        />
      )}
    </section>
  );
}
