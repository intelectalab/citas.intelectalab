'use client';

import { useEffect, useState } from 'react';
import { AlertTriangle, Building2, CalendarOff, UserRound } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { useVocabulary } from '@/hooks/useVocabulary';
import { addDays, apiError, dateOf, formatDateTime, timeOf, todayISO } from '@/features/appointments/appointments';
import { isAllDay, type AffectedAppointment, type Block, type BlockKind, type BlockOptions } from '../blocks';

interface FormBlockProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Block | null;
  options: BlockOptions | null;
  onSaved: () => void;
}

const ALL_BRANCHES = 'all';

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

const emptyForm = () => ({
  scope: 'professional' as 'professional' | 'branch',
  professional_id: '',
  branch_id: '',
  kind: 'vacation' as BlockKind,
  allDay: true,
  startDate: todayISO(),
  endDate: todayISO(),
  startTime: '09:00',
  endTime: '13:00',
  reason: '',
});

// Panel para registrar o editar un bloqueo: a quién aplica, de qué tipo es,
// cuándo y por qué. Mientras se llena avisa qué citas quedan dentro.
export default function FormBlock({ isOpen, onClose, initialData, options, onSaved }: FormBlockProps) {
  const vocab = useVocabulary();
  const [form, setForm] = useState(emptyForm);
  const [affected, setAffected] = useState<AffectedAppointment[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const branches = options?.branches ?? [];
  const professionals = options?.professionals ?? [];
  const canBlockBranch = Boolean(options?.can_block_branch);
  const kinds = (form.scope === 'professional' ? options?.professional_kinds : options?.branch_kinds) ?? [];

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setAffected([]);

    if (initialData) {
      const allDay = isAllDay(initialData);
      setForm({
        scope: initialData.scope,
        professional_id: initialData.professional_id ?? '',
        branch_id: initialData.branch_id ?? '',
        kind: initialData.kind,
        allDay,
        startDate: dateOf(initialData.starts_at),
        endDate: allDay ? addDays(dateOf(initialData.ends_at), -1) : dateOf(initialData.ends_at),
        startTime: allDay ? '09:00' : timeOf(initialData.starts_at),
        endTime: allDay ? '13:00' : timeOf(initialData.ends_at),
        reason: initialData.reason ?? '',
      });
      return;
    }

    setForm({
      ...emptyForm(),
      professional_id: professionals.length === 1 ? professionals[0].id : '',
      branch_id: branches.length === 1 ? branches[0].id : '',
    });
    // Solo al abrir: las listas no cambian mientras el panel está abierto.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, initialData]);

  const set = (changes: Partial<ReturnType<typeof emptyForm>>) => setForm((current) => ({ ...current, ...changes }));

  const changeScope = (scope: 'professional' | 'branch') => {
    if (scope === form.scope) return;
    set({ scope, kind: scope === 'professional' ? 'vacation' : 'holiday' });
  };

  const startsAt = form.allDay ? `${form.startDate}T00:00` : `${form.startDate}T${form.startTime}`;
  const endsAt = form.allDay ? `${addDays(form.endDate || form.startDate, 1)}T00:00` : `${form.endDate}T${form.endTime}`;
  const datesReady = Boolean(form.startDate && form.endDate && (form.allDay || (form.startTime && form.endTime)));
  const rangeValid = datesReady && endsAt > startsAt;

  // Sedes a las que se aplica: una, o todas (un bloqueo por sede).
  const targetBranches = form.branch_id === ALL_BRANCHES ? branches.map((b) => b.id) : form.branch_id ? [form.branch_id] : [];
  const targetReady = form.scope === 'professional' ? Boolean(form.professional_id) : targetBranches.length > 0;
  const targetKey = form.scope === 'professional' ? form.professional_id : targetBranches.join(',');

  // Citas que quedarían dentro del rango, para avisar antes de guardar.
  useEffect(() => {
    if (!isOpen || !targetReady || !rangeValid) {
      setAffected([]);
      return;
    }

    let cancelled = false;
    const timer = setTimeout(() => {
      const targets = form.scope === 'professional'
        ? [{ professional_id: form.professional_id }]
        : targetKey.split(',').map((branch_id) => ({ branch_id }));

      Promise.all(targets.map((target) => api.get('/availability-blocks/affected', { params: { ...target, starts_at: startsAt, ends_at: endsAt } })))
        .then((responses) => {
          if (cancelled) return;
          const list: AffectedAppointment[] = responses.flatMap((res) => res.data ?? []);
          setAffected(list.sort((a, b) => a.starts_at.localeCompare(b.starts_at)));
        })
        .catch(() => { if (!cancelled) setAffected([]); });
    }, 350);

    return () => { cancelled = true; clearTimeout(timer); };
  }, [isOpen, form.scope, form.professional_id, targetKey, targetReady, rangeValid, startsAt, endsAt]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!targetReady) {
      setError(form.scope === 'professional' ? 'Elige a quién se bloquea la agenda.' : 'Elige la sede.');
      return;
    }
    if (!rangeValid) {
      setError('La fecha de fin debe ser posterior a la de inicio.');
      return;
    }

    const base = { starts_at: startsAt, ends_at: endsAt, kind: form.kind, reason: form.reason.trim() };

    try {
      setSaving(true);
      setError('');

      if (initialData) {
        await api.put(`/availability-blocks/${initialData.id}`, {
          ...base,
          ...(form.scope === 'professional' ? { professional_id: form.professional_id } : { branch_id: targetBranches[0] }),
        });
      } else if (form.scope === 'professional') {
        await api.post('/availability-blocks', { ...base, professional_id: form.professional_id });
      } else {
        await Promise.all(targetBranches.map((branch_id) => api.post('/availability-blocks', { ...base, branch_id })));
      }

      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el bloqueo.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'block-form';
  const who = initialData?.professional?.display_name ?? initialData?.branch?.name ?? '';

  const scopeButton = (scope: 'professional' | 'branch', label: string, hint: string, icon: React.ReactNode) => {
    const isOn = form.scope === scope;

    return (
      <button
        type="button"
        data-scope={scope}
        aria-pressed={isOn}
        onClick={() => changeScope(scope)}
        className={`flex items-start gap-3 p-3.5 text-left border rounded-xl transition-colors ${
          isOn ? 'border-[#022340] bg-blue-50 ring-2 ring-[#022340]/10' : 'border-slate-200 bg-white hover:border-slate-300'
        }`}
      >
        <span className={`p-2 rounded-lg shrink-0 ${isOn ? 'bg-white text-[#022340]' : 'bg-slate-100 text-slate-500'}`}>{icon}</span>
        <span>
          <span className={`block text-sm font-bold ${isOn ? 'text-[#022340]' : 'text-slate-700'}`}>{label}</span>
          <span className="block text-xs text-slate-500">{hint}</span>
        </span>
      </button>
    );
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar bloqueo: ${who}` : 'Nuevo bloqueo'}
      subtitle={initialData ? initialData.kind_label : 'Un rango en el que no se agenda'}
      icon={<CalendarOff size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-6 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <span className={labelClass}>¿A quién aplica?</span>
            {initialData || !canBlockBranch ? (
              <p className="text-sm font-semibold text-slate-800" data-target>
                {form.scope === 'professional'
                  ? professionals.find((p) => p.id === form.professional_id)?.display_name ?? who
                  : `Toda la sede · ${who}`}
              </p>
            ) : (
              <div className="grid grid-cols-2 gap-3">
                {scopeButton('professional', vocab.pro, 'Vacaciones, permisos, reuniones', <UserRound size={18} />)}
                {scopeButton('branch', 'Toda la sede', 'Feriados y cierres', <Building2 size={18} />)}
              </div>
            )}
          </div>

          {!initialData && form.scope === 'professional' && professionals.length > 1 && (
            <Select
              label={vocab.pro}
              placeholder="Elige a quién..."
              value={form.professional_id}
              onChange={(e) => set({ professional_id: String(e.target.value) })}
              options={professionals.map((p) => ({ value: p.id, label: p.display_name }))}
            />
          )}

          {!initialData && form.scope === 'branch' && branches.length > 1 && (
            <Select
              label="Sede"
              placeholder="Elige la sede..."
              value={form.branch_id}
              onChange={(e) => set({ branch_id: String(e.target.value) })}
              options={[{ value: ALL_BRANCHES, label: 'Todas las sedes' }, ...branches.map((b) => ({ value: b.id, label: b.name }))]}
            />
          )}

          <div>
            <span className={labelClass}>Tipo</span>
            <div className="flex flex-wrap gap-2">
              {kinds.map((kind) => (
                <button
                  key={kind.key}
                  type="button"
                  data-kind={kind.key}
                  aria-pressed={form.kind === kind.key}
                  onClick={() => set({ kind: kind.key })}
                  className={`px-3.5 py-2 text-sm font-medium border rounded-full transition-colors ${
                    form.kind === kind.key
                      ? 'border-[#022340] bg-[#022340] text-white'
                      : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                  }`}
                >
                  {kind.label}
                </button>
              ))}
            </div>
            <p className="text-xs text-slate-500 mt-2" data-kind-hint>
              {form.kind === 'other'
                ? 'Para reuniones, capacitaciones o mantenimiento. El administrador puede agendar encima indicando un motivo.'
                : 'Nadie puede agendar dentro de este rango.'}
            </p>
          </div>

          <div className="space-y-3">
            <label className="flex items-center justify-between gap-3 px-4 py-3 border border-slate-200 rounded-xl cursor-pointer">
              <span>
                <span className="block text-sm font-semibold text-slate-800">Días completos</span>
                <span className="block text-xs text-slate-500">Apágalo para bloquear solo unas horas.</span>
              </span>
              <Switch checked={form.allDay} onChange={(allDay) => set({ allDay })} label="Bloquear días completos" />
            </label>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass} htmlFor="block-start-date">Desde *</label>
                <input
                  id="block-start-date"
                  type="date"
                  required
                  className={inputClass}
                  value={form.startDate}
                  onChange={(e) => set({ startDate: e.target.value, endDate: form.endDate < e.target.value ? e.target.value : form.endDate })}
                />
              </div>
              <div>
                <label className={labelClass} htmlFor="block-end-date">Hasta *</label>
                <input
                  id="block-end-date"
                  type="date"
                  required
                  min={form.startDate}
                  className={inputClass}
                  value={form.endDate}
                  onChange={(e) => set({ endDate: e.target.value })}
                />
              </div>

              {!form.allDay && (
                <>
                  <div>
                    <label className={labelClass} htmlFor="block-start-time">Hora de inicio *</label>
                    <input id="block-start-time" type="time" step={300} required className={inputClass} value={form.startTime} onChange={(e) => set({ startTime: e.target.value })} />
                  </div>
                  <div>
                    <label className={labelClass} htmlFor="block-end-time">Hora de fin *</label>
                    <input id="block-end-time" type="time" step={300} required className={inputClass} value={form.endTime} onChange={(e) => set({ endTime: e.target.value })} />
                  </div>
                </>
              )}
            </div>
          </div>

          <div>
            <label className={labelClass} htmlFor="block-reason">Motivo *</label>
            <input
              id="block-reason"
              type="text"
              required
              maxLength={255}
              placeholder={form.scope === 'branch' ? 'Ej. Feriado nacional' : 'Ej. Vacaciones de verano'}
              className={inputClass}
              value={form.reason}
              onChange={(e) => set({ reason: e.target.value })}
            />
            <p className="text-xs text-slate-500 mt-1.5">Se muestra en la agenda sobre las horas bloqueadas.</p>
          </div>

          {affected.length > 0 && (
            <div className="border border-amber-200 bg-amber-50 rounded-xl p-4 space-y-3" data-affected={affected.length}>
              <p className="flex items-start gap-2 text-sm font-semibold text-amber-800">
                <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                <span>
                  {affected.length === 1 ? 'Hay 1 cita dentro de este rango.' : `Hay ${affected.length} citas dentro de este rango.`}{' '}
                  <span className="font-normal">El bloqueo no las mueve ni las cancela: después de guardar, reprográmalas desde la lista.</span>
                </span>
              </p>
              <ul className="divide-y divide-amber-200/70">
                {affected.slice(0, 8).map((a) => (
                  <li key={a.id} className="py-2 text-xs text-amber-800 flex items-center justify-between gap-3">
                    <span>
                      <span className="font-mono font-bold">{a.code}</span> · {a.customer ?? '—'}
                      <span className="block opacity-80">{a.service}{a.professional ? ` · ${a.professional}` : ''}</span>
                    </span>
                    <span className="whitespace-nowrap">{formatDateTime(a.starts_at)}</span>
                  </li>
                ))}
              </ul>
              {affected.length > 8 && <p className="text-xs text-amber-800">y {affected.length - 8} más.</p>}
            </div>
          )}

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
          >
            Cancelar
          </button>
          <button
            type="submit"
            form={formId}
            disabled={saving}
            className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
          >
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Registrar bloqueo'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
