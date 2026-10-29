'use client';

import { useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, ShoppingCart, Truck, XCircle } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf, money } from '@/features/sales/sales';
import PurchaseDrawer from './PurchaseDrawer';
import SupplierDrawer from './SupplierDrawer';
import SuppliersPanel from './SuppliersPanel';
import { PURCHASE_TONES, formatUnits, type PurchaseOrder, type Supplier } from '../inventory';
import { useInventoryBoard } from '../useInventoryBoard';

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function InventoryPurchases() {
  const inv = useInventoryBoard();
  const { board, warehouses, warehouseFilter, can, currency } = inv;
  const [view, setView] = useState<'all' | 'pending' | 'received' | 'cancelled'>('all');
  const [search, setSearch] = useState('');
  const [purchaseDrawer, setPurchaseDrawer] = useState<{ open: boolean; receiving: PurchaseOrder | null }>({ open: false, receiving: null });
  const [panelOpen, setPanelOpen] = useState(false);
  const [supplierDrawer, setSupplierDrawer] = useState<{ open: boolean; supplier: Supplier | null; back: 'panel' | 'purchase' | null }>({ open: false, supplier: null, back: null });
  const [cancelling, setCancelling] = useState<PurchaseOrder | null>(null);
  const [deleting, setDeleting] = useState<Supplier | null>(null);
  const [busy, setBusy] = useState(false);

  const suppliers = board?.suppliers ?? [];
  const multiBranch = warehouses.length > 1;

  const all = useMemo(() => {
    const list = board?.purchases ?? [];
    return warehouseFilter ? list.filter((o) => o.warehouse_id === warehouseFilter) : list;
  }, [board?.purchases, warehouseFilter]);

  const isPending = (o: PurchaseOrder) => o.status === 'ordered' || o.status === 'partial';
  const term = search.trim().toLowerCase();
  const rows = all.filter((o) => {
    if (view === 'pending' && !isPending(o)) return false;
    if (view === 'received' && o.status !== 'received') return false;
    if (view === 'cancelled' && o.status !== 'cancelled') return false;
    return !term || `${o.code} ${o.supplier_name} ${o.branch_name} ${o.notes ?? ''}`.toLowerCase().includes(term);
  });

  const pendingCount = all.filter(isPending).length;
  const receivedCount = all.filter((o) => o.status === 'received').length;
  const cancelledCount = all.filter((o) => o.status === 'cancelled').length;

  const openSupplier = (supplier: Supplier | null, back: 'panel' | 'purchase') => {
    setPanelOpen(false);
    setPurchaseDrawer((current) => ({ ...current, open: false }));
    setSupplierDrawer({ open: true, supplier, back });
  };

  const closeSupplier = () => {
    const back = supplierDrawer.back;
    setSupplierDrawer({ open: false, supplier: null, back: null });
    if (back === 'panel') setPanelOpen(true);
    if (back === 'purchase') setPurchaseDrawer({ open: true, receiving: null });
  };

  const cancelOrder = async () => {
    if (!cancelling) return;
    try {
      setBusy(true);
      const res = await api.post(`/inventory/purchases/${cancelling.id}/cancel`);
      inv.saved(res.data, `Orden de compra ${cancelling.code} cancelada.`);
    } catch (err) {
      inv.setError(apiError(err, 'No se pudo cancelar la orden de compra.'));
    } finally {
      setBusy(false);
      setCancelling(null);
    }
  };

  const deleteSupplier = async () => {
    if (!deleting) return;
    try {
      setBusy(true);
      const res = await api.delete(`/inventory/suppliers/${deleting.id}`);
      inv.saved(res.data, deleting.orders_count > 0
        ? `${deleting.name} tiene órdenes registradas: quedó inactivo para conservar el historial.`
        : `${deleting.name} se eliminó.`);
    } catch (err) {
      inv.setError(apiError(err, 'No se pudo eliminar el proveedor.'));
    } finally {
      setBusy(false);
      setDeleting(null);
      setPanelOpen(true);
    }
  };

  const cards: MetricCardItem[] = [
    {
      title: 'Órdenes de compra',
      value: all.length,
      icon: <ShoppingCart size={18} />,
      iconColorClass: 'text-brand-accent',
      borderColorClass: view === 'all' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
      onClick: () => setView('all'),
    },
    {
      title: 'Pendientes de recibir',
      value: pendingCount,
      icon: <AlertTriangle size={18} />,
      iconColorClass: 'text-amber-600',
      borderColorClass: view === 'pending' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'pending' ? 'all' : 'pending'),
    },
    {
      title: 'Recibidas',
      value: receivedCount,
      icon: <CheckCircle2 size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: view === 'received' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'received' ? 'all' : 'received'),
    },
  ];

  const columns: Column<PurchaseOrder>[] = [
    {
      header: 'Orden',
      cell: (o) => (
        <div key={`oc-${o.id}`}>
          <span className="block text-sm font-bold text-brand-ink font-mono">{o.code}</span>
          <span className="block text-xs text-slate-400">{momentOf(o.created_at)}</span>
        </div>
      ),
    },
    {
      header: multiBranch ? 'Proveedor / Sede' : 'Proveedor',
      cell: (o) => (
        <div key={`os-${o.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{o.supplier_name}</span>
          {multiBranch && <span className="block text-xs text-slate-500">{o.branch_name}</span>}
        </div>
      ),
    },
    {
      header: 'Productos',
      cell: (o) => (
        <div key={`oi-${o.id}`} className="text-xs text-slate-600 space-y-0.5">
          {o.items.map((i) => (
            <span key={i.id} className="block">
              <strong>{formatUnits(i.received_quantity)}/{formatUnits(i.quantity)}</strong> × {i.product_name}
            </span>
          ))}
        </div>
      ),
    },
    {
      header: 'Total',
      cell: (o) => <span key={`ot-${o.id}`} className="text-sm font-bold text-brand-ink tabular-nums">{money(o.total, o.currency)}</span>,
    },
    {
      header: 'Estado',
      cell: (o) => <Badge key={`ost-${o.id}`} tone={PURCHASE_TONES[o.status]} nowrap>{o.status_label}</Badge>,
    },
    {
      header: 'Acciones',
      cell: (o) => (
        <div key={`oa-${o.id}`} className="flex items-center gap-1.5">
          {can.edit && isPending(o) && (
            <button
              type="button"
              onClick={() => setPurchaseDrawer({ open: true, receiving: o })}
              className="inline-flex items-center gap-1 rounded-lg bg-brand px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand/90"
            >
              <CheckCircle2 size={13} /> Recibir
            </button>
          )}
          {can.delete && o.status === 'ordered' && (
            <button type="button" title="Cancelar orden" onClick={() => setCancelling(o)} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
              <XCircle size={16} />
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={inv.error} />
      <SuccessAlert message={inv.notice} />

      <div className="flex justify-end">
        <Button type="button" variant="secondary" onClick={() => { inv.setNotice(''); setPanelOpen(true); }}>
          <Truck size={15} /> Proveedores ({suppliers.length})
        </Button>
      </div>

      <DataTable<PurchaseOrder>
        title="Compras"
        primaryActionLabel="Nueva orden de compra"
        onPrimaryAction={can.create ? () => { inv.setNotice(''); setPurchaseDrawer({ open: true, receiving: null }); } : undefined}
        tabs={[
          { id: 'all', label: 'Todas', count: all.length },
          { id: 'pending', label: 'Por recibir', count: pendingCount },
          { id: 'received', label: 'Recibidas', count: receivedCount },
          { id: 'cancelled', label: 'Canceladas', count: cancelledCount },
        ]}
        activeTab={view}
        onTabChange={(id) => setView(id as typeof view)}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={inv.warehouseFilterConfig}
        filterValues={{ warehouse: warehouseFilter }}
        onFilterChange={(_, value) => inv.setWarehouseFilter(String(value ?? ''))}
        dateFilters={[{ id: 'created_at', label: 'Fecha de orden', value: (o) => o.created_at }]}
        columns={columns}
        data={rows}
        exportTitle="Ordenes_de_Compra"
        exportScreen="inventory_purchases"
        exportColumns={[
          { header: 'Orden', value: (o) => o.code },
          { header: 'Fecha', type: 'datetime', value: (o) => o.created_at },
          { header: 'Proveedor', value: (o) => o.supplier_name },
          { header: 'Sede', value: (o) => o.branch_name },
          { header: 'Estado', value: (o) => o.status_label },
          { header: 'Total', type: 'money', total: true, value: (o) => o.total },
        ]}
        getItemKey={(o) => o.id}
        entityLabel={rows.length === 1 ? 'orden' : 'órdenes'}
        metricCards={cards}
      />

      <PurchaseDrawer
        isOpen={purchaseDrawer.open}
        onClose={() => setPurchaseDrawer({ open: false, receiving: null })}
        receivingOrder={purchaseDrawer.receiving}
        warehouses={warehouses}
        suppliers={suppliers}
        products={board?.products ?? []}
        currency={currency}
        onOpenNewSupplier={() => openSupplier(null, 'purchase')}
        onSaved={inv.saved}
      />

      <SuppliersPanel
        isOpen={panelOpen}
        onClose={() => setPanelOpen(false)}
        suppliers={suppliers}
        currency={currency}
        canCreate={can.create}
        canEdit={can.edit}
        canDelete={can.delete}
        onNew={() => openSupplier(null, 'panel')}
        onEdit={(supplier) => openSupplier(supplier, 'panel')}
        onDelete={(supplier) => { setPanelOpen(false); setDeleting(supplier); }}
      />

      <SupplierDrawer
        isOpen={supplierDrawer.open}
        onClose={closeSupplier}
        initialData={supplierDrawer.supplier}
        onSaved={inv.saved}
      />

      <AppModal
        isOpen={cancelling !== null}
        type="confirm"
        variant="danger"
        title={cancelling ? `¿Cancelar la orden de compra ${cancelling.code}?` : ''}
        message="La orden queda cancelada y ya no podrá recibir mercadería."
        confirmText="Sí, cancelar orden"
        cancelText="Volver"
        isLoading={busy}
        onConfirm={cancelOrder}
        onClose={() => setCancelling(null)}
      />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={deleting ? `¿Eliminar a ${deleting.name}?` : ''}
        message="Si ya tiene órdenes de compra, queda inactivo para conservar el historial."
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={deleteSupplier}
        onClose={() => { setDeleting(null); setPanelOpen(true); }}
      />
    </div>
  );
}
