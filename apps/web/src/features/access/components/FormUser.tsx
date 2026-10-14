'use client';

import { useEffect, useState } from 'react';
import { UserCog } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { apiError } from '@/features/appointments/appointments';
import FormRole from './FormRole';
import { type BranchOption, type RoleOption, type StaffUser } from '../access';

export interface NewCredentials {
  email: string;
  password: string;
}

interface FormUserProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: StaffUser | null;
  roles: RoleOption[];
  branches: BranchOption[];
  // Se creó o cambió alguien; con credenciales cuando la cuenta es nueva.
  onSaved: (credentials?: NewCredentials) => void;
  // Se creó un rol desde el "+": la lista de roles debe volver a pedirse.
  onRolesChanged?: () => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold text-slate-600 mb-1.5';

const empty = { firstName: '', lastName: '', email: '', phone: '', roleId: '', branchId: '', active: true };

// Panel para crear la cuenta de alguien que no atiende citas (recepción, caja,
// gerencia) o cambiar los datos, el rol y la sede de una cuenta que ya existe.
export default function FormUser({ isOpen, onClose, initialData, roles, branches, onSaved, onRolesChanged }: FormUserProps) {
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [roleFormOpen, setRoleFormOpen] = useState(false);

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setForm(initialData ? {
      firstName: initialData.first_name,
      lastName: initialData.last_name,
      email: initialData.email,
      phone: initialData.phone ?? '',
      roleId: initialData.role?.id ?? '',
      branchId: initialData.branch?.id ?? '',
      active: initialData.status === 'active',
    } : { ...empty, branchId: branches.length === 1 ? branches[0].id : '' });
  }, [isOpen, initialData, branches]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));

  const isProfessional = Boolean(initialData?.professional_id);
  // Al dueño y a uno mismo no se les cambia el rol ni se les desactiva; quien atiende citas conserva su rol.
  const fixedReason = initialData?.is_owner
    ? 'Es la cuenta del dueño de la empresa: siempre es Administrador y no se desactiva.'
    : initialData?.is_self
      ? 'Es tu propia cuenta: tu rol lo cambia otro administrador.'
      : isProfessional
        ? 'Atiende citas, así que conserva el rol Profesional. Si necesita hacer algo más, enciéndele esos permisos en su página.'
        : '';

  // El rol Profesional solo lo tiene quien está registrado como profesional.
  const roleOptions = roles.filter((role) => role.slug !== 'professional' || isProfessional);
  const chosenRole = roles.find((role) => role.id === form.roleId);
  // Quien trabaja solo con su sede necesita una.
  const isManager = chosenRole?.scope === 'branch';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!form.roleId) {
      setError('Elige el rol de esta persona.');
      return;
    }

    if (isManager && !form.branchId) {
      setError(`Quien tiene el rol ${chosenRole?.name ?? ''} trabaja solo con su sede: elige cuál es.`);
      return;
    }

    const payload = {
      first_name: form.firstName.trim(),
      last_name: form.lastName.trim(),
      email: form.email.trim(),
      phone: form.phone.trim() || null,
      role_id: form.roleId,
      branch_id: form.branchId || null,
      ...(initialData ? { status: form.active ? 'active' : 'inactive' } : {}),
    };

    try {
      setSaving(true);
      setError('');

      if (initialData) {
        await api.put(`/access/users/${initialData.id}`, payload);
        onSaved();
      } else {
        const res = await api.post('/access/users', payload);
        onSaved({ email: res.data.email, password: res.data.temporary_password });
      }

      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el usuario.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'staff-user-form';

  return (
    <>
      <FormDrawer
        isOpen={isOpen}
        onClose={onClose}
        title={initialData ? `Editar usuario: ${initialData.name}` : 'Nuevo usuario'}
        subtitle={initialData ? 'Sus datos, su rol y su sede' : 'Alguien que entra al sistema y no atiende citas: recepción, caja, gerencia'}
        icon={<UserCog size={20} />}
      >
        <div className="flex flex-col h-full justify-between">
          <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass} htmlFor="user-first-name">Nombres *</label>
                <input id="user-first-name" required maxLength={100} placeholder="Ej. Ana Lucía" className={inputClass} value={form.firstName} onChange={(e) => set({ firstName: e.target.value })} />
              </div>
              <div>
                <label className={labelClass} htmlFor="user-last-name">Apellidos *</label>
                <input id="user-last-name" required maxLength={100} placeholder="Ej. Torres Vega" className={inputClass} value={form.lastName} onChange={(e) => set({ lastName: e.target.value })} />
              </div>
            </div>

            <div>
              <label className={labelClass} htmlFor="user-email">Correo *</label>
              <input id="user-email" type="email" required maxLength={190} placeholder="Ej. ana.torres@miempresa.pe" className={inputClass} value={form.email} onChange={(e) => set({ email: e.target.value })} />
              <p className="text-xs text-slate-500 mt-1.5">Con este correo entra al sistema.</p>
            </div>

            <div>
              <label className={labelClass} htmlFor="user-phone">Celular</label>
              <input id="user-phone" maxLength={30} placeholder="Ej. 987 654 321" className={inputClass} value={form.phone} onChange={(e) => set({ phone: e.target.value.replace(/[^\d+\s-]/g, '') })} />
            </div>

            <div data-field="role">
              <span className={labelClass}>Rol *</span>
              <Select
                ariaLabel="Rol"
                placeholder="Elige un rol"
                disabled={Boolean(fixedReason)}
                value={form.roleId}
                onChange={(e) => set({ roleId: String(e.target.value) })}
                options={roleOptions.map((role) => ({ label: role.name, value: role.id }))}
                onAdd={fixedReason ? undefined : () => setRoleFormOpen(true)}
                addLabel="Nuevo rol"
              />
              {fixedReason ? (
                <p className="text-xs text-slate-500 mt-1.5" data-fixed-role>{fixedReason}</p>
              ) : (
                <p className="text-xs text-slate-500 mt-1.5" data-role-hint>
                  {chosenRole?.description
                    ? chosenRole.description
                    : 'El rol decide qué puede hacer. Después, en su página, puedes apagarle o encenderle permisos solo a esta persona.'}
                </p>
              )}
            </div>

            <div data-field="branch">
              <span className={labelClass}>Sede{isManager ? ' *' : ''}</span>
              <Select
                ariaLabel="Sede"
                placeholder={isManager ? 'Elige su sede' : 'Sin sede fija'}
                clearable={!isManager}
                value={form.branchId}
                onChange={(e) => set({ branchId: String(e.target.value) })}
                options={branches.map((branch) => ({ label: branch.name, value: branch.id }))}
              />
              <p className="text-xs text-slate-500 mt-1.5">
                {isManager
                  ? 'Verá solo lo de esta sede: su agenda, sus citas, su caja, sus ventas y su equipo.'
                  : 'Dónde trabaja normalmente. Con este rol ve toda la empresa: la sede sirve para ubicarla.'}
              </p>
            </div>

            {initialData && !initialData.is_owner && !initialData.is_self && (
              <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
                <div>
                  <span className="block text-sm font-semibold text-slate-800">Cuenta activa</span>
                  <span className="block text-xs text-slate-500">Apágala si ya no trabaja aquí: deja de entrar al sistema y libera su lugar en tu plan. No se borra nada de lo que hizo.</span>
                </div>
                <Switch checked={form.active} onChange={(active) => set({ active })} label="Cuenta activa" />
              </div>
            )}

            {!initialData && (
              <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                Al crear la cuenta verás una contraseña temporal para entregársela. Si esta persona atiende citas, regístrala mejor desde la pantalla de profesionales: su cuenta se crea sola.
              </p>
            )}

            {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
          </form>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
              Cancelar
            </button>
            <button type="submit" form={formId} data-submit="user" disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
              {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear usuario'}
            </button>
          </div>
        </div>
      </FormDrawer>

      {/* El "+" del rol: se crea ahí mismo y queda elegido. */}
      <FormRole
        isOpen={roleFormOpen}
        onClose={() => setRoleFormOpen(false)}
        initialData={null}
        roles={roles}
        onSaved={(id) => { onRolesChanged?.(); set({ roleId: id }); }}
      />
    </>
  );
}
