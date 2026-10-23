'use client';

import { useEffect, useState } from 'react';
import { ArrowDown, ArrowUp, Columns3, Plus, RotateCcw, Trash2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import type { BoardInfo, BoardStage } from '../board';

interface Draft {
  key: string;
  id?: string;
  name: string;
  description: string;
  color: string;
  customers: number;
}

interface BoardColumnsEditorProps {
  isOpen: boolean;
  info: BoardInfo;
  clientsWord: string;
  onClose: () => void;
  onSaved: (info: BoardInfo) => void;
}

const draftOf = (stage: BoardStage): Draft => ({
  key: stage.id,
  id: stage.id,
  name: stage.name,
  description: stage.description ?? '',
  color: stage.color,
  customers: stage.customers,
});

const cards = (n: number) => `${n} ${n === 1 ? 'tarjeta' : 'tarjetas'}`;

export default function BoardColumnsEditor({ isOpen, info, clientsWord, onClose, onSaved }: BoardColumnsEditorProps) {
  const [columns, setColumns] = useState<Draft[]>([]);
  const [removed, setRemoved] = useState<Draft[]>([]);
  const [moves, setMoves] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setColumns(info.stages.map(draftOf));
    setRemoved([]);
    setMoves({});
    setError('');
  }, [isOpen, info]);

  const kept = columns.filter((column) => column.id);
  const suggested = (exclude: string) => kept.find((column) => column.id === info.default_stage_id && column.id !== exclude)?.id ?? kept.find((column) => column.id !== exclude)?.id ?? '';

  const change = (key: string, patch: Partial<Draft>) => {
    setError('');
    setColumns((current) => current.map((column) => (column.key === key ? { ...column, ...patch } : column)));
  };

  const shift = (index: number, by: number) => {
    setColumns((current) => {
      const next = [...current];
      const [item] = next.splice(index, 1);
      next.splice(index + by, 0, item);
      return next;
    });
  };

  const remove = (column: Draft) => {
    setError('');
    setColumns((current) => current.filter((item) => item.key !== column.key));
    if (!column.id) return;
    setRemoved((current) => [...current, column]);
    if (column.customers > 0) setMoves((current) => ({ ...current, [column.id as string]: suggested(column.id as string) }));
  };

  const restore = (column: Draft) => {
    setRemoved((current) => current.filter((item) => item.key !== column.key));
    setMoves((current) => {
      const next = { ...current };
      delete next[column.id as string];
      return next;
    });
    setColumns((current) => [...current, column]);
  };

  const add = () => {
    const used = new Set(columns.map((column) => column.color));
    const color = info.colors.find((option) => !used.has(option)) ?? info.colors[0];
    setColumns((current) => [...current, { key: `new-${Date.now()}`, name: '', description: '', color, customers: 0 }]);
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    const missing = removed.find((column) => column.customers > 0 && !kept.some((item) => item.id === moves[column.id as string]));
    if (missing) {
      setError(`Elige a qué columna pasan las ${cards(missing.customers)} de "${missing.name}".`);
      return;
    }
    try {
      setSaving(true);
      const res = await api.put('/customer-board/columns', {
        columns: columns.map((column) => ({ id: column.id, name: column.name, description: column.description, color: column.color })),
        moves,
      });
      onSaved(res.data);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar las columnas.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Columnas del tablero"
      subtitle={`Ponles el nombre que usa tu equipo. Los cambios solo los ve tu empresa y valen para todos los ${clientsWord.toLowerCase()}.`}
      icon={<Columns3 size={20} />}
    >
      <form onSubmit={save} className="flex flex-col h-full" data-columns-editor>
        <div className="flex-1 overflow-y-auto select-scroll px-1 pb-28 space-y-3">
          {columns.map((column, index) => (
            <div key={column.key} data-column-row={column.name} className="rounded-xl border border-slate-200 bg-white p-3 space-y-2.5" style={{ borderLeft: `4px solid ${column.color}` }}>
              <div className="flex items-center gap-2">
                <div className="flex flex-col">
                  <button type="button" aria-label="Subir" data-action="up" disabled={index === 0} onClick={() => shift(index, -1)} className="p-0.5 text-slate-400 hover:text-[#022340] disabled:opacity-30"><ArrowUp size={14} /></button>
                  <button type="button" aria-label="Bajar" data-action="down" disabled={index === columns.length - 1} onClick={() => shift(index, 1)} className="p-0.5 text-slate-400 hover:text-[#022340] disabled:opacity-30"><ArrowDown size={14} /></button>
                </div>
                <input
                  aria-label="Nombre de la columna"
                  data-field="name"
                  required
                  maxLength={40}
                  value={column.name}
                  placeholder="Ej. Presupuesto enviado"
                  onChange={(e) => change(column.key, { name: e.target.value })}
                  className={`${inputClass} font-semibold`}
                />
                <button
                  type="button"
                  aria-label={`Borrar la columna ${column.name}`}
                  data-action="remove"
                  disabled={columns.length === 1}
                  title={columns.length === 1 ? 'El tablero necesita al menos una columna' : 'Borrar columna'}
                  onClick={() => remove(column)}
                  className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors disabled:opacity-30 disabled:hover:bg-transparent"
                >
                  <Trash2 size={16} />
                </button>
              </div>
              <input
                aria-label="Descripción de la columna"
                data-field="description"
                maxLength={150}
                value={column.description}
                placeholder="Para qué es esta columna (opcional)"
                onChange={(e) => change(column.key, { description: e.target.value })}
                className={`${inputClass} text-xs py-2`}
              />
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-1.5" role="radiogroup" aria-label="Color">
                  {info.colors.map((color) => (
                    <button
                      key={color}
                      type="button"
                      role="radio"
                      aria-checked={column.color === color}
                      aria-label={`Color ${color}`}
                      data-color={color}
                      onClick={() => change(column.key, { color })}
                      className={`w-5 h-5 rounded-full transition-transform ${column.color === color ? 'ring-2 ring-offset-2 ring-[#022340] scale-110' : 'hover:scale-110'}`}
                      style={{ backgroundColor: color }}
                    />
                  ))}
                </div>
                <span className="text-xs text-slate-500">{column.id ? cards(column.customers) : 'Columna nueva'}</span>
              </div>
            </div>
          ))}

          <button
            type="button"
            data-action="add-column"
            disabled={columns.length >= info.max_columns}
            onClick={add}
            className="w-full inline-flex items-center justify-center gap-1.5 py-2.5 text-sm font-bold text-[#3F768C] border border-dashed border-slate-300 rounded-xl hover:bg-slate-50 hover:text-[#022340] transition-colors disabled:opacity-40"
          >
            <Plus size={16} />
            {columns.length >= info.max_columns ? `Máximo ${info.max_columns} columnas` : 'Agregar columna'}
          </button>

          {removed.length > 0 && (
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 space-y-3" data-removed-columns>
              <span className="block text-sm font-bold text-amber-800">Se borrarán al guardar</span>
              {removed.map((column) => (
                <div key={column.key} data-removed={column.name} className="space-y-1.5">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-semibold text-amber-900">{column.name}{column.customers > 0 ? ` · ${cards(column.customers)}` : ''}</span>
                    <button type="button" data-action="restore" onClick={() => restore(column)} className="inline-flex items-center gap-1 text-xs font-bold text-[#3F768C] hover:text-[#022340]">
                      <RotateCcw size={12} /> No borrar
                    </button>
                  </div>
                  {column.customers > 0 && (
                    <div className="flex flex-wrap items-center gap-2 text-xs text-amber-800">
                      <span>Sus tarjetas pasan a:</span>
                      <Select
                        variant="chip"
                        ariaLabel={`Columna para las tarjetas de ${column.name}`}
                        placeholder="Elige una columna"
                        clearable={false}
                        value={moves[column.id as string] ?? ''}
                        onChange={(e) => { setError(''); setMoves((current) => ({ ...current, [column.id as string]: String(e.target.value) })); }}
                        options={kept.map((item) => ({ value: item.id as string, label: item.name || 'Sin nombre' }))}
                      />
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

          <ErrorAlert message={error} />
        </div>

        <div className="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 p-4 flex items-center justify-end gap-3 z-10">
          <Button type="button" variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button type="submit" variant="primary" disabled={saving} data-action="save-columns">{saving ? 'Guardando...' : 'Guardar columnas'}</Button>
        </div>
      </form>
    </FormDrawer>
  );
}
