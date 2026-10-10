'use client';

import { useEffect, useState } from 'react';
import { Store } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { apiError } from '@/features/appointments/appointments';
import { TIMEZONES, type Branch } from '../branches';

interface FormBranchProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Branch | null;
  onSaved: (branch: Branch) => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

const empty = { name: '', address: '', district: '', department: 'Lima', phone: '', timezone: 'America/Lima' };

// Panel para abrir una sucursal o cambiar sus datos. El horario de atención
// se define aparte, en su propio panel.
export default function FormBranch({ isOpen, onClose, initialData, onSaved }: FormBranchProps) {
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setForm(initialData ? {
      name: initialData.name,
      address: initialData.address ?? '',
      district: initialData.district ?? '',
      department: initialData.department ?? '',
      phone: initialData.phone ?? '',
      timezone: initialData.timezone,
    } : empty);
  }, [isOpen, initialData]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      name: form.name.trim(),
      address: form.address.trim() || null,
      district: form.district.trim() || null,
      department: form.department.trim() || null,
      phone: form.phone.trim() || null,
      timezone: form.timezone,
    };

    try {
      setSaving(true);
      setError('');
      const res = initialData ? await api.put(`/branches/${initialData.id}`, payload) : await api.post('/branches', payload);
      onSaved(res.data);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la sucursal.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'branch-form';
  // Una zona que no está en la lista (puesta por la plataforma) se conserva.
  const zones = TIMEZONES.some((z) => z.value === form.timezone) ? TIMEZONES : [...TIMEZONES, { value: form.timezone, label: form.timezone }];

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar sucursal: ${initialData.name}` : 'Nueva sucursal'}
      subtitle={initialData ? 'Sus datos de contacto y ubicación' : 'Abre con el mismo horario que tu primera sede; luego lo ajustas'}
      icon={<Store size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="branch-name">Nombre *</label>
            <input id="branch-name" type="text" required maxLength={150} placeholder="Ej. Sede Miraflores" className={inputClass} value={form.name} onChange={(e) => set({ name: e.target.value })} />
          </div>

          <div>
            <label className={labelClass} htmlFor="branch-address">Dirección</label>
            <input id="branch-address" type="text" maxLength={255} placeholder="Ej. Av. Larco 123" className={inputClass} value={form.address} onChange={(e) => set({ address: e.target.value })} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="branch-district">Distrito</label>
              <input id="branch-district" type="text" maxLength={100} className={inputClass} value={form.district} onChange={(e) => set({ district: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="branch-department">Departamento</label>
              <input id="branch-department" type="text" maxLength={100} className={inputClass} value={form.department} onChange={(e) => set({ department: e.target.value })} />
            </div>
          </div>

          <div>
            <label className={labelClass} htmlFor="branch-phone">Teléfono</label>
            <input id="branch-phone" type="tel" maxLength={30} placeholder="Ej. (01) 445 1100" className={inputClass} value={form.phone} onChange={(e) => set({ phone: e.target.value })} />
          </div>

          <div>
            <Select
              label="Zona horaria"
              value={form.timezone}
              onChange={(e) => set({ timezone: String(e.target.value) || 'America/Lima' })}
              options={zones}
            />
            <p className="text-xs text-slate-500 mt-1.5">Las horas de su agenda se muestran en esta zona.</p>
          </div>

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
          <button type="submit" form={formId} disabled={saving} className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear sucursal'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
