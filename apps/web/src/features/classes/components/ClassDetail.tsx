'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Ban, CalendarCheck, CalendarClock, CheckCheck, Clock, DoorOpen, Edit2, Hourglass, Loader2, MapPin, Receipt, Repeat, User, Users } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { PagedList } from '@/app/components/common/Pager';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import FormClass from './FormClass';
import { ENROLLMENT_BADGES, STATE_BADGES, classDate, shortClassDate, spotsLabel, type ClassDetailData, type ClassEnrollment } from '../classes';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const actionButton = 'flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold rounded-xl transition-colors disabled:opacity-50';
const rowButton = 'px-2.5 py-1 text-xs font-semibold rounded-lg transition-colors disabled:opacity-50';

export default function ClassDetail() {
  const params = useParams();
  const router = useRouter();
  const vocab = useVocabulary();
  const id = String(params?.id ?? '');
  const [data, setData] = useState<ClassDetailData | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState('');
  const [editing, setEditing] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');
  const [formError, setFormError] = useState('');
  const [picked, setPicked] = useState<PickedCustomer | null>(null);
  const [leaving, setLeaving] = useState<ClassEnrollment | null>(null);

  const load = useCallback(() => {
    if (!id) return;
    api.get(`/classes/${id}`)
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la clase.')));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const act = async (key: string, url: string, body: Record<string, unknown>, done: (fresh: ClassDetailData) => string) => {
    try {
      setBusy(key);
      setError('');
      setNotice('');
      const res = await api.post(url, body);
      setData(res.data);
      setNotice(done(res.data));
      return res.data as ClassDetailData;
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
      return null;
    } finally {
      setBusy('');
    }
  };

  const backLink = <BackLink href="/classes">Clases</BackLink>;

  if (!data) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        {error ? <ErrorAlert message={error} /> : <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={16} className="animate-spin" /> Cargando clase...</p>}
      </div>
    );
  }

  const live = data.status === 'scheduled' && data.state !== 'done';
  const started = data.state === 'running' || data.state === 'done';
  const enrolled = data.enrollments.filter((e) => e.status === 'enrolled' || e.status === 'attended' || e.status === 'no_show');
  const waiting = data.enrollments.filter((e) => e.status === 'waitlist');
  const gone = data.enrollments.filter((e) => e.status === 'cancelled');
  const pending = enrolled.filter((e) => e.status === 'enrolled').length;

  const enroll = async () => {
    if (!picked) return;
    const fresh = await act('enroll', `/classes/${id}/enrollments`, { customer_id: picked.id }, (f) => f.result?.status === 'waitlist'
      ? `${picked.first_name} ${picked.last_name} quedó en la lista de espera: la clase está completa.`
      : `${picked.first_name} ${picked.last_name} ya está en la clase.`);
    if (fresh) setPicked(null);
  };

  const charge = async (e: ClassEnrollment) => {
    const fresh = await act(`charge-${e.id}`, `/class-enrollments/${e.id}/charge`, {}, (f) => f.result?.sale?.status === 'paid'
      ? `Lo cubre su membresía: se registró la venta ${f.result.sale.code} sin cobro.`
      : `Se creó la venta ${f.result?.sale?.code ?? ''} por cobrar.`);
    if (fresh?.result?.sale && fresh.result.sale.status !== 'paid') router.push(`/pos?venta=${fresh.result.sale.id}`);
  };

  const facts: [React.ReactNode, string, React.ReactNode][] = [
    [<Clock key="c" size={15} />, 'Cuándo', <span key="w" className="inline-block first-letter:uppercase">{classDate(data.date)} · {data.time}–{data.end_time}</span>],
    [<User key="u" size={15} />, vocab.pro, data.professional ? <ProfessionalLink id={data.professional.id}>{data.professional.display_name}</ProfessionalLink> : '—'],
    [<DoorOpen key="d" size={15} />, 'Sala', data.room?.name ?? 'Sin sala'],
    [<MapPin key="m" size={15} />, 'Sede', data.branch?.name ?? '—'],
    [<Receipt key="r" size={15} />, 'Precio por persona', data.service.price !== undefined ? formatMoney(data.service.price) : '—'],
    [<CalendarClock key="o" size={15} />, 'Inscripción en línea', data.online_booking ? 'Sí, desde el portal y la página de reservas' : 'No, solo desde el panel'],
  ];

  const enrollmentRow = (e: ClassEnrollment) => (
    <li key={e.id} className="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 px-5 py-3" data-enrollment={e.customer?.name} data-status={e.status}>
      <div className="flex-1 min-w-0">
        <span className="block text-sm font-semibold text-slate-800 truncate">{e.customer ? <CustomerLink id={e.customer.id}>{e.customer.name}</CustomerLink> : '—'}</span>
        <span className="block text-xs text-slate-500">
          {e.source_label}{e.promoted ? ' · entró desde la espera' : ''}
          {e.paid && e.paid_label ? ` · Pagado con ${e.paid_label.toLowerCase()}${e.sale ? ` (${e.sale.code})` : e.package?.name ? ` (${e.package.name})` : ''}` : ''}
          {!e.paid && e.sale ? <> · <Link href={`/pos?venta=${e.sale.id}`} className="text-brand-accent hover:underline">{e.sale.code} por cobrar</Link></> : ''}
          {!e.paid && !e.sale && e.membership ? ` · Tiene la membresía ${e.membership.name}` : ''}
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        <Badge tone={ENROLLMENT_BADGES[e.status]} nowrap>{e.status_label}</Badge>
        {data.can.edit && data.status === 'scheduled' && started && (
          <>
            {e.status !== 'attended' && <button type="button" data-attend disabled={busy !== ''} onClick={() => act(`att-${e.id}`, `/class-enrollments/${e.id}/attendance`, { status: 'attended' }, () => `${e.customer?.name ?? ''}: asistió.`)} className={`${rowButton} bg-emerald-50 text-emerald-700 hover:bg-emerald-100`}>Asistió</button>}
            {e.status !== 'no_show' && <button type="button" data-noshow disabled={busy !== ''} onClick={() => act(`ns-${e.id}`, `/class-enrollments/${e.id}/attendance`, { status: 'no_show' }, () => `${e.customer?.name ?? ''}: no asistió.`)} className={`${rowButton} text-rose-700 hover:bg-rose-50`}>No asistió</button>}
          </>
        )}
        {data.can.edit && !e.paid && !e.sale && data.status === 'scheduled' && (
          <>
            {data.can.packages && e.has_package && <button type="button" data-use-package disabled={busy !== ''} onClick={() => act(`pkg-${e.id}`, `/class-enrollments/${e.id}/package`, {}, () => 'Se descontó una clase de su paquete.')} className={`${rowButton} bg-sky-50 text-sky-700 hover:bg-sky-100`}>Usar su paquete</button>}
            {data.can.charge && <button type="button" data-charge disabled={busy !== ''} onClick={() => charge(e)} className={`${rowButton} bg-brand text-white hover:bg-brand-hover`}>{e.membership ? 'Cobrar con su membresía' : 'Cobrar'}</button>}
          </>
        )}
        {data.can.edit && live && e.status === 'enrolled' && !started && (
          <button type="button" data-leave disabled={busy !== ''} onClick={() => setLeaving(e)} className={`${rowButton} text-slate-500 hover:bg-slate-100`}>Sacar</button>
        )}
      </div>
    </li>
  );

  return (
    <div className="space-y-5 pb-8 font-sans" data-class-detail={data.id}>
      {backLink}
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`} data-card="class">
          <div className="flex items-start gap-3">
            <span className="w-12 h-12 rounded-2xl bg-brand-soft/20 text-brand-ink flex items-center justify-center shrink-0"><CalendarCheck size={22} /></span>
            <div className="min-w-0">
              <h1 className="text-xl font-black text-brand-ink tracking-tight">{data.title}</h1>
              <p className="text-xs text-slate-500 first-letter:uppercase">{shortClassDate(data.date)} · {data.time}</p>
              <Badge data-state={data.state} tone={STATE_BADGES[data.state]} bold className="inline-block mt-2">{data.state_label}</Badge>
            </div>
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center justify-between text-sm"><span className="font-semibold text-slate-800">{spotsLabel(data)}</span><span className="text-xs text-slate-500">{data.free} {data.free === 1 ? 'cupo libre' : 'cupos libres'}</span></div>
            <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden"><div className={`h-full rounded-full ${data.taken >= data.capacity ? 'bg-amber-500' : 'bg-brand-accent'}`} style={{ width: `${Math.min(100, (data.taken / Math.max(1, data.capacity)) * 100)}%` }} /></div>
            {data.waitlist > 0 && <p className="text-xs text-amber-700">{data.waitlist} en lista de espera</p>}
          </div>

          <dl className="space-y-3">
            {facts.map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="mt-0.5 text-slate-400 shrink-0">{icon}</span>
                <div className="min-w-0"><dt className={smallLabel}>{label}</dt><dd className="text-sm font-semibold text-slate-800 break-words">{value}</dd></div>
              </div>
            ))}
          </dl>

          {data.notes && <p className="p-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-700">{data.notes}</p>}
          {data.status === 'cancelled' && (
            <p className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-800">Cancelada{data.cancel_reason ? `: ${data.cancel_reason}` : '.'} Se avisó a los inscritos.</p>
          )}

          {live && (data.can.create || data.can.cancel) && (
            <div className="flex flex-col gap-2 pt-4 border-t border-slate-100" data-actions>
              {data.can.create && <button type="button" data-action="edit" onClick={() => { setNotice(''); setEditing(true); }} className={`${actionButton} border border-slate-200 text-slate-700 hover:bg-slate-50`}><Edit2 size={16} /> Cambiar clase</button>}
              {data.can.cancel && <button type="button" data-action="cancel" onClick={() => { setFormError(''); setReason(''); setCancelling(true); }} className={`${actionButton} text-rose-700 hover:bg-rose-50`}><Ban size={16} /> Cancelar clase</button>}
            </div>
          )}
        </section>

        <div className="xl:col-span-2 space-y-5">
          <section className={`${card} overflow-hidden flex flex-col`} data-card="enrolled">
            <div className="px-5 pt-5 pb-3 space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <SectionTitle><Users className="w-4 h-4 text-brand-accent" /> Inscritos</SectionTitle>
                {data.can.edit && data.status === 'scheduled' && started && pending > 0 && (
                  <button type="button" data-attend-all disabled={busy !== ''} onClick={() => act('all', `/classes/${id}/attend-all`, {}, (f) => `Se marcó la asistencia de ${f.result?.marked ?? pending}.`)} className={`${rowButton} flex items-center gap-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100`}><CheckCheck size={14} /> Todos asistieron</button>
                )}
              </div>
              {data.can.edit && live && (
                <div className="flex flex-col sm:flex-row sm:items-end gap-2" data-enroll>
                  <div className="flex-1 min-w-0"><CustomerPicker value={picked} onChange={setPicked} required={false} /></div>
                  <button type="button" data-enroll-submit disabled={!picked || busy !== ''} onClick={enroll} className={`${actionButton} bg-brand text-white hover:bg-brand-hover justify-center`}>
                    {data.free > 0 ? 'Inscribir' : data.rules.waitlist ? 'Anotar en espera' : 'Clase completa'}
                  </button>
                </div>
              )}
              {!started && live && <p className="text-xs text-slate-500">La asistencia se marca cuando empieza la clase. El cobro puede hacerse antes o después.</p>}
            </div>
            <PagedList items={enrolled} empty="Aún no hay inscritos." renderRow={enrollmentRow} />
          </section>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            <section className={`${card} overflow-hidden flex flex-col`} data-card="waitlist">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><Hourglass className="w-4 h-4 text-brand-accent" /> Lista de espera</SectionTitle>
                <p className="text-xs text-slate-500 mt-1">En orden de llegada. {data.rules.waitlist ? 'Si alguien sale, el primero entra solo si así lo eligió tu empresa en Configuración.' : 'Tu empresa no usa lista de espera en las clases.'}</p>
              </div>
              <PagedList
                items={waiting}
                empty="Nadie espera cupo."
                renderRow={(e) => (
                  <li key={e.id} className="flex items-center gap-3 px-5 py-2.5" data-waiting={e.customer?.name}>
                    <span className="w-6 h-6 rounded-full bg-amber-50 text-amber-700 text-xs font-bold flex items-center justify-center shrink-0">{e.position}</span>
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{e.customer ? <CustomerLink id={e.customer.id}>{e.customer.name}</CustomerLink> : '—'}</span>
                      <span className="block text-xs text-slate-500">{e.source_label}</span>
                    </div>
                    {data.can.edit && live && (
                      <div className="flex items-center gap-1">
                        <button type="button" data-promote disabled={busy !== '' || data.free === 0} title={data.free === 0 ? 'No hay cupos libres' : undefined} onClick={() => act(`pr-${e.id}`, `/class-enrollments/${e.id}/promote`, {}, () => `${e.customer?.name ?? ''} pasó a la clase.`)} className={`${rowButton} bg-sky-50 text-sky-700 hover:bg-sky-100`}>Pasar a la clase</button>
                        <button type="button" disabled={busy !== ''} onClick={() => setLeaving(e)} className={`${rowButton} text-slate-500 hover:bg-slate-100`}>Quitar</button>
                      </div>
                    )}
                  </li>
                )}
              />
            </section>

            <section className={`${card} overflow-hidden flex flex-col`} data-card="series">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><Repeat className="w-4 h-4 text-brand-accent" /> {data.series_id ? 'Siguientes de la serie' : 'Salieron de la clase'}</SectionTitle>
              </div>
              {data.series_id ? (
                <PagedList
                  items={data.series}
                  empty="No quedan más clases de esta serie."
                  renderRow={(s) => (
                    <li key={s.id} className="flex items-center gap-3 px-5 py-2.5">
                      <Link href={`/classes/${s.id}`} className="flex-1 min-w-0 text-sm font-semibold text-slate-800 hover:text-brand-accent first-letter:uppercase">{shortClassDate(s.date)} · {s.time}</Link>
                      <span className="text-xs text-slate-500">{spotsLabel(s)}</span>
                    </li>
                  )}
                />
              ) : (
                <PagedList
                  items={gone}
                  empty="Nadie salió de esta clase."
                  renderRow={(e) => (
                    <li key={e.id} className="px-5 py-2.5 text-sm text-slate-600">{e.customer ? <CustomerLink id={e.customer.id}>{e.customer.name}</CustomerLink> : '—'}</li>
                  )}
                />
              )}
            </section>
          </div>
        </div>
      </div>

      <FormClass
        isOpen={editing}
        onClose={() => setEditing(false)}
        initialData={data}
        options={data.options}
        onSaved={(message) => { setNotice(message); load(); }}
      />

      <FormDrawer isOpen={cancelling} onClose={() => setCancelling(false)} title={`Cancelar ${data.title}`} subtitle={`${shortClassDate(data.date)} · ${data.time}`} icon={<Ban size={20} />}>
        <form className="space-y-5" data-cancel-form onSubmit={async (e) => {
          e.preventDefault();
          setFormError('');
          try {
            setBusy('cancel');
            const res = await api.post(`/classes/${id}/cancel`, { reason });
            setData(res.data);
            const paid: string[] = res.data?.result?.paid_sales ?? [];
            setNotice(`Clase cancelada. Se avisó a ${res.data?.result?.notified ?? 0} ${res.data?.result?.notified === 1 ? 'persona' : 'personas'}.${paid.length ? ` Si hay que devolver dinero, devuelve en Ventas: ${paid.join(', ')}.` : ''}`);
            setCancelling(false);
          } catch (err) {
            setFormError(apiError(err, 'No se pudo cancelar la clase.'));
          } finally {
            setBusy('');
          }
        }}>
          <p className="text-sm text-slate-600">Se les avisa a los {data.taken} inscritos y a los {data.waitlist} en espera. Las clases descontadas de un paquete se devuelven solas. Lo cobrado en una venta no se devuelve solo: se devuelve desde Ventas.</p>
          <div>
            <label className={labelClass} htmlFor="class-cancel-reason">Por qué se cancela *</label>
            <input id="class-cancel-reason" required minLength={3} maxLength={255} placeholder='Ej. "la instructora está enferma"' className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
            <p className="text-xs text-slate-500 mt-1.5">Va en el mensaje que reciben.</p>
          </div>
          <ErrorAlert message={formError} />
          <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={() => setCancelling(false)} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Volver</button>
            <button type="submit" data-submit disabled={busy !== ''} className="px-5 py-2.5 text-sm font-bold bg-rose-600 text-white rounded-xl hover:bg-rose-700 disabled:opacity-50">{busy === 'cancel' ? 'Cancelando...' : 'Cancelar clase'}</button>
          </div>
        </form>
      </FormDrawer>

      <AppModal
        isOpen={leaving !== null}
        type="confirm"
        variant="danger"
        title={leaving?.status === 'waitlist' ? `¿Quitar a ${leaving?.customer?.name ?? ''} de la espera?` : `¿Sacar a ${leaving?.customer?.name ?? ''} de la clase?`}
        message={leaving?.status === 'waitlist' ? 'Deja de esperar cupo en esta clase.' : `Su cupo queda libre${waiting.length ? ' y puede pasar el primero de la lista de espera' : ''}. Si pagó con un paquete, la clase se le devuelve.`}
        confirmText="Sí, sacar"
        cancelText="Volver"
        isLoading={busy.startsWith('leave')}
        onConfirm={async () => {
          if (!leaving) return;
          const who = leaving.customer?.name ?? '';
          await act(`leave-${leaving.id}`, `/class-enrollments/${leaving.id}/leave`, {}, (f) => `${who} salió de la clase.${f.result?.promoted ? ` Entró ${f.result.promoted} desde la lista de espera.` : ''}`);
          setLeaving(null);
        }}
        onClose={() => setLeaving(null)}
      />
    </div>
  );
}
