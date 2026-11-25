'use client';

import Link from 'next/link';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { Barcode, Boxes, Coins, Edit2, History, Package, Ruler, Scissors, Tag, Warehouse } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';
import { formatUnits } from '@/features/inventory/inventory';
import { useAuth } from '@/hooks/useAuth';
import FormProduct from './FormProduct';
import type { Product, ProductCatalog } from '../products';

interface ProductDetailData {
  product: Product;
  with_stock: boolean;
  warehouses: { id: string; name: string; quantity: number; in_transit: number }[];
  used_in: { id: string; name: string; quantity: number }[];
  movements: { id: string; type_label: string; quantity: number; balance_after: number; unit_cost: number | null; reason: string | null; branch_name: string; by: string; created_at: string }[];
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

export default function ProductDetail() {
  const params = useParams();
  const id = String(params?.id ?? '');
  const { can } = useAuth();
  const [data, setData] = useState<ProductDetailData | null>(null);
  const [catalog, setCatalog] = useState<ProductCatalog | null>(null);
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    if (!id) return;
    api.get(`/products/${id}`)
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el producto.')));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const openEdit = () => {
    api.get('/products')
      .then((res) => { setCatalog(res.data); setEditing(true); })
      .catch((err) => setError(apiError(err, 'No se pudo abrir el producto.')));
  };

  const backLink = <BackLink href="/products">Volver a productos</BackLink>;

  if (!data) {
    return (
      <div className="space-y-5 pb-8">
        {backLink}
        {error ? <ErrorAlert message={error} /> : <p className="text-sm text-slate-500">Cargando producto...</p>}
      </div>
    );
  }

  const p = data.product;
  const min = p.min_stock ?? 0;
  const total = data.warehouses.reduce((sum, w) => sum + w.quantity, 0);

  const facts: [React.ReactNode, string, React.ReactNode][] = [
    [<Coins key="price" size={15} />, 'Precio', money(p.price, p.currency)],
    ...(data.with_stock ? [[<Coins key="cost" size={15} />, 'Costo de compra', money(p.cost ?? 0, p.currency)] as [React.ReactNode, string, React.ReactNode]] : []),
    [<Tag key="brand" size={15} />, 'Marca', p.brand ?? '—'],
    [<Boxes key="cat" size={15} />, 'Categoría', p.category ?? '—'],
    [<Ruler key="unit" size={15} />, 'Unidad', p.unit ?? 'Unidad'],
    [<Barcode key="bar" size={15} />, 'Código de barras', p.barcode ?? '—'],
  ];

  return (
    <div className="space-y-5 pb-8 font-sans" data-product-detail={p.sku}>
      {backLink}
      <ErrorAlert message={error} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`}>
          <div className="flex items-start gap-3">
            <span className="w-12 h-12 rounded-2xl bg-brand-soft/20 text-brand-ink flex items-center justify-center shrink-0"><Package size={22} /></span>
            <div className="min-w-0">
              <h1 className="text-xl font-black text-brand-ink tracking-tight break-words">{p.name}</h1>
              <p className="text-xs text-slate-500 font-mono">{p.sku}</p>
              <div className="flex flex-wrap gap-1.5 mt-2">
                <Badge tone={p.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'} bold>{p.status === 'active' ? 'Activo' : 'Apagado'}</Badge>
                <Badge tone="bg-brand-soft/20 text-brand-ink">{p.kind_label}</Badge>
              </div>
            </div>
          </div>

          <dl className="space-y-3">
            {facts.map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="mt-0.5 text-slate-400 shrink-0">{icon}</span>
                <div className="min-w-0">
                  <dt className={smallLabel}>{label}</dt>
                  <dd className="text-sm font-semibold text-slate-800 break-words">{value}</dd>
                </div>
              </div>
            ))}
          </dl>

          {can('products.edit') && (
            <div className="pt-4 border-t border-slate-100">
              <button type="button" data-action="edit" onClick={openEdit} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors">
                <Edit2 size={16} /> Editar producto
              </button>
            </div>
          )}
        </section>

        <div className="xl:col-span-2 space-y-5">
          {data.with_stock && (
            <section className={`${card} p-6 space-y-4`} data-card="stock">
              <SectionTitle><Warehouse className="w-4 h-4 text-brand-accent" /> Stock por almacén</SectionTitle>
              {!p.track_stock ? (
                <p className="text-sm text-slate-500">Aún no se controla su stock. Empieza con su primera entrada.</p>
              ) : (
                <>
                  <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {data.warehouses.map((w) => {
                      const under = w.quantity <= 0 || (min > 0 && w.quantity <= min);
                      return (
                        <li key={w.id} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3" data-warehouse={w.name}>
                          <span className="text-sm text-slate-700">{w.name}</span>
                          <span className="text-right">
                            <span className={`block text-base font-black tabular-nums ${under ? 'text-red-600' : 'text-brand-ink'}`}>{formatUnits(w.quantity)} {p.unit ?? 'u.'}</span>
                            {w.in_transit > 0 && <span className="block text-[11px] font-semibold text-amber-600">+{formatUnits(w.in_transit)} en tránsito</span>}
                          </span>
                        </li>
                      );
                    })}
                  </ul>
                  <p className="text-xs text-slate-500">Total: <strong className="text-slate-700">{formatUnits(total)}</strong>{min > 0 ? ` · Mínimo por almacén: ${formatUnits(min)}. En rojo, lo que está en el mínimo o debajo.` : ''}</p>
                </>
              )}
            </section>
          )}

          <section className={`${card} p-6 space-y-3`} data-card="used-in">
            <SectionTitle><Scissors className="w-4 h-4 text-brand-accent" /> Se consume en</SectionTitle>
            {data.used_in.length === 0 ? (
              <p className="text-sm text-slate-500">Ningún servicio lo usa como insumo.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {data.used_in.map((s) => (
                  <li key={s.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <span className="font-semibold text-slate-800">{s.name}</span>
                    <span className="text-slate-600 tabular-nums">{formatUnits(s.quantity)} {p.unit ?? 'u.'} por atención</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {data.with_stock && (
            <section className={`${card} overflow-hidden flex flex-col`} data-card="movements">
              <div className="flex items-center justify-between gap-3 px-6 pt-6 pb-3">
                <SectionTitle><History className="w-4 h-4 text-brand-accent" /> Últimos movimientos</SectionTitle>
                <Link href={`/inventory/kardex?product=${p.id}`} className="text-xs font-bold text-brand-accent hover:underline">Ver el kárdex</Link>
              </div>
              <PagedList
                items={data.movements}
                empty="Aún no tiene movimientos."
                renderRow={(m) => (
                  <li key={m.id} className="flex items-center gap-3 px-6 py-3">
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">{m.type_label}{m.reason ? ` · ${m.reason}` : ''}</span>
                      <span className="block text-xs text-slate-500 truncate">{momentOf(m.created_at)} · {m.branch_name} · {m.by}{m.unit_cost !== null && m.quantity > 0 ? ` · costo ${money(m.unit_cost, p.currency)}` : ''}</span>
                    </span>
                    <span className="text-right">
                      <span className={`block text-sm font-black tabular-nums ${m.quantity > 0 ? 'text-emerald-600' : 'text-amber-700'}`}>{m.quantity > 0 ? `+${formatUnits(m.quantity)}` : formatUnits(m.quantity)}</span>
                      <span className="block text-[11px] text-slate-400">quedan {formatUnits(m.balance_after)}</span>
                    </span>
                  </li>
                )}
              />
            </section>
          )}
        </div>
      </div>

      <FormProduct isOpen={editing} onClose={() => setEditing(false)} initialData={p} catalog={catalog} onSaved={load} />
    </div>
  );
}
