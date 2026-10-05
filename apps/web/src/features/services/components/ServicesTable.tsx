'use client';

import { useState, useEffect } from 'react';
import { Clock, DollarSign, Tag, Pencil, Trash2 } from 'lucide-react';
import { DataTable, type Column, type FilterConfig } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormService from './FormService';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';

interface ServiceCategory {
  id: string;
  name: string;
}

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
  category_id: string | null;
  category?: ServiceCategory;
  status_id: string | null;
  status?: Status;
  name: string;
  duration_minutes: number;
  buffer_before_minutes: number;
  buffer_after_minutes: number;
  price: number;
}

export default function ServicesTable() {
  const [services, setServices] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  
  const [categoryOptions, setCategoryOptions] = useState<{ label: string; value: string }[]>([]);
  const [statusOptions, setStatusOptions] = useState<{ label: string; value: string; key: string }[]>([]);

  const [filters, setFilters] = useState<Record<string, any>>({
    category_id: '',
    status_id: ''
  });

  const [showModal, setShowModal] = useState(false);
  const [editingService, setEditingService] = useState<Service | null>(null);

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

  useEffect(() => {
    const fetchCategories = async () => {
      try {
        console.log('SERVICES_TABLE: Fetching categories...');
        const res = await api.get('/service-categories'); 
        console.log('SERVICES_TABLE: Categories response:', res.data);
        const options = res.data.map((cat: any) => ({
          label: cat.name,
          value: cat.id
        }));
        setCategoryOptions(options);
      } catch (error) {
        console.warn('SERVICES_TABLE: Error fetching categories:', error);
        setCategoryOptions([]);
      }
    };

    const fetchStatuses = async () => {
      try {
        console.log('SERVICES_TABLE: Fetching statuses...');
        const res = await api.get('/statuses');
        console.log('SERVICES_TABLE: All statuses received:', res.data);
        
        // Filtramos estrictamente los estados cuyo módulo sea 'services'
        const serviceStatuses = res.data.filter((st: any) => st.module?.name === 'services');
        console.log('SERVICES_TABLE: Filtered statuses for module "services":', serviceStatuses);

        const options = serviceStatuses.map((st: any) => ({
          label: st.name,
          value: st.id,
          key: st.id
        }));
        setStatusOptions(options);
      } catch (error) {
        console.warn('SERVICES_TABLE: Error fetching statuses:', error);
        setStatusOptions([]);
      }
    };

    fetchCategories();
    fetchStatuses();
  }, []);

  const serviceFilterConfigs: FilterConfig[] = [
    {
      id: 'category_id',
      label: 'Categoría',
      type: 'select',
      options: categoryOptions.map(cat => ({ ...cat, key: cat.value }))
    },
    {
      id: 'status_id',
      label: 'Estado',
      type: 'select',
      options: statusOptions
    }
  ];

  const fetchServices = async (search = searchTerm, currentFilters = filters, tab = activeTab) => {
    try {
      setLoading(true);
      
      const queryParams: Record<string, any> = {
        search,
        ...currentFilters,
      };

      // Si la pestaña no es 'all', podemos mandar el filtro por slug o tab si el backend lo soporta
      if (tab !== 'all') {
        queryParams.tab_status = tab; 
      }

      console.log('SERVICES_TABLE: Fetching services with params:', queryParams);
      const res = await api.get('/services', { params: queryParams });
      console.log('SERVICES_TABLE: Services response data:', res.data);
      
      setServices(res.data);
    } catch (error) {
      console.error('SERVICES_TABLE: Error loading services:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchServices();
  }, []);

  const handleSearchChange = (val: string) => {
    console.log('SERVICES_TABLE: Search term changed:', val);
    setSearchTerm(val);
    fetchServices(val, filters, activeTab);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    console.log('SERVICES_TABLE: Filter changed:', filterId, value);
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchServices(searchTerm, updatedFilters, activeTab);
  };

  const handleTabChange = (tabId: string) => {
    console.log('SERVICES_TABLE: Tab changed:', tabId);
    setActiveTab(tabId);
    const updatedFilters = tabId !== 'all' ? { ...filters, status_id: '' } : filters;
    setFilters(updatedFilters);
    fetchServices(searchTerm, updatedFilters, tabId);
  };

  const openCreateModal = () => {
    console.log('SERVICES_TABLE: Opening create modal');
    setEditingService(null);
    setShowModal(true);
  };

  const openEditModal = (service: Service) => {
    console.log('SERVICES_TABLE: Opening edit modal for service:', service);
    setEditingService(service);
    setShowModal(true);
  };

  const handleDelete = (id: string) => {
    console.log('SERVICES_TABLE: Requesting delete for service id:', id);
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      title: '¿Eliminar servicio?',
      message: '¿Estás seguro de que deseas eliminar este servicio? Esta acción no se puede deshacer.',
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          console.log('SERVICES_TABLE: Deleting service ID:', id);
          await api.delete(`/services/${id}`);
          fetchServices(searchTerm, filters, activeTab);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error) {
          console.error('SERVICES_TABLE: Error deleting service:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const columns: Column<Service>[] = [
    {
      header: 'Nombre del Servicio',
      cell: (service) => (
        <div key={`name-${service.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            {service.name[0]?.toUpperCase()}
          </div>
          <div>
            <span className={styles.customerName}>{service.name}</span>
            <span className="block text-xs text-slate-400">
              {service.category?.name || 'Sin categoría'}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Duración & Buffers',
      cell: (service) => (
        <div key={`duration-${service.id}`} className="flex flex-col text-xs text-slate-600 gap-0.5">
          <div className="flex items-center gap-1 font-medium">
            <Clock size={13} className="text-slate-400" />
            <span>{service.duration_minutes} mins</span>
          </div>
          {(service.buffer_before_minutes > 0 || service.buffer_after_minutes > 0) && (
            <span className="text-slate-400 text-[11px]">
              Buffers: {service.buffer_before_minutes}m / {service.buffer_after_minutes}m
            </span>
          )}
        </div>
      ),
    },
    {
      header: 'Precio',
      cell: (service) => (
        <div key={`price-${service.id}`} className="inline-flex items-center gap-1 font-semibold text-slate-700">
          <DollarSign size={14} className="text-slate-400" />
          <span>{Number(service.price).toFixed(2)}</span>
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
          <button 
            onClick={() => openEditModal(service)}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
            title="Editar"
          >
            <Pencil size={16} />
          </button>
          <button 
            onClick={() => handleDelete(service.id)}
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
    <div>
      <DataTable<Service>
        title="Catálogo de Servicios"
        primaryActionLabel="Nuevo Servicio"
        onPrimaryAction={openCreateModal}
        tabs={[
          { id: 'all', label: 'Todos', count: services.length },
          { id: 'active', label: 'Activos' },
          { id: 'inactive', label: 'Inactivos' },
        ]}
        activeTab={activeTab}
        onTabChange={handleTabChange}
        searchTerm={searchTerm}
        onSearchChange={handleSearchChange}
        filterConfigs={serviceFilterConfigs}
        filterValues={filters}
        onFilterChange={handleFilterChange}
        columns={columns}
        data={services}
        getItemKey={(service) => service.id}
        entityLabel="servicios registrados"
      />

      <FormService
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        initialData={editingService}
        categories={categoryOptions}
        onSubmitSuccess={() => {
          console.log('SERVICES_TABLE: Form submitted successfully, refreshing data...');
          fetchServices(searchTerm, filters, activeTab);
        }}
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