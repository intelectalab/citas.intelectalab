'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Boxes, CalendarClock, Edit2, Eye, Hourglass, Layers, Trash2, Wallet } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import FormPackagePlan from './FormPackagePlan';
import { STATUS_BADGES, daysLeftLabel, sessionsLabel, shortDate, type CustomerPackage, type PackagePlan, type PackagesBoardData } from '../packages';

type Tab = 'sold' | 'plans';
type View = 'all' | 'active' | 'pending' | 'expiring';

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export function UsageBar({ pkg }: { pkg: Pick<CustomerPackage, 'sessions_total' | 'sessions_used' | 'sessions_scheduled'> }) {
  const total = Math.max(1, pkg.sessions_total);
  return (
    <div className="min-w-[10rem]" data-usage>
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold text-slate-700">{pkg.sessions_used} de {pkg.sessions_total} usadas</span>
        {pkg.sessions_scheduled > 0 && <span className="text-[#3F768C] font-medium">{pkg.sessions_scheduled} con cita</span>}
      </div>
      <div className="mt-1 h-2 rounded-full bg-slate-100 overflow-hidden flex">
        <span className="h-full bg-[#022340]" style={{ width: `${(pkg.sessions_used / total) * 100}%` }} />
        <span className="h-full bg-[#84D0D9]" style={{ width: `${(pkg.sessions_scheduled / total) * 100}%` }} />
      </div>
    </div>
  );
}

export default function PackagesBoard() {
  const router = useRouter();
  const [data, setData] = useState<PackagesBoardData | null>(null);
  const [tab, setTab] = useState<Tab>('sold');
  const [view, setView] = useState<View>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ status: '', mode: '' });
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PackagePlan | null>(null);
  const [deleting, setDeleting] = useState<PackagePlan | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/packages')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los paquetes.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const packages = data?.packages ?? [];
  const plans = data?.plans ?? [];
  const currency = data?.currency;
  const term = searchTerm.trim().toLowerCase();
  const active = packages.filter((p) => p.status === 'active');
  const expiring = active.filter((p) => p.days_left <= 30);

  const soldRows = packages.filter((p) =>
    (view === 'all' || (view === 'active' && p.status === 'active') || (view === 'pending' && p.status === 'active' && p.sessions_used < p.sessions_total) || (view === 'expiring' && p.status === 'active' && p.days_left <= 30)) &&
    (!filters.status || p.status === filters.status) &&
    (!term || `${p.code} ${p.name} ${p.customer?.name ?? ''}`.toLowerCase().includes(term)),
  );

  const planRows = plans.filter((p) =>
    (!filters.mode || p.booking_mode === filters.mode) &&
    (!term || `${p.name} ${p.items.map((i) => i.name).join(' ')}`.toLowerCase().includes(term)),
  );

  const switchTab = (id: string) => { setTab(id as Tab); setSearchTerm(''); setView('all'); setError(''); setNotice(''); };
  const tabs = [
    { id: 'sold', label: 'Paquetes de clientes', count: packages.length },
    { id: 'plans', label: 'Catálogo', count: plans.length },
  ];

  const openPlan = (plan: PackagePlan | null) => { setError(''); setNotice(''); setEditing(plan); setFormOpen(true); };

  const remove = async () => {
    if (!deleting) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.delete(`/package-plans/${deleting.id}`);
      setNotice(res.data?.kept
        ? `${deleting.name} ya se vendió: se quitó del catálogo y los paquetes vendidos siguen igual.`
        : `${deleting.name} se eliminó.`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el paquete.'));
    } finally {
      setBusy(false);
      setDeleting(null);
    }
  };

  const card = (title: string, value: string | number, icon: React.ReactNode, id: View, color: string, ring: string): MetricCardItem => ({
    title,
    value,
    icon,
    iconColorClass: color,
    borderColorClass: view === id ? `${ring}` : 'hover:border-slate-300',
    onClick: () => setView(view === id ? 'all' : id),
  });

  const metricCards: MetricCardItem[] = tab === 'sold' ? [
    card('Vigentes', active.length, <Boxes size={18} />, 'active', 'text-[#3F768C]', 'border-[#3F768C] ring-2 ring-[#3F768C]/20'),
    card('Sesiones por usar', active.reduce((sum, p) => sum + p.sessions_total - p.sessions_used, 0), <Layers size={18} />, 'pending', 'text-emerald-600', 'border-emerald-500 ring-2 ring-emerald-500/20'),
    card('Por atender (ya cobrado)', money(active.reduce((sum, p) => sum + p.pending_value, 0), currency), <Wallet size={18} />, 'pending', 'text-violet-600', 'border-violet-500 ring-2 ring-violet-500/20'),
    card('Vencen en 30 días', expiring.length, <Hourglass size={18} />, 'expiring', 'text-amber-600', 'border-amber-500 ring-2 ring-amber-500/20'),
  ] : [];

  const soldColumns: Column<CustomerPackage>[] = [
    {
      header: 'Paquete',
      cell: (p) => (
        <div key={`name-${p.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-[#84D0D9]/20 text-[#022340]"><Boxes size={16} /></span>
          <div className="min-w-0">
            <Link href={`/packages/${p.id}`} data-package={p.code} className={`${styles.customerName} hover:text-[#3F768C] transition-colors`}>{p.name}</Link>
            <span className="block text-xs text-slate-400" title={p.schedule_label}><span className="font-mono">{p.code}</span> · {p.conditions.booking_mode === 'scheduled' ? 'Con fechas acordadas' : 'Libre'}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Cliente',
      cell: (p) => <span key={`customer-${p.id}`} className="text-sm font-semibold text-slate-700">{p.customer ? <CustomerLink id={p.customer.id}>{p.customer.name}</CustomerLink> : '—'}</span>,
    },
    { header: 'Uso', cell: (p) => <UsageBar key={`usage-${p.id}`} pkg={p} /> },
    {
      header: 'Vigencia',
      cell: (p) => (
        <div key={`days-${p.id}`} className="text-sm">
          <span className={`block font-medium ${p.status === 'active' && p.days_left <= 30 ? 'text-amber-700' : 'text-slate-700'}`}>{daysLeftLabel(p)}</span>
          <span className="block text-xs text-slate-400">Comprado el {shortDate(p.purchased_at)}</span>
        </div>
      ),
    },
    { header: 'Precio', cell: (p) => <span key={`price-${p.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{money(p.price, p.currency)}</span> },
    {
      header: 'Estado',
      cell: (p) => <Badge key={`status-${p.id}`} data-status={p.status} tone={STATUS_BADGES[p.status]} nowrap>{p.status_label}</Badge>,
    },
    {
      header: 'Acciones',
      cell: (p) => (
        <Link key={`actions-${p.id}`} href={`/packages/${p.id}`} title={`Ver ${p.code}`} className={`${iconButton} inline-flex hover:text-[#022340] hover:bg-slate-100`}>
          <Eye size={16} />
        </Link>
      ),
    },
  ];

  const planColumns: Column<PackagePlan>[] = [
    {
      header: 'Paquete',
      cell: (p) => (
        <div key={`plan-${p.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-[#84D0D9]/20 text-[#022340]"><Boxes size={16} /></span>
          <div className="min-w-0">
            <button type="button" data-edit-plan={p.name} disabled={!data?.can.edit} onClick={() => openPlan(p)} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left disabled:hover:text-inherit`}>{p.name}</button>
            <span className="block text-xs text-slate-400">{p.items.map((i) => `${i.sessions} × ${i.name}`).join(' · ')}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Cómo se agenda',
      cell: (p) => (
        <span key={`mode-${p.id}`} className="flex items-center gap-1.5 text-sm text-slate-700">
          {p.booking_mode === 'scheduled' && <CalendarClock size={14} className="text-[#3F768C] shrink-0" />}{p.schedule_label}
        </span>
      ),
    },
    { header: 'Precio', cell: (p) => <span key={`price-${p.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{money(p.price, p.currency)}</span> },
    {
      header: 'Ahorro',
      cell: (p) => <span key={`save-${p.id}`} className={`text-sm tabular-nums ${p.savings > 0 ? 'text-emerald-700 font-semibold' : 'text-slate-400'}`}>{p.savings > 0 ? `${money(p.savings, p.currency)} (${p.savings_percent} %)` : '—'}</span>,
    },
    { header: 'Vigencia', cell: (p) => <span key={`days-${p.id}`} className="text-sm text-slate-700">{p.validity_days} días · {sessionsLabel(p.sessions)}</span> },
    { header: 'Vendidos', cell: (p) => <span key={`sold-${p.id}`} className="text-sm text-slate-700 tabular-nums">{p.sold}</span> },
    {
      header: 'Estado',
      cell: (p) => <Badge key={`status-${p.id}`} tone={p.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}>{p.status === 'active' ? 'Se vende' : 'Apagado'}</Badge>,
    },
    {
      header: 'Acciones',
      cell: (p) => (
        <div key={`actions-${p.id}`} className="flex items-center gap-1">
          {data?.can.edit && (
            <button title={`Editar ${p.name}`} onClick={() => openPlan(p)} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}><Edit2 size={16} /></button>
          )}
          {data?.can.delete && (
            <button data-delete-plan={p.name} title={`Eliminar ${p.name}`} onClick={() => { setError(''); setNotice(''); setDeleting(p); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}><Trash2 size={16} /></button>
          )}
        </div>
      ),
    },
  ];

  const statusFilter: FilterConfig[] = [{ id: 'status', label: 'Estado', type: 'select', options: (data?.options.statuses ?? []).map((s) => ({ value: s.value, label: s.label })) }];
  const modeFilter: FilterConfig[] = [{ id: 'mode', label: 'Cómo se agenda', type: 'select', options: [{ value: 'free', label: 'Libre' }, { value: 'scheduled', label: 'Con fechas acordadas' }] }];

  return (
    <div className="space-y-6 mb-8" data-packages>
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {tab === 'sold' ? (
        <DataTable<CustomerPackage>
          title="Paquetes"
          primaryActionLabel="Vender paquete"
          onPrimaryAction={data?.can.sell ? () => router.push('/pos?tab=paquetes') : undefined}
          tabs={tabs}
          activeTab={tab}
          onTabChange={switchTab}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={statusFilter}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          dateFilters={[{ id: 'purchased_at', label: 'Comprado', value: (p) => p.purchased_at }, { id: 'expires_at', label: 'Vence', value: (p) => p.expires_at, future: true }]}
          sortOptions={[
            { id: 'purchased_at', label: 'Fecha de compra', type: 'date', value: (p) => p.purchased_at },
            { id: 'expires_at', label: 'Vencimiento', type: 'date', value: (p) => p.expires_at },
            { id: 'left', label: 'Sesiones por usar', type: 'number', value: (p) => p.sessions_total - p.sessions_used },
            { id: 'customer', label: 'Cliente', value: (p) => p.customer?.name ?? '' },
          ]}
          columns={soldColumns}
          data={soldRows}
          getItemKey={(p) => p.id}
          entityLabel={soldRows.length === 1 ? 'paquete' : 'paquetes'}
          metricCards={metricCards}
        />
      ) : (
        <DataTable<PackagePlan>
          title="Paquetes"
          primaryActionLabel="Nuevo paquete"
          onPrimaryAction={data?.can.create ? () => openPlan(null) : undefined}
          tabs={tabs}
          activeTab={tab}
          onTabChange={switchTab}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={modeFilter}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          dateFilters={[{ id: 'created_at', label: 'Creado', value: (p) => p.created_at }]}
          sortOptions={[
            { id: 'name', label: 'Nombre', value: (p) => p.name },
            { id: 'price', label: 'Precio', type: 'number', value: (p) => p.price },
            { id: 'sold', label: 'Más vendidos', type: 'number', value: (p) => p.sold },
          ]}
          columns={planColumns}
          data={planRows}
          getItemKey={(p) => p.id}
          entityLabel={planRows.length === 1 ? 'paquete' : 'paquetes'}
        />
      )}

      {data && tab === 'sold' && packages.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay paquetes vendidos. Crea uno en la pestaña Catálogo y véndelo desde el punto de venta.</p>
      )}
      {data && tab === 'plans' && plans.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay paquetes en el catálogo. Crea uno con &quot;Nuevo paquete&quot;: por ejemplo, 5 controles a precio preferencial.</p>
      )}

      <FormPackagePlan isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} options={data?.options ?? null} onSaved={load} />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={`¿Eliminar ${deleting?.name ?? ''}?`}
        message="Si ya se vendió, se quita del catálogo y los paquetes vendidos siguen igual. Si solo quieres dejar de ofrecerlo, apágalo desde su panel."
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
