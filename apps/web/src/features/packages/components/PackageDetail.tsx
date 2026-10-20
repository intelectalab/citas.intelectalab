'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams } from 'next/navigation';
import { Ban, Boxes, CalendarClock, CalendarPlus, CheckCircle2, Clock, FileText, History, Hourglass, Layers, Loader2, Receipt, Store, User, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { PagedList } from '@/app/components/common/Pager';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError, statusStyle } from '@/features/appointments/appointments';
import { cleanAmount, money, momentOf } from '@/features/sales/sales';
import SchedulePanel from './SchedulePanel';
import { UsageBar } from './PackagesBoard';
import { STATUS_BADGES, dayLabel, daysLeftLabel, hour12, sessionsLabel, shortDate, type PackageDetailData, type ScheduleDetails } from '../packages';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const actionButton = 'flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold rounded-xl transition-colors disabled:opacity-50';

const EVENT_LABELS: Record<string, string> = {
  sold: 'Venta', scheduled: 'Citas', unscheduled: 'Sesión liberada', extended: 'Más días', cancelled: 'Anulado', refunded: 'Devolución',
};

const LATE_TEXT: Record<string, string> = {
  lose: 'Si avisa a tiempo, se reprograma; si avisa tarde o no llega, la sesión se pierde.',
  reschedule: 'Si no puede venir, la sesión se reprograma siempre.',
};

type Drawer = 'use' | 'extend' | 'cancel' | null;

export default function PackageDetail() {
  const params = useParams();
  const id = String(params?.id ?? '');
  const [data, setData] = useState<PackageDetailData | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [drawer, setDrawer] = useState<Drawer>(null);
  const [scheduling, setScheduling] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState('');

  const [serviceId, setServiceId] = useState('');
  const [note, setNote] = useState('');
  const [days, setDays] = useState('30');
  const [reason, setReason] = useState('');
  const [refund, setRefund] = useState(true);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('cash');
  const [sessionId, setSessionId] = useState('');

  const load = useCallback(() => {
    if (!id) return;
    api.get(`/packages/${id}`)
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el paquete.')));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const { openDetail, panels } = useAppointmentPanels(load);

  const scheduleSource = useMemo(() => (data ? {
    kind: 'package' as const,
    id: data.id,
    name: `${data.code} · ${data.name}`,
    frequency: data.conditions.frequency,
    custom_days: data.conditions.custom_days,
    repeat_weeks: data.conditions.repeat_weeks,
    professional_mode: data.conditions.professional_mode,
    professional_id: data.professional_id,
  } : null), [data]);

  const open = (which: Drawer) => {
    setFormError('');
    setNotice('');
    setReason('');
    setNote('');
    setDays('30');
    if (which === 'use') setServiceId(data?.items.find((i) => i.available > 0)?.service_id ?? '');
    if (which === 'cancel' && data?.refund) {
      setRefund(data.refund.amount > 0 && !data.refund.blocked);
      setAmount(data.refund.amount.toFixed(2));
      setMethod(data.cash_sessions.length ? 'cash' : data.refund_methods.find((m) => m.value !== 'cash')?.value ?? 'cash');
      setSessionId(data.cash_sessions[0]?.id ?? '');
    }
    setDrawer(which);
  };

  const submit = async (url: string, body: Record<string, unknown>, done: (fresh: PackageDetailData) => string) => {
    try {
      setSaving(true);
      setFormError('');
      const res = await api.post(url, body);
      setData(res.data);
      setNotice(done(res.data));
      setDrawer(null);
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setSaving(false);
    }
  };

  const schedule = async (details: ScheduleDetails) => {
    const res = await api.post(`/packages/${id}/schedule`, { slots: details.slots, branch_id: details.branch_id });
    const fresh: PackageDetailData = res.data;
    setData(fresh);
    setScheduling(false);
    const created = fresh.result?.created?.length ?? 0;
    const failed = fresh.result?.failed?.length ?? 0;
    setNotice(`Se agendaron ${sessionsLabel(created)}.${failed ? ` ${failed} no se pudieron agendar porque el horario ya no estaba libre.` : ''}`);
  };

  const backLink = (
    <BackLink href="/packages">Paquetes</BackLink>
  );

  if (!data) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        {error ? <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p> : (
          <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={16} className="animate-spin" /> Cargando paquete...</p>
        )}
      </div>
    );
  }

  const c = data.conditions;
  const isOpen = data.status === 'active' || data.status === 'expired';
  const single = data.items.length === 1 ? data.items[0] : null;
  const canSchedule = data.can.schedule && data.status === 'active' && single !== null && single.available > 0 && Boolean(data.branch);
  const futureAppointments = data.appointments.filter((a) => a.status !== 'completed');
  const refundInfo = data.refund;

  const facts: [React.ReactNode, string, React.ReactNode][] = [
    [<User key="u" size={15} />, 'Cliente', data.customer ? <CustomerLink id={data.customer.id}>{data.customer.name}</CustomerLink> : '—'],
    [<Wallet key="w" size={15} />, 'Precio', money(data.price, data.currency)],
    [<Receipt key="r" size={15} />, 'Venta', data.sale ? `${data.sale.code} · ${data.sale.status_label}` : 'Sin venta registrada'],
    [<Clock key="c" size={15} />, 'Comprado', shortDate(data.purchased_at)],
    [<Hourglass key="h" size={15} />, 'Vigencia', `${daysLeftLabel(data)} (${shortDate(data.expires_at)})`],
    [<Store key="s" size={15} />, 'Vendido en', data.branch?.name ?? '—'],
    ...(data.professional ? [[<User key="p" size={15} />, 'Lo atiende', <ProfessionalLink key="pl" id={data.professional.id}>{data.professional.name}</ProfessionalLink>] as [React.ReactNode, string, React.ReactNode]] : []),
  ];

  const stats = [
    { id: 'used', title: 'Usadas', value: data.sessions_used, note: `de ${data.sessions_total}`, icon: <CheckCircle2 size={18} />, color: 'text-[#022340]' },
    { id: 'scheduled', title: 'Con cita', value: data.sessions_scheduled, note: 'agendadas', icon: <CalendarClock size={18} />, color: 'text-[#3F768C]' },
    { id: 'available', title: 'Sin fecha', value: data.sessions_available, note: 'por agendar', icon: <Layers size={18} />, color: 'text-emerald-600' },
    { id: 'pending', title: 'Por atender', value: money(data.pending_value, data.currency), note: 'ya cobrado', icon: <Wallet size={18} />, color: 'text-violet-600' },
  ];

  return (
    <div className="space-y-5 pb-8 font-sans" data-package-detail={data.code}>
      {backLink}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`} data-card="package">
          <div className="flex items-start gap-3">
            <span className="w-12 h-12 rounded-2xl bg-[#84D0D9]/20 text-[#022340] flex items-center justify-center shrink-0"><Boxes size={22} /></span>
            <div className="min-w-0">
              <h1 className="text-xl font-black text-[#022340] tracking-tight">{data.name}</h1>
              <p className="text-xs text-slate-500 font-mono">{data.code}</p>
              <Badge data-status={data.status} tone={STATUS_BADGES[data.status]} bold className="inline-block mt-2">{data.status_label}</Badge>
            </div>
          </div>

          <dl className="space-y-3">
            {facts.map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="mt-0.5 text-slate-400 shrink-0">{icon}</span>
                <div className="min-w-0">
                  <dt className={smallLabel}>{label}</dt>
                  <dd className="text-sm font-semibold text-slate-800 break-words">{value}</dd>
                </div>
              </div>
            ))}
          </dl>

          {data.status === 'cancelled' && (
            <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-800">
              Anulado{data.cancelled_at ? ` el ${shortDate(data.cancelled_at)}` : ''}{data.cancel_reason ? `: ${data.cancel_reason}` : ''}.
              {data.refunded_amount > 0 ? ` Se devolvieron ${money(data.refunded_amount, data.currency)}.` : ''}
            </div>
          )}

          <div className="flex flex-col gap-2 pt-4 border-t border-slate-100" data-actions>
            {canSchedule && (
              <button type="button" data-action="schedule" onClick={() => { setNotice(''); setScheduling(true); }} className={`${actionButton} bg-[#022340] text-white hover:bg-[#03315a]`}>
                <CalendarPlus size={16} /> Agendar sesiones ({single!.available} sin fecha)
              </button>
            )}
            {data.can.use && data.status === 'active' && data.sessions_available > 0 && (
              <button type="button" data-action="use" onClick={() => open('use')} className={`${actionButton} border border-slate-200 text-slate-700 hover:bg-slate-50`}>
                <CheckCircle2 size={16} /> Registrar uso sin cita
              </button>
            )}
            {data.can.edit && isOpen && (
              <button type="button" data-action="extend" onClick={() => open('extend')} className={`${actionButton} border border-slate-200 text-slate-700 hover:bg-slate-50`}>
                <Hourglass size={16} /> Dar más días
              </button>
            )}
            {data.can.delete && isOpen && (
              <button type="button" data-action="cancel" onClick={() => open('cancel')} className={`${actionButton} text-rose-700 hover:bg-rose-50`}>
                <Ban size={16} /> Anular paquete
              </button>
            )}
          </div>
        </section>

        <div className="xl:col-span-2 space-y-5">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {stats.map((stat) => (
              <div key={stat.id} data-stat={stat.id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
                <span className="flex items-start justify-between gap-2">
                  <span className="block min-w-0">
                    <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{stat.title}</span>
                    <span className="block mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{stat.value}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{stat.note}</span>
                  </span>
                  <span className={`p-2 rounded-xl bg-slate-50 border border-slate-100 shrink-0 ${stat.color}`}>{stat.icon}</span>
                </span>
              </div>
            ))}
          </div>

          <section className={`${card} p-6 space-y-4`} data-card="items">
            <SectionTitle><Layers className="w-4 h-4 text-[#3F768C]" /> Sesiones</SectionTitle>
            {data.items.map((item) => (
              <div key={item.service_id} className="space-y-1.5">
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="font-semibold text-slate-800">{item.name}</span>
                  <span className="text-xs text-slate-500">{money(item.unit_price, data.currency)} c/u por separado</span>
                </div>
                <UsageBar pkg={{ sessions_total: item.total, sessions_used: item.used, sessions_scheduled: item.scheduled }} />
              </div>
            ))}
          </section>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            <section className={`${card} overflow-hidden flex flex-col`} data-card="appointments">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><CalendarClock className="w-4 h-4 text-[#3F768C]" /> Citas por atender</SectionTitle>
                <p className="text-xs text-slate-500 mt-1">Se descuentan solas al darlas por atendidas.</p>
              </div>
              <PagedList
                items={futureAppointments}
                empty={data.status === 'active' ? 'No tiene citas agendadas con este paquete.' : 'Sin citas pendientes.'}
                renderRow={(a) => (
                  <li key={a.id} className="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50 transition-colors" data-package-appointment={a.code}>
                    <button type="button" onClick={() => openDetail(a.id)} className="flex-1 min-w-0 text-left">
                      <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{dayLabel(a.starts_at)} · {hour12(a.starts_at.slice(11, 16))}</span>
                      <span className="block text-xs text-slate-500 truncate">{a.code}{a.professional ? ' · ' : ''}{a.professional && <ProfessionalLink id={a.professional.id}>{a.professional.name}</ProfessionalLink>}</span>
                    </button>
                    <Badge tone={statusStyle(a.status).badge} nowrap>{a.status_name}</Badge>
                  </li>
                )}
              />
            </section>

            <section className={`${card} overflow-hidden flex flex-col`} data-card="usages">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><CheckCircle2 className="w-4 h-4 text-[#3F768C]" /> Sesiones usadas</SectionTitle>
                <p className="text-xs text-slate-500 mt-1">Atendidas, perdidas por faltar y las registradas a mano.</p>
              </div>
              <PagedList
                items={data.usages}
                empty="Aún no usa ninguna sesión."
                renderRow={(u) => (
                  <li key={u.id} className="flex items-center gap-3 px-5 py-2.5" data-usage-row={u.kind}>
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{dayLabel(u.at)} · {u.service}</span>
                      <span className="block text-xs text-slate-500 truncate">
                        {u.appointment ? <button type="button" onClick={() => openDetail(u.appointment!.id)} className="hover:text-[#3F768C] hover:underline">{u.appointment.code}</button> : 'Sin cita'}
                        {u.professional ? <> · <ProfessionalLink id={u.professional.id}>{u.professional.name}</ProfessionalLink></> : ''}
                        {u.note ? ` · ${u.note}` : ''}{u.by && u.kind === 'manual' ? ` · ${u.by}` : ''}
                      </span>
                    </div>
                    <Badge tone={u.kind === 'attended' ? 'bg-emerald-50 text-emerald-700' : u.kind === 'manual' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700'} nowrap>{u.kind_label}</Badge>
                  </li>
                )}
              />
            </section>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            <section className={`${card} p-6 space-y-3`} data-card="conditions">
              <SectionTitle><FileText className="w-4 h-4 text-[#3F768C]" /> Condiciones</SectionTitle>
              <p className="text-sm text-slate-700 leading-relaxed" data-terms-text>{data.terms_text}</p>
              <dl className="grid grid-cols-1 gap-2 text-sm pt-2 border-t border-slate-100">
                <div><dt className={smallLabel}>Cómo se agenda</dt><dd className="font-semibold text-slate-800">{data.schedule_label}{c.booking_mode === 'scheduled' ? ` · ${c.professional_mode === 'chosen' ? 'con la misma persona' : 'con quien esté libre'}` : ''}</dd></div>
                <div><dt className={smallLabel}>Si cancela o no llega</dt><dd className="font-semibold text-slate-800">{LATE_TEXT[c.late_policy]}</dd></div>
                <div><dt className={smallLabel}>Devolución</dt><dd className="font-semibold text-slate-800">{refundInfo?.policy_label ?? '—'}</dd></div>
              </dl>
              <p className="text-xs text-slate-400">Son las condiciones con las que se vendió: cambiar el catálogo no las toca.</p>
            </section>

            <section className={`${card} overflow-hidden flex flex-col`} data-card="events">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><History className="w-4 h-4 text-[#3F768C]" /> Historial</SectionTitle>
              </div>
              <PagedList
                items={data.events}
                empty="Sin movimientos."
                renderRow={(e) => (
                  <li key={e.id} className="px-5 py-2.5">
                    <span className="flex items-center justify-between gap-2">
                      <span className="text-xs font-bold text-[#022340]">{EVENT_LABELS[e.kind] ?? e.kind}</span>
                      <span className="text-xs text-slate-400">{momentOf(e.at)}</span>
                    </span>
                    <span className="block text-sm text-slate-700">{e.detail}</span>
                    {e.by && <span className="block text-xs text-slate-400">{e.by}</span>}
                  </li>
                )}
              />
            </section>
          </div>
        </div>
      </div>

      <FormDrawer isOpen={drawer === 'use'} onClose={() => setDrawer(null)} title="Registrar uso sin cita" subtitle={`${data.code} · ${data.customer?.name ?? ''}`} icon={<CheckCircle2 size={20} />}>
        <form className="space-y-5" onSubmit={(e) => { e.preventDefault(); submit(`/packages/${id}/usages`, { service_id: serviceId, note }, (fresh) => `Se descontó una sesión. Quedan ${sessionsLabel(fresh.sessions_total - fresh.sessions_used)}.`); }} data-use-form>
          <p className="text-sm text-slate-600">Úsalo solo cuando la sesión no tuvo cita, por ejemplo en una campaña. Las sesiones con cita se descuentan solas al atenderlas.</p>
          {data.items.length > 1 && (
            <Select label="Servicio *" value={serviceId} clearable={false} onChange={(e) => setServiceId(String(e.target.value))}
              options={data.items.filter((i) => i.available > 0).map((i) => ({ value: i.service_id, label: `${i.name} (${i.available} sin fecha)` }))} />
          )}
          <div>
            <label className={labelClass} htmlFor="use-note">Por qué se registra a mano *</label>
            <input id="use-note" required minLength={3} maxLength={255} placeholder='Ej. "sesión atendida en una campaña, sin cita"' className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
          <p className="text-xs text-slate-500">Queda registrado quién lo hizo y cuándo.</p>
          <ErrorAlert message={formError} />
          <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={() => setDrawer(null)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Cancelar</button>
            <button type="submit" data-submit disabled={saving || !serviceId} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] disabled:opacity-50">{saving ? 'Guardando...' : 'Descontar 1 sesión'}</button>
          </div>
        </form>
      </FormDrawer>

      <FormDrawer isOpen={drawer === 'extend'} onClose={() => setDrawer(null)} title="Dar más días" subtitle={`${data.code} · ${daysLeftLabel(data)}`} icon={<Hourglass size={20} />}>
        <form className="space-y-5" onSubmit={(e) => { e.preventDefault(); submit(`/packages/${id}/extend`, { days: Number(days || 0), reason }, (fresh) => `Ahora vence el ${shortDate(fresh.expires_at)}.`); }} data-extend-form>
          <p className="text-sm text-slate-600">Para cuando el cliente no pudo usar sus sesiones por un motivo válido. Ej. &quot;estuvo con descanso médico, se le dan 30 días más&quot;.</p>
          <div className="w-40">
            <label className={labelClass} htmlFor="extend-days">Días más *</label>
            <input id="extend-days" required inputMode="numeric" className={inputClass} value={days} onChange={(e) => setDays(e.target.value.replace(/\D/g, '').slice(0, 3))} />
          </div>
          <p className="-mt-3 text-xs text-slate-500">{data.status === 'expired' ? 'Ya venció: los días se cuentan desde hoy.' : 'Se suman al vencimiento actual.'}</p>
          <div>
            <label className={labelClass} htmlFor="extend-reason">Motivo *</label>
            <input id="extend-reason" required minLength={3} maxLength={255} className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <ErrorAlert message={formError} />
          <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={() => setDrawer(null)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Cancelar</button>
            <button type="submit" data-submit disabled={saving || !Number(days)} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] disabled:opacity-50">{saving ? 'Guardando...' : `Dar ${days || 0} días más`}</button>
          </div>
        </form>
      </FormDrawer>

      <FormDrawer isOpen={drawer === 'cancel'} onClose={() => setDrawer(null)} title={`Anular ${data.code}`} subtitle={`${data.name} · ${data.customer?.name ?? ''}`} icon={<Ban size={20} />}>
        <form className="space-y-5" data-cancel-form onSubmit={(e) => {
          e.preventDefault();
          submit(`/packages/${id}/cancel`, { refund, amount: refund ? Number(amount || 0) : null, method: refund ? method : null, cash_session_id: refund && method === 'cash' ? sessionId || null : null, reason },
            (fresh) => `Paquete anulado.${fresh.result?.refunded ? ` Se devolvieron ${money(fresh.result.refunded, fresh.currency)}.` : ''}${fresh.result?.cancelled_appointments?.length ? ` Se cancelaron las citas ${fresh.result.cancelled_appointments.join(', ')}.` : ''}`);
        }}>
          <p className="text-sm text-slate-600">Las sesiones que no usó dejan de estar disponibles. ¿Qué pasó con el dinero?</p>

          {refundInfo && (
            <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Qué pasa con el dinero">
              <OptionCard type="button" role="radio" aria-checked={refund} data-refund-option="yes" disabled={Boolean(refundInfo.blocked) || refundInfo.policy === 'none'} onClick={() => setRefund(true)} selected={refund} className="text-left rounded-xl border px-4 py-3 transition-colors disabled:opacity-60">
                <span className="block text-sm font-bold text-[#022340]">Devolver lo que no usó{refundInfo.policy !== 'none' ? ' (recomendado)' : ''}</span>
                <span className="block text-xs text-slate-500 mt-0.5">{refundInfo.explain} {refundInfo.suggested > 0 ? `Corresponde ${money(refundInfo.suggested, refundInfo.currency)}.` : ''}</span>
              </OptionCard>
              <OptionCard type="button" role="radio" aria-checked={!refund} data-refund-option="no" onClick={() => setRefund(false)} selected={!refund} className="text-left rounded-xl border px-4 py-3 transition-colors">
                <span className="block text-sm font-bold text-[#022340]">Sin devolución{refundInfo.policy === 'none' ? ' (así se vendió)' : ''}</span>
                <span className="block text-xs text-slate-500 mt-0.5">Ej. el cliente ya no vendrá y el paquete no tenía devolución, o se le devolvió por fuera.</span>
              </OptionCard>
            </div>
          )}

          {refundInfo?.blocked && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{refundInfo.blocked}</p>}

          {refund && refundInfo && !refundInfo.blocked && (
            <div className="space-y-3 rounded-2xl border border-slate-200 p-4" data-refund-fields>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={labelClass} htmlFor="refund-amount">Monto a devolver *</label>
                  <input id="refund-amount" inputMode="decimal" className={inputClass} value={amount} onChange={(e) => setAmount(cleanAmount(e.target.value))} />
                </div>
                <Select label="Cómo se devuelve *" value={method} clearable={false} onChange={(e) => setMethod(String(e.target.value))} options={data.refund_methods} />
              </div>
              {Number(amount) !== refundInfo.amount && <p className="text-xs text-slate-500">Lo calculado es {money(refundInfo.amount, refundInfo.currency)}: puedes cambiarlo si tu empresa decide otra cosa.</p>}
              {method === 'cash' && data.cash_sessions.length > 1 && (
                <Select label="Caja" value={sessionId} clearable={false} onChange={(e) => setSessionId(String(e.target.value))} options={data.cash_sessions.map((s) => ({ value: s.id, label: s.register_name }))} />
              )}
              {method === 'cash' && data.cash_sessions.length === 0 && <p className="text-xs text-amber-800">La caja de la sede está cerrada: ábrela para devolver en efectivo, o devuelve por otro medio.</p>}
              {refundInfo.sale && <p className="text-xs text-slate-500">Se registra como devolución de la venta {refundInfo.sale.code}, con su nota de crédito.</p>}
            </div>
          )}

          {futureAppointments.length > 0 && (
            <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              También se cancelan sus citas pendientes: {futureAppointments.map((a) => `${a.code} (${dayLabel(a.starts_at)})`).join(', ')}.
            </p>
          )}

          <div>
            <label className={labelClass} htmlFor="cancel-reason">Por qué se anula *</label>
            <input id="cancel-reason" required minLength={3} maxLength={255} placeholder='Ej. "se mudó de ciudad"' className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>

          <ErrorAlert message={formError} />
          <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={() => setDrawer(null)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Cancelar</button>
            <button type="submit" data-submit disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">
              {saving ? 'Guardando...' : refund && Number(amount) > 0 ? `Anular y devolver ${money(Number(amount), data.currency)}` : 'Anular sin devolver'}
            </button>
          </div>
        </form>
      </FormDrawer>

      <SchedulePanel
        isOpen={scheduling}
        onClose={() => setScheduling(false)}
        source={scheduleSource}
        branchId={data.branch?.id ?? null}
        customerId={data.customer?.id ?? null}
        confirmLabel={(count) => `Agendar ${sessionsLabel(count)}`}
        onConfirm={schedule}
      />

      {panels}
    </div>
  );
}
