'use client';

import { useMemo, useState } from 'react';
import { ArrowRightLeft, CheckCircle2, Truck, XCircle } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import TransferDrawer from './TransferDrawer';
import { TRANSFER_TONES, formatUnits, type StockTransfer } from '../inventory';
import { useInventoryBoard } from '../useInventoryBoard';

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function InventoryTransfers() {
  const inv = useInventoryBoard();
  const { board, warehouses, warehouseFilter, can } = inv;
  const [view, setView] = useState<'all' | 'in_transit' | 'received' | 'cancelled'>('all');
  const [search, setSearch] = useState('');
  const [drawer, setDrawer] = useState<{ open: boolean; receiving: StockTransfer | null }>({ open: false, receiving: null });
  const [cancelling, setCancelling] = useState<StockTransfer | null>(null);
  const [busy, setBusy] = useState(false);

  const multiBranch = warehouses.length > 1;

  const all = useMemo(() => {
    const list = board?.transfers ?? [];
    return warehouseFilter
      ? list.filter((t) => t.from_warehouse_id === warehouseFilter || t.to_warehouse_id === warehouseFilter)
      : list;
  }, [board?.transfers, warehouseFilter]);

  const term = search.trim().toLowerCase();
  const rows = all.filter((t) => {
    if (view !== 'all' && t.status !== view) return false;
    return !term || `${t.code} ${t.from_branch_name} ${t.to_branch_name} ${t.notes ?? ''} ${t.items.map((i) => i.product_name).join(' ')}`.toLowerCase().includes(term);
  });

  const count = (status: StockTransfer['status']) => all.filter((t) => t.status === status).length;

  const cancel = async () => {
    if (!cancelling) return;
    try {
      setBusy(true);
      const res = await api.post(`/inventory/transfers/${cancelling.id}/cancel`);
      inv.saved(res.data, `Traslado ${cancelling.code} cancelado: las unidades volvieron a ${cancelling.from_branch_name}.`);
    } catch (err) {
      inv.setError(apiError(err, 'No se pudo cancelar el traslado.'));
    } finally {
      setBusy(false);
      setCancelling(null);
    }
  };

  const cards: MetricCardItem[] = [
    {
      title: 'Todos los traslados',
      value: all.length,
      icon: <ArrowRightLeft size={18} />,
      iconColorClass: 'text-brand-accent',
      borderColorClass: view === 'all' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
      onClick: () => setView('all'),
    },
    {
      title: 'En tránsito por recibir',
      value: count('in_transit'),
      icon: <Truck size={18} />,
      iconColorClass: 'text-amber-600',
      borderColorClass: view === 'in_transit' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'in_transit' ? 'all' : 'in_transit'),
    },
    {
      title: 'Recibidos',
      value: count('received'),
      icon: <CheckCircle2 size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: view === 'received' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'received' ? 'all' : 'received'),
    },
  ];

  const columns: Column<StockTransfer>[] = [
    {
      header: 'Traslado',
      cell: (t) => (
        <div key={`tc-${t.id}`}>
          <span className="block text-sm font-bold text-brand-ink font-mono">{t.code}</span>
          <span className="block text-xs text-slate-400">{momentOf(t.created_at)}</span>
        </div>
      ),
    },
    {
      header: 'Origen → Destino',
      cell: (t) => (
        <div key={`tr-${t.id}`} className="text-sm">
          <span className="font-semibold text-slate-800">{t.from_branch_name}</span>
          <span className="mx-1.5 text-slate-400">→</span>
          <span className="font-bold text-brand-ink">{t.to_branch_name}</span>
          {t.notes && <span className="block text-xs text-slate-400">{t.notes}</span>}
        </div>
      ),
    },
    {
      header: 'Productos',
      cell: (t) => (
        <div key={`ti-${t.id}`} className="text-xs text-slate-600 space-y-0.5">
          {t.items.map((i) => (
            <span key={i.id} className="block">
              <strong>{t.status === 'received' ? `${formatUnits(i.received_quantity)}/${formatUnits(i.quantity)}` : formatUnits(i.quantity)}</strong> × {i.product_name}
            </span>
          ))}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (t) => <Badge key={`ts-${t.id}`} tone={TRANSFER_TONES[t.status]} nowrap>{t.status_label}</Badge>,
    },
    {
      header: 'Acciones',
      cell: (t) => {
        const canReceive = Boolean(warehouses.find((w) => w.id === t.to_warehouse_id)?.can_operate);
        return (
          <div key={`ta-${t.id}`} className="flex items-center gap-1.5">
            {t.status === 'in_transit' && (can.create || can.edit) && canReceive && (
              <button
                type="button"
                onClick={() => setDrawer({ open: true, receiving: t })}
                className="inline-flex items-center gap-1 rounded-lg bg-brand px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand/90"
              >
                <CheckCircle2 size={13} /> Confirmar recepción
              </button>
            )}
            {t.status === 'in_transit' && can.delete && (
              <button type="button" title="Cancelar traslado" onClick={() => setCancelling(t)} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
                <XCircle size={16} />
              </button>
            )}
          </div>
        );
      },
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={inv.error} />
      <SuccessAlert message={inv.notice} />

      {board && !multiBranch && (
        <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3">
          Los traslados sirven para mover productos de una sede a otra. Tu empresa tiene una sola sede: cuando registres otra en Sucursales, podrás trasladar desde aquí.
        </p>
      )}

      <DataTable<StockTransfer>
        title="Traslados entre sedes"
        primaryActionLabel="Nuevo traslado"
        onPrimaryAction={can.create && multiBranch ? () => { inv.setNotice(''); setDrawer({ open: true, receiving: null }); } : undefined}
        tabs={[
          { id: 'all', label: 'Todos', count: all.length },
          { id: 'in_transit', label: 'En tránsito', count: count('in_transit') },
          { id: 'received', label: 'Recibidos', count: count('received') },
          { id: 'cancelled', label: 'Cancelados', count: count('cancelled') },
        ]}
        activeTab={view}
        onTabChange={(id) => setView(id as typeof view)}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={inv.warehouseFilterConfig}
        filterValues={{ warehouse: warehouseFilter }}
        onFilterChange={(_, value) => inv.setWarehouseFilter(String(value ?? ''))}
        dateFilters={[{ id: 'created_at', label: 'Fecha de envío', value: (t) => t.created_at }]}
        columns={columns}
        data={rows}
        exportTitle="Traslados_Inventario"
        exportScreen="inventory_transfers"
        exportColumns={[
          { header: 'Código', value: (t) => t.code },
          { header: 'Fecha', type: 'datetime', value: (t) => t.created_at },
          { header: 'Sede origen', value: (t) => t.from_branch_name },
          { header: 'Sede destino', value: (t) => t.to_branch_name },
          { header: 'Estado', value: (t) => t.status_label },
          { header: 'Unidades totales', type: 'number', value: (t) => t.total_units },
          { header: 'Notas', value: (t) => t.notes },
        ]}
        getItemKey={(t) => t.id}
        entityLabel={rows.length === 1 ? 'traslado' : 'traslados'}
        metricCards={cards}
      />

      <TransferDrawer
        isOpen={drawer.open}
        onClose={() => setDrawer({ open: false, receiving: null })}
        receivingTransfer={drawer.receiving}
        warehouses={warehouses}
        products={board?.products ?? []}
        twoStep={board?.rules.transfer_two_step ?? true}
        onSaved={inv.saved}
      />

      <AppModal
        isOpen={cancelling !== null}
        type="confirm"
        variant="danger"
        title={cancelling ? `¿Cancelar el traslado ${cancelling.code}?` : ''}
        message={`Las unidades en tránsito vuelven al almacén de ${cancelling?.from_branch_name ?? 'origen'}.`}
        confirmText="Sí, cancelar traslado"
        cancelText="Volver"
        isLoading={busy}
        onConfirm={cancel}
        onClose={() => setCancelling(null)}
      />
    </div>
  );
}
