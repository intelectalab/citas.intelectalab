'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Loader2, PhoneCall } from 'lucide-react';
import { api } from '@/lib/axios';

export default function ContactForm({ businessType }: { businessType?: string }) {
  const [form, setForm] = useState({ name: '', phone: '', business: '', consent: false, website: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState('');

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      let utm: Record<string, string> = {};
      try { utm = JSON.parse(localStorage.getItem('site_utm') ?? '{}') ?? {}; } catch {}
      const res = await api.post('/web/leads', { ...form, business_type: businessType || null, utm });
      setDone(res.data.message ?? '¡Gracias! Te llamaremos pronto.');
    } catch (err: any) {
      setError(err.response?.data?.message ?? 'No pudimos enviar tus datos. Inténtalo otra vez.');
    } finally {
      setBusy(false);
    }
  };

  const field = 'w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white';

  if (done) {
    return <p className="p-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold" data-contact-done>{done}</p>;
  }

  return (
    <form onSubmit={send} className="bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 shadow-sm space-y-3" data-contact-form>
      <div className="flex items-center gap-2 text-brand-ink font-bold"><PhoneCall size={18} className="text-brand-accent" /> ¿Prefieres que te llamemos?</div>
      <p className="text-xs text-slate-500">Déjanos tu nombre y celular, y una persona del equipo te explica todo sin compromiso.</p>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <input aria-label="Tu nombre" required minLength={2} maxLength={150} placeholder="Tu nombre" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={field} />
        <input aria-label="Tu celular" required minLength={6} maxLength={30} inputMode="tel" placeholder="Tu celular" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={field} />
      </div>
      <input aria-label="Nombre de tu negocio" maxLength={200} placeholder="Nombre de tu negocio (opcional)" value={form.business} onChange={(e) => setForm({ ...form, business: e.target.value })} className={field} />
      <input tabIndex={-1} autoComplete="off" aria-hidden="true" value={form.website} onChange={(e) => setForm({ ...form, website: e.target.value })} className="hidden" name="website" />
      <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
        <input type="checkbox" checked={form.consent} onChange={(e) => setForm({ ...form, consent: e.target.checked })} className="mt-0.5 accent-[var(--brand-solid)]" required />
        <span>Acepto que me contacten y la <Link href="/legal/privacidad" className="font-semibold text-brand-accent hover:text-brand-ink">política de privacidad</Link>.</span>
      </label>
      {error && <p className="text-xs font-semibold text-rose-600">{error}</p>}
      <button type="submit" disabled={busy} className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-60 cursor-pointer">
        {busy && <Loader2 size={15} className="animate-spin" />} Quiero que me llamen
      </button>
    </form>
  );
}
