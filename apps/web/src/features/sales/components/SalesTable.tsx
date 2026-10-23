'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/hooks/useAuth';
import { Eye, HandCoins, Receipt, RotateCcw } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { dayOf, toISO } from '@/app/components/ui/table/dateFilters';
import { currentCurrency } from '@/lib/currency';
import { money, momentOf, SALE_BADGES, type SaleRow, type UnbilledAppointment } from '../sales';
import SaleDetailPanel from './SaleDetailPanel';
import ChargePanel from './ChargePanel';

const VIEWS: { id: string; label: string; test: (sale: SaleRow) => boolean }[] = [
  { id: 'all', label: 'Todas', test: () => true },
  { id: 'open', label: 'Por cobrar', test: (s) => s.status === 'pending' || s.status === 'partial' },
  { id: 'paid', label: 'Pagadas', test: (s) => s.status === 'paid' },
  { id: 'refunded', label: 'Con devolución', test: (s) => s.refunded > 0 && s.status !== 'void' },
  { id: 'void', label: 'Anuladas', test: (s) => s.status === 'void' },
];

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default function SalesTable() {
  const router = useRouter();
  const { can, hasModule } = useAuth();
  const [sales, setSales] = useState<SaleRow[] | null>(null);
  const [view, setView] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ branch_id: '', status: '' });
  const [viewing, setViewing] = useState<string | null>(null);
  const [unbilled, setUnbilled] = useState<SaleRow[] | null>(null);
  const [charging, setCharging] = useState<SaleRow | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get('/sales')
      .then((res) => {
        setSales(res.data.sales);
        setUnbilled(res.data.unbilled ? (res.data.unbilled as UnbilledAppointment[]).map((a) => ({
          id: `unbilled-${a.appointment_id}`, code: 'Sin venta', status: 'pending' as const, status_label: 'Atendida sin cobrar', created_at: a.at,
          branch_id: a.branch_id, branch_name: a.branch_name, customer: a.customer, appointment_code: a.appointment_code, detail: a.detail ?? '',
          total: a.total, paid: 0, balance: a.total, refunded: 0, invoice_number: null, invoice_label: null, unbilled: true, appointment_id: a.appointment_id,
        })) : null);
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las ventas.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const all = sales ?? [];
  const term = normalize(searchTerm.trim());
  const current = VIEWS.find((v) => v.id === view) ?? VIEWS[0];

  const showUnbilled = view === 'unbilled' && unbilled !== null;

  const rows = (showUnbilled ? unbilled : all).filter((sale) =>
    (showUnbilled || current.test(sale)) &&
    (!filters.branch_id || sale.branch_id === filters.branch_id) &&
    (!filters.status || sale.status === filters.status) &&
    (!term || normalize(`${sale.code} ${sale.customer?.name ?? ''} ${sale.detail} ${sale.invoice_number ?? ''} ${sale.appointment_code ?? ''}`).includes(term)),
  );

  const today = toISO(new Date());
  const ownCurrency = currentCurrency();
  const others = all.filter((s) => s.currency && s.currency !== ownCurrency).length;
  const live = all.filter((s) => s.status !== 'void' && (!s.currency || s.currency === ownCurrency));
  const todays = live.filter((s) => dayOf(s.created_at) === today);
  const open = all.filter(VIEWS[1].test).filter((s) => !s.currency || s.currency === ownCurrency);

  const branchOptions = [...new Map(all.map((s) => [s.branch_id, s.branch_name ?? 'Sede'])).entries()].map(([value, label]) => ({ value, label }));

  const filterConfigs: FilterConfig[] = [
    ...(branchOptions.length > 1 ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: branchOptions }] : []),
    {
      id: 'status', label: 'Estado', type: 'select',
      options: [
        { label: 'Por cobrar', value: 'pending' }, { label: 'Pago parcial', value: 'partial' }, { label: 'Pagada', value: 'paid' },
        { label: 'Devuelta en parte', value: 'partially_refunded' }, { label: 'Devuelta', value: 'refunded' }, { label: 'Anulada', value: 'void' },
      ],
    },
  ];

  const card = (id: string, on: string) => (view === id ? on : 'hover:border-slate-300');

  const metricCards: MetricCardItem[] = [
    {
      title: `Vendido hoy · ${todays.length} ${todays.length === 1 ? 'venta' : 'ventas'}`,
      value: money(todays.reduce((sum, s) => sum + s.total - s.refunded, 0)),
      icon: <Receipt size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: card('all', 'border-[#3F768C] ring-2 ring-[#3F768C]/20'),
      onClick: () => setView('all'),
    },
    {
      title: `Por cobrar · ${open.length} ${open.length === 1 ? 'venta' : 'ventas'}`,
      value: money(open.reduce((sum, s) => sum + s.balance, 0)),
      icon: <HandCoins size={18} />,
      iconColorClass: 'text-amber-600',
      borderColorClass: card('open', 'border-amber-500 ring-2 ring-amber-500/20'),
      onClick: () => setView(view === 'open' ? 'all' : 'open'),
    },
    {
      title: others ? `Devuelto · no suma ${others} en otra moneda` : 'Devuelto',
      value: money(live.reduce((sum, s) => sum + s.refunded, 0)),
      icon: <RotateCcw size={18} />,
      iconColorClass: 'text-rose-600',
      borderColorClass: card('refunded', 'border-rose-500 ring-2 ring-rose-500/20'),
      onClick: () => setView(view === 'refunded' ? 'all' : 'refunded'),
    },
  ];

  const exportColumns: ExportColumn<SaleRow>[] = [
    { header: 'Venta', value: (s) => (s.unbilled ? `Sin venta (cita ${s.appointment_code ?? ''})` : s.code) },
    { header: 'Fecha', type: 'datetime', value: (s) => s.created_at },
    { header: 'Cliente', value: (s) => s.customer?.name ?? 'Sin cliente' },
    { header: 'Sede', value: (s) => s.branch_name },
    { header: 'Detalle', value: (s) => s.detail },
    { header: 'Cita', value: (s) => s.appointment_code },
    { header: 'Total', type: 'money', value: (s) => s.total },
    { header: 'Pagado', type: 'money', value: (s) => s.paid },
    { header: 'Falta', type: 'money', value: (s) => (s.status === 'void' ? 0 : s.balance) },
    { header: 'Devuelto', type: 'money', value: (s) => s.refunded },
    { header: 'Comprobante', value: (s) => s.invoice_number },
    { header: 'Tipo de comprobante', value: (s) => s.invoice_label },
    { header: 'Estado', value: (s) => s.status_label },
  ];

  const columns: Column<SaleRow>[] = [
    {
      header: 'Venta',
      cell: (sale) => (
        <div key={`code-${sale.id}`}>
          <button type="button" data-view={sale.unbilled ? sale.appointment_code ?? '' : sale.code} onClick={() => (sale.unbilled ? setCharging(sale) : setViewing(sale.id))} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left tabular-nums`}>
            {sale.unbilled ? `Cobrar ${sale.appointment_code ?? 'cita'}` : sale.code}
          </button>
          <span className="block text-xs text-slate-400">{momentOf(sale.created_at)}</span>
        </div>
      ),
    },
    {
      header: 'Cliente',
      cell: (sale) => (
        <div key={`customer-${sale.id}`}>
          {sale.customer
            ? <CustomerLink id={sale.customer.id} className="text-sm font-semibold text-slate-800">{sale.customer.name}</CustomerLink>
            : <span className="text-sm text-slate-400">Sin cliente</span>}
          <span className="block text-xs text-slate-400">{sale.branch_name ?? '—'}</span>
        </div>
      ),
    },
    {
      header: 'Detalle',
      cell: (sale) => (
        <div key={`detail-${sale.id}`} className="max-w-[14rem]">
          <span className="block text-sm text-slate-700 truncate" title={sale.detail}>{sale.detail || '—'}</span>
          {sale.appointment_code && <span className="block text-xs text-slate-400">Cita {sale.appointment_code}</span>}
        </div>
      ),
    },
    {
      header: 'Total',
      cell: (sale) => (
        <div key={`total-${sale.id}`}>
          <span className={`block text-sm font-semibold tabular-nums ${sale.status === 'void' ? 'text-slate-400 line-through' : 'text-slate-800'}`}>{money(sale.total, sale.currency)}</span>
          {sale.balance > 0 && sale.status !== 'void' && <span className="block text-xs font-semibold text-amber-700 tabular-nums">Falta {money(sale.balance, sale.currency)}</span>}
          {sale.refunded > 0 && sale.status !== 'void' && <span className="block text-xs font-semibold text-rose-700 tabular-nums">Devuelto {money(sale.refunded, sale.currency)}</span>}
        </div>
      ),
    },
    {
      header: 'Comprobante',
      cell: (sale) => (
        <div key={`invoice-${sale.id}`}>
          <span className="block text-sm text-slate-700 tabular-nums">{sale.invoice_number ?? '—'}</span>
          {sale.invoice_label && <span className="block text-xs text-slate-400">{sale.invoice_label}</span>}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (sale) => (
        <Badge key={`status-${sale.id}`} data-status={sale.status} tone={SALE_BADGES[sale.status]} nowrap>
          {sale.status_label}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (sale) => (
        <button key={`actions-${sale.id}`} title={sale.unbilled ? 'Cobrar la cita' : `Ver la venta ${sale.code}`} onClick={() => (sale.unbilled ? setCharging(sale) : setViewing(sale.id))} className="p-1.5 text-slate-400 rounded-lg hover:text-[#022340] hover:bg-slate-100 transition-colors">
          <Eye size={16} />
        </button>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />

      <DataTable<SaleRow>
        title="Ventas"
        primaryActionLabel="Nueva venta"
        onPrimaryAction={hasModule('pos') && can('pos.view') && can('pos.create') ? () => router.push('/pos') : undefined}
        tabs={[
          ...VIEWS.map((v) => ({ id: v.id, label: v.label, count: all.filter(v.test).length })),
          ...(unbilled !== null ? [{ id: 'unbilled', label: 'Atendidas sin cobrar', count: unbilled.length }] : []),
        ]}
        activeTab={view}
        onTabChange={setView}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((values) => ({ ...values, [id]: value }))}
        dateFilters={[{ id: 'created_at', label: 'Fecha', value: (s) => s.created_at }]}
        sortOptions={[
          { id: 'created_at', label: 'Fecha', type: 'date', value: (s) => s.created_at },
          { id: 'total', label: 'Total', type: 'number', value: (s) => s.total },
          { id: 'balance', label: 'Saldo por cobrar', type: 'number', value: (s) => s.balance },
          { id: 'customer', label: 'Cliente', value: (s) => s.customer?.name },
          { id: 'status', label: 'Estado', value: (s) => s.status_label },
        ]}
        columns={columns}
        data={rows}
        exportTitle={showUnbilled ? 'Atendidas sin cobrar' : 'Ventas'}
        exportScreen="sales"
        exportColumns={exportColumns}
        getItemKey={(s) => s.id}
        entityLabel={rows.length === 1 ? 'venta' : 'ventas'}
        metricCards={metricCards}
      />

      {sales && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay ventas. Una venta nace al cobrar una cita: ábrela desde la agenda y usa "Cobrar".</p>
      )}

      <SaleDetailPanel saleId={viewing} onClose={() => setViewing(null)} onChanged={load} />
      <ChargePanel appointmentId={charging?.appointment_id ?? null} appointmentCode={charging?.appointment_code ?? undefined} onClose={() => setCharging(null)} onChanged={load} />
    </div>
  );
}
