'use client';

import { useState, useEffect } from 'react';
import { Clock, Pencil, Tag, Trash2 } from 'lucide-react';
import { formatMoney } from '@/lib/currency';
import { DataTable, type Column, type ExportColumn, type FilterConfig } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormService from './FormService';
import ServiceCategoriesPanel from './ServiceCategoriesPanel';
import ServiceView from './ServiceView';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api, readBranchFocus } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { categoryColor, type ServiceCategoryRow } from '../services';

interface Status {
  id: string;
  name: string;
  slug: string;
  color?: string;
  module?: {
    name: string;
  };
}

interface Service {
  id: string;
  code?: string | null;
  description?: string | null;
  branch_ids?: string[];
  branch_names?: string[];
  category_id: string | null;
  category?: { id: string; name: string; color?: string | null };
  status_id: string | null;
  status?: Status;
  name: string;
  duration_minutes: number;
  buffer_before_minutes: number;
  buffer_after_minutes: number;
  price: number;
  kind?: 'individual' | 'group';
  capacity?: number;
  online_booking?: boolean;
  requires_deposit?: boolean;
  is_virtual?: boolean;
  requires_record?: boolean;
  room_names?: string[];
  professional_ids?: string[];
  professional_names?: string[];
  branch_prices?: { branch_id: string; price: number }[];
  created_at?: string;
}

const STATUS_TABS: { id: string; label: string; test: (s: Service) => boolean }[] = [
  { id: 'all', label: 'Todos', test: () => true },
  { id: 'active', label: 'Activos', test: (s) => s.status?.slug === 'active' },
  { id: 'inactive', label: 'Inactivos', test: (s) => s.status?.slug === 'inactive' },
];

const pill = 'px-2 py-0.5 text-[11px] font-semibold rounded-full whitespace-nowrap';

export default function ServicesTable() {
  const { can, hasModule } = useAuth();
  const vocab = useVocabulary();
  const [focus] = useState(() => readBranchFocus());
  const priceOf = (s: Service) => Number(s.branch_prices?.find((p) => p.branch_id === focus)?.price ?? s.price);
  const [services, setServices] = useState<Service[]>([]);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [categories, setCategories] = useState<ServiceCategoryRow[]>([]);
  const [statusOptions, setStatusOptions] = useState<{ label: string; value: string; key: string }[]>([]);
  const [filters, setFilters] = useState<Record<string, any>>({ category_id: '', status_id: '', channel: '' });

  const [showModal, setShowModal] = useState(false);
  const [editingService, setEditingService] = useState<Service | null>(null);
  const [viewing, setViewing] = useState<Service | null>(null);
  const [categoriesOpen, setCategoriesOpen] = useState(false);
  const canEdit = can('services.edit');
  const usesRooms = hasModule('resources');

  const [modalConfig, setModalConfig] = useState<{
    isOpen: boolean;
    type: 'confirm' | 'alert';
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    onConfirm: () => void;
  }>({
    isOpen: false,
    type: 'confirm',
    title: '',
    message: '',
    onConfirm: () => {},
  });
  const [isActionLoading, setIsActionLoading] = useState(false);

  const fetchCategories = async () => {
    try {
      const res = await api.get('/service-categories');
      setCategories(Array.isArray(res.data) ? res.data : []);
    } catch {
      setCategories([]);
    }
  };

  useEffect(() => {
    const fetchStatuses = async () => {
      try {
        const res = await api.get('/statuses');
        const serviceStatuses = res.data.filter((st: any) => st.module?.name === 'services');
        setStatusOptions(serviceStatuses.map((st: any) => ({ label: st.name, value: st.id, key: st.id })));
      } catch {
        setStatusOptions([]);
      }
    };

    fetchCategories();
    fetchStatuses();
  }, []);

  const categoryOptions = categories.map((cat) => ({ label: cat.name, value: cat.id }));

  const serviceFilterConfigs: FilterConfig[] = [
    { id: 'category_id', label: 'Categoría', type: 'select', options: categoryOptions.map((cat) => ({ ...cat, key: cat.value })) },
    { id: 'status_id', label: 'Estado', type: 'select', options: statusOptions },
    { id: 'channel', label: 'Online', type: 'select', options: [{ label: 'Se reserva en línea', value: 'online' }, { label: 'Solo interno', value: 'internal' }] },
  ];

  const fetchServices = async (search = searchTerm, currentFilters = filters) => {
    try {
      const res = await api.get('/services', { params: { search, status_id: currentFilters.status_id } });
      setServices(res.data);
    } catch (error) {
      console.error('SERVICES_TABLE: Error loading services:', error);
    }
  };

  useEffect(() => {
    const wanted = new URLSearchParams(window.location.search).get('buscar');
    if (wanted) {
      window.history.replaceState(null, '', '/services');
      setSearchTerm(wanted);
      fetchServices(wanted);
      return;
    }
    fetchServices();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchServices(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    if (filterId === 'status_id') fetchServices(searchTerm, updatedFilters);
  };

  const handleTabChange = (tabId: string) => {
    setActiveTab(tabId);
    if (tabId !== 'all' && filters.status_id) {
      const updatedFilters = { ...filters, status_id: '' };
      setFilters(updatedFilters);
      fetchServices(searchTerm, updatedFilters);
    }
  };

  const openCreateModal = () => {
    setEditingService(null);
    setShowModal(true);
  };

  const openService = (service: Service) => {
    if (canEdit) {
      setEditingService(service);
      setShowModal(true);
    } else {
      setViewing(service);
    }
  };

  const handleDelete = async (id: string) => {
    let impact: { future_count: number; has_history: boolean; appointments: { code: string; starts_at: string; customer: string | null }[] } | null = null;
    try {
      impact = (await api.get(`/services/${id}/impact`)).data;
    } catch {
      impact = null;
    }
    const future = impact?.future_count ?? 0;
    const keep = future > 0 || Boolean(impact?.has_history);
    const list = (impact?.appointments ?? []).slice(0, 5).map((a) => `${a.code} · ${a.starts_at.replace('T', ' ').split(' ')[0].split('-').reverse().join('/')} ${a.starts_at.slice(11, 16)}${a.customer ? ` · ${a.customer}` : ''}`).join('; ');
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      title: keep ? 'Este servicio no se puede eliminar' : '¿Eliminar servicio?',
      message: future > 0
        ? `Tiene ${future} ${future === 1 ? 'cita por venir' : 'citas por venir'} (${list}${future > 5 ? '…' : ''}). Lo dejaremos inactivo para que ya no se pueda agendar; esas citas se mantienen y se atienden normal.`
        : keep
          ? 'Ya tiene citas o ventas en su historial. Lo dejaremos inactivo para que ya no se pueda agendar y su historial se mantiene.'
          : '¿Estás seguro de que deseas eliminar este servicio? Esta acción no se puede deshacer.',
      confirmText: keep ? 'Dejarlo inactivo' : 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          const res = await api.delete(`/services/${id}`);
          fetchServices(searchTerm, filters);
          fetchCategories();
          if (res.data?.deactivated) {
            setModalConfig({ isOpen: true, type: 'alert', title: 'Servicio inactivo', message: res.data.message, confirmText: 'Entendido', onConfirm: () => setModalConfig((prev) => ({ ...prev, isOpen: false })) });
          } else {
            setModalConfig(prev => ({ ...prev, isOpen: false }));
          }
        } catch (error) {
          console.error('SERVICES_TABLE: Error deleting service:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const prosLabel = (s: Service) => s.professional_names ?? [];
  const branchesLabel = (s: Service) => (s.branch_names?.length ? s.branch_names.join(', ') : 'Todas');
  const channelLabel = (s: Service) => [s.online_booking !== false ? 'Online' : 'Interno', s.requires_deposit ? 'Adelanto' : null].filter(Boolean).join(' + ');

  const exportColumns: ExportColumn<Service>[] = [
    { header: 'Código', value: (s) => s.code ?? '' },
    { header: 'Servicio', value: (s) => s.name },
    { header: 'Categoría', value: (s) => s.category?.name ?? 'Sin categoría' },
    { header: 'Tipo', value: (s) => (s.kind === 'group' ? `Clase · cupo ${s.capacity ?? 1}` : 'Individual') },
    { header: 'Duración (min)', type: 'number', value: (s) => s.duration_minutes },
    { header: 'Preparación antes (min)', type: 'number', value: (s) => s.buffer_before_minutes },
    { header: 'Preparación después (min)', type: 'number', value: (s) => s.buffer_after_minutes },
    { header: 'Precio', type: 'money', value: (s) => priceOf(s) },
    ...(usesRooms ? [{ header: 'Requiere', value: (s: Service) => (s.room_names ?? []).join(', ') }] : []),
    { header: vocab.pros, value: (s) => prosLabel(s).join(', ') },
    { header: 'Sedes', value: (s) => branchesLabel(s) },
    { header: 'Canal', value: (s) => channelLabel(s) },
    { header: 'Estado', value: (s) => s.status?.name ?? 'Sin estado' },
    { header: 'Descripción', value: (s) => s.description ?? '' },
    { header: 'Registrado', type: 'date', value: (s) => s.created_at },
  ];

  const columns: Column<Service>[] = [
    {
      header: 'Código',
      cell: (service) => <span key={`code-${service.id}`} className="font-mono text-xs text-slate-600 whitespace-nowrap">{service.code || '—'}</span>,
    },
    {
      header: 'Servicio',
      cell: (service) => (
        <div key={`name-${service.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            {service.name[0]?.toUpperCase()}
          </div>
          <div>
            <button type="button" data-open-service={service.id} onClick={() => openService(service)} className={`${styles.customerName} hover:text-brand-accent transition-colors text-left`}>
              {service.name}
            </button>
            {service.kind === 'group' && <span className={`${pill} ml-1.5 bg-sky-50 text-sky-700`} data-class-badge>Clase · cupo {service.capacity ?? 1}</span>}
            <span className="flex items-center gap-1.5 text-xs text-slate-400">
              {service.category && <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: categoryColor(service.category.color) }} />}
              {service.category?.name || 'Sin categoría'}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Duración',
      cell: (service) => (
        <div key={`duration-${service.id}`} className="flex flex-col text-xs text-slate-600 gap-0.5">
          <div className="flex items-center gap-1 font-medium">
            <Clock size={13} className="text-slate-400" />
            <span>{service.duration_minutes} min</span>
          </div>
          {(service.buffer_before_minutes > 0 || service.buffer_after_minutes > 0) && (
            <span className="text-slate-400 text-[11px]">
              {[service.buffer_before_minutes > 0 && `Preparación ${service.buffer_before_minutes} min`, service.buffer_after_minutes > 0 && `limpieza ${service.buffer_after_minutes} min`].filter(Boolean).join(' · ')}
            </span>
          )}
        </div>
      ),
    },
    {
      header: 'Precio',
      cell: (service) => (
        <div key={`price-${service.id}`} className="inline-flex items-center gap-1 font-semibold text-slate-700">
          <span>{formatMoney(priceOf(service))}</span>
          {focus && service.branch_prices?.some((p) => p.branch_id === focus) && <span className="text-[11px] font-medium text-slate-400" title={`Precio general: ${Number(service.price).toFixed(2)}`}>de la sede</span>}
          {!focus && (service.branch_prices?.length ?? 0) > 0 && <span className="text-[11px] font-medium text-slate-400">varía por sede</span>}
        </div>
      ),
    },
    ...(usesRooms ? [{
      header: 'Requiere',
      cell: (service: Service) => <span key={`rooms-${service.id}`} className="text-xs text-slate-600">{service.room_names?.length ? service.room_names.join(', ') : '—'}</span>,
    }] : []),
    {
      header: vocab.pros,
      cell: (service) => {
        const names = prosLabel(service);
        return (
          <span key={`pros-${service.id}`} className="text-sm font-semibold text-slate-700" title={names.join(', ')} data-pros-count={names.length}>
            {names.length}
            {!service.professional_ids?.length && names.length > 0 && <span className="block text-[11px] font-medium text-slate-400">{vocab.pick('pro', 'todos', 'todas')}</span>}
          </span>
        );
      },
    },
    {
      header: 'Sedes',
      cell: (service) => <span key={`branches-${service.id}`} className="text-xs text-slate-600">{branchesLabel(service)}</span>,
    },
    {
      header: 'Canal',
      cell: (service) => (
        <div key={`channel-${service.id}`} className="flex flex-wrap gap-1">
          {service.online_booking !== false
            ? <span className={`${pill} bg-emerald-50 text-emerald-700`}>Online</span>
            : <span className={`${pill} bg-slate-100 text-slate-600`}>Interno</span>}
          {service.requires_deposit && <span className={`${pill} bg-amber-50 text-amber-700`}>Adelanto</span>}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (service) => {
        const statusName = service.status?.name || 'Sin estado';
        const statusColor = service.status?.color;

        return (
          <span
            key={`status-${service.id}`}
            className="px-2.5 py-1 text-xs rounded-full font-medium inline-flex items-center gap-1.5"
            style={{
              backgroundColor: statusColor ? `${statusColor}15` : '#F1F5F9',
              color: statusColor || '#64748B'
            }}
          >
            <span
              className="w-1.5 h-1.5 rounded-full"
              style={{ backgroundColor: statusColor || '#94A3B8' }}
            />
            {statusName}
          </span>
        );
      },
    },
    {
      header: 'Acciones',
      cell: (service) => (
        <div key={`actions-${service.id}`} className="flex items-center gap-2">
          {canEdit && (<button
            onClick={() => openService(service)}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
            title="Editar"
          >
            <Pencil size={16} />
          </button>)}
          {can('services.delete') && (<button
            onClick={() => handleDelete(service.id)}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
            title="Eliminar"
          >
            <Trash2 size={16} />
          </button>)}
        </div>
      ),
    },
  ];

  const byTab = services.filter((STATUS_TABS.find((t) => t.id === activeTab) ?? STATUS_TABS[0]).test);
  const shown = byTab.filter((s) =>
    (!filters.category_id || s.category_id === filters.category_id)
    && (!filters.channel || (filters.channel === 'online') === (s.online_booking !== false)),
  );
  const countIn = (categoryId: string) => byTab.filter((s) => s.category_id === categoryId).length;
  const chip = (active: boolean) => `inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-full border transition-colors whitespace-nowrap ${active ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`;

  return (
    <div>
      {categories.length > 0 && (
        <div className="flex gap-2 overflow-x-auto pb-3 -mx-1 px-1" role="group" aria-label="Categorías" data-category-chips>
          <button type="button" className={chip(!filters.category_id)} aria-pressed={!filters.category_id} onClick={() => handleFilterChange('category_id', '')}>
            Todas <span className="text-slate-400">{byTab.length}</span>
          </button>
          {categories.map((cat) => (
            <button key={cat.id} type="button" data-category-chip={cat.id} className={chip(filters.category_id === cat.id)} aria-pressed={filters.category_id === cat.id} onClick={() => handleFilterChange('category_id', filters.category_id === cat.id ? '' : cat.id)}>
              <span className="w-2 h-2 rounded-full" style={{ backgroundColor: categoryColor(cat.color) }} />
              {cat.name} <span className="text-slate-400">{countIn(cat.id)}</span>
            </button>
          ))}
        </div>
      )}

      <DataTable<Service>
        title="Catálogo de Servicios"
        primaryActionLabel="Nuevo Servicio"
        onPrimaryAction={can('services.create') ? openCreateModal : undefined}
        headerActions={(can('services.create') || canEdit) ? (
          <button type="button" data-open-categories onClick={() => setCategoriesOpen(true)} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50">
            <Tag size={15} /> Categorías
          </button>
        ) : undefined}
        tabs={STATUS_TABS.map((t) => ({ id: t.id, label: t.label, count: services.filter(t.test).length }))}
        activeTab={activeTab}
        onTabChange={handleTabChange}
        searchTerm={searchTerm}
        onSearchChange={handleSearchChange}
        filterConfigs={serviceFilterConfigs}
        filterValues={filters}
        onFilterChange={handleFilterChange}
        columns={columns}
        dateFilters={[
          { id: 'created_at', label: 'Fecha de creación', value: (s) => s.created_at },
        ]}
        sortOptions={[
          { id: 'code', label: 'Código', value: (s) => s.code ?? '' },
          { id: 'name', label: 'Nombre', value: (s) => s.name },
          { id: 'category', label: 'Categoría', value: (s) => s.category?.name },
          { id: 'price', label: 'Precio', type: 'number', value: (s) => priceOf(s) },
          { id: 'duration', label: 'Duración', type: 'number', value: (s) => s.duration_minutes },
          { id: 'pros', label: vocab.pros, type: 'number', value: (s) => prosLabel(s).length },
          { id: 'status', label: 'Estado', value: (s) => s.status?.name },
          { id: 'created_at', label: 'Fecha de creación', type: 'date', value: (s) => s.created_at },
        ]}
        data={shown}
        exportTitle="Servicios"
        exportScreen="services"
        exportColumns={exportColumns}
        getItemKey={(service) => service.id}
        entityLabel="servicios registrados"
      />

      <FormService
        isOpen={showModal}
        onClose={() => { setShowModal(false); fetchCategories(); }}
        initialData={editingService}
        categories={categoryOptions}
        onSubmitSuccess={(saved?: { notice?: string | null }) => {
          fetchServices(searchTerm, filters);
          fetchCategories();
          if (saved?.notice) {
            setModalConfig({ isOpen: true, type: 'alert', title: 'Servicio inactivo', message: saved.notice, confirmText: 'Entendido', onConfirm: () => setModalConfig((prev) => ({ ...prev, isOpen: false })) });
          }
        }}
      />

      <ServiceView service={viewing} onClose={() => setViewing(null)} />

      <ServiceCategoriesPanel
        isOpen={categoriesOpen}
        onClose={() => setCategoriesOpen(false)}
        canCreate={can('services.create')}
        canEdit={canEdit}
        onChanged={() => { fetchCategories(); fetchServices(searchTerm, filters); }}
      />

      <AppModal
        isOpen={modalConfig.isOpen}
        type={modalConfig.type}
        title={modalConfig.title}
        message={modalConfig.message}
        confirmText={modalConfig.confirmText}
        cancelText={modalConfig.cancelText}
        onConfirm={modalConfig.onConfirm}
        onClose={() => setModalConfig(prev => ({ ...prev, isOpen: false }))}
        isLoading={isActionLoading}
      />
    </div>
  );
}
