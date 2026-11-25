'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Loader2, PhoneCall, X } from 'lucide-react';
import { api } from '@/lib/axios';
import Switch from '@/app/components/common/Switch';

const KEY = 'site_popup_seen';

export default function SitePopup({ popup }: { popup: { title: string; text: string | null; delay: number } }) {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ name: '', phone: '', consent: false, website: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState('');
  const first = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (pathname.startsWith('/register') || pathname.startsWith('/legal')) return;
    try { if (localStorage.getItem(KEY)) return; } catch {}
    const timer = window.setTimeout(() => setOpen(true), popup.delay * 1000);
    return () => window.clearTimeout(timer);
  }, [pathname, popup.delay]);

  useEffect(() => {
    if (!open) return;
    first.current?.focus();
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') close(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  const close = () => {
    setOpen(false);
    try { localStorage.setItem(KEY, new Date().toISOString()); } catch {}
  };

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.consent) {
      setError('Para llamarte necesitamos que aceptes la política de privacidad.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      let utm: Record<string, string> = {};
      try { utm = JSON.parse(localStorage.getItem('site_utm') ?? '{}') ?? {}; } catch {}
      const res = await api.post('/web/leads', { ...form, utm });
      setDone(res.data.message ?? '¡Gracias! Te llamaremos pronto.');
      try { localStorage.setItem(KEY, new Date().toISOString()); } catch {}
    } catch (err: any) {
      setError(err.response?.data?.message ?? 'No pudimos enviar tus datos. Inténtalo otra vez.');
    } finally {
      setBusy(false);
    }
  };

  if (!open) return null;

  const field = 'w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white';

  return (
    <div className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-3 sm:p-6 bg-slate-900/40" onClick={close} data-site-popup>
      <div role="dialog" aria-modal="true" aria-labelledby="site-popup-title" className="relative w-full max-w-md bg-white rounded-3xl shadow-2xl p-6 space-y-3" onClick={(e) => e.stopPropagation()}>
        <button type="button" onClick={close} aria-label="Cerrar" className="absolute top-3 right-3 p-1.5 rounded-lg text-slate-400 hover:bg-slate-100 cursor-pointer"><X size={18} /></button>
        <span className="inline-flex p-2.5 rounded-xl bg-brand-soft/25 text-brand-ink"><PhoneCall size={20} /></span>
        <h2 id="site-popup-title" className="text-xl font-black text-brand-ink tracking-tight pr-6">{popup.title}</h2>
        {popup.text && <p className="text-sm text-slate-600">{popup.text}</p>}
        {done ? (
          <p className="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold" data-popup-done>{done}</p>
        ) : (
          <form onSubmit={send} className="space-y-3">
            <input ref={first} aria-label="Tu nombre" required minLength={2} maxLength={150} placeholder="Tu nombre" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={field} />
            <input aria-label="Tu celular" required minLength={6} maxLength={30} inputMode="tel" placeholder="Tu celular" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={field} />
            <input tabIndex={-1} autoComplete="off" aria-hidden="true" value={form.website} onChange={(e) => setForm({ ...form, website: e.target.value })} className="hidden" name="website" />
            <div className="flex items-start gap-2.5 text-xs text-slate-600">
              <Switch checked={form.consent} onChange={(v) => setForm({ ...form, consent: v })} label="Acepto que me contacten y la política de privacidad" />
              <span>Acepto que me contacten y la <Link href="/legal/privacidad" className="font-semibold text-brand-accent hover:text-brand-ink">política de privacidad</Link>.</span>
            </div>
            {error && <p role="alert" className="text-xs font-semibold text-rose-600">{error}</p>}
            <button type="submit" disabled={busy} className="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-60 cursor-pointer">
              {busy && <Loader2 size={15} className="animate-spin" />} Quiero que me llamen
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
