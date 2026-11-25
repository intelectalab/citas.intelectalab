'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { Eye, EyeOff, KeyRound, Laptop, Lock, ShieldCheck, Trash2, UserRound } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { apiError } from '@/features/appointments/appointments';

type Device = { id: string; label: string; ip: string | null; created_at: string; expires_at: string; current: boolean };

const inputClass = 'w-full pl-10 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all';
const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

const day = (value: string) => new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });

const readDevice = (email?: string) => {
  if (!email) return null;
  try {
    return localStorage.getItem(`trusted_device:${email.trim().toLowerCase()}`);
  } catch {
    return null;
  }
};

const RULES = [
  { label: 'Al menos 8 caracteres', test: (value: string) => value.length >= 8 },
  { label: 'Una mayúscula', test: (value: string) => /[A-Z]/.test(value) },
  { label: 'Una minúscula', test: (value: string) => /[a-z]/.test(value) },
  { label: 'Un número', test: (value: string) => /[0-9]/.test(value) },
];

const strong = (value: string) => RULES.every((rule) => rule.test(value));

type Profile = { name: string; email: string; phone: string | null; company: string | null; role: string; scope: string | null; branch: string | null; code_required: boolean; last_login_at: string | null };

function ProfileCard() {
  const [profile, setProfile] = useState<Profile | null>(null);

  useEffect(() => {
    api.get<Profile>('/account/profile').then((res) => setProfile(res.data)).catch(() => setProfile(null));
  }, []);

  if (!profile) return null;

  const rows: [string, string | null][] = [
    ['Nombre', profile.name],
    ['Correo', profile.email],
    ['Celular', profile.phone],
    ['Empresa', profile.company],
    ['Rol', profile.role],
    ['Qué ve', profile.scope],
    ['Sede', profile.branch ?? (profile.scope === 'Solo su sede' ? 'Sin sede asignada' : 'Todas')],
    ['Código al entrar', profile.code_required ? 'Sí: te pedimos un código por correo' : 'No'],
    ['Último acceso', profile.last_login_at ? new Date(profile.last_login_at).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : null],
  ];

  return (
    <section className={`${cardClass} p-6 space-y-3 lg:col-span-2`} data-card="profile">
      <h2 className="flex items-center gap-2 text-base font-bold text-brand-ink"><UserRound size={18} className="text-brand-accent" /> Mis datos</h2>
      <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3">
        {rows.filter(([, value]) => value !== null && value !== '').map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">{label}</dt>
            <dd className="text-sm font-semibold text-slate-800 break-words">{value}</dd>
          </div>
        ))}
      </dl>
      <p className="text-xs text-slate-500">Tu nombre, rol y sede los cambia quien administra Usuarios y roles en tu empresa.</p>
    </section>
  );
}

function PasswordCard({ onChanged }: { onChanged: () => void }) {
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setNotice('');
    if (password !== confirm) {
      setError('Las dos contraseñas nuevas no coinciden.');
      return;
    }
    if (!strong(password)) {
      setError('La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.');
      return;
    }
    try {
      setBusy(true);
      setError('');
      await api.put('/account/password', { current_password: current, password, password_confirmation: confirm });
      setCurrent('');
      setPassword('');
      setConfirm('');
      setNotice('Listo: tu contraseña cambió. Se cerraron tus sesiones en otros equipos y todos volverán a pedir el código si tu empresa lo usa.');
      onChanged();
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar la contraseña.'));
    } finally {
      setBusy(false);
    }
  };

  const fields = [
    { id: 'account-current', label: 'Tu contraseña actual', value: current, set: setCurrent, auto: 'current-password', min: undefined },
    { id: 'account-new', label: 'Contraseña nueva', value: password, set: setPassword, auto: 'new-password', min: 8 },
    { id: 'account-confirm', label: 'Repite la contraseña nueva', value: confirm, set: setConfirm, auto: 'new-password', min: 8 },
  ];

  return (
    <form onSubmit={submit} className={`${cardClass} p-6 space-y-4`} data-card="password">
      <div>
        <h2 className="flex items-center gap-2 text-base font-bold text-brand-ink"><KeyRound size={18} className="text-brand-accent" /> Cambiar contraseña</h2>
        <p className="text-sm text-slate-500 mt-1">Al menos 8 caracteres, con una mayúscula, una minúscula y un número. Al cambiarla se cierran tus sesiones en otros equipos; en este sigues dentro.</p>
      </div>
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />
      {fields.map((field) => (
        <div key={field.id} className="space-y-1.5">
          <label htmlFor={field.id} className="block text-xs font-bold text-brand-ink uppercase tracking-wider">{field.label}</label>
          <div className="relative">
            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400"><Lock className="w-4 h-4" /></div>
            <input id={field.id} type={show ? 'text' : 'password'} required minLength={field.min} autoComplete={field.auto} value={field.value} onChange={(e) => field.set(e.target.value)} className={inputClass} />
            <button type="button" aria-label={show ? 'Ocultar contraseñas' : 'Mostrar contraseñas'} onClick={() => setShow(!show)} className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600">
              {show ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
          </div>
        </div>
      ))}
      {password && (
        <ul className="grid grid-cols-2 gap-1 text-xs" data-password-rules>
          {RULES.map((rule) => (
            <li key={rule.label} className={rule.test(password) ? 'text-emerald-700 font-semibold' : 'text-slate-500'}>{rule.test(password) ? '✓' : '·'} {rule.label}</li>
          ))}
        </ul>
      )}
      <div className="flex justify-end">
        <button type="submit" disabled={busy} className="px-5 py-2.5 text-sm font-bold text-white bg-brand hover:bg-brand-hover rounded-xl transition-colors disabled:opacity-70">
          {busy ? 'Guardando…' : 'Cambiar contraseña'}
        </button>
      </div>
    </form>
  );
}

function DevicesCard({ email, reload }: { email?: string; reload: number }) {
  const [devices, setDevices] = useState<Device[]>([]);
  const [ready, setReady] = useState(true);
  const [days, setDays] = useState(30);
  const [loaded, setLoaded] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const lastRequest = useRef(0);

  const load = useCallback(async () => {
    if (!email) return;
    const request = ++lastRequest.current;
    try {
      const device = readDevice(email);
      const res = await api.get('/account/devices', { headers: device ? { 'X-Trusted-Device': device } : {} });
      if (request !== lastRequest.current) return;
      setDevices(res.data.devices ?? []);
      setReady(res.data.ready !== false);
      setDays(res.data.trust_days ?? 30);
      setError('');
    } catch (err) {
      if (request !== lastRequest.current) return;
      setError(apiError(err, 'No se pudo cargar la lista de equipos.'));
    } finally {
      if (request === lastRequest.current) setLoaded(true);
    }
  }, [email]);

  useEffect(() => { load(); }, [load, reload]);

  const remove = async (device: Device) => {
    try {
      setBusyId(device.id);
      setNotice('');
      const res = await api.delete(`/account/devices/${device.id}`);
      if (device.current && email) {
        try { localStorage.removeItem(`trusted_device:${email.trim().toLowerCase()}`); } catch {}
      }
      setNotice(res.data.message);
      await load();
    } catch (err) {
      setError(apiError(err, 'No se pudo quitar el equipo.'));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <section className={`${cardClass} overflow-hidden`} data-card="devices">
      <div className="px-6 pt-6 pb-4">
        <h2 className="flex items-center gap-2 text-base font-bold text-brand-ink"><ShieldCheck size={18} className="text-brand-accent" /> Equipos de confianza</h2>
        <p className="text-sm text-slate-500 mt-1">
          Si al entrar te piden un código por correo y marcas &quot;Confiar en este equipo&quot;, ese equipo no lo vuelve a pedir durante {days} días. Quita los que ya no uses o no reconozcas.
        </p>
      </div>
      <div className="px-6 space-y-2">
        <ErrorAlert message={error} />
        <SuccessAlert message={notice} />
      </div>
      {!ready ? (
        <p className="px-6 py-8 text-sm text-amber-700">Falta actualizar la base de datos para ver esta lista (migración de seguridad de ingreso).</p>
      ) : !loaded ? (
        <p className="px-6 py-8 text-sm text-slate-500">Cargando…</p>
      ) : devices.length === 0 ? (
        <p className="px-6 py-8 text-sm text-slate-500">No tienes equipos de confianza. Si tu empresa no pide código al entrar, esta lista siempre estará vacía.</p>
      ) : (
        <ul className="divide-y divide-slate-100 border-t border-slate-100 mt-2">
          {devices.map((device) => (
            <li key={device.id} data-device={device.id} className="flex flex-wrap items-center gap-3 px-6 py-3.5">
              <span className="w-9 h-9 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"><Laptop size={17} /></span>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-semibold text-slate-800">
                  {device.label}
                  {device.current && <span className="ml-2 px-2 py-0.5 text-[11px] font-bold rounded-md bg-brand-soft/30 text-brand-ink">Este equipo</span>}
                </p>
                <p className="text-xs text-slate-500">Desde el {day(device.created_at)} · vence el {day(device.expires_at)}{device.ip ? ` · ${device.ip}` : ''}</p>
              </div>
              <button type="button" data-action="remove-device" disabled={busyId === device.id} onClick={() => remove(device)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50 rounded-lg transition-colors disabled:opacity-60">
                <Trash2 size={14} /> {busyId === device.id ? 'Quitando…' : 'Quitar'}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export default function AccountPage() {
  const { user } = useAuth();
  const [reload, setReload] = useState(0);
  const name = `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim();

  if (user?.impersonator) {
    return (
      <div className="space-y-5 pb-8 font-sans">
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><UserRound className="w-6 h-6 text-brand-accent" /> Mi cuenta</h1>
        <p className={`${cardClass} p-6 text-sm text-slate-600`}>Estás en una sesión de soporte: la contraseña y los equipos de esta persona solo los cambia ella. Para darle una contraseña temporal, usa el panel Acceso de la consola.</p>
      </div>
    );
  }

  return (
    <div className="space-y-5 pb-8 font-sans">
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><UserRound className="w-6 h-6 text-brand-accent" /> Mi cuenta</h1>
        <p className="text-sm text-slate-500 mt-1">{name || 'Tu cuenta'} · {user?.email}</p>
      </div>
      <div className="grid gap-5 lg:grid-cols-2 items-start">
        <ProfileCard />
        <PasswordCard onChanged={() => setReload((n) => n + 1)} />
        <DevicesCard email={user?.email} reload={reload} />
      </div>
    </div>
  );
}
