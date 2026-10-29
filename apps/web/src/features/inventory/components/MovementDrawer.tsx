'use client';

import { useEffect, useState } from 'react';
import { ArrowDownLeft, ArrowUpRight, ClipboardCheck, Package } from 'lucide-react';
import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money } from '@/features/sales/sales';
import { currencySymbol } from '@/lib/currency';
import {
  formatUnits,
  type InventoryBoardData,
  type InventoryProduct,
  type WarehouseInfo,
} from '../inventory';

interface MovementDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  initialMode?: 'entry' | 'exit' | 'count';
  initialProduct?: InventoryProduct | null;
  initialWarehouseId?: string;
  warehouses: WarehouseInfo[];
  products: InventoryProduct[];
  currency: string;
  canEdit: boolean;
  onSaved: (board: InventoryBoardData, message: string) => void;
}

const ENTRY_TYPES = [
  { value: 'purchase', label: 'Compra o reposición' },
  { value: 'initial', label: 'Carga inicial de inventario' },
  { value: 'return', label: 'Devolución o reingreso' },
];

const EXIT_TYPES = [
  { value: 'adjustment', label: 'Merma, vencimiento o ajuste' },
  { value: 'service_consumption', label: 'Consumo interno en atención' },
];

export default function MovementDrawer({
  isOpen,
  onClose,
  initialMode = 'entry',
  initialProduct = null,
  initialWarehouseId = '',
  warehouses,
  products,
  currency,
  canEdit,
  onSaved,
}: MovementDrawerProps) {
  const operableWarehouses = warehouses.filter((w) => w.can_operate && w.status === 'active');
  const [mode, setMode] = useState<'entry' | 'exit' | 'count'>(initialMode);
  const [warehouseId, setWarehouseId] = useState('');
  const [productId, setProductId] = useState('');
  const [subType, setSubType] = useState('purchase');
  const [quantity, setQuantity] = useState('');
  const [unitCost, setUnitCost] = useState('');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setMode(initialMode);
    const defaultWh =
      (initialWarehouseId && operableWarehouses.some((w) => w.id === initialWarehouseId)
        ? initialWarehouseId
        : operableWarehouses[0]?.id) ?? '';
    setWarehouseId(defaultWh);

    const prod = initialProduct ?? products[0] ?? null;
    setProductId(prod?.id ?? '');
    setSubType(initialMode === 'exit' ? 'adjustment' : 'purchase');

    if (initialMode === 'count' && prod && defaultWh) {
      const current = prod.stocks[defaultWh]?.quantity ?? 0;
      setQuantity(String(current));
    } else {
      setQuantity('');
    }
    setUnitCost(prod ? String(prod.cost ?? 0) : '');
    setReason('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, initialMode, initialProduct, initialWarehouseId]);

  const selectedProduct = products.find((p) => p.id === productId) ?? null;
  const currentStock = selectedProduct && warehouseId ? (selectedProduct.stocks[warehouseId]?.quantity ?? 0) : 0;

  const handleModeChange = (nextMode: 'entry' | 'exit' | 'count') => {
    setMode(nextMode);
    setError('');
    if (nextMode === 'entry') setSubType('purchase');
    if (nextMode === 'exit') setSubType('adjustment');
    if (nextMode === 'count' && selectedProduct && warehouseId) {
      setQuantity(String(selectedProduct.stocks[warehouseId]?.quantity ?? 0));
    } else if (mode === 'count') {
      setQuantity('');
    }
  };

  const handleProductChange = (nextId: string) => {
    setProductId(nextId);
    const prod = products.find((p) => p.id === nextId);
    if (prod) {
      setUnitCost(String(prod.cost ?? 0));
      if (mode === 'count' && warehouseId) {
        setQuantity(String(prod.stocks[warehouseId]?.quantity ?? 0));
      }
    }
  };

  const handleWarehouseChange = (nextWh: string) => {
    setWarehouseId(nextWh);
    if (mode === 'count' && selectedProduct && nextWh) {
      setQuantity(String(selectedProduct.stocks[nextWh]?.quantity ?? 0));
    }
  };

  const numericQty = Number(quantity || 0);
  const countDelta = mode === 'count' ? Math.round((numericQty - currentStock) * 100) / 100 : 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!warehouseId || !productId) {
      setError('Selecciona la sede y el producto.');
      return;
    }

    try {
      setSaving(true);
      setError('');
      const res = await api.post('/inventory/movements', {
        mode,
        warehouse_id: warehouseId,
        product_id: productId,
        type: mode === 'count' ? 'adjustment' : subType,
        quantity: Number(quantity || 0),
        unit_cost: mode === 'entry' && unitCost !== '' ? Number(unitCost) : null,
        reason: reason.trim() || null,
      });

      const msg =
        mode === 'count'
          ? `Conteo físico guardado para ${selectedProduct?.name ?? 'el producto'}.`
          : mode === 'entry'
            ? `Ingreso registrado (+${formatUnits(numericQty)} de ${selectedProduct?.name ?? 'producto'}).`
            : `Salida registrada (-${formatUnits(numericQty)} de ${selectedProduct?.name ?? 'producto'}).`;

      onSaved(res.data, msg);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo registrar el movimiento.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'inventory-movement-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={mode === 'count' ? 'Conteo físico de inventario' : 'Registrar movimiento de stock'}
      subtitle="Todo movimiento queda registrado en el kárdex con fecha, motivo y responsable"
      icon={<Package size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <span className={labelClass}>¿Qué deseas registrar?</span>
            <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Tipo de operación">
              <OptionCard
                type="button"
                role="radio"
                aria-checked={mode === 'entry'}
                selected={mode === 'entry'}
                onClick={() => handleModeChange('entry')}
                className="flex items-start gap-3 text-left rounded-xl border px-4 py-2.5 transition-colors"
              >
                <span className={`p-2 rounded-lg shrink-0 ${mode === 'entry' ? 'bg-brand text-brand-soft' : 'bg-emerald-50 text-emerald-600'}`}>
                  <ArrowDownLeft size={16} />
                </span>
                <span>
                  <span className="block text-sm font-bold text-brand-ink">Entrada de unidades</span>
                  <span className="block text-xs text-slate-500 mt-0.5">Compra rápida, carga inicial o reingreso al almacén.</span>
                </span>
              </OptionCard>

              <OptionCard
                type="button"
                role="radio"
                aria-checked={mode === 'exit'}
                selected={mode === 'exit'}
                onClick={() => handleModeChange('exit')}
                className="flex items-start gap-3 text-left rounded-xl border px-4 py-2.5 transition-colors"
              >
                <span className={`p-2 rounded-lg shrink-0 ${mode === 'exit' ? 'bg-brand text-brand-soft' : 'bg-amber-50 text-amber-600'}`}>
                  <ArrowUpRight size={16} />
                </span>
                <span>
                  <span className="block text-sm font-bold text-brand-ink">Salida o merma</span>
                  <span className="block text-xs text-slate-500 mt-0.5">Consumo interno, producto dañado, vencido o ajuste de baja.</span>
                </span>
              </OptionCard>

              {canEdit && (
                <OptionCard
                  type="button"
                  role="radio"
                  aria-checked={mode === 'count'}
                  selected={mode === 'count'}
                  onClick={() => handleModeChange('count')}
                  className="flex items-start gap-3 text-left rounded-xl border px-4 py-2.5 transition-colors"
                >
                  <span className={`p-2 rounded-lg shrink-0 ${mode === 'count' ? 'bg-brand text-brand-soft' : 'bg-blue-50 text-blue-600'}`}>
                    <ClipboardCheck size={16} />
                  </span>
                  <span>
                    <span className="block text-sm font-bold text-brand-ink">Conteo físico</span>
                    <span className="block text-xs text-slate-500 mt-0.5">Escribe cuántas unidades contaste en estante y el sistema ajusta la diferencia.</span>
                  </span>
                </OptionCard>
              )}
            </div>
          </div>

          <Select
            label="Sede / Almacén *"
            clearable={false}
            value={warehouseId}
            onChange={(e) => handleWarehouseChange(String(e.target.value))}
            options={operableWarehouses.map((w) => ({ value: w.id, label: `${w.branch_name} (${w.name})` }))}
          />

          <Select
            label="Producto *"
            clearable={false}
            value={productId}
            onChange={(e) => handleProductChange(String(e.target.value))}
            options={products.map((p) => ({
              value: p.id,
              label: `${p.name} (${p.sku}) · Stock sede: ${formatUnits(warehouseId ? (p.stocks[warehouseId]?.quantity ?? 0) : p.total_stock)}`,
            }))}
          />

          {selectedProduct && warehouseId && (
            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between text-xs">
              <div>
                <span className="text-slate-500 block">Existencia actual en sede</span>
                <span className="text-base font-black text-brand-ink tabular-nums">{formatUnits(currentStock)} u.</span>
              </div>
              <div className="text-right">
                <span className="text-slate-500 block">Costo actual · Mínimo</span>
                <span className="font-semibold text-slate-700 tabular-nums">
                  {money(selectedProduct.cost, currency)} · mín. {formatUnits(selectedProduct.min_stock)} u.
                </span>
              </div>
            </div>
          )}

          {mode !== 'count' && (
            <Select
              label={mode === 'entry' ? 'Motivo del ingreso' : 'Tipo de salida'}
              clearable={false}
              value={subType}
              onChange={(e) => setSubType(String(e.target.value))}
              options={mode === 'entry' ? ENTRY_TYPES : EXIT_TYPES}
            />
          )}

          <div className={mode === 'entry' ? 'grid grid-cols-2 gap-3' : ''}>
            <div>
              <label className={labelClass} htmlFor="movement-qty">
                {mode === 'count' ? 'Unidades contadas físicamente *' : 'Cantidad de unidades *'}
              </label>
              <input
                id="movement-qty"
                required
                inputMode="decimal"
                placeholder={mode === 'count' ? '0' : 'Ej. 10'}
                className={inputClass}
                value={quantity}
                onChange={(e) => setQuantity(cleanAmount(e.target.value))}
              />
            </div>

            {mode === 'entry' && (
              <div>
                <label className={labelClass} htmlFor="movement-cost">Costo unitario ({currencySymbol()})</label>
                <input
                  id="movement-cost"
                  inputMode="decimal"
                  placeholder="0.00"
                  className={inputClass}
                  value={unitCost}
                  onChange={(e) => setUnitCost(cleanAmount(e.target.value))}
                />
              </div>
            )}
          </div>

          {mode === 'count' && quantity !== '' && (
            <div className={`rounded-xl border px-4 py-2.5 text-xs font-semibold ${
              countDelta === 0
                ? 'border-slate-200 bg-slate-50 text-slate-600'
                : countDelta > 0
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                  : 'border-amber-200 bg-amber-50 text-amber-800'
            }`}>
              {countDelta === 0
                ? 'El conteo coincide exactamente con el stock del sistema (sin diferencia).'
                : countDelta > 0
                  ? `Se sumarán +${formatUnits(countDelta)} unidades de ajuste positivo (sobrante).`
                  : `Se descontarán ${formatUnits(countDelta)} unidades de ajuste negativo (faltante).`}
            </div>
          )}

          <div>
            <label className={labelClass} htmlFor="movement-reason">
              Observación o motivo {mode === 'exit' ? '*' : '(opcional)'}
            </label>
            <input
              id="movement-reason"
              required={mode === 'exit'}
              maxLength={255}
              placeholder={
                mode === 'count'
                  ? 'Ej. Inventario mensual de vitrina'
                  : mode === 'exit'
                    ? 'Ej. Envase roto o uso en cabina'
                    : 'Ej. Factura F001-234'
              }
              className={inputClass}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button
            type="submit"
            form={formId}
            disabled={saving || (mode === 'count' && countDelta === 0)}
            variant="primary"
          >
            {saving ? 'Guardando...' : mode === 'count' ? 'Aplicar conteo' : 'Registrar movimiento'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
