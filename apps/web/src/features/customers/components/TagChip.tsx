import { X } from 'lucide-react';

export interface CustomerTag {
  id: string;
  name: string;
  color: string | null;
  customers?: number;
}

export default function TagChip({ tag, onRemove, size = 'md' }: { tag: CustomerTag; onRemove?: () => void; size?: 'sm' | 'md' }) {
  return (
    <span
      data-tag={tag.id}
      className={`inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white font-semibold text-slate-700 ${size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs'}`}
    >
      <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: tag.color ?? undefined }} />
      <span className="truncate max-w-40">{tag.name}</span>
      {onRemove && (
        <button type="button" data-action="remove-tag" title={`Quitar ${tag.name}`} onClick={onRemove} className="-mr-1 p-0.5 rounded-full text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors">
          <X size={12} />
        </button>
      )}
    </span>
  );
}

export function ColorPicker({ colors, value, onChange }: { colors: string[]; value: string; onChange: (color: string) => void }) {
  return (
    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Color">
      {colors.map((color) => (
        <button
          key={color}
          type="button"
          role="radio"
          aria-checked={value === color}
          aria-label={`Color ${color}`}
          data-color={color}
          onClick={() => onChange(color)}
          className={`w-7 h-7 rounded-full border-2 transition-transform ${value === color ? 'border-brand-ink scale-110' : 'border-white shadow-xs hover:scale-105'}`}
          style={{ backgroundColor: color }}
        />
      ))}
    </div>
  );
}
