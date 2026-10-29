'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  Eye,
  FileText,
  Settings,
  Wallet,
  XCircle,
} from 'lucide-react';
import {
  DataTable,
  type Column,
  type MetricCardItem,
} from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import ChargePanel from '@/features/sales/components/ChargePanel';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { useAuth } from '@/hooks/useAuth';
import { QUOTE_STATUS_BADGES, type QuoteRow, type QuotesBoardData } from '../quotes';
import QuoteFormDrawer from './QuoteFormDrawer';
import QuoteDetailDrawer from './QuoteDetailDrawer';
import QuoteCancelDrawer from './QuoteCancelDrawer';

type QuotesFilter = 'all' | 'pending' | 'accepted' | 'in_progress' | 'overdue' | 'closed';

const isPending = (q: QuoteRow) => q.status === 'draft' || q.status === 'sent';
const isClosed = (q: QuoteRow) => ['rejected', 'expired', 'cancelled'].includes(q.status);
const inProgress = (q: QuoteRow) => q.status === 'accepted' && q.sessions_total > 0 && q.sessions_used < q.sessions_total;
const hasOverdue = (q: QuoteRow) => q.overdue_installments > 0;

export default function QuotesBoard() {
  const { can } = useAuth();
  const [data, setData] = useState<QuotesBoardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [quotesFilter, setQuotesFilter] = useState<QuotesFilter>('all');
  const [quotesSearch, setQuotesSearch] = useState('');
  const [quoteFilters, setQuoteFilters] = useState<Record<string, string>>({ status: '', professional_id: '' });

  const [formOpen, setFormOpen] = useState(false);
  const [editingQuote, setEditingQuote] = useState<QuoteRow | null>(null);
  const [selectedQuoteId, setSelectedQuoteId] = useState<string | null>(null);
  const [cancelling, setCancelling] = useState<QuoteRow | null>(null);
  const [charging, setCharging] = useState<{ saleId: string; amount?: number } | null>(null);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError('');
      const res = await api.get<QuotesBoardData>('/quotes');
      setData(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudieron cargar los presupuestos.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const handleSaved = (next: QuotesBoardData, message: string) => {
    setData(next);
    setNotice(message);
    setError('');
  };

  const currency = data?.currency ?? 'PEN';
  const quotes = useMemo(() => data?.quotes ?? [], [data?.quotes]);

  const selectedQuote = useMemo(
    () => (selectedQuoteId ? quotes.find((q) => q.id === selectedQuoteId) ?? null : null),
    [quotes, selectedQuoteId],
  );

  const stats = useMemo(() => {
    const pending = quotes.filter(isPending);
    const accepted = quotes.filter((q) => q.status === 'accepted');
    return {
      total: quotes.length,
      pending: pending.length,
      pendingTotal: pending.reduce((sum, q) => sum + q.total, 0),
      accepted: accepted.length,
      acceptedTotal: accepted.reduce((sum, q) => sum + q.total, 0),
      inProgress: quotes.filter(inProgress).length,
      overdue: quotes.filter(hasOverdue).length,
      closed: quotes.filter(isClosed).length,
    };
  }, [quotes]);

  const filteredQuotes = useMemo(() => {
    const term = quotesSearch.trim().toLowerCase();
    return quotes.filter((row) => {
      if (quotesFilter === 'pending' && !isPending(row)) return false;
      if (quotesFilter === 'accepted' && row.status !== 'accepted') return false;
      if (quotesFilter === 'in_progress' && !inProgress(row)) return false;
      if (quotesFilter === 'overdue' && !hasOverdue(row)) return false;
      if (quotesFilter === 'closed' && !isClosed(row)) return false;
      if (quoteFilters.status && row.status !== quoteFilters.status) return false;
      if (quoteFilters.professional_id && row.professional_id !== quoteFilters.professional_id) return false;
      if (!term) return true;
      return [row.code, row.title, row.customer_name, row.customer_document, row.professional_name, row.plan?.code].some((v) =>
        v?.toLowerCase().includes(term),
      );
    });
  }, [quotes, quotesFilter, quoteFilters, quotesSearch]);

  const toggle = (filter: QuotesFilter) => setQuotesFilter(quotesFilter === filter ? 'all' : filter);

  const columns: Column<QuoteRow>[] = [
    {
      header: 'Presupuesto',
      cell: (row) => (
        <div className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink">
            <FileText size={16} />
          </span>
          <div>
            <button
              type="button"
              data-quote={row.code}
              onClick={() => setSelectedQuoteId(row.id)}
              className={`${styles.customerName} hover:text-brand-accent transition-colors text-left`}
            >
              <span className="font-mono">{row.code}</span> · {row.title || 'Plan de tratamiento'}
            </button>
            <span className="block text-xs text-slate-400">
              {row.items_count} {row.items_count === 1 ? 'línea' : 'líneas'}
              {row.professional_name ? ` · ${row.professional_name}` : ''}
              {row.branch_name ? ` · ${row.branch_name}` : ''}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Cliente',
      cell: (row) => (
        <div>
          <span className="block font-bold text-slate-800">{row.customer_name}</span>
          <span className="block text-xs text-slate-500">
            {[row.customer_document, row.customer_phone].filter(Boolean).join(' · ') || '—'}
          </span>
        </div>
      ),
    },
    {
      header: 'Avance de sesiones',
      cell: (row) => {
        if (row.sessions_total <= 0) {
          return <span className="text-xs text-slate-400">Sin sesiones</span>;
        }
        return (
          <div className="w-36 space-y-1">
            <div className="flex items-center justify-between text-xs">
              <span className="font-bold text-slate-700">{row.sessions_used}/{row.sessions_total} ses.</span>
              <span className="font-bold text-brand-accent">{row.progress_percent}%</span>
            </div>
            <div className="w-full h-1.5 rounded-full bg-slate-200 overflow-hidden">
              <div className="h-full bg-brand-accent" style={{ width: `${row.progress_percent}%` }} />
            </div>
            {row.plan && <span className="block text-[11px] text-slate-500">{row.plan.code} · {row.plan.status_label}</span>}
          </div>
        );
      },
    },
    {
      header: 'Total y cobro',
      cell: (row) => (
        <div>
          <span className="block text-sm font-black text-brand-ink tabular-nums">{money(row.total, currency)}</span>
          {row.sale_id ? (
            <span className="block text-xs text-slate-500">
              Pagado: <strong className="text-emerald-700">{money(row.sale_paid, currency)}</strong>
              {row.sale_balance > 0 ? ` · Saldo: ${money(row.sale_balance, currency)}` : ''}
            </span>
          ) : row.installments_count > 0 ? (
            <span className="block text-xs text-slate-500">{row.installments_count} cuotas programadas</span>
          ) : null}
          {row.overdue_installments > 0 && (
            <span className="block text-xs font-semibold text-rose-600">
              {row.overdue_installments === 1 ? '1 cuota vencida' : `${row.overdue_installments} cuotas vencidas`}
            </span>
          )}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (row) => (
        <div className="space-y-1">
          <Badge tone={QUOTE_STATUS_BADGES[row.status]} bold>{row.status_label}</Badge>
          {row.valid_until && isPending(row) && <span className="block text-[11px] text-slate-500">Vence {row.valid_until}</span>}
        </div>
      ),
    },
    {
      header: 'Acciones',
      className: 'text-right',
      cell: (row) => (
        <div className="flex items-center justify-end gap-1.5">
          <button
            type="button"
            onClick={() => setSelectedQuoteId(row.id)}
            className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover transition-colors"
          >
            <Eye size={13} /> Ver
          </button>
          {data?.can.charge && row.sale_id && row.sale_balance > 0 && (
            <button
              type="button"
              onClick={() => setCharging({ saleId: row.sale_id! })}
              className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors"
            >
              <Wallet size={13} /> Cobrar
            </button>
          )}
        </div>
      ),
    },
  ];

  const cards: MetricCardItem[] = [
    {
      title: 'Por aprobar',
      value: `${stats.pending} (${money(stats.pendingTotal, currency)})`,
      icon: <Clock size={18} />,
      iconColorClass: 'text-amber-600',
      borderColorClass: quotesFilter === 'pending' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300',
      onClick: () => toggle('pending'),
    },
    {
      title: 'Aprobados',
      value: `${stats.accepted} (${money(stats.acceptedTotal, currency)})`,
      icon: <CheckCircle2 size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: quotesFilter === 'accepted' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => toggle('accepted'),
    },
    ...(data?.rules.allow_installments || stats.overdue > 0 ? [{
      title: 'Con cuotas vencidas',
      value: stats.overdue,
      icon: <AlertTriangle size={18} />,
      iconColorClass: 'text-rose-600',
      borderColorClass: quotesFilter === 'overdue' ? 'border-rose-500 ring-2 ring-rose-500/20' : 'hover:border-slate-300',
      onClick: () => toggle('overdue'),
    }] : []),
    {
      title: 'Rechazados, vencidos o anulados',
      value: stats.closed,
      icon: <XCircle size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: quotesFilter === 'closed' ? 'border-slate-500 ring-2 ring-slate-500/20' : 'hover:border-slate-300',
      onClick: () => toggle('closed'),
    },
  ];

  if (loading && !data) {
    return <p className="text-sm text-slate-500 p-6">Cargando presupuestos...</p>;
  }

  return (
    <div className="space-y-4">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {can('settings.view') && (
        <div className="flex justify-end">
          <Link
            href="/settings#presupuestos"
            className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 transition-colors"
          >
            <Settings size={14} /> Reglas de presupuestos
          </Link>
        </div>
      )}

      <DataTable<QuoteRow>
        title="Presupuestos"
        primaryActionLabel="Nuevo presupuesto"
        onPrimaryAction={data?.can.create ? () => { setEditingQuote(null); setFormOpen(true); } : undefined}
        tabs={[
          { id: 'all', label: 'Todos', count: stats.total },
          { id: 'pending', label: 'Por aprobar', count: stats.pending },
          { id: 'accepted', label: 'Aprobados', count: stats.accepted },
          { id: 'in_progress', label: 'En tratamiento', count: stats.inProgress },
          { id: 'closed', label: 'Cerrados', count: stats.closed },
        ]}
        activeTab={quotesFilter === 'overdue' ? 'all' : quotesFilter}
        onTabChange={(id) => setQuotesFilter(id as QuotesFilter)}
        searchTerm={quotesSearch}
        onSearchChange={setQuotesSearch}
        filterConfigs={[
          {
            id: 'status',
            label: 'Estado',
            type: 'select',
            options: [
              { value: 'draft', label: 'Borrador' },
              { value: 'sent', label: 'Enviado' },
              { value: 'accepted', label: 'Aprobado' },
              { value: 'rejected', label: 'Rechazado' },
              { value: 'expired', label: 'Vencido' },
              { value: 'cancelled', label: 'Anulado' },
            ],
          },
          ...((data?.professionals.length ?? 0) > 0
            ? [{
                id: 'professional_id',
                label: 'Profesional',
                type: 'select' as const,
                options: (data?.professionals ?? []).map((p) => ({ value: p.id, label: p.display_name })),
              }]
            : []),
        ]}
        filterValues={quoteFilters}
        onFilterChange={(id, value) => setQuoteFilters((curr) => ({ ...curr, [id]: value }))}
        dateFilters={[{ id: 'created_at', label: 'Fecha de creación', value: (q) => q.created_at }]}
        sortOptions={[
          { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (q) => q.created_at },
          { id: 'total', label: 'Monto total', type: 'number', value: (q) => q.total },
          { id: 'progress', label: 'Avance de sesiones', type: 'number', value: (q) => q.progress_percent },
          { id: 'customer', label: 'Cliente', value: (q) => q.customer_name },
        ]}
        columns={columns}
        data={filteredQuotes}
        exportTitle="Presupuestos"
        exportScreen="quotes_list"
        exportColumns={[
          { header: 'Código', value: (q) => q.code },
          { header: 'Título', value: (q) => q.title },
          { header: 'Cliente', value: (q) => q.customer_name },
          { header: 'Profesional', value: (q) => q.professional_name },
          { header: 'Estado', value: (q) => q.status_label },
          { header: 'Plan de tratamiento', value: (q) => q.plan?.code ?? null },
          { header: 'Sesiones realizadas', type: 'number', value: (q) => q.sessions_used },
          { header: 'Sesiones totales', type: 'number', value: (q) => q.sessions_total },
          { header: 'Total', type: 'money', total: true, value: (q) => q.total },
          { header: 'Pagado', type: 'money', total: true, value: (q) => q.sale_paid },
          { header: 'Cuotas vencidas', type: 'number', value: (q) => q.overdue_installments },
        ]}
        getItemKey={(q) => q.id}
        entityLabel={filteredQuotes.length === 1 ? 'presupuesto' : 'presupuestos'}
        metricCards={cards}
      />

      {data && (
        <QuoteFormDrawer
          isOpen={formOpen}
          onClose={() => { setFormOpen(false); setEditingQuote(null); }}
          board={data}
          editingQuote={editingQuote}
          onSaved={handleSaved}
        />
      )}

      <QuoteDetailDrawer
        quote={selectedQuote}
        currency={currency}
        can={data?.can ?? { create: false, edit: false, delete: false, charge: false }}
        onClose={() => setSelectedQuoteId(null)}
        onEdit={(q) => { setSelectedQuoteId(null); setEditingQuote(q); setFormOpen(true); }}
        onOpenCharge={(saleId, amount) => { setSelectedQuoteId(null); setCharging({ saleId, amount }); }}
        onCancelAccepted={(q) => { setSelectedQuoteId(null); setCancelling(q); }}
        onUpdated={handleSaved}
      />

      <QuoteCancelDrawer
        quote={cancelling}
        onClose={() => setCancelling(null)}
        onDone={(next, message) => { handleSaved(next, message); setCancelling(null); }}
      />

      <ChargePanel
        appointmentId={null}
        saleId={charging?.saleId ?? null}
        initialAmount={charging?.amount}
        onClose={() => setCharging(null)}
        onChanged={() => load()}
      />
    </div>
  );
}
