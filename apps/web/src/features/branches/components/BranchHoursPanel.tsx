'use client';

import { useEffect, useState } from 'react';
import { Clock, Plus, X } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Switch from '@/app/components/common/Switch';
import TimeField from '@/app/components/common/TimeField';
import { apiError } from '@/features/appointments/appointments';
import { WEEK, type BranchHoursMap, type HourRange } from '../branches';

interface BranchHoursPanelProps {
  isOpen: boolean;
  onClose: () => void;
  branch: { id: string; name: string } | null;
  onSaved: () => void;
}

interface Outside {
  professional: string | null;
  weekday: number;
  starts: string;
  ends: string;
}

const DEFAULT_RANGE: HourRange = { opens: '08:00', closes: '20:00' };

const emptyWeek = (): Record<number, HourRange[]> => ({ 0: [], 1: [], 2: [], 3: [], 4: [], 5: [], 6: [] });

// Horario de atención de una sucursal: qué días abre y a qué horas. Fuera de
// ese horario la agenda no ofrece citas, aunque un profesional tenga horario.
export default function BranchHoursPanel({ isOpen, onClose, branch, onSaved }: BranchHoursPanelProps) {
  const [week, setWeek] = useState<Record<number, HourRange[]>>(emptyWeek);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [outside, setOutside] = useState<Outside[]>([]);

  useEffect(() => {
    if (!isOpen || !branch) return;

    setLoading(true);
    setError('');
    setSaved(false);
    setOutside([]);

    api.get(`/branches/${branch.id}`)
      .then((res) => {
        const hours: BranchHoursMap = res.data.hours ?? {};
        const next = emptyWeek();
        Object.entries(hours).forEach(([weekday, ranges]) => { next[Number(weekday)] = (ranges as HourRange[]).map((r) => ({ ...r })); });
        setWeek(next);
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el horario.')))
      .finally(() => setLoading(false));
  }, [isOpen, branch]);

  const updateDay = (weekday: number, ranges: HourRange[]) => {
    setWeek((current) => ({ ...current, [weekday]: ranges }));
    setSaved(false);
  };

  // Al encender un día toma el horario del día anterior que abre.
  const toggleDay = (weekday: number, on: boolean) => {
    if (!on) return updateDay(weekday, []);

    const index = WEEK.findIndex((day) => day.weekday === weekday);
    const previous = WEEK.slice(0, index).reverse().find((day) => week[day.weekday].length > 0);
    updateDay(weekday, previous ? week[previous.weekday].map((range) => ({ ...range })) : [{ ...DEFAULT_RANGE }]);
  };

  const addRange = (weekday: number) => {
    const last = week[weekday][week[weekday].length - 1];
    updateDay(weekday, [...week[weekday], { opens: last?.closes ?? DEFAULT_RANGE.opens, closes: '22:00' }]);
  };

  const save = async () => {
    if (!branch) return;

    try {
      setSaving(true);
      setError('');
      const ranges = WEEK.flatMap((day) => week[day.weekday].map((range) => ({ weekday: day.weekday, ...range })));
      const res = await api.put(`/branches/${branch.id}/hours`, { ranges });
      setOutside(res.data.outside ?? []);
      setSaved(true);
      onSaved();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el horario.'));
    } finally {
      setSaving(false);
    }
  };

  const openDays = WEEK.filter((day) => week[day.weekday].length > 0).length;
  const dayName = (weekday: number) => WEEK.find((day) => day.weekday === weekday)?.label.toLowerCase() ?? '';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Horario de ${branch?.name ?? ''}`}
      subtitle="Días y horas en que la sucursal atiende"
      icon={<Clock size={20} />}
    >
      {loading ? (
        <p className="text-sm text-slate-500">Cargando horario...</p>
      ) : (
        <div className="space-y-5 pb-4" data-hours-panel>
          <p className="text-sm text-slate-600">
            Fuera de este horario la agenda no ofrece citas en esta sucursal, aunque alguien tenga horario. El administrador puede agendar igual indicando un motivo.
          </p>

          <div className="border border-slate-200 rounded-xl divide-y divide-slate-100">
            {WEEK.map((day) => {
              const ranges = week[day.weekday];
              const isOn = ranges.length > 0;

              return (
                <div key={day.weekday} className="flex items-start gap-3 px-4 py-3" data-day={day.weekday}>
                  <div className="flex items-center gap-3 w-36 shrink-0 pt-1.5">
                    <Switch checked={isOn} onChange={(on) => toggleDay(day.weekday, on)} label={`Abre el ${day.label.toLowerCase()}`} />
                    <span className={`text-sm font-semibold ${isOn ? 'text-slate-800' : 'text-slate-400'}`}>{day.label}</span>
                  </div>

                  <div className="flex-1 space-y-2">
                    {!isOn && <span className="block text-sm text-slate-400 pt-1.5">Cerrada</span>}

                    {ranges.map((range, index) => (
                      <div key={index} className="flex items-center gap-2">
                        <TimeField
                          size="compact"
                          clearable={false}
                          ariaLabel={`${day.label}: abre`}
                          value={range.opens}
                          onChange={(value) => updateDay(day.weekday, ranges.map((r, i) => (i === index ? { ...r, opens: value } : r)))}
                        />
                        <span className="text-sm text-slate-400">a</span>
                        <TimeField
                          size="compact"
                          clearable={false}
                          ariaLabel={`${day.label}: cierra`}
                          value={range.closes}
                          onChange={(value) => updateDay(day.weekday, ranges.map((r, i) => (i === index ? { ...r, closes: value } : r)))}
                        />
                        {ranges.length > 1 && (
                          <button
                            type="button"
                            title="Quitar tramo"
                            onClick={() => updateDay(day.weekday, ranges.filter((_, i) => i !== index))}
                            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                          >
                            <X size={15} />
                          </button>
                        )}
                      </div>
                    ))}

                    {isOn && (
                      <button type="button" onClick={() => addRange(day.weekday)} className="flex items-center gap-1 text-xs font-semibold text-[#3F768C] hover:text-[#022340] transition-colors">
                        <Plus size={13} /> Agregar tramo
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>

          {openDays === 0 && (
            <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2" data-no-hours>
              Sin ningún día encendido, la sucursal no limita la agenda: vale solo el horario de cada persona.
            </p>
          )}

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
          {saved && <p data-saved className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">Horario guardado.</p>}

          {saved && outside.length > 0 && (
            <div className="border border-amber-200 bg-amber-50 rounded-xl p-4 space-y-2" data-outside={outside.length}>
              <p className="text-sm font-semibold text-amber-800">
                {outside.length === 1 ? 'Un horario de tu equipo queda fuera de la sucursal.' : `${outside.length} horarios de tu equipo quedan fuera de la sucursal.`}{' '}
                <span className="font-normal">En esas horas la agenda ya no ofrece citas. Ajusta su horario o amplía el de la sede.</span>
              </p>
              <ul className="text-xs text-amber-800 space-y-0.5">
                {outside.slice(0, 8).map((item, index) => (
                  <li key={index}>{item.professional} · {dayName(item.weekday)} de {item.starts} a {item.ends}</li>
                ))}
              </ul>
              {outside.length > 8 && <p className="text-xs text-amber-800">y {outside.length - 8} más.</p>}
            </div>
          )}

          <div className="flex justify-end gap-3">
            <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">
              Cerrar
            </button>
            <button type="button" data-action="save-hours" onClick={save} disabled={saving} className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
              {saving ? 'Guardando...' : 'Guardar horario'}
            </button>
          </div>
        </div>
      )}
    </FormDrawer>
  );
}
