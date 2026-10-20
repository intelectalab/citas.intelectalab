'use client';

import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Ban, Check, Loader2, Lock, Pencil, RotateCcw, ShieldCheck, Trash2, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { PagedList } from '@/app/components/common/Pager';
import { UserLink } from '@/app/components/common/PersonLink';
import Select from '@/app/components/common/Select';
import { apiError } from '@/features/appointments/appointments';
import FormRole from './FormRole';
import PermissionGrid from './PermissionGrid';
import { SCOPE_OPTIONS, countAll, countOn, initialsOf, type Scope, type GridAction, type GridModule, type RoleRow } from '../access';

interface RolePerson {
  id: string;
  name: string;
  email: string;
  status: string;
  is_owner: boolean;
}

interface Detail {
  role: RoleRow;
  can_edit: boolean;
  can_delete: boolean;
  can_restore: boolean;
  grid: GridModule[];
  users: RolePerson[];
  scope_ready?: boolean;
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340]';

export default function RoleDetail() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const [detail, setDetail] = useState<Detail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState('');
  const [needsBranch, setNeedsBranch] = useState<{ message: string; people: { id: string; name: string; email: string }[]; branches: { id: string; name: string }[] } | null>(null);
  const [assign, setAssign] = useState<Record<string, string>>({});
  const [focus, setFocus] = useState('');
  const [editing, setEditing] = useState(false);
  const [confirm, setConfirm] = useState<'restore' | 'delete' | null>(null);

  const load = useCallback(() => {
    api.get(`/access/roles/${params.id}`)
      .then((res) => { setDetail(res.data); setError(''); })
      .catch((err) => setError(err?.response?.status === 404 ? 'Este rol no existe.' : apiError(err, 'No se pudo cargar la información.')))
      .finally(() => setLoading(false));
  }, [params.id]);

  useEffect(() => { load(); }, [load]);

  const toggle = async (action: GridAction) => {
    if (!detail) return;

    try {
      setBusy(action.name);
      setError('');
      setNotice('');
      const res = await api.put(`/access/roles/${detail.role.id}/permissions`, { permission: action.name, on: !action.on });
      setDetail(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar el permiso.'));
    } finally {
      setBusy('');
    }
  };

  const changeScope = async (scope: Scope, branches?: Record<string, string>) => {
    if (!detail || (detail.role.scope === scope && !branches)) return;

    try {
      setBusy('scope');
      setError('');
      setNotice('');
      const res = await api.put(`/access/roles/${detail.role.id}/scope`, { scope, ...(branches ? { branches } : {}) });
      setDetail(res.data);
      setNeedsBranch(null);
      setAssign({});
      const option = SCOPE_OPTIONS.find((o) => o.id === scope);
      setNotice(res.data.assigned > 0
        ? `Listo: quien tiene este rol ahora ve ${option?.title.toLowerCase()}. Se le asignó su sede a ${res.data.assigned === 1 ? 'una persona' : `${res.data.assigned} personas`}.`
        : `Listo: quien tiene este rol ahora ve ${option?.title.toLowerCase()}.`);
    } catch (err: any) {
      if (err?.response?.data?.code === 'NEEDS_BRANCH') {
        setNeedsBranch(err.response.data);
        const only = err.response.data.branches.length === 1 ? err.response.data.branches[0].id : '';
        setAssign(Object.fromEntries(err.response.data.people.map((p: { id: string }) => [p.id, only])));
      } else {
        setError(apiError(err, 'No se pudo cambiar.'));
      }
    } finally {
      setBusy('');
    }
  };

  const runConfirm = async () => {
    if (!detail || !confirm) return;

    try {
      setBusy(confirm);
      setError('');

      if (confirm === 'restore') {
        const res = await api.post(`/access/roles/${detail.role.id}/restore`);
        setDetail(res.data);
        setNotice(`${detail.role.name} volvió a los permisos con que nació.`);
      } else {
        await api.delete(`/access/roles/${detail.role.id}`);
        router.push('/users');
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
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
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

  const { role, grid, users } = detail;
  const total = countAll(grid);
  const on = countOn(grid);
  const shown = focus ? grid.filter((module) => module.actions.some((a) => (focus === 'on' ? a.on : !a.on))) : grid;

  const enters = (module: GridModule) => {
    const view = module.actions.find((a) => a.action === 'view');
    return view ? view.on : module.actions.some((a) => a.on);
  };

  const stats = [
    { id: 'on', title: 'Permite', value: role.locked ? 'Todo' : `${on} de ${total}`, note: 'acciones encendidas', icon: <Check size={18} />, color: 'text-emerald-600' },
    { id: 'off', title: 'No permite', value: String(total - on), note: 'acciones apagadas', icon: <Ban size={18} />, color: 'text-slate-500' },
    { id: 'people', title: 'Personas con este rol', value: String(users.length), note: users.length === 1 ? 'persona' : 'personas', icon: <Users size={18} />, color: 'text-[#3F768C]' },
  ];

  const pickStat = (id: string) => {
    if (id === 'people') {
      document.querySelector('[data-card="people"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    setFocus((current) => (current === id ? '' : id));
  };

  return (
    <div className="space-y-5 pb-8 font-sans">
      {backLink}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} overflow-hidden`} data-card="role">
          <div className="p-6 space-y-4">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-3 min-w-0">
                <span className="w-12 h-12 rounded-2xl bg-[#022340] text-[#84D0D9] flex items-center justify-center shrink-0"><ShieldCheck size={22} /></span>
                <div className="min-w-0">
                  <h1 className="text-xl font-black text-[#022340] tracking-tight truncate">{role.name}</h1>
                  <p className="text-xs text-slate-500">{role.is_system ? 'Rol de fábrica' : 'Rol propio de tu empresa'}</p>
                </div>
              </div>
              {detail.can_edit && (
                <button type="button" data-action="edit-role" title="Cambiar el nombre" onClick={() => setEditing(true)} className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors shrink-0">
                  <Pencil size={16} />
                </button>
              )}
            </div>

            {role.description && <p className="text-sm text-slate-600">{role.description}</p>}

            <div className="space-y-2 pt-1" data-card="scope">
              <span className="block text-[11px] font-bold text-slate-400">Qué ve quien tiene este rol</span>
              <div className="grid gap-2" role="radiogroup" aria-label="Qué ve">
                {SCOPE_OPTIONS
                  .filter((option) => (role.slug === 'professional' ? option.id === 'own' : role.locked ? option.id === 'tenant' : option.id !== 'own'))
                  .map((option) => {
                    const chosen = role.scope === option.id;
                    const editable = detail.can_edit && detail.scope_ready !== false && role.slug !== 'professional';

                    return (
                      <button
                        key={option.id}
                        type="button"
                        role="radio"
                        aria-checked={chosen}
                        data-scope={option.id}
                        disabled={!editable || busy === 'scope'}
                        onClick={() => { setNeedsBranch(null); changeScope(option.id); }}
                        className={`text-left rounded-xl border px-3.5 py-2.5 transition-colors disabled:cursor-default ${chosen ? 'border-[#022340] bg-[#84D0D9]/15 ring-2 ring-[#022340]/10' : 'border-slate-200 enabled:hover:bg-slate-50'}`}
                      >
                        <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                        <span className="block text-xs text-slate-500 mt-0.5">{option.hint}</span>
                      </button>
                    );
                  })}
              </div>

              {needsBranch && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-3.5 space-y-3" data-needs-branch>
                  <p className="text-xs text-amber-900 font-medium">{needsBranch.message}</p>
                  <ul className="space-y-2">
                    {needsBranch.people.map((person) => (
                      <li key={person.id} className="space-y-1">
                        <span className="block text-xs font-semibold text-slate-700">{person.name}</span>
                        <Select
                          ariaLabel={`Sede de ${person.name}`}
                          placeholder="Elige su sede"
                          value={assign[person.id] ?? ''}
                          onChange={(e) => setAssign((current) => ({ ...current, [person.id]: String(e.target.value) }))}
                          options={needsBranch.branches.map((branch) => ({ label: branch.name, value: branch.id }))}
                        />
                      </li>
                    ))}
                  </ul>
                  <div className="flex items-center justify-end gap-2">
                    <button type="button" onClick={() => setNeedsBranch(null)} className="px-3 py-1.5 text-xs font-semibold text-slate-600 border border-slate-200 bg-white rounded-lg hover:bg-slate-50 transition-colors">
                      Cancelar
                    </button>
                    <button
                      type="button"
                      data-action="assign-and-apply"
                      disabled={busy === 'scope' || needsBranch.people.some((p) => !assign[p.id])}
                      onClick={() => changeScope('branch', assign)}
                      className="px-3 py-1.5 text-xs font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50"
                    >
                      Asignar y aplicar
                    </button>
                  </div>
                </div>
              )}
            </div>

            {(detail.can_restore || detail.can_delete) && (
              <div className="flex flex-wrap gap-2 pt-1">
                {detail.can_restore && (
                  <button type="button" data-action="restore-role" onClick={() => { setError(''); setNotice(''); setConfirm('restore'); }} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-[#3F768C] border border-slate-200 hover:bg-slate-50 rounded-lg transition-colors">
                    <RotateCcw size={13} /> Volver a como nació
                  </button>
                )}
                {detail.can_delete && (
                  <button type="button" data-action="delete-role" onClick={() => { setError(''); setNotice(''); setConfirm('delete'); }} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-red-600 border border-red-100 hover:bg-red-50 rounded-lg transition-colors">
                    <Trash2 size={13} /> Eliminar rol
                  </button>
                )}
              </div>
            )}
          </div>

          <div className="border-t border-slate-100 flex flex-col scroll-mt-4" data-card="people">
            <span className="block px-5 pt-3 text-[11px] font-bold text-slate-400">Quiénes lo tienen ({users.length})</span>
            <PagedList
              items={users}
              empty="Nadie tiene este rol todavía."
              renderRow={(person) => (
                <li key={person.id} className="flex items-center gap-3 px-5 py-2.5">
                  <span className="w-8 h-8 rounded-full bg-[#022340] text-[#84D0D9] flex items-center justify-center text-xs font-bold shrink-0">{initialsOf(person.name)}</span>
                  <div className="min-w-0 flex-1">
                    <UserLink id={person.id} className="block text-sm font-semibold text-slate-800 truncate">{person.name}</UserLink>
                    <span className="block text-xs text-slate-400 truncate">{person.email}</span>
                  </div>
                  {person.is_owner && <span className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-[#84D0D9]/25 text-[#022340]">Dueño</span>}
                  {person.status !== 'active' && <span className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-rose-50 text-rose-600">Desactivado</span>}
                </li>
              )}
            />
          </div>
        </section>

        <div className="xl:col-span-2 space-y-5">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {stats.map((stat) => (
              <button
                key={stat.id}
                type="button"
                data-stat={stat.id}
                aria-pressed={stat.id === 'people' ? undefined : focus === stat.id}
                title={stat.id === 'people' ? 'Ver quiénes son' : 'Ver cuáles son'}
                onClick={() => pickStat(stat.id)}
                className={`bg-white px-4 py-3 rounded-2xl border text-left shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] transition-all duration-300 cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_25px_-5px_rgba(0,0,0,0.08)] ${
                  focus === stat.id ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'border-slate-100 hover:border-slate-200'
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

          <section className={`${card} p-6 space-y-4`} data-card="enters">
            <div>
              <h2 className={cardTitle}><Check className="w-4 h-4 text-emerald-600" /> A qué pantallas entra</h2>
              <div className="flex flex-wrap gap-1.5 mt-3" data-list="enters">
                {grid.filter(enters).length === 0 && <span className="text-sm text-slate-400">A ninguna todavía: enciende abajo lo que deba poder hacer.</span>}
                {grid.filter(enters).map((module) => (
                  <span key={module.key} className="px-2.5 py-1 text-xs font-semibold bg-[#84D0D9]/20 text-[#022340] rounded-lg">{module.label}</span>
                ))}
              </div>
            </div>
            {grid.some((module) => !enters(module)) && (
              <div className="pt-4 border-t border-slate-100">
                <h2 className={cardTitle}><Ban className="w-4 h-4 text-slate-400" /> A cuáles no</h2>
                <div className="flex flex-wrap gap-1.5 mt-3" data-list="not-enters">
                  {grid.filter((module) => !enters(module)).map((module) => (
                    <span key={module.key} className="px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-500 rounded-lg">{module.label}</span>
                  ))}
                </div>
              </div>
            )}
          </section>
        </div>

        <section className={`${card} xl:col-span-3 overflow-hidden`} data-card="permissions">
          <div className="px-6 pt-5 pb-4 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className={cardTitle}><ShieldCheck className="w-4 h-4 text-[#3F768C]" /> {focus === 'on' ? 'Lo que permite' : focus === 'off' ? 'Lo que no permite' : 'Qué permite, pantalla por pantalla'}</h2>
              <p className="text-xs text-slate-500 font-medium mt-1 max-w-3xl">
                {role.locked
                  ? 'El Administrador puede todo y no se le quitan permisos: así la empresa nunca se queda sin alguien que pueda arreglar las cosas.'
                  : `Cada interruptor vale para todas las personas con este rol, al instante. Para cambiarle algo a una sola persona, entra a su página. Toca el nombre de una pantalla para leer qué permite cada uno.`}
              </p>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              {focus && (
                <button type="button" data-action="clear-focus" onClick={() => setFocus('')} className="text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
                  Ver todas las pantallas
                </button>
              )}
              {role.locked && <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold bg-slate-100 text-slate-600 rounded-lg"><Lock size={12} /> No se recorta</span>}
            </div>
          </div>

          <PermissionGrid key={focus} grid={shown} mode="role" canEdit={detail.can_edit} busy={busy} onToggle={toggle} />
        </section>
      </div>

      <FormRole
        isOpen={editing}
        onClose={() => setEditing(false)}
        initialData={role}
        roles={[]}
        onSaved={() => { setNotice('Los cambios se guardaron.'); load(); }}
      />

      <AppModal
        isOpen={confirm !== null}
        type="confirm"
        variant={confirm === 'delete' ? 'danger' : 'default'}
        title={confirm === 'restore' ? `¿Volver ${role.name} a como nació?` : `¿Eliminar el rol ${role.name}?`}
        message={confirm === 'restore'
          ? 'Se pierden los cambios que hiciste a este rol y queda con sus permisos de fábrica. Lo agregado o quitado a cada persona no se toca.'
          : 'Solo se elimina si nadie lo tiene. Si alguien lo usa, primero cámbiale el rol.'}
        confirmText={confirm === 'restore' ? 'Sí, restablecer' : 'Sí, eliminar'}
        cancelText="Cancelar"
        isLoading={busy === confirm}
        onConfirm={runConfirm}
        onClose={() => setConfirm(null)}
      />
    </div>
  );
}
