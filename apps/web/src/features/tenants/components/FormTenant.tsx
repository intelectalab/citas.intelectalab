'use client';

import { useState, useEffect } from 'react';
import { Building2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';

interface Tenant {
  id?: string;
  name: string;
  slug?: string;
  timezone: string;
  status: string;
}

interface FormTenantProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: Tenant) => Promise<void>;
  initialData?: Tenant | null;
}

export default function FormTenant({
  isOpen,
  onClose,
  onSubmit,
  initialData,
}: FormTenantProps) {
  const [formData, setFormData] = useState<Tenant>({
    name: '',
    slug: '',
    timezone: 'UTC',
    status: 'active',
  });
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (initialData) {
      setFormData({
        name: initialData.name || '',
        slug: initialData.slug || '',
        timezone: initialData.timezone || 'UTC',
        status: initialData.status || 'active',
      });
    } else {
      setFormData({
        name: '',
        slug: '',
        timezone: 'UTC',
        status: 'active',
      });
    }
  }, [initialData, isOpen]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setLoading(true);
      await onSubmit(formData);
    } catch (error) {
      console.error('Error al enviar el formulario de tenant:', error);
    } finally {
      setLoading(false);
    }
  };

  const isEditing = Boolean(initialData);

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar Tenant' : 'Nuevo Tenant'}
      subtitle={isEditing ? 'Modifica los datos de la empresa' : 'Registra una nueva empresa en el sistema'}
      icon={<Building2 size={20} />}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
            Nombre de la Empresa / Tenant *
          </label>
          <input
            type="text"
            required
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            placeholder="Ej. Mi Empresa SaaS"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
            Slug (Identificador URL opcional)
          </label>
          <input
            type="text"
            value={formData.slug || ''}
            onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            placeholder="mi-empresa (se genera automático si se deja vacío)"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
            Zona Horaria (Timezone) *
          </label>
          <input
            type="text"
            required
            value={formData.timezone}
            onChange={(e) => setFormData({ ...formData, timezone: e.target.value })}
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
            placeholder="Ej. America/Lima"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
            Estado
          </label>
          <select
            value={formData.status}
            onChange={(e) => setFormData({ ...formData, status: e.target.value })}
            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white"
          >
            <option value="active">Activo</option>
            <option value="inactive">Inactivo</option>
          </select>
        </div>

        <div className="pt-6 border-t border-slate-100 flex items-center justify-end gap-3 mt-8">
          <button
            type="button"
            onClick={onClose}
            disabled={loading}
            className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
          >
            Cancelar
          </button>
          <button
            type="submit"
            disabled={loading}
            className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
          >
            {loading ? 'Guardando...' : (isEditing ? 'Guardar Cambios' : 'Crear Tenant')}
          </button>
        </div>
      </form>
    </FormDrawer>
  );
}