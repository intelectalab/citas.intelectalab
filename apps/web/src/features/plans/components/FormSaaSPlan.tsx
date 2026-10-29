'use client';

import { useState, useEffect, useRef } from 'react';
import Select from '@/app/components/common/Select';
import { Package, ChevronDown, Check } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { MODULE_GROUPS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';
import InfoTip from '@/app/components/common/InfoTip';

interface SaaSPlan {
  id?: string;
  name?: string;
  slug?: string;
  code_internal?: string;
  description?: string;
  price_monthly?: number;
  price_yearly?: number;
  currency?: string;
  status?: string;
  presentation_order?: number;
  is_public_catalog?: boolean;
  trial_config?: {
    has_trial?: boolean;
    trial_days?: number;
    require_payment_at_start?: boolean;
  };
  subscription_config?: {
    auto_renew?: boolean;
    default_period?: string;
  };
  modules?: Record<string, any>;
  limits?: {
    users?: number;
    branches?: number;
    professionals?: number;
    appointments_month?: number;
    whatsapp_month?: number;
    [key: string]: number | undefined;
  };
  consumption?: {
    storage_gb?: number;
    api_requests_monthly?: number;
    [key: string]: number | undefined;
  };
  support?: {
    level?: string;
    tickets_monthly?: number;
    [key: string]: any;
  };
  features?: Record<string, boolean>;
}

interface FormSaaSPlanProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: any) => Promise<void>;
  initialData?: SaaSPlan | null;
  totalPlansCount?: number;
}

const defaultFormState = {
  name: '',
  code_internal: '',
  description: '',
  status: 'active',
  presentation_order: 1,
  is_public_catalog: true,

  price_monthly: 0,
  price_yearly: 0,
  currency: 'PEN',
  has_trial: false,
  trial_days: 0,
  require_payment_at_start: false,
  auto_renew: true,
  default_period: 'monthly',

  modules: Object.fromEntries(OPTIONAL_MODULE_KEYS.map((key) => [key, false])) as Record<string, boolean>,

  limits: {
    users: 0,
    branches: 0,
    professionals: 0,
    appointments_month: 0,
    whatsapp_month: 0,
  },

  consumption: {
    storage_gb: 0,
    api_requests_monthly: 0,
  },

  support: {
    level: '',
    tickets_monthly: 0,
  },

  features: {
    multi_tenant: false,
    roles_and_permissions: false,
    audit: false,
    pos: false,
    advanced_reports: false,
    mfa: false,
    api: false,
    custom_domain: false,
    whatsapp_notifications: false,
    priority_support: false,
  } as Record<string, boolean>,
};

export default function FormSaaSPlan({
  isOpen,
  onClose,
  onSubmit,
  initialData,
  totalPlansCount = 0,
}: FormSaaSPlanProps) {
  const [activeAccordion, setActiveAccordion] = useState<number>(1);
  const [loading, setLoading] = useState(false);

  const sectionRefs = useRef<Record<number, HTMLDivElement | null>>({});

  const [openModules, setOpenModules] = useState<Record<string, boolean>>({
    agenda: true,
    care: true,
    catalog: true,
    sales: true,
    loyalty: true,
    company: true,
    platform: true,
  });

  const toggleModuleAccordion = (section: string) => {
    setOpenModules((prev) => ({ ...prev, [section]: !prev[section] }));
  };

  const [formData, setFormData] = useState(defaultFormState);
  const [currencies, setCurrencies] = useState<{ code: string; name: string; symbol: string; is_active: boolean }[]>([]);

  useEffect(() => {
    if (!isOpen) return;
    api.get('/admin/currencies').then((res) => setCurrencies(res.data.currencies ?? [])).catch(() => setCurrencies([]));
  }, [isOpen]);

  const currencyOptions = currencies
    .filter((c) => c.is_active || c.code === formData.currency)
    .map((c) => ({ value: c.code, label: `${c.symbol} · ${c.name} (${c.code})` }));

  useEffect(() => {
    if (initialData && Object.keys(initialData).length > 0) {
      console.log("📥 Mapeando datos reales desde la BD:", initialData);

      const rawModules = initialData.modules || {};
      const flattenedModules: Record<string, boolean> = Object.fromEntries(
        OPTIONAL_MODULE_KEYS.map((key) => [key, false])
      );

      Object.values(rawModules).forEach((val) => {
        if (Array.isArray(val)) {
          val.forEach((modName) => {
            if (modName in flattenedModules) flattenedModules[modName] = true;
          });
        }
      });

      const rawFeatures = initialData.features || {};
      const mappedFeatures = {
        multi_tenant: Boolean(rawFeatures.multi_tenant),
        roles_and_permissions: Boolean(rawFeatures.roles_and_permissions),
        audit: Boolean(rawFeatures.audit),
        pos: Boolean(rawFeatures.pos),
        advanced_reports: Boolean(rawFeatures.advanced_reports),
        mfa: Boolean(rawFeatures.mfa),
        api: Boolean(rawFeatures.api),
        custom_domain: Boolean(rawFeatures.custom_domain),
        whatsapp_notifications: Boolean(rawFeatures.whatsapp_notifications),
        priority_support: Boolean(rawFeatures.priority_support),
      };

      setFormData({
        name: initialData.name || '',
        code_internal: initialData.code_internal || '',
        description: initialData.description || '',
        status: initialData.status || 'active',
        presentation_order: initialData.presentation_order ?? 1,
        is_public_catalog: initialData.is_public_catalog ?? true,
        price_monthly: initialData.price_monthly ?? 0,
        price_yearly: initialData.price_yearly ?? 0,
        currency: initialData.currency || 'PEN',
        
        has_trial: initialData.trial_config?.has_trial ?? false,
        trial_days: initialData.trial_config?.trial_days ?? 0,
        require_payment_at_start: initialData.trial_config?.require_payment_at_start ?? false,
        
        auto_renew: initialData.subscription_config?.auto_renew ?? true,
        default_period: initialData.subscription_config?.default_period || 'monthly',

        modules: flattenedModules,

        limits: {
          users: initialData.limits?.users ?? 0,
          branches: initialData.limits?.branches ?? 0,
          professionals: initialData.limits?.professionals ?? 0,
          appointments_month: initialData.limits?.appointments_month ?? 0,
          whatsapp_month: initialData.limits?.whatsapp_month ?? 0,
        },

        consumption: {
          storage_gb: initialData.consumption?.storage_gb ?? 0,
          api_requests_monthly: initialData.consumption?.api_requests_monthly ?? 0,
        },

        support: {
          level: initialData.support?.level || '',
          tickets_monthly: initialData.support?.tickets_monthly ?? 0,
        },

        features: mappedFeatures,
      });
    } else {
      setFormData(defaultFormState);
    }
  }, [initialData, isOpen]);

  const handleModuleChange = (key: string) => {
    setFormData((prev) => ({
      ...prev,
      modules: { ...prev.modules, [key]: !prev.modules[key] },
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setLoading(true);
      const generatedSlug = formData.name
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-');

      const modulesPayload: Record<string, string[]> = {};
      MODULE_GROUPS.forEach((group) => {
        const selected = group.items.map((item) => item.key).filter((key) => formData.modules[key]);
        if (selected.length > 0) modulesPayload[group.key] = selected;
      });

      const payload = {
        name: formData.name,
        slug: generatedSlug,
        code_internal: formData.code_internal,
        description: formData.description,
        status: formData.status,
        presentation_order: Number(formData.presentation_order),
        is_public_catalog: formData.is_public_catalog,
        price_monthly: Number(formData.price_monthly),
        price_yearly: Number(formData.price_yearly),
        currency: formData.currency,
        trial_config: {
          has_trial: formData.has_trial,
          trial_days: Number(formData.trial_days),
          require_payment_at_start: formData.require_payment_at_start,
        },
        subscription_config: {
          auto_renew: formData.auto_renew,
          default_period: formData.default_period,
        },
        modules: modulesPayload,
        limits: {
          users: Number(formData.limits.users),
          branches: Number(formData.limits.branches),
          professionals: Number(formData.limits.professionals),
          appointments_month: Number(formData.limits.appointments_month),
          whatsapp_month: Number(formData.limits.whatsapp_month),
        },
        consumption: {
          storage_gb: Number(formData.consumption.storage_gb),
          api_requests_monthly: Number(formData.consumption.api_requests_monthly),
        },
        support: {
          level: formData.support.level,
          tickets_monthly: Number(formData.support.tickets_monthly),
        },
        features: formData.features,
      };

      await onSubmit(payload);
    } catch (error) {
      console.error('Error al guardar plan SaaS:', error);
    } finally {
      setLoading(false);
    }
  };

  const isEditing = Boolean(initialData);

  const sections = [
    { id: 1, title: '1. Información general' },
    { id: 2, title: '2. Precio y suscripción' },
    { id: 3, title: '3. Módulos del sistema' },
    { id: 4, title: '4. Límites de la empresa' },
    { id: 5, title: '5. Consumo SaaS' },
    { id: 6, title: '6. Soporte y canales' },
    { id: 7, title: '7. Características' },
  ];

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? 'Editar Plan SaaS' : 'Nuevo plan SaaS'}
      subtitle="Define qué puede contratar y utilizar cada empresa."
      icon={<Package size={20} />}
      maxWidth="max-w-3xl"
    >
      <form onSubmit={handleSubmit} className="flex flex-col h-full justify-between">
        <div className="divide-y divide-slate-200 pb-24 overflow-y-auto px-1">
          {sections.map((sec) => {
            const isOpenSection = activeAccordion === sec.id;

            return (
              <div
                key={sec.id}
                ref={(el) => {
                  sectionRefs.current[sec.id] = el;
                }}
                className="bg-white transition-all duration-300"
              >
                <button
                  type="button"
                  onClick={() => setActiveAccordion(isOpenSection ? 0 : sec.id)}
                  className="w-full flex items-center justify-between py-4 px-2 bg-white hover:bg-slate-50/85 transition-colors text-left"
                >
                  <span className="text-xs font-bold uppercase tracking-wider text-slate-800">{sec.title}</span>
                  <div className={`p-1 rounded-full bg-slate-100 text-slate-600 transition-transform duration-200 ${isOpenSection ? 'rotate-180' : ''}`}>
                    <ChevronDown size={14} />
                  </div>
                </button>

                <div
                  className={`grid transition-all duration-300 ease-in-out overflow-hidden ${
                    isOpenSection ? 'grid-rows-[1fr] opacity-100 pb-6 pt-2 px-2' : 'grid-rows-[0fr] opacity-0'
                  }`}
                >
                  <div className="overflow-hidden space-y-4">
                    {sec.id === 1 && (
                      <div className="space-y-4">
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombre del plan *</label>
                          <input
                            type="text"
                            required
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            placeholder="Ej. Starter"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Código interno *</label>
                          <input
                            type="text"
                            required
                            value={formData.code_internal}
                            onChange={(e) => setFormData({ ...formData, code_internal: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            placeholder="Ej. STA"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Descripción</label>
                          <textarea
                            rows={3}
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            placeholder="Describe para qué tipo de empresa está pensado este plan."
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Estado</label>
                          <Select
                            ariaLabel="Estado"
                            clearable={false}
                            value={formData.status}
                            onChange={(e) => setFormData({ ...formData, status: String(e.target.value) })}
                            options={[{ value: 'active', label: 'Activo' }, { value: 'inactive', label: 'Inactivo' }]}
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Orden de presentación</label>
                          <input
                            type="number"
                            value={formData.presentation_order}
                            onChange={(e) => setFormData({ ...formData, presentation_order: parseInt(e.target.value) || 0 })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Catálogo público</label>
                          <Select
                            ariaLabel="Visible en el catálogo"
                            clearable={false}
                            value={formData.is_public_catalog ? 'yes' : 'no'}
                            onChange={(e) => setFormData({ ...formData, is_public_catalog: e.target.value === 'yes' })}
                            options={[{ value: 'yes', label: 'Sí' }, { value: 'no', label: 'No' }]}
                          />
                        </div>
                      </div>
                    )}

                    {sec.id === 2 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Precios</h5>
                          <div className="grid grid-cols-2 gap-3">
                            <div>
                              <label className="block text-xs text-slate-600 mb-1">Mensual</label>
                              <input
                                type="number"
                                step="0.01"
                                value={formData.price_monthly}
                                onChange={(e) => setFormData({ ...formData, price_monthly: parseFloat(e.target.value) || 0 })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              />
                            </div>
                            <div>
                              <label className="block text-xs text-slate-600 mb-1">Anual</label>
                              <input
                                type="number"
                                step="0.01"
                                value={formData.price_yearly}
                                onChange={(e) => setFormData({ ...formData, price_yearly: parseFloat(e.target.value) || 0 })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              />
                            </div>
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Moneda</label>
                            <Select
                              ariaLabel="Moneda"
                              clearable={false}
                              value={formData.currency}
                              onChange={(e) => setFormData({ ...formData, currency: String(e.target.value) })}
                              options={currencyOptions.length ? currencyOptions : [{ value: 'PEN', label: 'S/ · Sol peruano (PEN)' }]}
                            />
                          </div>
                        </div>

                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Prueba gratuita</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">¿Tiene prueba?</label>
                            <Select
                              ariaLabel="Tiene prueba"
                              clearable={false}
                              value={formData.has_trial ? 'yes' : 'no'}
                              onChange={(e) => setFormData({ ...formData, has_trial: e.target.value === 'yes' })}
                              options={[{ value: 'yes', label: 'Sí' }, { value: 'no', label: 'No' }]}
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Días de prueba</label>
                            <input
                              type="number"
                              disabled={!formData.has_trial}
                              value={formData.trial_days}
                              onChange={(e) => setFormData({ ...formData, trial_days: parseInt(e.target.value) || 0 })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 disabled:opacity-50 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">¿Solicitar pago al iniciar?</label>
                            <Select
                              ariaLabel="Pide pago al inicio"
                              clearable={false}
                              value={formData.require_payment_at_start ? 'yes' : 'no'}
                              onChange={(e) => setFormData({ ...formData, require_payment_at_start: e.target.value === 'yes' })}
                              options={[{ value: 'yes', label: 'Sí' }, { value: 'no', label: 'No' }]}
                            />
                          </div>
                        </div>

                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Suscripción</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Renovación automática</label>
                            <Select
                              ariaLabel="Renovación automática"
                              clearable={false}
                              value={formData.auto_renew ? 'yes' : 'no'}
                              onChange={(e) => setFormData({ ...formData, auto_renew: e.target.value === 'yes' })}
                              options={[{ value: 'yes', label: 'Sí' }, { value: 'no', label: 'No' }]}
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Periodo predeterminado</label>
                            <Select
                              ariaLabel="Periodo por defecto"
                              clearable={false}
                              value={formData.default_period}
                              onChange={(e) => setFormData({ ...formData, default_period: String(e.target.value) })}
                              options={[{ value: 'monthly', label: 'Mensual' }, { value: 'yearly', label: 'Anual' }]}
                            />
                          </div>
                        </div>
                      </div>
                    )}

                    {sec.id === 3 && (
                      <div className="space-y-3">
                        <p className="text-xs text-slate-500">Están agrupados como en el menú de la empresa. Agenda y citas, y Clientes, vienen en todos los planes.</p>
                        {MODULE_GROUPS.map((group) => (
                          <div key={group.key} className="border border-slate-200 rounded-xl bg-slate-50/50 overflow-hidden">
                            <button
                              type="button"
                              onClick={() => toggleModuleAccordion(group.key)}
                              className="w-full flex items-center justify-between p-3.5 text-xs font-bold uppercase tracking-wider text-slate-700 hover:bg-slate-100/80 transition-colors"
                            >
                              <span>{group.title}</span>
                              <div className={`p-1 rounded-full bg-white text-slate-600 transition-transform duration-200 ${openModules[group.key] ? 'rotate-180' : ''}`}>
                                <ChevronDown size={14} />
                              </div>
                            </button>
                            
                            {openModules[group.key] && (
                              <div className="p-3 bg-white border-t border-slate-200 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                {group.items.map((mod) => {
                                  const isSelected = formData.modules[mod.key];
                                  return (
                                    <div
                                      key={mod.key}
                                      data-plan-module={mod.key}
                                      onClick={() => handleModuleChange(mod.key)}
                                      className={`flex items-center justify-between gap-2 p-3 rounded-xl border transition-all duration-200 cursor-pointer ${
                                        isSelected
                                          ? 'border-brand bg-brand/10 text-brand-ink shadow-sm'
                                          : 'border-slate-200 bg-white hover:border-slate-300 text-slate-700'
                                      }`}
                                    >
                                      <span className="flex items-center gap-1.5 min-w-0">
                                        <span className="text-xs font-semibold">{mod.label}</span>
                                        <InfoTip text={mod.hint} title={mod.label} label={`Qué permite ${mod.label}`} />
                                      </span>
                                      <div className={`w-5 h-5 shrink-0 rounded-lg flex items-center justify-center transition-all ${
                                        isSelected ? 'bg-brand text-white' : 'border border-slate-300 bg-white'
                                      }`}>
                                        {isSelected && <Check size={12} strokeWidth={3} />}
                                      </div>
                                    </div>
                                  );
                                })}
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    )}

                    {sec.id === 4 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Límites de la empresa</h5>
                          <p className="text-[11px] text-slate-500">Usa -1 para indicar valores ilimitados.</p>
                          {[
                            { key: 'users', label: 'Usuarios', hint: 'Todas las cuentas del personal, incluidos los profesionales. El dueño no cuenta.' },
                            { key: 'branches', label: 'Sedes' },
                            { key: 'professionals', label: 'Profesionales', hint: 'Los que atienden citas. Cada uno gasta un usuario.' },
                            { key: 'appointments_month', label: 'Citas al mes' },
                            { key: 'whatsapp_month', label: 'Mensajes de WhatsApp al mes' },
                          ].map((limit) => (
                            <div key={limit.key}>
                              <label className="block text-xs text-slate-600 mb-1">{limit.label}</label>
                              {limit.hint && <p className="text-[11px] text-slate-500 -mt-0.5 mb-1">{limit.hint}</p>}
                              <input
                                type="number"
                                value={formData.limits[limit.key as keyof typeof formData.limits]}
                                onChange={(e) =>
                                  setFormData({
                                    ...formData,
                                    limits: { ...formData.limits, [limit.key]: parseInt(e.target.value) || 0 },
                                  })
                                }
                                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              />
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {sec.id === 5 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Consumo y almacenamiento</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Almacenamiento GB</label>
                            <input
                              type="number"
                              value={formData.consumption.storage_gb}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  consumption: { ...formData.consumption, storage_gb: parseInt(e.target.value) || 0 },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Solicitudes API mensuales</label>
                            <input
                              type="number"
                              value={formData.consumption.api_requests_monthly}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  consumption: { ...formData.consumption, api_requests_monthly: parseInt(e.target.value) || 0 },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            />
                          </div>
                        </div>
                      </div>
                    )}

                    {sec.id === 6 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Configuración de soporte</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Nivel de soporte</label>
                            <input
                              type="text"
                              value={formData.support.level}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  support: { ...formData.support, level: e.target.value },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              placeholder="Ej. Estándar"
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Tickets mensuales</label>
                            <input
                              type="number"
                              value={formData.support.tickets_monthly}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  support: { ...formData.support, tickets_monthly: parseInt(e.target.value) || 0 },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            />
                          </div>
                        </div>
                      </div>
                    )}

                    {sec.id === 7 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Características</h5>
                          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            {Object.keys(formData.features).map((featureKey) => {
                              const isChecked = formData.features[featureKey as keyof typeof formData.features];
                              return (
                                <div
                                  key={featureKey}
                                  onClick={() =>
                                    setFormData({
                                      ...formData,
                                      features: {
                                        ...formData.features,
                                        [featureKey]: !isChecked,
                                      },
                                    })
                                  }
                                  className={`flex items-center justify-between p-3 rounded-xl border transition-all duration-200 cursor-pointer ${
                                    isChecked
                                      ? 'border-brand bg-brand/10 text-brand-ink shadow-sm'
                                      : 'border-slate-200 bg-white hover:border-slate-300 text-slate-700'
                                  }`}
                                >
                                  <span className="text-xs font-semibold uppercase">{featureKey.replace(/_/g, ' ')}</span>
                                  <div className={`w-5 h-5 rounded-lg flex items-center justify-center transition-all ${
                                    isChecked ? 'bg-brand text-white' : 'border border-slate-300 bg-white'
                                  }`}>
                                    {isChecked && <Check size={12} strokeWidth={3} />}
                                  </div>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        <div className="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 p-4 flex items-center justify-end gap-3 z-10">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors"
          >
            Cancelar
          </button>
          <button
            type="submit"
            disabled={loading}
            className="px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-white bg-brand hover:bg-brand/90 rounded-lg transition-colors shadow-sm disabled:opacity-50"
          >
            {loading ? 'Guardando...' : isEditing ? 'Actualizar plan' : 'Crear plan'}
          </button>
        </div>
      </form>
    </FormDrawer>
  );
}