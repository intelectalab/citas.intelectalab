'use client';

import { useEffect, useState } from 'react';
import { PawPrint, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';

export interface CustomerDependent {
  id?: string;
  name: string;
  species?: string | null;
  breed?: string | null;
  birth_date?: string | null;
  biological_sex?: string | null;
  notes?: string | null;
}

interface CustomerDependentsProps {
  customerId: string | null;
  onPendingChange: (dependents: CustomerDependent[]) => void;
}

const EMPTY: CustomerDependent = { name: '', species: '', breed: '', birth_date: '', biological_sex: '', notes: '' };

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none bg-white';
const labelClass = 'block text-[11px] font-semibold uppercase tracking-wider text-slate-600 mb-1';

export default function CustomerDependents({ customerId, onPendingChange }: CustomerDependentsProps) {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const isPet = user?.tenant?.dependents?.kind === 'pet';
  const dependentLower = vocab.dependent.toLowerCase();

  const [items, setItems] = useState<CustomerDependent[]>([]);
  const [draft, setDraft] = useState<CustomerDependent>(EMPTY);
  const [editingIndex, setEditingIndex] = useState<number | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setItems([]);
    setShowForm(false);
    setError(null);

    if (!customerId) return;

    api.get(`/customers/${customerId}/dependents`)
      .then((res) => setItems(res.data))
      .catch(() => setError(`No se pudieron cargar los registros de ${vocab.dependents.toLowerCase()}.`));
  }, [customerId]);

  const update = (next: CustomerDependent[]) => {
    setItems(next);
    if (!customerId) onPendingChange(next);
  };

  const openForm = (index: number | null) => {
    const item = index === null ? EMPTY : items[index];
    setDraft({
      ...EMPTY,
      ...item,
      birth_date: item.birth_date ? item.birth_date.split('T')[0] : '',
    });
    setEditingIndex(index);
    setError(null);
    setShowForm(true);
  };

  const save = async () => {
    if (!draft.name.trim()) {
      setError('El nombre es obligatorio.');
      return;
    }

    const payload: CustomerDependent = {
      name: draft.name.trim(),
      species: draft.species || null,
      breed: draft.breed || null,
      birth_date: draft.birth_date || null,
      biological_sex: draft.biological_sex || null,
      notes: draft.notes || null,
    };

    try {
      setSaving(true);
      setError(null);
      let saved = payload;

      if (customerId) {
        const current = editingIndex === null ? null : items[editingIndex];
        const res = current?.id
          ? await api.put(`/customers/${customerId}/dependents/${current.id}`, payload)
          : await api.post(`/customers/${customerId}/dependents`, payload);
        saved = res.data;
      }

      update(editingIndex === null ? [...items, saved] : items.map((item, i) => (i === editingIndex ? saved : item)));
      setShowForm(false);
    } catch (err: any) {
      setError(err.response?.data?.message || 'No se pudo guardar el registro.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (index: number) => {
    const item = items[index];

    try {
      setError(null);
      if (customerId && item.id) {
        await api.delete(`/customers/${customerId}/dependents/${item.id}`);
      }
      update(items.filter((_, i) => i !== index));
      setShowForm(false);
    } catch (err: any) {
      setError(err.response?.data?.message || 'No se pudo eliminar el registro.');
    }
  };

  const Icon = isPet ? PawPrint : Users;

  return (
    <div className="space-y-3">
      {items.length === 0 && !showForm && (
        <p className="text-xs text-slate-400 italic">
          Aún no hay {vocab.dependents.toLowerCase()} {vocab.pick('dependent', 'registrados', 'registradas')}.
        </p>
      )}

      {items.map((item, index) => (
        <div key={item.id ?? `new-${index}`} className="flex items-center justify-between gap-3 border border-slate-200 rounded-lg px-3 py-2">
          <div className="flex items-center gap-2.5 min-w-0">
            <span className="p-1.5 rounded-lg bg-slate-100 text-slate-500 shrink-0">
              <Icon size={14} />
            </span>
            <div className="min-w-0">
              <span className="block text-sm font-semibold text-slate-800 truncate">{item.name}</span>
              <span className="block text-xs text-slate-400 truncate">
                {[item.species, item.breed].filter(Boolean).join(' · ') || 'Sin más datos'}
              </span>
            </div>
          </div>
          <div className="flex items-center gap-1 shrink-0">
            <button
              type="button"
              onClick={() => openForm(index)}
              title={`Editar ${dependentLower}`}
              className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
            >
              <Pencil size={14} />
            </button>
            <button
              type="button"
              onClick={() => remove(index)}
              title={`Eliminar ${dependentLower}`}
              className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
            >
              <Trash2 size={14} />
            </button>
          </div>
        </div>
      ))}

      {error && <p className="text-xs font-medium text-red-600">{error}</p>}

      {showForm ? (
        <div className="space-y-3 border border-slate-200 rounded-lg p-3 bg-slate-50/60">
          <div>
            <label className={labelClass}>Nombre *</label>
            <input
              type="text"
              value={draft.name}
              onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              className={inputClass}
              placeholder={isPet ? 'Ej. Firulais' : 'Nombre completo'}
            />
          </div>

          {isPet && (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass}>Especie</label>
                <input
                  type="text"
                  value={draft.species ?? ''}
                  onChange={(e) => setDraft({ ...draft, species: e.target.value })}
                  className={inputClass}
                  placeholder="Perro, gato..."
                />
              </div>
              <div>
                <label className={labelClass}>Raza</label>
                <input
                  type="text"
                  value={draft.breed ?? ''}
                  onChange={(e) => setDraft({ ...draft, breed: e.target.value })}
                  className={inputClass}
                />
              </div>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>F. Nacimiento</label>
              <DateField
                ariaLabel="Fecha de nacimiento"
                max={new Date().toLocaleDateString('en-CA')}
                value={draft.birth_date ?? ''}
                onChange={(value) => setDraft({ ...draft, birth_date: value })}
              />
            </div>
            <div>
              <label className={labelClass}>Sexo</label>
              <Select
                ariaLabel="Sexo"
                placeholder="Sin indicar"
                value={draft.biological_sex ?? ''}
                onChange={(e) => setDraft({ ...draft, biological_sex: String(e.target.value) })}
                options={[{ value: 'M', label: isPet ? 'Macho' : 'Masculino' }, { value: 'F', label: isPet ? 'Hembra' : 'Femenino' }]}
              />
            </div>
          </div>

          <div>
            <label className={labelClass}>Observaciones</label>
            <textarea
              rows={2}
              value={draft.notes ?? ''}
              onChange={(e) => setDraft({ ...draft, notes: e.target.value })}
              className={inputClass}
              placeholder={isPet ? 'Alergias, carácter, esterilización...' : 'Datos relevantes'}
            />
          </div>

          <div className="flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="px-3 py-1.5 text-xs font-semibold text-slate-600 bg-white border border-slate-200 hover:bg-slate-100 rounded-lg transition-colors"
            >
              Cancelar
            </button>
            <button
              type="button"
              onClick={save}
              disabled={saving}
              className="px-3 py-1.5 text-xs font-semibold text-white bg-brand hover:bg-brand/90 rounded-lg transition-colors disabled:opacity-50"
            >
              {saving ? 'Guardando...' : editingIndex === null ? `Agregar ${dependentLower}` : 'Guardar cambios'}
            </button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => openForm(null)}
          className="inline-flex items-center gap-1 text-xs font-bold text-sky-600 hover:text-sky-700 transition-colors"
        >
          <Plus size={14} /> Agregar {dependentLower}
        </button>
      )}
    </div>
  );
}
