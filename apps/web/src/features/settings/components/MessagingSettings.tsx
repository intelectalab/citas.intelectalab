'use client';

import OptionCard from '@/app/components/common/OptionCard';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useRef, useState } from 'react';
import { BellRing, CheckCircle2, FlaskConical, Mail, MessageCircle, Pencil, RotateCcw, Send, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { EMAIL_BADGES, type MessagingSettingsData, type NoticeSetting } from '@/features/messages/messages';

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

const SAMPLE: Record<string, string> = {
  '[Nombre]': 'Juliana', '[Servicio]': 'Control Pediátrico', '[Fecha]': 'martes 20 de octubre', '[Hora]': '10:00 a. m.',
  '[Profesional]': 'Dra. María Gómez', '[Sede]': 'Sede Central - Miraflores', '[Código]': 'C-0248', '[Empresa]': 'tu empresa',
  '[Paquete]': 'Paquete 5 controles', '[Sesiones]': '2 sesiones', '[Vence]': 'viernes 30 de octubre', '[Monto]': 'S/ 60.00',
  '[Detalle]': '• Control Pediátrico: S/ 60.00', '[Comprobante]': 'B001-00000123', '[Link]': 'https://…/pay/…',
  '[Portal]': 'https://…/login', '[Correo]': 'juliana@correo.com', '[Control]': 'control de vacunas',
};

const fill = (text: string, company: string) => Object.entries(SAMPLE).reduce((acc, [token, value]) => acc.split(token).join(token === '[Empresa]' ? company : value), text);

type Mode = 'none' | 'simulated' | 'smtp';

function NoticePanel({ notice, company, canSave, onClose, onSaved }: { notice: NoticeSetting | null; company: string; canSave: boolean; onClose: () => void; onSaved: (n: NoticeSetting) => void }) {
  const [enabled, setEnabled] = useState(true);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [hours, setHours] = useState('24');
  const [second, setSecond] = useState(false);
  const [days, setDays] = useState('7');
  const [target, setTarget] = useState<'subject' | 'body'>('body');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const bodyRef = useRef<HTMLTextAreaElement>(null);
  const subjectRef = useRef<HTMLInputElement>(null);

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

  const insert = (token: string) => {
    const el = target === 'subject' ? subjectRef.current : bodyRef.current;
    const value = target === 'subject' ? subject : body;
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? value.length;
    const next = value.slice(0, start) + token + value.slice(end);
    if (target === 'subject') setSubject(next); else setBody(next);
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + token.length, start + token.length); });
  };

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

            <div>
              <label className={labelClass} htmlFor="notice-subject">Asunto</label>
              <input id="notice-subject" ref={subjectRef} maxLength={200} className={inputClass} value={subject} onFocus={() => setTarget('subject')} onChange={(e) => setSubject(e.target.value)} disabled={!canSave} />
            </div>
            <div>
              <label className={labelClass} htmlFor="notice-body">Mensaje</label>
              <textarea id="notice-body" ref={bodyRef} rows={9} maxLength={5000} className={inputClass} value={body} onFocus={() => setTarget('body')} onChange={(e) => setBody(e.target.value)} disabled={!canSave} />
            </div>

            {canSave && (
              <div data-variables>
                <span className={labelClass}>Agregar un dato {target === 'subject' ? 'al asunto' : 'al mensaje'}</span>
                <div className="flex flex-wrap gap-1.5">
                  {notice.variables.map((v) => (
                    <button key={v.token} type="button" data-variable={v.token} onClick={() => insert(v.token)}
                      className="px-2.5 py-1 text-xs font-semibold rounded-lg border border-[#84D0D9] bg-[#84D0D9]/15 text-[#022340] hover:bg-[#84D0D9]/30 transition-colors">
                      + {v.label}
                    </button>
                  ))}
                </div>
                <p className="text-xs text-slate-500 mt-1.5">Cada dato aparece entre corchetes, por ejemplo [Nombre], y al enviarse se cambia por el dato real de cada cliente.</p>
              </div>
            )}

            <div className="rounded-2xl border border-slate-200 overflow-hidden" data-preview>
              <div className="px-5 py-2.5 bg-slate-50/80 border-b border-slate-100 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Así le llega (con datos de ejemplo)</div>
              <div className="px-5 py-3 text-sm font-bold text-[#022340] border-b border-slate-100">{fill(subject, company)}</div>
              <p className="px-5 py-4 text-sm text-slate-700 whitespace-pre-line leading-relaxed">{fill(body, company)}</p>
            </div>

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

export default function MessagingSettings({ canSave, company }: { canSave: boolean; company: string }) {
  const [data, setData] = useState<MessagingSettingsData | null>(null);
  const [mode, setMode] = useState<Mode>('none');
  const [form, setForm] = useState({ preset: '', host: '', port: '587', security: 'tls', username: '', password: '', from_address: '', from_name: '', reply_to: '' });
  const [testTo, setTestTo] = useState('');
  const [editing, setEditing] = useState<NoticeSetting | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (payload: MessagingSettingsData) => {
    setData(payload);
    const config = payload.email.config;
    setMode(payload.email.state === 'none' ? 'none' : config?.provider === 'simulated' ? 'simulated' : 'smtp');
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

  if (!data) return error ? <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p> : null;

  const set = (changes: Partial<typeof form>) => setForm((current) => ({ ...current, ...changes }));
  const preset = data.presets.find((p) => p.key === form.preset);

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
      <section className={cardClass} id="correo" data-card="email-settings">
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
          ] as [Mode, string, string][]).map(([value, title, hint]) => (
            <OptionCard key={value} type="button" role="radio" aria-checked={mode === value} data-email-mode={value} disabled={!canSave} onClick={() => { setMode(value); setNotice(''); setError(''); }} selected={mode === value} className="text-left rounded-xl border px-4 py-3 transition-colors disabled:opacity-60">
              <span className="block text-sm font-bold text-[#022340]">{title}</span>
              <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
            </OptionCard>
          ))}
        </div>

        {mode === 'smtp' && (
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
                <button type="button" data-preset="other" disabled={!canSave} onClick={() => set({ preset: '' })}
                  className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${!form.preset ? 'bg-[#022340] text-white border-[#022340]' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                  Otro
                </button>
              </div>
              {preset && <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 mt-2">{preset.hint}</p>}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div className="md:col-span-2">
                <label className={labelClass} htmlFor="smtp-host">Servidor *</label>
                <input id="smtp-host" className={inputClass} placeholder="smtp.gmail.com" value={form.host} onChange={(e) => set({ host: e.target.value.trim() })} disabled={!canSave} />
              </div>
              <div>
                <label className={labelClass} htmlFor="smtp-port">Puerto *</label>
                <input id="smtp-port" inputMode="numeric" className={inputClass} value={form.port} onChange={(e) => set({ port: e.target.value.replace(/\D/g, '').slice(0, 5) })} disabled={!canSave} />
              </div>
              <div>
                <Select label="Seguridad" value={form.security} clearable={false} disabled={!canSave} onChange={(e) => set({ security: String(e.target.value) })}
                  options={[{ value: 'tls', label: 'TLS (puerto 587)' }, { value: 'ssl', label: 'SSL (puerto 465)' }]} />
              </div>
              <div>
                <label className={labelClass} htmlFor="smtp-user">Usuario</label>
                <input id="smtp-user" className={inputClass} placeholder="citas@tuempresa.com" value={form.username} onChange={(e) => set({ username: e.target.value.trim() })} disabled={!canSave} autoComplete="off" />
              </div>
              <div>
                <label className={labelClass} htmlFor="smtp-pass">Contraseña</label>
                <input id="smtp-pass" type="password" className={inputClass} placeholder={status.config?.password_saved ?? ''} value={form.password} onChange={(e) => set({ password: e.target.value })} disabled={!canSave} autoComplete="new-password" />
              </div>
            </div>
            {status.config?.password_saved && <p className="-mt-2 text-xs text-slate-500">La contraseña guardada está cifrada. Déjala vacía para no cambiarla.</p>}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label className={labelClass} htmlFor="smtp-from">Se envía desde *</label>
                <input id="smtp-from" className={inputClass} placeholder="citas@tuempresa.com" value={form.from_address} onChange={(e) => set({ from_address: e.target.value.trim() })} disabled={!canSave} />
              </div>
              <div>
                <label className={labelClass} htmlFor="smtp-name">Nombre que ve el cliente</label>
                <input id="smtp-name" className={inputClass} placeholder={company} value={form.from_name} onChange={(e) => set({ from_name: e.target.value })} disabled={!canSave} />
              </div>
              <div>
                <label className={labelClass} htmlFor="smtp-reply">Las respuestas llegan a</label>
                <input id="smtp-reply" className={inputClass} placeholder="Opcional" value={form.reply_to} onChange={(e) => set({ reply_to: e.target.value.trim() })} disabled={!canSave} />
              </div>
            </div>
            <p className="text-xs text-slate-500">Usa como &quot;Se envía desde&quot; el mismo correo de la cuenta: muchos servidores rechazan otro remitente.</p>
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
      </section>

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
      </section>

      <NoticePanel
        notice={editing}
        company={company}
        canSave={canSave}
        onClose={() => setEditing(null)}
        onSaved={(n) => { setData((current) => current && { ...current, notices: current.notices.map((x) => (x.kind === n.kind ? n : x)) }); setEditing((current) => (current ? n : current)); }}
      />
    </>
  );
}
