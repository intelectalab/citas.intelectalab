'use client';

import { useState, useEffect } from 'react';
import { Building2, Pencil, Trash2, Eye, CheckCircle, XCircle, LogIn, Ban } from 'lucide-react';
import SuspendTenantDrawer from './SuspendTenantDrawer';
import { DeleteTenantDrawer } from './TenantDeletionDrawers';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormTenant from './FormTenant';
import Link from 'next/link';
import { TENANT_STATUS } from '../tenantStatus';
import { useAuth } from '@/hooks/useAuth';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import ImpersonateDrawer from '@/features/support/components/ImpersonateDrawer';
import { api } from '@/lib/axios';
import { BILLING_STATES, type BillingState } from '@/features/billing/billing';
import { money } from '@/features/sales/sales';

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
  billing_state?: BillingState;
  mrr?: number;
  active_users?: number;
  appointments_30d?: number;
  trial_ends_on?: string | null;
}

const SUBSCRIPTION_STATUS: Record<string, string> = { active: 'Activa', trialing: 'En prueba', past_due: 'Pago vencido', expired: 'Prueba vencida', canceled: 'Cancelada' };

export default function TenantsTable() {
  const { user } = useAuth();
  const [entering, setEntering] = useState<{ id: string; name: string } | null>(null);
  const canManage = Boolean(user?.is_saas_admin);
  const [suspending, setSuspending] = useState<Tenant | null>(null);
  const [deleting, setDeleting] = useState<Tenant | null>(null);
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
        { label: 'Suspendido o inactivo', value: 'not_active' },
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
      onClick: () => handleFilterChange('status', filters.status === 'not_active' ? '' : 'not_active'),
      borderColorClass: filters.status === 'not_active' ? 'border-slate-500 ring-2 ring-slate-100 shadow-sm' : 'hover:border-slate-300',
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
      await api.put(`/tenants/${editingTenant.id}`, { name, slug, timezone, status, reason: formData.reason });
    } else {
      const res = await api.post('/tenants', formData);
      const owner = res.data?.owner;

      if (owner?.invited) {
        setModalConfig({
          isOpen: true,
          type: 'alert',
          title: '¡Empresa creada!',
          message: (
            <span className="block space-y-3 pt-1" data-tenant-invited>
              <span className="block text-sm text-slate-600">
                {res.data?.data?.name} quedó lista. Le enviamos a <span className="font-semibold text-slate-800">{owner.email}</span> un correo de bienvenida para que cree su contraseña.
              </span>
              <span className="block text-xs text-slate-500 bg-slate-100/80 border border-slate-200/60 p-3 rounded-xl font-medium">
                El enlace vale 3 días. Si no le llega, en la página de la empresa (Personal → Acceso) puedes reenviarlo o darle una contraseña temporal.
              </span>
            </span>
          ),
          confirmText: 'Entendido',
        });
      } else if (owner?.temporary_password) {
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
                Cópiala ahora: no se vuelve a mostrar. Al entrar con ella, el sistema le pedirá crear su propia contraseña. (No se envió invitación porque el correo de la plataforma no está funcionando.)
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

  const handleImpersonate = (tenant: Tenant) => setEntering({ id: tenant.id, name: tenant.name });

  const columns: Column<Tenant>[] = [
    {
      header: 'Nombre del Tenant',
      cell: (tenant) => (
        <div key={`name-${tenant.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            <Building2 size={16} />
          </div>
          <div>
            <Link href={`/admin/tenants/${tenant.id}`} className={`${styles.customerName} hover:text-brand-accent hover:underline`}>
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
              <span className={`block text-[11px] ${tenant.billing_state === 'locked' ? 'text-rose-600 font-semibold' : tenant.billing_state === 'grace' ? 'text-amber-700 font-semibold' : 'text-slate-400'}`} data-tenant-billing={tenant.billing_state}>
                {tenant.billing_state && BILLING_STATES[tenant.billing_state] ? BILLING_STATES[tenant.billing_state].label : SUBSCRIPTION_STATUS[tenant.subscription.status ?? ''] ?? tenant.subscription.status}
              </span>
            </>
          ) : (
            <span className="text-slate-400 italic">Sin plan</span>
          )}
        </div>
      ),
    },
    {
      header: 'Ingreso al mes',
      cell: (tenant) => (
        <div key={`mrr-${tenant.id}`} className="text-xs" data-tenant-mrr>
          <span className="font-semibold text-slate-700 tabular-nums">{money(tenant.mrr ?? 0)}</span>
          {tenant.trial_ends_on && <span className="block text-[11px] text-sky-700">Prueba hasta el {new Date(`${tenant.trial_ends_on}T12:00:00`).toLocaleDateString('es-PE', { day: 'numeric', month: 'short' })}</span>}
        </div>
      ),
    },
    {
      header: 'Uso',
      cell: (tenant) => (
        <div key={`use-${tenant.id}`} className="text-xs text-slate-600" data-tenant-use>
          <span className="block">{tenant.active_users ?? 0} {(tenant.active_users ?? 0) === 1 ? 'usuario' : 'usuarios'}</span>
          <span className="block text-[11px] text-slate-400">{tenant.appointments_30d ?? 0} citas en 30 días</span>
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
              {tenant.status === 'active' && (
                <button
                  onClick={() => setSuspending(tenant)}
                  className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                  title="Suspender"
                  aria-label={`Suspender ${tenant.name}`}
                >
                  <Ban size={16} />
                </button>
              )}
              <button
                onClick={() => setDeleting(tenant)}
                className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                title="Borrar empresa"
                aria-label={`Borrar ${tenant.name}`}
              >
                <Trash2 size={16} />
              </button>
            </>
          )}
        </div>
      ),
    },
  ];

  const exportColumns: ExportColumn<Tenant>[] = [
    { header: 'Empresa', value: (t) => t.name },
    { header: 'Identificador', value: (t) => t.slug },
    { header: 'Rubro', value: (t) => t.business_type?.name },
    { header: 'Plan', value: (t) => t.subscription?.plan?.name },
    { header: 'Plan a medida', value: (t) => (t.subscription?.plan ? (t.subscription.plan.is_public_catalog === false ? 'Sí' : 'No') : null) },
    { header: 'Suscripción', value: (t) => (t.billing_state ? BILLING_STATES[t.billing_state].label : t.subscription?.status ? SUBSCRIPTION_STATUS[t.subscription.status] ?? t.subscription.status : null) },
    { header: 'Estado', value: (t) => (TENANT_STATUS[t.status] ?? TENANT_STATUS.inactive).label },
    { header: 'Ingreso al mes', value: (t) => t.mrr ?? 0 },
    { header: 'Usuarios activos', value: (t) => t.active_users ?? 0 },
    { header: 'Citas en 30 días', value: (t) => t.appointments_30d ?? 0 },
    { header: 'Fin de la prueba', type: 'date', value: (t) => t.trial_ends_on ?? null },
    { header: 'Zona horaria', value: (t) => t.timezone },
    { header: 'Fecha de alta', type: 'date', value: (t) => t.created_at },
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
        exportTitle="Empresas"
        exportScreen="admin-tenants"
        exportColumns={exportColumns}
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

      {entering && <ImpersonateDrawer isOpen onClose={() => setEntering(null)} tenant={entering} />}
      {deleting && (
        <DeleteTenantDrawer
          isOpen
          onClose={() => setDeleting(null)}
          tenant={deleting}
          onSubmit={async (values) => {
            const res = await api.delete(`/tenants/${deleting.id}`, { data: values });
            fetchTenants(searchTerm, filters);
            setModalConfig({ isOpen: true, type: 'alert', title: 'Borrado programado', message: res.data?.message ?? 'La empresa quedó desactivada.', confirmText: 'Entendido' });
          }}
        />
      )}
      {suspending && <SuspendTenantDrawer isOpen onClose={() => setSuspending(null)} onDone={() => fetchTenants(searchTerm, filters)} tenant={suspending} />}
    </div>
  );
}