'use client';

import { Plus, Trash2 } from 'lucide-react';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import type { LookCard } from '@/hooks/usePlatform';
import { LOOK_ICONS, LookIcon } from '../../lookIcons';

interface LookCardsProps {
  cards: LookCard[];
  onChange: (cards: LookCard[]) => void;
  hint: string;
}

export default function LookCards({ cards, onChange, hint }: LookCardsProps) {
  const update = (index: number, patch: Partial<LookCard>) =>
    onChange(cards.map((card, i) => (i === index ? { ...card, ...patch } : card)));

  return (
    <div className="space-y-3" data-look-cards>
      <div className="flex items-center justify-between gap-2">
        <span>
          <span className="block text-sm font-semibold text-slate-800">Tarjetas (hasta 3)</span>
          <span className="block text-xs text-slate-500">{hint}</span>
        </span>
        {cards.length < 3 && (
          <button type="button" data-add-card onClick={() => onChange([...cards, { icon: 'sparkles', title: '', text: '' }])} className="inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink">
            <Plus size={14} /> Agregar tarjeta
          </button>
        )}
      </div>

      {cards.length === 0 && <p className="text-xs text-slate-400">Sin tarjetas.</p>}

      {cards.map((card, index) => (
        <div key={index} className="rounded-xl border border-slate-200 p-3 space-y-2.5" data-card-index={index}>
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-bold text-slate-600">Tarjeta {index + 1}</span>
            <button type="button" data-remove-card={index} onClick={() => onChange(cards.filter((_, i) => i !== index))} className="inline-flex items-center gap-1 text-xs font-semibold text-rose-700 hover:text-rose-800">
              <Trash2 size={13} /> Quitar
            </button>
          </div>
          <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={`Ícono de la tarjeta ${index + 1}`}>
            {Object.entries(LOOK_ICONS).map(([name, { label }]) => {
              const selected = card.icon === name;
              return (
                <button
                  key={name}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  title={label}
                  aria-label={label}
                  data-icon={name}
                  onClick={() => update(index, { icon: name })}
                  className={`p-2 rounded-lg border transition-colors ${selected ? 'border-brand bg-brand text-white' : 'border-slate-200 text-slate-500 hover:border-brand-accent hover:text-brand-ink'}`}
                >
                  <LookIcon name={name} className="w-4 h-4" />
                </button>
              );
            })}
          </div>
          <div>
            <label className={labelClass} htmlFor={`card-title-${index}`}>Título</label>
            <input id={`card-title-${index}`} className={inputClass} maxLength={40} value={card.title} onChange={(e) => update(index, { title: e.target.value })} />
          </div>
          <div>
            <label className={labelClass} htmlFor={`card-text-${index}`}>Texto</label>
            <textarea id={`card-text-${index}`} rows={2} className={inputClass} maxLength={140} value={card.text} onChange={(e) => update(index, { text: e.target.value })} />
          </div>
        </div>
      ))}
    </div>
  );
}
