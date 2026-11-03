'use client';

import { useEffect, useState } from 'react';
import { KeyRound, Mail, Send, ShieldCheck } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import CredentialsNote from '@/features/access/components/CredentialsNote';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';

export interface TenantMember {
  id: string;
  first_name: string;
  last_name: string;
  email: string;
  is_owner: boolean;
  role: string | null;
  security?: { mfa_mode: string | null; mfa_active: boolean; locked_minutes: number | null };
}

const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';
const sectionClass = 'rounded-2xl border border-slate-200 p-4 space-y-3';

export default function MemberAccessDrawer({ tenantId, member, onClose, onChanged }: { tenantId: string; member: TenantMember | null; onClose: () => void; onChanged: (detail: any) => void }) {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [credentials, setCredentials] = useState<{ email: string; password: string } | null>(null);
  const [confirmPassword, setConfirmPassword] = useState(false);

  useEffect(() => {
    setEmail(member?.email ?? '');
    setError('');
    setNotice('');
    setCredentials(null);
    setConfirmPassword(false);
  }, [member?.id, member?.email]);

  if (!member) return null;

  const name = `${member.first_name || ''} ${member.last_name || ''}`.trim() || member.email;

  const run = async (action: string, call: () => Promise<any>, ok: string) => {
    try {
      setBusy(action);
      setError('');
      setNotice('');
      const res = await call();
      if (res.data?.users) onChanged(res.data);
      setNotice(ok);
      return res.data;
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
      return null;
    } finally {
      setBusy('');
    }
  };

  const saveEmail = () => run('email', () => api.put(`/tenants/${tenantId}/users/${member.id}/email`, { email }), `Listo: ${name} ahora entra con ${email.trim().toLowerCase()}. Le avisamos al correo nuevo y al anterior.`);

  const givePassword = async () => {
    setConfirmPassword(false);
    const data = await run('password', () => api.post(`/tenants/${tenantId}/users/${member.id}/password`), 'Contraseña temporal creada.');
    if (data) setCredentials({ email: data.email, password: data.temporary_password });
  };

  const security = (action: 'unlock' | 'code_off' | 'code_default', ok: string) => run(action, () => api.post(`/tenants/${tenantId}/users/${member.id}/security`, { action }), ok);

  return (
    <FormDrawer isOpen onClose={onClose} title={`Acceso de ${name}`} subtitle={`${member.is_owner ? 'Dueño de la empresa' : member.role ?? 'Sin rol'} · lo que hagas queda en la auditoría`} icon={<ShieldCheck size={18} />}>
      <div className="space-y-4" data-member-access={member.email}>
        <SuccessAlert message={notice} />
        <ErrorAlert message={error} />

        <section className={sectionClass}>
          <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Mail size={15} /> Correo con el que entra</span>
          <p className="text-xs text-slate-500">Cámbialo si ya no puede usar el suyo. A ese correo le llegan los enlaces para recuperar la contraseña y los códigos al entrar.</p>
          <input type="email" aria-label="Correo nuevo" maxLength={190} value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} />
          <button type="button" data-member-email-save disabled={busy !== '' || !email.trim() || email.trim().toLowerCase() === member.email} onClick={saveEmail} className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-50">
            {busy === 'email' ? 'Guardando...' : 'Cambiar correo'}
          </button>
        </section>

        <section className={sectionClass}>
          <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Send size={15} /> Invitación por correo</span>
          <p className="text-xs text-slate-500">Le envía otra vez el correo de bienvenida para que cree su contraseña. El enlace vale 3 días.</p>
          <button type="button" data-member-invite disabled={busy !== ''} onClick={() => run('invite', () => api.post(`/tenants/${tenantId}/users/${member.id}/invite`), `Le enviamos la invitación a ${member.email}.`)} className="px-4 py-2 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
            {busy === 'invite' ? 'Enviando...' : 'Reenviar invitación'}
          </button>
        </section>

        <section className={sectionClass}>
          <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><KeyRound size={15} /> Contraseña temporal</span>
          <p className="text-xs text-slate-500">Para quien olvidó su contraseña y no puede recibir el enlace. Se muestra una sola vez; al entrar con ella, el sistema le pide crear la suya. Cierra sus sesiones abiertas.</p>
          {credentials ? (
            <CredentialsNote email={credentials.email} password={credentials.password} />
          ) : confirmPassword ? (
            <div className="flex flex-wrap items-center gap-2 text-xs">
              <span className="text-slate-600">¿Darle una contraseña temporal a {name}? La actual deja de valer.</span>
              <button type="button" data-member-password-confirm disabled={busy !== ''} onClick={givePassword} className="px-3 py-1.5 font-semibold text-white bg-brand rounded-lg hover:bg-brand-hover">Sí, darle una</button>
              <button type="button" onClick={() => setConfirmPassword(false)} className="px-3 py-1.5 font-semibold text-slate-600 hover:bg-slate-50 rounded-lg">No</button>
            </div>
          ) : (
            <button type="button" data-member-password disabled={busy !== ''} onClick={() => setConfirmPassword(true)} className="px-4 py-2 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
              Darle una contraseña temporal
            </button>
          )}
        </section>

        {member.security && (
          <section className={sectionClass}>
            <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><ShieldCheck size={15} /> Código al entrar y bloqueo</span>
            <p className="text-xs text-slate-600">
              {member.security.mfa_mode === 'off' ? 'Le quitaron el código: entra solo con su contraseña.' : member.security.mfa_active ? 'Recibe un código por correo al entrar.' : 'No se le pide código al entrar.'}
              {member.security.locked_minutes ? ` Está bloqueada ${member.security.locked_minutes} min por intentos fallidos.` : ''}
            </p>
            <div className="flex flex-wrap gap-2">
              {member.security.locked_minutes ? (
                <button type="button" data-member-unlock disabled={busy !== ''} onClick={() => security('unlock', 'Cuenta desbloqueada.')} className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100">Desbloquear</button>
              ) : null}
              {member.security.mfa_mode === 'off' ? (
                <button type="button" disabled={busy !== ''} onClick={() => security('code_default', 'Se le volverá a pedir el código según su rol.')} className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-50 text-amber-800 hover:bg-amber-100">Volver a pedirle el código</button>
              ) : member.security.mfa_active ? (
                <button type="button" data-member-code-off disabled={busy !== ''} onClick={() => security('code_off', 'Ya no se le pide el código al entrar.')} className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200">Quitarle el código</button>
              ) : null}
            </div>
          </section>
        )}
      </div>
    </FormDrawer>
  );
}
