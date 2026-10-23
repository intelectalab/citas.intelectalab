'use client';

import Badge from '@/app/components/common/Badge';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarDays, CalendarX2, CheckCircle, Eye, Hourglass, Stethoscope, ThumbsUp } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAppointmentPanels } from './useAppointmentPanels';
import { useAutoRefresh } from './useAutoRefresh';
import { RELEASED, addDays, dateOf, formatDate, statusStyle, timeOf, todayISO, type Appointment } from '../appointments';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { formatMoney } from '@/lib/currency';

const PERIODS: { id: string; label: string; range: () => { from: string; to: string } }[] = [
  { id: 'today', label: 'Hoy', range: () => ({ from: todayISO(), to: todayISO() }) },
  { id: 'week', label: 'Próximos 7 días', range: () => ({ from: todayISO(), to: addDays(todayISO(), 7) }) },
  { id: 'month', label: 'Próximos 30 días', range: () => ({ from: todayISO(), to: addDays(todayISO(), 30) }) },
  { id: 'past', label: 'Últimos 30 días', range: () => ({ from: addDays(todayISO(), -30), to: addDays(todayISO(), -1) }) },
];

const CARDS: { id: string; title: string; statuses: string[]; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'pending', title: 'Pendientes', statuses: ['pending'], icon: <Hourglass size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'confirmed', title: 'Confirmadas', statuses: ['confirmed'], icon: <ThumbsUp size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
  { id: 'ongoing', title: 'En curso', statuses: ['checked_in', 'in_progress'], icon: <Stethoscope size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20', hover: 'hover:border-violet-300' },
  { id: 'completed', title: 'Atendidas', statuses: ['completed'], icon: <CheckCircle size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20', hover: 'hover:border-emerald-300' },
  { id: 'released', title: 'Canceladas y ausencias', statuses: RELEASED, icon: <CalendarX2 size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20', hover: 'hover:border-rose-300' },
];

export default function AppointmentsTable() {
  const { user, can } = useAuth();
  const vocab = useVocabulary();
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [professionals, setProfessionals] = useState<{ id: string; display_name: string }[]>([]);
  const [period, setPeriod] = useState('week');
  const [dateRange, setDateRange] = useState<{ from: string; to: string } | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ status: '', professional_id: '' });
  const [activeCard, setActiveCard] = useState('');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [query, setQuery] = useState('');

  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);
  const statusOptions = (user?.tenant?.appointment_statuses ?? []).map((s) => ({ label: s.name, value: s.key }));

  const load = useCallback(() => {
    const range = dateRange ?? PERIODS.find((p) => p.id === period)!.range();

    api.get('/appointments', {
      params: {
        ...range,
        ...(query ? { search: query } : {}),
        ...(filters.professional_id ? { professional_id: filters.professional_id } : {}),
      },
    })
      .then((res) => setAppointments(Array.isArray(res.data) ? res.data : []))
      .catch((error) => { console.error('Error al cargar citas:', error); setAppointments([]); });
  }, [period, dateRange, query, filters.professional_id]);

  const { openNew, openDetail, panels, isOpen } = useAppointmentPanels(load);

  useEffect(() => { load(); }, [load]);

  useAutoRefresh(load, isOpen, 30000);

  useEffect(() => {
    api.get('/appointments/context')
      .then((res) => setProfessionals(res.data.professionals ?? []))
      .catch(() => setProfessionals([]));
  }, []);

  const handleSearchChange = (value: string) => {
    setSearchTerm(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setQuery(value.trim()), 300);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    setFilters((current) => ({ ...current, [filterId]: value }));
    if (filterId === 'status') setActiveCard('');
  };

  const toggleCard = (id: string) => {
    setActiveCard((current) => (current === id ? '' : id));
    setFilters((current) => ({ ...current, status: '' }));
  };

  const card = CARDS.find((c) => c.id === activeCard);
  const rows = appointments.filter((a) =>
    card ? card.statuses.includes(a.status) : filters.status ? a.status === filters.status : true,
  );

  const metricCards: MetricCardItem[] = [
    {
      title: 'Citas del periodo',
      value: appointments.length,
      icon: <CalendarDays size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard && !filters.status ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => { setActiveCard(''); setFilters((current) => ({ ...current, status: '' })); },
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: appointments.filter((a) => item.statuses.includes(a.status)).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => toggleCard(item.id),
    })),
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'status', label: 'Fase', type: 'select', options: statusOptions },
    { id: 'professional_id', label: vocab.pro, type: 'select', options: professionals.map((p) => ({ label: p.display_name, value: p.id })) },
  ];

  const exportColumns: ExportColumn<Appointment>[] = [
    { header: 'Cita', value: (a) => a.code },
    { header: 'Fecha y hora', type: 'datetime', value: (a) => a.starts_at },
    { header: 'Termina', value: (a) => timeOf(a.ends_at) },
    { header: vocab.client, value: (a) => a.customer?.name },
    { header: 'Teléfono', value: (a) => a.customer?.phone },
    ...(usesDependents ? [{ header: vocab.dependent, value: (a: Appointment) => a.dependent?.name }] : []),
    { header: 'Servicio', value: (a) => a.service?.name },
    { header: 'Duración (min)', type: 'number', value: (a) => a.duration_minutes },
    { header: 'Precio', type: 'money', value: (a) => a.price },
    { header: vocab.pro, value: (a) => a.professional?.display_name },
    { header: 'Fase', value: (a) => a.status_name },
    { header: 'Sin confirmar a tiempo', value: (a) => (a.confirmation_overdue ? 'Sí' : '') },
  ];

  const columns: Column<Appointment>[] = [
    {
      header: 'Cita',
      cell: (a) => (
        <button
          key={`code-${a.id}`}
          type="button"
          data-open={a.id}
          onClick={() => openDetail(a.id)}
          className="text-sm font-bold font-mono text-[#022340] hover:text-[#3F768C] transition-colors"
        >
          {a.code}
        </button>
      ),
    },
    {
      header: 'Fecha y hora',
      cell: (a) => (
        <div key={`date-${a.id}`}>
          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">
            {formatDate(dateOf(a.starts_at), { weekday: 'short', day: '2-digit', month: 'short' })}
          </span>
          <span className="block text-xs text-slate-500">{timeOf(a.starts_at)} a {timeOf(a.ends_at)}</span>
        </div>
      ),
    },
    {
      header: vocab.client,
      cell: (a) => (
        <div key={`customer-${a.id}`}>
          <CustomerLink id={a.customer?.id} className={styles.customerName}>{a.customer?.name ?? '—'}</CustomerLink>
          <span className="block text-xs text-slate-400">
            {usesDependents && a.dependent ? `${vocab.dependent}: ${a.dependent.name}` : a.customer?.phone ?? a.customer?.code}
          </span>
        </div>
      ),
    },
    {
      header: 'Servicio',
      cell: (a) => (
        <div key={`service-${a.id}`}>
          <span className="block text-sm text-slate-700">{a.service?.name ?? '—'}</span>
          <span className="block text-xs text-slate-400">{a.duration_minutes} min · {formatMoney(a.price, a.currency)}</span>
        </div>
      ),
    },
    {
      header: vocab.pro,
      cell: (a) => <ProfessionalLink key={`pro-${a.id}`} id={a.professional?.id} className="text-sm text-slate-700">{a.professional?.display_name ?? '—'}</ProfessionalLink>,
    },
    {
      header: 'Fase',
      cell: (a) => (
        <div key={`status-${a.id}`}>
          <Badge tone={statusStyle(a.status).badge} nowrap>
            {a.status_name}
          </Badge>
          {a.confirmation_overdue && (
            <span data-overdue className="block mt-1.5 text-[11px] font-semibold text-rose-600 whitespace-nowrap" title="Venció el plazo para confirmar y sigue pendiente">
              Sin confirmar a tiempo
            </span>
          )}
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (a) => (
        <button
          key={`actions-${a.id}`}
          onClick={() => openDetail(a.id)}
          title="Ver la cita"
          className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
        >
          <Eye size={16} />
        </button>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<Appointment>
          title="Citas"
          primaryActionLabel="Nueva cita"
          onPrimaryAction={can('agenda.create') ? () => openNew() : undefined}
          tabs={PERIODS.map((p) => ({ id: p.id, label: p.label, count: p.id === period ? appointments.length : undefined }))}
          activeTab={dateRange ? '' : period}
          onTabChange={(id) => { setPeriod(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          columns={columns}
          dateFilters={[
            { id: 'starts_at', label: 'Fecha de la cita', future: true, value: (a) => a.starts_at, onRange: setDateRange, clearOnTab: true },
          ]}
          sortOptions={[
            { id: 'starts_at', label: 'Fecha y hora', type: 'date', value: (a) => a.starts_at },
            { id: 'code', label: 'Código de cita', value: (a) => a.code },
            { id: 'customer', label: vocab.client, value: (a) => a.customer?.name },
            { id: 'service', label: 'Servicio', value: (a) => a.service?.name },
            { id: 'professional', label: vocab.pro, value: (a) => a.professional?.display_name },
            { id: 'status', label: 'Fase', value: (a) => a.status_name },
            { id: 'price', label: 'Precio', type: 'number', value: (a) => Number(a.price) },
            { id: 'duration', label: 'Duración', type: 'number', value: (a) => a.duration_minutes },
          ]}
          data={rows}
          exportTitle="Citas"
          exportScreen="appointments"
          exportColumns={exportColumns}
          exportFilters={card ? [`Tarjeta: ${card.title}`] : []}
          getItemKey={(a) => a.id}
          entityLabel="citas"
          metricCards={metricCards}
        />
      </div>

      {panels}
    </div>
  );
}
