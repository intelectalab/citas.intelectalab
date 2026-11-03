'use client';

import { useEffect, useState } from 'react';
import { Workflow } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import TimeField from '@/app/components/common/TimeField';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import MessageFields from '@/features/messages/components/MessageFields';
import { REPEAT_OPTIONS, type Automation, type AutomationsPayload } from '../automations';

const COUPON_TOKEN = '[Cupón]';

interface AutomationFormProps {
  automation: Automation | null;
  options: AutomationsPayload | null;
  onClose: () => void;
  onSaved: (payload: AutomationsPayload) => void;
}

export default function AutomationForm({ automation, options, onClose, onSaved }: AutomationFormProps) {
  const [active, setActive] = useState(false);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [couponId, setCouponId] = useState('');
  const [sendTime, setSendTime] = useState('09:00');
  const [delayValue, setDelayValue] = useState('1');
  const [delayUnit, setDelayUnit] = useState<'hours' | 'days'>('days');
  const [repeatDays, setRepeatDays] = useState(0);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!automation) return;
    setError('');
    setActive(automation.active);
    setSubject(automation.subject);
    setBody(automation.body);
    setCouponId(automation.coupon_id ?? '');
    setSendTime(automation.send_time ?? '09:00');
    const inDays = automation.delay_minutes > 0 && automation.delay_minutes % 1440 === 0;
    setDelayUnit(inDays ? 'days' : 'hours');
    setDelayValue(String(inDays ? automation.delay_minutes / 1440 : Math.max(0, Math.round(automation.delay_minutes / 60))));
    setRepeatDays(automation.repeat_days);
  }, [automation]);

  if (!automation) return null;

  const coupon = options?.coupons.find((c) => c.id === couponId) ?? null;
  const orphanCoupon = !couponId && (body.includes(COUPON_TOKEN) || subject.includes(COUPON_TOKEN));
  const variables = (options?.variables ?? []).filter((v) => v.token !== COUPON_TOKEN || couponId);
  const repeatOptions = REPEAT_OPTIONS.some((o) => o.value === repeatDays) ? REPEAT_OPTIONS : [...REPEAT_OPTIONS, { value: repeatDays, label: `Como máximo una vez cada ${repeatDays} días` }];

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const delay = Math.max(0, Number(delayValue || 0)) * (delayUnit === 'days' ? 1440 : 60);
      const res = await api.put<AutomationsPayload>(`/automations/${automation.id}`, {
        active,
        subject,
        body,
        coupon_id: couponId || null,
        repeat_days: repeatDays,
        ...(automation.daily ? { send_time: sendTime } : { delay_minutes: delay }),
      });
      onSaved(res.data);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la automatización.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'automation-form';

  return (
    <FormDrawer isOpen={Boolean(automation)} onClose={onClose} title={automation.label} subtitle={automation.when} icon={<Workflow size={20} />}>
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={save} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <label className="flex items-center justify-between gap-3 px-4 py-3 border border-slate-200 rounded-xl cursor-pointer">
            <span>
              <span className="block text-sm font-semibold text-slate-800">Encendida</span>
              <span className="block text-xs text-slate-500">Solo les llega a quienes lleguen a esta situación desde que la enciendes.</span>
            </span>
            <Switch checked={active} onChange={setActive} label="Encendida" />
          </label>

          <div className="grid grid-cols-2 gap-3">
            {automation.daily ? (
              <div>
                <span className={labelClass}>Hora de envío</span>
                <TimeField ariaLabel="Hora de envío" value={sendTime} onChange={setSendTime} />
              </div>
            ) : (
              <div>
                <span className={labelClass}>Cuánto después sale</span>
                <div className="flex items-center gap-2">
                  <input aria-label="Cuánto después" inputMode="numeric" className={`${inputClass} w-20`} value={delayValue} onChange={(e) => setDelayValue(e.target.value.replace(/\D/g, '').slice(0, 3))} />
                  <div className="flex-1">
                    <Select ariaLabel="Unidad" clearable={false} value={delayUnit} onChange={(e) => setDelayUnit(e.target.value === 'days' ? 'days' : 'hours')} options={[{ value: 'hours', label: 'horas' }, { value: 'days', label: 'días' }]} />
                  </div>
                </div>
              </div>
            )}
            <div>
              <span className={labelClass}>Cuántas veces a la misma persona</span>
              <Select ariaLabel="Cuántas veces" clearable={false} value={repeatDays} onChange={(e) => setRepeatDays(Number(e.target.value))} options={repeatOptions} />
            </div>
          </div>
          {automation.key === 'inactive' && options && (
            <p className="text-xs text-slate-500 -mt-2">Sale cuando cumple {options.inactive_days} días sin venir. Ese número se cambia en Configuración → Grupos de clientes.</p>
          )}

          <div data-coupon-field>
            <span className={labelClass}>Cupón</span>
            <Select ariaLabel="Cupón" placeholder="Sin cupón" clearable options={(options?.coupons ?? []).map((c) => ({ value: c.id, label: `${c.code} · ${c.benefit}` }))} value={couponId} onChange={(e) => setCouponId(String(e.target.value))} />
            {coupon && !body.includes(COUPON_TOKEN) && (
              <p className="mt-1 text-xs text-slate-500">Puedes poner el dato {COUPON_TOKEN} en el mensaje. Si no lo pones, al final se agrega: &quot;Usa el cupón {coupon.code} en tu próxima visita&quot;.</p>
            )}
            {automation.coupon_id && !coupon && automation.coupon_id === couponId && (
              <p className="mt-1 text-xs text-amber-700">El cupón {automation.coupon_code} ya no está vigente: elige otro o déjalo sin cupón.</p>
            )}
            {(options?.coupons.length ?? 0) === 0 && <p className="mt-1 text-xs text-slate-500">No tienes cupones vigentes. Puedes crearlos en Marketing → Cupones.</p>}
          </div>

          <MessageFields idPrefix="automation" subject={subject} onSubject={setSubject} body={body} onBody={setBody} variables={variables} minHeight={160}
            hint="Cada dato se cambia por el de cada cliente. Al final de cada correo se agrega solo un enlace para dejar de recibir promociones." />

          {orphanCoupon && (
            <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              El mensaje usa el dato {COUPON_TOKEN}, pero no elegiste un cupón. Elige uno arriba o quita ese dato del mensaje.
            </p>
          )}

          <p className="text-xs text-slate-500">Solo les llega a quienes aceptaron recibir promociones por correo. Cada cliente recibe como máximo una automatización por día.</p>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">Cancelar</Button>
          <Button type="submit" form={formId} data-save-automation disabled={saving || orphanCoupon} variant="primary">
            {saving ? 'Guardando...' : 'Guardar'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
