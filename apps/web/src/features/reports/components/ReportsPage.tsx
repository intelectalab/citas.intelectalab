'use client';

import { useEffect, useMemo, useState } from 'react';
import { BadgePercent, CalendarCheck, CalendarX2, Clock, Globe, Lock, Receipt, RotateCcw, Sparkles, TrendingUp, UserCheck, UserPlus, Users, Wallet } from 'lucide-react';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { ErrorAlert } from '@/app/components/common/Alert';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import ExportMenu from '@/app/components/ui/table/ExportMenu';
import type { ExportColumn } from '@/app/components/ui/table/DataTable';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { addDays, apiError, formatDate, formatMinutes, todayISO } from '@/features/appointments/appointments';
import { WEEKDAYS, delta, type AppointmentsSummary, type CatalogRow, type CustomersSummary, type MineSummary, type MyCustomer, type ProRow, type ReportPayload, type ReportTab, type SalesSummary, type Serie, type TopCustomer } from '../reports';

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

interface KpiProps {
  id: string;
  title: string;
  value: string;
  note?: string;
  change?: number | null;
  lowerIsBetter?: boolean;
  icon: React.ReactNode;
  color: string;
}

function Kpi({ id, title, value, note, change, lowerIsBetter = false, icon, color }: KpiProps) {
  const good = change !== null && change !== undefined && (lowerIsBetter ? change < 0 : change > 0);
  const bad = change !== null && change !== undefined && (lowerIsBetter ? change > 0 : change < 0);

  return (
    <div data-kpi={id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{title}</span>
          <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900 truncate">{value}</div>
          {change !== undefined && (
            <span data-change={change ?? ''} className={`block text-xs font-semibold mt-0.5 ${good ? 'text-emerald-700' : bad ? 'text-rose-700' : 'text-slate-400'}`}>
              {change === null ? 'Sin comparación' : change === 0 ? 'Igual que el periodo anterior' : `${change > 0 ? '▲' : '▼'} ${Math.abs(change)} % frente al anterior`}
            </span>
          )}
          {note && <span className="block text-xs text-slate-500 mt-0.5">{note}</span>}
        </div>
        <div className={`p-2 rounded-xl bg-slate-50 border border-slate-100 ${color}`}>{icon}</div>
      </div>
    </div>
  );
}

function Section({ title, hint, children, actions, dataKey }: { title: string; hint?: string; children: React.ReactNode; actions?: React.ReactNode; dataKey: string }) {
  return (
    <section className={`${cardClass} overflow-hidden`} data-section={dataKey}>
      <div className="px-6 pt-5 pb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-sm font-extrabold text-brand-ink uppercase tracking-wider">{title}</h2>
          {hint && <p className="text-xs text-slate-500 font-medium mt-1">{hint}</p>}
        </div>
        {actions}
      </div>
      {children}
    </section>
  );
}

function DayBars({ points, format, empty }: { points: { day: string; value: number | null }[]; format: (v: number) => string; empty: string }) {
  const max = Math.max(0, ...points.map((p) => p.value ?? 0));
  if (max <= 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;
  const labelEvery = Math.max(1, Math.ceil(points.length / 10));

  return (
    <div className="px-6 pb-5">
      <div className="flex items-end gap-[2px] h-40" data-chart="days">
        {points.map((p) => (
          <div key={p.day} className="flex-1 h-full flex items-end group relative" title={`${formatDate(p.day, { weekday: 'short', day: 'numeric', month: 'short' })}: ${p.value === null ? 'sin horario' : format(p.value)}`}>
            <div className={`w-full rounded-t ${p.value === null ? 'bg-slate-100' : 'bg-[var(--accent)] group-hover:opacity-80'}`} style={{ height: `${p.value === null ? 4 : Math.max(2, (p.value / max) * 100)}%` }} />
          </div>
        ))}
      </div>
      <div className="flex gap-[2px] mt-1">
        {points.map((p, i) => (
          <span key={p.day} className="flex-1 text-[10px] text-slate-400 text-center truncate">{i % labelEvery === 0 ? formatDate(p.day, { day: 'numeric', month: 'short' }) : ''}</span>
        ))}
      </div>
    </div>
  );
}

function ListBars({ items, format, empty }: { items: Serie[]; format: (v: number) => string; empty: string }) {
  const max = Math.max(0, ...items.map((i) => Math.abs(i.value)));
  if (items.length === 0 || max <= 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;

  return (
    <ul className="px-6 pb-5 space-y-2.5" data-chart="list">
      {items.map((item) => (
        <li key={item.key ?? item.label}>
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="font-semibold text-slate-700 truncate">{item.label}</span>
            <span className="tabular-nums text-slate-800 font-semibold">{format(item.value)}</span>
          </div>
          <span className="mt-1 block h-1.5 rounded-full bg-slate-100 overflow-hidden">
            <span className="block h-full rounded-full bg-[var(--accent)]" style={{ width: `${Math.max(3, (Math.abs(item.value) / max) * 100)}%` }} />
          </span>
        </li>
      ))}
    </ul>
  );
}

function Heatmap({ cells }: { cells: { weekday: number; hour: number; value: number }[] }) {
  if (cells.length === 0) return <p className="px-6 pb-6 text-sm text-slate-400">Aún no hay citas en este periodo.</p>;
  const hours = cells.map((c) => c.hour);
  const from = Math.min(...hours);
  const to = Math.max(...hours);
  const max = Math.max(...cells.map((c) => c.value));
  const value = (weekday: number, hour: number) => cells.find((c) => c.weekday === weekday && c.hour === hour)?.value ?? 0;
  const range = Array.from({ length: to - from + 1 }, (_, i) => from + i);

  return (
    <div className="px-6 pb-5 overflow-x-auto" data-chart="heatmap">
      <table className="text-[11px]">
        <thead>
          <tr>
            <th />
            {range.map((h) => <th key={h} className="px-0.5 pb-1 font-semibold text-slate-400 text-center w-9">{h}h</th>)}
          </tr>
        </thead>
        <tbody>
          {WEEKDAYS.map((day, index) => (
            <tr key={day}>
              <th className="pr-2 text-right font-semibold text-slate-500">{day}</th>
              {range.map((h) => {
                const v = value(index + 1, h);
                return (
                  <td key={h} className="p-0.5">
                    <div
                      title={`${day} ${h}:00 — ${v} ${v === 1 ? 'cita' : 'citas'}`}
                      className="h-7 w-9 rounded-md flex items-center justify-center font-semibold"
                      style={{ background: v ? `color-mix(in srgb, var(--accent) ${Math.round(15 + (v / max) * 85)}%, white)` : '#f8fafc', color: v / max > 0.55 ? '#fff' : '#475569' }}
                    >
                      {v || ''}
                    </div>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Table<T>({ columns, rows, empty, rowKey }: { columns: { header: string; align?: 'right'; cell: (row: T) => React.ReactNode }[]; rows: T[]; empty: string; rowKey: (row: T) => string }) {
  if (rows.length === 0) return <p className="px-6 pb-6 text-sm text-slate-400">{empty}</p>;

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-y border-slate-100 bg-slate-50">
            {columns.map((c, i) => <th key={c.header} className={`${i === 0 ? 'px-6' : 'px-4'} py-2.5 ${c.align === 'right' ? 'text-right' : ''}`}>{c.header}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((c, i) => <td key={c.header} className={`${i === 0 ? 'px-6 font-semibold text-slate-800' : 'px-4 text-slate-700'} py-3 ${c.align === 'right' ? 'text-right tabular-nums' : ''}`}>{c.cell(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function ReportsPage() {
  const { can } = useAuth();
  const vocab = useVocabulary();
  const [tab, setTab] = useState<ReportTab>('sales');
  const [period, setPeriod] = useState('30');
  const [from, setFrom] = useState(addDays(todayISO(), -29));
  const [to, setTo] = useState(todayISO());
  const [branchId, setBranchId] = useState('');
  const [data, setData] = useState<ReportPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (period === 'custom' && (!from || !to)) return;
    let active = true;
    setLoading(true);
    api.get<ReportPayload>('/reports', { params: { tab, period, ...(period === 'custom' ? { from, to } : {}), ...(branchId ? { branch_id: branchId } : {}) } })
      .then((res) => { if (active) { setData(res.data); setError(''); } })
      .catch((err) => active && setError(apiError(err, 'No se pudo cargar el reporte.')))
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [tab, period, from, to, branchId]);

  useEffect(() => {
    if (data && data.tab !== tab) setTab(data.tab);
  }, [data, tab]);

  const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(v, data?.currency));
  const percent = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v} %`);
  const rangeText = data ? `Del ${formatDate(data.range.from, { day: 'numeric', month: 'long' })} al ${formatDate(data.range.to, { day: 'numeric', month: 'long', year: 'numeric' })}` : '';
  const prevText = data ? `del ${formatDate(data.range.prev_from, { day: 'numeric', month: 'short' })} al ${formatDate(data.range.prev_to, { day: 'numeric', month: 'short' })}` : '';
  const filters = useMemo(() => [rangeText, data?.branch_id ? `Sede: ${data.branches.find((b) => b.id === data.branch_id)?.name ?? ''}` : 'Todas las sedes'].filter(Boolean), [rangeText, data]);
  const canExport = can('data.export');
  const content = data && data.tab === tab ? data.data : null;
  const previous = content?.previous ?? null;
  const change = (key: string) => (previous ? delta((content?.summary as unknown as Record<string, number | null>)?.[key], (previous as unknown as Record<string, number | null>)[key]) : undefined);

  const exportMenu = <T,>(title: string, columns: ExportColumn<T>[], rows: T[]) => canExport && rows.length > 0 ? (
    <ExportMenu title={title} screen="reports" columns={columns} filtered={rows} selected={[]} filters={filters} />
  ) : null;

  const renderSales = () => {
    const s = content?.summary as SalesSummary;
    const d = content?.detail;
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4">
          <Kpi id="income" title="Ingresos" value={money(s.income)} note="Cobros menos devoluciones" change={change('income')} icon={<Wallet size={18} />} color="text-emerald-600" />
          <Kpi id="sales" title="Ventas" value={String(s.sales)} change={change('sales')} icon={<Receipt size={18} />} color="text-slate-500" />
          <Kpi id="ticket" title="Ticket promedio" value={money(s.ticket)} change={change('ticket')} icon={<TrendingUp size={18} />} color="text-sky-600" />
          <Kpi id="discount" title="Descuentos" value={money(s.discount)} change={change('discount')} lowerIsBetter icon={<BadgePercent size={18} />} color="text-violet-600" />
          <Kpi id="tips" title="Propinas" value={money(s.tips)} change={change('tips')} icon={<Sparkles size={18} />} color="text-amber-600" />
          <Kpi id="refunds" title="Devoluciones" value={money(s.refunds)} change={change('refunds')} lowerIsBetter icon={<RotateCcw size={18} />} color="text-rose-700" />
        </div>
        {!data?.money ? (
          <p className="text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">No tienes permiso para ver los montos. Pídeselo al administrador (Usuarios y roles → Reportes → Ver montos).</p>
        ) : d && (
          <>
            <Section dataKey="income-by-day" title="Ingresos por día" hint="Lo que entró cada día por cobros, ya restadas las devoluciones.">
              <DayBars points={d.by_day ?? []} format={(v) => money(v)} empty="Aún no hay cobros en este periodo." />
            </Section>
            <div className="grid gap-4 lg:grid-cols-3">
              <Section dataKey="by-method" title="Por medio de pago" actions={exportMenu('Ingresos por medio de pago', [{ header: 'Medio', value: (r: Serie) => r.label }, { header: 'Cobros', type: 'number', value: (r: Serie) => r.count ?? 0 }, { header: 'Monto', type: 'number', value: (r: Serie) => r.value }], d.by_method ?? [])}>
                <ListBars items={d.by_method ?? []} format={(v) => money(v)} empty="Sin cobros." />
              </Section>
              <Section dataKey="by-type" title="Por tipo de venta">
                <ListBars items={d.by_type ?? []} format={(v) => money(v)} empty="Sin ventas." />
              </Section>
              <Section dataKey="by-branch" title="Por sede">
                <ListBars items={d.by_branch ?? []} format={(v) => money(v)} empty="Sin cobros." />
              </Section>
            </div>
          </>
        )}
      </>
    );
  };

  const renderAppointments = () => {
    const s = content?.summary as AppointmentsSummary;
    const d = content?.detail;
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4">
          <Kpi id="appointments" title="Citas" value={String(s.appointments)} note="Sin contar las canceladas" change={change('appointments')} icon={<CalendarCheck size={18} />} color="text-slate-500" />
          <Kpi id="completed" title="Atendidas" value={String(s.completed)} change={change('completed')} icon={<UserCheck size={18} />} color="text-emerald-600" />
          <Kpi id="occupancy" title="Ocupación" value={percent(s.occupancy)} note="Parte del horario del equipo con citas" change={change('occupancy')} icon={<TrendingUp size={18} />} color="text-sky-600" />
          <Kpi id="cancelled" title="Cancelaciones" value={percent(s.cancelled)} change={change('cancelled')} lowerIsBetter icon={<CalendarX2 size={18} />} color="text-rose-700" />
          <Kpi id="no_show" title="No asistieron" value={percent(s.no_show)} change={change('no_show')} lowerIsBetter icon={<CalendarX2 size={18} />} color="text-amber-600" />
          <Kpi id="online" title="Reservas por el portal" value={String(s.online)} change={change('online')} icon={<Globe size={18} />} color="text-violet-600" />
        </div>
        {d && (
          <>
            <Section dataKey="occupancy-by-day" title="Ocupación por día" hint="Qué parte del horario de trabajo del equipo estuvo con citas. Los días sin barra no había horario.">
              <DayBars points={d.occupancy_by_day ?? []} format={(v) => `${v} %`} empty="Aún no hay citas en este periodo." />
            </Section>
            <div className="grid gap-4 lg:grid-cols-3">
              <Section dataKey="by-source" title="Cómo se reservaron">
                <ListBars items={d.by_source ?? []} format={(v) => String(v)} empty="Sin citas." />
              </Section>
              <div className="lg:col-span-2">
                {d.heatmap ? (
                  <Section dataKey="heatmap" title="Qué días y horas se piden más" hint="Cuántas citas empezaron en cada hora. Más oscuro, más citas: sirve para armar los horarios del equipo.">
                    <Heatmap cells={d.heatmap} />
                  </Section>
                ) : (
                  <Section dataKey="heatmap-locked" title="Qué días y horas se piden más">
                    <p className="px-6 pb-6 text-sm text-slate-500 flex items-center gap-2"><Lock size={14} /> Viene con el módulo Analítica avanzada.</p>
                  </Section>
                )}
              </div>
            </div>
          </>
        )}
      </>
    );
  };

  const renderCustomers = () => {
    const s = content?.summary as CustomersSummary;
    const top = content?.top ?? null;
    return (
      <>
        <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
          <Kpi id="attended" title={`${vocab.clients} atendidos`} value={String(s.attended)} change={change('attended')} icon={<Users size={18} />} color="text-slate-500" />
          <Kpi id="new" title="Nuevos registrados" value={String(s.new)} change={change('new')} icon={<UserPlus size={18} />} color="text-sky-600" />
          <Kpi id="returning" title="Volvieron" value={String(s.returning)} note="Ya se habían atendido antes" change={change('returning')} icon={<RotateCcw size={18} />} color="text-emerald-600" />
          <Kpi id="rebooked" title="Volvieron a reservar" value={`${s.rebooked} %`} note="Reservaron otra cita después de atenderse" change={change('rebooked')} icon={<CalendarCheck size={18} />} color="text-violet-600" />
        </div>
        {top && (
          <Section dataKey="top-customers" title={`Los 10 ${vocab.clients.toLowerCase()} que más gastaron`} actions={exportMenu(`${vocab.clients} que más gastaron`, [{ header: vocab.client, value: (r: TopCustomer) => r.name }, { header: 'Atenciones', type: 'number', value: (r: TopCustomer) => r.visits }, { header: 'Ventas', type: 'number', value: (r: TopCustomer) => r.sales }, { header: 'Gastó', type: 'number', value: (r: TopCustomer) => r.spent }], top)}>
            <Table<TopCustomer>
              rowKey={(r) => r.id}
              rows={top}
              empty="Aún no hay ventas a clientes en este periodo."
              columns={[
                { header: vocab.client, cell: (r) => <CustomerLink id={r.id}>{r.name}</CustomerLink> },
                { header: 'Atenciones', align: 'right', cell: (r) => r.visits },
                { header: 'Ventas', align: 'right', cell: (r) => r.sales },
                { header: 'Gastó', align: 'right', cell: (r) => money(r.spent) },
              ]}
            />
          </Section>
        )}
      </>
    );
  };

  const renderPros = () => {
    const rows = content?.rows ?? [];
    return (
      <Section dataKey="pros" title={`Por ${vocab.pro.toLowerCase()}`} hint="Producción es el precio de lo atendido; Cobrado, lo que se pagó por sus servicios, ya restados los descuentos. La ocupación es la parte de su horario que tuvo con citas. El tiempo promedio solo cuenta las atenciones donde se marcó el inicio y el fin."
        actions={exportMenu(`Reporte por ${vocab.pro.toLowerCase()}`, [
          { header: vocab.pro, value: (r: ProRow) => r.name },
          { header: 'Atendidas', type: 'number', value: (r: ProRow) => r.completed },
          ...(data?.money ? [{ header: 'Producción', type: 'number' as const, value: (r: ProRow) => r.income }, { header: 'Cobrado', type: 'number' as const, value: (r: ProRow) => r.collected ?? 0 }] : []),
          { header: 'Ocupación (%)', type: 'number', value: (r: ProRow) => r.occupancy },
          { header: 'No asistieron', type: 'number', value: (r: ProRow) => r.no_show },
          { header: 'Minutos promedio', type: 'number', value: (r: ProRow) => r.minutes },
        ], rows)}>
        <Table<ProRow>
          rowKey={(r) => r.id}
          rows={rows}
          empty="Aún no hay citas en este periodo."
          columns={[
            { header: vocab.pro, cell: (r) => <ProfessionalLink id={r.id}>{r.name}</ProfessionalLink> },
            { header: 'Atendidas', align: 'right', cell: (r) => r.completed },
            ...(data?.money ? [{ header: 'Producción', align: 'right' as const, cell: (r: ProRow) => money(r.income) }, { header: 'Cobrado', align: 'right' as const, cell: (r: ProRow) => money(r.collected ?? 0) }] : []),
            { header: 'Ocupación', cell: (r) => r.occupancy === null ? <span className="text-slate-400">Sin horario</span> : (
              <div className="flex items-center gap-3">
                <span className="w-12 shrink-0 font-semibold tabular-nums">{r.occupancy} %</span>
                <span className="flex-1 h-1.5 min-w-16 rounded-full bg-slate-100 overflow-hidden"><span className="block h-full rounded-full bg-[var(--accent)]" style={{ width: `${Math.max(3, r.occupancy)}%` }} /></span>
              </div>
            ) },
            { header: 'No asistieron', align: 'right', cell: (r) => r.no_show },
            { header: 'Tiempo promedio', align: 'right', cell: (r) => formatMinutes(r.minutes) },
          ]}
        />
      </Section>
    );
  };

  const catalogSection = (key: string, title: string, rows: CatalogRow[], incomeLabel = 'Ingresos', withCollected = false) => (
    <Section dataKey={key} title={title} actions={exportMenu(title, [
      { header: 'Nombre', value: (r: CatalogRow) => r.name },
      { header: 'Cantidad', type: 'number', value: (r: CatalogRow) => r.quantity },
      ...(data?.money ? [{ header: incomeLabel, type: 'number' as const, value: (r: CatalogRow) => r.income }] : []),
      ...(data?.money && withCollected ? [{ header: 'Cobrado', type: 'number' as const, value: (r: CatalogRow) => r.collected ?? 0 }] : []),
    ], rows)}>
      <Table<CatalogRow>
        rowKey={(r) => `${r.id ?? r.name}`}
        rows={rows}
        empty="Aún no hay ventas en este periodo."
        columns={[
          { header: 'Nombre', cell: (r) => r.name },
          { header: 'Cantidad', align: 'right', cell: (r) => r.quantity },
          ...(data?.money ? [{ header: incomeLabel, align: 'right' as const, cell: (r: CatalogRow) => money(r.income) }] : []),
          ...(data?.money && withCollected ? [{ header: 'Cobrado', align: 'right' as const, cell: (r: CatalogRow) => money(r.collected ?? 0) }] : []),
        ]}
      />
    </Section>
  );

  const renderMine = () => {
    const s = content?.summary as MineSummary;
    const p = (content?.previous ?? null) as MineSummary | null;
    const diff = (key: keyof MineSummary) => (p ? delta(s[key] as number | null, p[key] as number | null) : undefined);
    const customers = content?.customers ?? [];
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4">
          <Kpi id="mine-completed" title="Atendidas" value={String(s.completed)} change={diff('completed')} icon={<UserCheck size={18} />} color="text-emerald-600" />
          {data?.money && <Kpi id="mine-income" title="Producción" value={money(s.income)} note="Precio de tus citas atendidas" change={diff('income')} icon={<Wallet size={18} />} color="text-emerald-600" />}
          <Kpi id="mine-occupancy" title="Ocupación" value={percent(s.occupancy)} note="Parte de tu horario con citas" change={diff('occupancy')} icon={<TrendingUp size={18} />} color="text-sky-600" />
          <Kpi id="mine-customers" title={`${vocab.clients} atendidos`} value={String(s.customers)} change={diff('customers')} icon={<Users size={18} />} color="text-slate-500" />
          <Kpi id="mine-no-show" title="No asistieron" value={String(s.no_show)} change={diff('no_show')} lowerIsBetter icon={<CalendarX2 size={18} />} color="text-amber-600" />
          <Kpi id="mine-minutes" title="Tiempo promedio" value={formatMinutes(s.minutes)} note="Con inicio y fin marcados" icon={<Clock size={18} />} color="text-violet-600" />
        </div>
        <Section dataKey="mine-by-day" title="Tu ocupación por día" hint="Qué parte de tu horario estuvo con citas. Los días sin barra no tenías horario.">
          <DayBars points={content?.by_day ?? []} format={(v) => `${v} %`} empty="Aún no tienes citas en este periodo." />
        </Section>
        <div className="grid gap-4 lg:grid-cols-2">
          {catalogSection('mine-services', 'Tus servicios más atendidos', content?.services ?? [], 'Producción', true)}
          <Section dataKey="mine-customers" title={`Tus ${vocab.clients.toLowerCase()} más frecuentes`} actions={exportMenu(`Mis ${vocab.clients.toLowerCase()} más frecuentes`, [
            { header: vocab.client, value: (r: MyCustomer) => r.name },
            { header: 'Atenciones', type: 'number', value: (r: MyCustomer) => r.visits },
            ...(data?.money ? [{ header: 'Producción', type: 'number' as const, value: (r: MyCustomer) => r.income }] : []),
          ], customers)}>
            <Table<MyCustomer>
              rowKey={(r) => r.id}
              rows={customers}
              empty="Aún no atiendes a nadie en este periodo."
              columns={[
                { header: vocab.client, cell: (r) => <CustomerLink id={r.id}>{r.name}</CustomerLink> },
                { header: 'Atenciones', align: 'right', cell: (r) => r.visits },
                ...(data?.money ? [{ header: 'Producción', align: 'right' as const, cell: (r: MyCustomer) => money(r.income) }] : []),
                { header: 'Última', align: 'right', cell: (r) => formatDate(r.last_at.slice(0, 10), { day: 'numeric', month: 'short' }) },
              ]}
            />
          </Section>
        </div>
      </>
    );
  };

  const renderCatalog = () => (
    <>
      {catalogSection('services', 'Servicios más atendidos', content?.services ?? [], 'Producción', true)}
      {content?.products && catalogSection('products', 'Productos más vendidos', content.products)}
    </>
  );

  return (
    <div className="space-y-5 pb-8 font-sans" data-reports>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">{data?.own_only ? 'Mis números' : 'Reportes'}</h1>
          <p className="text-sm text-slate-500 font-medium">
            {rangeText || 'Cómo va tu negocio'}
            {(data?.advanced || tab === 'mine') && rangeText ? ` · comparado con el periodo ${prevText}` : ''}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="w-44">
            <Select ariaLabel="Periodo" clearable={false} value={period} onChange={(e) => setPeriod(String(e.target.value))} options={(data?.periods ?? [{ value: '30', label: 'Últimos 30 días' }]).map((p) => ({ value: String(p.value), label: p.label }))} />
          </div>
          {period === 'custom' && (
            <>
              <div className="w-40"><DateField ariaLabel="Desde" value={from} max={to || undefined} onChange={setFrom} /></div>
              <div className="w-40"><DateField ariaLabel="Hasta" value={to} min={from || undefined} max={todayISO()} align="right" onChange={setTo} /></div>
            </>
          )}
          {data && data.branches.length > 1 && !data.branch_locked && (
            <div className="w-48">
              <Select ariaLabel="Sede" placeholder="Todas las sedes" value={branchId} onChange={(e) => setBranchId(String(e.target.value))} options={data.branches.map((b) => ({ value: b.id, label: b.name }))} />
            </div>
          )}
        </div>
      </div>

      {!data?.own_only && (
      <div className="flex flex-wrap items-center gap-1 p-1 bg-white border border-slate-200 rounded-xl w-fit" role="tablist">
        {(data?.tabs ?? []).map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            data-tab={item.key}
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={`px-3 py-1.5 text-sm font-semibold rounded-lg transition-colors flex items-center gap-1.5 ${tab === item.key ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-50'}`}
          >
            {item.key === 'customers' ? vocab.clients : item.key === 'pros' ? vocab.pros : item.label}
            {item.locked && <Lock size={12} />}
          </button>
        ))}
      </div>
      )}

      <ErrorAlert message={error} />

      {loading && !content ? (
        <p className="text-sm text-slate-500">Cargando el reporte...</p>
      ) : data?.locked ? (
        <div className={`${cardClass} p-6 flex items-start gap-3`} data-locked>
          <Lock size={18} className="text-slate-400 mt-0.5" />
          <div>
            <p className="text-sm font-bold text-brand-ink">Esta pestaña viene con el módulo Analítica avanzada.</p>
            <p className="text-sm text-slate-500 mt-1">Ventas y Citas están en todos los planes. Clientes, {vocab.pros}, Servicios y productos, la comparación con el periodo anterior y el mapa de días y horas vienen con Analítica avanzada. Si tu plan la incluye, enciéndela en Configuración → Módulos.</p>
          </div>
        </div>
      ) : content && (
        <div className={`space-y-5 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          {tab === 'sales' && renderSales()}
          {tab === 'appointments' && renderAppointments()}
          {tab === 'customers' && renderCustomers()}
          {tab === 'pros' && renderPros()}
          {tab === 'catalog' && renderCatalog()}
          {tab === 'mine' && renderMine()}
          {!data?.advanced && (tab === 'sales' || tab === 'appointments') && (
            <p className="text-xs text-slate-500">Con el módulo Analítica avanzada cada número se compara con el periodo anterior.</p>
          )}
        </div>
      )}
    </div>
  );
}
