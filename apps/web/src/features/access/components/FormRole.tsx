'use client';

import { useEffect, useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { apiError } from '@/features/appointments/appointments';
import type { RoleOption } from '../access';

interface FormRoleProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Pick<RoleOption, 'id' | 'name' | 'description'> | null;
  roles: RoleOption[];
  onSaved: (id: string) => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold text-slate-600 mb-1.5';

export default function FormRole({ isOpen, onClose, initialData, roles, onSaved }: FormRoleProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [copyFrom, setCopyFrom] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setName(initialData?.name ?? '');
    setDescription(initialData?.description ?? '');
    setCopyFrom('');
  }, [isOpen, initialData]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    e.stopPropagation();

    try {
      setSaving(true);
      setError('');

      const res = initialData
        ? await api.put(`/access/roles/${initialData.id}`, { name: name.trim(), description: description.trim() || null })
        : await api.post('/access/roles', { name: name.trim(), description: description.trim() || null, copy_from: copyFrom || null });

      onSaved(res.data.id);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el rol.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'role-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar rol: ${initialData.name}` : 'Nuevo rol'}
      subtitle={initialData ? 'Su nombre y para qué es' : 'Un rol propio de tu empresa, con los permisos que tú elijas'}
      icon={<ShieldCheck size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="role-name">Nombre *</label>
            <input id="role-name" required maxLength={100} placeholder="Ej. Asistente de recepción" className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
          </div>

          <div>
            <label className={labelClass} htmlFor="role-description">Para qué es</label>
            <input id="role-description" maxLength={255} placeholder="Ej. Apoya en recepción los fines de semana" className={inputClass} value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>

          {!initialData && (
            <div>
              <span className={labelClass}>Empezar con los permisos de</span>
              <Select
                ariaLabel="Copiar permisos de"
                placeholder="Ninguno: empieza sin permisos"
                clearable
                value={copyFrom}
                onChange={(e) => setCopyFrom(String(e.target.value))}
                options={roles.map((role) => ({ label: role.name, value: role.id }))}
              />
              <p className="text-xs text-slate-500 mt-1.5">
                Por ejemplo, copia Recepción y luego apágale lo que no deba hacer. Después de crearlo se abre su página para encender y apagar cada permiso.
              </p>
            </div>
          )}

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
          <button type="submit" form={formId} data-submit="role" disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear rol'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
