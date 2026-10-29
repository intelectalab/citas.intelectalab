'use client';

import { useEffect, useState } from 'react';
import { CheckCircle2, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
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
  type PurchaseOrder,
  type Supplier,
  type WarehouseInfo,
} from '../inventory';

interface PurchaseDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  receivingOrder?: PurchaseOrder | null;
  warehouses: WarehouseInfo[];
  suppliers: Supplier[];
  products: InventoryProduct[];
  currency: string;
  onOpenNewSupplier: () => void;
  onSaved: (board: InventoryBoardData, message: string) => void;
}

interface DraftPurchaseLine {
  productId: string;
  quantity: string;
  unitCost: string;
}

export default function PurchaseDrawer({
  isOpen,
  onClose,
  receivingOrder = null,
  warehouses,
  suppliers,
  products,
  currency,
  onOpenNewSupplier,
  onSaved,
}: PurchaseDrawerProps) {
  const operableWarehouses = warehouses.filter((w) => w.can_operate && w.status === 'active');
  const activeSuppliers = suppliers.filter((s) => s.status === 'active');

  const [supplierId, setSupplierId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [expectedAt, setExpectedAt] = useState('');
  const [receiveNow, setReceiveNow] = useState(true);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<DraftPurchaseLine[]>([]);
  const [receiveQtys, setReceiveQtys] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setNotes('');

    if (receivingOrder) {
      const map: Record<string, string> = {};
      receivingOrder.items.forEach((item) => {
        map[item.id] = String(item.pending_quantity);
      });
      setReceiveQtys(map);
      return;
    }

    setSupplierId(activeSuppliers[0]?.id ?? '');
    setWarehouseId(operableWarehouses[0]?.id ?? '');
    setExpectedAt('');
    setReceiveNow(true);
    const firstProd = products[0];
    setLines([
      {
        productId: firstProd?.id ?? '',
        quantity: '1',
        unitCost: firstProd ? String(firstProd.cost ?? 0) : '0',
      },
    ]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, receivingOrder]);

  const updateLine = (idx: number, patch: Partial<DraftPurchaseLine>) => {
    setLines((curr) => curr.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
  };

  const handleProductPick = (idx: number, productId: string) => {
    const prod = products.find((p) => p.id === productId);
    updateLine(idx, {
      productId,
      unitCost: prod ? String(prod.cost ?? 0) : '0',
    });
  };

  const addLine = () => {
    const used = new Set(lines.map((l) => l.productId));
    const nextProd = products.find((p) => !used.has(p.id));
    setLines((curr) => [
      ...curr,
      {
        productId: nextProd?.id ?? '',
        quantity: '1',
        unitCost: nextProd ? String(nextProd.cost ?? 0) : '0',
      },
    ]);
  };

  const removeLine = (idx: number) => {
    setLines((curr) => (curr.length > 1 ? curr.filter((_, i) => i !== idx) : curr));
  };

  const draftTotal = lines.reduce(
    (sum, l) => sum + Number(l.quantity || 0) * Number(l.unitCost || 0),
    0,
  );

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      setSaving(true);
      setError('');

      if (receivingOrder) {
        const res = await api.post(`/inventory/purchases/${receivingOrder.id}/receive`, {
          notes: notes.trim() || null,
          items: receivingOrder.items.map((item) => ({
            id: item.id,
            receive_quantity: Number(receiveQtys[item.id] ?? 0),
          })),
        });
        onSaved(res.data, `Recepción registrada para la orden ${receivingOrder.code}.`);
        onClose();
        return;
      }

      const validLines = lines.filter((l) => l.productId && Number(l.quantity) > 0);
      if (validLines.length === 0) {
        setError('Agrega al menos un producto con cantidad mayor que cero.');
        setSaving(false);
        return;
      }

      const res = await api.post('/inventory/purchases', {
        supplier_id: supplierId,
        warehouse_id: warehouseId,
        expected_at: expectedAt || null,
        notes: notes.trim() || null,
        receive_now: receiveNow,
        items: validLines.map((l) => ({
          product_id: l.productId,
          quantity: Number(l.quantity),
          unit_cost: Number(l.unitCost || 0),
        })),
      });

      onSaved(
        res.data,
        receiveNow
          ? 'Compra registrada y existencias sumadas al almacén.'
          : 'Orden de compra creada (pendiente de recepción).',
      );
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la orden de compra.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'inventory-purchase-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={receivingOrder ? `Recibir orden ${receivingOrder.code}` : 'Nueva orden de compra'}
      subtitle={
        receivingOrder
          ? `${receivingOrder.supplier_name} · ${receivingOrder.branch_name}`
          : 'Registra una compra a proveedor y actualiza el stock y costo de tus productos'
      }
      icon={receivingOrder ? <CheckCircle2 size={20} /> : <ShoppingCart size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          {receivingOrder ? (
            <>
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-xs text-slate-600 space-y-1">
                <p>
                  <strong className="text-brand-ink">Proveedor:</strong> {receivingOrder.supplier_name} ·{' '}
                  <strong className="text-brand-ink">Sede:</strong> {receivingOrder.branch_name}
                </p>
                <p>
                  Indica cuántas unidades estás recibiendo ahora. Si recibes solo una parte, la orden quedará en &quot;Recepción parcial&quot; para completar el resto después.
                </p>
              </div>

              <div className="space-y-3">
                <span className={labelClass}>Productos de la orden</span>
                {receivingOrder.items.map((item) => (
                  <div
                    key={item.id}
                    className="rounded-xl border border-slate-200 bg-white p-3.5 flex items-center justify-between gap-3"
                  >
                    <div className="min-w-0">
                      <span className="block text-sm font-bold text-brand-ink truncate">{item.product_name}</span>
                      <span className="block text-xs text-slate-500">
                        Pedido: {formatUnits(item.quantity)} · Ya recibido: {formatUnits(item.received_quantity)} ·{' '}
                        <strong>Pendiente: {formatUnits(item.pending_quantity)}</strong>
                      </span>
                      <span className="block text-[11px] text-slate-400">
                        Costo pactado: {money(item.unit_cost, currency)} c/u
                      </span>
                    </div>
                    <div className="w-28 shrink-0">
                      <label className="block text-[11px] font-semibold text-slate-500 mb-1">Ingresar ahora</label>
                      <input
                        inputMode="decimal"
                        disabled={item.pending_quantity <= 0}
                        className={inputClass}
                        value={receiveQtys[item.id] ?? '0'}
                        onChange={(e) =>
                          setReceiveQtys((curr) => ({ ...curr, [item.id]: cleanAmount(e.target.value) }))
                        }
                      />
                    </div>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <>
              <Select
                label="Proveedor *"
                placeholder="Selecciona un proveedor"
                clearable={false}
                value={supplierId}
                onChange={(e) => setSupplierId(String(e.target.value))}
                options={activeSuppliers.map((s) => ({
                  value: s.id,
                  label: s.document_number ? `${s.name} (${s.document_number})` : s.name,
                }))}
                onAdd={onOpenNewSupplier}
                addLabel="Nuevo proveedor"
              />

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Select
                  label="Sede de ingreso *"
                  clearable={false}
                  value={warehouseId}
                  onChange={(e) => setWarehouseId(String(e.target.value))}
                  options={operableWarehouses.map((w) => ({ value: w.id, label: w.branch_name }))}
                />

                <div>
                  <label className={labelClass} htmlFor="purchase-expected">Fecha esperada (opcional)</label>
                  <input
                    id="purchase-expected"
                    type="date"
                    className={inputClass}
                    value={expectedAt}
                    onChange={(e) => setExpectedAt(e.target.value)}
                  />
                </div>
              </div>

              <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                <div>
                  <span className="block text-sm font-semibold text-brand-ink">Ingresar mercadería al stock ahora mismo</span>
                  <span className="block text-xs text-slate-500">
                    {receiveNow
                      ? 'La compra entrará como "Recibida" y sumará las unidades de inmediato.'
                      : 'Quedará "Pendiente de recibir" hasta que confirmes la llegada de la mercadería.'}
                  </span>
                </div>
                <Switch
                  checked={receiveNow}
                  onChange={setReceiveNow}
                  label="Ingresar mercadería al stock ahora mismo"
                />
              </div>

              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <span className={labelClass}>Productos comprados *</span>
                  <button
                    type="button"
                    onClick={addLine}
                    className="inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink"
                  >
                    <Plus size={14} /> Agregar línea
                  </button>
                </div>

                {lines.map((line, idx) => (
                  <div
                    key={idx}
                    className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 space-y-2"
                  >
                    <div className="grid grid-cols-1 sm:grid-cols-[1fr_5.5rem_7rem_auto] gap-2 items-end">
                      <Select
                        label={idx === 0 ? 'Producto' : undefined}
                        clearable={false}
                        value={line.productId}
                        onChange={(e) => handleProductPick(idx, String(e.target.value))}
                        options={products.map((p) => ({
                          value: p.id,
                          label: `${p.name} (${p.sku})`,
                        }))}
                      />
                      <div>
                        {idx === 0 && <label className={labelClass}>Cant.</label>}
                        <input
                          inputMode="decimal"
                          placeholder="1"
                          className={inputClass}
                          value={line.quantity}
                          onChange={(e) => updateLine(idx, { quantity: cleanAmount(e.target.value) })}
                        />
                      </div>
                      <div>
                        {idx === 0 && <label className={labelClass}>Costo ({currencySymbol()})</label>}
                        <input
                          inputMode="decimal"
                          placeholder="0.00"
                          className={inputClass}
                          value={line.unitCost}
                          onChange={(e) => updateLine(idx, { unitCost: cleanAmount(e.target.value) })}
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
                  </div>
                ))}

                <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                  <span className="font-semibold text-slate-600">Total de la compra</span>
                  <span className="font-black text-brand-ink tabular-nums">{money(draftTotal, currency)}</span>
                </div>
              </div>
            </>
          )}

          <div>
            <label className={labelClass} htmlFor="purchase-notes">Comprobante o notas (opcional)</label>
            <input
              id="purchase-notes"
              maxLength={500}
              placeholder="Ej. Factura F001-00982"
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
              ? 'Guardando...'
              : receivingOrder
                ? 'Confirmar ingreso'
                : receiveNow
                  ? 'Registrar compra e ingresar'
                  : 'Crear orden de compra'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
