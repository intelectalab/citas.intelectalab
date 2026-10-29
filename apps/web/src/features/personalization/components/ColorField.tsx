'use client';

import { useEffect, useState } from 'react';
import { Check } from 'lucide-react';
import { contrast, isHex, type ColorCheck } from '@/lib/brand';

interface ColorFieldProps {
  id: string;
  label: string;
  hint: string;
  value: string;
  original: string;
  presets: string[];
  check?: ColorCheck | null;
  checkText?: (ratio: number) => string;
  disabled?: boolean;
  onChange: (value: string | null) => void;
}

export default function ColorField({ id, label, hint, value, original, presets, check, checkText, disabled, onChange }: ColorFieldProps) {
  const [text, setText] = useState(value);

  useEffect(() => { setText(value); }, [value]);

  const type = (raw: string) => {
    const next = (raw.startsWith('#') ? raw : `#${raw}`).slice(0, 7).toUpperCase();
    setText(next);
    if (isHex(next)) onChange(next);
  };

  const isOriginal = value.toUpperCase() === original.toUpperCase();

  return (
    <div className="space-y-2" data-color-field={id}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-sm font-semibold text-slate-800">{label}</span>
        {!isOriginal && !disabled && (
          <button type="button" data-color-original={id} onClick={() => onChange(null)} className="text-xs font-bold text-brand-accent hover:text-brand-ink">
            Volver al de fábrica
          </button>
        )}
      </div>
      <span className="block text-xs text-slate-500">{hint}</span>
      <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label={label}>
        {presets.map((color) => {
          const selected = value.toUpperCase() === color.toUpperCase();
          return (
            <button
              key={color}
              type="button"
              role="radio"
              aria-checked={selected}
              aria-label={`Color ${color}`}
              data-preset={color}
              disabled={disabled}
              onClick={() => onChange(color)}
              className={`w-8 h-8 rounded-full flex items-center justify-center border border-black/5 transition-transform hover:scale-110 disabled:hover:scale-100 ${selected ? 'ring-2 ring-offset-2 ring-brand' : ''}`}
              style={{ backgroundColor: color }}
            >
              {selected && <Check size={15} className={contrast(color, '#FFFFFF') >= 3 ? 'text-white' : 'text-slate-900'} />}
            </button>
          );
        })}
        <div className="flex items-center gap-1.5 ml-1">
          <span className="w-8 h-8 rounded-full border border-slate-200" style={{ backgroundColor: isHex(text) ? text : value }} />
          <input
            id={`color-${id}`}
            aria-label={`${label}: código del color`}
            data-color-input={id}
            className="w-24 text-sm border border-slate-200 rounded-lg px-2.5 py-1.5 font-mono uppercase bg-white"
            value={text}
            disabled={disabled}
            onChange={(e) => type(e.target.value.trim())}
            placeholder={original}
          />
        </div>
      </div>
      {check && !check.ok && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-contrast-warning={id}>
          <span className="flex-1 min-w-48">{checkText ? checkText(check.ratio) : `Se lee poco (${check.ratio.toFixed(1)}:1).`} Te sugerimos este tono un poco más oscuro:</span>
          <span className="w-5 h-5 rounded-full border border-black/10" style={{ backgroundColor: check.suggestion }} />
          <span className="font-mono">{check.suggestion}</span>
          {!disabled && (
            <button type="button" data-use-suggestion={id} onClick={() => onChange(check.suggestion)} className="font-bold underline">
              Usar este
            </button>
          )}
        </div>
      )}
    </div>
  );
}
