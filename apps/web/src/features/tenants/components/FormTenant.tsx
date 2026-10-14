'use client';

import { useState, useEffect } from 'react';
import Select from '@/app/components/common/Select';
import { Building2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';

interface Tenant {
  id?: string;
  name: string;
  slug?: string;
  timezone: string;
  status: string;
}

export interface TenantFormData extends Tenant {
  business_type_id: string;
  plan_id: string;
  start_trial: boolean;
  billing_cycle: string;
  legal_name: string;
  tax_id: string;
  phone: string;
  address: string;
  district: string;
  department: string;
  branch_name: string;
  admin_first_name: string;
  admin_last_name: string;
  admin_email: string;
}

interface FormTenantProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: TenantFormData) => Promise<void>;
  initialData?: Tenant | null;
}

const EMPTY_FORM: TenantFormData = {
  name: '',
  slug: '',
  timezone: 'America/Lima',
  status: 'active',
  business_type_id: '',
  plan_id: '',
  start_trial: true,
  billing_cycle: 'monthly',
  legal_name: '',
  tax_id: '',
  phone: '',
  address: '',
  district: '',
  department: 'Lima',
  branch_name: 'Sede Principal',
  admin_first_name: '',
  admin_last_name: '',
  admin_email: '',
};

const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';
const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800';
const sectionClass = 'text-[11px] font-bold uppercase tracking-wider text-slate-400 pt-5 mt-2 border-t border-slate-100';

export default function FormTenant({
  isOpen,
  onClose,
  onSubmit,
  initialData,
}: FormTenantProps) {
  const [formData, setFormData] = useState<TenantFormData>(EMPTY_FORM);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [businessTypes, setBusinessTypes] = useState<any[]>([]);
  const [plans, setPlans] = useState<any[]>([]);

  const isEditing = Boolean(initialData);

  useEffect(() => {
    setError(null);

    if (initialData) {
      setFormData({
        ...EMPTY_FORM,
        name: initialData.name || '',
        slug: initialData.slug || '',
        timezone: initialData.timezone || 'America/Lima',
        status: initialData.status || 'active',
      });
    } else {
      setFormData(EMPTY_FORM);
    }
  }, [initialData, isOpen]);

  useEffect(() => {
    if (!isOpen || isEditing) return;

    api.get('/admin/business-types')
      .then((res) => setBusinessTypes((res.data ?? []).filter((type: any) => type.status === 'active')))
      .catch(() => setBusinessTypes([]));
    api.get('/saas-plans')
      .then((res) => setPlans((res.data?.data ?? res.data ?? []).filter((plan: any) => plan.status === 'active')))
      .catch(() => setPlans([]));
  }, [isOpen, isEditing]);

  const set = <K extends keyof TenantFormData>(key: K, value: TenantFormData[K]) => setFormData((prev) => ({ ...prev, [key]: value }));

  const selectedType = businessTypes.find((type) => type.id === formData.business_type_id);
  const selectedPlan = plans.find((plan) => plan.id === formData.plan_id);
  const trialDays = selectedPlan?.trial_config?.trial_days ?? 14;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setLoading(true);
      setError(null);
      await onSubmit(formData);
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setError(first || err.response?.data?.message || 'No se pudo guardar el tenant.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? `Editar tenant: ${initialData?.name ?? ''}` : 'Nuevo Tenant'}
      subtitle={isEditing ? 'Modifica los datos de la empresa' : 'Registra una empresa lista para operar: rubro, plan, sede y administrador'}
      icon={<Building2 size={20} />}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className={labelClass}>
            Nombre de la Empresa / Tenant *
          </label>
          <input
            type="text"
            required
            value={formData.name}
            onChange={(e) => set('name', e.target.value)}
            className={inputClass}
            placeholder="Ej. Mi Empresa SaaS"
          />
        </div>

        {isEditing && (
          <div>
            <label className={labelClass}>
              Slug (Identificador URL opcional)
            </label>
            <input
              type="text"
              value={formData.slug || ''}
              onChange={(e) => set('slug', e.target.value)}
              className={inputClass}
              placeholder="mi-empresa (se genera automático si se deja vacío)"
            />
          </div>
        )}

        {!isEditing && (
          <>
            <div>
              <label className={labelClass}>Rubro *</label>
              <Select
                ariaLabel="Rubro"
                clearable={false}
                placeholder="Selecciona el rubro"
                value={formData.business_type_id}
                onChange={(e) => set('business_type_id', String(e.target.value))}
                options={businessTypes.map((type) => ({ value: type.id, label: type.name }))}
              />
              {selectedType && (
                <p className="text-[11px] text-slate-400 mt-1.5">
                  Empieza con {(selectedType.template?.categories ?? []).reduce((total: number, category: any) => total + (category.services?.length ?? 0), 0)} servicios
                  {selectedType.template?.record_template?.name ? ` y la ficha "${selectedType.template.record_template.name}"` : ''}.
                  Nombra a sus clientes "{selectedType.vocabulary?.clients ?? 'Clientes'}".
                </p>
              )}
            </div>

            <div>
              <label className={labelClass}>Plan *</label>
              <Select
                ariaLabel="Plan"
                clearable={false}
                placeholder="Selecciona el plan"
                value={formData.plan_id}
                onChange={(e) => set('plan_id', String(e.target.value))}
                options={plans.map((plan) => ({ value: plan.id, label: `${plan.name} · ${plan.currency || 'PEN'} ${plan.price_monthly}/mes${plan.is_public_catalog ? '' : ' (a medida)'}` }))}
              />
            </div>

            <label className="flex items-start gap-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.start_trial}
                onChange={(e) => set('start_trial', e.target.checked)}
                className="mt-0.5 rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
              />
              <span>
                <span className="block text-sm font-medium text-slate-800">Empezar en periodo de prueba</span>
                <span className="block text-xs text-slate-400">
                  {formData.start_trial
                    ? `${trialDays} días de prueba según su plan.`
                    : 'Empieza con la suscripción activa desde hoy.'}
                </span>
              </span>
            </label>

            {!formData.start_trial && (
              <div>
                <label className={labelClass}>Ciclo de cobro</label>
                <Select
                  ariaLabel="Ciclo de pago"
                  clearable={false}
                  value={formData.billing_cycle}
                  onChange={(e) => set('billing_cycle', String(e.target.value))}
                  options={[{ value: 'monthly', label: 'Mensual' }, { value: 'yearly', label: 'Anual' }]}
                />
              </div>
            )}

            <p className={sectionClass}>Datos de la empresa</p>

            <div>
              <label className={labelClass}>Razón social *</label>
              <input type="text" required maxLength={200} value={formData.legal_name} onChange={(e) => set('legal_name', e.target.value)} className={inputClass} placeholder="Empresa Ejemplo S.A.C." />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass}>RUC *</label>
                <input type="text" required maxLength={30} value={formData.tax_id} onChange={(e) => set('tax_id', e.target.value)} className={`${inputClass} font-mono`} placeholder="20123456789" />
              </div>
              <div>
                <label className={labelClass}>Teléfono</label>
                <input type="text" maxLength={30} value={formData.phone} onChange={(e) => set('phone', e.target.value)} className={inputClass} placeholder="+51 999 999 999" />
              </div>
            </div>

            <p className={sectionClass}>Sede principal</p>

            <div>
              <label className={labelClass}>Nombre de la sede *</label>
              <input type="text" required maxLength={150} value={formData.branch_name} onChange={(e) => set('branch_name', e.target.value)} className={inputClass} placeholder="Sede Principal" />
            </div>

            <div>
              <label className={labelClass}>Dirección</label>
              <input type="text" maxLength={255} value={formData.address} onChange={(e) => set('address', e.target.value)} className={inputClass} placeholder="Av. Principal 123" />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass}>Distrito</label>
                <input type="text" maxLength={100} value={formData.district} onChange={(e) => set('district', e.target.value)} className={inputClass} placeholder="Miraflores" />
              </div>
              <div>
                <label className={labelClass}>Departamento</label>
                <input type="text" maxLength={100} value={formData.department} onChange={(e) => set('department', e.target.value)} className={inputClass} placeholder="Lima" />
              </div>
            </div>

            <p className={sectionClass}>Administrador de la empresa</p>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass}>Nombres *</label>
                <input type="text" required maxLength={100} value={formData.admin_first_name} onChange={(e) => set('admin_first_name', e.target.value)} className={inputClass} placeholder="Carlos" />
              </div>
              <div>
                <label className={labelClass}>Apellidos *</label>
                <input type="text" required maxLength={100} value={formData.admin_last_name} onChange={(e) => set('admin_last_name', e.target.value)} className={inputClass} placeholder="Pérez Gómez" />
              </div>
            </div>

            <div>
              <label className={labelClass}>Correo del administrador *</label>
              <input type="email" required maxLength={190} value={formData.admin_email} onChange={(e) => set('admin_email', e.target.value)} className={inputClass} placeholder="admin@empresa.com" />
              <span className="text-[10px] text-slate-400 mt-1 block">Será el dueño de la cuenta. Su contraseña inicial se genera y se muestra al crear.</span>
            </div>

            <p className={sectionClass}>Sistema</p>
          </>
        )}

        <div>
          <label className={labelClass}>
            Zona Horaria (Timezone) *
          </label>
          <input
            type="text"
            required
            value={formData.timezone}
            onChange={(e) => set('timezone', e.target.value)}
            className={inputClass}
            placeholder="Ej. America/Lima"
          />
        </div>

        <div>
          <label className={labelClass}>
            Estado
          </label>
          <Select
            ariaLabel="Estado"
            clearable={false}
            value={formData.status}
            onChange={(e) => set('status', String(e.target.value))}
            options={[{ value: 'active', label: 'Activo' }, { value: 'suspended', label: 'Suspendido' }, { value: 'inactive', label: 'Inactivo' }]}
          />
        </div>

        {error && (
          <p className="text-xs font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">{error}</p>
        )}

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
