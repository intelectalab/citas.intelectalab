'use client';

import { WEEKDAY_LETTERS as WEEKDAYS } from '@/lib/dates';
import { useRef, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import Floating from './Floating';
import { formatDay, fromISO, toISO } from '@/app/components/ui/table/dateFilters';

interface DateFieldProps {
  value: string;
  onChange: (value: string) => void;
  id?: string;
  ariaLabel?: string;
  placeholder?: string;
  min?: string;
  max?: string;
  required?: boolean;
  disabled?: boolean;
  clearable?: boolean;
  size?: 'field' | 'compact';
  rounded?: 'lg' | 'xl';
  align?: 'left' | 'right';
  className?: string;
}

const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const YEARS_PER_PAGE = 12;

const monthGrid = (year: number, month: number): Date[] => {
  const first = new Date(year, month, 1);
  const start = new Date(year, month, 1 - ((first.getDay() + 6) % 7));
  return Array.from({ length: 42 }, (_, i) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + i));
};

export default function DateField({
  value,
  onChange,
  id,
  ariaLabel,
  placeholder = 'Elige una fecha',
  min,
  max,
  required = false,
  disabled = false,
  clearable,
  size = 'field',
  rounded = 'lg',
  align = 'left',
  className = '',
}: DateFieldProps) {
  const triggerRef = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [view, setView] = useState<'days' | 'months' | 'years'>('days');
  const [cursor, setCursor] = useState(() => new Date());

  const today = toISO(new Date());
  const canClear = clearable ?? !required;
  const allowed = (iso: string) => (!min || iso >= min) && (!max || iso <= max);

  const toggle = () => {
    if (disabled) return;
    setView('days');
    setCursor(value ? fromISO(value) : min && min > today ? fromISO(min) : max && max < today ? fromISO(max) : new Date());
    setOpen((current) => !current);
  };

  const pick = (iso: string) => {
    onChange(iso);
    setOpen(false);
  };

  const year = cursor.getFullYear();
  const month = cursor.getMonth();
  const firstYear = year - (year % YEARS_PER_PAGE);

  const move = (direction: 1 | -1) => {
    if (view === 'days') setCursor(new Date(year, month + direction, 1));
    else if (view === 'months') setCursor(new Date(year + direction, month, 1));
    else setCursor(new Date(year + direction * YEARS_PER_PAGE, month, 1));
  };

  const title =
    view === 'days'
      ? cursor.toLocaleDateString('es-PE', { month: 'long', year: 'numeric' })
      : view === 'months'
        ? String(year)
        : `${firstYear} – ${firstYear + YEARS_PER_PAGE - 1}`;

  const cellClass = (selected: boolean, muted = false) =>
    `text-sm rounded-lg transition-colors ${
      selected ? 'bg-[#022340] text-white font-semibold' : `${muted ? 'text-slate-300' : 'text-slate-700'} hover:bg-slate-100`
    }`;

  return (
    <div className={`relative ${className || 'w-full'}`}>
      <button
        ref={triggerRef}
        id={id}
        type="button"
        data-date-field
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={toggle}
        className={`w-full flex items-center justify-between gap-2 text-sm border text-left transition-all select-none ${
          rounded === 'xl' ? 'rounded-xl' : 'rounded-lg'
        } ${size === 'compact' ? 'px-2.5 py-2' : 'px-3.5 py-2.5'} ${
          disabled ? 'bg-slate-50 text-slate-600 cursor-not-allowed' : 'bg-white cursor-pointer'
        } ${open ? 'border-[#022340] ring-2 ring-[#022340]/20' : 'border-slate-200 hover:border-slate-300'}`}
      >
        <span className={`truncate ${value ? (disabled ? 'text-slate-600' : 'text-slate-800') : 'text-slate-400'}`}>
          {value ? formatDay(value) : placeholder}
        </span>
        <CalendarDays size={16} className="text-slate-400 shrink-0" />
      </button>

      {required && (
        <input
          tabIndex={-1}
          aria-hidden="true"
          required
          value={value}
          onChange={() => {}}
          className="absolute bottom-0 left-1/2 w-px h-px opacity-0 pointer-events-none"
        />
      )}

      <Floating
        anchorRef={triggerRef}
        open={open}
        onClose={() => setOpen(false)}
        align={align}
        role="dialog"
        className="w-72 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden animate-in fade-in duration-150 flex flex-col"
      >
        <div className="p-3 min-h-0 overflow-y-auto select-scroll" data-date-calendar={view}>
          <div className="flex items-center justify-between">
            <button type="button" aria-label="Anterior" onClick={() => move(-1)} className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors">
              <ChevronLeft size={15} />
            </button>
            <button
              type="button"
              data-date-title
              title={view === 'years' ? undefined : view === 'days' ? 'Elegir mes y año' : 'Elegir año'}
              disabled={view === 'years'}
              onClick={() => setView(view === 'days' ? 'months' : 'years')}
              className="px-2.5 py-1 rounded-lg text-sm font-semibold text-[#022340] first-letter:uppercase enabled:hover:bg-slate-100 transition-colors"
            >
              {title}
            </button>
            <button type="button" aria-label="Siguiente" onClick={() => move(1)} className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors">
              <ChevronRight size={15} />
            </button>
          </div>

          {view === 'days' && (
            <div className="mt-2 grid grid-cols-7 gap-y-0.5 text-center">
              {WEEKDAYS.map((day) => (
                <span key={day} className="py-1 text-[11px] font-medium text-slate-400">{day}</span>
              ))}

              {monthGrid(year, month).map((date) => {
                const iso = toISO(date);
                const selected = iso === value;
                const blocked = !allowed(iso);

                return (
                  <button
                    key={iso}
                    type="button"
                    data-day={iso}
                    aria-pressed={selected}
                    disabled={blocked}
                    onClick={() => pick(iso)}
                    className={`h-8 ${
                      blocked ? 'text-sm rounded-lg text-slate-300 line-through cursor-not-allowed' : cellClass(selected, date.getMonth() !== month)
                    } ${iso === today && !selected ? 'ring-1 ring-inset ring-[#3F768C]' : ''}`}
                  >
                    {date.getDate()}
                  </button>
                );
              })}
            </div>
          )}

          {view === 'months' && (
            <div className="mt-3 grid grid-cols-3 gap-1.5">
              {MONTHS.map((name, index) => (
                <button
                  key={name}
                  type="button"
                  data-month={index}
                  onClick={() => { setCursor(new Date(year, index, 1)); setView('days'); }}
                  className={`h-10 ${cellClass(Boolean(value) && fromISO(value).getFullYear() === year && fromISO(value).getMonth() === index)}`}
                >
                  {name}
                </button>
              ))}
            </div>
          )}

          {view === 'years' && (
            <div className="mt-3 grid grid-cols-3 gap-1.5">
              {Array.from({ length: YEARS_PER_PAGE }, (_, i) => firstYear + i).map((option) => (
                <button
                  key={option}
                  type="button"
                  data-year={option}
                  onClick={() => { setCursor(new Date(option, month, 1)); setView('months'); }}
                  className={`h-10 ${cellClass(Boolean(value) && fromISO(value).getFullYear() === option)}`}
                >
                  {option}
                </button>
              ))}
            </div>
          )}

          <div className="mt-2.5 pt-2.5 border-t border-slate-100 flex items-center justify-between">
            {allowed(today) ? (
              <button type="button" data-date-today onClick={() => pick(today)} className="text-xs font-semibold text-[#3F768C] hover:text-[#022340] transition-colors">
                Hoy
              </button>
            ) : <span />}
            {canClear && value && (
              <button type="button" data-date-clear onClick={() => pick('')} className="text-xs font-medium text-slate-500 hover:text-red-600 transition-colors">
                Quitar fecha
              </button>
            )}
          </div>
        </div>
      </Floating>
    </div>
  );
}
