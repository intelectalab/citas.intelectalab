'use client';

import { useEffect, useState } from 'react';
import { Plus, Search, X } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormCustomer from './FormCustomer';

export interface PickedCustomer {
  id: string;
  code: string;
  first_name: string;
  last_name: string;
  phone?: string | null;
  document_number?: string | null;
  dependents?: { id: string; name: string; species?: string | null }[];
}

interface CustomerPickerProps {
  value: PickedCustomer | null;
  onChange: (customer: PickedCustomer | null) => void;
  // Mientras el panel que lo contiene esté cerrado no busca.
  active?: boolean;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';

// Buscador de clientes para los paneles laterales: se escribe, se elige uno y
// queda fijo con una X para cambiarlo.
export default function CustomerPicker({ value, onChange, active = true }: CustomerPickerProps) {
  const vocab = useVocabulary();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<PickedCustomer[]>([]);
  const [searching, setSearching] = useState(false);
  // Cliente nuevo sin salir del panel: al guardarlo queda elegido.
  const [formOpen, setFormOpen] = useState(false);

  useEffect(() => {
    if (!active) setQuery('');
  }, [active]);

  useEffect(() => {
    if (!active || value || query.trim().length < 2) {
      setResults([]);
      return;
    }

    setSearching(true);
    const timer = setTimeout(() => {
      api.get('/customers', { params: { search: query.trim() } })
        .then((res) => setResults(res.data?.data ?? []))
        .catch(() => setResults([]))
        .finally(() => setSearching(false));
    }, 300);

    return () => clearTimeout(timer);
  }, [query, value, active]);

  return (
    <div>
      <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">{vocab.client} *</label>
      {value ? (
        <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-lg px-3.5 py-2.5 bg-slate-50" data-chosen-customer>
          <div>
            <span className="block text-sm font-semibold text-slate-800">{value.first_name} {value.last_name}</span>
            <span className="block text-xs text-slate-500 font-mono">{value.code}{value.phone ? ` · ${value.phone}` : ''}</span>
          </div>
          <button
            type="button"
            title={`Cambiar ${vocab.client.toLowerCase()}`}
            onClick={() => { onChange(null); setQuery(''); }}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          >
            <X size={16} />
          </button>
        </div>
      ) : (
        <>
          <div className="flex items-center gap-2">
            <div className="relative flex-1">
              <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                aria-label={`Buscar ${vocab.client.toLowerCase()}`}
                placeholder="Nombre, documento, celular o código"
                className={`${inputClass} pl-9`}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </div>
            <button
              type="button"
              data-new-customer
              title={`Nuevo ${vocab.client.toLowerCase()}`}
              aria-label={`Nuevo ${vocab.client.toLowerCase()}`}
              onClick={() => setFormOpen(true)}
              className="shrink-0 p-2.5 bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
            >
              <Plus size={18} className="stroke-[2.5]" />
            </button>
          </div>
          {query.trim().length >= 2 && (
            <div className="mt-1.5 border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-56 overflow-y-auto bg-white">
              {searching && results.length === 0 ? (
                <p className="px-3.5 py-2.5 text-sm text-slate-400">Buscando...</p>
              ) : results.length === 0 ? (
                <p className="px-3.5 py-2.5 text-sm text-slate-400">Sin resultados.</p>
              ) : (
                results.map((option) => (
                  <button
                    key={option.id}
                    type="button"
                    data-customer={option.id}
                    onClick={() => onChange(option)}
                    className="w-full text-left px-3.5 py-2 hover:bg-slate-50 transition-colors"
                  >
                    <span className="block text-sm font-semibold text-slate-800">{option.first_name} {option.last_name}</span>
                    <span className="block text-xs text-slate-500 font-mono">
                      {option.code}{option.document_number ? ` · ${option.document_number}` : ''}{option.phone ? ` · ${option.phone}` : ''}
                    </span>
                  </button>
                ))
              )}
            </div>
          )}
        </>
      )}

      <FormCustomer
        isOpen={active && formOpen}
        onClose={() => setFormOpen(false)}
        onSubmitSuccess={(created) => {
          if (created?.id) {
            onChange(created);
            setQuery('');
          }
        }}
      />
    </div>
  );
}
