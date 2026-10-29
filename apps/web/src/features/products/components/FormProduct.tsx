'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { Package } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount } from '@/features/sales/sales';
import { currencySymbol } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';
import type { Product, ProductCatalog } from '../products';

interface FormProductProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Product | null;
  catalog: ProductCatalog | null;
  onSaved: () => void;
}


const empty = {
  name: '',
  sku: '',
  price: '',
  cost: '',
  minStock: '',
  trackStock: false,
  kind: 'retail' as Product['kind'],
  categoryId: '',
  active: true,
};

const KIND_HINTS: Record<Product['kind'], string> = {
  retail: 'Ej. un shampoo o un protector solar que el cliente se lleva',
  supply: 'Ej. guantes o algodón: no aparece en el punto de venta',
  both: 'Ej. una crema que se usa en la atención y también se vende',
};

export default function FormProduct({ isOpen, onClose, initialData, catalog, onSaved }: FormProductProps) {
  const { can } = useAuth();
  const [form, setForm] = useState(empty);
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [newCategory, setNewCategory] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setNewCategory(null);
    setCategories(catalog?.categories ?? []);
    setForm(initialData ? {
      name: initialData.name,
      sku: initialData.sku,
      price: String(initialData.price),
      cost: initialData.cost !== undefined ? String(initialData.cost) : '',
      minStock: initialData.min_stock !== undefined ? String(initialData.min_stock) : '',
      trackStock: initialData.track_stock ?? false,
      kind: initialData.kind,
      categoryId: initialData.category_id ?? '',
      active: initialData.status === 'active',
    } : empty);
  }, [isOpen, initialData, catalog]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));

  const createCategory = async () => {
    if (!newCategory?.trim()) return;

    try {
      setError('');
      const res = await api.post('/product-categories', { name: newCategory.trim() });
      setCategories((current) => [...current, res.data].sort((a, b) => a.name.localeCompare(b.name)));
      set({ categoryId: res.data.id });
      setNewCategory(null);
    } catch (err) {
      setError(apiError(err, 'No se pudo crear la categoría.'));
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      name: form.name.trim(),
      sku: form.sku.trim() || null,
      price: Number(form.price || 0),
      cost: form.cost !== '' ? Number(form.cost) : 0,
      min_stock: form.minStock !== '' ? Number(form.minStock) : 0,
      track_stock: form.trackStock,
      kind: form.kind,
      category_id: form.categoryId || null,
      status: form.active ? 'active' : 'inactive',
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) {
        await api.put(`/products/${initialData.id}`, payload);
      } else {
        await api.post('/products', payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el producto.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'product-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar: ${initialData.name}` : 'Nuevo producto'}
      subtitle={initialData ? `Código ${initialData.sku}${initialData.sold ? ` · vendido ${initialData.sold} ${initialData.sold === 1 ? 'vez' : 'veces'}` : ''}` : 'Algo que tu empresa vende o usa en las atenciones'}
      icon={<Package size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="product-name">Nombre *</label>
            <input id="product-name" required maxLength={200} placeholder="Ej. Shampoo reparador 400 ml" className={inputClass} value={form.name} onChange={(e) => set({ name: e.target.value })} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="product-price">Precio ({currencySymbol()}) *</label>
              <input id="product-price" required inputMode="decimal" placeholder="45.00" className={inputClass} value={form.price} onChange={(e) => set({ price: cleanAmount(e.target.value) })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="product-sku">Código</label>
              <input id="product-sku" maxLength={60} placeholder={initialData ? '' : 'Se pone solo'} className={`${inputClass} uppercase placeholder:normal-case`} value={form.sku} onChange={(e) => set({ sku: e.target.value.toUpperCase() })} />
            </div>
          </div>
          <p className="-mt-3 text-xs text-slate-500">El precio es el que paga el cliente, con IGV incluido si así lo configuró tu empresa. Sin código, se pone uno solo (P-0001).</p>

          <div>
            <span className={labelClass}>¿Para qué es?</span>
            <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Para qué es">
              {(catalog?.kinds ?? []).map((option) => {
                const chosen = form.kind === option.value;
                return (
                  <OptionCard
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={chosen}
                    data-product-kind={option.value}
                    onClick={() => set({ kind: option.value })} selected={chosen} className="text-left rounded-xl border px-4 py-2.5 transition-colors"
                  >
                    <span className="block text-sm font-bold text-brand-ink">{option.label}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{KIND_HINTS[option.value]}</span>
                  </OptionCard>
                );
              })}
            </div>
          </div>

          <div>
            <Select
              label="Categoría"
              placeholder="Sin categoría"
              value={form.categoryId}
              onChange={(e) => set({ categoryId: String(e.target.value) })}
              options={categories.map((c) => ({ value: c.id, label: c.name }))}
              onAdd={can('products.create') ? () => setNewCategory('') : undefined}
              addLabel="Nueva categoría"
            />
            {newCategory !== null && (
              <div className="mt-2 flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2.5" data-new-category>
                <input autoFocus maxLength={120} placeholder="Ej. Cuidado del cabello" className={inputClass} value={newCategory} onChange={(e) => setNewCategory(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createCategory(); } }} />
                <button type="button" data-save-category disabled={!newCategory.trim()} onClick={createCategory} className="shrink-0 px-3.5 py-2.5 text-sm font-bold bg-brand text-white rounded-lg disabled:opacity-50">Crear</button>
                <button type="button" onClick={() => setNewCategory(null)} className="shrink-0 px-3 py-2.5 text-sm font-semibold text-slate-600">Cancelar</button>
              </div>
            )}
          </div>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Activo</span>
              <span className="block text-xs text-slate-500">Apágalo para que deje de ofrecerse sin borrarlo.</span>
            </div>
            <Switch checked={form.active} onChange={(active) => set({ active })} label="Producto activo" />
          </div>

          {catalog?.inventory_enabled ? (
            <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <span className="block text-sm font-semibold text-brand-ink">Controlar existencias (stock)</span>
                  <span className="block text-xs text-slate-500">
                    {form.trackStock
                      ? 'Descuenta unidades al vender o usar en las atenciones. Sin unidades en la sede, no se puede vender.'
                      : 'Se vende sin descontar unidades. Se enciende solo al registrar su primera entrada de stock.'}
                  </span>
                </div>
                <Switch checked={form.trackStock} onChange={(trackStock) => set({ trackStock })} label="Controlar existencias" />
              </div>

              <div className="grid grid-cols-2 gap-3 pt-1">
                <div>
                  <label className={labelClass} htmlFor="product-cost">Costo de compra ({currencySymbol()})</label>
                  <input id="product-cost" inputMode="decimal" placeholder="0.00" className={inputClass} value={form.cost} onChange={(e) => set({ cost: cleanAmount(e.target.value) })} />
                </div>
                <div>
                  <label className={labelClass} htmlFor="product-min-stock">Alerta de stock mínimo</label>
                  <input id="product-min-stock" inputMode="decimal" placeholder="Ej. 5" className={inputClass} value={form.minStock} onChange={(e) => set({ minStock: cleanAmount(e.target.value) })} />
                </div>
              </div>
              <p className="text-xs text-slate-500">
                Las entradas, salidas y conteos se registran con los botones de esta lista; lo que compras a un proveedor, en <a href="/inventory/purchases" className="font-semibold text-brand-accent hover:underline">Compras</a>.
              </p>
            </div>
          ) : (
            <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              Con el módulo Inventario podrás llevar existencias, compras y traslados por sede.
            </p>
          )}

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="submit" form={formId} data-submit disabled={saving} variant="primary">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear producto'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
