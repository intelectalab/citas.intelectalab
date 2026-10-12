'use client';

import { useEffect, useState } from 'react';
import { DoorOpen } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { apiError } from '@/features/appointments/appointments';
import type { Room as RoomRow } from '@/features/branches/branches';

interface FormRoomProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: RoomRow | null;
  branches: { id: string; name: string }[];
  // Salas donde puede estar instalado un equipo (vacío: aún no se puede indicar).
  rooms: RoomRow[];
  // Sede que se está viendo, para abrir el panel ya con ella.
  defaultBranchId?: string;
  onSaved: () => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

const empty = { branch_id: '', parent_id: '', name: '', description: '', kind: 'room' as RoomRow['kind'], active: true };

// Panel para registrar una sala o un equipo, o cambiar sus datos.
export default function FormRoom({ isOpen, onClose, initialData, branches, rooms, defaultBranchId, onSaved }: FormRoomProps) {
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setForm(initialData ? {
      branch_id: initialData.branch_id,
      parent_id: initialData.parent_id ?? '',
      name: initialData.name,
      description: initialData.description ?? '',
      kind: initialData.kind,
      active: initialData.status === 'active',
    } : { ...empty, branch_id: defaultBranchId || (branches.length === 1 ? branches[0].id : '') });
  }, [isOpen, initialData, defaultBranchId, branches]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!form.branch_id) {
      setError('Elige la sede donde está.');
      return;
    }

    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      kind: form.kind,
      status: form.active ? 'active' : 'inactive',
      parent_id: form.kind === 'equipment' && form.parent_id ? form.parent_id : null,
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) {
        await api.put(`/rooms/${initialData.id}`, payload);
      } else {
        await api.post(`/branches/${form.branch_id}/rooms`, payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'room-form';
  const isEquipment = form.kind === 'equipment';
  // Un equipo solo puede estar en una sala de su misma sede.
  const roomsHere = rooms.filter((r) => r.branch_id === form.branch_id);

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar: ${initialData.name}` : 'Nueva sala o equipo'}
      subtitle={initialData ? `En ${initialData.branch_name ?? 'su sede'}` : 'Un consultorio, una cabina, un sillón o un equipo de la sede'}
      icon={<DoorOpen size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <Select
              label="Tipo"
              clearable={false}
              value={form.kind}
              onChange={(e) => set({ kind: String(e.target.value) as RoomRow['kind'] })}
              options={[
                { value: 'room', label: 'Sala (consultorio, cabina, sillón)' },
                { value: 'equipment', label: 'Equipo (ecógrafo, láser, camilla)' },
              ]}
            />
          </div>

          <div>
            {/* Una sala no se muda de sede: se registra otra en la sede nueva. */}
            <Select
              label="Sede *"
              placeholder="Elige la sede"
              clearable={false}
              disabled={Boolean(initialData)}
              value={form.branch_id}
              onChange={(e) => set({ branch_id: String(e.target.value), parent_id: '' })}
              options={(initialData && !branches.some((b) => b.id === initialData.branch_id)
                ? [...branches, { id: initialData.branch_id, name: initialData.branch_name ?? 'Sede' }]
                : branches
              ).map((b) => ({ value: b.id, label: b.name }))}
            />
            {initialData && <p className="text-xs text-slate-500 mt-1.5">La sede no se cambia. Si se mudó, regístralo en la otra sede y desactiva este.</p>}
          </div>

          {isEquipment && rooms.length > 0 && (
            <div data-field="parent">
              <Select
                label="Sala donde está"
                placeholder={form.branch_id ? 'No está en una sala fija' : 'Elige primero la sede'}
                disabled={!form.branch_id}
                value={form.parent_id}
                onChange={(e) => set({ parent_id: String(e.target.value) })}
                options={roomsHere.map((r) => ({ value: r.id, label: r.name }))}
              />
              <p className="text-xs text-slate-500 mt-1.5">
                {form.branch_id && roomsHere.length === 0
                  ? 'Esta sede aún no tiene salas registradas.'
                  : 'Opcional. Sirve para saber qué sala se ve afectada si el equipo queda fuera de uso.'}
              </p>
            </div>
          )}

          <div>
            <label className={labelClass} htmlFor="room-name">Nombre *</label>
            <input id="room-name" type="text" required maxLength={100} placeholder={isEquipment ? 'Ej. Ecógrafo 1' : 'Ej. Consultorio 101'} className={inputClass} value={form.name} onChange={(e) => set({ name: e.target.value })} />
          </div>

          <div>
            <label className={labelClass} htmlFor="room-description">Descripción</label>
            <input id="room-description" type="text" maxLength={255} placeholder={isEquipment ? 'Ej. Para ecografías obstétricas' : 'Ej. Segundo piso, con camilla'} className={inputClass} value={form.description} onChange={(e) => set({ description: e.target.value })} />
          </div>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">En uso</span>
              <span className="block text-xs text-slate-500">Apágalo si está en mantenimiento o ya no se usa.</span>
            </div>
            <Switch checked={form.active} onChange={(active) => set({ active })} label="En uso" />
          </div>

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
          <button type="submit" form={formId} disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : isEquipment ? 'Registrar equipo' : 'Registrar sala'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
