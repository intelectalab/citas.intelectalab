'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowRightLeft, Loader2, MessagesSquare, MessageSquarePlus, StickyNote } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import Select from '@/app/components/common/Select';
import SectionTitle from '@/app/components/common/SectionTitle';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { STAGE_TONE, when, type Lead, type LeadEvent } from './leads';

const STAGES = [
  { value: 'new', label: 'Nuevo' },
  { value: 'contacted', label: 'Contactado' },
  { value: 'trial', label: 'En prueba' },
  { value: 'paid', label: 'Pagó' },
  { value: 'lost', label: 'Perdido' },
];

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

export default function LeadDetail({ id }: { id: string }) {
  const [lead, setLead] = useState<Lead | null>(null);
  const [events, setEvents] = useState<LeadEvent[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [panel, setPanel] = useState<'' | 'stage' | 'note'>('');
  const [stage, setStage] = useState('');
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');

  const apply = (data: { lead: Lead; events: LeadEvent[] }) => {
    setLead(data.lead);
    setEvents(data.events);
  };

  const load = useCallback(() => {
    api.get(`/admin/web/leads/${id}`).then((res) => apply(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar el interesado.')));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const send = async (kind: 'stage' | 'note') => {
    try {
      setBusy(true);
      setFormError('');
      const res = kind === 'stage'
        ? await api.put(`/admin/web/leads/${id}/stage`, { stage, reason: reason || null })
        : await api.post(`/admin/web/leads/${id}/notes`, { body: note });
      apply(res.data);
      setPanel('');
      setNote('');
      setReason('');
      setNotice(kind === 'stage' ? 'Etapa cambiada.' : 'Nota guardada.');
    } catch (err) {
      setFormError(apiError(err, kind === 'stage' ? 'No se pudo cambiar la etapa.' : 'No se pudo guardar la nota.'));
    } finally {
      setBusy(false);
    }
  };

  if (!lead) {
    return <div className="space-y-4"><BackLink href="/admin/funnel">Embudo e interesados</BackLink><ErrorAlert message={error} />{!error && <Loader2 className="animate-spin text-slate-400" />}</div>;
  }

  const locked = Boolean(lead.paid_at);
  const choices = STAGES.filter((s) => s.value !== lead.stage && (lead.tenant_id || !['trial', 'paid'].includes(s.value)));

  return (
    <div className="space-y-5 pb-10 font-sans" data-lead-detail>
      <BackLink href="/admin/funnel">Embudo e interesados</BackLink>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">{lead.name}</h1>
          <p className="text-sm text-slate-500">{lead.business ?? 'Sin negocio anotado'}{lead.business_type_name ? ` · ${lead.business_type_name}` : ''}</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={STAGE_TONE[lead.stage]} bold>{lead.stage_label}</Badge>
          {!locked && (
            <button type="button" aria-label="Cambiar etapa" title="Cambiar etapa" data-lead-stage onClick={() => { setStage(choices[0]?.value ?? ''); setFormError(''); setPanel('stage'); }} className="p-2 rounded-lg text-slate-500 hover:text-brand-ink hover:bg-slate-100 cursor-pointer"><ArrowRightLeft size={17} /></button>
          )}
          <button type="button" aria-label="Anotar" title="Anotar" data-lead-note onClick={() => { setFormError(''); setPanel('note'); }} className="p-2 rounded-lg text-slate-500 hover:text-brand-ink hover:bg-slate-100 cursor-pointer"><MessageSquarePlus size={17} /></button>
        </div>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
        <div className={`${card} p-5 space-y-3 text-sm`}>
          <SectionTitle>Datos</SectionTitle>
          <dl className="space-y-2">
            {[
              ['Celular', lead.phone],
              ['Correo', lead.email],
              ['Profesionales', lead.professionals ? String(lead.professionals) : null],
              ['Fuente', lead.source_label],
              ['Campaña', [lead.utm.source, lead.utm.medium, lead.utm.campaign].filter(Boolean).join(' · ') || null],
              ['Cupón', lead.coupon],
              ['Cómo nos conoció', lead.heard_from_label ?? null],
              ['Artículo del blog', lead.utm.post ?? null],
              ['Llegó', when(lead.created_at)],
              ['Empezó la prueba', when(lead.trial_at) || null],
              ['Pagó', when(lead.paid_at) || null],
              ['Motivo de pérdida', lead.lost_reason],
            ].filter(([, value]) => value).map(([label, value]) => (
              <div key={label} className="flex justify-between gap-3"><dt className="text-slate-500">{label}</dt><dd className="font-semibold text-slate-800 text-right break-all">{value}</dd></div>
            ))}
          </dl>
          {lead.tenant_id && <Link href={`/admin/tenants/${lead.tenant_id}`} className="inline-block font-bold text-brand-accent hover:text-brand-ink">Ver su empresa: {lead.tenant_name}</Link>}
          {locked && <p className="text-xs text-slate-500">Ya pagó su plan: la etapa queda fija para que el embudo cuadre.</p>}
        </div>

        <div className={`${card} p-5 space-y-3 lg:col-span-2`}>
          <SectionTitle>Historial</SectionTitle>
          <ol className="space-y-3" data-lead-events>
            {events.map((event) => (
              <li key={event.id} className="flex gap-3">
                <span className={`mt-0.5 w-7 h-7 rounded-full shrink-0 flex items-center justify-center ${event.kind === 'note' ? 'bg-amber-50 text-amber-700' : event.kind === 'chat' ? 'bg-sky-50 text-sky-700' : 'bg-brand-soft/20 text-brand-accent'}`}>
                  {event.kind === 'note' ? <StickyNote size={14} /> : event.kind === 'chat' ? <MessagesSquare size={14} /> : <ArrowRightLeft size={14} />}
                </span>
                <span className="min-w-0 text-sm">
                  <span className="block font-semibold text-slate-800">
                    {event.kind === 'note' ? 'Nota' : event.kind === 'chat' ? 'Conversación en el chat del sitio' : event.from_label ? `De ${event.from_label} a ${event.to_label}` : `Llegó como ${event.to_label}`}
                  </span>
                  {event.body && <span className="block text-slate-600 whitespace-pre-line break-words">{event.body}</span>}
                  <span className="block text-xs text-slate-400">{when(event.at)}{event.user_name ? ` · ${event.user_name}` : ' · automático'}</span>
                </span>
              </li>
            ))}
          </ol>
        </div>
      </div>

      <FormDrawer isOpen={panel === 'stage'} onClose={() => setPanel('')} title={`Cambiar etapa de ${lead.name}`} icon={<ArrowRightLeft size={18} />}>
        <div className="space-y-4" data-stage-form>
          <div><label className={labelClass}>Nueva etapa</label><Select ariaLabel="Nueva etapa" clearable={false} value={stage} onChange={(e) => setStage(String(e.target.value))} options={choices} /></div>
          {!lead.tenant_id && <p className="text-xs text-slate-500">«En prueba» y «Pagó» se ponen solos cuando se registra y paga, para que el embudo cuadre con las empresas reales.</p>}
          {stage === 'lost' && <div><label className={labelClass} htmlFor="lead-reason">¿Por qué se perdió?</label><input id="lead-reason" maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} placeholder="Ej. Le pareció caro" /></div>}
          <ErrorAlert message={formError} />
          <button type="button" data-stage-save disabled={busy || !stage || (stage === 'lost' && reason.trim().length < 2)} onClick={() => send('stage')} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={16} className="animate-spin" />} Cambiar etapa
          </button>
        </div>
      </FormDrawer>

      <FormDrawer isOpen={panel === 'note'} onClose={() => setPanel('')} title={`Anotar en ${lead.name}`} icon={<MessageSquarePlus size={18} />}>
        <div className="space-y-4" data-note-form>
          <div><label className={labelClass} htmlFor="lead-note-body">Nota</label><textarea id="lead-note-body" rows={4} maxLength={2000} value={note} onChange={(e) => setNote(e.target.value)} className={inputClass} placeholder="Ej. Pidió que lo llamemos el lunes por la tarde" /></div>
          <ErrorAlert message={formError} />
          <button type="button" data-note-save disabled={busy || note.trim().length < 2} onClick={() => send('note')} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={16} className="animate-spin" />} Guardar nota
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
