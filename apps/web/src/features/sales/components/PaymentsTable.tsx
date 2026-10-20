'use client';

import { ErrorAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import { Banknote, Coins, CreditCard, Landmark, RotateCcw, Smartphone, Wallet } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { dayOf, toISO } from '@/app/components/ui/table/dateFilters';
import { currentCurrency } from '@/lib/currency';
import { money, momentOf, type PaymentRow } from '../sales';
import SaleDetailPanel from './SaleDetailPanel';

const VIEWS = [
  { id: 'all', label: 'Todos' },
  { id: 'payment', label: 'Cobros' },
  { id: 'refund', label: 'Devoluciones' },
];

const METHOD_ICONS: Record<string, React.ReactNode> = {
  cash: <Banknote size={16} />,
  card: <CreditCard size={16} />,
  transfer: <Landmark size={16} />,
  yape: <Smartphone size={16} />,
  plin: <Smartphone size={16} />,
};

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default function PaymentsTable() {
  const [payments, setPayments] = useState<PaymentRow[] | null>(null);
  const [methods, setMethods] = useState<{ value: string; label: string }[]>([]);
  const [view, setView] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ method: '', branch_id: '' });
  const [viewing, setViewing] = useState<string | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get('/payments')
      .then((res) => { setPayments(res.data.payments); setMethods(res.data.methods); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los pagos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const all = payments ?? [];
  const term = normalize(searchTerm.trim());

  const rows = all.filter((payment) =>
    (view === 'all' || payment.kind === view) &&
    (!filters.method || payment.method === filters.method) &&
    (!filters.branch_id || payment.branch_id === filters.branch_id) &&
    (!term || normalize(`${payment.sale_code ?? ''} ${payment.customer?.name ?? ''} ${payment.reference ?? ''} ${payment.method_label} ${payment.by ?? ''}`).includes(term)),
  );

  const today = toISO(new Date());
  const current = currentCurrency();
  const todays = all.filter((p) => dayOf(p.at) === today && (!p.currency || p.currency === current));
  const sum = (list: PaymentRow[]) => list.reduce((total, p) => total + p.amount, 0);

  const used = new Set(all.map((p) => p.method));
  const branchOptions = [...new Map(all.filter((p) => p.branch_id).map((p) => [p.branch_id as string, p.branch_name ?? 'Sede'])).entries()].map(([value, label]) => ({ value, label }));

  const filterConfigs: FilterConfig[] = [
    { id: 'method', label: 'Medio', type: 'select', options: methods.filter((m) => used.has(m.value)) },
    ...(branchOptions.length > 1 ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: branchOptions }] : []),
  ];

  const metricCards: MetricCardItem[] = [
    {
      title: 'Cobrado hoy',
      value: money(sum(todays.filter((p) => p.kind === 'payment'))),
      icon: <Coins size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: view === 'payment' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'payment' ? 'all' : 'payment'),
    },
    {
      title: 'Devuelto hoy',
      value: money(Math.abs(sum(todays.filter((p) => p.kind === 'refund')))),
      icon: <RotateCcw size={18} />,
      iconColorClass: 'text-rose-600',
      borderColorClass: view === 'refund' ? 'border-rose-500 ring-2 ring-rose-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'refund' ? 'all' : 'refund'),
    },
    {
      title: 'Neto de hoy',
      value: money(sum(todays)),
      icon: <Wallet size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: view === 'all' ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
      onClick: () => setView('all'),
    },
  ];

  const columns: Column<PaymentRow>[] = [
    {
      header: 'Fecha',
      cell: (payment) => (
        <div key={`at-${payment.id}`}>
          <span className="block text-sm text-slate-700">{momentOf(payment.at)}</span>
          <span className="block text-xs text-slate-400">{payment.by ?? '—'}</span>
        </div>
      ),
    },
    {
      header: 'Medio',
      cell: (payment) => (
        <div key={`method-${payment.id}`} className="flex items-center gap-2.5">
          <span className={`p-2 rounded-lg shrink-0 ${payment.kind === 'refund' ? 'bg-rose-50 text-rose-600' : 'bg-[#84D0D9]/20 text-[#022340]'}`}>
            {payment.kind === 'refund' ? <RotateCcw size={16} /> : METHOD_ICONS[payment.method] ?? <Wallet size={16} />}
          </span>
          <div>
            <span className="block text-sm font-semibold text-slate-800">{payment.method_label}</span>
            <span className="block text-xs text-slate-400">{payment.kind === 'refund' ? 'Devolución' : payment.reference ?? (payment.register ?? 'Cobro')}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Venta',
      cell: (payment) => (payment.sale_id ? (
        <button key={`sale-${payment.id}`} type="button" data-view={payment.sale_code} onClick={() => setViewing(payment.sale_id)} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left tabular-nums`}>
          {payment.sale_code}
        </button>
      ) : <span key={`sale-${payment.id}`} className="text-sm text-slate-400">—</span>),
    },
    {
      header: 'Cliente',
      cell: (payment) => (
        <div key={`customer-${payment.id}`}>
          {payment.customer
            ? <CustomerLink id={payment.customer.id} className="text-sm font-semibold text-slate-800">{payment.customer.name}</CustomerLink>
            : <span className="text-sm text-slate-400">Sin cliente</span>}
          <span className="block text-xs text-slate-400">{[payment.branch_name, payment.register].filter(Boolean).join(' · ') || '—'}</span>
        </div>
      ),
    },
    {
      header: 'Monto',
      cell: (payment) => (
        <span key={`amount-${payment.id}`} data-kind={payment.kind} className={`text-sm font-bold tabular-nums ${payment.kind === 'refund' ? 'text-rose-700' : 'text-slate-800'}`}>
          {payment.kind === 'refund' ? `− ${money(Math.abs(payment.amount), payment.currency)}` : money(payment.amount, payment.currency)}
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />

      <DataTable<PaymentRow>
        title="Pagos"
        tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'all' ? all.length : all.filter((p) => p.kind === v.id).length }))}
        activeTab={view}
        onTabChange={setView}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((values) => ({ ...values, [id]: value }))}
        dateFilters={[{ id: 'at', label: 'Fecha', value: (p) => p.at }]}
        sortOptions={[
          { id: 'at', label: 'Fecha', type: 'date', value: (p) => p.at },
          { id: 'amount', label: 'Monto', type: 'number', value: (p) => p.amount },
          { id: 'method', label: 'Medio', value: (p) => p.method_label },
          { id: 'customer', label: 'Cliente', value: (p) => p.customer?.name },
        ]}
        columns={columns}
        data={rows}
        getItemKey={(p) => p.id}
        entityLabel={rows.length === 1 ? 'movimiento' : 'movimientos'}
        metricCards={metricCards}
      />

      {payments && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay pagos. Aparecen aquí al cobrar una cita.</p>
      )}

      <SaleDetailPanel saleId={viewing} onClose={() => setViewing(null)} onChanged={load} />
    </div>
  );
}
