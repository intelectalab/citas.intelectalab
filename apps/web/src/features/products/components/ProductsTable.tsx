'use client';

import Link from 'next/link';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Archive, ArrowDownLeft, ClipboardCheck, Coins, Edit2, History, Package, PackageCheck, PackageX, ShoppingBag, Trash2 } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { useAuth } from '@/hooks/useAuth';
import MovementDrawer from '@/features/inventory/components/MovementDrawer';
import { formatUnits, type InventoryProduct } from '@/features/inventory/inventory';
import { useInventoryBoard } from '@/features/inventory/useInventoryBoard';
import FormProduct from './FormProduct';
import type { Product, ProductCatalog } from '../products';

const VIEWS = [
  { id: 'all', label: 'Todos' },
  { id: 'active', label: 'Activos' },
  { id: 'inactive', label: 'Apagados' },
];

const KIND_BADGES: Record<Product['kind'], string> = {
  retail: 'bg-brand-soft/20 text-brand-ink',
  supply: 'bg-slate-100 text-slate-600',
  both: 'bg-violet-50 text-violet-700',
};

const KIND_SHORT: Record<Product['kind'], string> = {
  retail: 'Se vende',
  supply: 'Uso interno',
  both: 'Se vende y se usa',
};

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

type StockView = 'all' | 'ok' | 'low' | 'out';

interface StockInfo {
  track: boolean;
  quantity: number;
  inTransit: number;
  value: number;
  low: boolean;
  out: boolean;
  source: InventoryProduct;
}

export default function ProductsTable() {
  const { can } = useAuth();
  const [catalog, setCatalog] = useState<ProductCatalog | null>(null);
  const [view, setView] = useState('all');
  const [stockView, setStockView] = useState<StockView>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ kind: '', category_id: '' });
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);
  const [deleting, setDeleting] = useState<Product | null>(null);
  const [movement, setMovement] = useState<{ open: boolean; mode: 'entry' | 'exit' | 'count'; product: InventoryProduct | null }>({ open: false, mode: 'entry', product: null });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const withStock = Boolean(catalog?.inventory_enabled) && can('inventory.view');
  const inv = useInventoryBoard(withStock);
  const { warehouses, warehouseFilter } = inv;

  const load = useCallback(() => {
    api.get('/products')
      .then((res) => { setCatalog(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los productos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const stock = useMemo(() => {
    const map = new Map<string, StockInfo>();
    for (const p of inv.board?.products ?? []) {
      const entry = warehouseFilter ? p.stocks[warehouseFilter] : null;
      const quantity = warehouseFilter ? entry?.quantity ?? 0 : p.total_stock;
      const inTransit = warehouseFilter ? entry?.in_transit ?? 0 : p.total_in_transit;
      map.set(p.id, {
        track: p.track_stock,
        quantity,
        inTransit,
        value: Math.round(Math.max(0, quantity) * p.cost * 100) / 100,
        out: p.track_stock && quantity <= 0,
        low: p.track_stock && quantity > 0 && p.min_stock > 0 && quantity <= p.min_stock,
        source: p,
      });
    }
    return map;
  }, [inv.board?.products, warehouseFilter]);

  const all = catalog?.products ?? [];
  const term = searchTerm.trim().toLowerCase();
  const stockOf = (product: Product) => stock.get(product.id);
  const matchesStock = (product: Product, wanted: StockView) => {
    const info = stockOf(product);
    if (wanted === 'all') return true;
    if (!info?.track) return false;
    if (wanted === 'out') return info.out;
    if (wanted === 'low') return info.low;
    return !info.out && !info.low;
  };

  const rows = all.filter((product) =>
    (view === 'all' || product.status === view) &&
    (!withStock || matchesStock(product, stockView)) &&
    (!filters.kind || product.kind === filters.kind) &&
    (!filters.category_id || product.category_id === filters.category_id) &&
    (!term || `${product.name} ${product.sku} ${product.category ?? ''}`.toLowerCase().includes(term)),
  );

  const remove = async () => {
    if (!deleting) return;

    try {
      setBusy(true);
      setError('');
      const res = await api.delete(`/products/${deleting.id}`);
      setNotice(res.data?.kept
        ? `${deleting.name} ya tiene ventas o movimientos: se quitó de la lista y se conserva en las ventas donde aparece.`
        : `${deleting.name} se eliminó.`);
      load();
      inv.load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el producto.'));
    } finally {
      setBusy(false);
      setDeleting(null);
    }
  };

  const clearMessages = () => { setError(''); setNotice(''); inv.setNotice(''); inv.setError(''); };
  const open = (product: Product | null) => { clearMessages(); setEditing(product); setFormOpen(true); };
  const openMovement = (mode: 'entry' | 'exit' | 'count', product: Product | null) => {
    clearMessages();
    setMovement({ open: true, mode, product: product ? stockOf(product)?.source ?? null : null });
  };

  const filterConfigs: FilterConfig[] = [
    ...(withStock ? inv.warehouseFilterConfig : []),
    { id: 'kind', label: 'Para qué es', type: 'select', options: (catalog?.kinds ?? []).map((k) => ({ value: k.value, label: k.label })) },
    ...((catalog?.categories.length ?? 0) > 0 ? [{ id: 'category_id', label: 'Categoría', type: 'select' as const, options: (catalog?.categories ?? []).map((c) => ({ value: c.id, label: c.name })) }] : []),
  ];

  const stockCount = (wanted: StockView) => all.filter((p) => matchesStock(p, wanted)).length;
  const toggleStock = (wanted: StockView) => setStockView(stockView === wanted ? 'all' : wanted);

  const metricCards: MetricCardItem[] = withStock
    ? [
        {
          title: 'Con stock al día',
          value: stockCount('ok'),
          icon: <PackageCheck size={18} />,
          iconColorClass: 'text-emerald-600',
          borderColorClass: stockView === 'ok' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
          onClick: () => toggleStock('ok'),
        },
        {
          title: 'Bajo el mínimo',
          value: stockCount('low'),
          icon: <AlertTriangle size={18} />,
          iconColorClass: 'text-amber-600',
          borderColorClass: stockView === 'low' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300',
          onClick: () => toggleStock('low'),
        },
        {
          title: 'Agotados',
          value: stockCount('out'),
          icon: <PackageX size={18} />,
          iconColorClass: 'text-red-600',
          borderColorClass: stockView === 'out' ? 'border-red-500 ring-2 ring-red-500/20' : 'hover:border-slate-300',
          onClick: () => toggleStock('out'),
        },
        {
          title: 'Valor en almacén',
          value: money([...stock.values()].reduce((sum, s) => sum + s.value, 0), inv.currency),
          icon: <Coins size={18} />,
          iconColorClass: 'text-brand-accent',
          borderColorClass: stockView === 'all' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
          onClick: () => setStockView('all'),
        },
      ]
    : [
        {
          title: 'Activos',
          value: all.filter((p) => p.status === 'active').length,
          icon: <Package size={18} />,
          iconColorClass: 'text-brand-accent',
          borderColorClass: view === 'active' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
          onClick: () => setView(view === 'active' ? 'all' : 'active'),
        },
        {
          title: 'Unidades vendidas',
          value: all.reduce((sum, p) => sum + p.sold, 0),
          icon: <ShoppingBag size={18} />,
          iconColorClass: 'text-emerald-600',
          borderColorClass: view === 'all' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
          onClick: () => setView('all'),
        },
        {
          title: 'Apagados',
          value: all.filter((p) => p.status !== 'active').length,
          icon: <Archive size={18} />,
          iconColorClass: 'text-slate-500',
          borderColorClass: view === 'inactive' ? 'border-slate-500 ring-2 ring-slate-500/20' : 'hover:border-slate-300',
          onClick: () => setView(view === 'inactive' ? 'all' : 'inactive'),
        },
      ];

  const exportColumns: ExportColumn<Product>[] = [
    { header: 'Producto', value: (p) => p.name },
    { header: 'Código (SKU)', value: (p) => p.sku },
    { header: 'Categoría', value: (p) => p.category },
    { header: 'Precio', type: 'money', value: (p) => p.price },
    { header: 'Para qué es', value: (p) => p.kind_label },
    { header: 'Vendidos', type: 'number', value: (p) => p.sold },
    ...(withStock ? [
      { header: 'Existencia', type: 'number' as const, value: (p: Product) => (stockOf(p)?.track ? stockOf(p)?.quantity ?? 0 : null) },
      { header: 'Stock mínimo', type: 'number' as const, value: (p: Product) => p.min_stock ?? 0 },
      { header: 'Costo unitario', type: 'money' as const, value: (p: Product) => p.cost ?? 0 },
      { header: 'Valor en almacén', type: 'money' as const, total: true, value: (p: Product) => stockOf(p)?.value ?? 0 },
    ] : []),
    { header: 'Estado', value: (p) => (p.status === 'active' ? 'Activo' : 'Apagado') },
  ];

  const stockCell = (product: Product) => {
    const info = stockOf(product);
    if (!info) return <span key={`stock-${product.id}`} className="text-xs text-slate-400">—</span>;
    if (!info.track) return <span key={`stock-${product.id}`} className="text-xs text-slate-400" title="Empieza a controlarse con su primera entrada">Sin control</span>;

    const tone = info.out ? 'bg-red-50 text-red-600' : info.low ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700';
    const perBranch = warehouses.length > 1 && !warehouseFilter;

    return (
      <div key={`stock-${product.id}`} className="space-y-1">
        <Badge tone={tone} nowrap>{info.out ? `Agotado (${formatUnits(info.quantity)})` : `${formatUnits(info.quantity)} u.`}</Badge>
        {info.inTransit > 0 && <span className="block text-[11px] font-semibold text-amber-600">+{formatUnits(info.inTransit)} en tránsito</span>}
        {perBranch && (
          <span className="flex flex-wrap gap-1">
            {warehouses.map((w) => {
              const qty = info.source.stocks[w.id]?.quantity ?? 0;
              return (
                <span key={w.id} className="inline-flex gap-1 rounded-md border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[11px] text-slate-600 tabular-nums">
                  {w.branch_name}: <strong className={qty <= 0 ? 'text-red-600' : 'text-brand-ink'}>{formatUnits(qty)}</strong>
                </span>
              );
            })}
          </span>
        )}
      </div>
    );
  };

  const columns: Column<Product>[] = [
    {
      header: 'Producto',
      cell: (product) => (
        <div key={`name-${product.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink"><Package size={16} /></span>
          <div>
            <button type="button" data-edit={product.name} onClick={() => open(product)} className={`${styles.customerName} hover:text-brand-accent transition-colors text-left`}>
              {product.name}
            </button>
            <span className="block text-xs text-slate-400 font-mono">{product.sku}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Categoría',
      cell: (product) => <span key={`category-${product.id}`} className="text-sm text-slate-700">{product.category ?? '—'}</span>,
    },
    {
      header: 'Precio',
      cell: (product) => <span key={`price-${product.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{money(product.price, product.currency)}</span>,
    },
    {
      header: 'Para qué es',
      cell: (product) => (
        <Badge key={`kind-${product.id}`} title={product.kind_label} tone={KIND_BADGES[product.kind]} nowrap>
          {KIND_SHORT[product.kind]}
        </Badge>
      ),
    },
    ...(withStock
      ? [
          { header: 'Existencia', cell: stockCell },
          {
            header: 'Mínimo',
            cell: (product: Product) => (
              <span key={`min-${product.id}`} className="text-sm text-slate-600 tabular-nums">
                {stockOf(product)?.track && (product.min_stock ?? 0) > 0 ? `${formatUnits(product.min_stock ?? 0)} u.` : '—'}
              </span>
            ),
          },
        ]
      : [{
          header: 'Vendidos',
          cell: (product: Product) => <span key={`sold-${product.id}`} className="text-sm text-slate-700 tabular-nums">{product.sold}</span>,
        }]),
    {
      header: 'Estado',
      cell: (product) => (
        <Badge key={`status-${product.id}`} data-status={product.status} tone={product.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}>
          {product.status === 'active' ? 'Activo' : 'Apagado'}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (product) => (
        <div key={`actions-${product.id}`} className="flex items-center gap-1">
          {withStock && inv.can.create && (
            <button type="button" data-move={product.name} title="Registrar entrada o salida" onClick={() => openMovement('entry', product)} className={`${iconButton} hover:text-emerald-700 hover:bg-emerald-50`}>
              <ArrowDownLeft size={16} />
            </button>
          )}
          {withStock && inv.can.edit && (
            <button type="button" data-count={product.name} title="Conteo físico" onClick={() => openMovement('count', product)} className={`${iconButton} hover:text-blue-700 hover:bg-blue-50`}>
              <ClipboardCheck size={16} />
            </button>
          )}
          {withStock && (
            <Link href={`/inventory/kardex?product=${product.id}`} title="Ver su kárdex" className={`${iconButton} hover:text-brand-accent hover:bg-slate-100`}>
              <History size={16} />
            </Link>
          )}
          {can('products.edit') && (
            <button title={`Editar ${product.name}`} onClick={() => open(product)} className={`${iconButton} hover:text-brand-ink hover:bg-slate-100`}>
              <Edit2 size={16} />
            </button>
          )}
          {can('products.delete') && (
            <button data-delete={product.name} title={`Eliminar ${product.name}`} onClick={() => { clearMessages(); setDeleting(product); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
              <Trash2 size={16} />
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error || inv.error} />
      <SuccessAlert message={notice || inv.notice} />

      {withStock && (inv.can.create || inv.can.edit) && (
        <div className="flex flex-wrap justify-end gap-2">
          {inv.can.edit && (
            <Button type="button" variant="secondary" onClick={() => openMovement('count', null)}>
              <ClipboardCheck size={15} /> Conteo físico
            </Button>
          )}
          {inv.can.create && (
            <Button type="button" variant="secondary" onClick={() => openMovement('entry', null)}>
              <ArrowDownLeft size={15} /> Registrar movimiento
            </Button>
          )}
        </div>
      )}

      <DataTable<Product>
        title={withStock ? 'Productos y stock' : 'Productos'}
        primaryActionLabel="Nuevo producto"
        onPrimaryAction={can('products.create') ? () => open(null) : undefined}
        tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'all' ? all.length : all.filter((p) => p.status === v.id).length }))}
        activeTab={view}
        onTabChange={setView}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={{ ...filters, warehouse: warehouseFilter }}
        onFilterChange={(id, value) => (id === 'warehouse'
          ? inv.setWarehouseFilter(String(value ?? ''))
          : setFilters((current) => ({ ...current, [id]: String(value ?? '') })))}
        dateFilters={[{ id: 'created_at', label: 'Registrado', value: (p) => p.created_at }]}
        sortOptions={[
          { id: 'name', label: 'Nombre', value: (p) => p.name },
          { id: 'price', label: 'Precio', type: 'number', value: (p) => p.price },
          { id: 'sold', label: 'Más vendidos', type: 'number', value: (p) => p.sold },
          ...(withStock ? [
            { id: 'stock', label: 'Existencia', type: 'number' as const, value: (p: Product) => stockOf(p)?.quantity ?? 0 },
            { id: 'value', label: 'Valor en almacén', type: 'number' as const, value: (p: Product) => stockOf(p)?.value ?? 0 },
          ] : []),
          { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (p) => p.created_at },
        ]}
        columns={columns}
        data={rows}
        exportTitle={withStock ? 'Productos_y_stock' : 'Productos'}
        exportScreen="products"
        exportColumns={exportColumns}
        getItemKey={(p) => p.id}
        entityLabel={rows.length === 1 ? 'producto' : 'productos'}
        metricCards={metricCards}
      />

      {catalog && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay productos. Crea uno con &quot;Nuevo producto&quot; y aparecerá en el punto de venta, en la pestaña Productos.</p>
      )}

      <FormProduct isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} catalog={catalog} onSaved={() => { load(); inv.load(); }} />

      {withStock && (
        <MovementDrawer
          isOpen={movement.open}
          onClose={() => setMovement((current) => ({ ...current, open: false }))}
          initialMode={movement.mode}
          initialProduct={movement.product}
          initialWarehouseId={warehouseFilter}
          warehouses={warehouses}
          products={inv.board?.products ?? []}
          currency={inv.currency}
          canEdit={inv.can.edit}
          onSaved={(board, message) => { inv.saved(board, message); load(); }}
        />
      )}

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={`¿Eliminar ${deleting?.name ?? ''}?`}
        message="Si ya se vendió, se quita de la lista pero se conserva en las ventas donde aparece. Si solo quieres dejar de ofrecerlo, apágalo desde su panel."
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
