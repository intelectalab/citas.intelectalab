'use client';

import { useEffect, useRef, useState } from 'react';
import { Clock } from 'lucide-react';
import Floating from './Floating';

interface TimeFieldProps {
  value: string;
  onChange: (value: string) => void;
  id?: string;
  ariaLabel?: string;
  placeholder?: string;
  step?: number;
  required?: boolean;
  disabled?: boolean;
  clearable?: boolean;
  size?: 'field' | 'compact';
  className?: string;
}

const pad = (n: number) => String(n).padStart(2, '0');
const HOURS = Array.from({ length: 24 }, (_, i) => pad(i));

export default function TimeField({
  value,
  onChange,
  id,
  ariaLabel,
  placeholder = '--:--',
  step = 5,
  required = false,
  disabled = false,
  clearable,
  size = 'field',
  className = '',
}: TimeFieldProps) {
  const triggerRef = useRef<HTMLButtonElement>(null);
  const hoursRef = useRef<HTMLDivElement>(null);
  const minutesRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);

  const current = /^\d{2}:\d{2}/.test(value) ? value.slice(0, 5) : '';
  const hour = current ? current.slice(0, 2) : '';
  const minute = current ? current.slice(3, 5) : '';
  const canClear = clearable ?? !required;

  const minutes = Array.from({ length: Math.ceil(60 / step) }, (_, i) => pad(i * step));
  if (minute && !minutes.includes(minute)) minutes.push(minute);
  minutes.sort();

  useEffect(() => {
    if (!open) return;
    [hoursRef.current, minutesRef.current].forEach((column) => {
      const selected = column?.querySelector<HTMLElement>('[aria-selected="true"]');
      if (column && selected) column.scrollTop = selected.offsetTop - column.clientHeight / 2 + selected.clientHeight / 2;
    });
  }, [open]);

  const optionClass = (selected: boolean) =>
    `w-full px-3 py-1.5 text-sm text-center rounded-md transition-colors ${
      selected ? 'bg-[#022340] text-white font-semibold' : 'text-slate-700 hover:bg-slate-100'
    }`;

  return (
    <div className={`relative ${className || (size === 'compact' ? 'w-24' : 'w-full')}`}>
      <button
        ref={triggerRef}
        id={id}
        type="button"
        data-time-field
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => !disabled && setOpen((isOpen) => !isOpen)}
        className={`w-full flex items-center justify-between gap-2 text-sm border rounded-lg text-left transition-all select-none ${
          size === 'compact' ? 'px-2.5 py-2' : 'px-3.5 py-2.5'
        } ${disabled ? 'bg-slate-50 text-slate-600 cursor-not-allowed' : 'bg-white cursor-pointer'} ${
          open ? 'border-[#022340] ring-2 ring-[#022340]/20' : 'border-slate-200 hover:border-slate-300'
        }`}
      >
        <span className={`tabular-nums ${current ? 'text-slate-800' : 'text-slate-400'}`}>{current || placeholder}</span>
        <Clock size={15} className="text-slate-400 shrink-0" />
      </button>

      {required && (
        <input
          tabIndex={-1}
          aria-hidden="true"
          required
          value={current}
          onChange={() => {}}
          className="absolute bottom-0 left-1/2 w-px h-px opacity-0 pointer-events-none"
        />
      )}

      <Floating
        anchorRef={triggerRef}
        open={open}
        onClose={() => setOpen(false)}
        role="dialog"
        className="w-44 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden animate-in fade-in duration-150 flex flex-col"
      >
        <div className="grid grid-cols-2 px-2 pt-2 text-center text-[11px] font-medium text-slate-400">
          <span>Hora</span>
          <span>Minutos</span>
        </div>

        <div className="grid grid-cols-2 gap-1 p-2 min-h-0">
          <div ref={hoursRef} role="listbox" aria-label="Hora" data-time-hours className="relative max-h-52 overflow-y-auto select-scroll space-y-0.5">
            {HOURS.map((option) => (
              <button
                key={option}
                type="button"
                role="option"
                aria-selected={option === hour}
                data-hour={option}
                onClick={() => onChange(`${option}:${minute || '00'}`)}
                className={optionClass(option === hour)}
              >
                {option}
              </button>
            ))}
          </div>

          <div ref={minutesRef} role="listbox" aria-label="Minutos" data-time-minutes className="relative max-h-52 overflow-y-auto select-scroll space-y-0.5">
            {minutes.map((option) => (
              <button
                key={option}
                type="button"
                role="option"
                aria-selected={option === minute}
                data-minute={option}
                onClick={() => { onChange(`${hour || '00'}:${option}`); setOpen(false); }}
                className={optionClass(option === minute)}
              >
                {option}
              </button>
            ))}
          </div>
        </div>

        {canClear && current && (
          <div className="px-3 py-2 border-t border-slate-100 text-right">
            <button type="button" data-time-clear onClick={() => { onChange(''); setOpen(false); }} className="text-xs font-medium text-slate-500 hover:text-red-600 transition-colors">
              Quitar hora
            </button>
          </div>
        )}
      </Floating>
    </div>
  );
}
