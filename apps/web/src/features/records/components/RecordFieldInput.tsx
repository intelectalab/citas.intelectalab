'use client';

import DateField from '@/app/components/common/DateField';
import { CalendarClock } from 'lucide-react';
import { addDays, formatDate, todayISO } from '@/features/appointments/appointments';
import { TEETH_ROWS, TOOTH_STATES, isEmptyValue, type RecordField, type RecordValue } from '../records';

interface RecordFieldInputProps {
  field: RecordField;
  value: RecordValue;
  onChange: (value: RecordValue) => void;
  disabled?: boolean;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white disabled:bg-slate-50 disabled:text-slate-600';

const chip = (active: boolean, disabled?: boolean) =>
  `px-3 py-1.5 text-sm rounded-lg border transition-colors ${
    active ? 'bg-[#022340] border-[#022340] text-white font-semibold' : 'bg-white border-slate-200 text-slate-700'
  } ${disabled ? 'cursor-default' : active ? '' : 'hover:border-[#3F768C]'} ${disabled && !active ? 'opacity-50' : ''}`;

export default function RecordFieldInput({ field, value, onChange, disabled = false }: RecordFieldInputProps) {
  const label = field.label;

  switch (field.type) {
    case 'textarea':
      return (
        <textarea
          rows={3}
          aria-label={label}
          disabled={disabled}
          placeholder={disabled ? '' : field.placeholder}
          className={inputClass}
          value={(value as string) ?? ''}
          onChange={(e) => onChange(e.target.value)}
        />
      );

    case 'number':
      return (
        <input
          type="number"
          aria-label={label}
          disabled={disabled}
          step={field.step ?? 'any'}
          className={`${inputClass} max-w-[12rem]`}
          value={value === null || value === undefined ? '' : String(value)}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        />
      );

    case 'select':
      return (
        <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={label}>
          {(field.options ?? []).map((option) => (
            <button
              key={option}
              type="button"
              role="radio"
              aria-checked={value === option}
              disabled={disabled}
              onClick={() => onChange(value === option ? null : option)}
              className={chip(value === option, disabled)}
            >
              {option}
            </button>
          ))}
        </div>
      );

    case 'multiselect': {
      const chosen = Array.isArray(value) ? (value as string[]) : [];
      return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={label}>
          {(field.options ?? []).map((option) => {
            const active = chosen.includes(option);
            return (
              <button
                key={option}
                type="button"
                aria-pressed={active}
                disabled={disabled}
                onClick={() => onChange(active ? chosen.filter((o) => o !== option) : [...chosen, option])}
                className={chip(active, disabled)}
              >
                {option}
              </button>
            );
          })}
        </div>
      );
    }

    case 'boolean':
      return (
        <div className="flex gap-1.5" role="radiogroup" aria-label={label}>
          {[{ text: 'Sí', val: true }, { text: 'No', val: false }].map((option) => (
            <button
              key={option.text}
              type="button"
              role="radio"
              aria-checked={value === option.val}
              disabled={disabled}
              onClick={() => onChange(value === option.val ? null : option.val)}
              className={chip(value === option.val, disabled)}
            >
              {option.text}
            </button>
          ))}
        </div>
      );

    case 'scale':
      return (
        <div className="flex gap-1" role="radiogroup" aria-label={label}>
          {Array.from({ length: 11 }, (_, n) => (
            <button
              key={n}
              type="button"
              role="radio"
              aria-checked={value === n}
              disabled={disabled}
              onClick={() => onChange(value === n ? null : n)}
              className={`flex-1 h-9 rounded-md border text-sm font-semibold transition-colors ${
                value === n ? 'bg-[#022340] border-[#022340] text-white' : 'bg-white border-slate-200 text-slate-600'
              } ${disabled ? 'cursor-default' : value === n ? '' : 'hover:border-[#3F768C]'}`}
            >
              {n}
            </button>
          ))}
        </div>
      );

    case 'date':
      return (
        <DateField
          ariaLabel={label}
          disabled={disabled}
          className="w-full max-w-[14rem]"
          value={(value as string) ?? ''}
          onChange={(next) => onChange(next || null)}
        />
      );

    case 'next_control': {
      const date = (value as { date?: string } | null)?.date ?? '';
      const suggested = field.days ? addDays(todayISO(), Number(field.days)) : null;
      return (
        <div className="flex flex-wrap items-center gap-2">
          <DateField
            ariaLabel={label}
            disabled={disabled}
            className="w-full max-w-[14rem]"
            value={date}
            onChange={(next) => onChange(next ? { date: next } : null)}
          />
          {!disabled && suggested && date !== suggested && (
            <button
              type="button"
              onClick={() => onChange({ date: suggested })}
              className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-700 hover:bg-amber-100 transition-colors"
            >
              <CalendarClock size={13} /> Sugerido: en {field.days} días
            </button>
          )}
          {date && <span className="text-xs text-slate-500">{formatDate(date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</span>}
        </div>
      );
    }

    case 'odontogram': {
      const teeth = (value && typeof value === 'object' && !Array.isArray(value) ? value : {}) as Record<string, string>;

      const cycle = (tooth: string) => {
        const index = TOOTH_STATES.findIndex((state) => state.key === teeth[tooth]);
        const next = TOOTH_STATES[index + 1];
        const updated = { ...teeth };
        if (next) updated[tooth] = next.key; else delete updated[tooth];
        onChange(isEmptyValue(updated) ? null : updated);
      };

      return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 space-y-2" data-odontogram>
          {TEETH_ROWS.map((row, rowIndex) => (
            <div key={rowIndex} className="flex justify-center gap-1">
              {row.map((tooth, index) => {
                const state = TOOTH_STATES.find((s) => s.key === teeth[tooth]);
                return (
                  <button
                    key={tooth}
                    type="button"
                    data-tooth={tooth}
                    data-state={state?.key ?? ''}
                    disabled={disabled}
                    title={`Pieza ${tooth}: ${state?.label ?? 'Sana'}`}
                    onClick={() => cycle(tooth)}
                    className={`w-7 h-8 rounded-md border text-[10px] font-bold transition-colors ${index === 8 ? 'ml-2' : ''} ${
                      state ? state.className : 'bg-white border-slate-300 text-slate-500'
                    } ${disabled ? 'cursor-default' : 'hover:border-[#3F768C]'}`}
                  >
                    {tooth}
                  </button>
                );
              })}
            </div>
          ))}
          <div className="flex flex-wrap justify-center gap-x-3 gap-y-1 pt-1">
            {TOOTH_STATES.map((state) => (
              <span key={state.key} className="flex items-center gap-1 text-[11px] text-slate-500">
                <span className={`w-3 h-3 rounded-sm border ${state.className}`} /> {state.label}
              </span>
            ))}
          </div>
          {!disabled && <p className="text-center text-[11px] text-slate-400">Haz clic en una pieza para cambiar su estado.</p>}
        </div>
      );
    }

    default:
      return (
        <input
          type="text"
          aria-label={label}
          disabled={disabled}
          placeholder={disabled ? '' : field.placeholder}
          className={inputClass}
          value={(value as string) ?? ''}
          onChange={(e) => onChange(e.target.value)}
        />
      );
  }
}
