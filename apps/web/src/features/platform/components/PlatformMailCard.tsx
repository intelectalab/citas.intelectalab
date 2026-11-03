'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useEffect, useState } from 'react';
import { Mail, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

interface PlatformMailData {
  configured: boolean;
  ready: boolean;
  host: string | null;
  port: number;
  security: 'ssl' | 'tls';
  username: string | null;
  from_address: string | null;
  from_name: string | null;
  has_password: boolean;
  tested_at: string | null;
  test_ok: boolean;
  test_message: string | null;
  failed_at?: string | null;
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const labelClass = 'block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5';
const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';

export default function PlatformMailCard() {
  const { user } = useAuth();
  const [data, setData] = useState<PlatformMailData | null>(null);
  const [form, setForm] = useState({ host: '', port: 465, security: 'ssl' as 'ssl' | 'tls', username: '', password: '', from_address: '', from_name: '' });
  const [testTo, setTestTo] = useState('');
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (payload: PlatformMailData) => {
    setData(payload);
    setForm({
      host: payload.host ?? '',
      port: payload.port ?? 465,
      security: payload.security ?? 'ssl',
      username: payload.username ?? '',
      password: '',
      from_address: payload.from_address ?? '',
      from_name: payload.from_name ?? '',
    });
  };

  useEffect(() => {
    api.get('/admin/platform-mail')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar el correo de la plataforma.')));
  }, []);

  useEffect(() => {
    if (user?.email && !testTo) setTestTo(user.email);
  }, [user?.email, testTo]);

  if (!data) {
    return error ? <section className={card}><ErrorAlert message={error} /></section> : null;
  }

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => {
    setNotice('');
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const run = async (action: string, call: () => Promise<any>) => {
    try {
      setBusy(action);
      setError('');
      setNotice('');
      const res = await call();
      return res.data;
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
      return null;
    } finally {
      setBusy('');
    }
  };

  const save = async () => {
    const payload = await run('save', () => api.put('/admin/platform-mail', { ...form, username: form.username || null, password: form.password || null, from_name: form.from_name || null }));
    if (payload) {
      apply(payload);
      setNotice(payload.ready ? 'Cambios guardados.' : 'Guardado. Ahora envía un correo de prueba: hasta que llegue, no se piden códigos al entrar.');
    }
  };

  const test = async () => {
    const payload = await run('test', () => api.post('/admin/platform-mail/test', { to: testTo }));
    if (payload) {
      apply(payload.mail);
      if (payload.ok) setNotice(payload.message);
      else setError(payload.message);
    }
  };

  const remove = async () => {
    const payload = await run('remove', () => api.delete('/admin/platform-mail'));
    if (payload) {
      apply(payload);
      setNotice('Se quitó el correo de la plataforma. Ya no se envían enlaces ni códigos.');
    }
  };

  const state = data.ready ? ['Funcionando', 'bg-emerald-50 text-emerald-700'] : data.configured && data.failed_at ? ['Dejó de funcionar', 'bg-rose-50 text-rose-700'] : data.configured ? ['Falta la prueba', 'bg-amber-50 text-amber-800'] : ['Sin configurar', 'bg-slate-100 text-slate-600'];

  return (
    <section className={card} data-card="platform-mail">
      <div className="flex items-start justify-between gap-3">
        <div>
          <SectionTitle><span className="text-brand-accent"><Mail size={16} /></span> Correo de la plataforma</SectionTitle>
          <p className="text-xs text-slate-500 font-medium mt-1">
            Desde este correo salen los enlaces para recuperar la contraseña, los códigos para entrar y los avisos de bloqueo. Los datos están en el panel de tu hosting (en cPanel: Cuentas de correo → Conectar dispositivos).
          </p>
        </div>
        <span className={`shrink-0 px-2.5 py-1 rounded-lg text-[11px] font-bold ${state[1]}`} data-mail-state>{state[0]}</span>
      </div>

      {data.configured && data.failed_at && (
        <p data-mail-failed className="text-xs text-rose-700 bg-rose-50 border border-rose-100 rounded-xl px-3 py-2.5">
          {data.test_message} Mientras tanto nadie recibe código al entrar (entran solo con su contraseña) y no funciona recuperar la contraseña. Corrige los datos y envía una prueba para volver a activarlo.
        </p>
      )}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="md:col-span-2">
          <label className={labelClass} htmlFor="pm-host">Servidor de salida (SMTP)</label>
          <input id="pm-host" maxLength={190} value={form.host} onChange={(e) => set('host', e.target.value)} placeholder="mail.tudominio.com" className={inputClass} />
        </div>
        <div>
          <label className={labelClass} htmlFor="pm-port">Puerto</label>
          <input id="pm-port" type="number" min={1} max={65535} value={form.port} onChange={(e) => set('port', Number(e.target.value))} className={inputClass} />
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Seguridad">
        {([['ssl', 'SSL', 'Normalmente con el puerto 465'], ['tls', 'TLS', 'Normalmente con el puerto 587']] as const).map(([key, label, hint]) => (
          <OptionCard key={key} type="button" role="radio" aria-checked={form.security === key} data-security={key} selected={form.security === key} onClick={() => setForm((prev) => ({ ...prev, security: key, port: key === 'ssl' ? 465 : 587 }))} className="text-left rounded-xl border px-3.5 py-2.5">
            <span className="block text-sm font-bold text-brand-ink">{label}</span>
            <span className="block text-xs text-slate-500">{hint}</span>
          </OptionCard>
        ))}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label className={labelClass} htmlFor="pm-user">Usuario</label>
          <input id="pm-user" maxLength={190} value={form.username} onChange={(e) => set('username', e.target.value)} placeholder="Casi siempre, el mismo correo" className={inputClass} />
        </div>
        <div>
          <label className={labelClass} htmlFor="pm-pass">Contraseña</label>
          <input id="pm-pass" type="password" autoComplete="new-password" maxLength={255} value={form.password} onChange={(e) => set('password', e.target.value)} placeholder={data.has_password ? '•••••••• (guardada)' : 'La del buzón'} className={inputClass} />
        </div>
        <div>
          <label className={labelClass} htmlFor="pm-from">Correo remitente</label>
          <input id="pm-from" type="email" maxLength={190} value={form.from_address} onChange={(e) => set('from_address', e.target.value)} placeholder="no-responder@tudominio.com" className={inputClass} />
        </div>
        <div>
          <label className={labelClass} htmlFor="pm-name">Nombre que verá quien lo recibe</label>
          <input id="pm-name" maxLength={100} value={form.from_name} onChange={(e) => set('from_name', e.target.value)} placeholder="Por ejemplo, el nombre del producto" className={inputClass} />
        </div>
      </div>

      <div className="rounded-2xl bg-slate-50 border border-slate-200 p-4 space-y-2">
        <label className={labelClass} htmlFor="pm-test">Enviar un correo de prueba a</label>
        <div className="flex flex-col sm:flex-row gap-2">
          <input id="pm-test" type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} className={`${inputClass} flex-1`} />
          <button type="button" data-mail-test disabled={busy !== '' || !data.configured || !testTo} onClick={test} className="flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 bg-white rounded-xl text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
            <Send size={14} /> {busy === 'test' ? 'Enviando...' : 'Enviar prueba'}
          </button>
        </div>
        <p className="text-xs text-slate-500">
          {data.tested_at ? `Última prueba ${momentOf(data.tested_at)}: ${data.test_ok ? 'llegó bien.' : 'falló.'}` : 'Los códigos para entrar se empiezan a pedir solo cuando una prueba sale bien.'}
        </p>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <div className="flex flex-wrap gap-2">
        <button type="button" data-mail-save disabled={busy !== '' || !form.host || !form.from_address} onClick={save} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
          {busy === 'save' ? 'Guardando...' : 'Guardar correo'}
        </button>
        {data.configured && (
          <button type="button" data-mail-remove disabled={busy !== ''} onClick={remove} className="ml-auto px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
            Quitar correo
          </button>
        )}
      </div>
    </section>
  );
}
