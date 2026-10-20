'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import { Archive, Edit2, Package, ShoppingBag, Trash2 } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { useAuth } from '@/hooks/useAuth';
import FormProduct from './FormProduct';
import type { Product, ProductCatalog } from '../products';

const VIEWS = [
  { id: 'all', label: 'Todos' },
  { id: 'active', label: 'Activos' },
  { id: 'inactive', label: 'Apagados' },
];

const KIND_BADGES: Record<Product['kind'], string> = {
  retail: 'bg-[#84D0D9]/20 text-[#022340]',
  supply: 'bg-slate-100 text-slate-600',
  both: 'bg-violet-50 text-violet-700',
};

const KIND_SHORT: Record<Product['kind'], string> = {
  retail: 'Se vende',
  supply: 'Uso interno',
  both: 'Se vende y se usa',
};

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function ProductsTable() {
  const { can } = useAuth();
  const [catalog, setCatalog] = useState<ProductCatalog | null>(null);
  const [view, setView] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ kind: '', category_id: '' });
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);
  const [deleting, setDeleting] = useState<Product | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/products')
      .then((res) => { setCatalog(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los productos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const all = catalog?.products ?? [];
  const term = searchTerm.trim().toLowerCase();

  const rows = all.filter((product) =>
    (view === 'all' || product.status === view) &&
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
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el producto.'));
    } finally {
      setBusy(false);
      setDeleting(null);
    }
  };

  const open = (product: Product | null) => { setError(''); setNotice(''); setEditing(product); setFormOpen(true); };

  const filterConfigs: FilterConfig[] = [
    { id: 'kind', label: 'Para qué es', type: 'select', options: (catalog?.kinds ?? []).map((k) => ({ value: k.value, label: k.label })) },
    ...((catalog?.categories.length ?? 0) > 0 ? [{ id: 'category_id', label: 'Categoría', type: 'select' as const, options: (catalog?.categories ?? []).map((c) => ({ value: c.id, label: c.name })) }] : []),
  ];

  const metricCards: MetricCardItem[] = [
    {
      title: 'Activos',
      value: all.filter((p) => p.status === 'active').length,
      icon: <Package size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: view === 'active' ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
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

  const columns: Column<Product>[] = [
    {
      header: 'Producto',
      cell: (product) => (
        <div key={`name-${product.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-[#84D0D9]/20 text-[#022340]"><Package size={16} /></span>
          <div>
            <button type="button" data-edit={product.name} onClick={() => open(product)} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left`}>
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
    {
      header: 'Vendidos',
      cell: (product) => <span key={`sold-${product.id}`} className="text-sm text-slate-700 tabular-nums">{product.sold}</span>,
    },
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
          {can('products.edit') && (
            <button title={`Editar ${product.name}`} onClick={() => open(product)} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
              <Edit2 size={16} />
            </button>
          )}
          {can('products.delete') && (
            <button data-delete={product.name} title={`Eliminar ${product.name}`} onClick={() => { setError(''); setNotice(''); setDeleting(product); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
              <Trash2 size={16} />
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <DataTable<Product>
        title="Productos"
        primaryActionLabel="Nuevo producto"
        onPrimaryAction={can('products.create') ? () => open(null) : undefined}
        tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'all' ? all.length : all.filter((p) => p.status === v.id).length }))}
        activeTab={view}
        onTabChange={setView}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
        dateFilters={[{ id: 'created_at', label: 'Registrado', value: (p) => p.created_at }]}
        sortOptions={[
          { id: 'name', label: 'Nombre', value: (p) => p.name },
          { id: 'price', label: 'Precio', type: 'number', value: (p) => p.price },
          { id: 'sold', label: 'Más vendidos', type: 'number', value: (p) => p.sold },
          { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (p) => p.created_at },
        ]}
        columns={columns}
        data={rows}
        getItemKey={(p) => p.id}
        entityLabel={rows.length === 1 ? 'producto' : 'productos'}
        metricCards={metricCards}
      />

      {catalog && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay productos. Crea uno con &quot;Nuevo producto&quot; y aparecerá en el punto de venta, en la pestaña Productos.</p>
      )}

      <FormProduct isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} catalog={catalog} onSaved={load} />

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
