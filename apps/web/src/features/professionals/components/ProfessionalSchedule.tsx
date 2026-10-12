'use client';

import { useEffect, useState } from 'react';
import { CalendarClock, CalendarOff, Plus, Trash2, X } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import TimeField from '@/app/components/common/TimeField';
import Switch from '@/app/components/common/Switch';
import { AppModal } from '@/app/components/ui/modals/AppModal';

interface Range {
  starts: string;
  ends: string;
}

interface ScheduleRow extends Range {
  branch_id: string;
  weekday: number;
}

interface Branch {
  id: string;
  name: string;
}

interface Block {
  id: string;
  reason: string;
  kind?: string;
  kind_label?: string;
  starts_at: string;
  ends_at: string;
}

interface ProfessionalScheduleProps {
  isOpen: boolean;
  onClose: () => void;
  professional: { id: string; display_name: string } | null;
  // Sede en la que se abre el panel (por ejemplo, la que se está viendo).
  initialBranchId?: string | null;
}

// Lunes primero; el número es el día de la semana que guarda la API (0 = domingo).
const DAYS: { weekday: number; label: string }[] = [
  { weekday: 1, label: 'Lunes' },
  { weekday: 2, label: 'Martes' },
  { weekday: 3, label: 'Miércoles' },
  { weekday: 4, label: 'Jueves' },
  { weekday: 5, label: 'Viernes' },
  { weekday: 6, label: 'Sábado' },
  { weekday: 0, label: 'Domingo' },
];

// Tipos de ausencia de una persona. Solo el bloqueo de horario admite sobre-agenda.
const BLOCK_KINDS = [
  { value: 'vacation', label: 'Vacaciones' },
  { value: 'leave', label: 'Permiso o descanso médico' },
  { value: 'other', label: 'Bloqueo de horario' },
];

const emptyBlock = () => ({ starts_at: '', ends_at: '', reason: '', kind: 'vacation' });

const DEFAULT_RANGE: Range = { starts: '09:00', ends: '18:00' };

const inputClass =
  'text-sm border border-slate-200 rounded-lg px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

// Al elegir solo el día, la ausencia toma el día completo.
const MOMENTS: { key: 'starts_at' | 'ends_at'; label: string; time: string }[] = [
  { key: 'starts_at', label: 'Desde', time: '00:00' },
  { key: 'ends_at', label: 'Hasta', time: '23:59' },
];

const emptyWeek = (): Record<number, Range[]> => ({ 0: [], 1: [], 2: [], 3: [], 4: [], 5: [], 6: [] });

const formatDateTime = (value: string) =>
  new Date(value).toLocaleString('es-PE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const firstError = (error: any, fallback: string): string => {
  const errors = error?.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return String(first[0]);
  }
  return error?.response?.data?.message || fallback;
};

// Horario semanal de un profesional (por sede) y sus ausencias. El horario se
// guarda completo por sede; las ausencias se agregan y se quitan una a una.
export default function ProfessionalSchedule({ isOpen, onClose, professional, initialBranchId }: ProfessionalScheduleProps) {
  const [branches, setBranches] = useState<Branch[]>([]);
  const [branchId, setBranchId] = useState('');
  const [rows, setRows] = useState<ScheduleRow[]>([]);
  const [week, setWeek] = useState<Record<number, Range[]>>(emptyWeek);
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [blockForm, setBlockForm] = useState(emptyBlock);
  const [removing, setRemoving] = useState<Block | null>(null);

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [addingBlock, setAddingBlock] = useState(false);
  const [error, setError] = useState('');
  const [blockError, setBlockError] = useState('');
  const [saved, setSaved] = useState(false);

  const weekFor = (allRows: ScheduleRow[], branch: string) => {
    const next = emptyWeek();
    allRows
      .filter((row) => row.branch_id === branch)
      .forEach((row) => next[row.weekday].push({ starts: row.starts, ends: row.ends }));
    return next;
  };

  useEffect(() => {
    if (!isOpen || !professional) return;

    setLoading(true);
    setError('');
    setBlockError('');
    setSaved(false);
    setBlockForm(emptyBlock());

    Promise.all([
      api.get(`/professionals/${professional.id}/schedule`),
      api.get('/availability-blocks', { params: { professional_id: professional.id } }),
    ])
      .then(([scheduleRes, blocksRes]) => {
        const branchList: Branch[] = scheduleRes.data.branches ?? [];
        const allRows: ScheduleRow[] = scheduleRes.data.schedules ?? [];
        // Abre en la sede pedida; si no, en la primera donde la persona ya atiende.
        const firstBranch = branchList.find((b) => b.id === initialBranchId)?.id
          ?? branchList.find((b) => allRows.some((row) => row.branch_id === b.id))?.id
          ?? branchList[0]?.id ?? '';

        setBranches(branchList);
        setRows(allRows);
        setBranchId(firstBranch);
        setWeek(weekFor(allRows, firstBranch));
        setBlocks(blocksRes.data ?? []);
      })
      .catch((err) => setError(firstError(err, 'No se pudo cargar el horario.')))
      .finally(() => setLoading(false));
  }, [isOpen, professional]);

  const changeBranch = (id: string) => {
    setBranchId(id);
    setWeek(weekFor(rows, id));
    setSaved(false);
    setError('');
  };

  const updateDay = (weekday: number, ranges: Range[]) => {
    setWeek((current) => ({ ...current, [weekday]: ranges }));
    setSaved(false);
  };

  // Al encender un día toma el horario del día anterior que atiende, para no
  // escribir lo mismo siete veces.
  const toggleDay = (weekday: number, on: boolean) => {
    if (!on) return updateDay(weekday, []);

    const index = DAYS.findIndex((day) => day.weekday === weekday);
    const previous = DAYS.slice(0, index).reverse().find((day) => week[day.weekday].length > 0);
    updateDay(weekday, previous ? week[previous.weekday].map((range) => ({ ...range })) : [{ ...DEFAULT_RANGE }]);
  };

  const addRange = (weekday: number) => {
    const last = week[weekday][week[weekday].length - 1];
    updateDay(weekday, [...week[weekday], { starts: last?.ends ?? DEFAULT_RANGE.starts, ends: '20:00' }]);
  };

  const saveSchedule = async () => {
    if (!professional || !branchId) return;

    try {
      setSaving(true);
      setError('');
      const ranges = DAYS.flatMap((day) => week[day.weekday].map((range) => ({ weekday: day.weekday, ...range })));
      const res = await api.put(`/professionals/${professional.id}/schedule`, { branch_id: branchId, ranges });
      setRows(res.data.schedules ?? []);
      setSaved(true);
    } catch (err) {
      setError(firstError(err, 'No se pudo guardar el horario.'));
    } finally {
      setSaving(false);
    }
  };

  const addBlock = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!professional) return;

    try {
      setAddingBlock(true);
      setBlockError('');
      const res = await api.post('/availability-blocks', { professional_id: professional.id, ...blockForm });
      setBlocks((current) => [...current, res.data].sort((a, b) => a.starts_at.localeCompare(b.starts_at)));
      setBlockForm(emptyBlock());
    } catch (err) {
      setBlockError(firstError(err, 'No se pudo registrar la ausencia.'));
    } finally {
      setAddingBlock(false);
    }
  };

  // La ausencia viaja como "AAAA-MM-DDTHH:MM"; en pantalla son una fecha y una hora.
  const setMoment = (key: 'starts_at' | 'ends_at', part: { day?: string; hour?: string }, fallbackHour: string) => {
    const [day = '', hour = ''] = blockForm[key].split('T');
    const nextDay = part.day ?? (day || new Date().toLocaleDateString('en-CA'));
    setBlockForm({ ...blockForm, [key]: nextDay ? `${nextDay}T${part.hour ?? (hour || fallbackHour)}` : '' });
  };

  const removeBlock = async (id: string) => {
    try {
      setBlockError('');
      await api.delete(`/availability-blocks/${id}`);
      setBlocks((current) => current.filter((block) => block.id !== id));
    } catch (err) {
      setBlockError(firstError(err, 'No se pudo quitar la ausencia.'));
    } finally {
      setRemoving(null);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Horario de ${professional?.display_name ?? ''}`}
      subtitle="Días y horas en que atiende, y sus ausencias"
      icon={<CalendarClock size={20} />}
    >
      {loading ? (
        <p className="text-sm text-slate-500">Cargando horario...</p>
      ) : branches.length === 0 ? (
        <p className="text-sm text-slate-500">{error || 'Tu empresa no tiene sedes activas. Crea una sede para definir horarios.'}</p>
      ) : (
        <div className="space-y-8 pb-4">
          <section className="space-y-4">
            <div className="flex items-end justify-between gap-4">
              <h4 className="text-sm font-extrabold text-[#022340] uppercase tracking-wider">Horario semanal</h4>
              {branches.length === 1 && (
                <span className="px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-600 rounded-full">{branches[0].name}</span>
              )}
            </div>

            {branches.length > 1 && (
              <Select
                label="Sede"
                value={branchId}
                onChange={(e) => changeBranch(String(e.target.value))}
                options={branches.map((branch) => ({ value: branch.id, label: branch.name }))}
              />
            )}

            <div className="border border-slate-200 rounded-xl divide-y divide-slate-100">
              {DAYS.map((day) => {
                const ranges = week[day.weekday];
                const isOn = ranges.length > 0;

                return (
                  <div key={day.weekday} className="flex items-start gap-3 px-4 py-3" data-day={day.weekday}>
                    <div className="flex items-center gap-3 w-36 shrink-0 pt-1.5">
                      <Switch checked={isOn} onChange={(on) => toggleDay(day.weekday, on)} label={`Atiende el ${day.label.toLowerCase()}`} />
                      <span className={`text-sm font-semibold ${isOn ? 'text-slate-800' : 'text-slate-400'}`}>{day.label}</span>
                    </div>

                    <div className="flex-1 space-y-2">
                      {!isOn && <span className="block text-sm text-slate-400 pt-1.5">No atiende</span>}

                      {ranges.map((range, index) => (
                        <div key={index} className="flex items-center gap-2">
                          <TimeField
                            size="compact"
                            clearable={false}
                            ariaLabel={`${day.label}: desde`}
                            value={range.starts}
                            onChange={(value) => updateDay(day.weekday, ranges.map((r, i) => (i === index ? { ...r, starts: value } : r)))}
                          />
                          <span className="text-sm text-slate-400">a</span>
                          <TimeField
                            size="compact"
                            clearable={false}
                            ariaLabel={`${day.label}: hasta`}
                            value={range.ends}
                            onChange={(value) => updateDay(day.weekday, ranges.map((r, i) => (i === index ? { ...r, ends: value } : r)))}
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
                        <button
                          type="button"
                          onClick={() => addRange(day.weekday)}
                          className="flex items-center gap-1 text-xs font-semibold text-[#3F768C] hover:text-[#022340] transition-colors"
                        >
                          <Plus size={13} /> Agregar tramo
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>

            {error && <p className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
            {saved && <p className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">Horario guardado.</p>}

            <div className="flex justify-end">
              <button
                type="button"
                onClick={saveSchedule}
                disabled={saving}
                className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
              >
                {saving ? 'Guardando...' : 'Guardar horario'}
              </button>
            </div>
          </section>

          <section className="space-y-4 pt-6 border-t border-slate-100">
            <div>
              <h4 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
                <CalendarOff size={16} className="text-[#3F768C]" /> Ausencias
              </h4>
              <p className="text-xs text-slate-500 mt-1">Vacaciones, permisos o cualquier rango en que no atiende aunque su horario lo indique.</p>
            </div>

            {blocks.length === 0 ? (
              <p className="text-sm text-slate-400">No tiene ausencias próximas.</p>
            ) : (
              <ul className="border border-slate-200 rounded-xl divide-y divide-slate-100">
                {blocks.map((block) => (
                  <li key={block.id} className="flex items-center justify-between gap-3 px-4 py-3">
                    <div>
                      <span className="block text-sm font-semibold text-slate-800">{block.reason}</span>
                      {block.kind_label && <span className="block text-xs text-slate-400">{block.kind_label}</span>}
                      <span className="block text-xs text-slate-500">
                        {formatDateTime(block.starts_at)} — {formatDateTime(block.ends_at)}
                      </span>
                    </div>
                    <button
                      type="button"
                      title="Quitar ausencia"
                      onClick={() => setRemoving(block)}
                      className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                    >
                      <Trash2 size={16} />
                    </button>
                  </li>
                ))}
              </ul>
            )}

            <form onSubmit={addBlock} className="space-y-3 p-4 bg-slate-50 border border-slate-200/80 rounded-xl">
              <Select
                label="Tipo"
                value={blockForm.kind}
                onChange={(e) => setBlockForm({ ...blockForm, kind: String(e.target.value) || 'vacation' })}
                options={BLOCK_KINDS}
              />
              {MOMENTS.map((moment) => {
                const [day = '', hour = ''] = blockForm[moment.key].split('T');
                return (
                  <div key={moment.key} data-moment={moment.key}>
                    <span className={labelClass}>{moment.label} *</span>
                    <div className="flex items-center gap-2">
                      <DateField
                        required
                        ariaLabel={`${moment.label}: fecha`}
                        className="flex-1 min-w-0"
                        min={moment.key === 'ends_at' ? blockForm.starts_at.slice(0, 10) || undefined : undefined}
                        value={day}
                        onChange={(value) => setMoment(moment.key, { day: value }, moment.time)}
                      />
                      <TimeField
                        required
                        ariaLabel={`${moment.label}: hora`}
                        className="w-28 shrink-0"
                        value={hour}
                        onChange={(value) => setMoment(moment.key, { hour: value }, moment.time)}
                      />
                    </div>
                  </div>
                );
              })}
              <div>
                <label className={labelClass}>Motivo *</label>
                <input
                  type="text"
                  required
                  maxLength={255}
                  placeholder="Ej. Vacaciones"
                  className={`${inputClass} w-full`}
                  value={blockForm.reason}
                  onChange={(e) => setBlockForm({ ...blockForm, reason: e.target.value })}
                />
              </div>

              {blockError && <p className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{blockError}</p>}

              <div className="flex justify-end">
                <button
                  type="submit"
                  disabled={addingBlock}
                  className="px-4 py-2.5 text-sm font-medium border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  {addingBlock ? 'Agregando...' : 'Agregar ausencia'}
                </button>
              </div>
            </form>
          </section>
        </div>
      )}

      <AppModal
        isOpen={Boolean(removing)}
        variant="danger"
        title="Quitar ausencia"
        message={`Se quitará "${removing?.reason ?? ''}". Ese horario volverá a estar disponible para agendar.`}
        confirmText="Sí, quitar"
        cancelText="No, mantenerla"
        onConfirm={() => removing && removeBlock(removing.id)}
        onClose={() => setRemoving(null)}
      />
    </FormDrawer>
  );
}
