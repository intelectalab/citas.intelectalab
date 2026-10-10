'use client';

import { useEffect, useState } from 'react';
import { Building2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { apiError } from '@/features/appointments/appointments';
import type { CompanyProfileData } from '../companies';

interface FormCompanyProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: CompanyProfileData;
  onSaved: (data: CompanyProfileData) => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';
const formId = 'company-form';

const empty = { name: '', legal_name: '', tax_id: '', phone: '', address: '', district: '', department: '' };

// Panel para cambiar los datos generales de la empresa.
export default function FormCompany({ isOpen, onClose, initialData, onSaved }: FormCompanyProps) {
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setForm({
      name: initialData.tenant.name,
      legal_name: initialData.company.legal_name ?? '',
      tax_id: initialData.company.tax_id ?? '',
      phone: initialData.company.phone ?? '',
      address: initialData.company.address ?? '',
      district: initialData.company.district ?? '',
      department: initialData.company.department ?? '',
    });
  }, [isOpen, initialData]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      name: form.name.trim(),
      legal_name: form.legal_name.trim() || null,
      tax_id: form.tax_id.trim() || null,
      phone: form.phone.trim() || null,
      address: form.address.trim() || null,
      district: form.district.trim() || null,
      department: form.department.trim() || null,
    };

    try {
      setSaving(true);
      setError('');
      const res = await api.put('/company', payload);
      onSaved(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar los datos.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Editar empresa: ${initialData.tenant.name}`}
      subtitle="Sus datos generales y su dirección fiscal"
      icon={<Building2 size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="company-name">Nombre de la empresa *</label>
            <input id="company-name" type="text" required maxLength={150} className={inputClass} value={form.name} onChange={(e) => set({ name: e.target.value })} />
            <p className="text-xs text-slate-500 mt-1.5">Es el nombre comercial: el que ven tu equipo y quienes reservan contigo.</p>
          </div>

          <div>
            <label className={labelClass} htmlFor="company-legal">Razón social</label>
            <input id="company-legal" type="text" maxLength={200} placeholder="Ej. Servicios Integrales S.A.C." className={inputClass} value={form.legal_name} onChange={(e) => set({ legal_name: e.target.value })} />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={labelClass} htmlFor="company-tax">RUC</label>
              <input id="company-tax" type="text" inputMode="numeric" maxLength={11} placeholder="11 dígitos" className={inputClass} value={form.tax_id} onChange={(e) => set({ tax_id: e.target.value.replace(/\D/g, '') })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="company-phone">Teléfono</label>
              <input id="company-phone" type="text" maxLength={30} className={inputClass} value={form.phone} onChange={(e) => set({ phone: e.target.value })} />
            </div>
          </div>

          <div>
            <label className={labelClass} htmlFor="company-address">Dirección fiscal</label>
            <input id="company-address" type="text" maxLength={255} placeholder="Ej. Av. Larco 123" className={inputClass} value={form.address} onChange={(e) => set({ address: e.target.value })} />
            <p className="text-xs text-slate-500 mt-1.5">La dirección de cada local se define en Sucursales.</p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={labelClass} htmlFor="company-district">Distrito</label>
              <input id="company-district" type="text" maxLength={100} className={inputClass} value={form.district} onChange={(e) => set({ district: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="company-department">Departamento</label>
              <input id="company-department" type="text" maxLength={100} className={inputClass} value={form.department} onChange={(e) => set({ department: e.target.value })} />
            </div>
          </div>

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
          <button type="submit" form={formId} disabled={saving} className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
            {saving ? 'Guardando...' : 'Guardar cambios'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
