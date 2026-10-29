'use client';

import { useEffect, useMemo, useState } from 'react';
import { ArrowDownLeft, ArrowRightLeft, ArrowUpRight, History } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type ExportColumn, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { momentOf } from '@/features/sales/sales';
import MovementDrawer from './MovementDrawer';
import { MOVEMENT_TONES, formatUnits, type InventoryMovement } from '../inventory';
import { useInventoryBoard } from '../useInventoryBoard';

const ENTRIES = ['initial', 'purchase', 'return'];
const EXITS = ['sale', 'service_consumption', 'adjustment'];
const TRANSFERS = ['transfer_in', 'transfer_out'];

const TYPE_OPTIONS = [
  { value: 'initial', label: 'Carga inicial' },
  { value: 'purchase', label: 'Compra / ingreso' },
  { value: 'sale', label: 'Venta' },
  { value: 'service_consumption', label: 'Consumo en atención' },
  { value: 'adjustment', label: 'Ajuste / conteo' },
  { value: 'transfer_out', label: 'Salida por traslado' },
  { value: 'transfer_in', label: 'Entrada por traslado' },
  { value: 'return', label: 'Devolución' },
];

export default function InventoryKardex() {
  const inv = useInventoryBoard();
  const { board, warehouses, warehouseFilter, can } = inv;
  const [view, setView] = useState<'all' | 'entries' | 'exits' | 'transfers'>('all');
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ type: '', product_id: '' });
  const [drawerOpen, setDrawerOpen] = useState(false);

  useEffect(() => {
    const product = new URLSearchParams(window.location.search).get('product');
    if (product) setFilters((current) => ({ ...current, product_id: product }));
  }, []);

  const products = board?.products ?? [];
  const multiBranch = warehouses.length > 1;

  const all = useMemo(() => {
    const list = board?.movements ?? [];
    return warehouseFilter ? list.filter((m) => m.warehouse_id === warehouseFilter) : list;
  }, [board?.movements, warehouseFilter]);

  const term = search.trim().toLowerCase();
  const rows = all.filter((m) => {
    if (view === 'entries' && !ENTRIES.includes(m.type)) return false;
    if (view === 'exits' && !EXITS.includes(m.type)) return false;
    if (view === 'transfers' && !TRANSFERS.includes(m.type)) return false;
    if (filters.type && m.type !== filters.type) return false;
    if (filters.product_id && m.product_id !== filters.product_id) return false;
    return !term || `${m.product_name} ${m.product_sku} ${m.reason ?? ''} ${m.by} ${m.branch_name}`.toLowerCase().includes(term);
  });

  const count = (types: string[]) => all.filter((m) => types.includes(m.type)).length;

  const cards: MetricCardItem[] = [
    {
      title: 'Todos los movimientos',
      value: all.length,
      icon: <History size={18} />,
      iconColorClass: 'text-brand-accent',
      borderColorClass: view === 'all' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
      onClick: () => setView('all'),
    },
    {
      title: 'Entradas y compras',
      value: count(ENTRIES),
      icon: <ArrowDownLeft size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: view === 'entries' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'entries' ? 'all' : 'entries'),
    },
    {
      title: 'Salidas, ventas y atenciones',
      value: count(EXITS),
      icon: <ArrowUpRight size={18} />,
      iconColorClass: 'text-amber-600',
      borderColorClass: view === 'exits' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'exits' ? 'all' : 'exits'),
    },
    ...(multiBranch ? [{
      title: 'Traslados entre sedes',
      value: count(TRANSFERS),
      icon: <ArrowRightLeft size={18} />,
      iconColorClass: 'text-teal-600',
      borderColorClass: view === 'transfers' ? 'border-teal-500 ring-2 ring-teal-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'transfers' ? 'all' : 'transfers'),
    }] : []),
  ];

  const columns: Column<InventoryMovement>[] = [
    {
      header: 'Fecha',
      cell: (m) => <span key={`dt-${m.id}`} className="text-xs text-slate-600 tabular-nums">{momentOf(m.created_at)}</span>,
    },
    {
      header: 'Producto',
      cell: (m) => (
        <div key={`mp-${m.id}`}>
          <span className="block text-sm font-bold text-brand-ink">{m.product_name}</span>
          <span className="block text-xs text-slate-400 font-mono">{m.product_sku}</span>
        </div>
      ),
    },
    ...(multiBranch ? [{
      header: 'Sede',
      cell: (m: InventoryMovement) => <span key={`mb-${m.id}`} className="text-sm text-slate-700">{m.branch_name}</span>,
    }] : []),
    {
      header: 'Movimiento',
      cell: (m) => (
        <Badge key={`mt-${m.id}`} tone={MOVEMENT_TONES[m.type] ?? 'bg-slate-100 text-slate-600'} nowrap>{m.type_label}</Badge>
      ),
    },
    {
      header: 'Cantidad',
      cell: (m) => (
        <span key={`mq-${m.id}`} className={`text-sm font-black tabular-nums ${m.quantity > 0 ? 'text-emerald-600' : 'text-amber-700'}`}>
          {m.quantity > 0 ? `+${formatUnits(m.quantity)}` : formatUnits(m.quantity)}
        </span>
      ),
    },
    {
      header: 'Saldo luego',
      cell: (m) => <span key={`ma-${m.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{formatUnits(m.balance_after)} u.</span>,
    },
    {
      header: 'Motivo / Responsable',
      cell: (m) => (
        <div key={`mr-${m.id}`} className="text-xs">
          <span className="block text-slate-700">{m.reason ?? '—'}</span>
          <span className="block text-slate-400">Por {m.by}</span>
        </div>
      ),
    },
  ];

  const exportColumns: ExportColumn<InventoryMovement>[] = [
    { header: 'Fecha', type: 'datetime', value: (m) => m.created_at },
    { header: 'Producto', value: (m) => m.product_name },
    { header: 'Código (SKU)', value: (m) => m.product_sku },
    { header: 'Sede', value: (m) => m.branch_name },
    { header: 'Movimiento', value: (m) => m.type_label },
    { header: 'Cantidad', type: 'number', value: (m) => m.quantity },
    { header: 'Saldo después', type: 'number', value: (m) => m.balance_after },
    { header: 'Costo unitario', type: 'money', value: (m) => m.unit_cost },
    { header: 'Motivo', value: (m) => m.reason },
    { header: 'Registrado por', value: (m) => m.by },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={inv.error} />
      <SuccessAlert message={inv.notice} />

      <DataTable<InventoryMovement>
        title="Kárdex"
        primaryActionLabel="Registrar movimiento"
        onPrimaryAction={can.create ? () => { inv.setNotice(''); setDrawerOpen(true); } : undefined}
        tabs={[
          { id: 'all', label: 'Todos', count: all.length },
          { id: 'entries', label: 'Entradas', count: count(ENTRIES) },
          { id: 'exits', label: 'Salidas y consumos', count: count(EXITS) },
          ...(multiBranch ? [{ id: 'transfers', label: 'Traslados', count: count(TRANSFERS) }] : []),
        ]}
        activeTab={view}
        onTabChange={(id) => setView(id as typeof view)}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={[
          ...inv.warehouseFilterConfig,
          { id: 'product_id', label: 'Producto', type: 'select', options: products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` })) },
          { id: 'type', label: 'Tipo', type: 'select', options: TYPE_OPTIONS },
        ]}
        filterValues={{ ...filters, warehouse: warehouseFilter }}
        onFilterChange={(id, value) => (id === 'warehouse'
          ? inv.setWarehouseFilter(String(value ?? ''))
          : setFilters((current) => ({ ...current, [id]: String(value ?? '') })))}
        dateFilters={[{ id: 'created_at', label: 'Fecha', value: (m) => m.created_at }]}
        columns={columns}
        data={rows}
        exportTitle="Kardex_Inventario"
        exportScreen="inventory_movements"
        exportColumns={exportColumns}
        getItemKey={(m) => m.id}
        entityLabel={rows.length === 1 ? 'movimiento' : 'movimientos'}
        metricCards={cards}
      />

      <MovementDrawer
        isOpen={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        initialMode="entry"
        initialProduct={filters.product_id ? products.find((p) => p.id === filters.product_id) ?? null : null}
        initialWarehouseId={warehouseFilter}
        warehouses={warehouses}
        products={products}
        currency={inv.currency}
        canEdit={can.edit}
        onSaved={inv.saved}
      />
    </div>
  );
}
