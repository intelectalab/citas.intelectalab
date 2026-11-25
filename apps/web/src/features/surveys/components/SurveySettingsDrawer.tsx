'use client';

import { useEffect, useState } from 'react';
import { Settings2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Button from '@/app/components/common/Button';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import type { SurveyConfig } from '../surveys';

interface Props {
  isOpen: boolean;
  config: SurveyConfig;
  onClose: () => void;
  onSaved: (config: SurveyConfig) => void;
}

function Toggle({ checked, onChange, title, hint }: { checked: boolean; onChange: (v: boolean) => void; title: string; hint: string }) {
  return (
    <div className="flex items-start gap-3">
      <Switch checked={checked} onChange={onChange} label={title} />
      <span className="text-sm text-slate-700">
        {title}
        <span className="block text-xs text-slate-500">{hint}</span>
      </span>
    </div>
  );
}

export default function SurveySettingsDrawer({ isOpen, config, onClose, onSaved }: Props) {
  const [form, setForm] = useState({ ...config, google_url: config.google_url ?? '' });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (isOpen) {
      setForm({ ...config, google_url: config.google_url ?? '' });
      setError('');
    }
  }, [isOpen, config]);

  const save = async () => {
    try {
      setSaving(true);
      setError('');
      const res = await api.put<{ config: SurveyConfig }>('/surveys/settings', { ...form, google_url: form.google_url.trim() || null });
      onSaved(res.data.config);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setSaving(false);
    }
  };

  const number = (key: 'delay_hours' | 'google_min' | 'valid_days', min: number, max: number) => (
    <input type="number" min={min} max={max} className={inputClass} value={form[key]} onChange={(e) => setForm({ ...form, [key]: Number(e.target.value) })} />
  );

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title="Cómo se envían las encuestas" subtitle="Una sola pregunta del 0 al 10 después de cada atención" icon={<Settings2 size={18} />}>
      <div className="space-y-5" data-survey-settings>
        <Toggle checked={form.enabled} onChange={(v) => setForm({ ...form, enabled: v })} title="Enviar la encuesta después de cada cita atendida" hint="Le llega por correo (o por WhatsApp si lo tienes conectado) solo a quien aceptó recibir mensajes. Una sola encuesta por cita." />

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label className="block">
            <span className={labelClass}>Enviar pasadas (horas)</span>
            {number('delay_hours', 0, 48)}
            <span className="block text-xs text-slate-500 mt-1">Por ejemplo 2: si la atención terminó a las 10:00, la encuesta sale a las 12:00. Con 0 sale apenas termina.</span>
          </label>
          <label className="block">
            <span className={labelClass}>El enlace vale (días)</span>
            {number('valid_days', 1, 30)}
            <span className="block text-xs text-slate-500 mt-1">Después de esos días la encuesta ya no se puede responder.</span>
          </label>
        </div>

        <label className="block">
          <span className={labelClass}>Enlace para pedir reseñas en Google</span>
          <input className={inputClass} maxLength={500} placeholder="https://g.page/r/..." value={form.google_url} onChange={(e) => setForm({ ...form, google_url: e.target.value })} />
          <span className="block text-xs text-slate-500 mt-1">Lo copias desde tu Perfil de Empresa de Google, en «Pedir reseñas». Si lo dejas vacío no se invita a Google.</span>
        </label>

        <label className="block">
          <span className={labelClass}>Invitar a Google desde la nota</span>
          {number('google_min', 6, 10)}
          <span className="block text-xs text-slate-500 mt-1">Quien pone esta nota o más ve el botón para dejar su reseña en Google. A quien pone 6 o menos nunca se le ofrece: te llega un aviso para llamarlo.</span>
        </label>

        <Toggle checked={form.publish_reviews} onChange={(v) => setForm({ ...form, publish_reviews: v })} title="Mostrar reseñas aprobadas en tu página de reservas" hint="Solo las que tú apruebas y de clientes que aceptaron que se muestren. Nunca publiques comentarios con datos de salud." />

        <ErrorAlert message={error} />

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="secondary" type="button" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="button" disabled={saving} onClick={save} data-action="save-survey-settings">{saving ? 'Guardando...' : 'Guardar'}</Button>
        </div>
      </div>
    </FormDrawer>
  );
}
