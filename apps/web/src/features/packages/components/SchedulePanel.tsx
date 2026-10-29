'use client';

import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { AlertTriangle, CalendarClock, CalendarDays, Check, Loader2, Pencil, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import DateField from '@/app/components/common/DateField';
import TimeField from '@/app/components/common/TimeField';
import { apiError, todayISO } from '@/features/appointments/appointments';
import {
  FREQUENCY_HINTS, WEEKDAYS, dayLabel, hour12, sessionsLabel,
  type CheckedSlot, type CustomDay, type Frequency, type FreeTimes, type ProfessionalMode, type ScheduleDetails, type SchedulePreview, type SlotInput,
} from '../packages';

export interface ScheduleSource {
  kind: 'plan' | 'package';
  id: string;
  name: string;
  frequency: Frequency;
  custom_days: CustomDay[];
  repeat_weeks: number;
  professional_mode: ProfessionalMode;
  professional_id?: string | null;
}

interface SchedulePanelProps {
  isOpen: boolean;
  onClose: () => void;
  source: ScheduleSource | null;
  branchId: string | null;
  customerId: string | null;
  initial?: ScheduleDetails | null;
  confirmLabel: (count: number) => string;
  onConfirm: (details: ScheduleDetails) => Promise<void> | void;
}

const FREQUENCIES: { value: Frequency; label: string }[] = [
  { value: 'weekly', label: 'Cada semana' },
  { value: 'twice_weekly', label: '2 veces por semana' },
  { value: 'biweekly', label: 'Cada 15 días' },
  { value: 'monthly', label: 'Cada mes' },
  { value: 'custom', label: 'Personalizado' },
];

const tomorrow = () => {
  const date = new Date();
  date.setDate(date.getDate() + 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

export default function SchedulePanel({ isOpen, onClose, source, branchId, customerId, initial, confirmLabel, onConfirm }: SchedulePanelProps) {
  const [professionalId, setProfessionalId] = useState('');
  const [frequency, setFrequency] = useState<Frequency>('weekly');
  const [customDays, setCustomDays] = useState<CustomDay[]>([]);
  const [repeatWeeks, setRepeatWeeks] = useState(1);
  const [firstDate, setFirstDate] = useState(tomorrow());
  const [time, setTime] = useState('');
  const [preview, setPreview] = useState<SchedulePreview | null>(null);
  const [slots, setSlots] = useState<CheckedSlot[]>([]);
  const [editing, setEditing] = useState<number | null>(null);
  const [pickDate, setPickDate] = useState('');
  const [free, setFree] = useState<FreeTimes | null>(null);
  const [loadingFree, setLoadingFree] = useState(false);
  const [checking, setChecking] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const base = () => ({
    branch_id: branchId,
    customer_id: customerId,
    ...(source?.kind === 'plan' ? { plan_id: source.id } : { package_id: source?.id }),
    professional_id: professionalId || null,
  });

  const check = async (body: Record<string, unknown>) => {
    try {
      setChecking(true);
      setError('');
      const res = await api.post('/packages/schedule/preview', { ...base(), ...body });
      const data: SchedulePreview = res.data;
      setPreview(data);
      setSlots(data.slots);
      return data;
    } catch (err) {
      setError(apiError(err, 'No se pudieron revisar las fechas.'));
      return null;
    } finally {
      setChecking(false);
    }
  };

  useEffect(() => {
    if (!isOpen || !source) return;

    setError('');
    setEditing(null);
    setFree(null);
    setProfessionalId(initial?.professional_id ?? source.professional_id ?? '');
    setFrequency(source.frequency);
    setCustomDays(source.custom_days ?? []);
    setRepeatWeeks(source.repeat_weeks || 1);
    setFirstDate(initial?.slots?.[0]?.date ?? tomorrow());
    setTime(initial?.slots?.[0]?.time ?? source.custom_days?.find((d) => d.time)?.time ?? '');
    setPreview(null);
    setSlots([]);

    if (initial?.slots?.length && branchId && customerId) {
      api.post('/packages/schedule/preview', {
        branch_id: branchId,
        customer_id: customerId,
        ...(source.kind === 'plan' ? { plan_id: source.id } : { package_id: source.id }),
        professional_id: initial.professional_id || null,
        slots: initial.slots,
      }).then((res) => { setPreview(res.data); setSlots(res.data.slots); }).catch((err) => setError(apiError(err, 'No se pudieron revisar las fechas.')));
    } else if (branchId && customerId) {
      api.post('/packages/schedule/preview', {
        branch_id: branchId,
        customer_id: customerId,
        ...(source.kind === 'plan' ? { plan_id: source.id } : { package_id: source.id }),
        count: 1,
        slots: [],
      }).then((res) => { setPreview({ ...res.data, slots: [] }); }).catch(() => undefined);
    }
  }, [isOpen, source, initial, branchId, customerId]);

  const chosenMode = source?.professional_mode === 'chosen';
  const customNeedsTime = frequency === 'custom' && customDays.some((d) => !d.time);
  const canGenerate = Boolean(firstDate) && (frequency === 'custom' ? customDays.length > 0 && (!customNeedsTime || time) : Boolean(time)) && (!chosenMode || professionalId);

  const generate = () => check({
    first_date: firstDate,
    time: time || null,
    frequency,
    custom_days: frequency === 'custom' ? customDays : [],
    repeat_weeks: repeatWeeks,
  });

  const asInput = (list: CheckedSlot[]): SlotInput[] => list.map((s) => ({ date: s.date, time: s.time, professional_id: chosenMode ? professionalId || s.professional?.id : s.professional?.id ?? null }));

  const loadFree = async (date: string) => {
    if (!preview || !branchId) return;
    try {
      setLoadingFree(true);
      setPickDate(date);
      const res = await api.get('/packages/free-times', { params: { branch_id: branchId, service_id: preview.service.id, date, customer_id: customerId ?? undefined, professional_id: chosenMode && professionalId ? professionalId : undefined } });
      setFree(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudieron cargar las horas libres.'));
    } finally {
      setLoadingFree(false);
    }
  };

  const openEdit = (index: number) => {
    setEditing(index);
    setFree(null);
    loadFree(slots[index].date);
  };

  const pick = async (date: string, picked: FreeTimes['times'][number]) => {
    if (editing === null) return;
    const next = asInput(slots).map((s, i) => (i === editing ? { date, time: picked.time, professional_id: chosenMode ? professionalId : picked.professionals[0]?.id ?? null } : s));
    setEditing(null);
    setFree(null);
    await check({ slots: next });
  };

  const removeSlot = async (index: number) => {
    const next = asInput(slots).filter((_, i) => i !== index);
    setEditing(null);
    setFree(null);
    if (next.length === 0) { setSlots([]); return; }
    await check({ slots: next });
  };

  const okCount = slots.filter((s) => s.ok).length;
  const badCount = slots.length - okCount;

  const confirm = async () => {
    try {
      setSaving(true);
      setError('');
      await onConfirm({ professional_id: professionalId || null, branch_id: branchId, slots: asInput(slots) });
    } catch (err) {
      setError(apiError(err, 'No se pudieron agendar las sesiones.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Agendar sesiones"
      subtitle={source ? `${source.name}${preview ? ` · ${sessionsLabel(preview.count)} de ${preview.service.name} · vence el ${dayLabel(preview.expires_at)}` : ''}` : undefined}
      icon={<CalendarClock size={20} />}
      maxWidth="max-w-2xl"
    >
      <div className="flex flex-col h-full justify-between">
        <div className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-schedule>
          <section className="space-y-3 rounded-2xl border border-slate-200 p-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Select
                label={chosenMode ? 'Profesional *' : 'Profesional'}
                placeholder={chosenMode ? 'Elige quién atiende' : 'El que esté libre'}
                value={professionalId}
                clearable={!chosenMode}
                onChange={(e) => setProfessionalId(String(e.target.value))}
                options={(preview?.professionals ?? []).map((p) => ({ value: p.id, label: p.name }))}
              />
              <Select
                label="Frecuencia"
                value={frequency}
                clearable={false}
                onChange={(e) => setFrequency(String(e.target.value) as Frequency)}
                options={FREQUENCIES}
              />
            </div>
            <p className="-mt-1 text-xs text-slate-500">{chosenMode ? 'Este paquete se atiende siempre con la misma persona.' : 'Si no eliges a nadie, cada fecha queda con quien esté libre.'} {FREQUENCY_HINTS[frequency]}.</p>

            {frequency === 'custom' && (
              <div className="rounded-xl border border-slate-200 divide-y divide-slate-100" data-schedule-days>
                {[1, 2, 3, 4, 5, 6, 0].map((weekday) => {
                  const day = customDays.find((d) => d.weekday === weekday);
                  return (
                    <div key={weekday} className="flex items-center gap-3 px-3.5 py-2" data-weekday={weekday}>
                      <Switch checked={Boolean(day)} label={`Sesión los ${WEEKDAYS[weekday]}`}
                        onChange={(on) => setCustomDays((current) => (on ? [...current, { weekday, time: null }] : current.filter((d) => d.weekday !== weekday)).sort((a, b) => a.weekday - b.weekday))} />
                      <span className={`w-24 text-sm capitalize ${day ? 'font-semibold text-slate-800' : 'text-slate-400'}`}>{WEEKDAYS[weekday]}</span>
                      {day && (
                        <div className="w-40">
                          <TimeField size="compact" ariaLabel={`Hora del ${WEEKDAYS[weekday]}`} placeholder="Hora" value={day.time ?? ''} clearable
                            onChange={(value) => setCustomDays((current) => current.map((d) => (d.weekday === weekday ? { ...d, time: value || null } : d)))} />
                        </div>
                      )}
                    </div>
                  );
                })}
                <div className="flex items-center justify-between gap-3 px-3.5 py-2.5">
                  <span className="text-sm text-slate-600">Repetir</span>
                  <div className="flex gap-1.5">
                    {[1, 2].map((weeks) => (
                      <button key={weeks} type="button" onClick={() => setRepeatWeeks(weeks)}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${repeatWeeks === weeks ? 'bg-brand text-white border-brand' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                        {weeks === 1 ? 'Cada semana' : 'Cada 2 semanas'}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <div>
                <span className={labelClass}>Primera sesión *</span>
                <DateField ariaLabel="Primera sesión" value={firstDate} min={todayISO()} onChange={setFirstDate} />
              </div>
              {(frequency !== 'custom' || customNeedsTime) && (
                <div>
                  <span className={labelClass}>{frequency === 'custom' ? 'Hora de los días sin hora *' : 'Hora *'}</span>
                  <TimeField ariaLabel="Hora de las sesiones" value={time} onChange={setTime} />
                </div>
              )}
            </div>

            <button type="button" data-generate disabled={!canGenerate || checking} onClick={generate}
              className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold border border-brand text-brand-ink rounded-xl hover:bg-brand-soft/15 transition-colors disabled:opacity-50">
              {checking ? <Loader2 size={15} className="animate-spin" /> : <CalendarDays size={15} />} {slots.length ? 'Volver a armar las fechas' : 'Ver las fechas'}
            </button>
          </section>

          {slots.length > 0 && (
            <section className="space-y-2" data-slots>
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-black text-brand-ink">Fechas</h3>
                <span className={`text-xs font-semibold ${badCount ? 'text-amber-700' : 'text-emerald-700'}`} data-slots-summary>
                  {okCount} de {slots.length} libres{badCount ? ` · ${badCount} por cambiar` : ''}
                </span>
              </div>

              <ul className="rounded-2xl border border-slate-200 divide-y divide-slate-100">
                {slots.map((slot, index) => (
                  <li key={`${slot.date}-${index}`} className={slot.ok ? '' : 'bg-amber-50/40'} data-slot={index} data-slot-ok={slot.ok ? 'yes' : 'no'}>
                    <div className="flex items-center gap-3 px-4 py-2.5">
                      <span className={`w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${slot.ok ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-100 text-amber-700'}`}>
                        {slot.ok ? <Check size={13} /> : <AlertTriangle size={13} />}
                      </span>
                      <div className="flex-1 min-w-0">
                        <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{index + 1}. {dayLabel(slot.date)}{slot.time ? ` · ${hour12(slot.time)}` : ''}</span>
                        <span className={`block text-xs ${slot.ok ? 'text-slate-500' : 'text-amber-800'}`}>
                          {slot.ok ? slot.professional?.name : slot.reasons.map((r) => r.message).join(' · ')}
                        </span>
                      </div>
                      <button type="button" data-slot-edit={index} onClick={() => (editing === index ? setEditing(null) : openEdit(index))}
                        className={`flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold rounded-lg transition-colors ${slot.ok ? 'text-slate-600 hover:bg-slate-100' : 'bg-brand text-white hover:bg-brand-hover'}`}>
                        <Pencil size={12} /> Cambiar
                      </button>
                      <button type="button" title="Quitar esta fecha" onClick={() => removeSlot(index)} className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                        <Trash2 size={14} />
                      </button>
                    </div>

                    {editing === index && (
                      <div className="px-4 pb-4 space-y-3" data-free-times>
                        <div className="flex items-end gap-3">
                          <div className="w-52">
                            <span className={labelClass}>Otro día</span>
                            <DateField size="compact" ariaLabel="Elegir otro día" value={pickDate} min={todayISO()} max={preview?.expires_at} onChange={(value) => value && loadFree(value)} />
                          </div>
                          {loadingFree && <Loader2 size={16} className="mb-2 animate-spin text-slate-400" />}
                        </div>

                        {free && free.times.length > 0 && (
                          <div>
                            <span className="block text-xs font-semibold text-slate-600 mb-1.5 first-letter:uppercase">Horas libres del {dayLabel(free.date)}{chosenMode && professionalId ? '' : ' (con quien esté libre)'}</span>
                            <div className="flex flex-wrap gap-1.5">
                              {free.times.map((t) => (
                                <button key={t.time} type="button" data-free-time={t.time} title={t.professionals.map((p) => p.name).join(', ')} onClick={() => pick(free.date, t)}
                                  className="px-2.5 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 text-slate-700 hover:border-brand hover:bg-brand-soft/15 transition-colors">
                                  {t.label}
                                </button>
                              ))}
                            </div>
                          </div>
                        )}

                        {free && free.times.length === 0 && (
                          <div className="space-y-2" data-day-full>
                            <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 first-letter:uppercase">
                              {dayLabel(free.date)}: {chosenMode && professionalId ? `${preview?.professionals.find((p) => p.id === professionalId)?.name ?? 'el profesional'} no tiene` : 'no hay'} horas libres.
                              {free.next_days.length ? ' Estos días sí tienen espacio:' : ' Tampoco en las próximas tres semanas: prueba otra fecha.'}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                              {free.next_days.map((day) => (
                                <button key={day.date} type="button" data-next-day={day.date} onClick={() => loadFree(day.date)}
                                  className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 text-slate-700 hover:border-brand hover:bg-brand-soft/15 transition-colors first-letter:uppercase">
                                  {dayLabel(day.date)} · {day.count} {day.count === 1 ? 'hora' : 'horas'}
                                </button>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
              {preview && slots.length < preview.count && (
                <p className="text-xs text-slate-500">Quedan {sessionsLabel(preview.count - slots.length)} sin fecha: se podrán agendar después desde el paquete.</p>
              )}
            </section>
          )}

          <ErrorAlert message={error} />
        </div>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="button" data-confirm-schedule disabled={saving || checking || slots.length === 0 || badCount > 0} onClick={confirm}
            title={badCount ? 'Cambia las fechas marcadas antes de seguir' : undefined} variant="primary">
            {saving ? 'Guardando...' : confirmLabel(slots.length)}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
