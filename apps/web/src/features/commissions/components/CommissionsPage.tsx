'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { BadgeCheck, CircleDollarSign, Hourglass, ListChecks, RefreshCw, Wallet } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { STATUS_TONES, percentText, type CommissionEntry, type CommissionsPayload } from '../commissions';

const VIEWS = [
  { id: 'all', label: 'Todas' },
  { id: 'calculated', label: 'Por aprobar' },
  { id: 'approved', label: 'Aprobadas' },
  { id: 'paid', label: 'Pagadas' },
];

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const ruleText = (e: CommissionEntry, money: (v: number) => string) =>
  [e.percent > 0 ? percentText(e.percent) : '', e.fixed > 0 ? `${money(e.fixed)} por unidad` : ''].filter(Boolean).join(' + ');

export default function CommissionsPage() {
  const vocab = useVocabulary();
  const [period, setPeriod] = useState('');
  const [data, setData] = useState<CommissionsPayload | null>(null);
  const [view, setView] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ professional_id: '' });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [recalculating, setRecalculating] = useState(false);

  const load = useCallback((wanted: string) => {
    api.get<CommissionsPayload>('/commissions', { params: wanted ? { period: wanted } : {} })
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las comisiones.')));
  }, []);

  useEffect(() => { load(period); }, [load, period]);

  const money = useCallback((v: number) => formatMoney(v, data?.currency), [data?.currency]);
  const entries = useMemo(() => data?.entries ?? [], [data]);
  const term = normalize(searchTerm.trim());
  const rows = entries.filter((e) =>
    (view === 'all' || e.status === view) &&
    (!filters.professional_id || e.professional.id === filters.professional_id) &&
    (!term || normalize(`${e.professional.name} ${e.description} ${e.sale?.code ?? ''} ${e.rule_name ?? ''}`).includes(term)),
  );
  const periodLabel = data?.periods.find((p) => p.value === data.period)?.label ?? '';
  const own = data?.scope === 'own';

  const professionalOptions = useMemo(
    () => [...new Map(entries.map((e) => [e.professional.id, e.professional.name])).entries()].map(([value, label]) => ({ value, label })),
    [entries],
  );

  const filterConfigs: FilterConfig[] = own || professionalOptions.length < 2 ? [] : [
    { id: 'professional_id', label: vocab.pro, type: 'select', options: professionalOptions },
  ];

  const s = data?.summary;
  const metricCards: MetricCardItem[] = s ? [
    { title: `Por aprobar · ${s.calculated_count}`, value: money(s.calculated), icon: <Hourglass size={18} />, iconColorClass: 'text-sky-600', onClick: () => setView(view === 'calculated' ? 'all' : 'calculated'), borderColorClass: view === 'calculated' ? 'border-sky-500 ring-2 ring-sky-500/20' : 'hover:border-slate-300' },
    { title: `Aprobadas · ${s.approved_count}`, value: money(s.approved), icon: <BadgeCheck size={18} />, iconColorClass: 'text-amber-600', onClick: () => setView(view === 'approved' ? 'all' : 'approved'), borderColorClass: view === 'approved' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300' },
    { title: `Pagadas · ${s.paid_count}`, value: money(s.paid), icon: <Wallet size={18} />, iconColorClass: 'text-emerald-600', onClick: () => setView(view === 'paid' ? 'all' : 'paid'), borderColorClass: view === 'paid' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300' },
    { title: own ? 'Total de tu mes' : 'Total del mes', value: money(s.total), icon: <CircleDollarSign size={18} />, iconColorClass: 'text-brand-accent' },
  ] : [];

  const transition = async (ids: string[], to: 'approved' | 'paid', clear: () => void) => {
    setBusy(true);
    setNotice('');
    try {
      const res = await api.post<{ message: string }>('/commissions/transition', { ids, to });
      setNotice(res.data.message);
      setError('');
      clear();
      load(data?.period ?? period);
    } catch (err) {
      setError(apiError(err, 'No se pudieron cambiar las comisiones.'));
    } finally {
      setBusy(false);
    }
  };

  const recalculate = async () => {
    if (!data) return;
    setBusy(true);
    try {
      const res = await api.post<{ message: string }>('/commissions/recalculate', { period: data.period });
      setNotice(res.data.message);
      setError('');
      load(data.period);
    } catch (err) {
      setError(apiError(err, 'No se pudo recalcular el mes.'));
    } finally {
      setBusy(false);
      setRecalculating(false);
    }
  };

  const columns: Column<CommissionEntry>[] = [
    {
      header: 'Fecha',
      cell: (e) => <span className="text-sm text-slate-700 whitespace-nowrap">{formatDate(e.earned_at.slice(0, 10), { day: 'numeric', month: 'short' })}</span>,
    },
    ...(own ? [] : [{
      header: vocab.pro,
      cell: (e: CommissionEntry) => <ProfessionalLink id={e.professional.id} className={styles.customerName}>{e.professional.name}</ProfessionalLink>,
    }]),
    {
      header: 'Concepto',
      cell: (e) => (
        <div className="min-w-0">
          <span className="block text-sm font-semibold text-slate-800">{e.description}</span>
          <span className="block text-xs text-slate-500">
            {[e.source === 'package_use' ? e.source_label : '', e.sale?.code, e.branch_name].filter(Boolean).join(' · ')}
          </span>
        </div>
      ),
    },
    { header: 'Base', cell: (e) => <span className="text-sm tabular-nums text-slate-700">{money(e.base)}</span> },
    {
      header: 'Regla',
      cell: (e) => (
        <div>
          <span className="block text-sm text-slate-700">{ruleText(e, money)}</span>
          {e.rule_name && <span className="block text-xs text-slate-500">{e.rule_name}</span>}
        </div>
      ),
    },
    { header: 'Comisión', cell: (e) => <span className={`text-sm font-bold tabular-nums ${e.amount < 0 ? 'text-rose-700' : 'text-slate-900'}`} data-amount>{money(e.amount)}</span> },
    { header: 'Estado', cell: (e) => <Badge tone={STATUS_TONES[e.status]} bold nowrap data-status={e.status}>{e.status_label}</Badge> },
  ];

  const exportColumns: ExportColumn<CommissionEntry>[] = [
    { header: 'Fecha', type: 'date', value: (e) => e.earned_at },
    { header: vocab.pro, value: (e) => e.professional.name },
    { header: 'Concepto', value: (e) => e.description },
    { header: 'Origen', value: (e) => e.source_label },
    { header: 'Venta', value: (e) => e.sale?.code ?? '' },
    { header: 'Sede', value: (e) => e.branch_name ?? '' },
    { header: 'Base', type: 'number', value: (e) => e.base },
    { header: 'Porcentaje', type: 'number', value: (e) => e.percent },
    { header: 'Monto fijo', type: 'number', value: (e) => e.fixed },
    { header: 'Regla', value: (e) => e.rule_name ?? '' },
    { header: 'Comisión', type: 'number', total: true, value: (e) => e.amount },
    { header: 'Estado', value: (e) => e.status_label },
  ];

  const headerActions = data && (
    <div className="flex flex-wrap items-center gap-2">
      <div className="w-44">
        <Select ariaLabel="Mes" clearable={false} value={data.period} onChange={(e) => setPeriod(String(e.target.value))} options={data.periods} />
      </div>
      <Link href="/commissions/rules" data-link="rules" className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-brand-ink hover:bg-slate-50">
        <ListChecks size={16} /> Reglas
      </Link>
      {data.can.rules && (
        <button type="button" data-recalculate onClick={() => setRecalculating(true)} className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-brand-ink hover:bg-slate-50">
          <RefreshCw size={16} /> Recalcular el mes
        </button>
      )}
    </div>
  );

  const selectionActions = (selected: CommissionEntry[], clear: () => void) => {
    const toApprove = selected.filter((e) => e.status === 'calculated').map((e) => e.id);
    const toPay = selected.filter((e) => e.status === 'approved').map((e) => e.id);
    return (
      <>
        {data?.can.approve && toApprove.length > 0 && (
          <button type="button" data-approve disabled={busy} onClick={() => transition(toApprove, 'approved', clear)} className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-bold text-brand-ink hover:bg-slate-50 disabled:opacity-50">
            Aprobar {toApprove.length}
          </button>
        )}
        {data?.can.pay && toPay.length > 0 && (
          <button type="button" data-pay disabled={busy} onClick={() => transition(toPay, 'paid', clear)} className="px-3 py-1.5 rounded-lg bg-brand text-white text-xs font-bold hover:bg-brand-hover disabled:opacity-50">
            Marcar {toPay.length === 1 ? '1 como pagada' : `${toPay.length} como pagadas`}
          </button>
        )}
        {(data?.can.approve || data?.can.pay) && toApprove.length === 0 && toPay.length === 0 && (
          <span className="text-xs text-slate-500">Las elegidas ya están pagadas.</span>
        )}
      </>
    );
  };

  return (
    <div className="space-y-6 mb-8" data-commissions>
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {data && (data.can.approve || data.can.pay) && entries.length > 0 && (
        <p className="text-xs text-slate-500 px-1">Marca las comisiones en la lista para aprobarlas o, ya aprobadas, marcarlas como pagadas. El pago se hace por fuera del sistema: aquí solo queda anotado.</p>
      )}

      <DataTable<CommissionEntry>
        title={own ? 'Mis comisiones' : 'Comisiones'}
        headerActions={headerActions}
        tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'all' ? entries.length : entries.filter((e) => e.status === v.id).length }))}
        activeTab={view}
        onTabChange={setView}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((values) => ({ ...values, [id]: value }))}
        sortOptions={[
          { id: 'earned_at', label: 'Fecha', type: 'date', value: (e) => e.earned_at },
          { id: 'amount', label: 'Comisión', type: 'number', value: (e) => e.amount },
          { id: 'professional', label: vocab.pro, value: (e) => e.professional.name },
        ]}
        columns={columns}
        data={rows}
        exportTitle={`Comisiones ${periodLabel}`.trim()}
        exportScreen="commissions"
        exportColumns={exportColumns}
        getItemKey={(e) => e.id}
        entityLabel={rows.length === 1 ? 'comisión' : 'comisiones'}
        metricCards={metricCards}
        selectionActions={data && (data.can.approve || data.can.pay) ? selectionActions : undefined}
      />

      {data && entries.length === 0 && (
        <p className="text-sm text-slate-500 px-1" data-empty>
          No hay comisiones en {periodLabel.toLowerCase() || 'este mes'}. Se calculan solas al cobrar una venta con {vocab.pro.toLowerCase()} asignado o al usar una sesión de un paquete.
          {data.can.rules ? ' Revisa que haya reglas o un porcentaje para cada uno en Reglas.' : ''}
        </p>
      )}

      {data && !own && data.by_professional.length > 0 && (
        <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden" data-section="by-professional">
          <div className="px-6 pt-5 pb-3">
            <h2 className="text-sm font-extrabold text-brand-ink uppercase tracking-wider">Resumen por {vocab.pro.toLowerCase()}</h2>
            <p className="text-xs text-slate-500 font-medium mt-1">Lo de {periodLabel.toLowerCase()}. Las devoluciones ya están restadas.</p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-y border-slate-100 bg-slate-50">
                  <th className="px-6 py-2.5">{vocab.pro}</th>
                  <th className="px-4 py-2.5 text-right">Por aprobar</th>
                  <th className="px-4 py-2.5 text-right">Aprobada</th>
                  <th className="px-4 py-2.5 text-right">Pagada</th>
                  <th className="px-4 py-2.5 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.by_professional.map((p) => (
                  <tr key={p.id} data-pro-row={p.id}>
                    <td className="px-6 py-3 font-semibold text-slate-800">
                      <button type="button" onClick={() => setFilters({ professional_id: filters.professional_id === p.id ? '' : p.id })} className="hover:text-brand-accent hover:underline text-left" title="Ver solo sus comisiones">{p.name}</button>
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-700">{money(p.calculated)}</td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-700">{money(p.approved)}</td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-700">{money(p.paid)}</td>
                    <td className="px-4 py-3 text-right tabular-nums font-bold text-slate-900">{money(p.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <AppModal
        isOpen={recalculating}
        type="confirm"
        title={`¿Recalcular ${periodLabel.toLowerCase()}?`}
        message="Se vuelven a calcular, con las reglas de hoy, las comisiones del mes que todavía están por aprobar. Las aprobadas y las pagadas no cambian."
        confirmText="Sí, recalcular"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={recalculate}
        onClose={() => setRecalculating(false)}
      />
    </div>
  );
}
