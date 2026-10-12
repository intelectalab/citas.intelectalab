'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { DoorOpen, Edit2, Layers, PowerOff, Trash2, Wrench } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import FormRoom from './FormRoom';
import type { Room } from '@/features/branches/branches';

// El mismo tipo que usa la página de la sucursal.
export type RoomRow = Room & { created_at?: string };

interface RoomList {
  can_manage: boolean;
  // none: no pasa nada · observe: se muestra como observación · disable: la sala queda fuera de uso.
  equipment_down?: 'none' | 'observe' | 'disable';
  // La base ya permite decir en qué sala está un equipo.
  links_equipment?: boolean;
  branches: { id: string; name: string }[];
  rooms: RoomRow[];
}

const KIND_LABELS: Record<RoomRow['kind'], string> = { room: 'Sala', equipment: 'Equipo' };

const VIEWS = [
  { id: 'all', label: 'Todos' },
  { id: 'room', label: 'Salas' },
  { id: 'equipment', label: 'Equipos' },
];

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

// Salas y equipos de todas las sedes: consultorios, cabinas, sillones y equipos
// con los que se atiende. Los registra y los cambia el administrador.
export default function RoomsTable() {
  const [list, setList] = useState<RoomList | null>(null);
  const [view, setView] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ branch_id: '', status: '' });
  const [activeCard, setActiveCard] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<RoomRow | null>(null);
  const [deleting, setDeleting] = useState<RoomRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/rooms')
      .then((res) => { setList(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las salas y equipos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const rooms = list?.rooms ?? [];
  const statusOf = (room: RoomRow) => room.effective_status ?? room.status;
  const canManage = Boolean(list?.can_manage);
  const term = normalize(searchTerm.trim());

  const rows = rooms.filter((room) =>
    (view === 'all' || room.kind === view) &&
    (!filters.branch_id || room.branch_id === filters.branch_id) &&
    (!filters.status || statusOf(room) === filters.status) &&
    (activeCard !== 'inactive' || statusOf(room) === 'inactive') &&
    (!term || normalize(`${room.name} ${room.description ?? ''} ${room.branch_name ?? ''} ${room.parent_name ?? ''}`).includes(term)),
  );

  const remove = async () => {
    if (!deleting) return;

    try {
      setBusy(true);
      setError('');
      const res = await api.delete(`/rooms/${deleting.id}`);
      setNotice(res.data?.deactivated ? `${deleting.name} tiene citas registradas: se desactivó en lugar de eliminarse.` : `${deleting.name} se eliminó.`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar.'));
    } finally {
      setBusy(false);
      setDeleting(null);
    }
  };

  const branchOptions = [...new Map(rooms.map((r) => [r.branch_id, r.branch_name ?? 'Sede'])).entries()].map(([value, label]) => ({ value, label }));

  const filterConfigs: FilterConfig[] = [
    ...(branchOptions.length > 1 ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: branchOptions }] : []),
    { id: 'status', label: 'Estado', type: 'select', options: [{ label: 'En uso', value: 'active' }, { label: 'Fuera de uso', value: 'inactive' }] },
  ];

  const metricCards: MetricCardItem[] = [
    {
      title: 'Salas en uso',
      value: rooms.filter((r) => r.kind === 'room' && statusOf(r) === 'active').length,
      icon: <DoorOpen size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: view === 'room' && !activeCard ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
      onClick: () => { setActiveCard(''); setView(view === 'room' ? 'all' : 'room'); },
    },
    {
      title: 'Equipos en uso',
      value: rooms.filter((r) => r.kind === 'equipment' && statusOf(r) === 'active').length,
      icon: <Wrench size={18} />,
      iconColorClass: 'text-violet-600',
      borderColorClass: view === 'equipment' && !activeCard ? 'border-violet-500 ring-2 ring-violet-500/20' : 'hover:border-slate-300',
      onClick: () => { setActiveCard(''); setView(view === 'equipment' ? 'all' : 'equipment'); },
    },
    {
      title: 'Fuera de uso',
      value: rooms.filter((r) => statusOf(r) === 'inactive').length,
      icon: <PowerOff size={18} />,
      iconColorClass: 'text-rose-600',
      borderColorClass: activeCard === 'inactive' ? 'border-rose-500 ring-2 ring-rose-500/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(activeCard === 'inactive' ? '' : 'inactive'),
    },
  ];

  const columns: Column<RoomRow>[] = [
    {
      header: 'Sala o equipo',
      cell: (room) => (
        <div key={`name-${room.id}`} className="flex items-center gap-3">
          <span className={`p-2 rounded-lg shrink-0 ${room.kind === 'equipment' ? 'bg-violet-50 text-violet-600' : 'bg-[#84D0D9]/20 text-[#022340]'}`}>
            {room.kind === 'equipment' ? <Wrench size={16} /> : <DoorOpen size={16} />}
          </span>
          <div>
            {canManage ? (
              <button type="button" data-edit={room.id} onClick={() => { setEditing(room); setFormOpen(true); }} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left`}>
                {room.name}
              </button>
            ) : (
              <span className={styles.customerName}>{room.name}</span>
            )}
            <span className="block text-xs text-slate-400">
              {room.parent_name ? `En ${room.parent_name}${room.description ? ' · ' : ''}` : ''}{room.description || (room.parent_name ? '' : 'Sin descripción')}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Tipo',
      cell: (room) => (
        <span key={`kind-${room.id}`} className={`px-2.5 py-1 text-xs rounded-full font-medium ${room.kind === 'equipment' ? 'bg-violet-50 text-violet-700' : 'bg-[#84D0D9]/20 text-[#022340]'}`}>
          {KIND_LABELS[room.kind]}
        </span>
      ),
    },
    {
      header: 'Sede',
      cell: (room) => (
        <Link key={`branch-${room.id}`} href={`/branches/${room.branch_id}`} className="text-sm text-slate-700 hover:text-[#3F768C] hover:underline underline-offset-2 transition-colors">
          {room.branch_name ?? '—'}
        </Link>
      ),
    },
    {
      header: 'Estado',
      cell: (room) => (
        <div key={`status-${room.id}`}>
          <span data-status={statusOf(room)} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${statusOf(room) === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}>
            {statusOf(room) === 'active' ? 'En uso' : 'Fuera de uso'}
          </span>
          {(room.down_equipment?.length ?? 0) > 0 && (
            <span data-equipment-note className={`block mt-1.5 text-[11px] font-semibold ${room.auto_down ? 'text-rose-600' : 'text-amber-700'}`}>
              {room.auto_down ? 'Por' : 'Observación:'} {room.down_equipment!.join(', ')} fuera de uso
            </span>
          )}
        </div>
      ),
    },
    ...(canManage ? [{
      header: 'Acciones',
      cell: (room: RoomRow) => (
        <div key={`actions-${room.id}`} className="flex items-center gap-1">
          <button title={`Editar ${room.name}`} onClick={() => { setEditing(room); setFormOpen(true); }} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
            <Edit2 size={16} />
          </button>
          <button data-delete={room.id} title={`Eliminar ${room.name}`} onClick={() => { setError(''); setNotice(''); setDeleting(room); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
            <Trash2 size={16} />
          </button>
        </div>
      ),
    }] : []),
  ];

  return (
    <div className="space-y-6 mb-8">
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
      {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}

      <div className="mb-6">
        <DataTable<RoomRow>
          title="Salas y equipos"
          primaryActionLabel="Nueva sala o equipo"
          onPrimaryAction={canManage ? () => { setError(''); setNotice(''); setEditing(null); setFormOpen(true); } : undefined}
          primaryActionBlocked={canManage && (list?.branches.length ?? 0) === 0 ? 'Primero necesitas una sucursal activa donde registrarlos.' : undefined}
          tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'all' ? rooms.length : rooms.filter((r) => r.kind === v.id).length }))}
          activeTab={view}
          onTabChange={(id) => { setView(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          columns={columns}
          sortOptions={[
            { id: 'name', label: 'Nombre', value: (r) => r.name },
            { id: 'kind', label: 'Tipo', value: (r) => KIND_LABELS[r.kind] },
            { id: 'branch', label: 'Sede', value: (r) => r.branch_name },
            { id: 'status', label: 'Estado', value: (r) => ((r.effective_status ?? r.status) === 'active' ? 'En uso' : 'Fuera de uso') },
            { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (r) => r.created_at },
          ]}
          data={rows}
          getItemKey={(r) => r.id}
          entityLabel={rows.length === 1 ? 'registro' : 'registros'}
          metricCards={metricCards}
        />
      </div>

      {list && rooms.length === 0 && (
        <p className="flex items-center gap-2 text-sm text-slate-500 px-1">
          <Layers size={15} className="text-slate-400" />
          Aún no hay salas ni equipos registrados.{canManage ? ' Empieza con "Nueva sala o equipo".' : ''}
        </p>
      )}

      <FormRoom
        isOpen={formOpen}
        onClose={() => setFormOpen(false)}
        initialData={editing}
        branches={list?.branches ?? []}
        rooms={list?.links_equipment ? rooms.filter((r) => r.kind === 'room') : []}
        defaultBranchId={filters.branch_id}
        onSaved={load}
      />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={`¿Eliminar ${deleting?.name ?? ''}?`}
        message="Si ya tiene citas registradas no se elimina: queda fuera de uso para conservar su historia."
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
