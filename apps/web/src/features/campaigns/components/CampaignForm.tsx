'use client';

import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import TimeField from '@/app/components/common/TimeField';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { useEffect, useState } from 'react';
import { CalendarClock, Loader2, Megaphone, Send, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError, todayISO } from '@/features/appointments/appointments';
import MessageFields from '@/features/messages/components/MessageFields';
import { COUPON_TOKEN, EMAIL_NOTICE, copyName, type Audience, type Campaign, type CampaignOptions } from '../campaigns';

interface CampaignFormProps {
  isOpen: boolean;
  onClose: () => void;
  options: CampaignOptions | null;
  editing?: Campaign | null;
  duplicateFrom?: Campaign | null;
  onSaved: (campaign: Campaign, notice: string) => void;
}

const pad = (n: number) => String(n).padStart(2, '0');

const localParts = (iso: string) => {
  const date = new Date(iso);
  return { date: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`, time: `${pad(date.getHours())}:${pad(date.getMinutes())}` };
};

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

export default function CampaignForm({ isOpen, onClose, options, editing = null, duplicateFrom = null, onSaved }: CampaignFormProps) {
  const [name, setName] = useState('');
  const [group, setGroup] = useState('all');
  const [serviceId, setServiceId] = useState('');
  const [branchId, setBranchId] = useState('');
  const [couponNotice, setCouponNotice] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [couponId, setCouponId] = useState('');
  const [when, setWhen] = useState<'now' | 'later'>('now');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('09:00');
  const [audience, setAudience] = useState<Audience | null>(null);
  const [counting, setCounting] = useState(false);
  const [confirmBig, setConfirmBig] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setCouponNotice('');
    if (duplicateFrom) {
      setName(copyName(duplicateFrom.name));
      setGroup(duplicateFrom.segment.group);
      setServiceId(duplicateFrom.segment.service_id ?? '');
      setBranchId(duplicateFrom.segment.branch_id ?? '');
      setSubject(duplicateFrom.subject);
      setBody(duplicateFrom.body);
      setCouponId(duplicateFrom.coupon?.id ?? '');
      setWhen('now');
      setDate(todayISO());
      setTime('09:00');
    } else if (editing) {
      setName(editing.name);
      setGroup(editing.segment.group);
      setServiceId(editing.segment.service_id ?? '');
      setBranchId(editing.segment.branch_id ?? '');
      setSubject(editing.subject);
      setBody(editing.body);
      setCouponId(editing.coupon?.id ?? '');
      setWhen('later');
      const parts = localParts(editing.scheduled_at ?? new Date().toISOString());
      setDate(parts.date);
      setTime(parts.time);
    } else {
      setName('');
      setGroup('all');
      setServiceId('');
      setBranchId('');
      setSubject('');
      setBody('Hola [Nombre]:\n\n\n\n[Empresa]');
      setCouponId('');
      setWhen('now');
      setDate(todayISO());
      setTime('09:00');
    }
  }, [isOpen, editing, duplicateFrom]);

  const source = duplicateFrom ?? editing;

  useEffect(() => {
    if (!isOpen || !options || !source?.coupon) return;
    if (couponId === source.coupon.id && !options.coupons.some((c) => c.id === couponId)) {
      setCouponId('');
      setCouponNotice(`El cupón ${source.coupon.code} ya no está vigente: elige otro o déjalo sin cupón.`);
    }
  }, [isOpen, options, source, couponId]);

  const chosen = options?.groups.find((g) => g.key === group) ?? null;
  const segmentReady = chosen && (chosen.needs !== 'service' || serviceId) && (chosen.needs !== 'branch' || branchId);

  useEffect(() => {
    if (!isOpen || !segmentReady) {
      setAudience(null);
      return;
    }
    setCounting(true);
    const timer = window.setTimeout(() => {
      api.post('/campaigns/audience', { group, service_id: serviceId || null, branch_id: branchId || null })
        .then((res) => setAudience(res.data))
        .catch(() => setAudience(null))
        .finally(() => setCounting(false));
    }, 250);
    return () => window.clearTimeout(timer);
  }, [isOpen, group, serviceId, branchId, segmentReady]);

  const send = async () => {
    setConfirmBig(false);
    try {
      setSaving(true);
      setError('');
      const payload = {
        name: name.trim(),
        group,
        service_id: chosen?.needs === 'service' ? serviceId : null,
        branch_id: chosen?.needs === 'branch' ? branchId : null,
        subject: subject.trim(),
        body: body.trim(),
        coupon_id: couponId || null,
        send: when === 'now' ? 'now' : 'scheduled',
        scheduled_at: when === 'later' ? `${date}T${time}` : null,
      };
      const res = editing ? await api.put(`/campaigns/${editing.id}`, payload) : await api.post('/campaigns', payload);
      const campaign: Campaign = res.data;
      const simulated = options?.email.state === 'simulated';
      const notice = campaign.status === 'scheduled'
        ? `Campaña programada para el ${new Date(campaign.scheduled_at!).toLocaleString('es-PE', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', hour12: false })}. Puedes cambiarla o cancelarla mientras no salga.`
        : simulated
          ? `Campaña lista: se armaron ${plural(campaign.stats.eligible ?? 0, 'correo', 'correos')}, pero no salen porque tu empresa está en "Probar sin enviar".`
          : `Campaña enviada: ${plural(campaign.stats.eligible ?? 0, 'correo está', 'correos están')} saliendo. Los resultados se actualizan en su página.`;
      onSaved(campaign, notice);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la campaña.'));
    } finally {
      setSaving(false);
    }
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (audience && options && audience.eligible > options.volume_warning) {
      setConfirmBig(true);
      return;
    }
    send();
  };

  const formId = 'campaign-form';
  const coupon = options?.coupons.find((c) => c.id === couponId) ?? null;
  const orphanCoupon = !couponId && (body.includes(COUPON_TOKEN) || subject.includes(COUPON_TOKEN));
  const variables = (options?.variables ?? []).filter((v) => v.token !== COUPON_TOKEN || couponId);
  const ready = name.trim() && segmentReady && subject.trim() && body.trim() && !orphanCoupon && (when === 'now' || (date && time)) && options?.email.state !== 'none';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={editing ? `Cambiar campaña: ${editing.name}` : 'Nueva campaña'}
      subtitle={editing ? 'Programada: puedes cambiarla mientras no salga' : duplicateFrom ? `Copia de "${duplicateFrom.name}": revisa y cambia lo que quieras` : 'Un correo para un grupo de clientes, ahora o programado'}
      icon={<Megaphone size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={submit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-campaign-form>
          {options && options.email.state !== 'ok' && (
            <p className={`text-sm border rounded-lg px-3 py-2 ${EMAIL_NOTICE[options.email.state]}`} data-email-state={options.email.state}>{options.email.label}</p>
          )}

          <div>
            <label className={labelClass} htmlFor="campaign-name">Nombre de la campaña *</label>
            <input id="campaign-name" required maxLength={150} placeholder="Ej. Chequeo preventivo de octubre" className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
            <p className="mt-1 text-xs text-slate-500">Solo lo ve tu equipo; el cliente ve el asunto.</p>
          </div>

          <div className="space-y-3 rounded-2xl border border-slate-200 p-4" data-audience-box>
            <div>
              <span className={labelClass}>¿A quiénes? *</span>
              <Select ariaLabel="Grupo de clientes" options={(options?.groups ?? []).map((g) => ({ value: g.key, label: g.label }))} value={group} onChange={(e) => { if (e.target.value === '') return; setGroup(String(e.target.value)); }} />
              {chosen && <p className="mt-1 text-xs text-slate-500">{chosen.hint}</p>}
            </div>

            {chosen?.needs === 'service' && (
              <div>
                <span className={labelClass}>Servicio *</span>
                <Select ariaLabel="Servicio" placeholder="Elige el servicio" options={(options?.services ?? []).map((s) => ({ value: s.id, label: s.name }))} value={serviceId} onChange={(e) => setServiceId(String(e.target.value))} />
              </div>
            )}
            {chosen?.needs === 'branch' && (
              <div>
                <span className={labelClass}>Sede *</span>
                <Select ariaLabel="Sede" placeholder="Elige la sede" options={(options?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} value={branchId} onChange={(e) => setBranchId(String(e.target.value))} />
              </div>
            )}

            <div className="flex items-start gap-3 rounded-xl bg-[#84D0D9]/15 px-3 py-2.5" data-audience-count>
              <span className="p-1.5 rounded-lg bg-white text-[#3F768C] shrink-0">{counting ? <Loader2 size={16} className="animate-spin" /> : <Users size={16} />}</span>
              <span className="text-sm text-[#022340]">
                {!segmentReady && (chosen?.needs === 'service' ? 'Elige el servicio para ver a cuántos les llega.' : chosen?.needs === 'branch' ? 'Elige la sede para ver a cuántos les llega.' : 'Elige el grupo para ver a cuántos les llega.')}
                {segmentReady && audience && (
                  <>
                    <span className="block font-bold" data-eligible={audience.eligible}>Le llegará a {plural(audience.eligible, 'cliente', 'clientes')}</span>
                    <span className="block text-xs text-slate-600">
                      {audience.total === 0 ? 'Este grupo no tiene clientes por ahora.' : `De ${plural(audience.total, 'cliente', 'clientes')} del grupo${audience.no_consent ? `, ${audience.no_consent} no aceptaron recibir promociones` : ''}${audience.no_email ? `, ${audience.no_email} no tienen correo` : ''}. Solo se envía a quien aceptó promociones por correo.`}
                    </span>
                  </>
                )}
              </span>
            </div>
          </div>

          <div data-coupon-field>
            <span className={labelClass}>Cupón (opcional)</span>
            <Select ariaLabel="Cupón" placeholder="Sin cupón" clearable options={(options?.coupons ?? []).map((c) => ({ value: c.id, label: `${c.code} · ${c.benefit}` }))} value={couponId} onChange={(e) => { setCouponId(String(e.target.value)); setCouponNotice(''); }} />
            {couponNotice && <p className="mt-1 text-xs text-amber-700" data-coupon-notice>{couponNotice}</p>}
            {coupon && !body.includes(COUPON_TOKEN) && (
              <p className="mt-1 text-xs text-slate-500">Puedes poner el dato {COUPON_TOKEN} en el mensaje. Si no lo pones, al final se agrega: &quot;Usa el cupón {coupon.code} en tu próxima visita&quot;.</p>
            )}
            {(options?.coupons.length ?? 0) === 0 && <p className="mt-1 text-xs text-slate-500">No tienes cupones vigentes. Puedes crearlos en Ventas → Cupones.</p>}
          </div>

          <MessageFields idPrefix="campaign" subject={subject} onSubject={setSubject} body={body} onBody={setBody} variables={variables} subjectPlaceholder="Ej. [Nombre], es momento de tu chequeo" minHeight={180}
            hint="Cada dato se cambia por el de cada cliente. Al final de cada correo se agrega solo un enlace para dejar de recibir promociones." />

          {orphanCoupon && (
            <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-orphan-coupon>
              El mensaje usa el dato {COUPON_TOKEN}, pero no elegiste un cupón. Elige uno arriba o quita ese dato del mensaje.
            </p>
          )}

          <div>
            <span className={labelClass}>¿Cuándo sale?</span>
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Cuándo sale">
              <button type="button" role="radio" aria-checked={when === 'now'} data-when="now" onClick={() => setWhen('now')}
                className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-bold transition-colors ${when === 'now' ? 'border-[#022340] bg-[#84D0D9]/15 text-[#022340] ring-2 ring-[#022340]/10' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                <Send size={15} /> Ahora
              </button>
              <button type="button" role="radio" aria-checked={when === 'later'} data-when="later" onClick={() => setWhen('later')}
                className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-bold transition-colors ${when === 'later' ? 'border-[#022340] bg-[#84D0D9]/15 text-[#022340] ring-2 ring-[#022340]/10' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                <CalendarClock size={15} /> Programar
              </button>
            </div>
          </div>

          {when === 'later' && (
            <div className="grid grid-cols-2 gap-3" data-schedule-fields>
              <div>
                <span className={labelClass}>Día *</span>
                <DateField ariaLabel="Día del envío" value={date} min={todayISO()} onChange={setDate} />
              </div>
              <div>
                <span className={labelClass}>Hora *</span>
                <TimeField ariaLabel="Hora del envío" value={time} onChange={setTime} />
              </div>
              <p className="col-span-2 text-xs text-slate-500">El grupo se calcula al momento de enviarla: si alguien entra o sale del grupo antes, se toma en cuenta.</p>
            </div>
          )}

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">Cancelar</Button>
          <Button type="submit" form={formId} data-submit disabled={saving || !ready} variant="primary">
            {saving ? 'Guardando...' : editing ? (when === 'now' ? 'Enviar ahora' : 'Guardar cambios') : when === 'later' ? 'Programar campaña' : 'Enviar ahora'}
          </Button>
        </div>
      </div>

      <AppModal
        isOpen={confirmBig}
        title={`Vas a enviar ${audience?.eligible ?? 0} correos`}
        message={`Es un envío grande. Algunos servicios de correo limitan cuántos mensajes salen por día (Gmail, unos 500): si tu correo llega a ese límite, el resto quedará con error y podrás reintentarlo después. ¿Quieres continuar?`}
        confirmText="Sí, enviar"
        cancelText="Revisar"
        onConfirm={send}
        onClose={() => setConfirmBig(false)}
      />
    </FormDrawer>
  );
}
