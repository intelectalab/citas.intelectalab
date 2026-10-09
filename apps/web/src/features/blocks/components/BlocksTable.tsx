'use client';

import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Building2, CalendarClock, CalendarOff, ChevronRight, Edit2, PlayCircle, Trash2, UserRound } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError, formatDateTime, statusStyle } from '@/features/appointments/appointments';
import FormBlock from './FormBlock';
import { KIND_STYLES, STATE_LABELS, durationLabel, rangeLabel, type Block, type BlockOptions } from '../blocks';

const PERIODS = [
  { id: 'current', label: 'Vigentes y próximos' },
  { id: 'past', label: 'Anteriores' },
];

// Tarjetas que filtran la tabla.
const CARDS: { id: string; title: string; match: (b: Block) => boolean; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'current', title: 'En curso ahora', match: (b) => b.state === 'current', icon: <PlayCircle size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20', hover: 'hover:border-emerald-300' },
  { id: 'upcoming', title: 'Próximos', match: (b) => b.state === 'upcoming', icon: <CalendarClock size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
  { id: 'branch', title: 'Cierres de sede', match: (b) => b.scope === 'branch', icon: <Building2 size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20', hover: 'hover:border-violet-300' },
  { id: 'affected', title: 'Con citas dentro', match: (b) => b.affected_count > 0, icon: <AlertTriangle size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
];

const normalize = (value: string) => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// Bloqueos y ausencias de la empresa: vacaciones y permisos de cada persona,
// feriados y cierres de sede. Desde aquí se registran, se editan y se ve qué
// citas quedaron dentro de cada uno.
export default function BlocksTable() {
  const vocab = useVocabulary();
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [options, setOptions] = useState<BlockOptions | null>(null);
  const [period, setPeriod] = useState('current');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ kind: '', professional_id: '', branch_id: '' });
  const [activeCard, setActiveCard] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Block | null>(null);
  const [deleting, setDeleting] = useState<Block | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  // Bloqueo cuyas citas afectadas se están viendo.
  const [reviewing, setReviewing] = useState<Block | null>(null);
  const [reviewLoading, setReviewLoading] = useState(false);

  const load = useCallback(() => {
    api.get('/availability-blocks', { params: { period } })
      .then((res) => setBlocks(Array.isArray(res.data) ? res.data : []))
      .catch((error) => { console.error('Error al cargar bloqueos:', error); setBlocks([]); });
  }, [period]);

  const openReview = useCallback((id: string) => {
    setReviewLoading(true);
    api.get(`/availability-blocks/${id}`)
      .then((res) => setReviewing(res.data))
      .catch(() => setReviewing(null))
      .finally(() => setReviewLoading(false));
  }, []);

  // Al cambiar una cita desde aquí, la lista y el bloqueo abierto se ponen al día.
  const reviewingId = reviewing?.id;
  const refresh = useCallback(() => {
    load();
    if (reviewingId) openReview(reviewingId);
  }, [load, openReview, reviewingId]);

  const { openDetail, panels, isOpen: appointmentOpen } = useAppointmentPanels(refresh);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    api.get('/availability-blocks/options')
      .then((res) => setOptions(res.data))
      .catch(() => setOptions(null));
  }, []);

  const handleFilterChange = (filterId: string, value: any) => setFilters((current) => ({ ...current, [filterId]: value }));

  const confirmDelete = async () => {
    if (!deleting) return;

    try {
      setDeleteBusy(true);
      setDeleteError('');
      await api.delete(`/availability-blocks/${deleting.id}`);
      setDeleting(null);
      load();
    } catch (err) {
      setDeleteError(apiError(err, 'No se pudo eliminar el bloqueo.'));
    } finally {
      setDeleteBusy(false);
    }
  };

  const card = CARDS.find((c) => c.id === activeCard);
  const term = normalize(searchTerm.trim());
  const rows = blocks.filter((b) =>
    (!card || card.match(b))
    && (!filters.kind || b.kind === filters.kind)
    && (!filters.professional_id || b.professional_id === filters.professional_id)
    && (!filters.branch_id || b.branch_id === filters.branch_id)
    && (!term || normalize(`${b.reason ?? ''} ${b.professional?.display_name ?? ''} ${b.branch?.name ?? ''} ${b.kind_label}`).includes(term)),
  );

  const metricCards: MetricCardItem[] = [
    {
      title: period === 'past' ? 'Bloqueos anteriores' : 'Bloqueos vigentes',
      value: blocks.length,
      icon: <CalendarOff size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    ...CARDS.filter((item) => period === 'current' || item.id === 'branch').map((item) => ({
      title: item.title,
      value: blocks.filter(item.match).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => setActiveCard((current) => (current === item.id ? '' : item.id)),
    })),
  ];

  const kindOptions = [...(options?.professional_kinds ?? []), ...(options?.branch_kinds ?? [])]
    .filter((kind, index, all) => all.findIndex((k) => k.key === kind.key) === index)
    .map((kind) => ({ label: kind.label, value: kind.key }));

  const filterConfigs: FilterConfig[] = [
    { id: 'kind', label: 'Tipo', type: 'select', options: kindOptions },
    { id: 'professional_id', label: vocab.pro, type: 'select', options: (options?.professionals ?? []).map((p) => ({ label: p.display_name, value: p.id })) },
    ...((options?.branches.length ?? 0) > 1
      ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: options!.branches.map((b) => ({ label: b.name, value: b.id })) }]
      : []),
  ];

  const columns: Column<Block>[] = [
    {
      header: 'Aplica a',
      cell: (b) => (
        <div key={`who-${b.id}`} className="flex items-center gap-3">
          <span className={`p-2 rounded-lg shrink-0 ${b.scope === 'branch' ? 'bg-violet-50 text-violet-600' : 'bg-slate-100 text-slate-500'}`}>
            {b.scope === 'branch' ? <Building2 size={16} /> : <UserRound size={16} />}
          </span>
          <div>
            {b.can_manage ? (
              <button
                type="button"
                data-edit={b.id}
                onClick={() => { setEditing(b); setFormOpen(true); }}
                className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left`}
              >
                {b.professional?.display_name ?? b.branch?.name ?? '—'}
              </button>
            ) : (
              <span className={styles.customerName}>{b.professional?.display_name ?? b.branch?.name ?? '—'}</span>
            )}
            <span className="block text-xs text-slate-400">{b.scope === 'branch' ? 'Toda la sede' : vocab.pro}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Motivo',
      cell: (b) => (
        <div key={`reason-${b.id}`}>
          <span className="block text-sm text-slate-700">{b.reason || '—'}</span>
          <span className={`inline-block mt-1 px-2 py-0.5 text-[11px] rounded-full font-medium ${KIND_STYLES[b.kind] ?? KIND_STYLES.other}`}>{b.kind_label}</span>
        </div>
      ),
    },
    {
      header: 'Cuándo',
      cell: (b) => (
        <div key={`when-${b.id}`}>
          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{rangeLabel(b)}</span>
          <span className="block text-xs text-slate-500">{durationLabel(b)}</span>
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (b) => (
        <span key={`state-${b.id}`} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${STATE_LABELS[b.state].badge}`}>
          {STATE_LABELS[b.state].label}
        </span>
      ),
    },
    {
      header: 'Citas dentro',
      cell: (b) => b.affected_count > 0 ? (
        <button
          key={`affected-${b.id}`}
          type="button"
          data-review={b.id}
          onClick={() => openReview(b.id)}
          title="Ver las citas que quedaron dentro"
          className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors"
        >
          <AlertTriangle size={13} /> {b.affected_count} por resolver
        </button>
      ) : (
        <span key={`affected-${b.id}`} className="text-sm text-slate-400">Ninguna</span>
      ),
    },
    {
      header: 'Acciones',
      cell: (b) => b.can_manage ? (
        <div key={`actions-${b.id}`} className="flex items-center gap-1">
          <button
            onClick={() => { setEditing(b); setFormOpen(true); }}
            title="Editar"
            className="p-1.5 text-slate-400 hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors"
          >
            <Edit2 size={16} />
          </button>
          <button
            data-delete={b.id}
            onClick={() => { setDeleteError(''); setDeleting(b); }}
            title="Eliminar"
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          >
            <Trash2 size={16} />
          </button>
        </div>
      ) : (
        <span key={`actions-${b.id}`} className="text-sm text-slate-300">—</span>
      ),
    },
  ];

  const deletingWho = deleting?.professional?.display_name ?? deleting?.branch?.name ?? '';

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<Block>
          title="Bloqueos y ausencias"
          primaryActionLabel="Nuevo bloqueo"
          onPrimaryAction={() => { setEditing(null); setFormOpen(true); }}
          tabs={PERIODS.map((p) => ({ id: p.id, label: p.label, count: p.id === period ? blocks.length : undefined }))}
          activeTab={period}
          onTabChange={(id) => { setPeriod(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          columns={columns}
          data={rows}
          getItemKey={(b) => b.id}
          entityLabel="bloqueos"
          metricCards={metricCards}
        />
      </div>

      <FormBlock
        isOpen={formOpen}
        onClose={() => setFormOpen(false)}
        initialData={editing}
        options={options}
        onSaved={load}
      />

      <FormDrawer
        isOpen={Boolean(reviewing) && !appointmentOpen}
        onClose={() => setReviewing(null)}
        title={`Citas dentro del bloqueo: ${reviewing?.professional?.display_name ?? reviewing?.branch?.name ?? ''}`}
        subtitle={reviewing ? `${reviewing.reason ?? reviewing.kind_label} · ${rangeLabel(reviewing)}` : ''}
        icon={<AlertTriangle size={20} />}
      >
        {reviewing && (
          <div className="space-y-4" data-review-panel>
            {reviewLoading ? (
              <p className="text-sm text-slate-500">Actualizando...</p>
            ) : (reviewing.affected ?? []).length === 0 ? (
              <p className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">
                Ya no quedan citas dentro de este bloqueo.
              </p>
            ) : (
              <>
                <p className="text-sm text-slate-600">
                  Estas citas siguen en la agenda aunque el horario esté bloqueado. Abre cada una para reprogramarla o cancelarla.
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
        title="Eliminar bloqueo"
        message={
          <>
            <p>
              Se eliminará <strong>{deleting?.reason || deleting?.kind_label}</strong> de <strong>{deletingWho}</strong>
              {deleting ? ` (${rangeLabel(deleting).toLowerCase()})` : ''}. Ese horario volverá a estar disponible para agendar.
            </p>
            {deleteError && <p role="alert" className="mt-2 text-red-600">{deleteError}</p>}
          </>
        }
        confirmText="Sí, eliminar"
        cancelText="No, mantenerlo"
        isLoading={deleteBusy}
        onConfirm={confirmDelete}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
