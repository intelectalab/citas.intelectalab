'use client';

import { useEffect, useState } from 'react';
import { ArrowLeft, Loader2, Mail, MapPin, Phone, ShieldCheck } from 'lucide-react';
import { api } from '@/lib/axios';
import RichTextView from '@/app/components/common/RichTextView';
import { portalError, type PortalOverview } from '../portal';

const dateOf = (iso: string) => new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });

export function PortalContact({ contact }: { contact: NonNullable<NonNullable<PortalOverview['look']>['contact']> }) {
  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-3" data-card="portal-contact">
      <h2 className="text-sm font-bold text-brand-ink">Contáctanos</h2>
      <div className="flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-slate-700">
        {contact.phone && <a href={`tel:${contact.phone.replace(/\s+/g, '')}`} className="flex items-center gap-1.5 hover:text-brand-ink"><Phone size={14} className="text-brand-accent" /> {contact.phone}</a>}
        {contact.email && <a href={`mailto:${contact.email}`} className="flex items-center gap-1.5 hover:text-brand-ink break-all"><Mail size={14} className="text-brand-accent" /> {contact.email}</a>}
      </div>
      {contact.branches.length > 0 && (
        <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {contact.branches.map((branch) => (
            <li key={branch.id} className="rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs text-slate-600 space-y-0.5" data-portal-branch>
              <span className="block text-sm font-semibold text-slate-800">{branch.name}</span>
              {branch.address && <span className="flex items-start gap-1.5"><MapPin size={13} className="text-brand-accent shrink-0 mt-0.5" /> {branch.address}</span>}
              {branch.phone && <a href={`tel:${branch.phone.replace(/\s+/g, '')}`} className="flex items-center gap-1.5 hover:text-brand-ink"><Phone size={13} className="text-brand-accent shrink-0" /> {branch.phone}</a>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export default function PortalPrivacy({ acceptedAt, onAccepted, onBack }: { acceptedAt: string | null; onAccepted: (at: string | null) => void; onBack: () => void }) {
  const [html, setHtml] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/portal/privacy')
      .then((res) => { setHtml(res.data.html ?? ''); setUpdatedAt(res.data.updated_at ?? null); })
      .catch((err) => setError(portalError(err, 'No se pudo cargar la política de privacidad.')));
  }, []);

  const accept = async () => {
    try {
      setBusy(true);
      const res = await api.post('/portal/privacy/accept');
      onAccepted(res.data.accepted_at ?? null);
    } catch (err) {
      setError(portalError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-7 space-y-4" data-card="portal-privacy">
      <button type="button" onClick={onBack} className="inline-flex items-center gap-1.5 text-sm font-bold text-brand-accent hover:text-brand-ink">
        <ArrowLeft size={16} /> Volver
      </button>
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}
      {html === null && !error && <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando...</p>}
      {html !== null && (
        <>
          <RichTextView body={html} className="text-sm text-slate-700 [&_h2]:text-brand-ink [&_h3]:text-brand-ink" data-privacy-text />
          {updatedAt && <p className="text-xs text-slate-400">Última modificación: {dateOf(updatedAt)}.</p>}
          <div className="pt-3 border-t border-slate-100">
            {acceptedAt ? (
              <p className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700" data-privacy-accepted><ShieldCheck size={14} /> La aceptaste el {dateOf(acceptedAt)}.</p>
            ) : (
              <button type="button" data-action="accept-privacy" disabled={busy} onClick={accept} className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
                He leído y acepto
              </button>
            )}
          </div>
        </>
      )}
    </section>
  );
}
