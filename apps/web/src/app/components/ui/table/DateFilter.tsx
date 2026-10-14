'use client';

import { useMemo, useRef, useState } from 'react';
import { ArrowLeft, CalendarDays, CalendarRange, ChevronDown, ChevronLeft, ChevronRight, Search } from 'lucide-react';
import Floating from '@/app/components/common/Floating';
import { DATE_PRESETS, describeDateFilter, formatDay, fromISO, toISO, type DateFilterValue } from './dateFilters';

interface DateFilterProps {
  label: string;
  value: DateFilterValue | null;
  onChange: (value: DateFilterValue | null) => void;
  future?: boolean;
}

const WEEKDAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const monthGrid = (year: number, month: number): Date[] => {
  const first = new Date(year, month, 1);
  const start = new Date(year, month, 1 - ((first.getDay() + 6) % 7));
  return Array.from({ length: 42 }, (_, i) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + i));
};

export default function DateFilter({ label, value, onChange, future = false }: DateFilterProps) {
  const triggerRef = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<'list' | 'day' | 'range'>('list');
  const [query, setQuery] = useState('');
  const [cursor, setCursor] = useState(() => new Date());
  const [rangeStart, setRangeStart] = useState<string | null>(null);

  const today = toISO(new Date());
  const presets = useMemo(() => {
    const term = normalize(query.trim());
    return DATE_PRESETS.filter((preset) => (future || !preset.future) && (!term || normalize(`${preset.label} ${preset.hint}`).includes(term)));
  }, [query, future]);

  const openPanel = () => {
    setMode('list');
    setQuery('');
    setRangeStart(null);
    setCursor(value?.kind === 'day' ? fromISO(value.date) : value?.kind === 'range' ? fromISO(value.from) : new Date());
    setOpen((current) => !current);
  };

  const apply = (next: DateFilterValue | null) => {
    onChange(next);
    setOpen(false);
  };

  const pickDay = (iso: string) => {
    if (mode === 'day') return apply({ kind: 'day', date: iso });
    if (!rangeStart) return setRangeStart(iso);
    apply(iso < rangeStart ? { kind: 'range', from: iso, to: rangeStart } : { kind: 'range', from: rangeStart, to: iso });
  };

  const selectedFrom = mode === 'range' ? rangeStart ?? (value?.kind === 'range' ? value.from : null) : value?.kind === 'day' ? value.date : null;
  const selectedTo = mode === 'range' ? (rangeStart ? null : value?.kind === 'range' ? value.to : null) : selectedFrom;

  const rowClass = (active: boolean) =>
    `w-full text-left px-3.5 py-2 transition-colors ${active ? 'bg-[#84D0D9]/20' : 'hover:bg-slate-50'}`;

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        data-date-filter={label}
        aria-expanded={open}
        aria-label={label}
        onClick={openPanel}
        className={`flex items-center gap-1 text-xs leading-5 font-medium rounded-md pl-2 pr-1 select-none transition-colors cursor-pointer hover:bg-slate-100 ${open ? 'bg-slate-100' : ''} ${value ? 'text-slate-700' : 'text-slate-500'}`}
      >
        <span className="truncate max-w-56">{describeDateFilter(value)}</span>
        <ChevronDown size={13} className={`text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
      </button>

      <Floating
        anchorRef={triggerRef}
        open={open}
        onClose={() => setOpen(false)}
        className="w-72 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden animate-in fade-in duration-150 flex flex-col"
      >
        {mode === 'list' ? (
          <>
            <div className="p-2.5 border-b border-slate-100">
              <div className="flex items-center gap-2 px-3 py-1.5 rounded-full border border-slate-200 focus-within:border-[#3F768C] transition-colors">
                <input
                  autoFocus
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder="Buscar"
                  aria-label="Buscar periodo"
                  className="flex-1 min-w-0 text-sm text-slate-700 placeholder:text-slate-400 bg-transparent outline-none"
                />
                <Search size={14} className="text-slate-400 shrink-0" />
              </div>
            </div>

            <div className="overflow-y-auto select-scroll py-1 max-h-72 min-h-0 flex-1">
              <button type="button" data-date-option="all" onClick={() => apply(null)} className={rowClass(!value)}>
                <span className="block text-sm text-slate-500">Todas las fechas</span>
              </button>

              {presets.map((preset) => (
                <button
                  key={preset.id}
                  type="button"
                  data-date-option={preset.id}
                  onClick={() => apply({ kind: 'preset', id: preset.id })}
                  className={rowClass(value?.kind === 'preset' && value.id === preset.id)}
                >
                  <span className="block text-sm font-medium text-[#022340]">{preset.label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{preset.hint}</span>
                </button>
              ))}

              {presets.length === 0 && <p className="px-3.5 py-3 text-sm text-slate-400">Ningún periodo con ese nombre.</p>}
            </div>

            <div className="border-t border-slate-100 py-1">
              <button type="button" data-date-option="day" onClick={() => setMode('day')} className={`${rowClass(value?.kind === 'day')} flex items-center gap-2.5`}>
                <CalendarDays size={15} className="text-[#3F768C] shrink-0" />
                <span>
                  <span className="block text-sm font-medium text-[#022340]">Una fecha específica</span>
                  {value?.kind === 'day' && <span className="block text-xs text-slate-500 mt-0.5">{formatDay(value.date)}</span>}
                </span>
              </button>
              <button type="button" data-date-option="range" onClick={() => setMode('range')} className={`${rowClass(value?.kind === 'range')} flex items-center gap-2.5`}>
                <CalendarRange size={15} className="text-[#3F768C] shrink-0" />
                <span>
                  <span className="block text-sm font-medium text-[#022340]">Entre dos fechas</span>
                  {value?.kind === 'range' && <span className="block text-xs text-slate-500 mt-0.5">{describeDateFilter(value)}</span>}
                </span>
              </button>
            </div>
          </>
        ) : (
          <div className="p-3 min-h-0 overflow-y-auto select-scroll" data-date-calendar={mode}>
            <button type="button" onClick={() => { setMode('list'); setRangeStart(null); }} className="inline-flex items-center gap-1.5 text-xs font-medium text-[#3F768C] hover:text-[#022340] transition-colors">
              <ArrowLeft size={13} /> Periodos
            </button>

            <p className="mt-2 text-xs text-slate-500">
              {mode === 'day'
                ? 'Elige el día.'
                : rangeStart
                  ? `Desde el ${formatDay(rangeStart)}. Ahora elige el último día.`
                  : 'Elige el primer día y luego el último.'}
            </p>

            <div className="mt-2.5 flex items-center justify-between">
              <button type="button" aria-label="Mes anterior" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors">
                <ChevronLeft size={15} />
              </button>
              <span className="text-sm font-semibold text-[#022340] first-letter:uppercase" data-date-month>
                {cursor.toLocaleDateString('es-PE', { month: 'long', year: 'numeric' })}
              </span>
              <button type="button" aria-label="Mes siguiente" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors">
                <ChevronRight size={15} />
              </button>
            </div>

            <div className="mt-2 grid grid-cols-7 gap-y-0.5 text-center">
              {WEEKDAYS.map((day) => (
                <span key={day} className="py-1 text-[11px] font-medium text-slate-400">{day}</span>
              ))}

              {monthGrid(cursor.getFullYear(), cursor.getMonth()).map((date) => {
                const iso = toISO(date);
                const outside = date.getMonth() !== cursor.getMonth();
                const isEdge = iso === selectedFrom || iso === selectedTo;
                const inside = Boolean(selectedFrom && selectedTo && iso > selectedFrom && iso < selectedTo);

                return (
                  <button
                    key={iso}
                    type="button"
                    data-day={iso}
                    aria-pressed={isEdge}
                    onClick={() => pickDay(iso)}
                    className={`h-8 text-sm rounded-lg transition-colors ${
                      isEdge
                        ? 'bg-[#022340] text-white font-semibold'
                        : inside
                          ? 'bg-[#84D0D9]/25 text-[#022340]'
                          : `${outside ? 'text-slate-300' : 'text-slate-700'} hover:bg-slate-100`
                    } ${iso === today && !isEdge ? 'ring-1 ring-inset ring-[#3F768C]' : ''}`}
                  >
                    {date.getDate()}
                  </button>
                );
              })}
            </div>
          </div>
        )}
      </Floating>
    </>
  );
}
