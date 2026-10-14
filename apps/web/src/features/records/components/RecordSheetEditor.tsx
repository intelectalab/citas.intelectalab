'use client';

import { useEffect, useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, ClipboardList, PenLine, Plus, Trash2, X } from 'lucide-react';
import { api } from '@/lib/axios';
import Select from '@/app/components/common/Select';
import { FieldPreview } from '@/features/business-types/components/RecordPreview';
import { apiError } from '@/features/appointments/appointments';
import { FIELD_TYPES, NEW_FIELD, type RecordField, type RecordTemplate } from '../records';

interface RecordSheetEditorProps {
  template: RecordTemplate;
  color: string;
  companyName: string;
  onSaved: (template: RecordTemplate) => void;
  onDirtyChange?: (dirty: boolean) => void;
}

interface Draft {
  name: string;
  fields: RecordField[];
  consent_text: string;
  require_consent: boolean;
}

const toDraft = (template: RecordTemplate): Draft => ({
  name: template.name,
  fields: template.fields.map((field) => ({ ...field, options: field.options ? [...field.options] : undefined })),
  consent_text: template.consent_text ?? '',
  require_consent: template.require_consent,
});

const inlineText =
  'bg-transparent rounded-md border border-transparent hover:border-slate-200 focus:border-[#3F768C] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#3F768C]/15 transition-colors';
const tool = 'p-1 rounded-md text-slate-400 hover:text-[#022340] hover:bg-slate-100 transition-colors disabled:opacity-30 disabled:hover:bg-transparent';

export default function RecordSheetEditor({ template, color, companyName, onSaved, onDirtyChange }: RecordSheetEditorProps) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(template));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [adding, setAdding] = useState(false);

  useEffect(() => {
    setDraft(toDraft(template));
    setError('');
    setAdding(false);
  }, [template]);

  const dirty = useMemo(() => JSON.stringify(draft) !== JSON.stringify(toDraft(template)), [draft, template]);

  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);

  const setField = (index: number, patch: Partial<RecordField>) =>
    setDraft((current) => ({ ...current, fields: current.fields.map((field, i) => (i === index ? { ...field, ...patch } : field)) }));

  const moveField = (index: number, direction: -1 | 1) =>
    setDraft((current) => {
      const target = index + direction;
      if (target < 0 || target >= current.fields.length) return current;
      const fields = [...current.fields];
      [fields[index], fields[target]] = [fields[target], fields[index]];
      return { ...current, fields };
    });

  const removeField = (index: number) => setDraft((current) => ({ ...current, fields: current.fields.filter((_, i) => i !== index) }));

  const addField = (type: string) => {
    setDraft((current) => ({
      ...current,
      fields: [...current.fields, { ...NEW_FIELD, type, ...(['select', 'multiselect'].includes(type) ? { options: ['Opción 1', 'Opción 2'] } : {}) }],
    }));
    setAdding(false);
  };

  const setOption = (index: number, option: number, value: string) =>
    setField(index, { options: (draft.fields[index].options ?? []).map((item, i) => (i === option ? value : item)) });

  const save = async () => {
    if (!draft.name.trim()) { setError('La ficha necesita un nombre.'); return; }
    if (draft.fields.some((field) => !field.label.trim())) { setError('Hay un campo sin nombre: escríbele uno o quítalo.'); return; }

    try {
      setSaving(true);
      setError('');
      const res = await api.put(`/record-templates/${template.id}`, {
        name: draft.name.trim(),
        description: template.description,
        fields: draft.fields,
        consent_text: draft.consent_text.trim() || null,
        require_consent: draft.require_consent && draft.consent_text.trim() !== '',
        service_ids: template.service_ids,
      });
      onSaved(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la ficha.'));
    } finally {
      setSaving(false);
    }
  };

  const hasConsent = draft.consent_text !== '' || draft.require_consent;

  return (
    <div className="bg-white rounded-2xl border border-slate-200 shadow-lg" data-sheet-editor data-dirty={dirty}>
      <div className="px-6 py-5 text-white rounded-t-2xl" style={{ backgroundColor: color }}>
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-widest opacity-80">
            <ClipboardList size={14} /> Ficha de atención
          </div>
          <span className="px-2 py-0.5 rounded-md bg-white/15 text-[10px] font-semibold">Edítala aquí mismo</span>
        </div>
        <input
          type="text"
          data-sheet-name
          aria-label="Nombre de la ficha"
          maxLength={150}
          value={draft.name}
          onChange={(e) => setDraft((current) => ({ ...current, name: e.target.value }))}
          className="w-full mt-1 -mx-1.5 px-1.5 py-0.5 text-lg font-black tracking-tight bg-transparent rounded-md border border-transparent hover:border-white/30 focus:border-white/60 focus:bg-white/10 focus:outline-none placeholder:text-white/50"
          placeholder="Nombre de la ficha"
        />
        <p className="text-xs opacity-80 mt-0.5">{companyName}</p>
      </div>

      <div className="grid grid-cols-3 gap-3 px-6 py-4 bg-slate-50 border-b border-slate-200">
        {['Cliente', 'Servicio', 'Fecha'].map((label) => (
          <div key={label}>
            <span className="block text-[10px] font-bold uppercase tracking-wider text-slate-400">{label}</span>
            <span className="block h-5 border-b border-dashed border-slate-300" />
          </div>
        ))}
      </div>

      <div className="px-6 py-5 space-y-3">
        {draft.fields.length === 0 && <p className="text-xs text-slate-400 italic">La ficha aún no tiene campos. Agrega el primero abajo.</p>}

        {draft.fields.map((field, index) => {
          const hasOptions = ['select', 'multiselect'].includes(field.type);

          return (
            <div key={field.key || `nuevo-${index}`} data-sheet-field={index} className="group/field rounded-xl border border-transparent hover:border-slate-200 focus-within:border-[#3F768C]/40 focus-within:bg-slate-50/50 px-3 py-2.5 -mx-3 space-y-2 transition-colors">
              <div className="flex items-center gap-1.5">
                <span className="text-[10px] font-mono text-slate-300 shrink-0">{String(index + 1).padStart(2, '0')}</span>
                <input
                  type="text"
                  data-field-label
                  aria-label={`Nombre del campo ${index + 1}`}
                  maxLength={150}
                  value={field.label}
                  onChange={(e) => setField(index, { label: e.target.value })}
                  placeholder="Nombre del campo"
                  className={`${inlineText} flex-1 min-w-0 px-1.5 py-0.5 text-xs font-semibold text-slate-700 placeholder:font-normal placeholder:italic placeholder:text-slate-400`}
                />
                <button
                  type="button"
                  data-field-required
                  aria-pressed={Boolean(field.required)}
                  title={field.required ? 'Obligatorio: clic para hacerlo opcional' : 'Opcional: clic para hacerlo obligatorio'}
                  onClick={() => setField(index, { required: !field.required })}
                  className={`px-2 py-0.5 rounded-md text-[10px] font-semibold border transition-colors shrink-0 ${
                    field.required ? 'bg-rose-50 border-rose-100 text-rose-600' : 'bg-white border-slate-200 text-slate-400 hover:text-slate-600'
                  }`}
                >
                  {field.required ? 'Obligatorio' : 'Opcional'}
                </button>
                <div data-field-type={field.type} className="shrink-0 bg-slate-100 rounded-md">
                  <Select
                    variant="chip"
                    align="right"
                    ariaLabel={`Tipo del campo ${index + 1}`}
                    clearable={false}
                    value={field.type}
                    onChange={(e) => {
                      const type = String(e.target.value);
                      setField(index, { type, ...(['select', 'multiselect'].includes(type) && !(field.options ?? []).length ? { options: ['Opción 1', 'Opción 2'] } : {}) });
                    }}
                    options={FIELD_TYPES}
                  />
                </div>
                <div className="flex items-center shrink-0 opacity-40 group-hover/field:opacity-100 group-focus-within/field:opacity-100 transition-opacity">
                  <button type="button" data-field-up title="Subir" disabled={index === 0} onClick={() => moveField(index, -1)} className={tool}><ArrowUp size={13} /></button>
                  <button type="button" data-field-down title="Bajar" disabled={index === draft.fields.length - 1} onClick={() => moveField(index, 1)} className={tool}><ArrowDown size={13} /></button>
                  <button type="button" data-field-remove title="Quitar este campo" onClick={() => removeField(index)} className={`${tool} hover:text-red-600 hover:bg-red-50`}><Trash2 size={13} /></button>
                </div>
              </div>

              {hasOptions ? (
                <div className="flex flex-wrap items-center gap-1.5 pl-5" data-field-options>
                  {(field.options ?? []).map((option, optionIndex) => (
                    <span key={optionIndex} className="inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-lg border border-slate-200 bg-white">
                      <span className={`w-3 h-3 border border-slate-300 bg-white shrink-0 ${field.type === 'select' ? 'rounded-full' : 'rounded-sm'}`} />
                      <input
                        type="text"
                        aria-label={`Opción ${optionIndex + 1}`}
                        value={option}
                        size={Math.max(4, option.length)}
                        onChange={(e) => setOption(index, optionIndex, e.target.value)}
                        className="bg-transparent text-xs text-slate-600 focus:outline-none focus:text-slate-900 min-w-0"
                      />
                      <button type="button" title="Quitar opción" onClick={() => setField(index, { options: (field.options ?? []).filter((_, i) => i !== optionIndex) })} className="p-0.5 rounded text-slate-300 hover:text-red-600">
                        <X size={11} />
                      </button>
                    </span>
                  ))}
                  <button type="button" data-option-add onClick={() => setField(index, { options: [...(field.options ?? []), `Opción ${(field.options ?? []).length + 1}`] })} className="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-dashed border-slate-300 text-[11px] font-semibold text-[#3F768C] hover:border-[#3F768C] transition-colors">
                    <Plus size={11} /> opción
                  </button>
                </div>
              ) : (
                <div className="pl-5 pointer-events-none select-none"><FieldPreview field={field} /></div>
              )}

              {field.type === 'next_control' && (
                <label className="flex items-center gap-2 pl-5 text-[11px] text-slate-500">
                  Sugerir el control en
                  <input
                    type="number"
                    min={1}
                    max={3650}
                    data-field-days
                    value={field.days ?? ''}
                    onChange={(e) => setField(index, { days: e.target.value === '' ? '' : Number(e.target.value) })}
                    className="w-16 text-xs text-slate-700 border border-slate-200 rounded-md px-2 py-0.5 focus:outline-none focus:border-[#3F768C]"
                  />
                  días
                </label>
              )}
            </div>
          );
        })}

        {adding ? (
          <div className="rounded-xl border border-dashed border-[#3F768C]/50 bg-slate-50/60 p-3 space-y-2" data-add-types>
            <div className="flex items-center justify-between">
              <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">¿Qué tipo de campo?</span>
              <button type="button" onClick={() => setAdding(false)} className={tool} title="Cancelar"><X size={13} /></button>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {FIELD_TYPES.map((type) => (
                <button key={type.value} type="button" data-add-type={type.value} onClick={() => addField(type.value)} className="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-medium text-slate-700 hover:border-[#3F768C] hover:text-[#022340] transition-colors">
                  {type.label}
                </button>
              ))}
            </div>
          </div>
        ) : (
          <button type="button" data-action="add-field" onClick={() => setAdding(true)} className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl border border-dashed border-slate-300 text-xs font-semibold text-[#3F768C] hover:border-[#3F768C] hover:bg-slate-50 transition-colors">
            <Plus size={14} /> Agregar campo
          </button>
        )}
      </div>

      {hasConsent ? (
        <div className="mx-6 mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3" data-consent>
          <div className="flex items-center justify-between gap-2">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Consentimiento informado</span>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                data-consent-required
                aria-pressed={draft.require_consent}
                onClick={() => setDraft((current) => ({ ...current, require_consent: !current.require_consent }))}
                className={`px-2 py-0.5 rounded-md text-[10px] font-semibold transition-colors ${draft.require_consent ? 'bg-rose-50 text-rose-600' : 'bg-slate-200 text-slate-600 hover:bg-slate-300'}`}
              >
                {draft.require_consent ? 'Obligatorio' : 'Opcional'}
              </button>
              <button type="button" data-consent-remove title="Quitar el consentimiento" onClick={() => setDraft((current) => ({ ...current, consent_text: '', require_consent: false }))} className={`${tool} hover:text-red-600 hover:bg-red-50`}>
                <Trash2 size={13} />
              </button>
            </div>
          </div>
          <textarea
            rows={3}
            data-consent-text
            aria-label="Texto del consentimiento"
            maxLength={5000}
            value={draft.consent_text}
            onChange={(e) => setDraft((current) => ({ ...current, consent_text: e.target.value }))}
            placeholder="Escribe lo que el cliente acepta antes de la atención."
            className={`${inlineText} w-full px-2 py-1.5 -mx-2 text-xs text-slate-600 leading-relaxed resize-y`}
          />
          <div className="flex items-end gap-2 pt-1">
            <PenLine size={14} className="text-slate-400 mb-0.5" />
            <span className="flex-1 border-b border-slate-400" />
            <span className="text-[10px] text-slate-400">Firma del cliente</span>
          </div>
        </div>
      ) : (
        <div className="mx-6 mb-6">
          <button type="button" data-action="add-consent" onClick={() => setDraft((current) => ({ ...current, require_consent: true }))} className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl border border-dashed border-slate-300 text-xs font-semibold text-slate-500 hover:border-[#3F768C] hover:text-[#3F768C] transition-colors">
            <PenLine size={13} /> Agregar consentimiento informado
          </button>
        </div>
      )}

      <div className={`px-6 py-3 border-t rounded-b-2xl flex flex-wrap items-center justify-between gap-3 sticky bottom-0 transition-colors ${dirty ? 'bg-amber-50 border-amber-200' : 'bg-white border-slate-100'}`} data-sheet-bar>
        <span role={error ? 'alert' : undefined} className={`text-xs font-medium ${error ? 'text-red-600' : dirty ? 'text-amber-800' : 'text-slate-400'}`} data-sheet-status>
          {error || (dirty
            ? 'Tienes cambios sin guardar. Las fichas ya llenadas no cambian.'
            : `${draft.fields.length} campos · ${draft.fields.filter((field) => field.required).length} obligatorios · Haz clic en cualquier texto para cambiarlo`)}
        </span>
        {dirty && (
          <div className="flex items-center gap-2 shrink-0">
            <button type="button" data-action="discard" disabled={saving} onClick={() => { setDraft(toDraft(template)); setError(''); }} className="px-3 py-2 text-xs font-medium border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50 transition-colors disabled:opacity-50">
              Descartar
            </button>
            <button type="button" data-action="save-sheet" disabled={saving} onClick={save} className="px-4 py-2 text-xs font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
              {saving ? 'Guardando...' : 'Guardar ficha'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
