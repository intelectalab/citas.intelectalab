'use client';

import OptionCard from '@/app/components/common/OptionCard';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { BellRing, CheckCircle2, ChevronDown, Construction, FlaskConical, Mail, MessageCircle, Pencil, RotateCcw, Send, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import EmailDesignCard from './EmailDesignCard';
import MessageFields from '@/features/messages/components/MessageFields';
import { EMAIL_BADGES, type MessagingSettingsData, type NoticeSetting } from '@/features/messages/messages';

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

type Mode = 'none' | 'simulated' | 'smtp';

const STEPS: Record<string, { title: string; steps: string[] }> = {
  gmail: {
    title: 'Cómo sacar la contraseña de aplicación de Gmail',
    steps: [
      'Entra a myaccount.google.com con el correo de tu empresa y abre "Seguridad".',
      'Activa la "Verificación en dos pasos" si aún no está activa.',
      'Busca "Contraseñas de aplicaciones", crea una con el nombre "Citas" y copia las 16 letras que te muestra.',
      'Pégalas aquí abajo. No es tu contraseña normal: solo sirve para enviar correos y la puedes borrar cuando quieras.',
    ],
  },
  outlook: {
    title: 'Qué contraseña usar con Outlook / Microsoft 365',
    steps: [
      'Si tu cuenta tiene verificación en dos pasos, crea una contraseña de aplicación en account.microsoft.com → Seguridad → Opciones de seguridad avanzadas.',
      'Si es una cuenta de empresa (Microsoft 365), quien la administra debe tener activado el envío por SMTP.',
      'Escribe aquí el correo y esa contraseña.',
    ],
  },
  brevo: {
    title: 'Dónde están tus datos en Brevo',
    steps: [
      'En Brevo, abre el menú de tu cuenta → "SMTP y API" → pestaña "SMTP".',
      'Ahí ves tu "Usuario SMTP" y puedes generar una "Clave SMTP".',
      'El correo que envía debe estar verificado en Brevo, en "Remitentes".',
    ],
  },
};

function NoticePanel({ notice, canSave, onClose, onSaved }: { notice: NoticeSetting | null; canSave: boolean; onClose: () => void; onSaved: (n: NoticeSetting) => void }) {
  const [enabled, setEnabled] = useState(true);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [hours, setHours] = useState('24');
  const [second, setSecond] = useState(false);
  const [days, setDays] = useState('7');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!notice) return;
    setEnabled(notice.enabled);
    setSubject(notice.subject);
    setBody(notice.body);
    setHours(String(notice.options.hours ?? 24));
    setSecond(Boolean(notice.options.second));
    setDays(String(notice.options.days ?? 7));
    setError('');
  }, [notice]);

  const save = async (reset = false) => {
    if (!notice) return;
    try {
      setSaving(true);
      setError('');
      const res = reset
        ? await api.post(`/messaging/notices/${notice.kind}/reset`)
        : await api.put(`/messaging/notices/${notice.kind}`, {
          enabled,
          subject,
          body,
          options: {
            ...(notice.options.hours !== undefined ? { hours: Number(hours || 0), second } : {}),
            ...(notice.options.days !== undefined ? { days: Number(days || 0) } : {}),
          },
        });
      onSaved(res.data);
      if (reset) { setSubject(res.data.subject); setBody(res.data.body); } else onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el aviso.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer isOpen={notice !== null} onClose={onClose} title={notice ? notice.label : ''} subtitle={notice?.when} icon={<BellRing size={20} />} maxWidth="max-w-2xl">
      {notice && (
        <div className="flex flex-col h-full justify-between">
          <div className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-notice-form={notice.kind}>
            {!notice.manual && (
              <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
                <div>
                  <span className="block text-sm font-semibold text-slate-800">Enviar este aviso</span>
                  <span className="block text-xs text-slate-500">Apágalo si tu empresa no quiere enviarlo.</span>
                </div>
                <Switch checked={enabled} onChange={setEnabled} label="Enviar este aviso" disabled={!canSave} />
              </div>
            )}

            {notice.options.hours !== undefined && (
              <div className="space-y-3 rounded-xl border border-slate-200 px-4 py-3">
                <div className="flex flex-wrap items-center gap-3">
                  <label htmlFor="notice-hours" className="text-sm font-semibold text-slate-800">Se envía</label>
                  <input id="notice-hours" inputMode="numeric" className="w-20 text-sm border border-slate-200 rounded-lg px-3 py-2 text-center" value={hours} onChange={(e) => setHours(e.target.value.replace(/\D/g, '').slice(0, 3))} />
                  <span className="text-sm text-slate-600">horas antes de la cita.</span>
                </div>
                <p className="text-xs text-slate-500">Ejemplo con 24: para una cita del sábado a las 10:00, sale el viernes a las 10:00. Si la cita se agenda con menos anticipación, no se envía: ya le llegó la confirmación.</p>
                <div className="flex items-center justify-between gap-3 pt-2 border-t border-slate-100">
                  <span className="text-sm text-slate-700">Además, un segundo recordatorio 2 horas antes</span>
                  <Switch checked={second} onChange={setSecond} label="Segundo recordatorio" disabled={!canSave} />
                </div>
              </div>
            )}

            {notice.options.days !== undefined && (
              <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 px-4 py-3">
                <label htmlFor="notice-days" className="text-sm font-semibold text-slate-800">Se envía</label>
                <input id="notice-days" inputMode="numeric" className="w-20 text-sm border border-slate-200 rounded-lg px-3 py-2 text-center" value={days} onChange={(e) => setDays(e.target.value.replace(/\D/g, '').slice(0, 2))} />
                <span className="text-sm text-slate-600">días antes.</span>
              </div>
            )}

            <MessageFields idPrefix="notice" subject={subject} onSubject={setSubject} body={body} onBody={setBody} variables={notice.variables} required={false} disabled={!canSave} previewOpen
              hint={canSave ? 'Cada dato aparece entre corchetes, por ejemplo [Nombre], y al enviarse se cambia por el dato real de cada cliente.' : undefined} />

            <ErrorAlert message={error} />
          </div>

          {canSave && (
            <div className="flex items-center justify-between gap-3 pt-4 border-t border-slate-100">
              {notice.custom ? (
                <button type="button" data-reset-notice disabled={saving} onClick={() => save(true)} className="flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-[#022340]">
                  <RotateCcw size={13} /> Volver al texto original
                </button>
              ) : <span />}
              <div className="flex gap-3">
                <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Cancelar</button>
                <button type="button" data-submit disabled={saving || !subject.trim() || !body.trim()} onClick={() => save()} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] disabled:opacity-50">{saving ? 'Guardando...' : 'Guardar'}</button>
              </div>
            </div>
          )}
        </div>
      )}
    </FormDrawer>
  );
}

type Part = 'email' | 'design' | 'notices';

interface MessagingSettingsProps {
  canSave: boolean;
  company: string;
  part: Part;
  onDirtyChange?: (dirty: boolean) => void;
}

const emailState = (mode: Mode, form: Record<string, string>) => JSON.stringify({ mode, ...form, password: '' });

export default function MessagingSettings({ canSave, company, part, onDirtyChange }: MessagingSettingsProps) {
  const [data, setData] = useState<MessagingSettingsData | null>(null);
  const [mode, setMode] = useState<Mode>('none');
  const [form, setForm] = useState({ preset: '', host: '', port: '587', security: 'tls', username: '', password: '', from_address: '', from_name: '', reply_to: '' });
  const [saved, setSaved] = useState<string | null>(null);
  const [testTo, setTestTo] = useState('');
  const [editing, setEditing] = useState<NoticeSetting | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [advanced, setAdvanced] = useState(false);

  const apply = (payload: MessagingSettingsData) => {
    setData(payload);
    const config = payload.email.config;
    setMode(payload.email.state === 'none' ? 'none' : config?.provider === 'simulated' ? 'simulated' : payload.own_email === false ? 'none' : 'smtp');
    if (config?.provider !== 'smtp') {
      const gmail = payload.presets.find((p) => p.key === 'gmail');
      if (gmail) setForm((current) => (current.host ? current : { ...current, preset: 'gmail', host: gmail.host, port: String(gmail.port), security: gmail.security }));
    }
    if (config?.provider === 'smtp') {
      setForm({
        preset: config.preset ?? '', host: config.host ?? '', port: String(config.port ?? 587), security: config.security ?? 'tls',
        username: config.username ?? '', password: '', from_address: config.from_address ?? '', from_name: config.from_name ?? '', reply_to: config.reply_to ?? '',
      });
    }
  };

  useEffect(() => {
    api.get('/messaging/settings').then((res) => apply(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar la configuración de mensajes.')));
  }, []);

  useEffect(() => {
    if (data) setSaved(null);
  }, [data]);

  useEffect(() => {
    if (data && saved === null) setSaved(emailState(mode, form));
  }, [data, saved, mode, form]);

  const emailDirty = part === 'email' && saved !== null && (emailState(mode, form) !== saved || form.password !== '');

  useEffect(() => {
    if (part === 'email') onDirtyChange?.(emailDirty);
  }, [part, emailDirty, onDirtyChange]);

  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);

  if (!data) return error ? <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p> : null;

  const set = (changes: Partial<typeof form>) => setForm((current) => ({ ...current, ...changes }));
  const preset = data.presets.find((p) => p.key === form.preset);
  const simple = form.preset === 'gmail' || form.preset === 'outlook';

  const run = async (fn: () => Promise<{ data: MessagingSettingsData & { ok?: boolean; message?: string } }>, done?: (res: MessagingSettingsData & { ok?: boolean; message?: string }) => void) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await fn();
      apply(res.data);
      done?.(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const save = () => run(
    () => api.put('/messaging/email', mode === 'simulated' ? { provider: 'simulated' } : { provider: 'smtp', ...form, port: Number(form.port || 0), preset: form.preset || null }),
    () => setNotice(mode === 'simulated' ? 'Listo: los mensajes se arman y quedan en la bandeja, pero no salen.' : 'Datos guardados. Ahora envía un correo de prueba para confirmar que funciona.'),
  );

  const status = data.email;

  return (
    <>
      {part === 'email' && (
      <section className={cardClass} id="correo-config" data-card="email-settings">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <SectionTitle><Mail className="w-4 h-4 text-[#3F768C]" /> Correo de tu empresa</SectionTitle>
            <p className="text-xs text-slate-500 mt-1">Los avisos y mensajes a tus clientes salen desde el correo que configures aquí. Mientras no lo configures, no sale ningún correo.</p>
          </div>
          <span className={`px-3 py-1.5 text-xs font-semibold rounded-full border ${EMAIL_BADGES[status.state]}`} data-email-state={status.state}>{status.label}</span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-2" role="radiogroup" aria-label="Cómo se envían los correos">
          {([
            ['none', 'Sin configurar', 'No sale ningún correo. Los mensajes quedan como "No enviado".'],
            ['simulated', 'Probar sin enviar', 'Para probar: los mensajes se arman y se ven en la bandeja, pero no salen.'],
            ['smtp', 'Con mi correo', 'Gmail, Outlook, Brevo o el correo de tu dominio. Salen de verdad.'],
          ] as [Mode, string, string][]).map(([value, title, hint]) => {
            const building = value === 'smtp' && data.own_email === false;
            return (
              <OptionCard key={value} type="button" role="radio" aria-checked={mode === value} data-email-mode={value} data-building={building || undefined} disabled={!canSave || building} onClick={() => { setMode(value); setNotice(''); setError(''); }} selected={mode === value} className="text-left rounded-xl border px-4 py-3 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                <span className="flex items-center justify-between gap-2">
                  <span className="block text-sm font-bold text-[#022340]">{title}</span>
                  {building && <span className="flex items-center gap-1 px-2 py-0.5 text-[11px] font-bold rounded-full bg-amber-100 text-amber-800 shrink-0"><Construction size={12} /> En construcción</span>}
                </span>
                <span className="block text-xs text-slate-500 mt-0.5">{building ? 'Estamos trabajando en esto. Muy pronto tus correos saldrán desde el correo de tu empresa.' : hint}</span>
              </OptionCard>
            );
          })}
        </div>

        {data.own_email === false && (
          <p className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-email-building>
            <Construction size={16} className="mt-0.5 shrink-0" />
            <span>Enviar desde el correo de tu empresa está <strong>en construcción</strong>. Mientras tanto, elige &quot;Probar sin enviar&quot;: los avisos y las campañas se arman y los ves en Mensajes, pero no salen.</span>
          </p>
        )}

        {mode === 'smtp' && data.own_email !== false && (
          <div className="space-y-4 rounded-2xl border border-slate-200 p-4" data-smtp-form>
            <div>
              <span className={labelClass}>¿Qué correo usa tu empresa?</span>
              <div className="flex flex-wrap gap-2">
                {data.presets.map((p) => (
                  <button key={p.key} type="button" data-preset={p.key} disabled={!canSave} onClick={() => set({ preset: p.key, host: p.host, port: String(p.port), security: p.security })}
                    className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${form.preset === p.key ? 'bg-[#022340] text-white border-[#022340]' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                    {p.label}
                  </button>
                ))}
                <button type="button" data-preset="other" disabled={!canSave} onClick={() => { set({ preset: '' }); setAdvanced(true); }}
                  className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${!form.preset ? 'bg-[#022340] text-white border-[#022340]' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                  Otro (correo de mi dominio)
                </button>
              </div>
            </div>

            {preset && STEPS[preset.key] && (
              <div className="rounded-xl bg-[#84D0D9]/15 border border-[#84D0D9]/50 px-4 py-3" data-steps={preset.key}>
                <span className="block text-xs font-bold text-[#022340] mb-1.5">{STEPS[preset.key].title}</span>
                <ol className="list-decimal pl-4 space-y-1 text-xs text-slate-700">
                  {STEPS[preset.key].steps.map((step) => <li key={step}>{step}</li>)}
                </ol>
              </div>
            )}

            {simple && (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3" data-simple-fields>
                <div>
                  <label className={labelClass} htmlFor="smtp-email">{form.preset === 'gmail' ? 'Correo de Gmail *' : 'Correo *'}</label>
                  <input id="smtp-email" className={inputClass} placeholder={form.preset === 'gmail' ? 'citas.tuempresa@gmail.com' : 'citas@tuempresa.com'} value={form.from_address} onChange={(e) => { const v = e.target.value.trim(); set({ from_address: v, username: v }); }} disabled={!canSave} autoComplete="off" />
                </div>
                <div>
                  <label className={labelClass} htmlFor="smtp-pass">Contraseña de aplicación *</label>
                  <input id="smtp-pass" type="password" className={inputClass} placeholder={status.config?.password_saved ?? 'abcd efgh ijkl mnop'} value={form.password} onChange={(e) => set({ password: e.target.value })} disabled={!canSave} autoComplete="new-password" />
                </div>
                <div>
                  <label className={labelClass} htmlFor="smtp-name">Nombre que ve el cliente</label>
                  <input id="smtp-name" className={inputClass} placeholder={company} value={form.from_name} onChange={(e) => set({ from_name: e.target.value })} disabled={!canSave} />
                </div>
              </div>
            )}

            {form.preset === 'brevo' && (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3" data-brevo-fields>
                <div>
                  <label className={labelClass} htmlFor="smtp-user">Usuario SMTP de Brevo *</label>
                  <input id="smtp-user" className={inputClass} placeholder="9a1b2c001@smtp-brevo.com" value={form.username} onChange={(e) => set({ username: e.target.value.trim() })} disabled={!canSave} autoComplete="off" />
                </div>
                <div>
                  <label className={labelClass} htmlFor="smtp-pass">Clave SMTP *</label>
                  <input id="smtp-pass" type="password" className={inputClass} placeholder={status.config?.password_saved ?? ''} value={form.password} onChange={(e) => set({ password: e.target.value })} disabled={!canSave} autoComplete="new-password" />
                </div>
                <div>
                  <label className={labelClass} htmlFor="smtp-from">Correo que envía *</label>
                  <input id="smtp-from" className={inputClass} placeholder="citas@tuempresa.com" value={form.from_address} onChange={(e) => set({ from_address: e.target.value.trim() })} disabled={!canSave} />
                </div>
                <div>
                  <label className={labelClass} htmlFor="smtp-name">Nombre que ve el cliente</label>
                  <input id="smtp-name" className={inputClass} placeholder={company} value={form.from_name} onChange={(e) => set({ from_name: e.target.value })} disabled={!canSave} />
                </div>
              </div>
            )}

            {!form.preset && (
              <p className="text-xs text-slate-600" data-other-hint>Estos datos te los da quien te vende el correo de tu dominio (tu proveedor de hosting o de correo). Búscalos como &quot;datos SMTP&quot; o &quot;configuración de correo saliente&quot;.</p>
            )}

            {status.config?.password_saved && <p className="text-xs text-slate-500">La contraseña guardada está cifrada. Déjala vacía para no cambiarla.</p>}

            <div className="border-t border-slate-100 pt-3">
              {form.preset && (
                <button type="button" data-advanced onClick={() => setAdvanced(!advanced)} className="flex items-center gap-1.5 text-xs font-bold text-[#3F768C] hover:text-[#022340]">
                  <ChevronDown size={14} className={`transition-transform ${advanced ? 'rotate-180' : ''}`} /> Opciones avanzadas
                </button>
              )}

              {(advanced || !form.preset) && (
                <div className="space-y-3 mt-3" data-advanced-fields>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div className="md:col-span-2">
                      <label className={labelClass} htmlFor="smtp-host">Servidor *</label>
                      <input id="smtp-host" className={inputClass} placeholder="mail.tuempresa.com" value={form.host} onChange={(e) => set({ host: e.target.value.trim() })} disabled={!canSave} />
                    </div>
                    <div>
                      <label className={labelClass} htmlFor="smtp-port">Puerto *</label>
                      <input id="smtp-port" inputMode="numeric" className={inputClass} value={form.port} onChange={(e) => set({ port: e.target.value.replace(/\D/g, '').slice(0, 5) })} disabled={!canSave} />
                    </div>
                    <div>
                      <Select label="Seguridad" value={form.security} clearable={false} disabled={!canSave} onChange={(e) => set({ security: String(e.target.value) })}
                        options={[{ value: 'tls', label: 'TLS (puerto 587)' }, { value: 'ssl', label: 'SSL (puerto 465)' }]} />
                    </div>
                    {!form.preset && (
                      <>
                        <div>
                          <label className={labelClass} htmlFor="smtp-user">Usuario</label>
                          <input id="smtp-user" className={inputClass} placeholder="citas@tuempresa.com" value={form.username} onChange={(e) => set({ username: e.target.value.trim() })} disabled={!canSave} autoComplete="off" />
                        </div>
                        <div>
                          <label className={labelClass} htmlFor="smtp-pass">Contraseña</label>
                          <input id="smtp-pass" type="password" className={inputClass} placeholder={status.config?.password_saved ?? ''} value={form.password} onChange={(e) => set({ password: e.target.value })} disabled={!canSave} autoComplete="new-password" />
                        </div>
                        <div>
                          <label className={labelClass} htmlFor="smtp-from">Se envía desde *</label>
                          <input id="smtp-from" className={inputClass} placeholder="citas@tuempresa.com" value={form.from_address} onChange={(e) => set({ from_address: e.target.value.trim() })} disabled={!canSave} />
                        </div>
                        <div>
                          <label className={labelClass} htmlFor="smtp-name">Nombre que ve el cliente</label>
                          <input id="smtp-name" className={inputClass} placeholder={company} value={form.from_name} onChange={(e) => set({ from_name: e.target.value })} disabled={!canSave} />
                        </div>
                      </>
                    )}
                    <div>
                      <label className={labelClass} htmlFor="smtp-reply">Las respuestas llegan a</label>
                      <input id="smtp-reply" className={inputClass} placeholder="Opcional: otro correo" value={form.reply_to} onChange={(e) => set({ reply_to: e.target.value.trim() })} disabled={!canSave} />
                    </div>
                  </div>
                  {form.preset ? (
                    <p className="text-xs text-slate-500">Ya vienen llenos para {preset?.label}: cámbialos solo si tu proveedor te lo indica.</p>
                  ) : (
                    <p className="text-xs text-slate-500">Usa como &quot;Se envía desde&quot; el mismo correo de la cuenta: muchos servidores rechazan otro remitente.</p>
                  )}
                </div>
              )}
            </div>
          </div>
        )}

        <ErrorAlert message={error} />
        <SuccessAlert message={notice} />

        {canSave && (
          <div className="flex flex-wrap items-center gap-3">
            {mode !== 'none' && (
              <button type="button" data-save-email disabled={busy} onClick={save} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] disabled:opacity-50">
                {busy ? 'Guardando...' : 'Guardar'}
              </button>
            )}
            {mode === 'none' && status.state !== 'none' && (
              <button type="button" data-remove-email disabled={busy} onClick={() => run(() => api.delete('/messaging/email'), () => setNotice('Listo: ya no sale ningún correo.'))} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-rose-200 text-rose-700 rounded-xl hover:bg-rose-50 disabled:opacity-50">
                <Trash2 size={14} /> Quitar el correo configurado
              </button>
            )}
          </div>
        )}

        {canSave && status.state !== 'none' && status.config?.provider === 'smtp' && mode === 'smtp' && (
          <div className="space-y-2 pt-4 border-t border-slate-100" data-test-email>
            <span className={labelClass}>Enviar un correo de prueba</span>
            <div className="flex flex-wrap items-center gap-2">
              <input aria-label="Correo para la prueba" className={`${inputClass} max-w-xs`} placeholder="tu.correo@gmail.com" value={testTo} onChange={(e) => setTestTo(e.target.value.trim())} />
              <button type="button" data-send-test disabled={busy || !testTo} onClick={() => run(() => api.post('/messaging/email/test', { to: testTo }), (res) => { if (res.ok) setNotice(res.message ?? ''); else setError(res.message ?? 'Falló la prueba.'); })}
                className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold border border-[#022340] text-[#022340] rounded-xl hover:bg-[#84D0D9]/15 disabled:opacity-50">
                <Send size={14} /> Enviar prueba
              </button>
            </div>
            <p className="text-xs text-slate-500">Mándalo a tu propio correo. {status.config.tested_at ? `Última prueba: ${momentOf(status.config.tested_at)}.` : ''}</p>
          </div>
        )}

        <div className="flex items-start gap-3 rounded-xl border border-slate-200 px-4 py-3">
          <span className="p-2 rounded-lg bg-[#84D0D9]/20 text-[#022340] shrink-0"><MessageCircle size={16} /></span>
          <span>
            <span className="block text-sm font-bold text-[#022340]">WhatsApp</span>
            <span className="block text-xs text-slate-500 mt-0.5">{data.whatsapp.label}. Siempre necesita un proveedor (Meta u otro), con costo por mensaje y plantillas aprobadas.</span>
          </span>
        </div>
        {emailDirty && <p className="text-xs text-amber-700 text-right" data-unsaved>Tienes cambios sin guardar.</p>}
      </section>
      )}

      {part === 'design' && (data.design
        ? <EmailDesignCard design={data.design} canSave={canSave} onSaved={apply} onDirtyChange={onDirtyChange} />
        : <section className={cardClass}><p className="text-sm text-slate-500">Falta actualizar la base de datos para usar el diseño del correo: ejecuta php artisan migrate.</p></section>)}

      {part === 'notices' && (
      <section className={cardClass} data-card="notices">
        <div>
          <SectionTitle><BellRing className="w-4 h-4 text-[#3F768C]" /> Mensajes al cliente</SectionTitle>
          <p className="text-xs text-slate-500 mt-1">Los avisos que salen solos. Cada uno ya viene escrito: revísalo y cámbialo si quieres. Los avisos de su cita no necesitan que el cliente acepte promociones.</p>
        </div>
        <div className="rounded-2xl border border-slate-200 overflow-hidden">
          <PagedList
            items={data.notices}
            empty="Sin avisos."
            renderRow={(n) => (
              <li key={n.kind} className="flex items-center gap-3 px-5 py-3" data-notice-row={n.kind}>
                <div className="flex-1 min-w-0">
                  <span className="flex items-center gap-2 text-sm font-bold text-slate-800">
                    {n.label}
                    {n.custom && <span className="px-1.5 py-0.5 text-[10px] font-bold rounded bg-violet-50 text-violet-700">Texto propio</span>}
                  </span>
                  <span className="block text-xs text-slate-500 line-clamp-2">{n.when}{n.options.hours ? ` · ${n.options.hours} h antes${n.options.second ? ' y 2 h antes' : ''}` : ''}{n.options.days !== undefined ? ` · ${n.options.days} días antes` : ''}</span>
                </div>
                {n.manual ? (
                  <span className="text-xs text-slate-400 shrink-0">Lo envía el personal</span>
                ) : (
                  <Switch checked={n.enabled} label={`Enviar ${n.label}`} disabled={!canSave}
                    onChange={(on) => api.put(`/messaging/notices/${n.kind}`, { enabled: on, subject: n.subject, body: n.body, options: n.options })
                      .then((res) => setData((current) => current && { ...current, notices: current.notices.map((x) => (x.kind === n.kind ? res.data : x)) }))
                      .catch((err) => setError(apiError(err, 'No se pudo cambiar el aviso.')))} />
                )}
                <button type="button" data-edit-notice={n.kind} title={`Ver y cambiar ${n.label}`} onClick={() => setEditing(n)} className="p-1.5 text-slate-400 hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors shrink-0">
                  <Pencil size={15} />
                </button>
              </li>
            )}
          />
        </div>
        {status.state === 'none' && (
          <p className="flex items-center gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><FlaskConical size={14} /> Mientras tu empresa no configure su correo, estos avisos quedan como &quot;No enviado&quot; en Mensajes.</p>
        )}
        {status.state === 'ok' && <p className="flex items-center gap-2 text-xs text-emerald-700"><CheckCircle2 size={14} /> Los avisos encendidos ya salen desde tu correo.</p>}
        <ErrorAlert message={error} />
      </section>
      )}

      <NoticePanel
        notice={editing}
        canSave={canSave}
        onClose={() => setEditing(null)}
        onSaved={(n) => { setData((current) => current && { ...current, notices: current.notices.map((x) => (x.kind === n.kind ? n : x)) }); setEditing((current) => (current ? n : current)); }}
      />
    </>
  );
}
