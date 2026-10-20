'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import { BadgeCheck, CalendarX2, Edit2, TicketPercent, Trash2 } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { formatDay } from '@/app/components/ui/table/dateFilters';
import { money, type Coupon } from '@/features/sales/sales';
import FormCoupon from './FormCoupon';
import { useAuth } from '@/hooks/useAuth';

const STATES: Record<Coupon['state'], { label: string; badge: string }> = {
  valid: { label: 'Vigente', badge: 'bg-emerald-50 text-emerald-600' },
  scheduled: { label: 'Aún no empieza', badge: 'bg-sky-50 text-sky-700' },
  expired: { label: 'Vencido', badge: 'bg-slate-100 text-slate-500' },
  used_up: { label: 'Agotado', badge: 'bg-amber-50 text-amber-700' },
  inactive: { label: 'Desactivado', badge: 'bg-slate-100 text-slate-500' },
};

const VIEWS = [
  { id: 'all', label: 'Todos' },
  { id: 'valid', label: 'Vigentes' },
  { id: 'closed', label: 'Ya no valen' },
];

const isClosed = (coupon: Coupon) => coupon.state !== 'valid' && coupon.state !== 'scheduled';
const benefitOf = (coupon: Coupon) => (coupon.discount_type === 'percent' ? `${coupon.discount_value} %` : money(coupon.discount_value));
const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function CouponsTable() {
  const { can } = useAuth();
  const [coupons, setCoupons] = useState<Coupon[] | null>(null);
  const [view, setView] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ type: '' });
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Coupon | null>(null);
  const [deleting, setDeleting] = useState<Coupon | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/coupons')
      .then((res) => { setCoupons(res.data.coupons); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los cupones.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const all = coupons ?? [];
  const term = searchTerm.trim().toLowerCase();

  const rows = all.filter((coupon) =>
    (view === 'all' || (view === 'valid' ? coupon.state === 'valid' : isClosed(coupon))) &&
    (!filters.type || coupon.discount_type === filters.type) &&
    (!term || `${coupon.code} ${coupon.description ?? ''}`.toLowerCase().includes(term)),
  );

  const remove = async () => {
    if (!deleting) return;

    try {
      setBusy(true);
      setError('');
      const res = await api.delete(`/coupons/${deleting.id}`);
      setNotice(res.data?.deactivated ? `${deleting.code} ya se usó en ventas: se desactivó en lugar de eliminarse.` : `${deleting.code} se eliminó.`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el cupón.'));
    } finally {
      setBusy(false);
      setDeleting(null);
    }
  };

  const open = (coupon: Coupon | null) => { setError(''); setNotice(''); setEditing(coupon); setFormOpen(true); };

  const filterConfigs: FilterConfig[] = [
    { id: 'type', label: 'Descuento', type: 'select', options: [{ label: 'Porcentaje', value: 'percent' }, { label: 'Monto fijo', value: 'amount' }] },
  ];

  const metricCards: MetricCardItem[] = [
    {
      title: 'Vigentes',
      value: all.filter((c) => c.state === 'valid').length,
      icon: <BadgeCheck size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: view === 'valid' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'valid' ? 'all' : 'valid'),
    },
    {
      title: 'Veces usados',
      value: all.reduce((sum, c) => sum + c.used_count, 0),
      icon: <TicketPercent size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: view === 'all' ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
      onClick: () => setView('all'),
    },
    {
      title: 'Ya no valen',
      value: all.filter(isClosed).length,
      icon: <CalendarX2 size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: view === 'closed' ? 'border-slate-500 ring-2 ring-slate-500/20' : 'hover:border-slate-300',
      onClick: () => setView(view === 'closed' ? 'all' : 'closed'),
    },
  ];

  const columns: Column<Coupon>[] = [
    {
      header: 'Cupón',
      cell: (coupon) => (
        <div key={`code-${coupon.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-[#84D0D9]/20 text-[#022340]"><TicketPercent size={16} /></span>
          <div>
            <button type="button" data-edit={coupon.code} onClick={() => open(coupon)} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left tracking-wide`}>
              {coupon.code}
            </button>
            <span className="block text-xs text-slate-400">{coupon.description || 'Sin descripción'}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Descuenta',
      cell: (coupon) => (
        <div key={`benefit-${coupon.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{benefitOf(coupon)}</span>
          <span className="block text-xs text-slate-400">{coupon.min_purchase > 0 ? `Desde ${money(coupon.min_purchase)}` : 'Sin compra mínima'}</span>
        </div>
      ),
    },
    {
      header: 'Vigencia',
      cell: (coupon) => (
        <span key={`validity-${coupon.id}`} className="text-sm text-slate-700">
          {!coupon.starts_on && !coupon.ends_on
            ? 'Siempre'
            : `${coupon.starts_on ? formatDay(coupon.starts_on) : 'Desde ya'} – ${coupon.ends_on ? formatDay(coupon.ends_on) : 'sin fin'}`}
        </span>
      ),
    },
    {
      header: 'Usos',
      cell: (coupon) => (
        <span key={`uses-${coupon.id}`} className="text-sm text-slate-700 tabular-nums">
          {coupon.used_count} de {coupon.max_uses ?? '∞'}
        </span>
      ),
    },
    {
      header: 'Estado',
      cell: (coupon) => (
        <Badge key={`state-${coupon.id}`} data-state={coupon.state} tone={STATES[coupon.state].badge} nowrap>
          {STATES[coupon.state].label}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (coupon) => (
        <div key={`actions-${coupon.id}`} className="flex items-center gap-1">
          {can('coupons.edit') && (<button title={`Editar ${coupon.code}`} onClick={() => open(coupon)} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
            <Edit2 size={16} />
          </button>)}
          {can('coupons.delete') && (<button data-delete={coupon.code} title={`Eliminar ${coupon.code}`} onClick={() => { setError(''); setNotice(''); setDeleting(coupon); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
            <Trash2 size={16} />
          </button>)}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <DataTable<Coupon>
        title="Cupones"
        primaryActionLabel="Nuevo cupón"
        onPrimaryAction={can('coupons.create') ? () => open(null) : undefined}
        tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'all' ? all.length : all.filter((c) => (v.id === 'valid' ? c.state === 'valid' : isClosed(c))).length }))}
        activeTab={view}
        onTabChange={setView}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
        dateFilters={[{ id: 'ends_on', label: 'Vence', future: true, value: (c) => c.ends_on }]}
        sortOptions={[
          { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (c) => c.created_at },
          { id: 'code', label: 'Código', value: (c) => c.code },
          { id: 'used', label: 'Veces usado', type: 'number', value: (c) => c.used_count },
          { id: 'ends_on', label: 'Vencimiento', type: 'date', value: (c) => c.ends_on },
        ]}
        columns={columns}
        data={rows}
        getItemKey={(c) => c.id}
        entityLabel={rows.length === 1 ? 'cupón' : 'cupones'}
        metricCards={metricCards}
      />

      {coupons && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay cupones. Crea uno con "Nuevo cupón" y el cliente lo dice al pagar: se escribe en el campo "Cupón" al cobrar.</p>
      )}

      <FormCoupon isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} onSaved={load} />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={`¿Eliminar ${deleting?.code ?? ''}?`}
        message="Si ya se usó en alguna venta no se elimina: se desactiva para conservar su historia."
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
