'use client';

import { useState, useEffect } from 'react';
import { Building2, Pencil, Trash2, Eye, CheckCircle, XCircle, LogIn } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormTenant from './FormTenant';
import Link from 'next/link';
import { TENANT_STATUS } from '../tenantStatus';
import { useAuth } from '@/hooks/useAuth';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';

interface Tenant {
  id: string;
  name: string;
  slug: string;
  timezone: string;
  status: string;
  created_at: string;
  business_type?: { name: string; short_name: string } | null;
  subscription?: { status?: string; plan?: { name?: string; is_public_catalog?: boolean } | null } | null;
  users?: Array<{ is_owner?: boolean; account_type?: string }>;
}

export default function TenantsTable() {
  const { user, startImpersonation } = useAuth();
  const canManage = Boolean(user?.is_saas_admin);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');

  const [filters, setFilters] = useState<Record<string, any>>({ status: '' });

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingTenant, setEditingTenant] = useState<Tenant | null>(null);

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

  const tenantFilterConfigs: FilterConfig[] = [
    {
      id: 'status',
      label: 'Estado',
      type: 'select',
      options: [
        { label: 'Activo', value: 'active' },
        { label: 'Suspendido', value: 'suspended' },
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

  const totalActiveTenants = safeTenants.filter(t => t.status === 'active').length;
  const totalInactiveTenants = safeTenants.filter(t => t.status !== 'active').length;

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
      title: 'Tenants suspendidos o inactivos',
      value: totalInactiveTenants,
      icon: <XCircle size={18} />,
      iconColorClass: 'text-slate-400',
      onClick: () => handleFilterChange('status', filters.status === 'suspended' ? '' : 'suspended'),
      borderColorClass: filters.status === 'suspended' ? 'border-slate-500 ring-2 ring-slate-100 shadow-sm' : 'hover:border-slate-300',
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
    if (editingTenant) {
      const { name, slug, timezone, status } = formData;
      await api.put(`/tenants/${editingTenant.id}`, { name, slug, timezone, status });
    } else {
      const res = await api.post('/tenants', formData);
      const owner = res.data?.owner;

      if (owner?.temporary_password) {
        setModalConfig({
          isOpen: true,
          type: 'alert',
          title: '¡Empresa creada!',
          message: (
            <span className="block space-y-4 pt-1">
              <span className="block text-sm text-slate-600">
                {res.data?.data?.name} quedó lista para operar. Estas son las credenciales de su administrador:
              </span>
              <span className="block space-y-2.5 p-4 bg-slate-50 border border-slate-200/80 rounded-2xl">
                <span className="flex items-center justify-between gap-3">
                  <span className="text-sm font-medium text-slate-500">Correo</span>
                  <span className="text-xs font-mono font-semibold text-slate-800 bg-white px-3 py-1.5 rounded-lg border border-slate-200 select-all">{owner.email}</span>
                </span>
                <span className="flex items-center justify-between gap-3">
                  <span className="text-sm font-medium text-slate-500">Contraseña</span>
                  <span className="text-xs font-mono font-bold text-slate-900 bg-white px-3 py-1.5 rounded-lg border border-slate-200 select-all">{owner.temporary_password}</span>
                </span>
              </span>
              <span className="block text-xs text-slate-500 bg-slate-100/80 border border-slate-200/60 p-3 rounded-xl font-medium">
                Cópiala ahora: no se vuelve a mostrar.
              </span>
            </span>
          ),
          confirmText: 'Entendido',
        });
      }
    }

    setShowFormModal(false);
    fetchTenants(searchTerm, filters);
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

  const handleImpersonate = (tenant: Tenant) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      title: `¿Entrar al panel de ${tenant.name}?`,
      message: 'Vas a ver y usar su panel como su administrador para darle soporte. El ingreso queda registrado y la sesión se cierra sola a las 2 horas.',
      confirmText: 'Entrar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await startImpersonation(tenant.id);
        } catch (error: any) {
          setIsActionLoading(false);
          setModalConfig({
            isOpen: true,
            type: 'alert',
            title: 'No se pudo entrar',
            message: error.response?.data?.message || 'No se pudo abrir el panel de la empresa.',
          });
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
            <Link href={`/admin/tenants/${tenant.id}`} className={`${styles.customerName} hover:text-[#3F768C] hover:underline`}>
              {tenant.name}
            </Link>
            <span className="block text-xs text-slate-400 font-mono">
              slug: {tenant.slug}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Rubro',
      cell: (tenant) => (
        <span key={`type-${tenant.id}`} className="text-xs text-slate-600">
          {tenant.business_type?.short_name ?? <span className="text-slate-400 italic">Sin rubro</span>}
        </span>
      ),
    },
    {
      header: 'Plan',
      cell: (tenant) => (
        <div key={`plan-${tenant.id}`} className="text-xs">
          {tenant.subscription?.plan?.name ? (
            <>
              <span className="font-semibold text-slate-700">{tenant.subscription.plan.name}</span>
              {tenant.subscription.plan.is_public_catalog === false && <span className="text-slate-400"> · a medida</span>}
              <span className="block text-[11px] text-slate-400">
                {({ active: 'Activa', trialing: 'En prueba', past_due: 'Pago vencido', expired: 'Prueba vencida', canceled: 'Cancelada' } as Record<string, string>)[tenant.subscription.status ?? ''] ?? tenant.subscription.status}
              </span>
            </>
          ) : (
            <span className="text-slate-400 italic">Sin plan</span>
          )}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (tenant) => {
        const status = TENANT_STATUS[tenant.status] ?? TENANT_STATUS.inactive;
        return (
          <span key={`status-${tenant.id}`} className={`px-2 py-0.5 text-xs rounded-full font-medium ${status.className}`}>
            {status.label}
          </span>
        );
      },
    },
    {
      header: 'Acciones',
      cell: (tenant) => (
        <div key={`actions-${tenant.id}`} className="flex items-center gap-2">
          <Link
            href={`/admin/tenants/${tenant.id}`}
            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
            title="Ver detalles"
          >
            <Eye size={16} />
          </Link>
          <button
            onClick={() => handleImpersonate(tenant)}
            className="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
            title="Entrar al panel de la empresa"
          >
            <LogIn size={16} />
          </button>
          {canManage && (
            <>
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
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <DataTable<Tenant>
        title="Gestión de Tenants"
        primaryActionLabel={canManage ? 'Nuevo Tenant' : undefined}
        onPrimaryAction={canManage ? openCreateModal : undefined}
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
        dateFilters={[
          { id: 'created_at', label: 'Fecha de alta', value: (t) => t.created_at },
        ]}
        sortOptions={[
          { id: 'created_at', label: 'Fecha de alta', type: 'date', value: (t) => t.created_at },
          { id: 'name', label: 'Nombre', value: (t) => t.name },
          { id: 'business_type', label: 'Rubro', value: (t) => t.business_type?.name },
          { id: 'plan', label: 'Plan', value: (t) => t.subscription?.plan?.name },
          { id: 'status', label: 'Estado', value: (t) => t.status },
        ]}
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