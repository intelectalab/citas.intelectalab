'use client';

import RichTextView from '@/app/components/common/RichTextView';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { PagedList } from '@/app/components/common/Pager';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { AlertTriangle, Ban, CalendarCheck, CalendarClock, Clock, Copy, Loader2, Mail, MailCheck, Megaphone, Pencil, Send, Ticket, User, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import CampaignForm from './CampaignForm';
import { CAMPAIGN_BADGES, EMAIL_NOTICE, RECIPIENT_BADGES, type CampaignDetail as Detail, type CampaignOptions, type Recipient } from '../campaigns';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const actionButton = 'flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold rounded-xl transition-colors disabled:opacity-50';

type Filter = 'all' | 'sent' | 'pending' | 'failed' | 'skipped';

const FILTERS: { id: Filter; label: string; match: (r: Recipient) => boolean }[] = [
  { id: 'all', label: 'Todos', match: () => true },
  { id: 'sent', label: 'Enviados', match: (r) => r.state === 'sent' || r.state === 'simulated' },
  { id: 'pending', label: 'Por enviar', match: (r) => r.state === 'pending' },
  { id: 'failed', label: 'Con error', match: (r) => r.state === 'failed' },
  { id: 'skipped', label: 'No se enviaron', match: (r) => r.state === 'skipped' },
];

export default function CampaignDetail() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAuth();
  const [data, setData] = useState<Detail | null>(null);
  const [options, setOptions] = useState<CampaignOptions | null>(null);
  const [filter, setFilter] = useState<Filter>('all');
  const router = useRouter();
  const [form, setForm] = useState<'edit' | 'duplicate' | null>(null);
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get(`/campaigns/${id}`)
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la campaña.')));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const cancel = async () => {
    setConfirmCancel(false);
    try {
      setBusy(true);
      await api.post(`/campaigns/${id}/cancel`);
      setNotice('Campaña cancelada: ya no saldrá.');
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo cancelar la campaña.'));
    } finally {
      setBusy(false);
    }
  };

  const openForm = (mode: 'edit' | 'duplicate') => {
    setNotice('');
    setOptions(null);
    api.get('/campaigns/options').then((res) => setOptions(res.data)).catch(() => setOptions(null));
    setForm(mode);
  };

  const backLink = <BackLink href="/campaigns">Campañas</BackLink>;

  if (!data) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        {error ? <ErrorAlert message={error} /> : <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={16} className="animate-spin" /> Cargando campaña...</p>}
      </div>
    );
  }

  const r = data.results;
  const shown = data.recipients.filter(FILTERS.find((f) => f.id === filter)!.match);
  const simulated = (r?.simulated ?? 0) > 0;

  const facts: [React.ReactNode, string, React.ReactNode][] = [
    [<Users key="a" size={15} />, 'A quiénes', data.audience],
    [<Clock key="w" size={15} />, data.status === 'scheduled' ? 'Sale' : data.sent_at ? 'Salió' : 'Creada', momentOf(data.sent_at ?? data.scheduled_at ?? data.created_at)],
    [<Ticket key="c" size={15} />, 'Cupón', data.coupon ? `${data.coupon.code}${data.coupon.description ? ` · ${data.coupon.description}` : ''}` : 'Sin cupón'],
    [<User key="b" size={15} />, 'La creó', data.by ?? '—'],
  ];

  const stats = [
    { id: 'sent', title: simulated ? 'Simulados' : 'Enviados', value: r?.sent ?? 0, note: simulated ? 'no salen: Probar sin enviar' : 'correos que salieron', icon: <MailCheck size={18} />, color: 'text-emerald-600' },
    { id: 'pending', title: 'Por enviar', value: r?.pending ?? 0, note: 'saliendo ahora', icon: <Send size={18} />, color: 'text-sky-600' },
    { id: 'failed', title: 'Con error', value: r?.failed ?? 0, note: 'reintentar en Mensajes', icon: <AlertTriangle size={18} />, color: 'text-rose-600' },
    { id: 'skipped', title: 'No se enviaron', value: r?.skipped ?? 0, note: 'sin permiso o sin correo', icon: <Ban size={18} />, color: 'text-slate-500' },
    { id: 'booked', title: 'Agendaron después', value: r?.booked.customers ?? 0, note: `${r?.booked.appointments ?? 0} citas en ${data.booked_days} días`, icon: <CalendarCheck size={18} />, color: 'text-[#3F768C]' },
  ];

  return (
    <div className="space-y-5 pb-8 font-sans" data-campaign-detail={data.id}>
      {backLink}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`} data-card="campaign">
          <div className="flex items-start gap-3">
            <span className="w-12 h-12 rounded-2xl bg-[#84D0D9]/20 text-[#022340] flex items-center justify-center shrink-0"><Megaphone size={22} /></span>
            <div className="min-w-0">
              <h1 className="text-xl font-black text-[#022340] tracking-tight break-words">{data.name}</h1>
              <Badge data-status={data.status} tone={CAMPAIGN_BADGES[data.status]} bold className="inline-block mt-2">{data.status_label}</Badge>
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

          {data.status === 'scheduled' && (
            <p className="text-xs text-slate-500">El grupo se calcula al salir: los números aparecen cuando se envíe.</p>
          )}

          {((data.editable && can('campaigns.edit')) || can('campaigns.create')) && (
            <div className="flex flex-col gap-2 pt-4 border-t border-slate-100" data-actions>
              {data.editable && can('campaigns.edit') && (
                <button type="button" data-action="edit" onClick={() => openForm('edit')} className={`${actionButton} bg-[#022340] text-white hover:bg-[#03315a]`}>
                  <Pencil size={16} /> Cambiar campaña
                </button>
              )}
              {can('campaigns.create') && (
                <button type="button" data-action="duplicate" onClick={() => openForm('duplicate')} className={`${actionButton} ${data.editable && can('campaigns.edit') ? 'text-[#022340] border border-slate-200 hover:bg-slate-50' : 'bg-[#022340] text-white hover:bg-[#03315a]'}`}>
                  <Copy size={16} /> Duplicar
                </button>
              )}
              {data.editable && can('campaigns.edit') && (
                <button type="button" data-action="cancel" disabled={busy} onClick={() => setConfirmCancel(true)} className={`${actionButton} text-rose-700 hover:bg-rose-50`}>
                  <Ban size={16} /> Cancelar envío
                </button>
              )}
              {can('campaigns.create') && <p className="text-xs text-slate-500">Duplicar crea una campaña nueva con el mismo grupo, mensaje y cupón, para enviarla otra vez.</p>}
            </div>
          )}
        </section>

        <div className="xl:col-span-2 space-y-5">
          {data.email.state !== 'ok' && (
            <p className={`text-sm border rounded-xl px-4 py-2.5 ${EMAIL_NOTICE[data.email.state]}`}>{data.email.label}</p>
          )}

          {r && (
            <div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-4">
              {stats.map((stat) => (
                <div key={stat.id} data-stat={stat.id} className={`${stat.id === 'booked' ? 'col-span-2 2xl:col-span-1 ' : ''}bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]`}>
                  <span className="flex items-start justify-between gap-2">
                    <span className="block min-w-0">
                      <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{stat.title}</span>
                      <span className="block mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{stat.value}</span>
                      <span className="block text-xs text-slate-500 mt-0.5 leading-snug">{stat.note}</span>
                    </span>
                    <span className={`p-2 rounded-xl bg-slate-50 border border-slate-100 shrink-0 ${stat.color}`}>{stat.icon}</span>
                  </span>
                </div>
              ))}
            </div>
          )}

          <section className={`${card} overflow-hidden`} data-card="message">
            <div className="px-5 pt-5 pb-3"><SectionTitle><Mail className="w-4 h-4 text-[#3F768C]" /> El correo</SectionTitle></div>
            <div className="mx-5 mb-5 rounded-2xl border border-slate-200 overflow-hidden">
              <div className="px-5 py-3 bg-slate-50/80 border-b border-slate-100 text-sm font-bold text-[#022340]">{data.subject}</div>
              <RichTextView body={data.body} className="px-5 py-4 text-sm text-slate-700" />
            </div>
          </section>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            <section className={`${card} overflow-hidden flex flex-col`} data-card="booked">
              <div className="px-5 pt-5 pb-3">
                <SectionTitle><CalendarCheck className="w-4 h-4 text-[#3F768C]" /> Agendaron después</SectionTitle>
                <p className="text-xs text-slate-500 mt-1">Citas que agendaron quienes recibieron el correo, en los {data.booked_days} días siguientes. Puede que algunas las hubieran agendado igual.</p>
              </div>
              <PagedList
                items={data.booked_list}
                empty={data.sent_at ? 'Aún nadie agendó después de esta campaña.' : 'Aparecen cuando la campaña salga.'}
                renderRow={(a) => (
                  <li key={a.id} className="px-5 py-2.5" data-booked={a.code}>
                    <span className="block text-sm font-semibold text-slate-800"><CustomerLink id={a.customer.id}>{a.customer.name}</CustomerLink></span>
                    <span className="block text-xs text-slate-500 truncate">{a.service ?? 'Cita'} · {momentOf(a.starts_at)} · agendada {momentOf(a.created_at)}</span>
                  </li>
                )}
              />
            </section>

            <section className={`${card} overflow-hidden flex flex-col`} data-card="recipients">
              <div className="px-5 pt-5 pb-3 space-y-2">
                <SectionTitle><Users className="w-4 h-4 text-[#3F768C]" /> Destinatarios</SectionTitle>
                <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Filtrar destinatarios">
                  {FILTERS.map((f) => {
                    const count = data.recipients.filter(f.match).length;
                    return (
                      <button key={f.id} type="button" role="radio" aria-checked={filter === f.id} data-filter={f.id} onClick={() => setFilter(f.id)}
                        className={`px-2.5 py-1 text-xs font-semibold rounded-lg border transition-colors ${filter === f.id ? 'bg-[#022340] text-white border-[#022340]' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                        {f.label} {count}
                      </button>
                    );
                  })}
                </div>
              </div>
              <PagedList
                items={shown}
                empty={data.sent_at ? 'Nadie en este grupo.' : 'Aparecen cuando la campaña salga.'}
                renderRow={(rec) => (
                  <li key={rec.id} className="flex items-center gap-3 px-5 py-2.5" data-recipient={rec.state}>
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate"><CustomerLink id={rec.customer.id}>{rec.customer.name}</CustomerLink></span>
                      <span className="block text-xs text-slate-500 truncate">{rec.reason ?? rec.email ?? 'Sin correo'}</span>
                    </div>
                    <Badge tone={RECIPIENT_BADGES[rec.state]} nowrap>{rec.label}</Badge>
                  </li>
                )}
              />
            </section>
          </div>

          {data.status === 'scheduled' && (
            <p className="flex items-center gap-2 text-xs text-slate-500"><CalendarClock size={14} /> Mientras el sistema esté en tu computadora, sale cuando alguien usa el panel después de la hora o con &quot;Revisar ahora&quot; en Mensajes. En el servidor sale a su hora.</p>
          )}
        </div>
      </div>

      <CampaignForm
        isOpen={form !== null}
        onClose={() => setForm(null)}
        options={options}
        editing={form === 'edit' ? data : null}
        duplicateFrom={form === 'duplicate' ? data : null}
        onSaved={(campaign, text) => {
          if (form === 'duplicate') {
            router.push(`/campaigns/${campaign.id}`);
            return;
          }
          setNotice(text);
          load();
        }}
      />

      <AppModal
        isOpen={confirmCancel}
        variant="danger"
        title="¿Cancelar el envío?"
        message="La campaña no saldrá. Si después quieres enviarla, usa Duplicar para crearla de nuevo con lo mismo."
        confirmText="Sí, cancelar"
        cancelText="No"
        onConfirm={cancel}
        onClose={() => setConfirmCancel(false)}
      />
    </div>
  );
}
