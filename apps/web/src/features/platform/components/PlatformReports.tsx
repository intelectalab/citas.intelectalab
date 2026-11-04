'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, Building2, CalendarCheck, CalendarClock, CircleSlash, Gauge, Hourglass, Lock, Moon, Receipt, Repeat, TrendingUp, UserMinus, UserPlus, Wallet, XCircle } from 'lucide-react';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { ErrorAlert } from '@/app/components/common/Alert';
import ExportMenu from '@/app/components/ui/table/ExportMenu';
import type { ExportColumn } from '@/app/components/ui/table/DataTable';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { addDays, apiError, formatDate, todayISO } from '@/features/appointments/appointments';
import { delta, type Serie } from '@/features/reports/reports';
import { DayBars, Donut, Kpi, ListBars, Section, Table, isWeekly } from '@/features/reports/components/ReportParts';

type Tab = 'income' | 'tenants' | 'usage';

interface Money {
  amount: number;
  others: { currency: string; amount: number }[];
}

interface IncomeData {
  summary: {
    mrr: Money;
    yearly: Money;
    collected: Money;
    payments: number;
    failed: { count: number; amount: number };
    owed: Money & { count: number };
    review: { count: number; amount: number };
  };
  previous: { collected: number; payments: number; failed: number };
  by_day: { day: string; value: number }[];
  by_method: Serie[];
  by_plan: Serie[];
  recurring_by_plan: Serie[];
  owing: OwingRow[];
}

interface OwingRow {
  id: string;
  name: string;
  plan: string | null;
  status: string;
  status_label: string;
  amount: number;
  currency: string;
}

interface TenantsData {
  summary: {
    active: number;
    trial: number;
    grace: number;
    locked: number;
    canceled: number;
    suspended: number;
    total: number;
    new: number;
    conversion: number | null;
    converted: number;
    ended: number;
    lost: number;
  };
  previous: { new: number; conversion: number | null; lost: number };
  by_month: { day: string; value: number }[];
  by_status: Serie[];
  by_plan: Serie[];
  by_business_type: Serie[];
}

interface LimitUse {
  key: string;
  label: string;
  used: number;
  limit: number | null;
  percent: number | null;
}

interface UsageRow {
  id: string;
  name: string;
  plan: string | null;
  status: string;
  status_label: string;
  appointments: number;
  limits: LimitUse[];
  top: LimitUse | null;
  last_login_at: string | null;
  idle: boolean;
  near: boolean;
}

interface UsageData {
  summary: { appointments: number; average: number; near: number; idle: number };
  previous: { appointments: number };
  near_limit: number;
  idle_days: number;
  login_ready: boolean;
  rows: UsageRow[];
}

interface Payload {
  tab: Tab;
  tabs: { key: Tab; label: string }[];
  periods: { value: string; label: string }[];
  range: { from: string; to: string; prev_from: string; prev_to: string };
  currency: string;
  ready: { payments: boolean; transfers: boolean };
  data: IncomeData | TenantsData | UsageData;
}

const STATUS_STYLE: Record<string, string> = {
  active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  trial: 'bg-sky-50 text-sky-700 border-sky-200',
  grace: 'bg-amber-50 text-amber-700 border-amber-200',
  locked: 'bg-rose-50 text-rose-700 border-rose-200',
  none: 'bg-slate-50 text-slate-600 border-slate-200',
  canceled: 'bg-slate-50 text-slate-600 border-slate-200',
  suspended: 'bg-slate-50 text-slate-600 border-slate-200',
};

const StatusPill = ({ status, label }: { status: string; label: string }) => (
  <span className={`inline-flex px-2 py-0.5 rounded-full border text-xs font-semibold ${STATUS_STYLE[status] ?? STATUS_STYLE.none}`}>{label}</span>
);

const TenantLink = ({ id, name }: { id: string; name: string }) => (
  <Link href={`/admin/tenants/${id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{name}</Link>
);

const monthLabel = (day: string) => formatDate(day, { month: 'short' });
const monthTitle = (day: string) => formatDate(day, { month: 'long', year: 'numeric' });

const count = (n: number) => n.toLocaleString('es-PE');
const usageText = (limit: LimitUse) => (limit.limit ? `${count(limit.used)} de ${count(limit.limit)}` : count(limit.used));

const UseCell = ({ limit }: { limit: LimitUse | undefined }) => {
  if (!limit) return <span className="text-slate-400">—</span>;
  const tone = limit.percent === null ? 'text-slate-700' : limit.percent >= 100 ? 'text-rose-700 font-bold' : limit.percent >= 80 ? 'text-amber-700 font-bold' : 'text-slate-700';
  return <span className={`whitespace-nowrap ${tone}`}>{usageText(limit)}</span>;
};

export default function PlatformReports({ initialTab }: { initialTab?: string }) {
  const [tab, setTab] = useState<Tab>(initialTab === 'tenants' || initialTab === 'usage' ? initialTab : 'income');
  const [period, setPeriod] = useState('30');
  const [from, setFrom] = useState(addDays(todayISO(), -29));
  const [to, setTo] = useState(todayISO());
  const [data, setData] = useState<Payload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (period === 'custom' && (!from || !to)) return;
    let active = true;
    setLoading(true);
    api.get<Payload>('/admin/reports', { params: { tab, period, ...(period === 'custom' ? { from, to } : {}) } })
      .then((res) => {
        if (!active) return;
        setData(res.data);
        setError('');
      })
      .catch((err) => active && setError(apiError(err, 'No se pudo cargar el reporte.')))
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [tab, period, from, to]);

  const currency = data?.currency;
  const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(v, currency));
  const others = (m: Money) => (m.others.length ? `Además ${m.others.map((o) => formatMoney(o.amount, o.currency)).join(' y ')}` : undefined);
  const rangeText = data ? `Del ${formatDate(data.range.from, { day: 'numeric', month: 'long' })} al ${formatDate(data.range.to, { day: 'numeric', month: 'long', year: 'numeric' })}` : '';
  const prevText = data ? `del ${formatDate(data.range.prev_from, { day: 'numeric', month: 'short' })} al ${formatDate(data.range.prev_to, { day: 'numeric', month: 'short' })}` : '';
  const filters = useMemo(() => [rangeText].filter(Boolean), [rangeText]);
  const content = data && data.tab === tab ? data.data : null;
  const paymentsReady = data?.ready.payments ?? true;

  const exportMenu = <T,>(title: string, columns: ExportColumn<T>[], rows: T[]) => rows.length > 0 ? (
    <ExportMenu title={title} screen="admin-reports" columns={columns} filtered={rows} selected={[]} filters={filters} />
  ) : null;

  const renderIncome = (d: IncomeData) => {
    const s = d.summary;
    const owedNote = [s.owed.count ? `${s.owed.count} ${s.owed.count === 1 ? 'empresa' : 'empresas'}` : 'Ninguna empresa debe', s.review.count ? `${s.review.count} ${s.review.count === 1 ? 'comprobante' : 'comprobantes'} en revisión` : ''].filter(Boolean).join(' · ');
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4">
          <Kpi id="mrr" title="Ingreso mensual" value={money(s.mrr.amount)} note={others(s.mrr) ?? 'Suscripciones vigentes, por mes'} icon={<Repeat size={18} />} color="text-sky-600" />
          <Kpi id="yearly" title="Lo que suma al año" value={money(s.yearly.amount)} note={others(s.yearly) ?? 'El ingreso mensual por 12'} icon={<TrendingUp size={18} />} color="text-violet-600" />
          <Kpi id="collected" title="Cobrado" value={money(s.collected.amount)} note={others(s.collected)} change={delta(s.collected.amount, d.previous.collected)} icon={<Wallet size={18} />} color="text-emerald-600" />
          <Kpi id="payments" title="Pagos recibidos" value={String(s.payments)} change={delta(s.payments, d.previous.payments)} icon={<Receipt size={18} />} color="text-slate-500" />
          <Kpi id="failed" title="Cobros que fallaron" value={paymentsReady ? String(s.failed.count) : '—'} note={paymentsReady ? (s.failed.count ? money(s.failed.amount) : undefined) : 'Falta actualizar la base'} change={paymentsReady ? delta(s.failed.count, d.previous.failed) : undefined} lowerIsBetter icon={<XCircle size={18} />} color="text-rose-700" />
          <Kpi id="owed" title="Por cobrar" value={money(s.owed.amount)} note={owedNote} icon={<Hourglass size={18} />} color="text-amber-600" />
        </div>
        <Section dataKey="collected-by-day" title={isWeekly(d.by_day) ? 'Cobrado por semana' : 'Cobrado por día'} hint={`Lo que pagaron las empresas por su plan cada ${isWeekly(d.by_day) ? 'semana' : 'día'}. Pasa el cursor sobre una barra para ver el monto.`}>
          <DayBars points={d.by_day} format={(v) => money(v)} axis={(v) => formatMoney(v, currency, 0)} empty="Aún no hay pagos de planes en este periodo." />
        </Section>
        <div className="grid gap-4 lg:grid-cols-3">
          <Section dataKey="by-method" title="Por forma de pago" actions={exportMenu('Cobrado por forma de pago', [{ header: 'Forma de pago', value: (r: Serie) => r.label }, { header: 'Pagos', type: 'number', value: (r: Serie) => r.count ?? 0 }, { header: 'Monto', type: 'number', value: (r: Serie) => r.value }], d.by_method)}>
            <Donut items={d.by_method} format={(v) => money(v)} unit="Por forma de pago" empty="Sin pagos." />
          </Section>
          <Section dataKey="by-plan" title="Cobrado por plan">
            <ListBars items={d.by_plan} format={(v) => money(v)} empty="Sin pagos." />
          </Section>
          <Section dataKey="recurring-by-plan" title="Ingreso mensual por plan" hint="Lo que deja cada plan al mes hoy.">
            <ListBars items={d.recurring_by_plan} format={(v) => money(v)} empty="Ninguna empresa paga un plan todavía." />
          </Section>
        </div>
        <Section dataKey="owing" title="Empresas por cobrar" hint="En días de gracia o en solo lectura porque su plan venció sin pagarse." actions={exportMenu('Empresas por cobrar', [{ header: 'Empresa', value: (r: OwingRow) => r.name }, { header: 'Plan', value: (r: OwingRow) => r.plan ?? '' }, { header: 'Estado', value: (r: OwingRow) => r.status_label }, { header: 'Monto', type: 'number', total: true, value: (r: OwingRow) => r.amount }], d.owing)}>
          <Table<OwingRow>
            rowKey={(r) => r.id}
            rows={d.owing}
            empty="Ninguna empresa debe su plan."
            columns={[
              { header: 'Empresa', cell: (r) => <TenantLink id={r.id} name={r.name} /> },
              { header: 'Plan', cell: (r) => r.plan ?? '—' },
              { header: 'Estado', cell: (r) => <StatusPill status={r.status} label={r.status_label} /> },
              { header: 'Monto', align: 'right', cell: (r) => formatMoney(r.amount, r.currency) },
            ]}
          />
        </Section>
      </>
    );
  };

  const renderTenants = (d: TenantsData) => {
    const s = d.summary;
    return (
      <>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <Kpi id="active" title="Activas" value={String(s.active)} note={`De ${s.total} en total`} icon={<Building2 size={18} />} color="text-emerald-600" />
          <Kpi id="trial" title="En prueba" value={String(s.trial)} icon={<CalendarClock size={18} />} color="text-sky-600" />
          <Kpi id="grace" title="En días de gracia" value={String(s.grace)} note="Su plan venció y aún pueden usarlo" icon={<Hourglass size={18} />} color="text-amber-600" />
          <Kpi id="locked" title="Solo lectura" value={String(s.locked)} note="Su plan venció sin pagarse" icon={<Lock size={18} />} color="text-rose-700" />
          <Kpi id="new" title="Nuevas" value={String(s.new)} change={delta(s.new, d.previous.new)} icon={<UserPlus size={18} />} color="text-sky-600" />
          <Kpi id="conversion" title="De la prueba a pagar" value={s.conversion === null ? '—' : `${s.conversion} %`} note={s.converted + s.ended ? `${s.converted} de ${s.converted + s.ended} pruebas terminadas` : 'Ninguna prueba terminó en el periodo'} change={delta(s.conversion, d.previous.conversion)} icon={<TrendingUp size={18} />} color="text-violet-600" />
          <Kpi id="lost" title="Bajas" value={String(s.lost)} note="Cancelaron su plan en el periodo" change={delta(s.lost, d.previous.lost)} lowerIsBetter icon={<UserMinus size={18} />} color="text-rose-700" />
          <Kpi id="canceled" title="Canceladas y suspendidas" value={String(s.canceled + s.suspended)} note={`${s.canceled} canceladas · ${s.suspended} suspendidas`} icon={<CircleSlash size={18} />} color="text-slate-500" />
        </div>
        <Section dataKey="new-by-month" title="Empresas nuevas por mes" hint="Los últimos 12 meses hasta el final del periodo.">
          <DayBars points={d.by_month} format={(v) => `${v} ${v === 1 ? 'empresa' : 'empresas'}`} axis={(v) => (Number.isInteger(v) ? String(v) : '')} empty="No se registraron empresas en estos 12 meses." labelOf={monthLabel} titleOf={monthTitle} />
        </Section>
        <div className="grid gap-4 lg:grid-cols-3">
          <Section dataKey="by-status" title="Por estado">
            <Donut items={d.by_status} format={(v) => String(v)} unit="Por estado" empty="Aún no hay empresas." limit={7} />
          </Section>
          <Section dataKey="tenants-by-plan" title="Por plan" hint="Sin contar canceladas ni suspendidas.">
            <Donut items={d.by_plan} format={(v) => String(v)} unit="Por plan" empty="Aún no hay empresas." />
          </Section>
          <Section dataKey="by-business-type" title="Por rubro" hint="Sin contar canceladas ni suspendidas.">
            <Donut items={d.by_business_type} format={(v) => String(v)} unit="Por rubro" empty="Aún no hay empresas." />
          </Section>
        </div>
      </>
    );
  };

  const renderUsage = (d: UsageData) => {
    const s = d.summary;
    const limitOf = (r: UsageRow, key: string) => r.limits.find((l) => l.key === key);
    const lastLogin = (r: UsageRow) => (r.last_login_at ? new Date(r.last_login_at).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Nunca');
    const near = d.rows.filter((r) => r.near).sort((a, b) => (b.top?.percent ?? 0) - (a.top?.percent ?? 0));
    const idle = d.rows.filter((r) => r.idle);
    const exportColumns: ExportColumn<UsageRow>[] = [
      { header: 'Empresa', value: (r) => r.name },
      { header: 'Plan', value: (r) => r.plan ?? '' },
      { header: 'Estado', value: (r) => r.status_label },
      { header: 'Citas del periodo', type: 'number', total: true, value: (r) => r.appointments },
      ...['appointments_month', 'users', 'professionals', 'branches'].flatMap((key) => {
        const label = d.rows[0]?.limits.find((l) => l.key === key)?.label ?? key;
        return [
          { header: `${label} (uso)`, type: 'number' as const, value: (r: UsageRow) => limitOf(r, key)?.used ?? 0 },
          { header: `${label} (tope)`, type: 'number' as const, value: (r: UsageRow) => limitOf(r, key)?.limit ?? '' },
        ];
      }),
      { header: 'Último ingreso', value: (r) => lastLogin(r) },
    ];

    return (
      <>
        <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
          <Kpi id="appointments" title="Citas en el periodo" value={String(s.appointments)} note="De todas las empresas, sin las canceladas" change={delta(s.appointments, d.previous.appointments)} icon={<CalendarCheck size={18} />} color="text-sky-600" />
          <Kpi id="average" title="Promedio por empresa" value={String(s.average)} note="Sin contar canceladas ni suspendidas" icon={<Gauge size={18} />} color="text-violet-600" />
          <Kpi id="near" title="Cerca del tope" value={String(s.near)} note={`Usan el ${d.near_limit} % o más de su plan`} icon={<AlertTriangle size={18} />} color="text-amber-600" />
          <Kpi id="idle" title="Sin movimiento" value={d.login_ready ? String(s.idle) : '—'} note={d.login_ready ? `Nadie entró en ${d.idle_days} días` : 'Falta actualizar la base'} icon={<Moon size={18} />} color="text-rose-700" />
        </div>
        <div className="grid gap-4 lg:grid-cols-2">
          <Section dataKey="near-limit" title="Cerca del tope" hint="Les puedes ofrecer un plan mayor.">
            <Table<UsageRow>
              rowKey={(r) => r.id}
              rows={near}
              empty="Ninguna empresa está cerca del tope de su plan."
              columns={[
                { header: 'Empresa', cell: (r) => <TenantLink id={r.id} name={r.name} /> },
                { header: 'Plan', cell: (r) => r.plan ?? '—' },
                { header: 'Qué', cell: (r) => r.top?.label ?? '—' },
                { header: 'Uso', align: 'right', cell: (r) => <UseCell limit={r.top ?? undefined} /> },
              ]}
            />
          </Section>
          <Section dataKey="idle" title="Sin movimiento" hint={`Nadie de la empresa entró en ${d.idle_days} días: pueden darse de baja.`}>
            <Table<UsageRow>
              rowKey={(r) => r.id}
              rows={d.login_ready ? idle : []}
              empty={d.login_ready ? 'Todas las empresas entraron hace poco.' : 'Falta actualizar la base para saber el último ingreso.'}
              columns={[
                { header: 'Empresa', cell: (r) => <TenantLink id={r.id} name={r.name} /> },
                { header: 'Plan', cell: (r) => r.plan ?? '—' },
                { header: 'Último ingreso', align: 'right', cell: (r) => lastLogin(r) },
              ]}
            />
          </Section>
        </div>
        <Section dataKey="usage" title="Uso por empresa" hint="Solo conteos: la plataforma no ve los datos de los clientes de cada empresa. Las citas del mes, usuarios, profesionales y sedes se comparan con el tope de su plan." actions={exportMenu('Uso por empresa', exportColumns, d.rows)}>
          <Table<UsageRow>
            rowKey={(r) => r.id}
            rows={d.rows}
            empty="Aún no hay empresas."
            columns={[
              { header: 'Empresa', cell: (r) => <TenantLink id={r.id} name={r.name} /> },
              { header: 'Plan', cell: (r) => r.plan ?? '—' },
              { header: 'Estado', cell: (r) => <StatusPill status={r.status} label={r.status_label} /> },
              { header: 'Citas del periodo', align: 'right', cell: (r) => r.appointments },
              { header: 'Citas del mes', align: 'right', cell: (r) => <UseCell limit={limitOf(r, 'appointments_month')} /> },
              { header: 'Usuarios', align: 'right', cell: (r) => <UseCell limit={limitOf(r, 'users')} /> },
              { header: 'Profesionales', align: 'right', cell: (r) => <UseCell limit={limitOf(r, 'professionals')} /> },
              { header: 'Sedes', align: 'right', cell: (r) => <UseCell limit={limitOf(r, 'branches')} /> },
              { header: 'Último ingreso', align: 'right', cell: (r) => (d.login_ready ? lastLogin(r) : '—') },
            ]}
          />
        </Section>
      </>
    );
  };

  return (
    <div className="space-y-5 pb-8 font-sans" data-platform-reports>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">Reportes de la plataforma</h1>
          <p className="text-sm text-slate-500 font-medium">
            {rangeText ? `${rangeText} · comparado con el periodo ${prevText}` : 'Cómo va la plataforma'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="w-44">
            <Select ariaLabel="Periodo" clearable={false} value={period} onChange={(e) => setPeriod(String(e.target.value))} options={(data?.periods ?? [{ value: '30', label: 'Últimos 30 días' }]).map((p) => ({ value: p.value, label: p.label }))} />
          </div>
          {period === 'custom' && (
            <>
              <div className="w-40"><DateField ariaLabel="Desde" value={from} max={to || undefined} onChange={setFrom} /></div>
              <div className="w-40"><DateField ariaLabel="Hasta" value={to} min={from || undefined} max={todayISO()} align="right" onChange={setTo} /></div>
            </>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-1 p-1 bg-white border border-slate-200 rounded-xl w-fit" role="tablist">
        {(data?.tabs ?? []).map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            data-tab={item.key}
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={`px-3 py-1.5 text-sm font-semibold rounded-lg transition-colors ${tab === item.key ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-50'}`}
          >
            {item.label}
          </button>
        ))}
      </div>

      <ErrorAlert message={error} />

      {data && !paymentsReady && tab === 'income' && (
        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-not-ready>
          A la base le faltan las actualizaciones del cobro de planes: los cobros que fallaron y la forma de pago no se pueden mostrar todavía. Se arregla con <span className="font-mono">php artisan migrate</span>.
        </p>
      )}

      {loading && !content ? (
        <p className="text-sm text-slate-500">Cargando el reporte...</p>
      ) : content && (
        <div className={`space-y-5 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          {tab === 'income' && renderIncome(content as IncomeData)}
          {tab === 'tenants' && renderTenants(content as TenantsData)}
          {tab === 'usage' && renderUsage(content as UsageData)}
        </div>
      )}
    </div>
  );
}
