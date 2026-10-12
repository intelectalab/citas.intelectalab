'use client';

import { useEffect, useState } from 'react';
import { PartyPopper } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import Switch from '@/app/components/common/Switch';
import { apiError, formatDate } from '@/features/appointments/appointments';
import type { Holiday } from '../blocks';

interface FormHolidayProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Holiday | null;
  branches: { id: string; name: string }[];
  onSaved: () => void;
}

const ALL_BRANCHES = 'all';

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

// Panel para agregar o editar un feriado: qué día es, a qué sedes aplica y si
// ese día la sede cierra.
export default function FormHoliday({ isOpen, onClose, initialData, branches, onSaved }: FormHolidayProps) {
  const [name, setName] = useState('');
  const [date, setDate] = useState('');
  const [branchId, setBranchId] = useState(ALL_BRANCHES);
  const [closed, setClosed] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setName(initialData?.name ?? '');
    setDate(initialData?.date ?? '');
    setBranchId(initialData?.branch_id ?? ALL_BRANCHES);
    setClosed(initialData?.closed ?? true);
  }, [isOpen, initialData]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = { name: name.trim(), date, branch_id: branchId === ALL_BRANCHES ? null : branchId, closed };

    try {
      setSaving(true);
      setError('');
      if (initialData) {
        await api.put(`/holidays/${initialData.id}`, payload);
      } else {
        await api.post('/holidays', payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el feriado.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'holiday-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar feriado: ${initialData.name}` : 'Agregar feriado'}
      subtitle={initialData ? formatDate(initialData.date) : 'Un día en que la agenda no ofrece horarios'}
      icon={<PartyPopper size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="holiday-name">Nombre *</label>
            <input
              id="holiday-name"
              type="text"
              required
              maxLength={150}
              placeholder="Ej. Jueves Santo, aniversario de la empresa"
              className={inputClass}
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>

          <div>
            <label className={labelClass} htmlFor="holiday-date">Fecha *</label>
            <DateField id="holiday-date" required value={date} onChange={setDate} />
          </div>

          {branches.length > 1 && (
            <Select
              label="¿A qué sedes aplica?"
              value={branchId}
              onChange={(e) => setBranchId(String(e.target.value) || ALL_BRANCHES)}
              options={[{ value: ALL_BRANCHES, label: 'Todas las sedes' }, ...branches.map((b) => ({ value: b.id, label: b.name }))]}
            />
          )}

          <label className="flex items-center justify-between gap-3 px-4 py-3 border border-slate-200 rounded-xl cursor-pointer">
            <span>
              <span className="block text-sm font-semibold text-slate-800">La sede cierra todo el día</span>
              <span className="block text-xs text-slate-500" data-closed-hint>
                {closed
                  ? 'Ese día no se ofrecen horarios ni se puede agendar.'
                  : 'Se atiende con normalidad; el feriado solo se recuerda en la agenda.'}
              </span>
            </span>
            <Switch checked={closed} onChange={setClosed} label="La sede cierra todo el día" />
          </label>

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
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Agregar feriado'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
