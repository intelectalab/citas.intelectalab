'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AlertTriangle, Eye, KeyRound, Pencil, ShieldCheck, SlidersHorizontal, Trash2, UserCheck, UserX, Users } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { ProfessionalLink, UserLink } from '@/app/components/common/PersonLink';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import FormUser, { type NewCredentials } from './FormUser';
import FormRole from './FormRole';
import CredentialsNote from './CredentialsNote';
import { initialsOf, scopeTitle, type BranchOption, type PlanUsage, type RoleOption, type RoleRow, type StaffUser } from '../access';

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function UsersRolesBoard() {
  const router = useRouter();
  const [tab, setTab] = useState<'users' | 'roles'>('users');
  const [users, setUsers] = useState<StaffUser[] | null>(null);
  const [roles, setRoles] = useState<RoleRow[]>([]);
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([]);
  const [branches, setBranches] = useState<BranchOption[]>([]);
  const [usage, setUsage] = useState<PlanUsage | null>(null);
  const [blocked, setBlocked] = useState('');
  const [myBranch, setMyBranch] = useState<BranchOption | null>(null);
  const [can, setCan] = useState({ create: false, edit: false, delete: false });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ role: '', status: '', changed: '', nobranch: '' });
  const [roleSearch, setRoleSearch] = useState('');
  const [roleFilters, setRoleFilters] = useState<Record<string, string>>({ kind: '' });

  const [userFormOpen, setUserFormOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<StaffUser | null>(null);
  const [roleFormOpen, setRoleFormOpen] = useState(false);
  const [credentials, setCredentials] = useState<(NewCredentials & { title: string }) | null>(null);
  const [resetting, setResetting] = useState<StaffUser | null>(null);
  const [deletingRole, setDeletingRole] = useState<RoleRow | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api.get('/access/users')
      .then((res) => {
        setUsers(res.data.users);
        setRoleOptions(res.data.roles);
        setBranches(res.data.branches);
        setUsage(res.data.usage);
        setBlocked(res.data.blocked ?? '');
        setMyBranch(res.data.my_branch ?? null);
        setCan((current) => ({ ...current, create: res.data.can.create, edit: res.data.can.edit }));
        setError('');
      })
      .catch((err) => { setUsers([]); setError(apiError(err, 'No se pudieron cargar los usuarios.')); });

    api.get('/access/roles')
      .then((res) => { setRoles(res.data.roles); setCan((current) => ({ ...current, delete: res.data.can.delete })); })
      .catch(() => setRoles([]));
  }, []);

  useEffect(() => { load(); }, [load]);

  const all = users ?? [];
  const term = searchTerm.trim().toLowerCase();
  const changedOf = (user: StaffUser) => (user.granted ?? 0) + (user.revoked ?? 0);
  const lacksBranch = (user: StaffUser) => user.role?.scope === 'branch' && !user.branch;

  const userRows = all.filter((user) =>
    (!filters.role || user.role?.id === filters.role) &&
    (!filters.status || (filters.status === 'active' ? user.status === 'active' : user.status !== 'active')) &&
    (!filters.changed || changedOf(user) > 0) &&
    (!filters.nobranch || lacksBranch(user)) &&
    (!term || `${user.name} ${user.email}`.toLowerCase().includes(term)),
  );

  const roleTerm = roleSearch.trim().toLowerCase();
  const roleRows = roles.filter((role) =>
    (!roleFilters.kind || (roleFilters.kind === 'system' ? role.is_system : !role.is_system)) &&
    (!roleTerm || `${role.name} ${role.description ?? ''}`.toLowerCase().includes(roleTerm)),
  );

  const setFilter = (id: string, value: string) => setFilters((current) => ({ ...current, [id]: value }));
  const toggleFilter = (id: string, value: string) => setFilters((current) => ({ ...current, [id]: current[id] === value ? '' : value }));

  const openUser = (user: StaffUser | null) => { setError(''); setNotice(''); setEditingUser(user); setUserFormOpen(true); };

  const onUserSaved = (created?: NewCredentials) => {
    load();
    if (created) setCredentials({ ...created, title: 'Usuario creado' });
    else setNotice('Los cambios se guardaron.');
  };

  const resetPassword = async () => {
    if (!resetting) return;

    try {
      setBusy(true);
      setError('');
      const res = await api.post(`/access/users/${resetting.id}/password`);
      setCredentials({ email: res.data.email, password: res.data.temporary_password, title: `Contraseña nueva para ${resetting.name}` });
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar la contraseña.'));
    } finally {
      setBusy(false);
      setResetting(null);
    }
  };

  const removeRole = async () => {
    if (!deletingRole) return;

    try {
      setBusy(true);
      setError('');
      await api.delete(`/access/roles/${deletingRole.id}`);
      setNotice(`El rol ${deletingRole.name} se eliminó.`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el rol.'));
    } finally {
      setBusy(false);
      setDeletingRole(null);
    }
  };

  const tabs = [
    { id: 'users', label: 'Usuarios', count: all.length },
    { id: 'roles', label: 'Roles', count: roles.length },
  ];

  const limitReached = Boolean(blocked);

  const userCards: MetricCardItem[] = [
    ...(usage && usage.limit !== null ? [{
      title: `Cuentas de ${usage.limit} del plan${limitReached ? ': tope alcanzado' : ''}`,
      value: usage.used,
      icon: <Users size={18} />,
      iconColorClass: limitReached ? 'text-amber-500' : 'text-slate-500',
      borderColorClass: limitReached ? 'border-amber-300' : 'hover:border-slate-300',
    }] : []),
    {
      title: 'Activos',
      value: all.filter((u) => u.status === 'active').length,
      icon: <UserCheck size={18} />,
      iconColorClass: 'text-emerald-500',
      borderColorClass: filters.status === 'active' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-emerald-300',
      onClick: () => toggleFilter('status', 'active'),
    },
    {
      title: 'Con permisos cambiados',
      value: all.filter((u) => changedOf(u) > 0).length,
      icon: <SlidersHorizontal size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: filters.changed ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
      onClick: () => toggleFilter('changed', '1'),
    },
    ...(all.some(lacksBranch) ? [{
      title: 'Falta su sede',
      value: all.filter(lacksBranch).length,
      icon: <AlertTriangle size={18} />,
      iconColorClass: 'text-amber-500',
      borderColorClass: filters.nobranch ? 'border-amber-500 ring-2 ring-amber-500/20' : 'border-amber-300',
      onClick: () => toggleFilter('nobranch', '1'),
    }] : []),
    {
      title: 'Desactivados',
      value: all.filter((u) => u.status !== 'active').length,
      icon: <UserX size={18} />,
      iconColorClass: 'text-rose-500',
      borderColorClass: filters.status === 'inactive' ? 'border-rose-500 ring-2 ring-rose-500/20' : 'hover:border-rose-300',
      onClick: () => toggleFilter('status', 'inactive'),
    },
  ];

  const userFilters: FilterConfig[] = [
    { id: 'role', label: 'Rol', type: 'select', options: roleOptions.map((role) => ({ label: role.name, value: role.id })) },
    { id: 'status', label: 'Estado', type: 'select', options: [{ label: 'Activo', value: 'active' }, { label: 'Desactivado', value: 'inactive' }] },
  ];

  const userColumns: Column<StaffUser>[] = [
    {
      header: 'Persona',
      cell: (user) => (
        <div key={`name-${user.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>{initialsOf(user.name)}</div>
          <div>
            <span className="flex items-center gap-1.5 flex-wrap">
              <UserLink id={user.id} className={styles.customerName}>{user.name}</UserLink>
              {user.is_owner && <span className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-[#84D0D9]/25 text-[#022340]">Dueño</span>}
              {user.is_self && <span className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-slate-100 text-slate-600">Tú</span>}
            </span>
            <span className="block text-xs text-slate-400 font-mono">{user.email}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Rol',
      cell: (user) => (
        <div key={`role-${user.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{user.role?.name ?? 'Sin rol'}</span>
          {changedOf(user) > 0 ? (
            <span className="block text-xs text-slate-500" data-changed={user.email}>
              {[user.granted ? `${user.granted} agregado${user.granted === 1 ? '' : 's'}` : '', user.revoked ? `${user.revoked} quitado${user.revoked === 1 ? '' : 's'}` : ''].filter(Boolean).join(' · ')}
            </span>
          ) : user.professional_id ? (
            <ProfessionalLink id={user.professional_id} className="block text-xs text-slate-400">Atiende citas</ProfessionalLink>
          ) : null}
        </div>
      ),
    },
    {
      header: 'Sede',
      cell: (user) => lacksBranch(user)
        ? <span key={`branch-${user.id}`} data-lacks-branch={user.email} title="Su rol trabaja solo con su sede: asígnasela con el lápiz para que vea su agenda, su caja y sus ventas." className="px-2.5 py-1 text-xs rounded-full font-semibold bg-amber-50 text-amber-700 whitespace-nowrap">Falta su sede</span>
        : <span key={`branch-${user.id}`} className="text-sm text-slate-700">{user.branch?.name ?? <span className="text-slate-400">Sin sede fija</span>}</span>,
    },
    {
      header: 'Estado',
      cell: (user) => (
        <Badge key={`status-${user.id}`} data-status={user.status} tone={user.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}>
          {user.status === 'active' ? 'Activo' : 'Desactivado'}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (user) => (
        <div key={`actions-${user.id}`} className="flex items-center gap-1">
          <Link href={`/users/${user.id}`} data-open={user.email} title={`Ver a qué puede acceder ${user.name}`} className={`${iconButton} hover:text-indigo-600 hover:bg-indigo-50`}>
            <Eye size={16} />
          </Link>
          {can.edit && (
            <button data-edit={user.email} title={`Editar a ${user.name}`} onClick={() => openUser(user)} className={`${iconButton} hover:text-blue-600 hover:bg-blue-50`}>
              <Pencil size={16} />
            </button>
          )}
          {can.edit && !user.is_owner && !user.is_self && (
            <button data-reset={user.email} title={`Darle una contraseña nueva a ${user.name}`} onClick={() => { setError(''); setNotice(''); setResetting(user); }} className={`${iconButton} hover:text-amber-600 hover:bg-amber-50`}>
              <KeyRound size={16} />
            </button>
          )}
        </div>
      ),
    },
  ];

  const roleColumns: Column<RoleRow>[] = [
    {
      header: 'Rol',
      cell: (role) => (
        <div key={`role-${role.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-[#84D0D9]/20 text-[#022340]"><ShieldCheck size={16} /></span>
          <div>
            <Link href={`/users/roles/${role.id}`} data-role-link={role.slug} className={`${styles.customerName} hover:text-[#3F768C] hover:underline underline-offset-2`}>{role.name}</Link>
            <span className="block text-xs text-slate-400">{role.description || 'Sin descripción'}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Personas',
      cell: (role) => <span key={`users-${role.id}`} className="text-sm text-slate-700 tabular-nums">{role.users}</span>,
    },
    {
      header: 'Qué puede',
      cell: (role) => (
        <div key={`perms-${role.id}`} className="min-w-32">
          <span className="block text-sm font-semibold text-slate-800 tabular-nums">{role.locked ? 'Todo' : `${role.permissions} de ${role.total}`}</span>
          <span className="block h-1.5 mt-1 rounded-full bg-slate-100 overflow-hidden">
            <span className="block h-full rounded-full bg-[#3F768C]" style={{ width: `${role.total ? Math.round((role.permissions / role.total) * 100) : 0}%` }} />
          </span>
        </div>
      ),
    },
    {
      header: 'Qué ve',
      cell: (role) => <span key={`scope-${role.id}`} data-role-scope={role.scope} className="text-sm text-slate-700 whitespace-nowrap">{scopeTitle(role.scope)}</span>,
    },
    {
      header: 'Tipo',
      cell: (role) => (
        <Badge key={`kind-${role.id}`} tone={role.is_system ? 'bg-slate-100 text-slate-600' : 'bg-sky-50 text-sky-700'} nowrap>
          {role.is_system ? 'De fábrica' : 'Propio'}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (role) => (
        <div key={`actions-${role.id}`} className="flex items-center gap-1">
          <Link href={`/users/roles/${role.id}`} title={`Ver los permisos de ${role.name}`} className={`${iconButton} hover:text-indigo-600 hover:bg-indigo-50`}>
            <Eye size={16} />
          </Link>
          {can.delete && !role.is_system && (
            <button data-delete-role={role.name} title={`Eliminar ${role.name}`} onClick={() => { setError(''); setNotice(''); setDeletingRole(role); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
              <Trash2 size={16} />
            </button>
          )}
        </div>
      ),
    },
  ];

  const roleCards: MetricCardItem[] = [
    {
      title: 'Roles',
      value: roles.length,
      icon: <ShieldCheck size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: !roleFilters.kind ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
      onClick: () => setRoleFilters({ kind: '' }),
    },
    {
      title: 'De fábrica',
      value: roles.filter((r) => r.is_system).length,
      icon: <ShieldCheck size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: roleFilters.kind === 'system' ? 'border-slate-500 ring-2 ring-slate-500/20' : 'hover:border-slate-300',
      onClick: () => setRoleFilters((current) => ({ kind: current.kind === 'system' ? '' : 'system' })),
    },
    {
      title: 'Propios de tu empresa',
      value: roles.filter((r) => !r.is_system).length,
      icon: <ShieldCheck size={18} />,
      iconColorClass: 'text-sky-600',
      borderColorClass: roleFilters.kind === 'custom' ? 'border-sky-500 ring-2 ring-sky-500/20' : 'hover:border-sky-300',
      onClick: () => setRoleFilters((current) => ({ kind: current.kind === 'custom' ? '' : 'custom' })),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {tab === 'users' ? (
        <DataTable<StaffUser>
          title="Usuarios y roles"
          primaryActionLabel="Nuevo usuario"
          onPrimaryAction={can.create ? () => { if (!limitReached) openUser(null); } : undefined}
          primaryActionBlocked={can.create && limitReached ? blocked : undefined}
          tabs={tabs}
          activeTab={tab}
          onTabChange={(id) => setTab(id as 'users' | 'roles')}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={userFilters}
          filterValues={filters}
          onFilterChange={setFilter}
          dateFilters={[{ id: 'created_at', label: 'Fecha de registro', value: (u) => u.created_at }]}
          sortOptions={[
            { id: 'name', label: 'Nombre', value: (u) => u.name },
            { id: 'role', label: 'Rol', value: (u) => u.role?.name },
            { id: 'branch', label: 'Sede', value: (u) => u.branch?.name },
            { id: 'changed', label: 'Permisos cambiados', type: 'number', value: (u) => changedOf(u) },
            { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (u) => u.created_at },
          ]}
          columns={userColumns}
          data={userRows}
          getItemKey={(u) => u.id}
          entityLabel={userRows.length === 1 ? 'usuario' : 'usuarios'}
          metricCards={userCards}
        />
      ) : (
        <DataTable<RoleRow>
          title="Usuarios y roles"
          primaryActionLabel="Nuevo rol"
          onPrimaryAction={can.create ? () => { setError(''); setNotice(''); setRoleFormOpen(true); } : undefined}
          tabs={tabs}
          activeTab={tab}
          onTabChange={(id) => setTab(id as 'users' | 'roles')}
          searchTerm={roleSearch}
          onSearchChange={setRoleSearch}
          filterConfigs={[{ id: 'kind', label: 'Tipo', type: 'select', options: [{ label: 'De fábrica', value: 'system' }, { label: 'Propio', value: 'custom' }] }]}
          filterValues={roleFilters}
          onFilterChange={(id, value) => setRoleFilters((current) => ({ ...current, [id]: value }))}
          sortOptions={[
            { id: 'name', label: 'Nombre', value: (r) => r.name },
            { id: 'users', label: 'Personas', type: 'number', value: (r) => r.users },
            { id: 'permissions', label: 'Cuánto puede', type: 'number', value: (r) => r.permissions },
          ]}
          columns={roleColumns}
          data={roleRows}
          getItemKey={(r) => r.id}
          entityLabel={roleRows.length === 1 ? 'rol' : 'roles'}
          metricCards={roleCards}
        />
      )}

      <p className="text-sm text-slate-500 px-1">
        {tab === 'users' && myBranch && <span className="block mb-1 font-semibold text-slate-600" data-my-branch>Ves al personal de tu sede, {myBranch.name}. Lo que registres queda en esa sede.</span>}
        {tab === 'users'
          ? 'Cada persona tiene un rol, y el rol decide qué puede hacer. Toca su nombre para ver, pantalla por pantalla, a qué puede acceder, y para apagarle o encenderle algo solo a ella. Quienes atienden citas se registran desde la pantalla de profesionales.'
          : 'Toca un rol para ver y cambiar lo que permite: el cambio rige al instante para todas las personas que lo tienen. Con "Nuevo rol" creas uno propio, por ejemplo copiando Recepción y apagándole lo que no deba hacer.'}
      </p>

      <FormUser
        isOpen={userFormOpen}
        onClose={() => setUserFormOpen(false)}
        initialData={editingUser}
        roles={roleOptions}
        branches={branches}
        onSaved={onUserSaved}
        onRolesChanged={load}
      />

      <FormRole
        isOpen={roleFormOpen}
        onClose={() => setRoleFormOpen(false)}
        initialData={null}
        roles={roleOptions}
        onSaved={(id) => router.push(`/users/roles/${id}`)}
      />

      <AppModal
        isOpen={credentials !== null}
        type="alert"
        title={credentials?.title ?? ''}
        message={credentials ? <CredentialsNote email={credentials.email} password={credentials.password} /> : ''}
        confirmText="Entendido"
        maxWidth="max-w-xl"
        onConfirm={() => setCredentials(null)}
        onClose={() => setCredentials(null)}
      />

      <AppModal
        isOpen={resetting !== null}
        type="confirm"
        title={`¿Darle una contraseña nueva a ${resetting?.name ?? ''}?`}
        message="Su contraseña actual deja de servir y se cierra su sesión. Verás la contraseña nueva una sola vez, para entregársela."
        confirmText="Sí, cambiarla"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={resetPassword}
        onClose={() => setResetting(null)}
      />

      <AppModal
        isOpen={deletingRole !== null}
        type="confirm"
        variant="danger"
        title={`¿Eliminar el rol ${deletingRole?.name ?? ''}?`}
        message="Solo se elimina si nadie lo tiene. Si alguien lo usa, primero cámbiale el rol."
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={removeRole}
        onClose={() => setDeletingRole(null)}
      />
    </div>
  );
}
