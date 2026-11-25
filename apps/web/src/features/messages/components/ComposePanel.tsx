'use client';

import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { CalendarClock, Mail, MessageCircle, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import DateField from '@/app/components/common/DateField';
import TimeField from '@/app/components/common/TimeField';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import { apiError, todayISO } from '@/features/appointments/appointments';
import { useAuth } from '@/hooks/useAuth';
import MessageFields from './MessageFields';
import { STARTERS, outcomeOf, type EmailState, type Message } from '../messages';

interface ComposePanelProps {
  isOpen: boolean;
  onClose: () => void;
  customer?: PickedCustomer | null;
  editing?: Message | null;
  emailState?: EmailState;
  onSaved: (message: Message, notice: string) => void;
}


const pad = (n: number) => String(n).padStart(2, '0');

const localParts = (iso: string) => {
  const date = new Date(iso);
  return { date: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`, time: `${pad(date.getHours())}:${pad(date.getMinutes())}` };
};

export default function ComposePanel({ isOpen, onClose, customer = null, editing = null, emailState, onSaved }: ComposePanelProps) {
  const { user } = useAuth();
  const [picked, setPicked] = useState<PickedCustomer | null>(null);
  const [email, setEmail] = useState<string | null | undefined>(undefined);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [starter, setStarter] = useState('blank');
  const [when, setWhen] = useState<'now' | 'later'>('now');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('09:00');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [channel, setChannel] = useState<'email' | 'whatsapp'>('email');
  const [channels, setChannels] = useState<{ email: { available: boolean; reason: string | null }; whatsapp: { available: boolean; reason: string | null }; variables: { token: string; label: string }[] } | null>(null);

  const company = user?.tenant?.name ?? '';

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    if (editing) {
      setPicked(null);
      setSubject(editing.subject ?? '');
      setBody(editing.body);
      setWhen('later');
      const parts = localParts(editing.scheduled_at ?? new Date().toISOString());
      setDate(parts.date);
      setTime(parts.time);
      setEmail(editing.to);
    } else {
      setPicked(customer);
      setStarter('blank');
      setSubject('');
      setBody(STARTERS[0].body(customer?.first_name ?? '', company));
      setWhen('now');
      setDate(todayISO());
      setTime('09:00');
    }
  }, [isOpen, editing, customer, company]);

  useEffect(() => {
    if (!isOpen || editing) return;
    setEmail(undefined);
    setChannel('email');
    setChannels(null);
    if (!picked) return;
    api.get(`/customers/${picked.id}/channels`)
      .then((res) => setChannels(res.data))
      .catch(() => setChannels(null));
    api.get(`/customers/${picked.id}`)
      .then((res) => setEmail(res.data.customer?.email ?? null))
      .catch(() => setEmail(null));
  }, [picked, isOpen, editing]);

  const applyStarter = (id: string) => {
    const chosen = STARTERS.find((s) => s.id === id) ?? STARTERS[0];
    setStarter(id);
    setSubject(chosen.subject);
    setBody(chosen.body(picked?.first_name ?? '', company));
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      subject: subject.trim(),
      body: body.trim(),
      scheduled_at: when === 'later' ? `${date}T${time}` : null,
    };

    try {
      setSaving(true);
      setError('');
      const res = editing
        ? await api.put(`/messages/${editing.id}`, payload)
        : await api.post('/messages', { ...payload, customer_id: picked?.id, channel });
      const message: Message = res.data;
      const notice = message.status === 'scheduled'
        ? `Mensaje programado para el ${new Date(message.scheduled_at!).toLocaleString('es-PE', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', hour12: false })}. Puedes cambiarlo o cancelarlo mientras no salga.`
        : outcomeOf(message, 'el mensaje').text;
      onSaved(message, notice);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el mensaje.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'compose-form';
  const ready = Boolean(editing || picked) && subject.trim() && body.trim() && (when === 'now' || (date && time));

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={editing ? 'Cambiar mensaje programado' : 'Nuevo mensaje'}
      subtitle={editing ? `Para ${editing.to_name ?? editing.to}` : 'Un correo para un cliente, ahora o programado'}
      icon={<Mail size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={submit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-compose>
          {!editing && (
            <CustomerPicker value={picked} onChange={(c) => { setPicked(c); if (c && starter) setBody((current) => current.replace(/Hola [^:<]*:/, `Hola ${c.first_name}:`)); }} locked={Boolean(customer)} />
          )}

          {!editing && channels && (
            <div>
              <span className={labelClass}>¿Por dónde le escribes?</span>
              <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Canal del mensaje">
                {([['email', 'Correo', <Mail key="i" size={15} />], ['whatsapp', 'WhatsApp', <MessageCircle key="i" size={15} />]] as ['email' | 'whatsapp', string, React.ReactNode][]).map(([key, label, icon]) => (
                  <button key={key} type="button" role="radio" aria-checked={channel === key} data-channel={key} disabled={!channels[key].available} title={channels[key].reason ?? undefined} onClick={() => setChannel(key)}
                    className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-bold transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${channel === key ? 'border-brand bg-brand-soft/15 text-brand-ink ring-2 ring-brand/10' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                    {icon} {label}
                  </button>
                ))}
              </div>
              {!channels.whatsapp.available && channels.whatsapp.reason && <p className="mt-1.5 text-xs text-slate-500" data-whatsapp-reason>WhatsApp no está disponible: {channels.whatsapp.reason}</p>}
              {channel === 'whatsapp' && <p className="mt-1.5 text-xs text-slate-500">Sale como texto simple: el formato y el asunto no se ven en WhatsApp.</p>}
            </div>
          )}

          {email === null && channel === 'email' && (editing || picked) && (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-no-email>
              Este cliente no tiene un correo registrado: agrégalo en su ficha para poder escribirle.
            </p>
          )}
          {email && channel === 'email' && <p className="-mt-2 text-xs text-slate-500">Se envía a <span className="font-semibold text-slate-700">{email}</span>.</p>}

          {emailState === 'none' && (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              Tu empresa aún no configura su correo: el mensaje quedará como &quot;No enviado&quot;. Configúralo en Configuración → Correo.
            </p>
          )}
          {emailState === 'simulated' && (
            <p className="text-sm text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-lg px-3 py-2">
              Tu empresa está en &quot;Probar sin enviar&quot;: el mensaje se arma y queda en la bandeja, pero no sale.
            </p>
          )}

          {!editing && (
            <div>
              <span className={labelClass}>Empezar desde</span>
              <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Empezar desde">
                {STARTERS.map((s) => (
                  <button key={s.id} type="button" role="radio" aria-checked={starter === s.id} data-starter={s.id} onClick={() => applyStarter(s.id)}
                    className={`px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${starter === s.id ? 'bg-brand text-white border-brand' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                    {s.label}
                  </button>
                ))}
              </div>
            </div>
          )}

          <MessageFields idPrefix="compose" subject={subject} onSubject={setSubject} body={body} onBody={setBody} variables={editing ? [] : channels?.variables ?? []} subjectPlaceholder="Ej. Tus resultados están listos" />

          <div>
            <span className={labelClass}>¿Cuándo sale?</span>
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Cuándo sale">
              {!editing && (
                <button type="button" role="radio" aria-checked={when === 'now'} data-when="now" onClick={() => setWhen('now')}
                  className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-bold transition-colors ${when === 'now' ? 'border-brand bg-brand-soft/15 text-brand-ink ring-2 ring-brand/10' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                  <Send size={15} /> Ahora
                </button>
              )}
              <button type="button" role="radio" aria-checked={when === 'later'} data-when="later" onClick={() => setWhen('later')}
                className={`flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-bold transition-colors ${when === 'later' ? 'border-brand bg-brand-soft/15 text-brand-ink ring-2 ring-brand/10' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
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
              <p className="col-span-2 text-xs text-slate-500">Mientras el sistema esté en tu computadora, sale cuando alguien usa el panel o con &quot;Revisar ahora&quot; en Mensajes. En el servidor sale a su hora.</p>
            </div>
          )}

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">Cancelar</Button>
          <Button type="submit" form={formId} data-submit disabled={saving || !ready} variant="primary">
            {saving ? 'Guardando...' : editing ? 'Guardar cambios' : when === 'later' ? 'Programar mensaje' : 'Enviar ahora'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
