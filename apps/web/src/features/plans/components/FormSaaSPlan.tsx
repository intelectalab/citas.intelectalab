'use client';

import { useState, useEffect, useRef } from 'react';
import { Package, ChevronDown, Check } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';

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
    clients?: number;
    services?: number;
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

// Objeto por defecto para limpiar el formulario cuando es un plan nuevo
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

  modules: {
    clients: false,
    services: false,
    agenda: false,
    finances: false,
    payments: false,
    electronic_billing: false,
    personal: false,
    support: false,
    pos: false,
    advanced_reports: false,
    mfa: false,
    api: false,
    custom_domain: false,
    crm: false,
    whatsapp_notifications: false,
    priority_support: false,
    documents: false,
    suppliers: false,
  } as Record<string, boolean>,

  limits: {
    users: 0,
    branches: 0,
    clients: 0,
    services: 0,
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
    core: false,
    finanzas: false,
    operaciones: false,
    portal: false,
    especializados: false,
  });

  const toggleModuleAccordion = (section: string) => {
    setOpenModules((prev) => ({ ...prev, [section]: !prev[section] }));
  };

  const [formData, setFormData] = useState(defaultFormState);

  // Solución: Limpiar o mapear según corresponda cada vez que se abra el modal o cambie initialData
  useEffect(() => {
    if (initialData && Object.keys(initialData).length > 0) {
      console.log("📥 Mapeando datos reales desde la BD:", initialData);

      const rawModules = initialData.modules || {};
      const flattenedModules: Record<string, boolean> = {
        clients: false, services: false, agenda: false, finances: false,
        payments: false, electronic_billing: false, personal: false, support: false,
        pos: false, advanced_reports: false, mfa: false, api: false,
        custom_domain: false, crm: false, whatsapp_notifications: false,
        priority_support: false, documents: false, suppliers: false,
      };

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
          clients: initialData.limits?.clients ?? 0,
          services: initialData.limits?.services ?? 0,
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
      // Si es un nuevo plan, reseteamos el formulario por completo
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

      const coreModules = Object.keys(formData.modules).filter(
        (k) => ['clients', 'services', 'agenda', 'documents'].includes(k) && formData.modules[k]
      );
      const financeModules = Object.keys(formData.modules).filter(
        (k) => ['finances', 'payments', 'electronic_billing'].includes(k) && formData.modules[k]
      );
      const operationsModules = Object.keys(formData.modules).filter(
        (k) => ['personal', 'suppliers', 'support'].includes(k) && formData.modules[k]
      );
      const crmModules = Object.keys(formData.modules).filter(
        (k) => ['crm'].includes(k) && formData.modules[k]
      );

      const modulesPayload: Record<string, string[]> = {};
      if (coreModules.length > 0) modulesPayload.core = coreModules;
      if (financeModules.length > 0) modulesPayload.finance = financeModules;
      if (operationsModules.length > 0) modulesPayload.operations = operationsModules;
      if (crmModules.length > 0) modulesPayload.crm = crmModules;

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
          clients: Number(formData.limits.clients),
          services: Number(formData.limits.services),
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
    { id: 4, title: '4. Límites del tenant' },
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
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Ej. Starter"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Código interno (code_internal) *</label>
                          <input
                            type="text"
                            required
                            value={formData.code_internal}
                            onChange={(e) => setFormData({ ...formData, code_internal: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Ej. STA"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Descripción</label>
                          <textarea
                            rows={3}
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Describe para qué tipo de empresa está pensado este plan."
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Estado</label>
                          <select
                            value={formData.status}
                            onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                          >
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                          </select>
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Orden de presentación</label>
                          <input
                            type="number"
                            value={formData.presentation_order}
                            onChange={(e) => setFormData({ ...formData, presentation_order: parseInt(e.target.value) || 0 })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Catálogo público (is_public_catalog)</label>
                          <select
                            value={formData.is_public_catalog ? 'yes' : 'no'}
                            onChange={(e) => setFormData({ ...formData, is_public_catalog: e.target.value === 'yes' })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                          >
                            <option value="yes">Sí</option>
                            <option value="no">No</option>
                          </select>
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
                                className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              />
                            </div>
                            <div>
                              <label className="block text-xs text-slate-600 mb-1">Anual</label>
                              <input
                                type="number"
                                step="0.01"
                                value={formData.price_yearly}
                                onChange={(e) => setFormData({ ...formData, price_yearly: parseFloat(e.target.value) || 0 })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              />
                            </div>
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Moneda</label>
                            <select
                              value={formData.currency}
                              onChange={(e) => setFormData({ ...formData, currency: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="PEN">PEN - Sol peruano</option>
                              <option value="USD">USD - Dólar estadounidense</option>
                            </select>
                          </div>
                        </div>

                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Prueba gratuita (trial_config)</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">¿Tiene prueba?</label>
                            <select
                              value={formData.has_trial ? 'yes' : 'no'}
                              onChange={(e) => setFormData({ ...formData, has_trial: e.target.value === 'yes' })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="yes">Sí</option>
                              <option value="no">No</option>
                            </select>
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Días de prueba</label>
                            <input
                              type="number"
                              disabled={!formData.has_trial}
                              value={formData.trial_days}
                              onChange={(e) => setFormData({ ...formData, trial_days: parseInt(e.target.value) || 0 })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 disabled:opacity-50 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">¿Solicitar pago al iniciar?</label>
                            <select
                              value={formData.require_payment_at_start ? 'yes' : 'no'}
                              onChange={(e) => setFormData({ ...formData, require_payment_at_start: e.target.value === 'yes' })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="yes">Sí</option>
                              <option value="no">No</option>
                            </select>
                          </div>
                        </div>

                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Suscripción (subscription_config)</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Renovación automática (auto_renew)</label>
                            <select
                              value={formData.auto_renew ? 'yes' : 'no'}
                              onChange={(e) => setFormData({ ...formData, auto_renew: e.target.value === 'yes' })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="yes">Sí</option>
                              <option value="no">No</option>
                            </select>
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Periodo predeterminado</label>
                            <select
                              value={formData.default_period}
                              onChange={(e) => setFormData({ ...formData, default_period: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="monthly">Mensual</option>
                              <option value="yearly">Anual</option>
                            </select>
                          </div>
                        </div>
                      </div>
                    )}

                    {sec.id === 3 && (
                      <div className="space-y-3">
                        {[
                          {
                            key: 'core',
                            title: 'Core',
                            items: [
                              { key: 'clients', label: 'Clientes (clients)' },
                              { key: 'services', label: 'Servicios (services)' },
                              { key: 'agenda', label: 'Agenda (agenda)' },
                              { key: 'documents', label: 'Documentos (documents)' },
                            ],
                          },
                          {
                            key: 'finanzas',
                            title: 'Finanzas y facturación',
                            items: [
                              { key: 'finances', label: 'Finanzas (finances)' },
                              { key: 'payments', label: 'Pagos (payments)' },
                              { key: 'electronic_billing', label: 'Facturación Electrónica (electronic_billing)' },
                            ],
                          },
                          {
                            key: 'operaciones',
                            title: 'Operaciones y servicio',
                            items: [
                              { key: 'personal', label: 'Personal (personal)' },
                              { key: 'suppliers', label: 'Proveedores (suppliers)' },
                              { key: 'support', label: 'Soporte (support)' },
                            ],
                          },
                          {
                            key: 'especializados',
                            title: 'Especializados y CRM',
                            items: [
                              { key: 'crm', label: 'CRM (crm)' },
                              { key: 'pos', label: 'POS (pos)' },
                              { key: 'advanced_reports', label: 'Reportes avanzados (advanced_reports)' },
                            ],
                          },
                        ].map((group) => (
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
                              <div className="p-3 bg-white border-t border-slate-200 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                {group.items.map((mod) => {
                                  const isSelected = formData.modules[mod.key];
                                  return (
                                    <div
                                      key={mod.key}
                                      onClick={() => handleModuleChange(mod.key)}
                                      className={`flex items-center justify-between p-3 rounded-xl border transition-all duration-200 cursor-pointer ${
                                        isSelected
                                          ? 'border-[#030336] bg-[#030336]/10 text-[#030336] shadow-sm'
                                          : 'border-slate-200 bg-white hover:border-slate-300 text-slate-700'
                                      }`}
                                    >
                                      <span className="text-xs font-semibold">{mod.label}</span>
                                      <div className={`w-5 h-5 rounded-lg flex items-center justify-center transition-all ${
                                        isSelected ? 'bg-[#030336] text-white' : 'border border-slate-300 bg-white'
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
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Límites del tenant (limits)</h5>
                          <p className="text-[11px] text-slate-500">Usa -1 para indicar valores ilimitados.</p>
                          {[
                            { key: 'users', label: 'Usuarios (users)' },
                            { key: 'branches', label: 'Sucursales (branches)' },
                            { key: 'clients', label: 'Clientes (clients)' },
                            { key: 'services', label: 'Servicios (services)' },
                          ].map((limit) => (
                            <div key={limit.key}>
                              <label className="block text-xs text-slate-600 mb-1">{limit.label}</label>
                              <input
                                type="number"
                                value={formData.limits[limit.key as keyof typeof formData.limits]}
                                onChange={(e) =>
                                  setFormData({
                                    ...formData,
                                    limits: { ...formData.limits, [limit.key]: parseInt(e.target.value) || 0 },
                                  })
                                }
                                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              />
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {sec.id === 5 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Consumo y almacenamiento (consumption)</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Almacenamiento GB (storage_gb)</label>
                            <input
                              type="number"
                              value={formData.consumption.storage_gb}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  consumption: { ...formData.consumption, storage_gb: parseInt(e.target.value) || 0 },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Solicitudes API mensuales (api_requests_monthly)</label>
                            <input
                              type="number"
                              value={formData.consumption.api_requests_monthly}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  consumption: { ...formData.consumption, api_requests_monthly: parseInt(e.target.value) || 0 },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            />
                          </div>
                        </div>
                      </div>
                    )}

                    {sec.id === 6 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Configuración de soporte (support)</h5>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Nivel de soporte (level)</label>
                            <input
                              type="text"
                              value={formData.support.level}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  support: { ...formData.support, level: e.target.value },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              placeholder="Ej. Estándar"
                            />
                          </div>
                          <div>
                            <label className="block text-xs text-slate-600 mb-1">Tickets mensuales (tickets_monthly)</label>
                            <input
                              type="number"
                              value={formData.support.tickets_monthly}
                              onChange={(e) =>
                                setFormData({
                                  ...formData,
                                  support: { ...formData.support, tickets_monthly: parseInt(e.target.value) || 0 },
                                })
                              }
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            />
                          </div>
                        </div>
                      </div>
                    )}

                    {sec.id === 7 && (
                      <div className="space-y-4">
                        <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl space-y-3">
                          <h5 className="text-xs font-bold uppercase tracking-wider text-slate-700">Características (features)</h5>
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
                                      ? 'border-[#030336] bg-[#030336]/10 text-[#030336] shadow-sm'
                                      : 'border-slate-200 bg-white hover:border-slate-300 text-slate-700'
                                  }`}
                                >
                                  <span className="text-xs font-semibold uppercase">{featureKey.replace(/_/g, ' ')}</span>
                                  <div className={`w-5 h-5 rounded-lg flex items-center justify-center transition-all ${
                                    isChecked ? 'bg-[#030336] text-white' : 'border border-slate-300 bg-white'
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
            className="px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-white bg-[#030336] hover:bg-[#030336]/90 rounded-lg transition-colors shadow-sm disabled:opacity-50"
          >
            {loading ? 'Guardando...' : isEditing ? 'Actualizar plan' : 'Crear plan'}
          </button>
        </div>
      </form>
    </FormDrawer>
  );
}