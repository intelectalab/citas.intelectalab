'use client';

import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, CalendarClock, CalendarDays, ChevronRight, DoorOpen, Edit2, PartyPopper, Trash2 } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem, type TabOption } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError, formatDate, formatDateTime, statusStyle } from '@/features/appointments/appointments';
import FormHoliday from './FormHoliday';
import { holidayDueLabel, type Holiday } from '../blocks';

interface HolidaysTableProps {
  tabs: TabOption[];
  activeTab: string;
  onTabChange: (id: string) => void;
  branches: { id: string; name: string }[];
  onCount: (count: number) => void;
}

const CARDS: { id: string; title: string; match: (h: Holiday) => boolean; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'soon', title: 'En los próximos 30 días', match: (h) => h.days_left >= 0 && h.days_left <= 30, icon: <CalendarClock size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
  { id: 'affected', title: 'Con citas ese día', match: (h) => h.affected_count > 0, icon: <AlertTriangle size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'open', title: 'Se atiende igual', match: (h) => !h.closed, icon: <DoorOpen size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20', hover: 'hover:border-emerald-300' },
];

const normalize = (value: string) => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default function HolidaysTable({ tabs, activeTab, onTabChange, branches, onCount }: HolidaysTableProps) {
  const [holidays, setHolidays] = useState<Holiday[]>([]);
  const [years, setYears] = useState<number[]>([]);
  const [missing, setMissing] = useState<number[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ year: '' });
  const [activeCard, setActiveCard] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Holiday | null>(null);
  const [deleting, setDeleting] = useState<Holiday | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [reviewing, setReviewing] = useState<Holiday | null>(null);

  const year = filters.year ? Number(filters.year) : null;

  const load = useCallback(() => {
    api.get('/holidays', { params: year ? { year } : {} })
      .then((res) => {
        const list: Holiday[] = res.data.holidays ?? [];
        setHolidays(list);
        setYears(res.data.years ?? []);
        setMissing(res.data.missing ?? []);
        setCanManage(Boolean(res.data.can_manage));
        if (!year) onCount(list.length);
      })
      .catch((err) => { console.error('Error al cargar feriados:', err); setHolidays([]); });
  }, [year, onCount]);

  const openReview = useCallback((id: string) => {
    api.get(`/holidays/${id}`)
      .then((res) => setReviewing(res.data))
      .catch(() => setReviewing(null));
  }, []);

  const reviewingId = reviewing?.id;
  const refresh = useCallback(() => {
    load();
    if (reviewingId) openReview(reviewingId);
  }, [load, openReview, reviewingId]);

  const { openDetail, panels, isOpen: appointmentOpen } = useAppointmentPanels(refresh);

  useEffect(() => { load(); }, [load]);

  const loadNational = async (target: number) => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/holidays/national', { year: target });
      setNotice(res.data.message ?? '');
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudieron cargar los feriados nacionales.'));
    } finally {
      setBusy(false);
    }
  };

  const confirmDelete = async () => {
    if (!deleting) return;

    try {
      setBusy(true);
      setError('');
      await api.delete(`/holidays/${deleting.id}`);
      setDeleting(null);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el feriado.'));
      setDeleting(null);
    } finally {
      setBusy(false);
    }
  };

  const card = CARDS.find((c) => c.id === activeCard);
  const term = normalize(searchTerm.trim());
  const rows = holidays.filter((h) =>
    (!card || card.match(h)) && (!term || normalize(`${h.name} ${h.branch?.name ?? 'todas las sedes'}`).includes(term)),
  );

  const metricCards: MetricCardItem[] = [
    {
      title: year ? `Feriados de ${year}` : 'Feriados que vienen',
      value: holidays.length,
      icon: <PartyPopper size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: holidays.filter(item.match).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => setActiveCard((current) => (current === item.id ? '' : item.id)),
    })),
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'year', label: 'Año completo', type: 'select', options: years.map((y) => ({ label: String(y), value: String(y) })) },
  ];

  const columns: Column<Holiday>[] = [
    {
      header: 'Fecha',
      cell: (h) => (
        <div key={`date-${h.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-rose-50 text-rose-700"><CalendarDays size={16} /></span>
          <div>
            <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">
              {formatDate(h.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
            </span>
            <span className={`block text-xs ${h.days_left < 0 ? 'text-slate-400' : h.days_left <= 7 ? 'font-semibold text-amber-700' : 'text-slate-500'}`}>
              {holidayDueLabel(h.days_left)}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Feriado',
      cell: (h) => h.can_manage ? (
        <button
          key={`name-${h.id}`}
          type="button"
          data-edit={h.id}
          onClick={() => { setEditing(h); setFormOpen(true); }}
          className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left`}
        >
          {h.name}
        </button>
      ) : <span key={`name-${h.id}`} className={styles.customerName}>{h.name}</span>,
    },
    ...(branches.length > 1 ? [{
      header: 'Sedes',
      cell: (h: Holiday) => <span key={`branch-${h.id}`} className="text-sm text-slate-700">{h.branch?.name ?? 'Todas las sedes'}</span>,
    }] : []),
    {
      header: 'Ese día',
      cell: (h) => (
        <span key={`closed-${h.id}`} data-closed={h.closed} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${h.closed ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'}`}>
          {h.closed ? 'La sede cierra' : 'Se atiende'}
        </span>
      ),
    },
    {
      header: 'Citas ese día',
      cell: (h) => h.affected_count > 0 ? (
        <button
          key={`affected-${h.id}`}
          type="button"
          data-review={h.id}
          onClick={() => openReview(h.id)}
          title="Ver las citas agendadas ese día"
          className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors"
        >
          <AlertTriangle size={13} /> {h.affected_count} por resolver
        </button>
      ) : <span key={`affected-${h.id}`} className="text-sm text-slate-400">{h.closed ? 'Ninguna' : '—'}</span>,
    },
    {
      header: 'Acciones',
      cell: (h) => h.can_manage ? (
        <div key={`actions-${h.id}`} className="flex items-center gap-1">
          <button onClick={() => { setEditing(h); setFormOpen(true); }} title="Editar" className="p-1.5 text-slate-400 hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors">
            <Edit2 size={16} />
          </button>
          <button data-delete={h.id} onClick={() => setDeleting(h)} title="Eliminar" className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
            <Trash2 size={16} />
          </button>
        </div>
      ) : <span key={`actions-${h.id}`} className="text-sm text-slate-300">—</span>,
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
      {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}

      {canManage && missing.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-missing>
          <p className="text-sm text-amber-800">
            Aún no tienes los feriados nacionales de {missing.join(' ni de ')}. Sin ellos, la agenda ofrece horarios esos días.
          </p>
          <div className="flex gap-2">
            {missing.map((target) => (
              <button
                key={target}
                type="button"
                data-load-national={target}
                disabled={busy}
                onClick={() => loadNational(target)}
                className="px-3.5 py-2 text-sm font-medium bg-white border border-amber-300 rounded-lg text-amber-800 hover:bg-amber-100 transition-colors disabled:opacity-50"
              >
                Cargar los de {target}
              </button>
            ))}
          </div>
        </div>
      )}

      <div className="mb-6">
        <DataTable<Holiday>
          title="Bloqueos y ausencias"
          primaryActionLabel="Agregar feriado"
          onPrimaryAction={canManage ? () => { setEditing(null); setFormOpen(true); } : undefined}
          tabs={tabs}
          activeTab={activeTab}
          onTabChange={onTabChange}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={(id, value) => { setFilters((current) => ({ ...current, [id]: value })); setActiveCard(''); }}
          columns={columns}
          dateFilters={[
            { id: 'date', label: 'Fecha', future: true, value: (h) => h.date },
          ]}
          sortOptions={[
            { id: 'date', label: 'Fecha', type: 'date', value: (h) => h.date },
            { id: 'name', label: 'Feriado', value: (h) => h.name },
            { id: 'branch', label: 'Sede', value: (h) => h.branch?.name },
            { id: 'affected', label: 'Citas ese día', type: 'number', value: (h) => h.affected_count },
          ]}
          data={rows}
          getItemKey={(h) => h.id}
          entityLabel="feriados"
          metricCards={metricCards}
        />
      </div>

      <FormHoliday isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} branches={branches} onSaved={load} />

      <FormDrawer
        isOpen={Boolean(reviewing) && !appointmentOpen}
        onClose={() => setReviewing(null)}
        title={`Citas el feriado: ${reviewing?.name ?? ''}`}
        subtitle={reviewing ? formatDate(reviewing.date) : ''}
        icon={<AlertTriangle size={20} />}
      >
        {reviewing && (
          <div className="space-y-4" data-review-panel>
            {(reviewing.affected ?? []).length === 0 ? (
              <p className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">
                Ya no quedan citas agendadas ese día.
              </p>
            ) : (
              <>
                <p className="text-sm text-slate-600">
                  Estas citas siguen en la agenda aunque ese día la sede cierra. Abre cada una para reprogramarla o cancelarla.
                </p>
                <ul className="border border-slate-200 rounded-xl divide-y divide-slate-100">
                  {(reviewing.affected ?? []).map((a) => (
                    <li key={a.id}>
                      <button
                        type="button"
                        data-open-appointment={a.id}
                        onClick={() => openDetail(a.id)}
                        className="w-full flex items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50 transition-colors"
                      >
                        <span>
                          <span className="block text-sm font-semibold text-slate-800">
                            <span className="font-mono text-[#022340]">{a.code}</span> · {a.customer ?? '—'}
                          </span>
                          <span className="block text-xs text-slate-500">
                            {formatDateTime(a.starts_at)} · {a.service}{a.professional ? ` · ${a.professional}` : ''}
                          </span>
                        </span>
                        <span className="flex items-center gap-2 shrink-0">
                          <span className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${statusStyle(a.status).badge}`}>{a.status_name}</span>
                          <ChevronRight size={16} className="text-slate-400" />
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        )}
      </FormDrawer>

      {panels}

      <AppModal
        isOpen={Boolean(deleting)}
        variant="danger"
        title="Eliminar feriado"
        message={`Se eliminará "${deleting?.name ?? ''}" (${deleting ? formatDate(deleting.date) : ''}). Ese día la agenda volverá a ofrecer horarios.`}
        confirmText="Sí, eliminar"
        cancelText="No, mantenerlo"
        isLoading={busy}
        onConfirm={confirmDelete}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
