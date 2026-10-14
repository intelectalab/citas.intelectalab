'use client';

import { useState } from 'react';
import Select from '@/app/components/common/Select';
import {
  ArrowDown, ArrowUp, ChevronDown, ClipboardList, Clock, FolderOpen, Plus, Trash2,
  Globe, HandCoins, Video, FileText, Users, CalendarClock
} from 'lucide-react';

export interface TemplateService {
  code?: string | null;
  name: string;
  duration_minutes: number;
  price: number;
  buffer_after_minutes?: number;
  uses_record?: boolean;
  requires_deposit?: boolean;
  online_booking?: boolean;
  is_virtual?: boolean;
  kind?: 'individual' | 'group';
  capacity?: number;
  recall?: { label: string; days: number | ''; service_code?: string } | null;
  [key: string]: any;
}

export interface TemplateCategory {
  name: string;
  services: TemplateService[];
  [key: string]: any;
}

export interface TemplateField {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  options?: string[];
  placeholder?: string;
  days?: number | '';
  [key: string]: any;
}

export interface TemplateRecord {
  name: string;
  consent_text?: string | null;
  require_consent?: boolean;
  fields: TemplateField[];
  [key: string]: any;
}

export interface BusinessTemplate {
  categories: TemplateCategory[];
  record_template: TemplateRecord | null;
}

const FIELD_TYPES: { value: string; label: string }[] = [
  { value: 'text', label: 'Texto corto' },
  { value: 'textarea', label: 'Texto largo' },
  { value: 'number', label: 'Número' },
  { value: 'select', label: 'Lista (una opción)' },
  { value: 'multiselect', label: 'Lista (varias opciones)' },
  { value: 'boolean', label: 'Sí / No' },
  { value: 'date', label: 'Fecha' },
  { value: 'scale', label: 'Escala 0 a 10' },
  { value: 'odontogram', label: 'Odontograma' },
  { value: 'next_control', label: 'Próximo control' },
];

const FIELD_TYPE_LABELS = Object.fromEntries(FIELD_TYPES.map((type) => [type.value, type.label]));

const NEW_SERVICE: TemplateService = {
  code: '',
  name: '',
  duration_minutes: 30,
  price: 0,
  buffer_after_minutes: 0,
  uses_record: false,
  requires_deposit: false,
  online_booking: true,
  is_virtual: false,
  kind: 'individual',
  capacity: 1,
  recall: null,
};

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none bg-white';
const labelClass = 'block text-[11px] font-semibold uppercase tracking-wider text-slate-600 mb-1';
const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-30 disabled:hover:bg-transparent';
const addLink = 'inline-flex items-center gap-1 text-xs font-bold text-sky-600 hover:text-sky-700 transition-colors';

const fieldKeyFrom = (label: string, taken: string[]): string => {
  const base = label
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 50) || 'campo';

  let key = base;
  let n = 2;
  while (taken.includes(key)) key = `${base}_${n++}`;
  return key;
};

export function templateProblems(template: BusinessTemplate): string[] {
  const problems: string[] = [];
  const names = template.categories.map((category) => category.name.trim().toLowerCase());

  template.categories.forEach((category, index) => {
    const label = category.name.trim() || `la categoría ${index + 1}`;
    if (!category.name.trim()) problems.push(`Ponle nombre a la categoría ${index + 1}.`);
    if (category.name.trim() && names.indexOf(category.name.trim().toLowerCase()) !== index) problems.push(`Hay dos categorías llamadas "${category.name.trim()}".`);

    category.services.forEach((service, serviceIndex) => {
      if (!service.name.trim()) problems.push(`Ponle nombre al servicio ${serviceIndex + 1} de ${label}.`);
      if (!(Number(service.duration_minutes) >= 1)) problems.push(`"${service.name || 'Servicio'}" necesita una duración de al menos 1 minuto.`);
      if (service.recall && Boolean(service.recall.label?.trim()) !== (Number(service.recall.days) >= 1)) {
        problems.push(`El control de "${service.name || 'servicio'}" necesita nombre y días.`);
      }
    });
  });

  if (template.record_template) {
    if (!template.record_template.name.trim()) problems.push('Ponle nombre a la ficha de atención.');
    template.record_template.fields.forEach((field, index) => {
      if (!field.label.trim()) problems.push(`Ponle nombre al campo ${index + 1} de la ficha.`);
      if (['select', 'multiselect'].includes(field.type) && !(field.options ?? []).some((option) => option.trim())) {
        problems.push(`El campo "${field.label || index + 1}" es una lista y no tiene opciones.`);
      }
    });
  }

  return problems;
}

export function cleanTemplate(template: BusinessTemplate): Record<string, any> {
  const categories = template.categories.map((category) => ({
    ...category,
    name: category.name.trim(),
    services: category.services.map((service) => {
      const { recall, ...rest } = service;
      const isGroup = service.kind === 'group';
      const cleaned: Record<string, any> = {
        ...rest,
        code: service.code?.trim() || null,
        name: service.name.trim(),
        duration_minutes: Number(service.duration_minutes),
        price: Number(service.price) || 0,
        buffer_after_minutes: Number(service.buffer_after_minutes) || 0,
        kind: isGroup ? 'group' : 'individual',
        capacity: isGroup ? Math.max(1, Number(service.capacity) || 1) : 1,
      };

      if (recall?.label?.trim() && Number(recall.days) >= 1) {
        cleaned.recall = { label: recall.label.trim(), days: Number(recall.days) };
        if (recall.service_code) cleaned.recall.service_code = recall.service_code;
      }

      return cleaned;
    }),
  }));

  const result: Record<string, any> = {};
  if (categories.length > 0) result.categories = categories;

  if (template.record_template) {
    const record = template.record_template;
    result.record_template = {
      ...record,
      name: record.name.trim(),
      consent_text: record.consent_text?.trim() || null,
      require_consent: Boolean(record.require_consent),
      fields: record.fields.map((field, index) => {
        const { options, days, ...rest } = field;
        const takenKeys = record.fields.filter((_, i) => i !== index).map((other) => other.key).filter(Boolean);
        const cleaned: Record<string, any> = {
          ...rest,
          key: field.key || fieldKeyFrom(field.label, takenKeys),
          label: field.label.trim(),
          required: Boolean(field.required),
        };
        if (['select', 'multiselect'].includes(field.type)) {
          cleaned.options = (options ?? []).map((option) => option.trim()).filter(Boolean);
        }
        if (field.type === 'next_control' && Number(days) >= 1) cleaned.days = Number(days);
        return cleaned;
      }),
    };
  }

  return result;
}

export function parseTemplate(raw: any): BusinessTemplate {
  return {
    categories: (raw?.categories ?? []).map((category: any) => ({ ...category, name: category.name ?? '', services: category.services ?? [] })),
    record_template: raw?.record_template
      ? { ...raw.record_template, name: raw.record_template.name ?? '', fields: raw.record_template.fields ?? [] }
      : null,
  };
}

function Toggle({ icon, label, checked, onChange }: { icon: React.ReactNode; label: string; checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <button
      type="button"
      onClick={() => onChange(!checked)}
      aria-pressed={checked}
      className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border text-xs font-semibold transition-colors ${
        checked ? 'bg-[#022340] border-[#022340] text-white' : 'bg-white border-slate-200 text-slate-500 hover:bg-slate-50'
      }`}
    >
      {icon} {label}
    </button>
  );
}

interface TemplateEditorProps {
  value: BusinessTemplate;
  onChange: (value: BusinessTemplate) => void;
  allowGroupClasses: boolean;
}

export default function TemplateEditor({ value, onChange, allowGroupClasses }: TemplateEditorProps) {
  const [openCategory, setOpenCategory] = useState<number | null>(value.categories.length === 1 ? 0 : null);
  const [openService, setOpenService] = useState<string | null>(null);
  const [openField, setOpenField] = useState<number | null>(null);

  const totalServices = value.categories.reduce((total, category) => total + category.services.length, 0);
  const servicesWithCode = value.categories.flatMap((category) => category.services).filter((service) => service.code?.trim());

  const setCategories = (categories: TemplateCategory[]) => onChange({ ...value, categories });
  const updateCategory = (index: number, patch: Partial<TemplateCategory>) =>
    setCategories(value.categories.map((category, i) => (i === index ? { ...category, ...patch } : category)));
  const updateService = (categoryIndex: number, serviceIndex: number, patch: Partial<TemplateService>) =>
    updateCategory(categoryIndex, {
      services: value.categories[categoryIndex].services.map((service, i) => (i === serviceIndex ? { ...service, ...patch } : service)),
    });

  const record = value.record_template;
  const setRecord = (patch: Partial<TemplateRecord>) => record && onChange({ ...value, record_template: { ...record, ...patch } });
  const updateField = (index: number, patch: Partial<TemplateField>) =>
    record && setRecord({ fields: record.fields.map((field, i) => (i === index ? { ...field, ...patch } : field)) });

  const moveField = (index: number, direction: -1 | 1) => {
    if (!record) return;
    const target = index + direction;
    if (target < 0 || target >= record.fields.length) return;
    const fields = [...record.fields];
    [fields[index], fields[target]] = [fields[target], fields[index]];
    setRecord({ fields });
    setOpenField(openField === index ? target : openField === target ? index : openField);
  };

  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <span className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-800">
              <FolderOpen size={14} className="text-[#3F768C]" /> Categorías y servicios
            </span>
            <span className="block text-xs text-slate-400">
              {value.categories.length === 0
                ? 'Sin catálogo: la empresa empieza sin servicios.'
                : `${value.categories.length} categorías · ${totalServices} servicios`}
            </span>
          </div>
        </div>

        {value.categories.map((category, categoryIndex) => {
          const isOpen = openCategory === categoryIndex;

          return (
            <div key={categoryIndex} className="border border-slate-200 rounded-xl overflow-hidden bg-white">
              <div className="flex items-center gap-2 px-3 py-2.5 bg-slate-50/85">
                <button
                  type="button"
                  onClick={() => setOpenCategory(isOpen ? null : categoryIndex)}
                  className={`p-1 rounded-full bg-white border border-slate-200 text-slate-600 transition-transform duration-200 shrink-0 ${isOpen ? 'rotate-180' : ''}`}
                  title={isOpen ? 'Contraer' : 'Ver servicios'}
                >
                  <ChevronDown size={14} />
                </button>
                <input
                  type="text"
                  maxLength={120}
                  value={category.name}
                  onChange={(e) => updateCategory(categoryIndex, { name: e.target.value })}
                  className="flex-1 min-w-0 text-sm font-semibold text-slate-800 bg-transparent border border-transparent hover:border-slate-200 focus:border-[#030336] focus:bg-white rounded-lg px-2 py-1 outline-none"
                  placeholder="Nombre de la categoría"
                />
                <span className="text-[11px] font-medium text-slate-400 shrink-0">
                  {category.services.length} {category.services.length === 1 ? 'servicio' : 'servicios'}
                </span>
                <button
                  type="button"
                  onClick={() => {
                    setCategories(value.categories.filter((_, i) => i !== categoryIndex));
                    setOpenCategory(null);
                    setOpenService(null);
                  }}
                  className={`${iconButton} hover:text-red-600 hover:bg-red-50 shrink-0`}
                  title="Eliminar categoría y sus servicios"
                >
                  <Trash2 size={15} />
                </button>
              </div>

              {isOpen && (
                <div className="p-3 space-y-2">
                  {category.services.length === 0 && (
                    <p className="text-xs text-slate-400 italic">Esta categoría aún no tiene servicios.</p>
                  )}

                  {category.services.map((service, serviceIndex) => {
                    const serviceId = `c${categoryIndex}s${serviceIndex}`;
                    const isServiceOpen = openService === serviceId;
                    const isGroup = service.kind === 'group';

                    return (
                      <div key={serviceIndex} className={`border rounded-lg ${isServiceOpen ? 'border-[#3F768C]/50 shadow-sm' : 'border-slate-200'}`}>
                        <button
                          type="button"
                          onClick={() => setOpenService(isServiceOpen ? null : serviceId)}
                          className="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-slate-50/85 rounded-lg transition-colors"
                        >
                          <div className="flex-1 min-w-0">
                            <span className={`block text-sm font-medium truncate ${service.name ? 'text-slate-800' : 'text-slate-400 italic'}`}>
                              {service.name || 'Servicio sin nombre'}
                            </span>
                            <span className="flex items-center gap-2 text-[11px] text-slate-400">
                              {service.code && <span className="font-mono">{service.code}</span>}
                              <span className="flex items-center gap-0.5"><Clock size={11} /> {service.duration_minutes} min</span>
                              {isGroup && <span className="flex items-center gap-0.5"><Users size={11} /> {service.capacity} cupos</span>}
                              {service.requires_deposit && <span title="Exige adelanto"><HandCoins size={11} /></span>}
                              {service.is_virtual && <span title="Videollamada"><Video size={11} /></span>}
                              {service.uses_record && <span title="Se atiende con ficha"><FileText size={11} /></span>}
                              {service.recall?.label && <span title={`Control: ${service.recall.label}`}><CalendarClock size={11} /></span>}
                            </span>
                          </div>
                          <span className="text-sm font-semibold text-slate-700 shrink-0">S/ {Number(service.price || 0).toFixed(2)}</span>
                          <ChevronDown size={14} className={`text-slate-400 shrink-0 transition-transform duration-200 ${isServiceOpen ? 'rotate-180' : ''}`} />
                        </button>

                        {isServiceOpen && (
                          <div className="px-3 pb-3 pt-1 space-y-3 border-t border-slate-100">
                            <div className="grid grid-cols-3 gap-2 pt-2">
                              <div className="col-span-2">
                                <label className={labelClass}>Nombre *</label>
                                <input type="text" maxLength={150} value={service.name} onChange={(e) => updateService(categoryIndex, serviceIndex, { name: e.target.value })} className={inputClass} placeholder="Ej. Corte de dama" />
                              </div>
                              <div>
                                <label className={labelClass}>Código</label>
                                <input type="text" maxLength={30} value={service.code ?? ''} onChange={(e) => updateService(categoryIndex, serviceIndex, { code: e.target.value })} className={`${inputClass} font-mono`} placeholder="CAB-01" />
                              </div>
                            </div>

                            <div className="grid grid-cols-3 gap-2">
                              <div>
                                <label className={labelClass}>Duración (min) *</label>
                                <input type="number" min="1" value={service.duration_minutes} onChange={(e) => updateService(categoryIndex, serviceIndex, { duration_minutes: Number(e.target.value) })} className={inputClass} />
                              </div>
                              <div>
                                <label className={labelClass}>Precio (S/) *</label>
                                <input type="number" min="0" step="0.01" value={service.price} onChange={(e) => updateService(categoryIndex, serviceIndex, { price: Number(e.target.value) })} className={inputClass} />
                              </div>
                              <div>
                                <label className={labelClass}>Pausa después</label>
                                <input type="number" min="0" value={service.buffer_after_minutes ?? 0} onChange={(e) => updateService(categoryIndex, serviceIndex, { buffer_after_minutes: Number(e.target.value) })} className={inputClass} />
                              </div>
                            </div>

                            <div className="flex flex-wrap gap-1.5">
                              <Toggle icon={<Globe size={13} />} label="Reserva en línea" checked={service.online_booking !== false} onChange={(checked) => updateService(categoryIndex, serviceIndex, { online_booking: checked })} />
                              <Toggle icon={<HandCoins size={13} />} label="Exige adelanto" checked={Boolean(service.requires_deposit)} onChange={(checked) => updateService(categoryIndex, serviceIndex, { requires_deposit: checked })} />
                              <Toggle icon={<Video size={13} />} label="Videollamada" checked={Boolean(service.is_virtual)} onChange={(checked) => updateService(categoryIndex, serviceIndex, { is_virtual: checked })} />
                              <Toggle icon={<FileText size={13} />} label="Con ficha" checked={Boolean(service.uses_record)} onChange={(checked) => updateService(categoryIndex, serviceIndex, { uses_record: checked })} />
                              {(allowGroupClasses || isGroup) && (
                                <Toggle icon={<Users size={13} />} label="Clase grupal" checked={isGroup} onChange={(checked) => updateService(categoryIndex, serviceIndex, { kind: checked ? 'group' : 'individual', capacity: checked ? Math.max(2, service.capacity ?? 10) : 1 })} />
                              )}
                            </div>

                            {isGroup && (
                              <div className="w-1/3">
                                <label className={labelClass}>Cupo por clase</label>
                                <input type="number" min="1" value={service.capacity ?? 1} onChange={(e) => updateService(categoryIndex, serviceIndex, { capacity: Number(e.target.value) })} className={inputClass} />
                              </div>
                            )}

                            <div className="space-y-2 pt-2 border-t border-slate-100">
                              <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                                <input
                                  type="checkbox"
                                  checked={Boolean(service.recall)}
                                  onChange={(e) => updateService(categoryIndex, serviceIndex, { recall: e.target.checked ? { label: '', days: 30 } : null })}
                                  className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
                                />
                                Sugerir un control de seguimiento al completar la atención
                              </label>
                              {service.recall && (
                                <div className="grid grid-cols-6 gap-2">
                                  <div className="col-span-3">
                                    <label className={labelClass}>Nombre del control</label>
                                    <input type="text" maxLength={150} value={service.recall.label} onChange={(e) => updateService(categoryIndex, serviceIndex, { recall: { ...service.recall!, label: e.target.value } })} className={inputClass} placeholder="Ej. Retoque de raíz" />
                                  </div>
                                  <div>
                                    <label className={labelClass}>Días</label>
                                    <input type="number" min="1" value={service.recall.days} onChange={(e) => updateService(categoryIndex, serviceIndex, { recall: { ...service.recall!, days: e.target.value === '' ? '' : Number(e.target.value) } })} className={inputClass} />
                                  </div>
                                  <div className="col-span-2">
                                    <label className={labelClass}>Se agenda con</label>
                                    <Select
                                      ariaLabel="Servicio del control"
                                      placeholder="Este mismo"
                                      value={service.recall.service_code ?? ''}
                                      onChange={(e) => updateService(categoryIndex, serviceIndex, { recall: { ...service.recall!, service_code: String(e.target.value) || undefined } })}
                                      options={servicesWithCode.filter((other) => other.code !== service.code).map((other) => ({ value: other.code!, label: other.name }))}
                                    />
                                  </div>
                                </div>
                              )}
                            </div>

                            <div className="flex justify-end">
                              <button
                                type="button"
                                onClick={() => {
                                  updateCategory(categoryIndex, { services: category.services.filter((_, i) => i !== serviceIndex) });
                                  setOpenService(null);
                                }}
                                className="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700"
                              >
                                <Trash2 size={13} /> Quitar servicio
                              </button>
                            </div>
                          </div>
                        )}
                      </div>
                    );
                  })}

                  <button
                    type="button"
                    onClick={() => {
                      updateCategory(categoryIndex, { services: [...category.services, { ...NEW_SERVICE }] });
                      setOpenService(`c${categoryIndex}s${category.services.length}`);
                    }}
                    className={addLink}
                  >
                    <Plus size={14} /> Agregar servicio
                  </button>
                </div>
              )}
            </div>
          );
        })}

        <button
          type="button"
          onClick={() => {
            setCategories([...value.categories, { name: '', services: [] }]);
            setOpenCategory(value.categories.length);
            setOpenService(null);
          }}
          className={addLink}
        >
          <Plus size={14} /> Agregar categoría
        </button>
      </div>

      <div className="space-y-3 pt-5 border-t border-slate-200">
        <div className="flex items-start justify-between gap-3">
          <div>
            <span className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-800">
              <ClipboardList size={14} className="text-[#3F768C]" /> Ficha de atención
            </span>
            <span className="block text-xs text-slate-400">
              Lo que el profesional llena al atender. Se usa en los servicios marcados "Con ficha".
            </span>
          </div>
          <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer shrink-0">
            <input
              type="checkbox"
              checked={Boolean(record)}
              onChange={(e) => {
                onChange({ ...value, record_template: e.target.checked ? { name: '', consent_text: '', require_consent: false, fields: [] } : null });
                setOpenField(null);
              }}
              className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
            />
            Este rubro usa ficha
          </label>
        </div>

        {record && (
          <div className="space-y-3">
            <div>
              <label className={labelClass}>Nombre de la ficha *</label>
              <input type="text" maxLength={150} value={record.name} onChange={(e) => setRecord({ name: e.target.value })} className={inputClass} placeholder="Ej. Ficha técnica de color" />
            </div>

            <div className="space-y-2">
              <span className={labelClass}>Campos ({record.fields.length})</span>

              {record.fields.map((field, index) => {
                const isFieldOpen = openField === index;
                const hasOptions = ['select', 'multiselect'].includes(field.type);

                return (
                  <div key={index} className={`border rounded-lg bg-white ${isFieldOpen ? 'border-[#3F768C]/50 shadow-sm' : 'border-slate-200'}`}>
                    <div className="flex items-center gap-1 pl-3 pr-1.5 py-1.5">
                      <button type="button" onClick={() => setOpenField(isFieldOpen ? null : index)} className="flex-1 min-w-0 flex items-center gap-2 text-left">
                        <span className="text-[11px] font-mono text-slate-300 shrink-0">{index + 1}</span>
                        <span className={`text-sm font-medium truncate ${field.label ? 'text-slate-800' : 'text-slate-400 italic'}`}>
                          {field.label || 'Campo sin nombre'}
                          {field.required && <span className="text-red-500"> *</span>}
                        </span>
                        <span className="px-2 py-0.5 text-[11px] font-medium bg-slate-100 text-slate-600 rounded-md shrink-0">
                          {FIELD_TYPE_LABELS[field.type] ?? field.type}
                        </span>
                      </button>
                      <button type="button" onClick={() => moveField(index, -1)} disabled={index === 0} className={`${iconButton} hover:text-slate-700 hover:bg-slate-100`} title="Subir">
                        <ArrowUp size={14} />
                      </button>
                      <button type="button" onClick={() => moveField(index, 1)} disabled={index === record.fields.length - 1} className={`${iconButton} hover:text-slate-700 hover:bg-slate-100`} title="Bajar">
                        <ArrowDown size={14} />
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setRecord({ fields: record.fields.filter((_, i) => i !== index) });
                          setOpenField(null);
                        }}
                        className={`${iconButton} hover:text-red-600 hover:bg-red-50`}
                        title="Quitar campo"
                      >
                        <Trash2 size={14} />
                      </button>
                    </div>

                    {isFieldOpen && (
                      <div className="px-3 pb-3 pt-2 space-y-3 border-t border-slate-100">
                        <div className="grid grid-cols-2 gap-2">
                          <div>
                            <label className={labelClass}>Nombre del campo *</label>
                            <input type="text" maxLength={150} value={field.label} onChange={(e) => updateField(index, { label: e.target.value })} className={inputClass} placeholder="Ej. Motivo de consulta" />
                          </div>
                          <div>
                            <label className={labelClass}>Tipo</label>
                            <Select
                              ariaLabel="Tipo de campo"
                              clearable={false}
                              value={field.type}
                              onChange={(e) => updateField(index, { type: String(e.target.value) })}
                              options={FIELD_TYPES}
                            />
                          </div>
                        </div>

                        {hasOptions && (
                          <div>
                            <label className={labelClass}>Opciones (una por línea)</label>
                            <textarea
                              rows={Math.min(6, Math.max(3, (field.options ?? []).length + 1))}
                              value={(field.options ?? []).join('\n')}
                              onChange={(e) => updateField(index, { options: e.target.value.split('\n') })}
                              className={inputClass}
                              placeholder={'Liso\nOndulado\nRizado'}
                            />
                          </div>
                        )}

                        {field.type === 'next_control' && (
                          <div className="w-1/2">
                            <label className={labelClass}>Días sugeridos</label>
                            <input type="number" min="1" value={field.days ?? ''} onChange={(e) => updateField(index, { days: e.target.value === '' ? '' : Number(e.target.value) })} className={inputClass} placeholder="30" />
                          </div>
                        )}

                        {['text', 'textarea'].includes(field.type) && (
                          <div>
                            <label className={labelClass}>Texto de ayuda</label>
                            <input type="text" maxLength={150} value={field.placeholder ?? ''} onChange={(e) => updateField(index, { placeholder: e.target.value })} className={inputClass} placeholder="Ej.: 7.1 + 7.0 (1:1)" />
                          </div>
                        )}

                        <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                          <input type="checkbox" checked={Boolean(field.required)} onChange={(e) => updateField(index, { required: e.target.checked })} className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]" />
                          Obligatorio
                        </label>
                      </div>
                    )}
                  </div>
                );
              })}

              <button
                type="button"
                onClick={() => {
                  setRecord({ fields: [...record.fields, { key: '', label: '', type: 'text', required: false }] });
                  setOpenField(record.fields.length);
                }}
                className={addLink}
              >
                <Plus size={14} /> Agregar campo
              </button>
            </div>

            <div className="space-y-2 pt-3 border-t border-slate-100">
              <label className={labelClass}>Consentimiento informado</label>
              <textarea
                rows={3}
                value={record.consent_text ?? ''}
                onChange={(e) => setRecord({ consent_text: e.target.value })}
                className={inputClass}
                placeholder="Texto que el cliente acepta antes de la atención. Déjalo vacío si no aplica."
              />
              <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                <input type="checkbox" checked={Boolean(record.require_consent)} onChange={(e) => setRecord({ require_consent: e.target.checked })} className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]" />
                El consentimiento es obligatorio para atender
              </label>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
