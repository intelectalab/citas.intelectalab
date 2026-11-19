'use client';

import Link from 'next/link';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { BadgeCheck, CalendarX, Edit2, Eye, Hourglass, Trash2, Users, Wallet } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import FormMembershipPlan from './FormMembershipPlan';
import { STATUS_BADGES, daysLeftLabel, includedLeftText, shortDate, type CustomerMembership, type MembershipPlan, type MembershipsBoardData } from '../memberships';

type Tab = 'members' | 'plans';
type View = 'all' | 'active' | 'expiring' | 'expired';

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function MembershipsBoard() {
  const router = useRouter();
  const [data, setData] = useState<MembershipsBoardData | null>(null);
  const [tab, setTab] = useState<Tab>('members');
  const [view, setView] = useState<View>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ status: '', plan: '' });
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<MembershipPlan | null>(null);
  const [deleting, setDeleting] = useState<MembershipPlan | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/memberships')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las membresías.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const memberships = data?.memberships ?? [];
  const plans = data?.plans ?? [];
  const currency = data?.currency;
  const term = searchTerm.trim().toLowerCase();
  const active = memberships.filter((m) => m.status === 'active' || m.status === 'grace');
  const expiring = memberships.filter((m) => m.status === 'active' && m.days_left <= 7);
  const expired = memberships.filter((m) => m.status === 'expired');
  const monthly = active.reduce((sum, m) => sum + m.price / Math.max(1, m.months), 0);

  const memberRows = memberships.filter((m) =>
    (view === 'all' || (view === 'active' && (m.status === 'active' || m.status === 'grace')) || (view === 'expiring' && m.status === 'active' && m.days_left <= 7) || (view === 'expired' && m.status === 'expired')) &&
    (!filters.status || m.status === filters.status) &&
    (!filters.plan || m.name === filters.plan) &&
    (!term || `${m.code} ${m.name} ${m.customer?.name ?? ''}`.toLowerCase().includes(term)),
  );

  const planRows = plans.filter((p) => !term || `${p.name} ${p.benefits_text}`.toLowerCase().includes(term));

  const switchTab = (id: string) => { setTab(id as Tab); setSearchTerm(''); setView('all'); setError(''); setNotice(''); };
  const tabs = [
    { id: 'members', label: 'Socios', count: memberships.length },
    { id: 'plans', label: 'Planes', count: plans.length },
  ];

  const openPlan = (plan: MembershipPlan | null) => { setError(''); setNotice(''); setEditing(plan); setFormOpen(true); };

  const remove = async () => {
    if (!deleting) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.delete(`/membership-plans/${deleting.id}`);
      setNotice(res.data?.kept
        ? `${deleting.name} ya se vendió: se quitó de los planes y las membresías vendidas siguen igual.`
        : `${deleting.name} se eliminó.`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo quitar el plan.'));
    } finally {
      setBusy(false);
      setDeleting(null);
    }
  };

  const card = (title: string, value: string | number, icon: React.ReactNode, id: View | null, color: string, ring: string): MetricCardItem => ({
    title,
    value,
    icon,
    iconColorClass: color,
    borderColorClass: id && view === id ? ring : 'hover:border-slate-300',
    onClick: id ? () => setView(view === id ? 'all' : id) : undefined,
  });

  const metricCards: MetricCardItem[] = tab === 'members' ? [
    card('Socios vigentes', active.length, <Users size={18} />, 'active', 'text-brand-accent', 'border-brand-accent ring-2 ring-brand-accent/20'),
    card('Ingreso recurrente al mes', money(monthly, currency), <Wallet size={18} />, null, 'text-emerald-600', 'border-emerald-500 ring-2 ring-emerald-500/20'),
    card('Vencen en 7 días', expiring.length, <Hourglass size={18} />, 'expiring', 'text-amber-600', 'border-amber-500 ring-2 ring-amber-500/20'),
    card('Vencidas', expired.length, <CalendarX size={18} />, 'expired', 'text-slate-500', 'border-slate-400 ring-2 ring-slate-400/20'),
  ] : [];

  const memberExport: ExportColumn<CustomerMembership>[] = [
    { header: 'Código', value: (m) => m.code },
    { header: 'Socio', value: (m) => m.customer?.name },
    { header: 'Plan', value: (m) => m.name },
    { header: 'Periodo', value: (m) => m.period_label },
    { header: 'Beneficios', value: (m) => m.benefits_text },
    { header: 'Inicio', type: 'date', value: (m) => m.starts_at },
    { header: 'Vence', type: 'date', value: (m) => m.ends_at },
    { header: 'Usos', type: 'number', value: (m) => m.uses },
    { header: 'Precio', type: 'money', total: true, value: (m) => m.price },
    { header: 'Estado', value: (m) => m.status_label },
  ];

  const planExport: ExportColumn<MembershipPlan>[] = [
    { header: 'Plan', value: (p) => p.name },
    { header: 'Periodo', value: (p) => p.period_label },
    { header: 'Precio', type: 'money', value: (p) => p.price },
    { header: 'Descuento (%)', type: 'number', value: (p) => p.discount_percent },
    { header: 'Beneficios', value: (p) => p.benefits_text },
    { header: 'Socios vigentes', type: 'number', value: (p) => p.members },
    { header: 'Estado', value: (p) => (p.status === 'active' ? 'Se vende' : 'Apagado') },
  ];

  const memberColumns: Column<CustomerMembership>[] = [
    {
      header: 'Socio',
      cell: (m) => (
        <div key={`name-${m.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink"><BadgeCheck size={16} /></span>
          <div className="min-w-0">
            <span className={styles.customerName} data-membership={m.code}>{m.customer ? <CustomerLink id={m.customer.id}>{m.customer.name}</CustomerLink> : '—'}</span>
            <Link href={`/memberships/${m.id}`} data-membership-link={m.code} className="block text-xs text-slate-400 hover:text-brand-accent hover:underline"><span className="font-mono">{m.code}</span> · {m.name}</Link>
          </div>
        </div>
      ),
    },
    {
      header: 'Este periodo',
      cell: (m) => (
        <div key={`use-${m.id}`} className="text-xs text-slate-600 max-w-[16rem]">
          {m.included.length ? includedLeftText(m.included) : <span className="text-slate-400">{m.status === 'active' || m.status === 'grace' ? 'Sin servicios incluidos' : '—'}</span>}
          {m.period_end && m.included.length > 0 && <span className="block text-slate-400">Periodo hasta el {shortDate(m.period_end)}</span>}
        </div>
      ),
    },
    {
      header: 'Vigencia',
      cell: (m) => (
        <div key={`days-${m.id}`} className="text-sm">
          <span className={`block font-medium ${(m.status === 'active' && m.days_left <= 7) || m.status === 'grace' ? 'text-amber-700' : 'text-slate-700'}`}>{daysLeftLabel(m)}</span>
          <span className="block text-xs text-slate-400">Desde el {shortDate(m.starts_at)}</span>
        </div>
      ),
    },
    { header: 'Precio', cell: (m) => <span key={`price-${m.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{money(m.price, m.currency)} <span className="block text-xs font-normal text-slate-400">{m.period_label}</span></span> },
    { header: 'Estado', cell: (m) => <Badge key={`status-${m.id}`} data-status={m.status} tone={STATUS_BADGES[m.status]} nowrap>{m.status_label}</Badge> },
    {
      header: 'Acciones',
      cell: (m) => (
        <Link key={`actions-${m.id}`} href={`/memberships/${m.id}`} title={`Ver ${m.code}`} className={`${iconButton} inline-flex hover:text-brand-ink hover:bg-slate-100`}>
          <Eye size={16} />
        </Link>
      ),
    },
  ];

  const planColumns: Column<MembershipPlan>[] = [
    {
      header: 'Plan',
      cell: (p) => (
        <div key={`plan-${p.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink"><BadgeCheck size={16} /></span>
          <div className="min-w-0">
            <button type="button" data-edit-plan={p.name} disabled={!data?.can.edit} onClick={() => openPlan(p)} className={`${styles.customerName} hover:text-brand-accent transition-colors text-left disabled:hover:text-inherit`}>{p.name}</button>
            <span className="block text-xs text-slate-400">{p.benefits_text}</span>
          </div>
        </div>
      ),
    },
    { header: 'Precio', cell: (p) => <span key={`price-${p.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{money(p.price, p.currency)} <span className="text-xs font-normal text-slate-500">{p.per_label}</span></span> },
    { header: 'Descuento', cell: (p) => <span key={`disc-${p.id}`} className="text-sm text-slate-700 tabular-nums">{p.discount_percent > 0 ? `${p.discount_percent} %` : '—'}</span> },
    { header: 'Socios vigentes', cell: (p) => <span key={`members-${p.id}`} className="text-sm text-slate-700 tabular-nums">{p.members}</span> },
    { header: 'Estado', cell: (p) => <Badge key={`status-${p.id}`} tone={p.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}>{p.status === 'active' ? 'Se vende' : 'Apagado'}</Badge> },
    {
      header: 'Acciones',
      cell: (p) => (
        <div key={`actions-${p.id}`} className="flex items-center gap-1">
          {data?.can.edit && <button title={`Editar ${p.name}`} onClick={() => openPlan(p)} className={`${iconButton} hover:text-brand-ink hover:bg-slate-100`}><Edit2 size={16} /></button>}
          {data?.can.delete && <button data-delete-plan={p.name} title={`Quitar ${p.name}`} onClick={() => { setError(''); setNotice(''); setDeleting(p); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}><Trash2 size={16} /></button>}
        </div>
      ),
    },
  ];

  const memberFilters: FilterConfig[] = [
    { id: 'status', label: 'Estado', type: 'select', options: (data?.options.statuses ?? []).map((s) => ({ value: s.value, label: s.label })) },
    { id: 'plan', label: 'Plan', type: 'select', options: [...new Set(memberships.map((m) => m.name))].map((n) => ({ value: n, label: n })) },
  ];

  return (
    <div className="space-y-6 mb-8" data-memberships>
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {tab === 'members' ? (
        <DataTable<CustomerMembership>
          title="Membresías"
          primaryActionLabel="Vender membresía"
          onPrimaryAction={data?.can.sell ? () => router.push('/pos?tab=membresias') : undefined}
          tabs={tabs}
          activeTab={tab}
          onTabChange={switchTab}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={memberFilters}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          dateFilters={[{ id: 'starts_at', label: 'Inicio', value: (m) => m.starts_at }, { id: 'ends_at', label: 'Vence', value: (m) => m.ends_at, future: true }]}
          sortOptions={[
            { id: 'ends_at', label: 'Vencimiento', type: 'date', value: (m) => m.ends_at },
            { id: 'starts_at', label: 'Inicio', type: 'date', value: (m) => m.starts_at },
            { id: 'customer', label: 'Socio', value: (m) => m.customer?.name ?? '' },
          ]}
          columns={memberColumns}
          data={memberRows}
          exportTitle="Membresías"
          exportScreen="memberships"
          exportFilters={view === 'all' ? [] : [`Tarjeta: ${{ active: 'Socios vigentes', expiring: 'Vencen en 7 días', expired: 'Vencidas' }[view]}`]}
          exportColumns={memberExport}
          getItemKey={(m) => m.id}
          entityLabel={memberRows.length === 1 ? 'membresía' : 'membresías'}
          metricCards={metricCards}
        />
      ) : (
        <DataTable<MembershipPlan>
          title="Membresías"
          primaryActionLabel="Nuevo plan"
          onPrimaryAction={data?.can.create ? () => openPlan(null) : undefined}
          tabs={tabs}
          activeTab={tab}
          onTabChange={switchTab}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          dateFilters={[{ id: 'created_at', label: 'Creado', value: (p) => p.created_at }]}
          sortOptions={[
            { id: 'name', label: 'Nombre', value: (p) => p.name },
            { id: 'price', label: 'Precio', type: 'number', value: (p) => p.price },
            { id: 'members', label: 'Más socios', type: 'number', value: (p) => p.members },
          ]}
          columns={planColumns}
          data={planRows}
          exportTitle="Planes de membresía"
          exportScreen="membership_plans"
          exportColumns={planExport}
          getItemKey={(p) => p.id}
          entityLabel={planRows.length === 1 ? 'plan' : 'planes'}
        />
      )}

      {data && tab === 'members' && memberships.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay membresías vendidas. Crea un plan en la pestaña Planes y véndelo desde el punto de venta.</p>
      )}
      {data && tab === 'plans' && plans.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay planes. Crea uno con &quot;Nuevo plan&quot;: por ejemplo, S/ 89 al mes con 10 % de descuento y una consulta incluida.</p>
      )}

      <FormMembershipPlan isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} options={data?.options ?? null} currency={currency} onSaved={load} />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={`¿Quitar ${deleting?.name ?? ''}?`}
        message="Si ya se vendió, se quita de los planes y las membresías vendidas siguen igual. Si solo quieres dejar de ofrecerlo, apágalo desde su panel."
        confirmText="Sí, quitar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
