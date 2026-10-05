'use client';

import { useState, useEffect } from 'react';
import { Building2, Clock, Pencil, Trash2, Eye, CheckCircle, XCircle } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormTenant from './FormTenant';
import TenantDetailsModal from './TenantDetailsModal';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';

interface Tenant {
  id: string;
  name: string;
  slug: string;
  timezone: string;
  status: string;
  created_at: string;
}

export default function TenantsTable() {
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');

  // Iniciamos los filtros limpios
  const [filters, setFilters] = useState<Record<string, any>>({ status: '' });
  const [viewingTenant, setViewingTenant] = useState<Tenant | null>(null);

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingTenant, setEditingTenant] = useState<Tenant | null>(null);

  const [modalConfig, setModalConfig] = useState<{
    isOpen: boolean;
    type?: 'confirm' | 'alert';
    variant?: 'default' | 'danger';
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    onConfirm?: () => void;
  }>({
    isOpen: false,
    title: '',
    message: '',
  });
  const [isActionLoading, setIsActionLoading] = useState(false);

  const tenantFilterConfigs: FilterConfig[] = [
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

  const fetchTenants = async (search = '', currentFilters = filters) => {
    try {
      setLoading(true);
      const res = await api.get('/tenants', {
        params: { 
          search,
          ...currentFilters 
        }
      });
      const responseData = res.data;
      if (Array.isArray(responseData)) {
        setTenants(responseData);
      } else if (responseData && Array.isArray(responseData.data)) {
        setTenants(responseData.data);
      } else {
        setTenants([]);
      }
    } catch (error) {
      console.error('Error al cargar tenants:', error);
      setTenants([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTenants();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchTenants(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchTenants(searchTerm, updatedFilters);
  };

  const safeTenants = Array.isArray(tenants) ? tenants : [];

  // Conteos calculados para las tarjetas
  const totalActiveTenants = safeTenants.filter(t => t.status === 'active').length;
  const totalInactiveTenants = safeTenants.filter(t => t.status === 'inactive').length;

  // Configuración de métricas con el diseño tenue y comportamiento de toggle exacto
  const metricCards: MetricCardItem[] = [
    {
      title: 'Tenants activos',
      value: totalActiveTenants,
      icon: <CheckCircle size={18} />,
      iconColorClass: 'text-emerald-500',
      onClick: () => handleFilterChange('status', filters.status === 'active' ? '' : 'active'),
      borderColorClass: filters.status === 'active' ? 'border-emerald-500 ring-2 ring-emerald-100 shadow-sm' : 'hover:border-emerald-300',
    },
    {
      title: 'Tenants inactivos',
      value: totalInactiveTenants,
      icon: <XCircle size={18} />,
      iconColorClass: 'text-slate-400',
      onClick: () => handleFilterChange('status', filters.status === 'inactive' ? '' : 'inactive'),
      borderColorClass: filters.status === 'inactive' ? 'border-slate-500 ring-2 ring-slate-100 shadow-sm' : 'hover:border-slate-300',
    }
  ];

  const openCreateModal = () => {
    setEditingTenant(null);
    setShowFormModal(true);
  };

  const openEditModal = (tenant: Tenant) => {
    setEditingTenant(tenant);
    setShowFormModal(true);
  };

  const handleFormSubmit = async (formData: any) => {
    try {
      if (editingTenant) {
        await api.put(`/tenants/${editingTenant.id}`, formData);
      } else {
        await api.post('/tenants', formData);
      }

      setShowFormModal(false);
      fetchTenants(searchTerm, filters);
    } catch (error) {
      console.error('Error al guardar tenant:', error);
    }
  };

  const handleDelete = (id: string) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      variant: 'danger',
      title: '¿Eliminar tenant?',
      message: '¿Estás seguro de que deseas eliminar este tenant? Se perderán todos los datos asociados de manera permanente.',
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await api.delete(`/tenants/${id}`);
          fetchTenants(searchTerm, filters);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error) {
          console.error('Error al eliminar tenant:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const columns: Column<Tenant>[] = [
    {
      header: 'Nombre del Tenant',
      cell: (tenant) => (
        <div key={`name-${tenant.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            <Building2 size={16} />
          </div>
          <div>
            <span className={styles.customerName}>{tenant.name}</span>
            <span className="block text-xs text-slate-400 font-mono">
              slug: {tenant.slug}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Zona Horaria',
      cell: (tenant) => (
        <div key={`timezone-${tenant.id}`} className="flex items-center gap-1.5 text-xs text-slate-600">
          <Clock size={14} className="text-slate-400" />
          <span>{tenant.timezone}</span>
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (tenant) => (
        <span key={`status-${tenant.id}`} className={`px-2 py-0.5 text-xs rounded-full font-medium ${
          tenant.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'
        }`}>
          {tenant.status === 'active' ? 'Activo' : 'Inactivo'}
        </span>
      ),
    },
    {
      header: 'Acciones',
      cell: (tenant) => (
        <div key={`actions-${tenant.id}`} className="flex items-center gap-2">
          <button 
            onClick={() => setViewingTenant(tenant)}
            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
            title="Ver detalles"
          >
            <Eye size={16} />
          </button>
          <button 
            onClick={() => openEditModal(tenant)}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
            title="Editar"
          >
            <Pencil size={16} />
          </button>
          <button 
            onClick={() => handleDelete(tenant.id)}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
            title="Eliminar"
          >
            <Trash2 size={16} />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <DataTable<Tenant>
        title="Gestión de Tenants"
        primaryActionLabel="Nuevo Tenant"
        onPrimaryAction={openCreateModal}
        metricCards={metricCards}
        tabs={[
          { id: 'all', label: 'Todos los tenants', count: safeTenants.length },
        ]}
        activeTab={activeTab}
        onTabChange={setActiveTab}
        searchTerm={searchTerm}
        onSearchChange={handleSearchChange}
        filterConfigs={tenantFilterConfigs}
        filterValues={filters}
        onFilterChange={handleFilterChange}
        columns={columns}
        data={safeTenants}
        getItemKey={(tenant) => tenant.id}
        entityLabel="tenants registrados"
      />

      <FormTenant
        isOpen={showFormModal}
        onClose={() => setShowFormModal(false)}
        onSubmit={handleFormSubmit}
        initialData={editingTenant}
      />

      <TenantDetailsModal
        isOpen={Boolean(viewingTenant)}
        onClose={() => setViewingTenant(null)}
        tenant={viewingTenant}
      />

      <AppModal
        isOpen={modalConfig.isOpen}
        type={modalConfig.type || 'alert'}
        variant={modalConfig.variant || 'default'}
        title={modalConfig.title}
        message={modalConfig.message}
        confirmText={modalConfig.confirmText || 'Aceptar'}
        cancelText={modalConfig.cancelText || 'Cancelar'}
        onConfirm={modalConfig.onConfirm || (() => setModalConfig({ ...modalConfig, isOpen: false }))}
        onClose={() => setModalConfig({ ...modalConfig, isOpen: false })}
        isLoading={isActionLoading}
      />
    </div>
  );
}