'use client';

import { useEffect, useState } from 'react';
import { ArrowRightLeft, CheckCircle2, Plus, Trash2 } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount } from '@/features/sales/sales';
import {
  formatUnits,
  type InventoryBoardData,
  type InventoryProduct,
  type StockTransfer,
  type WarehouseInfo,
} from '../inventory';

interface TransferDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  receivingTransfer?: StockTransfer | null;
  warehouses: WarehouseInfo[];
  products: InventoryProduct[];
  twoStep: boolean;
  onSaved: (board: InventoryBoardData, message: string) => void;
}

interface DraftLine {
  productId: string;
  quantity: string;
}

export default function TransferDrawer({
  isOpen,
  onClose,
  receivingTransfer = null,
  warehouses,
  products,
  twoStep,
  onSaved,
}: TransferDrawerProps) {
  const activeWarehouses = warehouses.filter((w) => w.status === 'active');
  const operableWarehouses = activeWarehouses.filter((w) => w.can_operate);

  const [fromId, setFromId] = useState('');
  const [toId, setToId] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<DraftLine[]>([{ productId: '', quantity: '1' }]);
  const [receivedQtys, setReceivedQtys] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setNotes('');

    if (receivingTransfer) {
      const map: Record<string, string> = {};
      receivingTransfer.items.forEach((item) => {
        map[item.product_id] = String(item.quantity);
      });
      setReceivedQtys(map);
      return;
    }

    const origin = operableWarehouses[0]?.id ?? activeWarehouses[0]?.id ?? '';
    const dest = activeWarehouses.find((w) => w.id !== origin)?.id ?? '';
    setFromId(origin);
    setToId(dest);
    setLines([{ productId: products[0]?.id ?? '', quantity: '1' }]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, receivingTransfer]);

  const updateLine = (idx: number, patch: Partial<DraftLine>) => {
    setLines((current) => current.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
  };

  const addLine = () => {
    const used = new Set(lines.map((l) => l.productId));
    const nextProduct = products.find((p) => !used.has(p.id))?.id ?? '';
    setLines((current) => [...current, { productId: nextProduct, quantity: '1' }]);
  };

  const removeLine = (idx: number) => {
    setLines((current) => (current.length > 1 ? current.filter((_, i) => i !== idx) : current));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      setSaving(true);
      setError('');

      if (receivingTransfer) {
        const res = await api.post(`/inventory/transfers/${receivingTransfer.id}/receive`, {
          notes: notes.trim() || null,
          items: receivingTransfer.items.map((item) => ({
            product_id: item.product_id,
            received_quantity: Number(receivedQtys[item.product_id] ?? item.quantity),
          })),
        });
        onSaved(res.data, `Recepción del traslado ${receivingTransfer.code} confirmada.`);
        onClose();
        return;
      }

      const validLines = lines.filter((l) => l.productId && Number(l.quantity) > 0);
      if (validLines.length === 0) {
        setError('Agrega al menos un producto con cantidad mayor que cero.');
        setSaving(false);
        return;
      }

      const res = await api.post('/inventory/transfers', {
        from_warehouse_id: fromId,
        to_warehouse_id: toId,
        notes: notes.trim() || null,
        items: validLines.map((l) => ({
          product_id: l.productId,
          quantity: Number(l.quantity),
        })),
      });

      onSaved(
        res.data,
        twoStep
          ? 'Traslado enviado (en tránsito hasta que la sede de destino confirme recepción).'
          : 'Traslado completado entre sedes.',
      );
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo procesar el traslado.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'inventory-transfer-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={receivingTransfer ? `Recibir traslado ${receivingTransfer.code}` : 'Nuevo traslado entre sedes'}
      subtitle={
        receivingTransfer
          ? `De ${receivingTransfer.from_branch_name} hacia ${receivingTransfer.to_branch_name}`
          : twoStep
            ? 'Sale de la sede de origen y queda en tránsito hasta confirmar en destino'
            : 'Mueve existencias directamente de una sede a otra'
      }
      icon={receivingTransfer ? <CheckCircle2 size={20} /> : <ArrowRightLeft size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          {receivingTransfer ? (
            <>
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-xs text-slate-600 space-y-1">
                <p>
                  <strong className="text-brand-ink">Origen:</strong> {receivingTransfer.from_branch_name} →{' '}
                  <strong className="text-brand-ink">Destino:</strong> {receivingTransfer.to_branch_name}
                </p>
                <p>
                  Verifica las unidades recibidas. Si llegó menos cantidad de la enviada, la diferencia regresará automáticamente al almacén de origen para no perder la trazabilidad.
                </p>
              </div>

              <div className="space-y-3">
                <span className={labelClass}>Productos enviados</span>
                {receivingTransfer.items.map((item) => (
                  <div
                    key={item.id}
                    className="rounded-xl border border-slate-200 bg-white p-3.5 flex items-center justify-between gap-3"
                  >
                    <div className="min-w-0">
                      <span className="block text-sm font-bold text-brand-ink truncate">{item.product_name}</span>
                      <span className="block text-xs text-slate-500">
                        {item.product_sku} · Enviado: <strong>{formatUnits(item.quantity)} u.</strong>
                      </span>
                    </div>
                    <div className="w-28 shrink-0">
                      <label className="block text-[11px] font-semibold text-slate-500 mb-1">Recibido</label>
                      <input
                        inputMode="decimal"
                        className={inputClass}
                        value={receivedQtys[item.product_id] ?? String(item.quantity)}
                        onChange={(e) =>
                          setReceivedQtys((curr) => ({ ...curr, [item.product_id]: cleanAmount(e.target.value) }))
                        }
                      />
                    </div>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Select
                  label="Sede de origen *"
                  clearable={false}
                  value={fromId}
                  onChange={(e) => {
                    const nextFrom = String(e.target.value);
                    setFromId(nextFrom);
                    if (nextFrom === toId) {
                      const other = activeWarehouses.find((w) => w.id !== nextFrom)?.id ?? '';
                      setToId(other);
                    }
                  }}
                  options={operableWarehouses.map((w) => ({ value: w.id, label: w.branch_name }))}
                />

                <Select
                  label="Sede de destino *"
                  clearable={false}
                  value={toId}
                  onChange={(e) => setToId(String(e.target.value))}
                  options={activeWarehouses
                    .filter((w) => w.id !== fromId)
                    .map((w) => ({ value: w.id, label: w.branch_name }))}
                />
              </div>

              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <span className={labelClass}>Productos a trasladar *</span>
                  <button
                    type="button"
                    onClick={addLine}
                    className="inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink"
                  >
                    <Plus size={14} /> Agregar línea
                  </button>
                </div>

                {lines.map((line, idx) => {
                  const prod = products.find((p) => p.id === line.productId);
                  const avail = prod && fromId ? (prod.stocks[fromId]?.quantity ?? 0) : 0;

                  return (
                    <div
                      key={idx}
                      className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 space-y-2"
                    >
                      <div className="grid grid-cols-[1fr_6.5rem_auto] gap-2 items-end">
                        <Select
                          label={idx === 0 ? 'Producto' : undefined}
                          clearable={false}
                          value={line.productId}
                          onChange={(e) => updateLine(idx, { productId: String(e.target.value) })}
                          options={products.map((p) => ({
                            value: p.id,
                            label: `${p.name} (${formatUnits(fromId ? (p.stocks[fromId]?.quantity ?? 0) : p.total_stock)} disp.)`,
                          }))}
                        />
                        <div>
                          {idx === 0 && <label className={labelClass}>Cantidad</label>}
                          <input
                            inputMode="decimal"
                            placeholder="1"
                            className={inputClass}
                            value={line.quantity}
                            onChange={(e) => updateLine(idx, { quantity: cleanAmount(e.target.value) })}
                          />
                        </div>
                        {lines.length > 1 && (
                          <button
                            type="button"
                            onClick={() => removeLine(idx)}
                            className="p-2.5 text-slate-400 hover:text-red-600 rounded-lg"
                            title="Quitar línea"
                          >
                            <Trash2 size={16} />
                          </button>
                        )}
                      </div>
                      {prod && (
                        <p className="text-[11px] text-slate-500">
                          Disponible en origen: <strong className="text-slate-700">{formatUnits(avail)} u.</strong>
                        </p>
                      )}
                    </div>
                  );
                })}
              </div>
            </>
          )}

          <div>
            <label className={labelClass} htmlFor="transfer-notes">Notas o guía de remisión (opcional)</label>
            <input
              id="transfer-notes"
              maxLength={500}
              placeholder="Ej. Reposición semanal de vitrina"
              className={inputClass}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="submit" form={formId} disabled={saving} variant="primary">
            {saving
              ? 'Procesando...'
              : receivingTransfer
                ? 'Confirmar recepción'
                : twoStep
                  ? 'Enviar traslado'
                  : 'Registrar traslado'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
