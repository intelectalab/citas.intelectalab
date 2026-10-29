'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { AlertTriangle, CalendarCheck, CheckCircle2, Clock, CreditCard, Loader2, ShieldCheck, XCircle } from 'lucide-react';
import { api } from '@/lib/axios';
import { setCurrencies, type CurrencyInfo } from '@/lib/currency';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';

interface PublicLink {
  status: 'pending' | 'paid' | 'expired' | 'cancelled' | 'failed';
  status_label: string;
  purpose: 'sale' | 'deposit';
  amount: number;
  currency: string;
  currency_info: CurrencyInfo;
  description: string;
  expires_at: string;
  paid_at: string | null;
  reason: string | null;
  rejected: boolean;
  simulated: boolean;
  provider: string;
  company: { name: string; color: string | null; logo: string | null };
  booking: { service: string | null; professional: string | null; branch: string | null; starts_at: string | null; price: number | null } | null;
  appointment: string | null;
}

const minutesLeft = (iso: string) => Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 60000));

export default function PayPage() {
  const params = useParams<{ token: string }>();
  const [link, setLink] = useState<PublicLink | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState<'' | 'approved' | 'rejected'>('');
  const [, setTick] = useState(0);

  const apply = (data: PublicLink) => {
    setCurrencies([data.currency_info], data.currency);
    setLink(data);
  };

  const load = useCallback(() => {
    api.get(`/pay/${params.token}`)
      .then((res) => { apply(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo abrir este link de pago.')));
  }, [params.token]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    const timer = setInterval(() => setTick((t) => t + 1), 30000);
    return () => clearInterval(timer);
  }, []);

  const simulate = async (result: 'approved' | 'rejected') => {
    try {
      setBusy(result);
      setError('');
      setNotice('');
      const res = await api.post(`/pay/${params.token}/simulate`, { result });
      apply(res.data.link);
      if (res.data.result?.status === 'rejected') setNotice(res.data.result.message);
      if (res.data.result?.status === 'refunded') setError(res.data.result.message);
    } catch (err: any) {
      const fresh: PublicLink | undefined = err?.response?.data?.link;
      if (fresh) apply(fresh);
      if (!fresh || fresh.status === 'pending') setError(err?.response?.data?.result?.message ?? apiError(err, 'No se pudo completar el pago.'));
    } finally {
      setBusy('');
    }
  };

  if (!link) {
    return (
      <div className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4">
        <p className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Abriendo el link de pago...'}</p>
      </div>
    );
  }

  const left = minutesLeft(link.expires_at);
  const accent = link.company.color || '#022340';
  const isDeposit = link.purpose === 'deposit';

  return (
    <div className="min-h-screen bg-[#F2F2F2] py-10 px-4 font-sans" data-pay-page={link.status}>
      <div className="max-w-md mx-auto space-y-4">
        <header className="flex items-center gap-3">
          {link.company.logo
            ? <img src={link.company.logo} alt="" className="w-11 h-11 rounded-xl object-cover" />
            : <span className="w-11 h-11 rounded-xl flex items-center justify-center text-white font-black" style={{ background: accent }}>{link.company.name.slice(0, 1)}</span>}
          <div>
            <span className="block text-base font-black text-brand-ink">{link.company.name}</span>
            <span className="block text-xs text-slate-500">{isDeposit ? 'Adelanto para reservar tu cita' : 'Link de pago'}</span>
          </div>
        </header>

        {link.simulated && (
          <p className="flex items-start gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-simulated>
            <AlertTriangle size={15} className="shrink-0 mt-0.5" />
            <span><span className="font-bold">Pago de prueba: no se cobra dinero.</span> Esta empresa aún no conecta su pasarela de pagos; los botones de abajo simulan lo que respondería la pasarela.</span>
          </p>
        )}

        <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
          <div className="px-6 py-5 border-b border-slate-100">
            <span className="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">{isDeposit ? 'Adelanto' : 'Total a pagar'}</span>
            <span className="block text-4xl font-black text-brand-ink tabular-nums" data-pay-amount>{money(link.amount, link.currency)}</span>
            <span className="block text-sm text-slate-600 mt-1">{link.description}</span>
          </div>

          {link.booking && (
            <dl className="px-6 py-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm border-b border-slate-100" data-pay-booking>
              {([
                ['Servicio', link.booking.service],
                ['Cuándo', link.booking.starts_at ? `${formatDate(link.booking.starts_at.slice(0, 10), { weekday: 'long', day: 'numeric', month: 'long' })}, ${link.booking.starts_at.slice(11)}` : null],
                ['Con', link.booking.professional],
                ['Sede', link.booking.branch],
                ['Precio del servicio', link.booking.price !== null ? money(link.booking.price, link.currency) : null],
                ['Pagas en la sede', link.booking.price !== null ? money(Math.max(0, link.booking.price - link.amount), link.currency) : null],
              ] as [string, string | null][]).filter(([, value]) => value).map(([label, value]) => (
                <div key={label}>
                  <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">{label}</dt>
                  <dd className="font-semibold text-slate-800 first-letter:uppercase">{value}</dd>
                </div>
              ))}
            </dl>
          )}

          <div className="px-6 py-5 space-y-4">
            {link.status === 'pending' && (
              <>
                {isDeposit && (
                  <p className="flex items-start gap-2 text-xs text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-xl px-4 py-3" data-hold-warning>
                    <Clock size={15} className="shrink-0 mt-0.5" />
                    <span>Tu horario se reserva recién al pagar el adelanto. Si demoras, otra persona puede tomarlo: en ese caso este link se anula y no se te cobra nada.</span>
                  </p>
                )}
                <p className="text-xs text-slate-500" data-expires>
                  {left > 90 ? `Este link vence el ${momentOf(link.expires_at)}.` : `Este link vence en ${left} ${left === 1 ? 'minuto' : 'minutos'}.`}
                </p>
                {link.rejected && !notice && <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">Tu último intento fue rechazado. Puedes intentarlo de nuevo.</p>}
                {notice && <p role="status" data-pay-notice className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">{notice}</p>}

                {link.simulated ? (
                  <div className="space-y-2" data-simulate>
                    <button type="button" data-simulate-result="approved" disabled={busy !== ''} onClick={() => simulate('approved')} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold text-white rounded-xl shadow-sm transition-colors disabled:opacity-50" style={{ background: accent }}>
                      {busy === 'approved' ? <Loader2 size={16} className="animate-spin" /> : <CreditCard size={16} />} Simular pago aprobado
                    </button>
                    <button type="button" data-simulate-result="rejected" disabled={busy !== ''} onClick={() => simulate('rejected')} className="w-full px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 bg-white rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50">
                      {busy === 'rejected' ? 'Enviando...' : 'Simular pago rechazado'}
                    </button>
                  </div>
                ) : (
                  <p className="text-sm text-slate-600">Paga con tarjeta, Yape o Plin a través de {link.provider}.</p>
                )}
              </>
            )}

            {link.status === 'paid' && (
              <div className="text-center space-y-2" data-pay-done>
                <span className="w-14 h-14 mx-auto rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center"><CheckCircle2 size={28} /></span>
                <h1 className="text-lg font-black text-brand-ink">{isDeposit ? '¡Pagaste tu adelanto!' : '¡Pago recibido!'}</h1>
                <p className="text-sm text-slate-600">
                  {isDeposit
                    ? `Tu cita${link.appointment ? ` ${link.appointment}` : ''} quedó reservada y confirmada. El resto se paga en la sede.`
                    : `${link.company.name} ya recibió tu pago${link.paid_at ? ` (${momentOf(link.paid_at)})` : ''}.`}
                </p>
              </div>
            )}

            {['expired', 'cancelled', 'failed'].includes(link.status) && (
              <div className="text-center space-y-2" data-pay-closed>
                <span className="w-14 h-14 mx-auto rounded-full bg-slate-100 text-slate-500 flex items-center justify-center"><XCircle size={28} /></span>
                <h1 className="text-lg font-black text-brand-ink">{link.status === 'expired' ? 'Este link venció' : 'Este link ya no está disponible'}</h1>
                <p className="text-sm text-slate-600">
                  {link.reason ?? (link.status === 'expired'
                    ? isDeposit ? 'Pasó el tiempo para pagar el adelanto. Si el horario sigue libre, vuelve a reservarlo.' : 'Pídele a la empresa un link nuevo.'
                    : 'Pídele a la empresa un link nuevo.')}
                </p>
                <p className="text-xs text-slate-400">No se te cobró nada.</p>
              </div>
            )}

            {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}

            {isDeposit && link.status !== 'pending' && (
              <Link href="/portal" data-back-portal className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors">
                <CalendarCheck size={16} /> {link.status === 'paid' ? 'Ver mis citas' : 'Elegir otro horario'}
              </Link>
            )}
          </div>
        </section>

        <p className="flex items-center justify-center gap-1.5 text-xs text-slate-400">
          <ShieldCheck size={13} /> {link.simulated ? 'Simulación de pasarela' : `Pago procesado por ${link.provider}`}
        </p>
      </div>
    </div>
  );
}
