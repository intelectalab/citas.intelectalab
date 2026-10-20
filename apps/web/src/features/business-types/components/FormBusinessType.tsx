'use client';

import { labelClass } from '@/app/components/ui/forms/styles';
import { useState, useEffect } from 'react';
import Select from '@/app/components/common/Select';
import { ChevronDown, Layers } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { MODULE_GROUPS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';
import Switch from '@/app/components/common/Switch';
import RulesEditor from './RulesEditor';
import type { RuleValue } from '@/lib/rules';
import TemplateEditor, { cleanTemplate, parseTemplate, templateProblems, type BusinessTemplate } from './TemplateEditor';

export interface BusinessType {
  id: string;
  slug: string;
  name: string;
  short_name: string;
  description?: string | null;
  icon?: string | null;
  color?: string | null;
  sort_order?: number;
  status: string;
  vocabulary?: Record<string, string>;
  modules_off?: string[];
  default_rules?: Record<string, string | number | boolean>;
  dependents?: { enabled?: boolean; kind?: string | null; required?: boolean };
  template?: any;
  tenants_count?: number;
  outdated_tenants_count?: number;
}

interface FormBusinessTypeProps {
  isOpen: boolean;
  onClose: () => void;
  initialData?: BusinessType | null;
  onSubmitSuccess: () => void;
}

const VOCABULARY_FIELDS: { key: string; label: string; placeholder: string }[] = [
  { key: 'client', label: 'Cliente (singular)', placeholder: 'Cliente' },
  { key: 'clients', label: 'Clientes (plural)', placeholder: 'Clientes' },
  { key: 'pro', label: 'Profesional (singular)', placeholder: 'Profesional' },
  { key: 'pros', label: 'Profesionales (plural)', placeholder: 'Profesionales' },
  { key: 'dependent', label: 'Dependiente (singular)', placeholder: 'Dependiente' },
  { key: 'dependents', label: 'Dependientes (plural)', placeholder: 'Dependientes' },
];

const EMPTY_FORM = {
  name: '',
  short_name: '',
  slug: '',
  description: '',
  color: '#3C7DC4',
  sort_order: 0,
  status: 'active',
  vocabulary: {} as Record<string, string>,
  modules_off: [] as string[],
  dependents_enabled: false,
  dependents_kind: '',
  dependents_required: false,
  rules: {} as Record<string, RuleValue>,
  template: { categories: [], record_template: null } as BusinessTemplate,
};

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none bg-white';

export default function FormBusinessType({ isOpen, onClose, initialData, onSubmitSuccess }: FormBusinessTypeProps) {
  const [form, setForm] = useState(EMPTY_FORM);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeAccordion, setActiveAccordion] = useState<number>(1);

  const sections = [
    { id: 1, title: '1. Datos del rubro' },
    { id: 2, title: '2. Vocabulario' },
    { id: 3, title: '3. Módulos del rubro' },
    { id: 4, title: '4. Dependientes' },
    { id: 5, title: '5. Reglas por defecto' },
    { id: 6, title: '6. Catálogo inicial' },
  ];

  useEffect(() => {
    setError(null);
    setActiveAccordion(1);

    if (initialData) {
      setForm({
        name: initialData.name || '',
        short_name: initialData.short_name || '',
        slug: initialData.slug || '',
        description: initialData.description || '',
        color: initialData.color || '#3C7DC4',
        sort_order: initialData.sort_order ?? 0,
        status: initialData.status || 'active',
        vocabulary: { ...(initialData.vocabulary ?? {}) },
        modules_off: [...(initialData.modules_off ?? [])],
        dependents_enabled: Boolean(initialData.dependents?.enabled),
        dependents_kind: initialData.dependents?.kind || '',
        dependents_required: Boolean(initialData.dependents?.required),
        rules: { ...(initialData.default_rules ?? {}) },
        template: parseTemplate(initialData.template),
      });
    } else {
      setForm(EMPTY_FORM);
    }
  }, [initialData, isOpen]);

  const toggleModule = (key: string) => {
    setForm((prev) => ({
      ...prev,
      modules_off: prev.modules_off.includes(key) ? prev.modules_off.filter((k) => k !== key) : [...prev.modules_off, key],
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const problems = templateProblems(form.template);
    if (problems.length > 0) {
      setActiveAccordion(6);
      setError(`Catálogo inicial: ${problems[0]}`);
      return;
    }

    const payload = {
      name: form.name,
      short_name: form.short_name,
      slug: form.slug || null,
      description: form.description || null,
      color: form.color || null,
      sort_order: Number(form.sort_order),
      status: form.status,
      vocabulary: form.vocabulary,
      modules_off: form.modules_off,
      dependents: {
        enabled: form.dependents_enabled,
        kind: form.dependents_kind || null,
        required: form.dependents_required,
      },
      default_rules: form.rules,
      template: cleanTemplate(form.template),
    };

    try {
      setLoading(true);
      if (initialData?.id) {
        await api.put(`/admin/business-types/${initialData.id}`, payload);
      } else {
        await api.post('/admin/business-types', payload);
      }
      onSubmitSuccess();
      onClose();
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? `${Object.keys(errors)[0]}: ${(Object.values(errors)[0] as string[])[0]}` : null;
      setError(first || err.response?.data?.message || 'No se pudo guardar el rubro.');
    } finally {
      setLoading(false);
    }
  };

  const isEditing = Boolean(initialData?.id);

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? `Editar plantilla: ${initialData?.name ?? ''}` : 'Nueva plantilla de rubro'}
      subtitle={isEditing ? `slug: ${initialData?.slug} · Define cómo se ve y con qué empieza una empresa de este rubro.` : 'Define cómo se ve y con qué empieza una empresa de este rubro.'}
      icon={<Layers size={20} />}
      maxWidth="max-w-2xl"
    >
      <form onSubmit={handleSubmit} className="flex flex-col h-full justify-between">
        <div className="divide-y divide-slate-200 pb-24 overflow-y-auto px-1">
          {isEditing && (initialData?.tenants_count ?? 0) > 0 && (
            <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg p-3 mb-2">
              {initialData?.tenants_count === 1 ? '1 empresa usa' : `${initialData?.tenants_count} empresas usan`} este rubro.
              Guardar no las cambia: conservan lo que recibieron. Para llevarles estos cambios, usa "Aplicar a las empresas" en la página de la plantilla.
            </p>
          )}

          {sections.map((sec) => {
            const isOpenSection = activeAccordion === sec.id;

            return (
              <div key={sec.id} className="bg-white transition-all duration-300">
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
                          <label className={labelClass}>Nombre *</label>
                          <input type="text" required maxLength={150} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} placeholder="Ej. Clínica dental" />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className={labelClass}>Nombre corto *</label>
                            <input type="text" required maxLength={80} value={form.short_name} onChange={(e) => setForm({ ...form, short_name: e.target.value })} className={inputClass} placeholder="Ej. Dental" />
                          </div>
                          <div>
                            <label className={labelClass}>Slug</label>
                            <input type="text" maxLength={50} value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} className={`${inputClass} font-mono`} placeholder="Se genera del nombre" />
                          </div>
                        </div>
                        <div>
                          <label className={labelClass}>Descripción</label>
                          <textarea rows={2} maxLength={500} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={inputClass} placeholder="Se muestra al elegir el rubro en el registro." />
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                          <div>
                            <label className={labelClass}>Color</label>
                            <input type="color" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} className="w-full h-[42px] border border-slate-200 rounded-lg bg-white px-1" />
                          </div>
                          <div>
                            <label className={labelClass}>Orden</label>
                            <input type="number" min="0" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })} className={inputClass} />
                          </div>
                          <div>
                            <label className={labelClass}>Estado</label>
                            <Select
                              ariaLabel="Estado"
                              clearable={false}
                              value={form.status}
                              onChange={(e) => setForm({ ...form, status: String(e.target.value) })}
                              options={[{ value: 'active', label: 'Activo' }, { value: 'inactive', label: 'Inactivo' }]}
                            />
                          </div>
                        </div>
                        <p className="text-xs text-slate-400">Un rubro inactivo no aparece en el registro de empresas, pero las que ya lo usan siguen igual.</p>
                      </div>
                    )}

                    {sec.id === 2 && (
                      <div className="space-y-4">
                        <p className="text-xs text-slate-500">Cómo se nombra al cliente y al profesional en el panel de las empresas de este rubro. Lo vacío usa el término base.</p>
                        <div className="grid grid-cols-2 gap-3">
                          {VOCABULARY_FIELDS.map((field) => (
                            <div key={field.key}>
                              <label className={labelClass}>{field.label}</label>
                              <input
                                type="text"
                                maxLength={40}
                                value={form.vocabulary[field.key] ?? ''}
                                onChange={(e) => setForm({ ...form, vocabulary: { ...form.vocabulary, [field.key]: e.target.value } })}
                                className={inputClass}
                                placeholder={field.placeholder}
                              />
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {sec.id === 3 && (
                      <div className="space-y-5">
                        <div className="flex items-start justify-between gap-4">
                          <p className="text-xs text-slate-500">
                            Lo que usa una empresa de este rubro. Un módulo encendido lo recibe si su plan lo incluye; uno apagado no le aparece aunque su plan lo tenga.
                          </p>
                          <span className="shrink-0 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-100 text-xs font-bold text-emerald-700">
                            {OPTIONAL_MODULE_KEYS.length - form.modules_off.length} de {OPTIONAL_MODULE_KEYS.length} encendidos
                          </span>
                        </div>

                        <div className="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-500">
                          <span className="w-2 h-2 rounded-full bg-emerald-500 shrink-0" />
                          <span><span className="font-semibold text-slate-700">Agenda y citas</span> y <span className="font-semibold text-slate-700">Clientes / CRM 360</span> son la base del producto y siempre están encendidos.</span>
                        </div>

                        {MODULE_GROUPS.map((group) => (
                          <div key={group.key} className="space-y-2">
                            <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400">{group.title}</span>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                              {group.items.map((item) => {
                                const isOn = !form.modules_off.includes(item.key);
                                return (
                                  <label
                                    key={item.key}
                                    className={`flex items-center justify-between gap-3 rounded-xl border px-3.5 py-3 cursor-pointer transition-colors ${
                                      isOn ? 'bg-white border-emerald-200 hover:border-emerald-300' : 'bg-slate-50 border-slate-200 hover:border-slate-300'
                                    }`}
                                  >
                                    <span className={`text-sm font-medium leading-snug ${isOn ? 'text-slate-800' : 'text-slate-400'}`}>{item.label}</span>
                                    <Switch checked={isOn} onChange={() => toggleModule(item.key)} label={`${item.label} en este rubro`} />
                                  </label>
                                );
                              })}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}

                    {sec.id === 4 && (
                      <div className="space-y-4">
                        <label className="flex items-start gap-2.5 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={form.dependents_enabled}
                            onChange={(e) => setForm({ ...form, dependents_enabled: e.target.checked })}
                            className="mt-0.5 rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
                          />
                          <span>
                            <span className="block text-sm font-medium text-slate-800">El cliente tiene dependientes</span>
                            <span className="block text-xs text-slate-400">Mascotas de un tutor, hijos de un titular. Agrega esa sección a la ficha del cliente.</span>
                          </span>
                        </label>
                        {form.dependents_enabled && (
                          <>
                            <div>
                              <label className={labelClass}>Tipo</label>
                              <Select
                                ariaLabel="Tipo de dependiente"
                                placeholder="Genérico"
                                value={form.dependents_kind}
                                onChange={(e) => setForm({ ...form, dependents_kind: String(e.target.value) })}
                                options={[{ value: 'pet', label: 'Mascota (pide especie y raza)' }, { value: 'child', label: 'Hijo o familiar' }]}
                              />
                            </div>
                            <label className="flex items-start gap-2.5 cursor-pointer">
                              <input
                                type="checkbox"
                                checked={form.dependents_required}
                                onChange={(e) => setForm({ ...form, dependents_required: e.target.checked })}
                                className="mt-0.5 rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
                              />
                              <span>
                                <span className="block text-sm font-medium text-slate-800">Obligatorio en la cita</span>
                                <span className="block text-xs text-slate-400">Cada cita es para un dependiente, no para el cliente.</span>
                              </span>
                            </label>
                          </>
                        )}
                      </div>
                    )}

                    {sec.id === 5 && (
                      <RulesEditor value={form.rules} onChange={(rules) => setForm((prev) => ({ ...prev, rules }))} />
                    )}

                    {sec.id === 6 && (
                      <div className="space-y-4">
                        <p className="text-xs text-slate-500">
                          Lo que recibe una empresa al registrarse con este rubro. Después cada empresa lo ajusta a su manera.
                        </p>
                        <TemplateEditor
                          value={form.template}
                          onChange={(template) => setForm((prev) => ({ ...prev, template }))}
                          allowGroupClasses={!form.modules_off.includes('classes')}
                        />
                      </div>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        <div className="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 p-4 flex items-center justify-end gap-3 z-10">
          {error && <span className="text-xs font-medium text-red-600 mr-auto">{error}</span>}
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
            {loading ? 'Guardando...' : isEditing ? 'Actualizar rubro' : 'Crear rubro'}
          </button>
        </div>
      </form>
    </FormDrawer>
  );
}
