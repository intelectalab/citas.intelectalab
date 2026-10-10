'use client';

import { CalendarClock, ClipboardList, PenLine } from 'lucide-react';
import type { TemplateField, TemplateRecord } from './TemplateEditor';

interface RecordPreviewProps {
  record: TemplateRecord;
  // Color del rubro: tiñe la cabecera de la ficha.
  color: string;
  businessTypeName: string;
}

const blankLine = 'h-9 rounded-lg border border-slate-200 bg-slate-50/70';

// Cómo se vería cada campo al llenarlo. Es una vista previa: nada es editable.
export function FieldPreview({ field }: { field: TemplateField }) {
  const options = (field.options ?? []).filter(Boolean);

  switch (field.type) {
    case 'textarea':
      return (
        <div className="h-16 rounded-lg border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs text-slate-300">
          {field.placeholder}
        </div>
      );

    case 'select':
    case 'multiselect':
      return (
        <div className="flex flex-wrap gap-1.5">
          {options.map((option) => (
            <span key={option} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-slate-200 bg-white text-xs text-slate-600">
              <span className={`w-3 h-3 border border-slate-300 bg-white ${field.type === 'select' ? 'rounded-full' : 'rounded-sm'}`} />
              {option}
            </span>
          ))}
        </div>
      );

    case 'boolean':
      return (
        <div className="flex items-center gap-4 text-xs text-slate-600">
          {['Sí', 'No'].map((option) => (
            <span key={option} className="inline-flex items-center gap-1.5">
              <span className="w-3.5 h-3.5 rounded-full border border-slate-300 bg-white" /> {option}
            </span>
          ))}
        </div>
      );

    case 'scale':
      return (
        <div className="flex items-center gap-1">
          {Array.from({ length: 11 }, (_, n) => (
            <span key={n} className="flex-1 h-7 rounded-md border border-slate-200 bg-white text-[11px] font-medium text-slate-500 flex items-center justify-center">
              {n}
            </span>
          ))}
        </div>
      );

    case 'odontogram':
      return (
        <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3 space-y-1.5">
          {[0, 1].map((row) => (
            <div key={row} className="flex justify-center gap-1">
              {Array.from({ length: 16 }, (_, n) => (
                <span key={n} className="w-4 h-5 rounded-[5px] border border-slate-300 bg-white" />
              ))}
            </div>
          ))}
          <span className="block text-center text-[10px] text-slate-400 pt-1">Odontograma: se marca el estado de cada pieza</span>
        </div>
      );

    case 'next_control':
      return (
        <div className="flex items-center gap-2">
          <div className={`${blankLine} flex-1 flex items-center gap-2 px-3 text-xs text-slate-400`}>
            <CalendarClock size={14} /> dd/mm/aaaa
          </div>
          {field.days ? (
            <span className="px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-100 text-[11px] font-semibold text-amber-700 shrink-0">
              Sugerido: en {field.days} días
            </span>
          ) : null}
        </div>
      );

    case 'date':
      return <div className={`${blankLine} w-1/2 flex items-center px-3 text-xs text-slate-300`}>dd/mm/aaaa</div>;

    case 'number':
      return <div className={`${blankLine} w-1/3`} />;

    default:
      return <div className={`${blankLine} flex items-center px-3 text-xs text-slate-300`}>{field.placeholder}</div>;
  }
}

// Vista previa de la ficha de atención con forma de hoja: cabecera, campos y
// consentimiento con línea de firma.
export default function RecordPreview({ record, color, businessTypeName }: RecordPreviewProps) {
  return (
    <div className="bg-white rounded-2xl border border-slate-200 shadow-lg overflow-hidden">
      <div className="px-6 py-5 text-white" style={{ backgroundColor: color }}>
        <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-widest opacity-80">
          <ClipboardList size={14} /> Ficha de atención
        </div>
        <h3 className="text-lg font-black tracking-tight mt-1">{record.name}</h3>
        <p className="text-xs opacity-80 mt-0.5">{businessTypeName}</p>
      </div>

      {/* Datos que la ficha toma de la cita */}
      <div className="grid grid-cols-3 gap-3 px-6 py-4 bg-slate-50 border-b border-slate-200">
        {['Cliente', 'Servicio', 'Fecha'].map((label) => (
          <div key={label}>
            <span className="block text-[10px] font-bold uppercase tracking-wider text-slate-400">{label}</span>
            <span className="block h-5 border-b border-dashed border-slate-300" />
          </div>
        ))}
      </div>

      <div className="px-6 py-5 space-y-5">
        {record.fields.length === 0 && (
          <p className="text-xs text-slate-400 italic">La ficha aún no tiene campos.</p>
        )}

        {record.fields.map((field, index) => (
          <div key={field.key || index} className="space-y-1.5">
            <span className="flex items-baseline gap-1.5 text-xs font-semibold text-slate-700">
              <span className="text-[10px] font-mono text-slate-300">{String(index + 1).padStart(2, '0')}</span>
              {field.label}
              {field.required && <span className="text-red-500" title="Obligatorio">*</span>}
            </span>
            <FieldPreview field={field} />
          </div>
        ))}
      </div>

      {record.consent_text && (
        <div className="mx-6 mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Consentimiento informado</span>
            <span className={`px-2 py-0.5 rounded-md text-[10px] font-semibold ${record.require_consent ? 'bg-rose-50 text-rose-600' : 'bg-slate-200 text-slate-600'}`}>
              {record.require_consent ? 'Obligatorio' : 'Opcional'}
            </span>
          </div>
          <p className="text-xs text-slate-600 leading-relaxed">{record.consent_text}</p>
          <div className="flex items-end gap-2 pt-3">
            <PenLine size={14} className="text-slate-400 mb-0.5" />
            <span className="flex-1 border-b border-slate-400" />
            <span className="text-[10px] text-slate-400">Firma del cliente</span>
          </div>
        </div>
      )}

      <div className="px-6 py-3 border-t border-slate-100 text-[10px] text-slate-400 flex items-center justify-between">
        <span>{record.fields.length} campos · {record.fields.filter((field) => field.required).length} obligatorios</span>
        <span>Vista previa</span>
      </div>
    </div>
  );
}
