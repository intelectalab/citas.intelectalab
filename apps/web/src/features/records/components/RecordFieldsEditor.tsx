'use client';

import { useState } from 'react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { FIELD_TYPES, FIELD_TYPE_LABELS, NEW_FIELD, type RecordField } from '../records';

interface RecordFieldsEditorProps {
  value: RecordField[];
  onChange: (fields: RecordField[]) => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none bg-white';
const labelClass = 'block text-[11px] font-semibold uppercase tracking-wider text-slate-600 mb-1';
const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-30 disabled:hover:bg-transparent';

// Editor de los campos de una ficha: agregar, quitar, renombrar, ordenar y
// elegir el tipo de cada uno. Es el mismo criterio del editor de la plataforma.
export default function RecordFieldsEditor({ value, onChange }: RecordFieldsEditorProps) {
  const [openField, setOpenField] = useState<number | null>(null);

  const update = (index: number, patch: Partial<RecordField>) =>
    onChange(value.map((field, i) => (i === index ? { ...field, ...patch } : field)));

  const move = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= value.length) return;
    const fields = [...value];
    [fields[index], fields[target]] = [fields[target], fields[index]];
    onChange(fields);
    setOpenField(openField === index ? target : openField === target ? index : openField);
  };

  return (
    <div className="space-y-2" data-fields-editor>
      {value.length === 0 && <p className="text-xs text-slate-400 italic">La ficha aún no tiene campos.</p>}

      {value.map((field, index) => {
        const isOpen = openField === index;
        const hasOptions = ['select', 'multiselect'].includes(field.type);

        return (
          <div key={index} data-field-row={index} className={`border rounded-lg bg-white ${isOpen ? 'border-[#3F768C]/50 shadow-sm' : 'border-slate-200'}`}>
            <div className="flex items-center gap-1 pl-3 pr-1.5 py-1.5">
              <button type="button" onClick={() => setOpenField(isOpen ? null : index)} className="flex-1 min-w-0 flex items-center gap-2 text-left">
                <span className="text-[11px] font-mono text-slate-300 shrink-0">{index + 1}</span>
                <span className={`text-sm font-medium truncate ${field.label ? 'text-slate-800' : 'text-slate-400 italic'}`}>
                  {field.label || 'Campo sin nombre'}
                  {field.required && <span className="text-red-500"> *</span>}
                </span>
                <span className="px-2 py-0.5 text-[11px] font-medium bg-slate-100 text-slate-600 rounded-md shrink-0">
                  {FIELD_TYPE_LABELS[field.type] ?? field.type}
                </span>
              </button>
              <button type="button" onClick={() => move(index, -1)} disabled={index === 0} className={`${iconButton} hover:text-slate-700 hover:bg-slate-100`} title="Subir">
                <ArrowUp size={14} />
              </button>
              <button type="button" onClick={() => move(index, 1)} disabled={index === value.length - 1} className={`${iconButton} hover:text-slate-700 hover:bg-slate-100`} title="Bajar">
                <ArrowDown size={14} />
              </button>
              <button
                type="button"
                onClick={() => { onChange(value.filter((_, i) => i !== index)); setOpenField(null); }}
                className={`${iconButton} hover:text-red-600 hover:bg-red-50`}
                title="Quitar campo"
              >
                <Trash2 size={14} />
              </button>
            </div>

            {isOpen && (
              <div className="px-3 pb-3 pt-2 space-y-3 border-t border-slate-100">
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className={labelClass}>Nombre del campo *</label>
                    <input
                      type="text"
                      maxLength={150}
                      aria-label={`Nombre del campo ${index + 1}`}
                      value={field.label}
                      onChange={(e) => update(index, { label: e.target.value })}
                      className={inputClass}
                      placeholder="Ej. Motivo de consulta"
                    />
                  </div>
                  <div>
                    <label className={labelClass}>Tipo</label>
                    <select
                      aria-label={`Tipo del campo ${index + 1}`}
                      value={field.type}
                      onChange={(e) => update(index, { type: e.target.value })}
                      className={inputClass}
                    >
                      {FIELD_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                    </select>
                  </div>
                </div>

                {hasOptions && (
                  <div>
                    <label className={labelClass}>Opciones (una por línea)</label>
                    <textarea
                      rows={Math.min(6, Math.max(3, (field.options ?? []).length + 1))}
                      aria-label={`Opciones del campo ${index + 1}`}
                      value={(field.options ?? []).join('\n')}
                      onChange={(e) => update(index, { options: e.target.value.split('\n') })}
                      className={inputClass}
                      placeholder={'Leve\nModerado\nGrave'}
                    />
                  </div>
                )}

                {field.type === 'next_control' && (
                  <div className="w-1/2">
                    <label className={labelClass}>Días sugeridos</label>
                    <input
                      type="number"
                      min="1"
                      value={field.days ?? ''}
                      onChange={(e) => update(index, { days: e.target.value === '' ? '' : Number(e.target.value) })}
                      className={inputClass}
                      placeholder="30"
                    />
                  </div>
                )}

                {['text', 'textarea'].includes(field.type) && (
                  <div>
                    <label className={labelClass}>Texto de ayuda</label>
                    <input type="text" maxLength={150} value={field.placeholder ?? ''} onChange={(e) => update(index, { placeholder: e.target.value })} className={inputClass} placeholder="Ej.: 120/80" />
                  </div>
                )}

                <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                  <input type="checkbox" checked={Boolean(field.required)} onChange={(e) => update(index, { required: e.target.checked })} className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]" />
                  Obligatorio para firmar la ficha
                </label>
              </div>
            )}
          </div>
        );
      })}

      <button
        type="button"
        data-action="add-field"
        onClick={() => { onChange([...value, { ...NEW_FIELD }]); setOpenField(value.length); }}
        className="inline-flex items-center gap-1 text-xs font-bold text-sky-600 hover:text-sky-700 transition-colors"
      >
        <Plus size={14} /> Agregar campo
      </button>
    </div>
  );
}
