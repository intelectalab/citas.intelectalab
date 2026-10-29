'use client';

import { Plus, Trash2 } from 'lucide-react';
import Select from '@/app/components/common/Select';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { cleanAmount, money } from '@/features/sales/sales';

export interface SupplyLine {
  productId: string;
  quantity: string;
}

export interface ServiceSuppliesData {
  currency: string;
  consume_on_service: boolean;
  can_edit: boolean;
  products: { id: string; name: string; sku: string; cost: number }[];
  supplies: { product_id: string; product_name: string; quantity: number; unit_cost: number }[];
}

interface ServiceSuppliesFieldsProps {
  data: ServiceSuppliesData | null;
  error: string;
  lines: SupplyLine[];
  onChange: (lines: SupplyLine[]) => void;
  servicePrice: number;
}

export const toLines = (data: ServiceSuppliesData): SupplyLine[] =>
  data.supplies.map((s) => ({ productId: s.product_id, quantity: String(s.quantity) }));

export const validLines = (lines: SupplyLine[]) =>
  lines.filter((l) => l.productId && Number(l.quantity) > 0).map((l) => ({ product_id: l.productId, quantity: Number(l.quantity) }));

export default function ServiceSuppliesFields({ data, error, lines, onChange, servicePrice }: ServiceSuppliesFieldsProps) {
  if (error) return <p className="text-xs text-red-600">{error}</p>;
  if (!data) return <p className="text-xs text-slate-400">Cargando insumos…</p>;

  const products = data.products;
  const costOf = (id: string) => products.find((p) => p.id === id)?.cost ?? 0;
  const total = lines.reduce((sum, l) => sum + costOf(l.productId) * Number(l.quantity || 0), 0);

  const hint = data.consume_on_service
    ? 'Cada vez que una cita de este servicio quede como atendida, estos insumos se descuentan del almacén de la sede.'
    : 'El descuento al atender está apagado en Configuración → Inventario; la lista sirve igual para saber cuánto cuesta cada atención.';

  if (!data.can_edit) {
    return (
      <div className="space-y-2">
        <p className="text-xs text-slate-500">{hint}</p>
        {data.supplies.length === 0
          ? <p className="text-sm text-slate-500">Este servicio no descuenta insumos.</p>
          : (
            <ul className="space-y-1 text-sm text-slate-700">
              {data.supplies.map((s) => <li key={s.product_id}><strong>{s.quantity}</strong> × {s.product_name}</li>)}
            </ul>
          )}
        <p className="text-xs text-slate-400">Para cambiarlos necesitas el permiso de Inventario &quot;Contar, recibir y ajustar&quot;.</p>
      </div>
    );
  }

  const add = () => {
    const used = new Set(lines.map((l) => l.productId));
    const next = products.find((p) => !used.has(p.id));
    onChange([...lines, { productId: next?.id ?? '', quantity: '1' }]);
  };

  const update = (index: number, patch: Partial<SupplyLine>) =>
    onChange(lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

  return (
    <div className="space-y-3" data-service-supplies>
      <p className="text-xs text-slate-500">{hint}</p>

      {products.length === 0 && lines.length === 0 ? (
        <p className="text-sm text-slate-500">
          Aún no hay productos de uso interno. En Productos y stock, registra los insumos con &quot;Para qué es&quot;: uso interno, o se vende y se usa.
        </p>
      ) : (
        <>
          {lines.map((line, index) => (
            <div key={index} className="grid grid-cols-[1fr_6rem_auto] gap-2 items-end">
              <Select
                label={index === 0 ? 'Insumo' : undefined}
                ariaLabel={`Insumo ${index + 1}`}
                clearable={false}
                value={line.productId}
                onChange={(e) => update(index, { productId: String(e.target.value) })}
                options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
              />
              <div>
                {index === 0 && <label className={labelClass}>Cantidad</label>}
                <input
                  inputMode="decimal"
                  aria-label={`Cantidad del insumo ${index + 1}`}
                  className={inputClass}
                  value={line.quantity}
                  onChange={(e) => update(index, { quantity: cleanAmount(e.target.value) })}
                />
              </div>
              <button type="button" title="Quitar insumo" onClick={() => onChange(lines.filter((_, i) => i !== index))} className="p-2.5 text-slate-400 hover:text-red-600 rounded-lg">
                <Trash2 size={16} />
              </button>
            </div>
          ))}

          <button type="button" onClick={add} className="inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink">
            <Plus size={14} /> Agregar insumo
          </button>

          {lines.length > 0 && (
            <p className="text-xs text-slate-500">
              Costo de insumos por atención: <strong className="text-brand-ink">{money(total, data.currency)}</strong>
              {servicePrice > 0 && <> · queda {money(Math.max(0, servicePrice - total), data.currency)} del precio</>}
            </p>
          )}
        </>
      )}
    </div>
  );
}
