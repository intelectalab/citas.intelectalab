'use client';

import { useEffect, useMemo, useState } from 'react';
import { CalendarCheck, CalendarClock, CalendarX2, Check, Clock, Loader2, MapPin, UserRound, Video } from 'lucide-react';
import { isAxiosError } from 'axios';
import { api } from '@/lib/axios';
import { previewStyle, resolveColors } from '@/lib/brand';
import { dateOf, formatDate, timeOf } from '@/features/appointments/appointments';
import { portalError, type PortalAppointment } from '@/features/portal/portal';

interface LinkData {
  company: { name: string; logo: string | null; primary: string | null; accent: string | null; slug: string };
  contact: { phone: string | null; email: string | null } | null;
  customer: string;
  cancel_window_hours: number;
  cancel_allowed: boolean;
  appointment: PortalAppointment;
}

interface ExpiredLink {
  message: string;
  reason: 'cancelled' | 'past';
  company: { name: string; slug: string };
}

export default function AppointmentLinkPage({ token }: { token: string }) {
  const [data, setData] = useState<LinkData | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');
  const [done, setDone] = useState('');
  const [expired, setExpired] = useState<ExpiredLink | null>(null);

  useEffect(() => {
    api.get<LinkData>(`/public/appointment/${token}`)
      .then((res) => { setData(res.data); document.title = `Tu cita · ${res.data.company.name}`; })
      .catch((err) => {
        if (isAxiosError(err) && err.response?.status === 410 && err.response.data?.code === 'LINK_EXPIRED') {
          setExpired(err.response.data as ExpiredLink);
          return;
        }
        setError(portalError(err, 'No se pudo abrir tu cita.'));
      });
  }, [token]);

  const style = useMemo(() => (data ? previewStyle(resolveColors({ primary: data.company.primary, accent: data.company.accent }), false) : undefined), [data]);

  const act = async (action: 'confirm' | 'cancel') => {
    if (!data) return;
    try {
      setBusy(action);
      setError('');
      const res = await api.post(`/public/appointment/${token}/${action}`, action === 'cancel' ? { reason: reason.trim() || null } : {});
      setData({ ...data, appointment: res.data });
      setCancelling(false);
      setDone(action === 'confirm' ? '¡Gracias! Tu asistencia quedó confirmada.' : 'Tu cita quedó cancelada. El horario queda libre para otra persona.');
    } catch (err) {
      setError(portalError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  if (expired) {
    return (
      <main className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4" data-link-expired={expired.reason}>
        <section className="max-w-md w-full bg-white border border-slate-200/80 rounded-3xl shadow-xs p-6 space-y-4 text-center">
          <span className="mx-auto w-12 h-12 rounded-2xl bg-slate-100 text-slate-500 flex items-center justify-center">{expired.reason === 'cancelled' ? <CalendarX2 size={22} /> : <CalendarClock size={22} />}</span>
          <div className="space-y-1">
            <p className="text-xs font-semibold text-slate-500">{expired.company.name}</p>
            <h1 className="text-lg font-black text-slate-800">{expired.reason === 'cancelled' ? 'Esta cita fue cancelada' : 'Este enlace ya venció'}</h1>
          </div>
          <p className="text-sm text-slate-600">{expired.message}</p>
          <div className="flex flex-col gap-2">
            <a href={`/reservar/${expired.company.slug}`} className="px-5 py-3 text-sm font-bold bg-slate-800 text-white rounded-xl hover:bg-slate-700">Reservar otra cita</a>
            <a href="/login" className="px-5 py-3 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Entrar al portal</a>
          </div>
        </section>
      </main>
    );
  }

  if (!data) {
    return (
      <main className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4">
        {error
          ? <p role="alert" className="max-w-md text-center text-sm text-slate-600 bg-white border border-slate-200 rounded-2xl px-6 py-5">{error}</p>
          : <Loader2 size={22} className="animate-spin text-slate-400" />}
      </main>
    );
  }

  const a = data.appointment;
  const statusText: Record<string, string> = {
    pending: 'Pendiente de confirmar',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Atendida',
    no_show: 'No asistió',
  };

  return (
    <main className="theme-scope min-h-screen bg-[#F2F2F2] text-slate-800 px-4 py-8" style={style} data-appointment-link>
      <div className="max-w-md mx-auto space-y-4">
        <header className="flex items-center gap-3">
          {data.company.logo ? (
            <img src={data.company.logo} alt={data.company.name} className="w-12 h-12 rounded-2xl object-contain bg-white border border-slate-100" />
          ) : (
            <span className="w-12 h-12 rounded-2xl bg-brand text-white flex items-center justify-center"><CalendarClock size={22} /></span>
          )}
          <div>
            <p className="text-xs font-semibold text-slate-500">{data.company.name}</p>
            <h1 className="text-xl font-black text-brand-ink tracking-tight">Hola, {data.customer}</h1>
          </div>
        </header>

        <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-4">
          <div className="flex items-center justify-between gap-3">
            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Tu cita {a.code}</span>
            <span className="text-xs font-bold text-brand-ink bg-brand-soft/20 rounded-full px-2.5 py-1" data-status={a.status}>{statusText[a.status] ?? a.status_name}</span>
          </div>
          <div className="space-y-2">
            <p className="text-lg font-black text-slate-800">{a.service?.name}</p>
            <p className="flex items-center gap-2 text-sm text-slate-600 first-letter:uppercase"><Clock size={15} className="text-brand-accent" /> {formatDate(dateOf(a.starts_at))}, a las {timeOf(a.starts_at)}</p>
            {a.professional && <p className="flex items-center gap-2 text-sm text-slate-600"><UserRound size={15} className="text-brand-accent" /> {a.professional.display_name}</p>}
            {a.branch && <p className="flex items-center gap-2 text-sm text-slate-600"><MapPin size={15} className="text-brand-accent" /> {a.branch.name}</p>}
            {a.video_url && <a data-video-link href={a.video_url} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-sm font-bold text-brand-accent hover:text-brand-ink"><Video size={15} /> Entrar a la videollamada</a>}
          </div>

          {done && <p className="flex items-start gap-2 text-sm text-emerald-800 bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-2" data-done><Check size={16} className="shrink-0 mt-0.5" /> {done}</p>}
          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-3 py-2">{error}</p>}

          {!cancelling ? (
            <div className="flex flex-col gap-2">
              {a.can_confirm && (
                <button type="button" data-action="confirm" disabled={busy !== ''} onClick={() => act('confirm')} className="flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
                  {busy === 'confirm' ? <Loader2 size={16} className="animate-spin" /> : <CalendarCheck size={16} />} Confirmar que asistiré
                </button>
              )}
              {a.can_cancel && (
                <button type="button" data-action="cancel" disabled={busy !== ''} onClick={() => setCancelling(true)} className="flex items-center justify-center gap-2 px-5 py-3 text-sm font-semibold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50">
                  <CalendarX2 size={16} /> No podré ir: cancelar la cita
                </button>
              )}
              {a.is_upcoming && !a.can_cancel && (
                <p className="text-xs text-slate-500">
                  {data.cancel_allowed
                    ? `Ya no se puede cancelar en línea (faltan menos de ${data.cancel_window_hours} horas). Comunícate con la sede${data.contact?.phone ? ` al ${data.contact.phone}` : ''}.`
                    : `Para cambiar o cancelar, comunícate con la sede${data.contact?.phone ? ` al ${data.contact.phone}` : ''}.`}
                </p>
              )}
            </div>
          ) : (
            <div className="space-y-3 rounded-2xl border border-rose-200 bg-rose-50/40 p-4" data-cancel-form>
              <span className="block text-sm font-bold text-rose-800">¿Seguro que quieres cancelar?</span>
              <textarea rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Cuéntanos el motivo (opcional)" aria-label="Motivo" className="w-full text-sm border border-rose-200 rounded-xl px-3.5 py-2.5 bg-white outline-none focus:ring-2 focus:ring-rose-200" />
              <div className="flex gap-2">
                <button type="button" data-action="cancel-confirm" disabled={busy !== ''} onClick={() => act('cancel')} className="px-4 py-2 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{busy === 'cancel' ? 'Cancelando...' : 'Sí, cancelar'}</button>
                <button type="button" onClick={() => setCancelling(false)} className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl">Volver</button>
              </div>
            </div>
          )}
        </section>

        <footer className="flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-slate-500">
          <a href="/login" className="font-semibold hover:text-brand-ink">Ver todas mis citas en el portal</a>
          <a href={`/reservar/${data.company.slug}`} className="font-semibold hover:text-brand-ink">Reservar otra cita</a>
        </footer>
      </div>
    </main>
  );
}
