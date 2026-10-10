'use client';

import { useState } from 'react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';

export interface CreatedDependent {
  id: string;
  name: string;
  species?: string | null;
}

interface QuickDependentProps {
  customerId: string;
  onCreated: (dependent: CreatedDependent) => void;
  onCancel: () => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';

// Mascota (u otro dependiente) nueva sin salir del formulario: lo mínimo para
// seguir. El resto de sus datos se completa después en la ficha del cliente.
export default function QuickDependent({ customerId, onCreated, onCancel }: QuickDependentProps) {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const isPet = user?.tenant?.dependents?.kind === 'pet';
  const [name, setName] = useState('');
  const [species, setSpecies] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const save = async () => {
    if (!name.trim()) { setError('Escribe su nombre.'); return; }

    try {
      setSaving(true);
      setError('');
      const res = await api.post(`/customers/${customerId}/dependents`, { name: name.trim(), species: isPet ? species.trim() || null : null });
      onCreated(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo agregar.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mt-2 p-3 bg-slate-50 border border-slate-200 rounded-lg space-y-2" data-quick-dependent>
      <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Agregar {vocab.dependent.toLowerCase()}</span>
      <div className={`grid gap-2 ${isPet ? 'grid-cols-2' : 'grid-cols-1'}`}>
        <input
          type="text"
          autoFocus
          maxLength={150}
          aria-label="Nombre"
          placeholder="Nombre"
          className={inputClass}
          value={name}
          onChange={(e) => setName(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }}
        />
        {isPet && (
          <input
            type="text"
            maxLength={80}
            aria-label="Especie"
            placeholder="Especie (perro, gato...)"
            className={inputClass}
            value={species}
            onChange={(e) => setSpecies(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }}
          />
        )}
      </div>
      {error && <p role="alert" className="text-xs font-medium text-red-600">{error}</p>}
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onCancel} disabled={saving} className="px-3 py-1.5 text-xs font-medium border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50 transition-colors disabled:opacity-50">
          Cancelar
        </button>
        <button type="button" data-action="save-dependent" onClick={save} disabled={saving} className="px-3 py-1.5 text-xs font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50">
          {saving ? 'Agregando...' : 'Agregar'}
        </button>
      </div>
    </div>
  );
}
