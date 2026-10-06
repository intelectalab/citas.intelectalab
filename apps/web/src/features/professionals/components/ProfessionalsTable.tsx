'use client';

import { useState, useEffect } from 'react';
import { Contact, Pencil, Trash2, CheckCircle, XCircle, Award, FileBadge, UserCheck, Eye } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import FormProfessional from './FormProfessional';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAuth } from '@/hooks/useAuth';

interface Professional {
  id: string;
  display_name: string;
  license_number?: string;
  status?: {
    id: string;
    name: string;
    slug?: string;
    color?: string;
  };
  user?: {
    id: string;
    email: string;
    first_name?: string;
    last_name?: string;
  };
  specialties?: Array<{
    id: string;
    name: string;
  }>;
}

export default function ProfessionalsTable() {
  const vocab = useVocabulary();
  const { user } = useAuth();
  const proLower = vocab.pro.toLowerCase();
  const [professionals, setProfessionals] = useState<Professional[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ status: '' });

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingProfessional, setEditingProfessional] = useState<Professional | null>(null);
  const [viewingProfessional, setViewingProfessional] = useState<Professional | null>(null);

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

  const filterConfigs: FilterConfig[] = [
    {
      id: 'status',
      label: 'Estado',
      type: 'select',
      options: [
        { label: 'Activo', value: 'active' },
        { label: 'Inactivo', value: 'inactive' },
      ]
    }
  ];

  const fetchProfessionals = async (search = '', currentFilters = filters) => {
    try {
      setLoading(true);
      const res = await api.get('/professionals', {
        params: { search, ...currentFilters }
      });
      const responseData = res.data;
      if (Array.isArray(responseData)) {
        setProfessionals(responseData);
      } else if (responseData && Array.isArray(responseData.data)) {
        setProfessionals(responseData.data);
      } else {
        setProfessionals([]);
      }
    } catch (error) {
      console.error('Error al cargar profesionales:', error);
      setProfessionals([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchProfessionals();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchProfessionals(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchProfessionals(searchTerm, updatedFilters);
  };

  const handleMetricCardClick = (statusSlug: string) => {
    const newStatus = filters.status === statusSlug ? '' : statusSlug;
    const updatedFilters = { ...filters, status: newStatus };
    setFilters(updatedFilters);
    fetchProfessionals(searchTerm, updatedFilters);
  };

  const handleDelete = (id: string) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      variant: 'danger',
      title: `¿Eliminar ${proLower}?`,
      message: `¿Estás seguro de eliminar este registro de ${proLower}? Esta acción no se puede deshacer.`,
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await api.delete(`/professionals/${id}`);
          fetchProfessionals(searchTerm, filters);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error: any) {
          console.error('Error al eliminar profesional:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const handleCreationSuccess = (credentials?: { email: string; tempPassword?: string }) => {
    fetchProfessionals(searchTerm, filters);
    if (credentials?.tempPassword) {
      setModalConfig({
        isOpen: true,
        type: 'alert',
        variant: 'default',
        title: `¡${vocab.pro} creado con éxito!`,
        message: (
          <div className="space-y-4 pt-1">
            <p className="text-sm text-slate-600">
              Se ha registrado el perfil y las credenciales de acceso de este registro:
            </p>
            
            <div className="space-y-2.5 p-4 bg-slate-50 border border-slate-200/80 rounded-2xl">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-slate-500">Correo</span>
                <span className="text-xs font-mono font-semibold text-slate-800 bg-white px-3 py-1.5 rounded-lg border border-slate-200 select-all shadow-2xs">
                  {credentials.email}
                </span>
              </div>

              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-slate-500">Contraseña</span>
                <span className="text-xs font-mono font-bold text-slate-900 bg-white px-3 py-1.5 rounded-lg border border-slate-200 select-all shadow-2xs">
                  {credentials.tempPassword}
                </span>
              </div>
            </div>

            <div className="text-xs text-slate-500 bg-slate-100/80 border border-slate-200/60 p-3 rounded-xl font-medium">
              Asegúrate de copiar las credenciales de forma segura antes de cerrar.
            </div>
          </div>
        ),
        confirmText: 'Entendido',
        onConfirm: () => setModalConfig(prev => ({ ...prev, isOpen: false })),
      });
    }
  };

  const safeProfessionals = Array.isArray(professionals) ? professionals : [];

  // Conteos calculados para las tarjetas
  const totalActive = safeProfessionals.filter(p => p.status?.slug === 'active' || p.status?.name?.toLowerCase() === 'activo').length;
  const totalInactive = safeProfessionals.filter(p => p.status?.slug === 'inactive' || p.status?.name?.toLowerCase() === 'inactivo').length;

  const metricCards: MetricCardItem[] = [
    {
      title: `${vocab.pros}: activos`,
      value: totalActive,
      icon: <CheckCircle size={18} />,
      iconColorClass: 'text-emerald-500',
      borderColorClass: filters.status === 'active' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-emerald-300',
      onClick: () => handleMetricCardClick('active'),
    },
    {
      title: `${vocab.pros}: inactivos`,
      value: totalInactive,
      icon: <XCircle size={18} />,
      iconColorClass: 'text-rose-500',
      borderColorClass: filters.status === 'inactive' ? 'border-rose-500 ring-2 ring-rose-500/20' : 'hover:border-rose-300',
      onClick: () => handleMetricCardClick('inactive'),
    }
  ];

  // La columna de colegiatura solo aparece en los rubros que la usan.
  const usesLicense = Boolean(user?.tenant?.rules?.['professionals.license']);

  const allColumns: (Column<Professional> | null)[] = [
    {
      header: vocab.pro,
      cell: (prof) => (
        <div key={`name-${prof.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            <Contact size={16} />
          </div>
          <div>
            <span className={styles.customerName}>{prof.display_name}</span>
            <span className="block text-xs text-slate-400 font-mono">
              {prof.user?.email ? prof.user.email : 'Sin usuario vinculado'}
            </span>
          </div>
        </div>
      ),
    },
    usesLicense ? {
      header: 'Colegiatura',
      cell: (prof) => (
        <div key={`license-${prof.id}`} className="text-sm font-medium text-slate-700 font-mono">
          {prof.license_number ? prof.license_number : <span className="text-slate-400 italic font-normal">No registrada</span>}
        </div>
      ),
    } : null,
    {
      header: 'Especialidades',
      cell: (prof) => (
        <div key={`specialties-${prof.id}`} className="flex flex-wrap gap-1">
          {prof.specialties && prof.specialties.length > 0 ? (
            prof.specialties.map((spec) => (
              <span key={spec.id} className="px-2 py-0.5 text-xs font-medium bg-sky-50 text-sky-700 rounded-md border border-sky-100">
                {spec.name}
              </span>
            ))
          ) : (
            <span className="text-xs text-slate-400 italic">Sin especialidades</span>
          )}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (prof) => {
        const statusName = prof.status?.name || 'Activo';
        const isInactive = prof.status?.slug === 'inactive' || statusName.toLowerCase() === 'inactivo';
        return (
          <span 
            key={`status-${prof.id}`} 
            className={`px-2.5 py-1 text-xs rounded-full font-medium ${
              isInactive ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'
            }`}
          >
            {statusName}
          </span>
        );
      },
    },
    {
      header: 'Acciones',
      cell: (prof) => (
        <div key={`actions-${prof.id}`} className="flex items-center gap-1">
          <button 
            onClick={() => setViewingProfessional(prof)}
            title="Ver detalles"
            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
          >
            <Eye size={16} />
          </button>
          <button 
            onClick={() => { setEditingProfessional(prof); setShowFormModal(true); }}
            title={`Editar ${proLower}`}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
          >
            <Pencil size={16} />
          </button>
          <button 
            onClick={() => handleDelete(prof.id)}
            title={`Eliminar ${proLower}`}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          >
            <Trash2 size={16} />
          </button>
        </div>
      ),
    },
  ];

  const columns = allColumns.filter((column): column is Column<Professional> => column !== null);

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<Professional>
          title={`Gestión de ${vocab.pros}`}
          primaryActionLabel={`Nuevo ${vocab.pro}`}
          onPrimaryAction={() => { setEditingProfessional(null); setShowFormModal(true); }}
          tabs={[{ id: 'all', label: 'Todos', count: safeProfessionals.length }]}
          activeTab={activeTab}
          onTabChange={setActiveTab}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          columns={columns}
          data={safeProfessionals}
          getItemKey={(p) => p.id}
          entityLabel="registros"
          metricCards={metricCards}
        />
      </div>

      <FormProfessional
        isOpen={showFormModal}
        onClose={() => { setShowFormModal(false); setEditingProfessional(null); }}
        initialData={editingProfessional}
        onSubmitSuccess={handleCreationSuccess}
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