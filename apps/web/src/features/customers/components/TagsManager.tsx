'use client';

import { useEffect, useState } from 'react';
import { Pencil, Tag, Trash2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import TagChip, { ColorPicker, type CustomerTag } from './TagChip';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onChanged: (tags: CustomerTag[]) => void;
  clientsWord: string;
}

export default function TagsManager({ isOpen, onClose, onChanged, clientsWord }: Props) {
  const [tags, setTags] = useState<CustomerTag[]>([]);
  const [colors, setColors] = useState<string[]>([]);
  const [ready, setReady] = useState(true);
  const [editing, setEditing] = useState<CustomerTag | null>(null);
  const [name, setName] = useState('');
  const [color, setColor] = useState('');
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setEditing(null);
    setConfirmDelete(null);
    api.get('/customer-tags')
      .then((res) => { setTags(res.data.tags ?? []); setColors(res.data.colors ?? []); setReady(Boolean(res.data.ready)); setColor(res.data.colors?.[0] ?? ''); setName(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las etiquetas.')));
  }, [isOpen]);

  const apply = (list: CustomerTag[]) => { setTags(list); onChanged(list); };

  const save = () => {
    setBusy(true);
    setError('');
    const request = editing ? api.put(`/customer-tags/${editing.id}`, { name, color }) : api.post('/customer-tags', { name, color });
    request
      .then((res) => { apply(res.data.tags ?? []); setEditing(null); setName(''); })
      .catch((err) => setError(apiError(err, 'No se pudo guardar la etiqueta.')))
      .finally(() => setBusy(false));
  };

  const remove = (id: string) => {
    setBusy(true);
    setError('');
    api.delete(`/customer-tags/${id}`)
      .then((res) => { apply(res.data.tags ?? []); setConfirmDelete(null); })
      .catch((err) => setError(apiError(err, 'No se pudo eliminar la etiqueta.')))
      .finally(() => setBusy(false));
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title="Etiquetas" subtitle={`Para agrupar a tus ${clientsWord.toLowerCase()} como quieras y filtrarlos en la lista y en campañas.`} icon={<Tag size={20} />}>
      <div className="space-y-5 px-1 pb-8">
        {!ready ? (
          <p role="alert" className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para usar etiquetas: ejecuta php artisan migrate.</p>
        ) : (
          <>
            <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-tag-form>
              <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">{editing ? `Cambiar «${editing.name}»` : 'Nueva etiqueta'}</span>
              <input
                type="text"
                maxLength={40}
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Ej. Prefiere WhatsApp"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
              />
              <ColorPicker colors={colors} value={color} onChange={setColor} />
              <div className="flex justify-end gap-2">
                {editing && <Button variant="secondary" type="button" onClick={() => { setEditing(null); setName(''); }}>Cancelar</Button>}
                <Button variant="classic" type="button" data-action="save-tag" onClick={save} disabled={busy || !name.trim()}>{editing ? 'Guardar cambios' : 'Crear etiqueta'}</Button>
              </div>
            </div>

            <ErrorAlert message={error} />

            <div className="rounded-xl border border-slate-200 overflow-hidden">
              <PagedList
                items={tags}
                empty="Aún no tienes etiquetas."
                renderRow={(tag) => (
                  <li key={tag.id} data-tag-row={tag.id} className="flex items-center gap-3 px-4 py-2.5">
                    <div className="flex-1 min-w-0 flex items-center gap-2">
                      <TagChip tag={tag} />
                      <span className="text-xs text-slate-500">{tag.customers ?? 0} {clientsWord.toLowerCase()}</span>
                    </div>
                    {confirmDelete === tag.id ? (
                      <span className="flex items-center gap-2">
                        <span className="text-xs text-slate-600">¿Eliminar? Se quita a todos.</span>
                        <button type="button" data-action="confirm-delete-tag" onClick={() => remove(tag.id)} disabled={busy} className="text-xs font-bold text-rose-700 hover:underline">Sí, eliminar</button>
                        <button type="button" onClick={() => setConfirmDelete(null)} className="text-xs font-semibold text-slate-500 hover:underline">No</button>
                      </span>
                    ) : (
                      <span className="flex items-center gap-1">
                        <button type="button" data-action="edit-tag" title="Cambiar" onClick={() => { setEditing(tag); setName(tag.name); setColor(tag.color ?? colors[0] ?? ''); }} className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"><Pencil size={14} /></button>
                        <button type="button" data-action="delete-tag" title="Eliminar" onClick={() => setConfirmDelete(tag.id)} className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"><Trash2 size={14} /></button>
                      </span>
                    )}
                  </li>
                )}
              />
            </div>
          </>
        )}
      </div>
    </FormDrawer>
  );
}
