'use client';

import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle, ArrowDownToLine, ArrowUpFromLine, Boxes, Scale, Percent, BadgePercent, CalendarCheck, CalendarX2, Clock, Globe, Lock, Receipt, RotateCcw, Sparkles, TrendingUp, UserCheck, UserPlus, Users, Wallet } from 'lucide-react';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import ExportMenu from '@/app/components/ui/table/ExportMenu';
import type { ExportColumn } from '@/app/components/ui/table/DataTable';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { addDays, apiError, formatDate, formatMinutes, todayISO } from '@/features/appointments/appointments';
import { delta, type CashDifferenceRow, type CashRegisterRow, type CashSummary, type InventorySummary, type LowStockRow, type MovedProduct, type AppointmentsSummary, type CatalogRow, type CustomersSummary, type MineSummary, type MyCustomer, type ProRow, type ReportPayload, type ReportTab, type SalesSummary, type Serie, type TopCustomer } from '../reports';
import { Donut, DayBars, Heatmap, Kpi, ListBars, Section, Table, cardClass, isWeekly } from './ReportParts';
import { ReportDownloadButton, ReportDownloads, useReportDownloads } from './ReportDownloads';

export default function ReportsPage() {
  const { can, hasModule } = useAuth();
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
      .then((res) => {
        if (!active) return;
        setData(res.data);
        setError('');
        if (res.data.tab !== tab) setTab(res.data.tab);
      })
      .catch((err) => active && setError(apiError(err, 'No se pudo cargar el reporte.')))
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [tab, period, from, to, branchId]);

  const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(v, data?.currency));
  const percent = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v} %`);
  const rangeText = data ? `Del ${formatDate(data.range.from, { day: 'numeric', month: 'long' })} al ${formatDate(data.range.to, { day: 'numeric', month: 'long', year: 'numeric' })}` : '';
  const prevText = data ? `del ${formatDate(data.range.prev_from, { day: 'numeric', month: 'short' })} al ${formatDate(data.range.prev_to, { day: 'numeric', month: 'short' })}` : '';
  const filters = useMemo(() => [rangeText, data?.branch_id ? `Sede: ${data.branches.find((b) => b.id === data.branch_id)?.name ?? ''}` : 'Todas las sedes'].filter(Boolean), [rangeText, data]);
  const canExport = can('data.export');
  const downloads = useReportDownloads(canExport);
  const content = data && data.tab === tab ? data.data : null;
  const previous = content?.previous ?? null;
  const change = (key: string) => (previous ? delta((content?.summary as unknown as Record<string, number | null>)?.[key], (previous as unknown as Record<string, number | null>)[key]) : undefined);

  const canPayments = can('payments.view') && hasModule('pos');
  const paymentsLink = (param: 'method' | 'branch_id') => (item: Serie) => canPayments && data && item.key
    ? `/payments?${new URLSearchParams({ [param]: item.key, from: data.range.from, to: data.range.to, ...(param === 'method' && data.branch_id ? { branch_id: data.branch_id } : {}) }).toString()}`
    : undefined;

  const canSales = can('sales.view') && hasModule('pos');
  const salesLink = (item: Serie) => canSales && data && item.key
    ? `/sales?${new URLSearchParams({ item_type: item.key, from: data.range.from, to: data.range.to, ...(data.branch_id ? { branch_id: data.branch_id } : {}) }).toString()}`
    : undefined;

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
            <Section dataKey="income-by-day" title={isWeekly(d.by_day ?? []) ? 'Ingresos por semana' : 'Ingresos por día'} hint={isWeekly(d.by_day ?? []) ? 'Lo que entró cada semana por cobros, ya restadas las devoluciones. Pasa el cursor sobre una barra para ver el monto.' : 'Lo que entró cada día por cobros, ya restadas las devoluciones. Pasa el cursor sobre una barra para ver el monto.'}>
              <DayBars points={d.by_day ?? []} format={(v) => money(v)} axis={(v) => formatMoney(v, data?.currency, 0)} empty="Aún no hay cobros en este periodo." />
            </Section>
            <div className="grid gap-4 lg:grid-cols-3">
              <Section dataKey="by-method" title="Por medio de pago" actions={exportMenu('Ingresos por medio de pago', [{ header: 'Medio', value: (r: Serie) => r.label }, { header: 'Cobros', type: 'number', value: (r: Serie) => r.count ?? 0 }, { header: 'Monto', type: 'number', value: (r: Serie) => r.value }], d.by_method ?? [])}>
                <Donut items={d.by_method ?? []} format={(v) => money(v)} unit="Por medio de pago" empty="Sin cobros." hrefOf={paymentsLink('method')} />
              </Section>
              <Section dataKey="by-type" title="Por tipo de venta">
                <ListBars items={d.by_type ?? []} format={(v) => money(v)} empty="Sin ventas." hrefOf={salesLink} />
              </Section>
              <Section dataKey="by-branch" title="Por sede">
                <ListBars items={d.by_branch ?? []} format={(v) => money(v)} empty="Sin cobros." hrefOf={paymentsLink('branch_id')} />
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
          <Kpi id="online" title="Reservas en línea" value={s.online_percent !== undefined ? `${s.online_percent} %` : String(s.online)} note={`${s.online} ${s.online === 1 ? 'cita reservada' : 'citas reservadas'} por la página o el portal`} change={change('online')} icon={<Globe size={18} />} color="text-violet-600" />
          {s.class_attendees !== null && s.class_attendees !== undefined && (
            <Kpi id="class_attendees" title="Asistentes a clases" value={String(s.class_attendees)} note="Marcados como asistieron" change={change('class_attendees')} icon={<UserCheck size={18} />} color="text-emerald-600" />
          )}
        </div>
        {s.no_show_alert && (
          <p data-no-show-alert className="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
            <span className="font-bold">Muchas inasistencias.</span> En este periodo no asistió el {s.no_show} % de las citas, más del {s.no_show_alert} %. Puede ayudar pedir un adelanto al reservar o confirmar las citas un día antes.
          </p>
        )}
        {d && (
          <>
            <Section dataKey="occupancy-by-day" title={isWeekly(d.occupancy_by_day ?? []) ? 'Ocupación por semana' : 'Ocupación por día'} hint={`Qué parte del horario de trabajo del equipo estuvo con citas${isWeekly(d.occupancy_by_day ?? []) ? ', en promedio cada semana' : ''}. Donde no hay barra no había horario o no hubo citas.`}>
              <DayBars points={d.occupancy_by_day ?? []} aggregate="avg" format={(v) => `${v} %`} empty="Aún no hay citas en este periodo." />
            </Section>
            <div className="grid gap-4 lg:grid-cols-3">
              <Section dataKey="by-source" title="Cómo se reservaron">
                <Donut items={d.by_source ?? []} format={(v) => String(v)} unit="Cómo se reservaron" empty="Sin citas." />
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
            {d.by_channel && (
              <Section dataKey="by-channel" title="Reservas en línea por canal" hint="De qué red o enlace llegó cada reserva hecha por la página de reservas o el portal. Los enlaces por canal están en Configuración → Página de reservas.">
                <ListBars items={d.by_channel} format={(v) => String(v)} empty="Aún no hay reservas en línea en este periodo." />
              </Section>
            )}
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
        <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
          <Kpi id="attended" title={`${vocab.clients} atendidos`} value={String(s.attended)} change={change('attended')} icon={<Users size={18} />} color="text-slate-500" />
          <Kpi id="new" title="Nuevos registrados" value={String(s.new)} change={change('new')} icon={<UserPlus size={18} />} color="text-sky-600" />
          <Kpi id="returning" title="Volvieron" value={String(s.returning)} note="Ya se habían atendido antes" change={change('returning')} icon={<RotateCcw size={18} />} color="text-emerald-600" />
          <Kpi id="rebooked" title="Volvieron a reservar" value={`${s.rebooked} %`} note={`Reservaron otra cita en los ${s.rebook_days ?? 60} días siguientes`} change={change('rebooked')} icon={<CalendarCheck size={18} />} color="text-violet-600" />
          {s.avg_spend !== undefined && (
            <Kpi id="avg_spend" title="Gasto promedio" value={money(s.avg_spend)} note={`Lo que ha pagado cada ${vocab.client.toLowerCase()}, en promedio`} change={change('avg_spend')} icon={<Wallet size={18} />} color="text-brand-accent" />
          )}
        </div>
        <Section dataKey="first-vs-returning" title="Primera vez y los que volvieron" hint={`De los ${vocab.clients.toLowerCase()} atendidos en el periodo, cuántos venían por primera vez.`}>
          <Donut items={[{ key: 'first', label: 'Primera vez', value: Math.max(0, s.attended - s.returning) }, { key: 'returning', label: 'Volvieron', value: s.returning }]} format={(v) => String(v)} unit="Atendidos" empty="Aún no hay atenciones en este periodo." />
        </Section>
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
    const withCommissions = Boolean(data?.money) && rows.some((r) => r.commissions !== null && r.commissions !== undefined);
    return (
      <Section dataKey="pros" title={`Por ${vocab.pro.toLowerCase()}`} hint="Producción es el precio de lo atendido; Cobrado, lo que se pagó por sus servicios, ya restados los descuentos. La ocupación es la parte de su horario que tuvo con citas. El tiempo promedio solo cuenta las atenciones donde se marcó el inicio y el fin."
        actions={exportMenu(`Reporte por ${vocab.pro.toLowerCase()}`, [
          { header: vocab.pro, value: (r: ProRow) => r.name },
          { header: 'Atendidas', type: 'number', value: (r: ProRow) => r.completed },
          ...(data?.money ? [{ header: 'Producción', type: 'number' as const, value: (r: ProRow) => r.income }, { header: 'Cobrado', type: 'number' as const, value: (r: ProRow) => r.collected ?? 0 }] : []),
          ...(withCommissions ? [{ header: 'Comisiones', type: 'number' as const, value: (r: ProRow) => r.commissions ?? 0 }] : []),
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
            ...(withCommissions ? [{ header: 'Comisiones', align: 'right' as const, cell: (r: ProRow) => money(r.commissions ?? 0) }] : []),
            { header: 'Ocupación', cell: (r) => r.occupancy === null ? <span className="text-slate-400">Sin horario</span> : (
              <div className="flex items-center gap-3">
                <span className="w-12 shrink-0 font-semibold tabular-nums">{r.occupancy} %</span>
                <span className="flex-1 h-1.5 min-w-16 rounded-full bg-slate-100 overflow-hidden"><span className="block h-full rounded-full bg-[var(--brand-link)]" style={{ width: `${Math.max(3, r.occupancy)}%` }} /></span>
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
          {s.commissions !== null && s.commissions !== undefined && <Kpi id="mine-commissions" title="Comisiones" value={money(s.commissions)} note="Lo que ganaste en el periodo, ya restadas las devoluciones" change={diff('commissions')} icon={<Percent size={18} />} color="text-violet-600" />}
          <Kpi id="mine-minutes" title="Tiempo promedio" value={formatMinutes(s.minutes)} note="Con inicio y fin marcados" icon={<Clock size={18} />} color="text-violet-600" />
        </div>
        <Section dataKey="mine-by-day" title={isWeekly(content?.by_day ?? []) ? 'Tu ocupación por semana' : 'Tu ocupación por día'} hint="Qué parte de tu horario estuvo con citas. Donde no hay barra no tenías horario o no hubo citas.">
          <DayBars points={content?.by_day ?? []} aggregate="avg" format={(v) => `${v} %`} empty="Aún no tienes citas en este periodo." />
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

  const noMoney = (
    <p className="text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">No tienes permiso para ver los montos. Pídeselo al administrador (Usuarios y roles → Reportes → Ver montos).</p>
  );

  const renderCash = () => {
    const s = content?.summary as CashSummary;
    const registers = content?.registers ?? [];
    const differences = content?.differences ?? [];
    const signed = (v: number) => (v > 0 ? `+${money(v)}` : money(v));
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4">
          <Kpi id="cash-sessions" title="Turnos de caja" value={String(s.sessions)} note={`${s.closed} cerrados`} change={change('sessions')} icon={<Receipt size={18} />} color="text-slate-500" />
          <Kpi id="cash-sales" title="Cobrado en caja" value={money(s.sales)} note={s.online ? `Más ${money(s.online)} cobrados en línea` : 'Ventas pagadas con dinero de la caja'} change={change('sales')} icon={<Wallet size={18} />} color="text-emerald-600" />
          <Kpi id="cash-income" title="Otros ingresos" value={money(s.income)} change={change('income')} icon={<ArrowDownToLine size={18} />} color="text-sky-600" />
          <Kpi id="cash-out" title="Gastos y retiros" value={s.expense === null || s.withdrawal === null ? '—' : money(s.expense + s.withdrawal)} note={s.refund ? `Más ${money(s.refund)} devueltos` : undefined} lowerIsBetter icon={<ArrowUpFromLine size={18} />} color="text-rose-700" />
          <Kpi id="cash-short" title="Faltantes" value={money(s.short === null ? null : Math.abs(s.short))} note="Al cerrar, faltó dinero" lowerIsBetter icon={<Scale size={18} />} color="text-amber-600" />
          <Kpi id="cash-over" title="Sobrantes" value={money(s.over)} note={`${s.with_difference} ${s.with_difference === 1 ? 'cierre' : 'cierres'} con diferencia`} lowerIsBetter icon={<Scale size={18} />} color="text-violet-600" />
        </div>
        {!data?.money ? noMoney : (
          <>
            <Section dataKey="cash-by-day" title={isWeekly(content?.by_day ?? []) ? 'Movimiento de caja por semana' : 'Movimiento de caja por día'} hint="Lo que entró a las cajas menos lo que salió (gastos, retiros y devoluciones). Pasa el cursor sobre una barra para ver el monto.">
              <DayBars points={(content?.by_day ?? []).map((p) => ({ day: p.day, value: p.value ?? 0 }))} format={(v) => money(v)} axis={(v) => formatMoney(v, data?.currency, 0)} empty="Aún no hay movimientos de caja en este periodo." />
            </Section>
            <div className="grid gap-4 lg:grid-cols-2">
              <Section dataKey="cash-registers" title="Por caja" actions={exportMenu('Caja por caja', [
                { header: 'Caja', value: (r: CashRegisterRow) => r.name },
                { header: 'Sede', value: (r: CashRegisterRow) => r.branch ?? '' },
                { header: 'Turnos', type: 'number', value: (r: CashRegisterRow) => r.sessions },
                { header: 'Cobrado', type: 'money', value: (r: CashRegisterRow) => r.sales },
                { header: 'Neto', type: 'money', value: (r: CashRegisterRow) => r.net },
                { header: 'Diferencia', type: 'money', value: (r: CashRegisterRow) => r.difference },
              ], registers)}>
                <Table<CashRegisterRow>
                  rowKey={(r) => r.id}
                  rows={registers}
                  empty="Aún no se abrió ninguna caja en este periodo."
                  columns={[
                    { header: 'Caja', cell: (r) => (<span><span className="font-semibold text-slate-800">{r.name}</span>{r.branch && <span className="block text-xs text-slate-400">{r.branch}</span>}</span>) },
                    { header: 'Turnos', align: 'right', cell: (r) => (r.online ? <span title="Los pagos en línea no tienen turno: entran solos, sin efectivo.">—</span> : r.sessions) },
                    { header: 'Cobrado', align: 'right', cell: (r) => money(r.sales) },
                    { header: 'Neto', align: 'right', cell: (r) => money(r.net) },
                    { header: 'Diferencia', align: 'right', cell: (r) => <span className={r.difference < 0 ? 'text-rose-700 font-semibold' : r.difference > 0 ? 'text-amber-700 font-semibold' : ''}>{signed(r.difference)}</span> },
                  ]}
                />
              </Section>
              <Section dataKey="cash-differences" title="Cierres con diferencia" hint="Los cierres en que el dinero contado no coincidió con lo esperado, de mayor a menor." actions={exportMenu('Cierres de caja con diferencia', [
                { header: 'Caja', value: (r: CashDifferenceRow) => r.register },
                { header: 'Cerrada', type: 'datetime', value: (r: CashDifferenceRow) => r.closed_at },
                { header: 'Cerró', value: (r: CashDifferenceRow) => r.closed_by ?? '' },
                { header: 'Diferencia', type: 'money', value: (r: CashDifferenceRow) => r.difference },
                { header: 'Nota', value: (r: CashDifferenceRow) => r.notes ?? '' },
              ], differences)}>
                <Table<CashDifferenceRow>
                  rowKey={(r) => r.id}
                  rows={differences}
                  empty="Todos los cierres cuadraron."
                  columns={[
                    { header: 'Caja', cell: (r) => (<span><span className="font-semibold text-slate-800">{r.register}</span>{r.notes && <span className="block text-xs text-slate-400 line-clamp-1" title={r.notes}>{r.notes}</span>}</span>) },
                    { header: 'Cerrada', cell: (r) => (r.closed_at ? formatDate(r.closed_at.slice(0, 10), { day: 'numeric', month: 'short' }) : '—') },
                    { header: 'Cerró', cell: (r) => r.closed_by ?? '—' },
                    { header: 'Diferencia', align: 'right', cell: (r) => <span className={r.difference < 0 ? 'text-rose-700 font-semibold' : 'text-amber-700 font-semibold'}>{signed(r.difference)}</span> },
                  ]}
                />
              </Section>
            </div>
          </>
        )}
      </>
    );
  };

  const renderInventory = () => {
    const s = content?.summary as InventorySummary;
    const moved = content?.moved ?? [];
    const low = content?.low ?? [];
    const qty = (v: number) => v.toLocaleString('es-PE', { maximumFractionDigits: 2 });
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4">
          <Kpi id="inv-products" title="Productos con stock" value={String(s.products)} note="Los que llevan control de stock" icon={<Boxes size={18} />} color="text-slate-500" />
          {data?.money && <Kpi id="inv-value" title="Valor del stock" value={money(s.value)} note="Stock de hoy por su costo" icon={<Wallet size={18} />} color="text-emerald-600" />}
          <Kpi id="inv-low" title="Bajo el mínimo" value={String(s.low)} note="Hoy, con stock mínimo definido" lowerIsBetter icon={<AlertTriangle size={18} />} color="text-amber-600" />
          <Kpi id="inv-empty" title="Sin stock" value={String(s.empty)} note="Hoy" lowerIsBetter icon={<AlertTriangle size={18} />} color="text-rose-700" />
          {data?.money && <Kpi id="inv-in" title="Entró" value={money(s.in_value)} note="Compras y devoluciones, a costo" change={change('in_value')} icon={<ArrowDownToLine size={18} />} color="text-sky-600" />}
          {data?.money && <Kpi id="inv-out" title="Salió" value={money(s.out_value)} note="Ventas y consumo en atenciones, a costo" change={change('out_value')} icon={<ArrowUpFromLine size={18} />} color="text-violet-600" />}
          {!data?.money && <Kpi id="inv-adjust" title="Ajustes" value={String(s.adjust)} note="Mermas y correcciones del periodo" lowerIsBetter icon={<Scale size={18} />} color="text-violet-600" />}
        </div>
        <div className="grid gap-4 lg:grid-cols-3">
          <Section dataKey="inv-by-type" title="Movimientos del periodo" hint="Unidades que entraron o salieron por cada motivo. No cuenta los traslados entre almacenes.">
            <ListBars items={content?.by_type ?? []} format={(v) => `${qty(v)} u.`} empty="Sin movimientos en este periodo." />
          </Section>
          <div className="lg:col-span-2">
            <Section dataKey="inv-moved" title="Productos que más salen" hint="Vendidos y usados en atenciones durante el periodo." actions={exportMenu('Productos que más salen', [
              { header: 'Producto', value: (r: MovedProduct) => r.name },
              { header: 'Código', value: (r: MovedProduct) => r.sku },
              { header: 'Vendidos', type: 'number', value: (r: MovedProduct) => r.sold },
              { header: 'Usados en atenciones', type: 'number', value: (r: MovedProduct) => r.used },
              ...(data?.money ? [{ header: 'Costo', type: 'money' as const, value: (r: MovedProduct) => r.value }] : []),
            ], moved)}>
              <Table<MovedProduct>
                rowKey={(r) => r.id}
                rows={moved}
                empty="Aún no sale ningún producto en este periodo."
                columns={[
                  { header: 'Producto', cell: (r) => (<span><span className="font-semibold text-slate-800">{r.name}</span><span className="block text-xs text-slate-400 font-mono">{r.sku}</span></span>) },
                  { header: 'Vendidos', align: 'right', cell: (r) => qty(r.sold) },
                  { header: 'Usados', align: 'right', cell: (r) => qty(r.used) },
                  ...(data?.money ? [{ header: 'Costo', align: 'right' as const, cell: (r: MovedProduct) => money(r.value) }] : []),
                ]}
              />
            </Section>
          </div>
        </div>
        <Section dataKey="inv-low" title="Por reponer" hint="Productos sin stock o bajo su stock mínimo hoy, empezando por los que más faltan." actions={exportMenu('Productos por reponer', [
          { header: 'Producto', value: (r: LowStockRow) => r.name },
          { header: 'Código', value: (r: LowStockRow) => r.sku },
          { header: 'Stock', type: 'number', value: (r: LowStockRow) => r.quantity },
          { header: 'Mínimo', type: 'number', value: (r: LowStockRow) => r.min_stock },
        ], low)}>
          <Table<LowStockRow>
            rowKey={(r) => r.id}
            rows={low}
            empty="Ningún producto está por debajo de su mínimo."
            columns={[
              { header: 'Producto', cell: (r) => (<span><span className="font-semibold text-slate-800">{r.name}</span><span className="block text-xs text-slate-400 font-mono">{r.sku}</span></span>) },
              { header: 'Stock', align: 'right', cell: (r) => <span className={r.quantity <= 0 ? 'text-rose-700 font-semibold' : 'text-amber-700 font-semibold'}>{qty(r.quantity)}</span> },
              { header: 'Mínimo', align: 'right', cell: (r) => qty(r.min_stock) },
            ]}
          />
        </Section>
      </>
    );
  };

  const renderCatalog = () => (
    <>
      <div className="grid gap-4 lg:grid-cols-2">
        <Section dataKey="services-share" title="Qué servicios se atienden más" hint="Parte de las atenciones del periodo que fue de cada servicio.">
          <Donut items={(content?.services ?? []).map((r) => ({ key: String(r.id ?? r.name), label: r.name, value: r.quantity }))} format={(v) => String(v)} unit="Servicios" empty="Aún no hay atenciones en este periodo." />
        </Section>
        {data?.money ? (
          <Section dataKey="services-income" title="Producción por servicio" hint="El precio de lo atendido de cada servicio.">
            <ListBars items={(content?.services ?? []).map((r) => ({ key: String(r.id ?? r.name), label: r.name, value: r.income ?? 0 })).sort((a, b) => b.value - a.value).slice(0, 8)} format={(v) => money(v)} empty="Aún no hay atenciones en este periodo." />
          </Section>
        ) : content?.products ? (
          <Section dataKey="products-share" title="Qué productos se venden más">
            <Donut items={content.products.map((r) => ({ key: String(r.id ?? r.name), label: r.name, value: r.quantity }))} format={(v) => String(v)} unit="Productos" empty="Aún no hay ventas en este periodo." />
          </Section>
        ) : null}
      </div>
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
          {canExport && data && !data.locked && content && (
            <ReportDownloadButton busy={downloads.busy} onClick={() => downloads.request({ tab: data.tab, period, ...(period === 'custom' ? { from, to } : {}), branch_id: data.branch_id })} />
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
      <ErrorAlert message={downloads.error} />
      <SuccessAlert message={downloads.message} />

      {loading && !content ? (
        <p className="text-sm text-slate-500">Cargando el reporte...</p>
      ) : data?.locked ? (
        <div className={`${cardClass} p-6 flex items-start gap-3`} data-locked>
          <Lock size={18} className="text-slate-400 mt-0.5" />
          <div>
            <p className="text-sm font-bold text-brand-ink">Esta pestaña viene con el módulo Analítica avanzada.</p>
            <p className="text-sm text-slate-500 mt-1">Ventas y Citas están en todos los planes. {vocab.clients}, {vocab.pros}, Servicios y productos, Caja, Inventario, la comparación con el periodo anterior y el mapa de días y horas vienen con Analítica avanzada. Si tu plan la incluye, enciéndela en Configuración → Módulos.</p>
          </div>
        </div>
      ) : content && (
        <div className={`space-y-5 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          {tab === 'sales' && renderSales()}
          {tab === 'appointments' && renderAppointments()}
          {tab === 'customers' && renderCustomers()}
          {tab === 'pros' && renderPros()}
          {tab === 'catalog' && renderCatalog()}
          {tab === 'cash' && renderCash()}
          {tab === 'inventory' && renderInventory()}
          {tab === 'mine' && renderMine()}
          {!data?.advanced && (tab === 'sales' || tab === 'appointments') && (
            <p className="text-xs text-slate-500">Con el módulo Analítica avanzada cada número se compara con el periodo anterior.</p>
          )}
        </div>
      )}

      {canExport && <ReportDownloads items={downloads.items} />}
    </div>
  );
}
