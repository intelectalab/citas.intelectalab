'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { CalendarDays, Clock, Edit2, Eye, MapPin, Power, Store, Users } from 'lucide-react';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import FormBranch from './FormBranch';
import BranchHoursPanel from './BranchHoursPanel';
import { addressLabel, todayLabel, type Branch, type BranchList } from '../branches';

const VIEWS = [
  { id: 'active', label: 'Activas' },
  { id: 'inactive', label: 'Inactivas' },
];

// Tarjetas que filtran la tabla.
const CARDS: { id: string; title: string; match: (b: Branch) => boolean; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'open', title: 'Abren hoy', match: (b) => b.today !== null && b.today.length > 0, icon: <Clock size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20', hover: 'hover:border-emerald-300' },
  { id: 'no-hours', title: 'Sin horario definido', match: (b) => !b.has_hours, icon: <Clock size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'no-team', title: 'Sin nadie que atienda', match: (b) => b.professionals_count === 0, icon: <Users size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20', hover: 'hover:border-rose-300' },
];

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-50';
const normalize = (value: string) => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// Sucursales de la empresa: dónde atiende, en qué horario y con quién. El ojo
// y el nombre llevan a la página de cada una.
export default function BranchesTable() {
  const vocab = useVocabulary();
  const [list, setList] = useState<BranchList | null>(null);
  const [view, setView] = useState('active');
  const [searchTerm, setSearchTerm] = useState('');
  const [activeCard, setActiveCard] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Branch | null>(null);
  const [hoursFor, setHoursFor] = useState<Branch | null>(null);
  const [toggling, setToggling] = useState<Branch | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get('/branches')
      .then((res) => setList(res.data))
      .catch((err) => { console.error('Error al cargar sucursales:', err); setList(null); });
  }, []);

  useEffect(() => { load(); }, [load]);

  const canManage = Boolean(list?.can_manage);
  const branches = (list?.branches ?? []).filter((b) => b.status === view);
  const inactiveCount = (list?.branches ?? []).filter((b) => b.status === 'inactive').length;

  const confirmToggle = async () => {
    if (!toggling) return;

    try {
      setBusy(true);
      setError('');
      await api.post(`/branches/${toggling.id}/status`, { status: toggling.status === 'active' ? 'inactive' : 'active' });
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar el estado de la sucursal.'));
    } finally {
      setBusy(false);
      setToggling(null);
    }
  };

  const openNew = () => {
    setError('');

    if (list && !list.can_add) {
      setError(list.multi_branch
        ? `Tu plan permite hasta ${list.limit} ${list.limit === 1 ? 'sucursal activa' : 'sucursales activas'}. Para abrir otra, cambia de plan o desactiva una.`
        : 'Tu plan incluye una sola sucursal. Para abrir otra necesitas un plan con Multisede.');
      return;
    }

    setEditing(null);
    setFormOpen(true);
  };

  const card = view === 'active' ? CARDS.find((c) => c.id === activeCard) : undefined;
  const term = normalize(searchTerm.trim());
  const rows = branches.filter((b) =>
    (!card || card.match(b)) && (!term || normalize(`${b.name} ${b.address ?? ''} ${b.district ?? ''}`).includes(term)),
  );

  const metricCards: MetricCardItem[] = view !== 'active' ? [] : [
    {
      // El tope del plan a la vista: cuántas tiene frente a cuántas puede tener.
      title: list?.limit ? `Sucursales activas de ${list.limit}` : 'Sucursales activas',
      value: list?.active ?? 0,
      icon: <Store size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: branches.filter(item.match).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => setActiveCard((current) => (current === item.id ? '' : item.id)),
    })),
    {
      title: 'Citas de hoy',
      value: branches.reduce((sum, b) => sum + b.appointments_today, 0),
      icon: <CalendarDays size={18} />,
      iconColorClass: 'text-sky-600',
      borderColorClass: 'hover:border-slate-300',
    },
  ];

  const columns: Column<Branch>[] = [
    {
      header: 'Sucursal',
      cell: (b) => (
        <div key={`name-${b.id}`} className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-[#84D0D9]/20 text-[#022340]"><Store size={16} /></span>
          <div>
            <Link href={`/branches/${b.id}`} data-open={b.id} className={`${styles.customerName} hover:text-[#3F768C] transition-colors`}>{b.name}</Link>
            <span className="block text-xs text-slate-400">{b.phone ?? 'Sin teléfono'}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Dirección',
      cell: (b) => (
        <span key={`address-${b.id}`} className="flex items-start gap-1.5 text-sm text-slate-700 max-w-xs">
          <MapPin size={14} className="text-slate-400 mt-0.5 shrink-0" /> {addressLabel(b) || 'Sin registrar'}
        </span>
      ),
    },
    {
      header: 'Hoy',
      cell: (b) => (
        <div key={`today-${b.id}`}>
          <span className={`block text-sm font-semibold ${b.today === null ? 'text-amber-700' : b.today.length === 0 ? 'text-slate-400' : 'text-slate-800'}`}>{todayLabel(b)}</span>
          <span className="block text-xs text-slate-500">{b.has_hours ? `Abre ${b.open_days} ${b.open_days === 1 ? 'día' : 'días'} a la semana` : 'No limita la agenda'}</span>
        </div>
      ),
    },
    {
      header: vocab.pros,
      cell: (b) => <span key={`pros-${b.id}`} className={`text-sm ${b.professionals_count === 0 ? 'text-rose-700 font-semibold' : 'text-slate-700'}`}>{b.professionals_count === 0 ? 'Nadie' : b.professionals_count}</span>,
    },
    {
      header: 'Citas',
      cell: (b) => (
        <div key={`appts-${b.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{b.appointments_today} hoy</span>
          <span className="block text-xs text-slate-500">{b.upcoming} por atender</span>
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (b) => (
        <div key={`actions-${b.id}`} className="flex items-center gap-1">
          <Link href={`/branches/${b.id}`} title="Ver la sucursal" className={`${iconButton} hover:text-indigo-600 hover:bg-indigo-50`}>
            <Eye size={16} />
          </Link>
          {canManage && (
            <>
              <button data-hours={b.id} title="Horario de atención" onClick={() => setHoursFor(b)} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
                <Clock size={16} />
              </button>
              <button title="Editar" onClick={() => { setEditing(b); setFormOpen(true); }} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
                <Edit2 size={16} />
              </button>
              <button
                data-toggle={b.id}
                title={b.status === 'active' ? 'Desactivar' : 'Volver a activar'}
                onClick={() => { setError(''); setToggling(b); }}
                className={`${iconButton} ${b.status === 'active' ? 'hover:text-red-600 hover:bg-red-50' : 'hover:text-emerald-600 hover:bg-emerald-50'}`}
              >
                <Power size={16} />
              </button>
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      <div className="mb-6">
        <DataTable<Branch>
          title="Sucursales"
          primaryActionLabel="Nueva sucursal"
          onPrimaryAction={canManage ? openNew : undefined}
          tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === 'active' ? list?.active : inactiveCount }))}
          activeTab={view}
          onTabChange={(id) => { setView(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          columns={columns}
          data={rows}
          getItemKey={(b) => b.id}
          entityLabel="sucursales"
          metricCards={metricCards}
        />
      </div>

      <FormBranch isOpen={formOpen} onClose={() => setFormOpen(false)} initialData={editing} onSaved={load} />
      <BranchHoursPanel isOpen={Boolean(hoursFor)} onClose={() => setHoursFor(null)} branch={hoursFor} onSaved={load} />

      <AppModal
        isOpen={Boolean(toggling)}
        variant={toggling?.status === 'active' ? 'danger' : 'default'}
        title={toggling?.status === 'active' ? 'Desactivar sucursal' : 'Volver a activar sucursal'}
        message={toggling?.status === 'active'
          ? `${toggling?.name} dejará de ofrecerse para agendar y para definir horarios. Su historia se conserva y se puede volver a activar.`
          : `${toggling?.name ?? ''} volverá a ofrecerse para agendar.`}
        confirmText={toggling?.status === 'active' ? 'Sí, desactivar' : 'Sí, activar'}
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={confirmToggle}
        onClose={() => setToggling(null)}
      />
    </div>
  );
}
