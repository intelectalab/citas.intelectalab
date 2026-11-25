'use client';

import { CalendarCheck2, CalendarX2, Repeat } from 'lucide-react';
import Switch from '@/app/components/common/Switch';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';

export interface RepeatValue {
  on: boolean;
  frequency: 'weekly' | 'days' | 'monthly';
  every: number;
  end: 'count' | 'until';
  occurrences: number;
  until: string;
}

export interface SeriesDate {
  date: string;
  ok: boolean;
  reason: string | null;
}

export const REPEAT_OFF: RepeatValue = { on: false, frequency: 'weekly', every: 1, end: 'count', occurrences: 4, until: '' };

export const repeatPayload = (value: RepeatValue) => ({
  frequency: value.frequency,
  every: Math.max(1, value.every || 1),
  ...(value.end === 'count' ? { occurrences: value.occurrences } : { until_date: value.until }),
});

const UNIT: Record<RepeatValue['frequency'], [string, string]> = {
  weekly: ['semana', 'semanas'],
  days: ['día', 'días'],
  monthly: ['mes', 'meses'],
};

const longDate = (iso: string) => {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
};

interface RepeatFieldsProps {
  value: RepeatValue;
  onChange: (value: RepeatValue) => void;
  dates: SeriesDate[] | null;
  checking: boolean;
  canCheck: boolean;
  onCheck: () => void;
}

export default function RepeatFields({ value, onChange, dates, checking, canCheck, onCheck }: RepeatFieldsProps) {
  const set = (patch: Partial<RepeatValue>) => onChange({ ...value, ...patch });
  const [one, many] = UNIT[value.frequency];
  const blocked = dates?.filter((d) => !d.ok).length ?? 0;

  return (
    <div className="border border-slate-200 rounded-xl p-3.5 space-y-3" data-repeat>
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2 min-w-0">
          <Repeat size={16} className="text-slate-400 shrink-0" />
          <div className="min-w-0">
            <span className="block text-sm font-semibold text-slate-800">Repetir esta cita</span>
            <span className="block text-xs text-slate-500">Para tratamientos o sesiones que se repiten, como una terapia cada semana.</span>
          </div>
        </div>
        <Switch checked={value.on} onChange={(on) => set({ on })} label="Repetir esta cita" />
      </div>

      {value.on && (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Se repite"
              clearable={false}
              value={value.frequency}
              onChange={(e) => set({ frequency: String(e.target.value) as RepeatValue['frequency'] })}
              options={[{ value: 'weekly', label: 'Por semanas' }, { value: 'days', label: 'Por días' }, { value: 'monthly', label: 'Por meses' }]}
            />
            <div>
              <label className={labelClass}>Cada</label>
              <div className="flex items-center gap-2">
                <input
                  type="number"
                  min={1}
                  max={90}
                  aria-label="Cada cuánto se repite"
                  className={inputClass}
                  value={value.every}
                  onChange={(e) => set({ every: Math.max(1, Math.min(90, Number(e.target.value) || 1)) })}
                />
                <span className="text-sm text-slate-500 shrink-0">{value.every === 1 ? one : many}</span>
              </div>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Termina"
              clearable={false}
              value={value.end}
              onChange={(e) => set({ end: String(e.target.value) as RepeatValue['end'] })}
              options={[{ value: 'count', label: 'Después de unas veces' }, { value: 'until', label: 'En una fecha' }]}
            />
            {value.end === 'count' ? (
              <div>
                <label className={labelClass}>Veces en total</label>
                <input
                  type="number"
                  min={2}
                  max={52}
                  aria-label="Cuántas citas en total"
                  className={inputClass}
                  value={value.occurrences}
                  onChange={(e) => set({ occurrences: Math.max(2, Math.min(52, Number(e.target.value) || 2)) })}
                />
              </div>
            ) : (
              <div>
                <label className={labelClass}>Hasta el</label>
                <DateField ariaLabel="Última fecha de la serie" value={value.until} onChange={(until) => set({ until })} />
              </div>
            )}
          </div>

          <button
            type="button"
            data-action="check-series"
            disabled={!canCheck || checking}
            onClick={onCheck}
            className="px-3.5 py-2 text-sm font-medium border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
          >
            {checking ? 'Revisando fechas...' : 'Revisar las fechas'}
          </button>

          {dates && (
            <div className="space-y-1.5" data-series-dates>
              <p className="text-xs text-slate-500">
                {blocked === 0
                  ? `Las ${dates.length} fechas están libres.`
                  : `${dates.length - blocked} de ${dates.length} fechas están libres. Las marcadas en rojo no se agendarán; las demás sí.`}
              </p>
              <ul className="max-h-48 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-100">
                {dates.map((d) => (
                  <li key={d.date} data-series-date={d.date} data-ok={d.ok ? '1' : '0'} className={`flex items-start gap-2 px-3 py-1.5 text-xs ${d.ok ? 'text-slate-700' : 'text-rose-700 bg-rose-50'}`}>
                    {d.ok ? <CalendarCheck2 size={14} className="mt-0.5 shrink-0 text-emerald-600" /> : <CalendarX2 size={14} className="mt-0.5 shrink-0" />}
                    <span><span className="font-semibold capitalize">{longDate(d.date)}</span>{d.reason ? ` · ${d.reason}` : ''}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </>
      )}
    </div>
  );
}
