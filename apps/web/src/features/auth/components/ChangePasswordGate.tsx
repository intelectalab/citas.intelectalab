'use client';

import { useState } from 'react';
import { Eye, EyeOff, KeyRound, Lock } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';

const inputClass = 'w-full pl-10 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all';

export default function ChangePasswordGate({ children }: { children: React.ReactNode }) {
  const { user, refetchUser, logout } = useAuth();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (!user?.must_change_password || user.impersonator || user.account_type === 'customer') {
    return <>{children}</>;
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== confirm) {
      setError('Las dos contraseñas no coinciden.');
      return;
    }
    try {
      setBusy(true);
      setError('');
      await api.put('/account/password', { current_password: current, password, password_confirmation: confirm });
      await refetchUser();
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      setError((errors ? (Object.values(errors)[0] as string[])?.[0] : null) || err?.response?.data?.message || 'No se pudo cambiar la contraseña.');
    } finally {
      setBusy(false);
    }
  };

  const fields = [
    { id: 'gate-current', label: 'Contraseña con la que entraste', value: current, set: setCurrent, auto: 'current-password' },
    { id: 'gate-new', label: 'Tu contraseña nueva', value: password, set: setPassword, auto: 'new-password' },
    { id: 'gate-confirm', label: 'Repite la contraseña nueva', value: confirm, set: setConfirm, auto: 'new-password' },
  ];

  return (
    <div className="min-h-[70vh] flex items-center justify-center p-4" data-password-gate>
      <form onSubmit={submit} className="w-full max-w-md bg-white rounded-3xl border border-slate-200/80 shadow-xl p-6 sm:p-8 space-y-5">
        <div className="space-y-2">
          <span className="w-11 h-11 rounded-2xl bg-brand-soft/20 text-brand-ink flex items-center justify-center"><KeyRound size={20} /></span>
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">Crea tu contraseña</h1>
          <p className="text-sm text-slate-500">Entraste con una contraseña temporal. Antes de seguir, elige una que solo tú conozcas (al menos 8 caracteres).</p>
        </div>
        {error && <p role="alert" className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">{error}</p>}
        {fields.map((field) => (
          <div key={field.id} className="space-y-1.5">
            <label htmlFor={field.id} className="block text-xs font-bold text-brand-ink uppercase tracking-wider">{field.label}</label>
            <div className="relative">
              <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400"><Lock className="w-4 h-4" /></div>
              <input id={field.id} type={show ? 'text' : 'password'} required minLength={field.id === 'gate-current' ? undefined : 8} autoComplete={field.auto} value={field.value} onChange={(e) => field.set(e.target.value)} className={inputClass} />
              <button type="button" aria-label={show ? 'Ocultar contraseñas' : 'Mostrar contraseñas'} onClick={() => setShow(!show)} className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600">
                {show ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>
        ))}
        <button type="submit" disabled={busy} className="w-full bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-xl text-sm shadow-md transition-all disabled:opacity-70">
          {busy ? 'Guardando...' : 'Guardar y continuar'}
        </button>
        <button type="button" onClick={() => logout()} className="w-full text-xs font-semibold text-slate-500 hover:text-brand-ink">Salir</button>
      </form>
    </div>
  );
}
