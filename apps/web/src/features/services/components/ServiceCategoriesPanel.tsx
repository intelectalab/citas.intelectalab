'use client';

import { useEffect, useState } from 'react';
import { Check, Pencil, Plus, Tag, X } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { ColorPicker } from '@/features/customers/components/TagChip';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { CATEGORY_COLORS, categoryColor, type ServiceCategoryRow } from '../services';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  canCreate: boolean;
  canEdit: boolean;
  onChanged: () => void;
}

export default function ServiceCategoriesPanel({ isOpen, onClose, canCreate, canEdit, onChanged }: Props) {
  const [rows, setRows] = useState<ServiceCategoryRow[]>([]);
  const [name, setName] = useState('');
  const [color, setColor] = useState(CATEGORY_COLORS[0]);
  const [editing, setEditing] = useState<ServiceCategoryRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = () => {
    api.get('/service-categories')
      .then((res) => setRows(Array.isArray(res.data) ? res.data : []))
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las categorías.')));
  };

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setNotice('');
    setEditing(null);
    setName('');
    setColor(CATEGORY_COLORS[0]);
    load();
  }, [isOpen]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    try {
      setBusy(true);
      setError('');
      await api.post('/service-categories', { name: name.trim(), color });
      setNotice(`Se creó la categoría «${name.trim()}».`);
      setName('');
      load();
      onChanged();
    } catch (err) {
      setError(apiError(err, 'No se pudo crear la categoría.'));
    } finally {
      setBusy(false);
    }
  };

  const save = async () => {
    if (!editing || !editing.name.trim()) return;
    try {
      setBusy(true);
      setError('');
      await api.put(`/service-categories/${editing.id}`, { name: editing.name.trim(), color: editing.color || null });
      setNotice('Categoría actualizada.');
      setEditing(null);
      load();
      onChanged();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la categoría.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title="Categorías de servicios" subtitle="Agrupan los servicios en la lista, en la reserva en línea y en los reportes." icon={<Tag size={20} />}>
      <div className="space-y-6 pb-4" data-categories-panel>
        <ErrorAlert message={error} />
        <SuccessAlert message={notice} />

        {rows.length === 0 ? (
          <p className="text-sm text-slate-400">Aún no hay categorías.</p>
        ) : (
          <ul className="border border-slate-200 rounded-xl divide-y divide-slate-100">
            {rows.map((row) => (
              <li key={row.id} className="px-4 py-3" data-category={row.id}>
                {editing?.id === row.id ? (
                  <div className="space-y-3">
                    <input
                      aria-label="Nombre de la categoría"
                      maxLength={120}
                      value={editing.name}
                      onChange={(e) => setEditing({ ...editing, name: e.target.value })}
                      className={inputClass}
                    />
                    <ColorPicker colors={CATEGORY_COLORS} value={editing.color || ''} onChange={(value) => setEditing({ ...editing, color: value })} />
                    <div className="flex justify-end gap-2">
                      <button type="button" onClick={() => setEditing(null)} className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg">
                        <X size={13} /> Cancelar
                      </button>
                      <button type="button" data-save-category disabled={busy || !editing.name.trim()} onClick={save} className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-brand hover:bg-brand-hover rounded-lg disabled:opacity-50">
                        <Check size={13} /> Guardar
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="flex items-center gap-3">
                    <span className="w-3.5 h-3.5 rounded shrink-0" style={{ backgroundColor: categoryColor(row.color) }} />
                    <span className="flex-1 min-w-0 text-sm font-semibold text-slate-800 truncate">{row.name}</span>
                    <span className="text-xs text-slate-400 whitespace-nowrap">{row.services_count ?? 0} {(row.services_count ?? 0) === 1 ? 'servicio' : 'servicios'}</span>
                    {canEdit && (
                      <button type="button" title={`Editar ${row.name}`} data-edit-category={row.id} onClick={() => setEditing({ ...row })} className="p-1.5 text-slate-400 hover:text-brand-ink hover:bg-slate-100 rounded-lg">
                        <Pencil size={14} />
                      </button>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}

        {canCreate && (
          <form onSubmit={create} className="space-y-3 p-4 bg-slate-50 border border-slate-200/80 rounded-xl" data-new-category>
            <div>
              <label htmlFor="new-category-name" className={labelClass}>Nueva categoría</label>
              <input id="new-category-name" maxLength={120} placeholder="Ej. Rostro, Uñas, Masajes" value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
            </div>
            <div>
              <span className={labelClass}>Color</span>
              <ColorPicker colors={CATEGORY_COLORS} value={color} onChange={setColor} />
            </div>
            <div className="flex justify-end">
              <button type="submit" disabled={busy || !name.trim()} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-brand hover:bg-brand-hover rounded-lg disabled:opacity-50">
                <Plus size={15} /> Agregar
              </button>
            </div>
          </form>
        )}
      </div>
    </FormDrawer>
  );
}
