'use client';

import Badge from '@/app/components/common/Badge';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ClipboardList, Eye, EyeOff, FileCheck2, FilePen, Lock } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { addDays, dateOf, formatDate, timeOf, todayISO } from '@/features/appointments/appointments';
import RecordPanel from './RecordPanel';
import { RECORD_STATUS, type CareRecord } from '../records';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';

const PERIODS: { id: string; label: string; days: number | null }[] = [
  { id: '30', label: 'Últimos 30 días', days: 30 },
  { id: '90', label: 'Últimos 90 días', days: 90 },
  { id: 'all', label: 'Todas', days: null },
];

const CARDS: { id: string; title: string; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'draft', title: 'Borradores', icon: <FilePen size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'signed', title: 'Firmadas', icon: <FileCheck2 size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20', hover: 'hover:border-emerald-300' },
  { id: 'locked', title: 'Bloqueadas', icon: <Lock size={18} />, color: 'text-slate-500', active: 'border-slate-500 ring-2 ring-slate-500/20', hover: 'hover:border-slate-400' },
];

export default function RecordsTable() {
  const router = useRouter();
  const { user } = useAuth();
  const vocab = useVocabulary();
  const [records, setRecords] = useState<CareRecord[]>([]);
  const [period, setPeriod] = useState('30');
  const [dateRange, setDateRange] = useState<{ from: string; to: string } | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('');
  const [openAppointmentId, setOpenAppointmentId] = useState<string | null>(null);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);

  const load = useCallback(() => {
    const days = PERIODS.find((p) => p.id === period)!.days;

    api.get('/care-records', {
      params: {
        ...(dateRange ? dateRange : days ? { from: addDays(todayISO(), -(days - 1)) } : {}),
        ...(query ? { search: query } : {}),
      },
    })
      .then((res) => setRecords(Array.isArray(res.data) ? res.data : []))
      .catch((error) => { console.error('Error al cargar fichas:', error); setRecords([]); });
  }, [period, dateRange, query]);

  useEffect(() => { load(); }, [load]);

  const handleSearchChange = (value: string) => {
    setSearchTerm(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setQuery(value.trim()), 300);
  };

  const rows = status ? records.filter((r) => r.status === status) : records;

  const metricCards: MetricCardItem[] = [
    {
      title: 'Fichas del periodo',
      value: records.length,
      icon: <ClipboardList size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !status ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setStatus(''),
    },
    ...CARDS.map((card) => ({
      title: card.title,
      value: records.filter((r) => r.status === card.id).length,
      icon: card.icon,
      iconColorClass: card.color,
      borderColorClass: status === card.id ? card.active : card.hover,
      onClick: () => setStatus(status === card.id ? '' : card.id),
    })),
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'status', label: 'Estado', type: 'select', options: Object.entries(RECORD_STATUS).map(([value, item]) => ({ value, label: item.label })) },
  ];

  const columns: Column<CareRecord>[] = [
    {
      header: 'Fecha',
      cell: (r) => {
        const date = r.appointment?.starts_at ?? r.created_at;
        return (
          <div key={`date-${r.id}`}>
            <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">
              {formatDate(dateOf(date), { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })}
            </span>
            <span className="block text-xs text-slate-500">{timeOf(date)}{r.appointment ? ` · ${r.appointment.code}` : ''}</span>
          </div>
        );
      },
    },
    {
      header: vocab.client,
      cell: (r) => (
        <div key={`customer-${r.id}`}>
          <CustomerLink id={r.customer?.id} className={styles.customerName}>{r.customer?.name ?? '—'}</CustomerLink>
          <button type="button" data-open={r.id} onClick={() => setOpenAppointmentId(r.appointment_id)} className="block text-left text-xs text-slate-400 hover:text-[#3F768C] transition-colors">
            {usesDependents && r.dependent ? `${vocab.dependent}: ${r.dependent.name}` : r.customer?.code} · ver ficha
          </button>
        </div>
      ),
    },
    {
      header: 'Ficha',
      cell: (r) => (
        <div key={`template-${r.id}`}>
          <span className="flex items-center gap-1.5 text-sm text-slate-700">
            {r.template_name ?? '—'}
            {r.is_private && <span title="Privada: solo la ve quien la escribió"><EyeOff size={13} className="text-violet-600" /></span>}
          </span>
          <span className="block text-xs text-slate-400">{r.appointment?.service ?? ''}</span>
        </div>
      ),
    },
    {
      header: vocab.pro,
      cell: (r) => <ProfessionalLink key={`pro-${r.id}`} id={r.professional ? r.professional_id : null} className="text-sm text-slate-700">{r.professional ?? r.author ?? '—'}</ProfessionalLink>,
    },
    {
      header: 'Estado',
      cell: (r) => (
        <Badge key={`status-${r.id}`} tone={RECORD_STATUS[r.status]?.badge ?? ''}>
          {RECORD_STATUS[r.status]?.label ?? r.status}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (r) => (
        <button
          key={`actions-${r.id}`}
          onClick={() => setOpenAppointmentId(r.appointment_id)}
          disabled={!r.appointment_id}
          title="Ver la ficha"
          className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors disabled:opacity-30"
        >
          <Eye size={16} />
        </button>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<CareRecord>
          title="Fichas de atención"
          primaryActionLabel="Plantillas de ficha"
          onPrimaryAction={() => router.push('/records/templates')}
          tabs={PERIODS.map((p) => ({ id: p.id, label: p.label, count: p.id === period ? records.length : undefined }))}
          activeTab={period}
          onTabChange={(id) => { setPeriod(id); setStatus(''); }}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={{ status }}
          onFilterChange={(_, value) => setStatus(value)}
          columns={columns}
          dateFilters={[
            { id: 'created_at', label: 'Fecha de la ficha', value: (r) => r.created_at, onRange: setDateRange },
          ]}
          sortOptions={[
            { id: 'created_at', label: 'Fecha', type: 'date', value: (r) => r.created_at },
            { id: 'customer', label: vocab.client, value: (r) => r.customer?.name },
            { id: 'template', label: 'Ficha', value: (r) => r.template_name },
            { id: 'professional', label: vocab.pro, value: (r) => r.professional },
            { id: 'status', label: 'Estado', value: (r) => r.status },
          ]}
          data={rows}
          getItemKey={(r) => r.id}
          entityLabel="fichas"
          metricCards={metricCards}
        />
      </div>

      <RecordPanel appointmentId={openAppointmentId} onClose={() => setOpenAppointmentId(null)} onChanged={load} />
    </div>
  );
}
