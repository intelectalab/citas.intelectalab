'use client';

import { useState, useEffect } from 'react';
import { UserPlus, Building2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';

interface FormContactProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (formData: any) => void;
  initialData?: any | null;
}

export default function FormContact({ isOpen, onClose, onSubmit, initialData }: FormContactProps) {
  const [form, setForm] = useState({
    tenant_id: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    position: '',
  });

  const [tenants, setTenants] = useState<any[]>([]);

  useEffect(() => {
    if (isOpen) {
      api.get('/tenants')
        .then(res => setTenants(res.data.data || res.data))
        .catch(err => console.error('Error cargando tenants', err));
    }
  }, [isOpen]);

  useEffect(() => {
    if (initialData) {
      setForm({
        tenant_id: initialData.tenant_id || '',
        first_name: initialData.first_name || '',
        last_name: initialData.last_name || '',
        email: initialData.email || '',
        phone: initialData.phone || '',
        position: initialData.position || '',
      });
    } else {
      setForm({
        tenant_id: '',
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        position: '',
      });
    }
  }, [initialData, isOpen]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(form);
  };

  const isEditing = Boolean(initialData);

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar Contacto' : 'Crear Contacto'}
      subtitle={isEditing ? 'Modifica los datos del contacto' : 'Añade un contacto clave para la empresa'}
      icon={<UserPlus size={20} />}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        
        <Select
          label="Empresa / Tenant *"
          required
          icon={<Building2 size={16} />}
          placeholder="Seleccione la empresa..."
          value={form.tenant_id}
          onChange={(e) => setForm({ ...form, tenant_id: String(e.target.value) })}
          options={tenants.map((tenant) => ({
            value: tenant.id,
            label: tenant.name || tenant.company_name || tenant.id,
          }))}
        />

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Correo Electrónico *</label>
          <input 
            type="email" required
            placeholder="carlos@empresa.com"
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            value={form.email}
            onChange={e => setForm({...form, email: e.target.value})}
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombres *</label>
          <input 
            type="text" required
            placeholder="Ej. Carlos"
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            value={form.first_name}
            onChange={e => setForm({...form, first_name: e.target.value})}
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Apellidos *</label>
          <input 
            type="text" required
            placeholder="Ej. Mendoza Ruiz"
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            value={form.last_name}
            onChange={e => setForm({...form, last_name: e.target.value})}
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Cargo / Posición</label>
          <input 
            type="text"
            placeholder="Ej. Gerente General"
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            value={form.position}
            onChange={e => setForm({...form, position: e.target.value})}
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Teléfono</label>
          <input 
            type="text"
            placeholder="+51 999 888 777"
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            value={form.phone}
            onChange={e => setForm({...form, phone: e.target.value})}
          />
        </div>

        <div className="pt-6 border-t border-slate-100 flex items-center justify-end gap-3 mt-8">
          <button 
            type="button" 
            onClick={onClose}
            className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
          >
            Cancelar
          </button>
          <button 
            type="submit" 
            className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
          >
            {isEditing ? 'Guardar Cambios' : 'Crear Contacto'}
          </button>
        </div>
      </form>
    </FormDrawer>
  );
}