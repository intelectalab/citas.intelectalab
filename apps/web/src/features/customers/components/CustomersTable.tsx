'use client';

import Badge from '@/app/components/common/Badge';
import { useState, useEffect } from 'react';
import Link from 'next/link';
import { User, Pencil, Trash2, Users, Mars, Venus, Phone, MapPin, Eye } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import FormCustomer from './FormCustomer';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAuth } from '@/hooks/useAuth';
import { blockLabel, missesLabel, type OnlineBookingState } from '../onlineBooking';
import CustomerBoard from './CustomerBoard';
import BoardColumnsEditor from './BoardColumnsEditor';
import { stageOf, type BoardInfo } from '../board';
import { apiError } from '@/features/appointments/appointments';

const VIEW_KEY = 'customers.view';

const savedView = (): 'table' | 'board' => {
  try {
    return window.localStorage.getItem(VIEW_KEY) === 'board' ? 'board' : 'table';
  } catch {
    return 'table';
  }
};

interface Customer {
  id: string;
  code: string;
  created_at?: string;
  first_name: string;
  last_name: string;
  biological_sex?: string;
  email?: string;
  phone?: string;
  document_number?: string;
  district?: string;
  dependents?: { id: string; name: string; species?: string | null }[];
  online_booking?: OnlineBookingState | null;
  pipeline_stage_id?: string | null;
  last_visit?: string | null;
  next_visit?: string | null;
  status?: {
    id: string;
    name: string;
    slug?: string;
  };
}

export default function CustomersTable() {
  const vocab = useVocabulary();
  const clientLower = vocab.client.toLowerCase();
  const { user, can } = useAuth();
  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ biological_sex: '' });

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingCustomer, setEditingCustomer] = useState<Customer | null>(null);

  const [modalConfig, setModalConfig] = useState<{
    isOpen: boolean;
    type?: 'confirm' | 'alert';
    variant?: 'default' | 'danger';
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    cancelText?: string;
    onConfirm?: () => void;
  }>({
    isOpen: false,
    title: '',
    message: '',
  });
  const [isActionLoading, setIsActionLoading] = useState(false);
  const [view, setView] = useState<'table' | 'board'>('table');
  const [board, setBoard] = useState<BoardInfo | null>(null);
  const [boardError, setBoardError] = useState('');
  const [editingColumns, setEditingColumns] = useState(false);

  const loadBoard = () => {
    api.get('/customer-board')
      .then((res) => { setBoard(res.data); setBoardError(''); })
      .catch(() => setBoardError('No se pudo cargar el tablero.'));
  };

  useEffect(() => {
    setView(savedView());
    loadBoard();
  }, []);

  const changeView = (next: 'table' | 'board') => {
    setView(next);
    try {
      window.localStorage.setItem(VIEW_KEY, next);
    } catch {}
  };

  const moveCustomer = (customerId: string, stageId: string) => {
    const before = customers.find((c) => c.id === customerId)?.pipeline_stage_id ?? null;
    setCustomers((current) => current.map((c) => (c.id === customerId ? { ...c, pipeline_stage_id: stageId } : c)));
    api.put(`/customers/${customerId}/stage`, { stage_id: stageId, source: 'board' })
      .then((res) => setBoard((current) => (current ? { ...current, stages: res.data.stages } : current)))
      .catch((error) => {
        setCustomers((current) => current.map((c) => (c.id === customerId ? { ...c, pipeline_stage_id: before } : c)));
        setModalConfig({ isOpen: true, type: 'alert', title: 'No se pudo mover la tarjeta', message: apiError(error, 'Inténtalo de nuevo.') });
      });
  };

  const filterConfigs: FilterConfig[] = [
    {
      id: 'biological_sex',
      label: 'Sexo Biológico',
      type: 'select',
      options: [
        { label: 'Masculino', value: 'M' },
        { label: 'Femenino', value: 'F' },
        { label: 'Otro', value: 'O' },
      ]
    }
  ];

  const fetchCustomers = async (search = '', currentFilters = filters) => {
    try {
      setLoading(true);
      const res = await api.get('/customers', {
        params: { search, ...currentFilters, per_page: 500 }
      });
      const responseData = res.data;
      setTotal(Number(responseData?.total ?? (Array.isArray(responseData) ? responseData.length : 0)));
      if (Array.isArray(responseData)) {
        setCustomers(responseData);
      } else if (responseData && Array.isArray(responseData.data)) {
        setCustomers(responseData.data);
      } else {
        setCustomers([]);
      }
    } catch (error) {
      console.error('Error al cargar clientes:', error);
      setCustomers([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCustomers();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchCustomers(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchCustomers(searchTerm, updatedFilters);
  };

  const handleMetricCardClick = (sexValue: string) => {
    const newSex = filters.biological_sex === sexValue ? '' : sexValue;
    const updatedFilters = { ...filters, biological_sex: newSex };
    setFilters(updatedFilters);
    fetchCustomers(searchTerm, updatedFilters);
  };

  const handleDelete = (id: string) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      variant: 'danger',
      title: `¿Eliminar ${clientLower}?`,
      message: `¿Estás seguro de eliminar este ${clientLower}? Esta acción se puede revertir desde la papelera.`,
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await api.delete(`/customers/${id}`);
          fetchCustomers(searchTerm, filters);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error) {
          console.error('Error al eliminar cliente:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const safeCustomers = Array.isArray(customers) ? customers : [];
  const totalMale = safeCustomers.filter(p => p.biological_sex === 'M' || p.biological_sex?.toLowerCase() === 'masculino').length;
  const totalFemale = safeCustomers.filter(p => p.biological_sex === 'F' || p.biological_sex?.toLowerCase() === 'femenino').length;

  const metricCards: MetricCardItem[] = [
    {
      title: 'Hombres',
      value: totalMale,
      icon: <Mars size={18} />,
      iconColorClass: 'text-blue-500',
      borderColorClass: filters.biological_sex === 'M' ? 'border-blue-500 ring-2 ring-blue-500/20' : 'hover:border-blue-300',
      onClick: () => handleMetricCardClick('M'),
    },
    {
      title: 'Mujeres',
      value: totalFemale,
      icon: <Venus size={18} />,
      iconColorClass: 'text-pink-500',
      borderColorClass: filters.biological_sex === 'F' ? 'border-pink-500 ring-2 ring-pink-500/20' : 'hover:border-pink-300',
      onClick: () => handleMetricCardClick('F'),
    }
  ];

  const exportColumns: ExportColumn<Customer>[] = [
    { header: 'Código', value: (c) => c.code },
    { header: vocab.client, value: (c) => `${c.first_name} ${c.last_name}`.trim() },
    { header: 'Correo', value: (c) => c.email },
    ...(usesDependents ? [{ header: vocab.dependents, value: (c: Customer) => (c.dependents ?? []).map((d) => d.name).join(', ') }] : []),
    { header: 'Documento', value: (c) => c.document_number },
    { header: 'Teléfono', value: (c) => c.phone },
    { header: 'Distrito', value: (c) => c.district },
    { header: 'Sexo biológico', value: (c) => (c.biological_sex === 'M' ? 'Masculino' : c.biological_sex === 'F' ? 'Femenino' : c.biological_sex ? 'Otro' : '') },
    ...(board?.ready ? [{ header: 'Etapa', value: (c: Customer) => stageOf(board, c.pipeline_stage_id)?.name }] : []),
    { header: 'Registrado', type: 'date', value: (c) => c.created_at },
  ];

  const allColumns: (Column<Customer> | null)[] = [
    {
      header: 'Código',
      cell: (pat) => (
        <div key={`code-${pat.id}`} className="font-mono text-xs font-semibold text-slate-700">
          {pat.code ? pat.code : <span className="text-slate-400 font-normal italic">Sin código</span>}
        </div>
      ),
    },
    {
      header: vocab.client,
      cell: (pat) => (
        <div key={`name-${pat.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            <User size={16} />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <Link href={`/customers/${pat.id}`} data-customer-link={pat.id} className={`${styles.customerName} hover:text-brand-accent transition-colors`}>
                {`${pat.first_name} ${pat.last_name}`}
              </Link>
            </div>
            <span className="block text-xs text-slate-400 font-mono">
              {pat.email ? pat.email : 'Sin correo electrónico'}
            </span>
            {pat.online_booking && (
              <span
                data-online-booking={pat.online_booking.blocked ? 'blocked' : 'note'}
                title={[missesLabel(pat.online_booking), blockLabel(pat.online_booking)].filter(Boolean).join('. ')}
                className={`inline-block mt-1 px-2 py-0.5 text-[11px] font-semibold rounded-md ${pat.online_booking.blocked ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'}`}
              >
                {pat.online_booking.blocked ? 'Sin reserva en línea' : `${pat.online_booking.misses} ${pat.online_booking.misses === 1 ? 'inasistencia' : 'inasistencias'} en línea`}
              </span>
            )}
          </div>
        </div>
      ),
    },
    usesDependents ? {
      header: vocab.dependents,
      cell: (pat) => (
        <div key={`dependents-${pat.id}`} className="flex flex-wrap gap-1">
          {pat.dependents && pat.dependents.length > 0 ? (
            pat.dependents.map((dep) => (
              <span key={dep.id} title={dep.species ?? undefined} className="px-2 py-0.5 text-xs font-medium bg-amber-50 text-amber-700 rounded-md border border-amber-100">
                {dep.name}
              </span>
            ))
          ) : (
            <span className="text-xs text-slate-400 italic">Sin registrar</span>
          )}
        </div>
      ),
    } : null,
    {
      header: 'Documento',
      cell: (pat) => (
        <div key={`doc-${pat.id}`} className="text-sm font-medium text-slate-700 font-mono">
          {pat.document_number ? pat.document_number : <span className="text-slate-400 italic font-normal">No registrado</span>}
        </div>
      ),
    },
    {
      header: 'Contacto / Ubicación',
      cell: (pat) => (
        <div key={`contact-${pat.id}`} className="space-y-0.5 text-xs text-slate-600">
          <div className="flex items-center gap-1.5">
            <Phone size={12} className="text-slate-400" />
            <span>{pat.phone || 'Sin teléfono'}</span>
          </div>
          <div className="flex items-center gap-1.5 text-slate-400">
            <MapPin size={12} />
            <span>{pat.district || 'Sin distrito'}</span>
          </div>
        </div>
      ),
    },
    board?.ready ? {
      header: 'Etapa',
      cell: (pat) => {
        const stage = stageOf(board, pat.pipeline_stage_id);
        return stage ? (
          <span key={`stage-${pat.id}`} data-stage-cell className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 whitespace-nowrap">
            <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: stage.color }} />
            {stage.name}
          </span>
        ) : null;
      },
    } : null,
    {
      header: 'Sexo Biológico',
      cell: (pat) => {
        const sex = pat.biological_sex;
        const label = sex === 'M' ? 'Masculino' : sex === 'F' ? 'Femenino' : 'Otro';
        const badgeColor = sex === 'M' ? 'bg-blue-50 text-blue-600' : sex === 'F' ? 'bg-pink-50 text-pink-600' : 'bg-slate-100 text-slate-600';
        return (
          <Badge key={`sex-${pat.id}`} tone={badgeColor}>
            {label}
          </Badge>
        );
      },
    },
    {
      header: 'Acciones',
      cell: (pat) => (
        <div key={`actions-${pat.id}`} className="flex items-center gap-1">
          <Link
            href={`/customers/${pat.id}`}
            title={`Ver la historia de este ${clientLower}`}
            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
          >
            <Eye size={16} />
          </Link>
          {can('customers.edit') && (<button 
            onClick={() => { setEditingCustomer(pat); setShowFormModal(true); }}
            title={`Editar ${clientLower}`}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
          >
            <Pencil size={16} />
          </button>)}
          {can('customers.delete') && (<button 
            onClick={() => handleDelete(pat.id)}
            title={`Eliminar ${clientLower}`}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          >
            <Trash2 size={16} />
          </button>)}
        </div>
      ),
    },
  ];

  const columns = allColumns.filter((column): column is Column<Customer> => column !== null);

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<Customer>
          title={`Gestión de ${vocab.clients}`}
          primaryActionLabel={`${vocab.pick('client', 'Nuevo', 'Nueva')} ${vocab.client}`}
          onPrimaryAction={can('customers.create') ? () => { setEditingCustomer(null); setShowFormModal(true); } : undefined}
          tabs={[{ id: 'all', label: `${vocab.pick('client', 'Todos los', 'Todas las')} ${vocab.clients.toLowerCase()}`, count: safeCustomers.length }]}
          activeTab={activeTab}
          onTabChange={setActiveTab}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          columns={columns}
          dateFilters={[
            { id: 'created_at', label: 'Fecha de registro', value: (c) => c.created_at },
          ]}
          sortOptions={[
            { id: 'created_at', label: 'Fecha de registro', type: 'date', value: (c) => c.created_at },
            { id: 'code', label: 'Código', value: (c) => c.code },
            { id: 'first_name', label: 'Nombre', value: (c) => `${c.first_name} ${c.last_name}` },
            { id: 'last_name', label: 'Apellido', value: (c) => `${c.last_name} ${c.first_name}` },
            { id: 'document', label: 'Documento', value: (c) => c.document_number },
            { id: 'district', label: 'Distrito', value: (c) => c.district },
          ]}
          data={safeCustomers}
          exportTitle={vocab.clients}
          exportScreen="customers"
          exportColumns={exportColumns}
          exportNotice={total > safeCustomers.length ? `Se exportan ${safeCustomers.length} de ${total} ${vocab.clients.toLowerCase()}: la lista carga hasta 500. Filtra más para exportar el resto.` : undefined}
          getItemKey={(p) => p.id}
          entityLabel={`${vocab.clients.toLowerCase()} ${vocab.pick('client', 'registrados', 'registradas')}`}
          metricCards={metricCards}
          view={view}
          onViewChange={changeView}
          boardView={(rows) => (
            board?.ready ? (
              <CustomerBoard
                rows={rows}
                info={board}
                clientWord={vocab.client}
                onMove={moveCustomer}
                onEditColumns={board.can_edit_columns ? () => setEditingColumns(true) : undefined}
              />
            ) : (
              <p role="alert" data-board-not-ready className="m-4 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                {board?.message ?? (boardError || 'Cargando el tablero...')}
              </p>
            )
          )}
        />
      </div>

      {board?.ready && (
        <BoardColumnsEditor
          isOpen={editingColumns}
          info={board}
          clientsWord={vocab.clients}
          onClose={() => setEditingColumns(false)}
          onSaved={(info) => { setBoard(info); fetchCustomers(searchTerm, filters); }}
        />
      )}

      <FormCustomer
        isOpen={showFormModal}
        onClose={() => {
          setShowFormModal(false);
          setEditingCustomer(null);
          if (usesDependents) fetchCustomers(searchTerm, filters);
        }}
        initialData={editingCustomer}
        onSubmitSuccess={() => { fetchCustomers(searchTerm, filters); loadBoard(); }}
      />

      <AppModal
        isOpen={modalConfig.isOpen}
        type={modalConfig.type || 'alert'}
        variant={modalConfig.variant || 'default'}
        title={modalConfig.title}
        message={modalConfig.message}
        confirmText={modalConfig.confirmText || 'Aceptar'}
        cancelText={modalConfig.cancelText || 'Cancelar'}
        isLoading={isActionLoading}
        maxWidth="max-w-xl"
        onConfirm={modalConfig.onConfirm || (() => setModalConfig({ ...modalConfig, isOpen: false }))}
        onClose={() => setModalConfig({ ...modalConfig, isOpen: false })}
      />
    </div>
  );
}