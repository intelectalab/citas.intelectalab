'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { ArrowLeft, Eye, EyeOff, KeyRound, Lock, Mail } from 'lucide-react';
import { api } from '@/lib/axios';
import { usePlatform } from '@/hooks/usePlatform';
import { useAuth } from '@/hooks/useAuth';

const inputClass = 'w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all';
const buttonClass = 'w-full bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center justify-center gap-2 text-sm shadow-md transition-all disabled:opacity-70 cursor-pointer';

const errorOf = (err: any, fallback: string) => {
  const errors = err?.response?.data?.errors;
  return (errors ? (Object.values(errors)[0] as string[])?.[0] : null) || err?.response?.data?.message || fallback;
};

function Shell({ title, text, children }: { title: string; text: string; children: React.ReactNode }) {
  const { platform } = usePlatform();

  return (
    <div className="min-h-screen w-full bg-slate-100/70 flex items-center justify-center p-4 font-sans text-[#1F2937]">
      <div className="w-full max-w-md bg-white rounded-3xl border border-slate-200/80 shadow-xl p-6 sm:p-10 space-y-5">
        <div className="space-y-2">
          <span className="text-xs font-semibold text-slate-400">{platform.product_name}</span>
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">{title}</h1>
          <p className="text-sm text-slate-500 font-medium">{text}</p>
        </div>
        {children}
        <Link href="/login" className="inline-flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink">
          <ArrowLeft className="w-3.5 h-3.5" /> Volver a iniciar sesión
        </Link>
      </div>
    </div>
  );
}

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState('');
  const [error, setError] = useState('');

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/password/forgot', { email });
      setSent(res.data.message);
    } catch (err) {
      setError(errorOf(err, 'No se pudo enviar el enlace. Inténtalo de nuevo.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Shell title="Recupera tu contraseña" text="Escribe el correo con el que entras. Te enviaremos un enlace para crear una contraseña nueva.">
      {sent ? (
        <p data-forgot-sent className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium">{sent}</p>
      ) : (
        <form onSubmit={submit} className="space-y-4" data-forgot-form>
          {error && <p role="alert" className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">{error}</p>}
          <div className="space-y-1.5">
            <label htmlFor="forgot-email" className="block text-xs font-bold text-brand-ink uppercase tracking-wider">Correo electrónico</label>
            <div className="relative">
              <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400"><Mail className="w-4 h-4" /></div>
              <input id="forgot-email" type="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} placeholder="usuario@empresa.com" className={inputClass} />
            </div>
          </div>
          <button type="submit" disabled={busy} className={buttonClass}>
            {busy ? <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" /> : <><KeyRound className="w-4 h-4 text-brand-soft" /> Enviarme el enlace</>}
          </button>
        </form>
      )}
    </Shell>
  );
}

export function ResetPasswordPage() {
  const params = useParams<{ token: string }>();
  const router = useRouter();
  const { login } = useAuth();
  const [info, setInfo] = useState<{ email: string; where: string; invite?: boolean; name?: string } | null>(null);
  const [invalid, setInvalid] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState('');

  useEffect(() => {
    api.get(`/password/reset/${params.token}`)
      .then((res) => setInfo(res.data))
      .catch((err) => setInvalid(errorOf(err, 'Este enlace ya no sirve: venció o ya se usó. Pide uno nuevo.')));
  }, [params.token]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== confirm) {
      setError('Las dos contraseñas no coinciden.');
      return;
    }
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/password/reset', { token: params.token, password, password_confirmation: confirm });
      setDone(res.data.message);
      if (res.data.access_token) {
        try {
          localStorage.setItem('last_activity', String(Date.now()));
          localStorage.setItem('auth_token', res.data.access_token);
          localStorage.setItem('user', JSON.stringify(res.data.user));
        } catch {
        }
        api.defaults.headers.common['Authorization'] = `Bearer ${res.data.access_token}`;
        login(res.data.access_token, res.data.user);
        router.push('/home?bienvenida=1');
        return;
      }
      try {
        sessionStorage.setItem('switch_account_email', res.data.email);
      } catch {
      }
      setTimeout(() => router.push('/login'), 2500);
    } catch (err) {
      setError(errorOf(err, 'No se pudo cambiar la contraseña.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Shell
      title={info?.invite ? `Te damos la bienvenida${info.name ? `, ${info.name}` : ''}` : 'Crea tu contraseña nueva'}
      text={info ? (info.invite ? `Crea la contraseña con la que entrarás a ${info.where} (${info.email}). Usa al menos 8 caracteres.` : `Para ${info.email} en ${info.where}. Usa al menos 8 caracteres.`) : 'Revisando el enlace...'}
    >
      {invalid ? (
        <div className="space-y-3">
          <p role="alert" className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-sm font-medium">{invalid}</p>
          <Link href="/recuperar" className="text-sm font-bold text-brand-accent hover:text-brand-ink">Pedir otro enlace</Link>
        </div>
      ) : done ? (
        <p data-reset-done className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium">{done}</p>
      ) : info ? (
        <form onSubmit={submit} className="space-y-4" data-reset-form>
          {error && <p role="alert" className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">{error}</p>}
          {[
            { id: 'new-password', label: 'Contraseña nueva', value: password, set: setPassword },
            { id: 'confirm-password', label: 'Repite la contraseña', value: confirm, set: setConfirm },
          ].map((field) => (
            <div key={field.id} className="space-y-1.5">
              <label htmlFor={field.id} className="block text-xs font-bold text-brand-ink uppercase tracking-wider">{field.label}</label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400"><Lock className="w-4 h-4" /></div>
                <input id={field.id} type={show ? 'text' : 'password'} required minLength={8} autoComplete="new-password" value={field.value} onChange={(e) => field.set(e.target.value)} placeholder="••••••••" className={`${inputClass} pr-10`} />
                <button type="button" aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'} onClick={() => setShow(!show)} className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600">
                  {show ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>
          ))}
          <button type="submit" disabled={busy} className={buttonClass}>
            {busy ? <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" /> : info.invite ? 'Guardar y entrar' : 'Guardar mi contraseña nueva'}
          </button>
        </form>
      ) : null}
    </Shell>
  );
}
