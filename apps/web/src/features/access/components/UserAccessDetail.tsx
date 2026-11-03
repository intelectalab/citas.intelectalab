'use client';

import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { Clock, Contact, Eye, KeyRound, LayoutList, Loader2, Lock, Mail, MinusCircle, Pencil, Phone, PlusCircle, RotateCcw, ShieldCheck, Sparkles, Store } from 'lucide-react';
import { api } from '@/lib/axios';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { apiError, formatDate } from '@/features/appointments/appointments';
import FormUser from './FormUser';
import PermissionGrid from './PermissionGrid';
import CredentialsNote from './CredentialsNote';
import AccessSummary from './AccessSummary';
import AccessHistory from './AccessHistory';
import RecentAuditCard from '@/features/audit/components/RecentAuditCard';
import { useAuth } from '@/hooks/useAuth';
import { momentOf } from '@/features/sales/sales';
import { NO_BRANCH_NOTE, countOverride, entersModule, initialsOf, type AccessChange, type AdminOnlyScreen, type BranchOption, type GridAction, type GridModule, type RoleOption, type StaffUser } from '../access';

interface Detail {
  user: StaffUser;
  everything: boolean;
  locked: string | null;
  can_edit: boolean;
  grid: GridModule[];
  roles: RoleOption[];
  branches: BranchOption[];
  scope?: 'tenant' | 'branch' | 'own';
  scope_label?: string;
  admin_only?: AdminOnlyScreen[];
  history?: AccessChange[];
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-brand-ink';
const smallLabel = 'text-[11px] font-bold text-slate-400';

const FOCUS_TITLES: Record<string, string> = {
  enters: 'Las pantallas a las que entra',
  specials: 'Sus permisos especiales',
  granted: 'Lo que se le agregó a esta persona',
  revoked: 'Lo que se le quitó a esta persona',
};

export default function UserAccessDetail() {
  const params = useParams<{ id: string }>();
  const { can, user: me } = useAuth();
  const isAdmin = Boolean(me?.is_tenant_admin);
  const [detail, setDetail] = useState<Detail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState('');
  const [focus, setFocus] = useState('');
  const [editing, setEditing] = useState(false);
  const [confirm, setConfirm] = useState<'reset' | 'password' | null>(null);
  const [credentials, setCredentials] = useState<{ email: string; password: string } | null>(null);

  const load = useCallback(() => {
    api.get(`/access/users/${params.id}`)
      .then((res) => { setDetail(res.data); setError(''); })
      .catch((err) => setError(err?.response?.status === 404 ? 'Este usuario no existe.' : apiError(err, 'No se pudo cargar la información.')))
      .finally(() => setLoading(false));
  }, [params.id]);

  useEffect(() => { load(); }, [load]);

  const toggle = async (action: GridAction) => {
    if (!detail) return;

    try {
      setBusy(action.name);
      setError('');
      setNotice('');
      const res = await api.put(`/access/users/${detail.user.id}/permissions`, { permission: action.name, on: !action.on });
      setDetail(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar el permiso.'));
    } finally {
      setBusy('');
    }
  };

  const security = async (action: 'unlock' | 'require_code' | 'default_code') => {
    if (!detail) return;

    try {
      setBusy(action);
      setError('');
      setNotice('');
      const res = await api.post(`/access/users/${detail.user.id}/security`, { action });
      setDetail(res.data);
      setNotice(action === 'unlock'
        ? `${detail.user.name} ya puede volver a entrar.`
        : action === 'require_code'
          ? `Desde ahora ${detail.user.name} recibirá un código por correo cada vez que entre.`
          : `${detail.user.name} vuelve a lo de su rol: no se le pide código.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  const runConfirm = async () => {
    if (!detail || !confirm) return;

    try {
      setBusy(confirm);
      setError('');

      if (confirm === 'reset') {
        const res = await api.delete(`/access/users/${detail.user.id}/permissions`);
        setDetail(res.data);
        setFocus('');
        setNotice(`${detail.user.name} volvió a tener exactamente lo que trae su rol.`);
      } else {
        const res = await api.post(`/access/users/${detail.user.id}/password`);
        setCredentials({ email: res.data.email, password: res.data.temporary_password });
      }
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
      setConfirm(null);
    }
  };

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-brand-accent animate-spin" />
      </div>
    );
  }

  const backLink = (
    <BackLink href="/users">Usuarios y roles</BackLink>
  );

  if (!detail) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>
      </div>
    );
  }

  const { user, grid } = detail;
  const granted = countOverride(grid, true);
  const revoked = countOverride(grid, false);
  const canChange = detail.can_edit && !detail.locked;
  const inactive = user.status !== 'active';

  const matches = (module: GridModule) =>
    focus === 'enters' ? entersModule(module)
      : focus === 'specials' ? module.actions.some((a) => a.special && a.on)
        : focus === 'granted' ? module.actions.some((a) => a.override === true)
          : focus === 'revoked' ? module.actions.some((a) => a.override === false)
            : true;
  const shown = focus ? grid.filter(matches) : grid;
  const screenCount = 1 + grid.filter((m) => (m.screens ?? []).length > 0 && (detail.everything || entersModule(m))).reduce((sum, m) => sum + (m.screens ?? []).length, 0) + (detail.everything ? (detail.admin_only ?? []).length : 0);
  const specialCount = grid.reduce((sum, m) => sum + m.actions.filter((a) => a.special && a.on).length, 0);

  const facts: [React.ReactNode, string, React.ReactNode][] = [
    [<Mail key="i" size={14} />, 'Correo de acceso', user.email],
    [<Phone key="i" size={14} />, 'Celular', user.phone],
    [<Store key="i" size={14} />, 'Sede', user.branch?.name],
    [<Eye key="i" size={14} />, 'Qué ve', detail.scope === 'branch' && user.branch ? `${detail.scope_label}: ${user.branch.name}` : detail.scope_label],
    [<Clock key="i" size={14} />, 'Último ingreso', user.last_login_at ? momentOf(user.last_login_at) : 'Todavía no entra al sistema'],
  ];

  const stats = [
    { id: 'enters', title: 'Entra a', value: detail.everything ? 'Todo' : String(screenCount), note: detail.everything ? 'el menú completo' : screenCount === 1 ? 'pantalla del menú' : 'pantallas del menú', icon: <LayoutList size={18} />, color: 'text-emerald-600' },
    { id: 'specials', title: 'Permisos especiales', value: String(specialCount), note: 'encendidos', icon: <Sparkles size={18} />, color: 'text-amber-600' },
    { id: 'granted', title: 'Agregado a esta persona', value: String(granted), note: 'su rol no lo trae', icon: <PlusCircle size={18} />, color: 'text-emerald-600' },
    { id: 'revoked', title: 'Quitado a esta persona', value: String(revoked), note: 'su rol sí lo trae', icon: <MinusCircle size={18} />, color: 'text-rose-600' },
  ];

  return (
    <div className="space-y-5 pb-8 font-sans">
      {backLink}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`} data-card="user">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <span className="w-12 h-12 rounded-2xl bg-brand text-brand-soft flex items-center justify-center text-base font-black shrink-0">{initialsOf(user.name)}</span>
              <div className="min-w-0">
                <h1 className="text-xl font-black text-brand-ink tracking-tight truncate">{user.name}</h1>
                <p className="text-xs text-slate-500">
                  {user.role?.name ?? 'Sin rol'}{user.created_at ? ` · desde ${formatDate(user.created_at.slice(0, 10), { month: 'long', year: 'numeric' })}` : ''}
                </p>
              </div>
            </div>
            {detail.can_edit && (
              <button type="button" data-action="edit-user" title={`Editar a ${user.name}`} onClick={() => setEditing(true)} className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors shrink-0">
                <Pencil size={16} />
              </button>
            )}
          </div>

          <div className="flex flex-wrap gap-1.5">
            <Badge data-status={user.status} tone={inactive ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'} className="inline-flex">
              {inactive ? 'Desactivado' : 'Activo'}
            </Badge>
            {user.is_owner && <span className="inline-flex px-2.5 py-1 text-xs rounded-full font-medium bg-brand-soft/25 text-brand-ink">Dueño de la empresa</span>}
            {user.is_self && <span className="inline-flex px-2.5 py-1 text-xs rounded-full font-medium bg-slate-100 text-slate-600">Tu cuenta</span>}
          </div>

          <dl className="space-y-3">
            {facts.filter(([, , value]) => value).map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="mt-0.5 text-slate-400 shrink-0">{icon}</span>
                <div className="min-w-0">
                  <dt className={smallLabel}>{label}</dt>
                  <dd className="text-sm font-semibold text-slate-800 break-words">{value}</dd>
                </div>
              </div>
            ))}
          </dl>

          {detail.scope === 'branch' && !user.branch && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2.5" data-no-branch>{NO_BRANCH_NOTE}</p>}

          {inactive && <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5">Con la cuenta desactivada no entra al sistema, aunque tenga permisos encendidos.</p>}

          <div className="pt-4 border-t border-slate-100 space-y-2">
            {user.role && (
              <Link href={`/users/roles/${user.role.id}`} data-action="open-role" className="flex items-center gap-2 text-sm font-bold text-brand-accent hover:text-brand-ink transition-colors">
                <ShieldCheck size={15} /> Ver el rol {user.role.name}
              </Link>
            )}
            {user.professional_id && (
              <Link href={`/professionals/${user.professional_id}`} className="flex items-center gap-2 text-sm font-bold text-brand-accent hover:text-brand-ink transition-colors">
                <Contact size={15} /> Atiende citas: ver su horario y sus citas
              </Link>
            )}
            {user.security && (
              <div className="rounded-xl border border-slate-200 p-3 space-y-2" data-user-security>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-xs font-bold text-slate-700">Código por correo al entrar</span>
                  <Badge tone={user.security.mfa_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}>
                    {user.security.mfa_mode === 'off' ? 'Quitado por la plataforma' : user.security.mfa_active ? (user.security.mfa_mode === 'required' ? 'Siempre' : 'Por ser administrador') : 'No'}
                  </Badge>
                </div>
                {isAdmin && detail.can_edit && user.security.mfa_mode !== 'off' && !(user.security.mfa_active && user.security.mfa_mode !== 'required') && (
                  <button type="button" data-action="toggle-code" disabled={busy !== ''} onClick={() => security(user.security?.mfa_mode === 'required' ? 'default_code' : 'require_code')} className="text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors disabled:opacity-50">
                    {user.security.mfa_mode === 'required' ? 'Dejar de pedirle el código' : 'Pedirle siempre el código'}
                  </button>
                )}
                {user.security.locked_minutes && (
                  <div className="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                    <span className="text-xs font-semibold text-rose-700" data-user-locked>Bloqueada {user.security.locked_minutes} min por intentos fallidos</span>
                    {detail.can_edit && (
                      <button type="button" data-action="unlock" disabled={busy !== ''} onClick={() => security('unlock')} className="text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors disabled:opacity-50">Desbloquear</button>
                    )}
                  </div>
                )}
              </div>
            )}
            {detail.can_edit && !user.is_owner && !user.is_self && (
              <button type="button" data-action="new-password" onClick={() => { setError(''); setNotice(''); setConfirm('password'); }} className="flex items-center gap-2 text-sm font-bold text-brand-accent hover:text-brand-ink transition-colors">
                <KeyRound size={15} /> Darle una contraseña nueva
              </button>
            )}
          </div>
        </section>

        <div className="xl:col-span-2 space-y-5">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {stats.map((stat) => (
              <button
                key={stat.id}
                type="button"
                data-stat={stat.id}
                aria-pressed={focus === stat.id}
                title="Ver cuáles son"
                onClick={() => setFocus((current) => (current === stat.id ? '' : stat.id))}
                className={`bg-white px-4 py-3 rounded-2xl border text-left shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] transition-all duration-300 cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_25px_-5px_rgba(0,0,0,0.08)] ${
                  focus === stat.id ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'border-slate-100 hover:border-slate-200'
                }`}
              >
                <span className="flex items-start justify-between gap-2">
                  <span className="block min-w-0">
                    <span className="block text-[11px] font-semibold text-slate-400">{stat.title}</span>
                    <span className="block mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900" data-stat-value>{stat.value}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{stat.note}</span>
                  </span>
                  <span className={`p-2 rounded-xl bg-slate-50 border border-slate-100 shrink-0 ${stat.color}`}>{stat.icon}</span>
                </span>
              </button>
            ))}
          </div>

          <AccessSummary grid={grid} adminOnly={detail.admin_only} everything={detail.everything} who={user.first_name} />
        </div>

        <section className={`${card} xl:col-span-3 overflow-hidden`} data-card="permissions">
          <div className="px-6 pt-5 pb-4 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className={cardTitle}><ShieldCheck className="w-4 h-4 text-brand-accent" /> {focus ? FOCUS_TITLES[focus] : 'Qué puede hacer, pantalla por pantalla'}</h2>
              <p className="text-xs text-slate-500 font-medium mt-1 max-w-3xl">
                {detail.locked
                  ? detail.locked
                  : `Cada interruptor vale solo para ${user.first_name}: enciende algo que su rol no trae o apaga algo que sí. Quien comparte su rol no cambia. Toca el nombre de una pantalla para leer qué permite cada uno.`}
              </p>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              {focus && (
                <button type="button" data-action="clear-focus" onClick={() => setFocus('')} className="text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors">
                  Ver todas las pantallas
                </button>
              )}
              {canChange && granted + revoked > 0 && (
                <button type="button" data-action="reset-permissions" onClick={() => { setError(''); setNotice(''); setConfirm('reset'); }} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink hover:bg-slate-100 rounded-lg transition-colors">
                  <RotateCcw size={13} /> Volver a lo de su rol
                </button>
              )}
              {detail.locked && <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold bg-slate-100 text-slate-600 rounded-lg"><Lock size={12} /> No se cambia aquí</span>}
            </div>
          </div>

          <PermissionGrid
            key={focus}
            grid={shown}
            mode="user"
            canEdit={canChange}
            busy={busy}
            onToggle={toggle}
            empty={focus === 'granted' ? 'No se le agregó nada: tiene lo que trae su rol.' : focus === 'revoked' ? 'No se le quitó nada: tiene lo que trae su rol.' : focus === 'specials' ? 'No tiene permisos especiales encendidos.' : 'Nada que mostrar.'}
          />
        </section>

        <div className="xl:col-span-3">
          <AccessHistory history={detail.history ?? []} empty={`Todavía no hay cambios registrados para ${user.first_name}.`} />
        </div>

        {can('audit.view') && (
          <RecentAuditCard
            endpoint="/audit"
            params={{ actor_id: String(user.id) }}
            title={`Últimas acciones de ${user.first_name}`}
            allHref={`/audit?actor_id=${user.id}`}
            empty={`${user.first_name} todavía no tiene acciones registradas.`}
            className={`${card} p-6 xl:col-span-3`}
            showActor={false}
            refreshKey={detail}
          />
        )}
      </div>

      <FormUser
        isOpen={editing}
        onClose={() => setEditing(false)}
        initialData={user}
        roles={detail.roles}
        branches={detail.branches}
        onSaved={() => { setNotice('Los cambios se guardaron.'); load(); }}
        onRolesChanged={load}
      />

      <AppModal
        isOpen={confirm !== null}
        type="confirm"
        title={confirm === 'reset' ? `¿Volver a ${user.first_name} a lo de su rol?` : `¿Darle una contraseña nueva a ${user.name}?`}
        message={confirm === 'reset'
          ? `Se borra lo que se le agregó y lo que se le quitó. Quedará con lo mismo que cualquier persona con el rol ${user.role?.name ?? ''}.`
          : 'Su contraseña actual deja de servir y se cierra su sesión. Verás la contraseña nueva una sola vez, para entregársela.'}
        confirmText={confirm === 'reset' ? 'Sí, volver a su rol' : 'Sí, cambiarla'}
        cancelText="Cancelar"
        isLoading={busy === confirm}
        onConfirm={runConfirm}
        onClose={() => setConfirm(null)}
      />

      <AppModal
        isOpen={credentials !== null}
        type="alert"
        title={`Contraseña nueva para ${user.name}`}
        message={credentials ? <CredentialsNote email={credentials.email} password={credentials.password} /> : ''}
        confirmText="Entendido"
        maxWidth="max-w-xl"
        onConfirm={() => setCredentials(null)}
        onClose={() => setCredentials(null)}
      />
    </div>
  );
}
