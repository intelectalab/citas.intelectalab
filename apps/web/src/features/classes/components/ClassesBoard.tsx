'use client';

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarCheck, CalendarClock, Eye, Hourglass, UserCheck, Users } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import FormClass from './FormClass';
import { STATE_BADGES, shortClassDate, spotsLabel, type ClassSession, type ClassesBoardData } from '../classes';

type Tab = 'upcoming' | 'past';
type View = 'all' | 'week' | 'full' | 'waitlist';

function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function plusDays(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function ClassesBoard() {
  const vocab = useVocabulary();
  const [data, setData] = useState<ClassesBoardData | null>(null);
  const [tab, setTab] = useState<Tab>('upcoming');
  const [view, setView] = useState<View>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ state: '', professional: '', branch: '' });
  const [formOpen, setFormOpen] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/classes')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las clases.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const classes = useMemo(() => data?.classes ?? [], [data]);
  const today = todayIso();
  const weekEnd = plusDays(7);
  const upcoming = classes.filter((c) => c.state !== 'done' && c.state !== 'cancelled');
  const past = classes.filter((c) => c.state === 'done' || c.state === 'cancelled').reverse();
  const thisWeek = upcoming.filter((c) => c.date >= today && c.date <= weekEnd);
  const full = upcoming.filter((c) => c.state === 'full');
  const withWait = upcoming.filter((c) => c.waitlist > 0);
  const marked = past.filter((c) => c.state === 'done').reduce((acc, c) => ({ attended: acc.attended + c.attended, total: acc.total + c.attended + c.no_show }), { attended: 0, total: 0 });
  const attendance = marked.total ? `${Math.round((marked.attended / marked.total) * 100)} %` : '—';

  const term = searchTerm.trim().toLowerCase();
  const base = tab === 'upcoming' ? upcoming : past;
  const rows = base.filter((c) =>
    (tab !== 'upcoming' || view === 'all' || (view === 'week' && c.date >= today && c.date <= weekEnd) || (view === 'full' && c.state === 'full') || (view === 'waitlist' && c.waitlist > 0)) &&
    (!filters.state || c.state === filters.state) &&
    (!filters.professional || c.professional?.id === filters.professional) &&
    (!filters.branch || c.branch?.id === filters.branch) &&
    (!term || `${c.title} ${c.professional?.display_name ?? ''} ${c.room?.name ?? ''} ${c.branch?.name ?? ''}`.toLowerCase().includes(term)),
  );

  const card = (title: string, value: string | number, icon: React.ReactNode, id: View | null, color: string, ring: string): MetricCardItem => ({
    title, value, icon, iconColorClass: color,
    borderColorClass: id && view === id ? ring : 'hover:border-slate-300',
    onClick: id ? () => { setTab('upcoming'); setView(view === id ? 'all' : id); } : undefined,
  });

  const metricCards: MetricCardItem[] = [
    card('Clases en los próximos 7 días', thisWeek.length, <CalendarClock size={18} />, 'week', 'text-brand-accent', 'border-brand-accent ring-2 ring-brand-accent/20'),
    card('Completas', full.length, <Users size={18} />, 'full', 'text-amber-600', 'border-amber-500 ring-2 ring-amber-500/20'),
    card('Con lista de espera', withWait.length, <Hourglass size={18} />, 'waitlist', 'text-sky-600', 'border-sky-500 ring-2 ring-sky-500/20'),
    card('Asistencia del último mes', attendance, <UserCheck size={18} />, null, 'text-emerald-600', ''),
  ];

  const columns: Column<ClassSession>[] = [
    {
      header: 'Clase',
      cell: (c) => (
        <div key={`name-${c.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink"><CalendarCheck size={16} /></span>
          <div className="min-w-0">
            <Link href={`/classes/${c.id}`} className={`${styles.customerName} hover:text-brand-accent`} data-class-link={c.id}>{c.title}</Link>
            <span className="block text-xs text-slate-500 first-letter:uppercase">{shortClassDate(c.date)} · {c.time}–{c.end_time}</span>
          </div>
        </div>
      ),
    },
    { header: vocab.pro, cell: (c) => <span key={`pro-${c.id}`} className="text-sm text-slate-700">{c.professional ? <ProfessionalLink id={c.professional.id}>{c.professional.display_name}</ProfessionalLink> : '—'}</span> },
    { header: 'Lugar', cell: (c) => <span key={`room-${c.id}`} className="text-sm text-slate-700">{c.room?.name ?? 'Sin sala'}<span className="block text-xs text-slate-400">{c.branch?.name}</span></span> },
    {
      header: 'Inscritos',
      cell: (c) => (
        <div key={`spots-${c.id}`} className="min-w-[8rem]">
          <div className="h-2 rounded-full bg-slate-100 overflow-hidden">
            <div className={`h-full rounded-full ${c.taken >= c.capacity ? 'bg-amber-500' : 'bg-brand-accent'}`} style={{ width: `${Math.min(100, (c.taken / Math.max(1, c.capacity)) * 100)}%` }} />
          </div>
          <span className="block text-xs text-slate-500 mt-1">{spotsLabel(c)}{c.waitlist > 0 ? ` · ${c.waitlist} en espera` : ''}</span>
        </div>
      ),
    },
    { header: 'Estado', cell: (c) => <Badge key={`state-${c.id}`} data-state={c.state} tone={STATE_BADGES[c.state]} nowrap>{c.state === 'open' ? `${c.free} ${c.free === 1 ? 'cupo' : 'cupos'}` : c.state_label}</Badge> },
    {
      header: 'Acciones',
      cell: (c) => (
        <Link key={`act-${c.id}`} href={`/classes/${c.id}`} title={`Ver ${c.title}`} className="p-1.5 text-slate-400 rounded-lg transition-colors inline-flex hover:text-brand-ink hover:bg-slate-100">
          <Eye size={16} />
        </Link>
      ),
    },
  ];

  const exportColumns: ExportColumn<ClassSession>[] = [
    { header: 'Clase', value: (c) => c.title },
    { header: 'Fecha', type: 'date', value: (c) => c.date },
    { header: 'Hora', value: (c) => `${c.time}–${c.end_time}` },
    { header: vocab.pro, value: (c) => c.professional?.display_name },
    { header: 'Sala', value: (c) => c.room?.name ?? '' },
    { header: 'Sede', value: (c) => c.branch?.name },
    { header: 'Cupos', type: 'number', value: (c) => c.capacity },
    { header: 'Inscritos', type: 'number', value: (c) => c.taken },
    { header: 'En espera', type: 'number', value: (c) => c.waitlist },
    { header: 'Asistieron', type: 'number', value: (c) => c.attended },
    { header: 'Estado', value: (c) => c.state_label },
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'state', label: 'Estado', type: 'select', options: (tab === 'upcoming' ? [['open', 'Con cupos'], ['full', 'Completa'], ['running', 'En curso']] : [['done', 'Realizada'], ['cancelled', 'Cancelada']]).map(([value, label]) => ({ value, label })) },
    { id: 'professional', label: vocab.pro, type: 'select', options: (data?.options.professionals ?? []).map((p) => ({ value: p.id, label: p.display_name })) },
    ...((data?.options.branches.length ?? 0) > 1 ? [{ id: 'branch', label: 'Sede', type: 'select' as const, options: (data?.options.branches ?? []).map((b) => ({ value: b.id, label: b.name })) }] : []),
  ];

  const tabs = [
    { id: 'upcoming', label: 'Próximas', count: upcoming.length },
    { id: 'past', label: 'Pasadas y canceladas', count: past.length },
  ];

  return (
    <div className="space-y-6 mb-8" data-classes>
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <DataTable<ClassSession>
        title="Clases"
        primaryActionLabel="Programar clase"
        onPrimaryAction={data?.can.create ? () => { setNotice(''); setFormOpen(true); } : undefined}
        tabs={tabs}
        activeTab={tab}
        onTabChange={(id) => { setTab(id as Tab); setView('all'); setFilters({ state: '', professional: '', branch: '' }); }}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
        dateFilters={[{ id: 'date', label: 'Fecha', value: (c) => c.date, future: tab === 'upcoming' }]}
        sortOptions={[
          { id: 'date', label: 'Fecha', type: 'date', value: (c) => c.starts_at },
          { id: 'taken', label: 'Más inscritos', type: 'number', value: (c) => c.taken },
          { id: 'title', label: 'Clase', value: (c) => c.title },
        ]}
        columns={columns}
        data={rows}
        exportTitle="Clases"
        exportScreen="classes"
        exportColumns={exportColumns}
        getItemKey={(c) => c.id}
        entityLabel={rows.length === 1 ? 'clase' : 'clases'}
        metricCards={metricCards}
      />

      {data && classes.length === 0 && (
        <p className="text-sm text-slate-500 px-1">
          {data.options.services.length === 0
            ? <>Para empezar, crea la clase en <Link href="/services" className="text-brand-accent hover:underline">Servicios</Link> y marca que es grupal, con su cupo. Luego prográmala aquí.</>
            : 'Aún no hay clases programadas. Pulsa "Programar clase": puedes repetirla cada semana.'}
        </p>
      )}

      <FormClass
        isOpen={formOpen}
        onClose={() => setFormOpen(false)}
        initialData={null}
        options={data?.options ?? null}
        maxWeeks={data?.max_weeks}
        onSaved={(message) => { setNotice(message); load(); }}
      />
    </div>
  );
}
