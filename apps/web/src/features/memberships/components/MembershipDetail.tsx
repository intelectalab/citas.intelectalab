'use client';

import Link from 'next/link';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { Ban, BadgeCheck, CalendarCheck, CheckCircle2, Clock, Gift, History, Hourglass, Loader2, PauseCircle, PlayCircle, Receipt, RefreshCw, Store, User, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { PagedList } from '@/app/components/common/Pager';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';
import { STATUS_BADGES, daysLeftLabel, shortDate, type MembershipDetailData } from '../memberships';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const actionButton = 'flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold rounded-xl transition-colors disabled:opacity-50';

const EVENT_LABELS: Record<string, string> = {
  sold: 'Venta', renewed: 'Renovación', paused: 'Pausa', resumed: 'Reactivada', cancelled: 'Cancelada', replaced: 'Cambio de plan', refunded: 'Devolución', restored: 'Vuelve a valer',
};

type Drawer = 'pause' | 'cancel' | null;

export default function MembershipDetail() {
  const params = useParams();
  const id = String(params?.id ?? '');
  const [data, setData] = useState<MembershipDetailData | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [drawer, setDrawer] = useState<Drawer>(null);
  const [resuming, setResuming] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState('');
  const [reason, setReason] = useState('');

  const load = useCallback(() => {
    if (!id) return;
    api.get(`/memberships/${id}`)
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la membresía.')));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const { openDetail, panels } = useAppointmentPanels(load);

  const open = (which: Drawer) => {
    setFormError('');
    setNotice('');
    setReason('');
    setDrawer(which);
  };

  const submit = async (url: string, body: Record<string, unknown>, done: (fresh: MembershipDetailData) => string) => {
    try {
      setSaving(true);
      setFormError('');
      const res = await api.post(url, body);
      setData(res.data);
      setNotice(done(res.data));
      setDrawer(null);
      setResuming(false);
    } catch (err) {
      const message = apiError(err, 'No se pudo guardar.');
      if (resuming) setError(message);
      else setFormError(message);
      setResuming(false);
    } finally {
      setSaving(false);
    }
  };

  const backLink = <BackLink href="/memberships">Membresías</BackLink>;

  if (!data) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        {error ? <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p> : (
          <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={16} className="animate-spin" /> Cargando membresía...</p>
        )}
      </div>
    );
  }

  const alive = data.status === 'active' || data.status === 'grace';
  const open_ = alive || data.status === 'paused';
  const canRenew = data.can.sell && alive && data.plan_on_sale;

  const facts: [React.ReactNode, string, React.ReactNode][] = [
    [<User key="u" size={15} />, 'Socio', data.customer ? <CustomerLink id={data.customer.id}>{data.customer.name}</CustomerLink> : '—'],
    [<Wallet key="w" size={15} />, 'Precio', `${money(data.price, data.currency)} · ${data.period_label}`],
    [<Receipt key="r" size={15} />, 'Venta', data.sale ? `${data.sale.code} · ${data.sale.status_label}` : 'Sin venta registrada'],
    [<Clock key="c" size={15} />, 'Desde', shortDate(data.starts_at)],
    [<Hourglass key="h" size={15} />, 'Vigencia', `${daysLeftLabel(data)}${data.status === 'active' ? ` (${shortDate(data.ends_at)})` : ''}`],
    ...(data.paused_days > 0 ? [[<PauseCircle key="p" size={15} />, 'Días pausada', `${data.paused_days} en total, ya sumados al vencimiento`] as [React.ReactNode, string, React.ReactNode]] : []),
    [<Store key="s" size={15} />, 'Vendida en', data.branch?.name ?? '—'],
  ];

  return (
    <div className="space-y-5 pb-8 font-sans" data-membership-detail={data.code}>
      {backLink}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`} data-card="membership">
          <div className="flex items-start gap-3">
            <span className="w-12 h-12 rounded-2xl bg-brand-soft/20 text-brand-ink flex items-center justify-center shrink-0"><BadgeCheck size={22} /></span>
            <div className="min-w-0">
              <h1 className="text-xl font-black text-brand-ink tracking-tight">{data.name}</h1>
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

          {data.status === 'grace' && (
            <p className="p-3 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800" data-grace-note>
              Ya venció, pero sigue dando sus beneficios hasta el {shortDate(data.grace_until)} ({data.grace_days} días de gracia). Si la renueva ahora, el nuevo periodo sigue justo después de su vencimiento, no desde hoy.
            </p>
          )}
          {data.status === 'paused' && (
            <p className="p-3 bg-sky-50 border border-sky-200 rounded-xl text-sm text-sky-800" data-paused-note>
              Pausada desde el {shortDate(data.paused_at)}. Mientras está pausada no da beneficios. Al reactivarla, los días pausada se suman a su vencimiento.
            </p>
          )}
          {data.status === 'cancelled' && (
            <p className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-800">
              Cancelada{data.cancelled_at ? ` el ${shortDate(data.cancelled_at)}` : ''}{data.cancel_reason ? `: ${data.cancel_reason}` : '.'}
            </p>
          )}

          {(canRenew || (data.can.edit && open_) || (data.can.delete && open_)) && (
            <div className="flex flex-col gap-2 pt-4 border-t border-slate-100" data-actions>
              {canRenew && (
                <Link href={`/pos?tab=membresias&renovar=${data.id}`} data-action="renew" className={`${actionButton} bg-brand text-white hover:bg-brand-hover`}>
                  <RefreshCw size={16} /> Renovar en el punto de venta
                </Link>
              )}
              {data.can.edit && data.status === 'active' && (
                <button type="button" data-action="pause" onClick={() => open('pause')} className={`${actionButton} border border-slate-200 text-slate-700 hover:bg-slate-50`}>
                  <PauseCircle size={16} /> Pausar
                </button>
              )}
              {data.can.edit && data.status === 'paused' && (
                <button type="button" data-action="resume" onClick={() => { setError(''); setNotice(''); setResuming(true); }} className={`${actionButton} bg-brand text-white hover:bg-brand-hover`}>
                  <PlayCircle size={16} /> Reactivar
                </button>
              )}
              {data.can.delete && open_ && (
                <button type="button" data-action="cancel" onClick={() => open('cancel')} className={`${actionButton} text-rose-700 hover:bg-rose-50`}>
                  <Ban size={16} /> Cancelar membresía
                </button>
              )}
              {data.can.sell && alive && !data.plan_on_sale && (
                <p className="text-xs text-slate-500">Su plan ya no se vende, así que no se puede renovar. Puede cambiarse a otro plan en el punto de venta.</p>
              )}
            </div>
          )}
        </section>

        <div className="xl:col-span-2 space-y-5">
          <section className={`${card} p-6 space-y-4`} data-card="benefits">
            <SectionTitle><Gift className="w-4 h-4 text-brand-accent" /> Beneficios</SectionTitle>
            <p className="text-sm text-slate-700">{data.benefits_text}</p>
            {alive && data.included.length > 0 && (
              <div className="space-y-3 pt-3 border-t border-slate-100" data-period>
                <p className="text-xs text-slate-500">Este periodo: del {shortDate(data.period_start)} al {shortDate(data.period_end)}. Lo que no usa no pasa al siguiente.</p>
                {data.included.map((item) => (
                  <div key={item.service_id} className="space-y-1.5" data-included={item.name}>
                    <div className="flex items-center justify-between gap-3 text-sm">
                      <span className="font-semibold text-slate-800">{item.name}</span>
                      <span className="text-xs text-slate-500">{item.left === 0 ? 'Ya usó todas' : `Le ${item.left === 1 ? 'queda 1' : `quedan ${item.left}`}`} de {item.sessions}</span>
                    </div>
                    <div className="h-2 rounded-full bg-slate-100 overflow-hidden">
                      <div className="h-full bg-brand-accent rounded-full" style={{ width: `${Math.min(100, (item.used / Math.max(1, item.sessions)) * 100)}%` }} />
                    </div>
                  </div>
                ))}
              </div>
            )}
            {!alive && <p className="text-xs text-slate-400">{data.status === 'paused' ? 'Pausada: no da beneficios hasta que se reactive.' : 'Ya no da beneficios.'}</p>}
            <p className="text-xs text-slate-400">Son los beneficios con que se vendió: cambiar el plan no los toca.</p>
          </section>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            <section className={`${card} overflow-hidden flex flex-col`} data-card="usages">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><CheckCircle2 className="w-4 h-4 text-brand-accent" /> Usos</SectionTitle>
                <p className="text-xs text-slate-500 mt-1">Servicios incluidos que ya usó. Si la venta se anula o se devuelve, el uso se libera.</p>
              </div>
              <PagedList
                items={data.usages}
                empty="Aún no usa ningún servicio incluido."
                renderRow={(u) => (
                  <li key={u.id} className="flex items-center gap-3 px-5 py-2.5" data-usage-row={u.sale.code}>
                    <div className="flex-1 min-w-0">
                      <span className={`block text-sm font-semibold ${u.released ? 'text-slate-400 line-through' : 'text-slate-800'}`}>{u.quantity > 1 ? `${u.quantity} × ` : ''}{u.service}</span>
                      <span className="block text-xs text-slate-500 truncate">
                        {momentOf(u.at)} · {u.sale.code}
                        {u.appointment ? <> · <button type="button" onClick={() => openDetail(u.appointment!.id)} className="hover:text-brand-accent hover:underline">{u.appointment.code}</button></> : ''}
                      </span>
                    </div>
                    <Badge tone={u.released ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-700'} nowrap>{u.released ? 'Liberado' : 'Usado'}</Badge>
                  </li>
                )}
              />
            </section>

            <section className={`${card} overflow-hidden flex flex-col`} data-card="events">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><History className="w-4 h-4 text-brand-accent" /> Historial</SectionTitle>
              </div>
              {data.history_ready ? (
                <PagedList
                  items={data.events}
                  empty="Sin movimientos registrados."
                  renderRow={(e) => (
                    <li key={e.id} className="px-5 py-2.5" data-event={e.kind}>
                      <span className="flex items-center justify-between gap-2">
                        <span className="text-xs font-bold text-brand-ink">{EVENT_LABELS[e.kind] ?? e.kind}</span>
                        <span className="text-xs text-slate-400">{momentOf(e.at)}</span>
                      </span>
                      <span className="block text-sm text-slate-700">{e.detail}</span>
                      {e.by && <span className="block text-xs text-slate-400">{e.by}</span>}
                    </li>
                  )}
                />
              ) : (
                <p className="px-5 pb-5 text-xs text-amber-800">Falta actualizar la base de datos para ver el historial: ejecuta php artisan migrate.</p>
              )}
            </section>
          </div>
        </div>
      </div>

      <FormDrawer isOpen={drawer === 'pause'} onClose={() => setDrawer(null)} title={`Pausar ${data.code}`} subtitle={`${data.name} · ${data.customer?.name ?? ''}`} icon={<PauseCircle size={20} />}>
        <form className="space-y-5" data-pause-form onSubmit={(e) => { e.preventDefault(); submit(`/memberships/${id}/pause`, { reason }, () => 'Membresía pausada. Mientras esté pausada no da beneficios.'); }}>
          <p className="text-sm text-slate-600">Para cuando el socio no puede venir por un tiempo, por ejemplo por un viaje o por salud. Mientras está pausada no da beneficios. Cuando vuelva, pulsa &quot;Reactivar&quot;: los días pausada se suman a su vencimiento.</p>
          <div>
            <label className={labelClass} htmlFor="pause-reason">Motivo *</label>
            <input id="pause-reason" required minLength={3} maxLength={255} placeholder='Ej. "viaja por un mes"' className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <ErrorAlert message={formError} />
          <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={() => setDrawer(null)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Cancelar</button>
            <button type="submit" data-submit disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">{saving ? 'Guardando...' : 'Pausar'}</button>
          </div>
        </form>
      </FormDrawer>

      <FormDrawer isOpen={drawer === 'cancel'} onClose={() => setDrawer(null)} title={`Cancelar ${data.code}`} subtitle={`${data.name} · ${data.customer?.name ?? ''}`} icon={<Ban size={20} />}>
        <form className="space-y-5" data-cancel-form onSubmit={(e) => { e.preventDefault(); submit(`/memberships/${id}/cancel`, { reason }, () => 'Membresía cancelada: ya no da beneficios.'); }}>
          <p className="text-sm text-slate-600">Deja de dar beneficios desde hoy y ya no se puede renovar. Si más adelante vuelve, se le vende una membresía nueva.</p>
          {data.sale && <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">Cancelar no devuelve dinero. Si hay que devolverlo, devuelve la venta {data.sale.code} en Ventas: eso también cancela la membresía.</p>}
          <div>
            <label className={labelClass} htmlFor="cancel-reason">Por qué se cancela *</label>
            <input id="cancel-reason" required minLength={3} maxLength={255} placeholder='Ej. "se mudó de ciudad"' className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <ErrorAlert message={formError} />
          <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={() => setDrawer(null)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Volver</button>
            <button type="submit" data-submit disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{saving ? 'Guardando...' : 'Cancelar membresía'}</button>
          </div>
        </form>
      </FormDrawer>

      <AppModal
        isOpen={resuming}
        type="confirm"
        title={`¿Reactivar ${data.code}?`}
        message={<span className="flex items-start gap-2"><CalendarCheck size={18} className="text-brand-accent shrink-0 mt-0.5" /> Vuelve a dar beneficios desde hoy. Los días que estuvo pausada (desde el {shortDate(data.paused_at)}) se suman a su vencimiento.</span>}
        confirmText="Sí, reactivar"
        cancelText="Volver"
        isLoading={saving}
        onConfirm={() => submit(`/memberships/${id}/resume`, {}, (fresh) => `Membresía reactivada. ${fresh.result?.days ? `Estuvo pausada ${fresh.result.days === 1 ? '1 día' : `${fresh.result.days} días`}: ahora vence el ${shortDate(fresh.ends_at)}.` : `Sigue venciendo el ${shortDate(fresh.ends_at)}.`}`)}
        onClose={() => setResuming(false)}
      />

      {panels}
    </div>
  );
}
