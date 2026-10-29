'use client';

import Badge from '@/app/components/common/Badge';
import { useState, useEffect } from 'react';
import { Ticket, Pencil, Trash2, Users, Store, Contact, CalendarDays, CheckCircle, Clock, XCircle, Infinity, Eye } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormSaaSPlan from './FormSaaSPlan';
import Link from 'next/link';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { money } from '@/features/sales/sales';

interface SaaSPlan {
  id: string;
  name: string;
  slug: string;
  price_monthly: number;
  price_yearly: number;
  currency?: string;
  modules?: Record<string, any>;
  limits?: {
    usuarios?: number;
    sucursales?: number;
    sedes?: number;
    professionals?: number;
    appointments_month?: number;
    [key: string]: any;
  };
  trial_config?: {
    has_trial: boolean;
    trial_days: number;
    require_payment_at_start: boolean;
  };
  features: Record<string, any>;
  status: string;
  subscriptions_count?: number;
  active_subscriptions_count?: number;
  created_at?: string;
}

export default function SaaSPlansTable() {
  const [plans, setPlans] = useState<SaaSPlan[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ status: '', has_trial: '' });

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingPlan, setEditingPlan] = useState<SaaSPlan | null>(null);


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

  const fetchPlans = async (search = '', currentFilters = filters) => {
    try {
      setLoading(true);
      const res = await api.get('/saas-plans', {
        params: { search, ...currentFilters }
      });
      const responseData = res.data;
      if (Array.isArray(responseData)) {
        setPlans(responseData);
      } else if (responseData && Array.isArray(responseData.data)) {
        setPlans(responseData.data);
      } else if (responseData && Array.isArray(responseData.plans)) {
        setPlans(responseData.plans);
      } else {
        setPlans([]);
      }
    } catch (error) {
      console.error('Error al cargar planes:', error);
      setPlans([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPlans();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchPlans(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchPlans(searchTerm, updatedFilters);
  };

  const handleFormSubmit = async (formData: any) => {
    try {
      if (editingPlan) {
        await api.put(`/saas-plans/${editingPlan.id}`, formData);
      } else {
        await api.post('/saas-plans', formData);
      }
      setShowFormModal(false);
      fetchPlans(searchTerm, filters);
    } catch (error: any) {
      if (error.response && error.response.status === 422) {
        const serverErrors = error.response.data.errors;
        
        if (serverErrors?.slug) {
          setModalConfig({
            isOpen: true,
            type: 'alert',
            variant: 'danger',
            title: 'Nombre o Slug duplicado',
            message: 'Ya existe un plan registrado con este mismo identificador (slug). Por favor, cambia el nombre del plan para generar uno nuevo.',
            confirmText: 'Entendido',
            onConfirm: () => setModalConfig(prev => ({ ...prev, isOpen: false })),
          });
          return;
        }
      }

      setModalConfig({
        isOpen: true,
        type: 'alert',
        variant: 'danger',
        title: 'Ocurrió un error',
        message: 'No se pudo guardar el plan. Por favor, verifica los datos e inténtalo de nuevo.',
        confirmText: 'Entendido',
        onConfirm: () => setModalConfig(prev => ({ ...prev, isOpen: false })),
      });
    }
  };

  const handleDelete = (id: string) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      variant: 'danger',
      title: '¿Eliminar plan SaaS?',
      message: '¿Estás seguro de eliminar este plan? Esta acción no se puede deshacer.',
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await api.delete(`/saas-plans/${id}`);
          fetchPlans(searchTerm, filters);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error: any) {
          console.error('Error al eliminar plan:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const safePlans = Array.isArray(plans) ? plans : [];

  const totalActivePlans = safePlans.filter(p => p.status === 'active').length;
  const totalInactivePlans = safePlans.filter(p => p.status === 'inactive').length;
  const plansWithTrial = safePlans.filter(p => p.trial_config?.has_trial).length;

  const metricCards: MetricCardItem[] = [
    {
      title: 'Planes activos',
      value: totalActivePlans,
      icon: <CheckCircle size={18} />,
      iconColorClass: 'text-emerald-500',
      onClick: () => handleFilterChange('status', filters.status === 'active' ? '' : 'active'),
      borderColorClass: filters.status === 'active' ? 'border-emerald-500 ring-2 ring-emerald-100' : 'hover:border-emerald-300',
    },
    {
      title: 'Planes inactivos',
      value: totalInactivePlans,
      icon: <XCircle size={18} />,
      iconColorClass: 'text-slate-400',
      onClick: () => handleFilterChange('status', filters.status === 'inactive' ? '' : 'inactive'),
      borderColorClass: filters.status === 'inactive' ? 'border-slate-500 ring-2 ring-slate-100' : 'hover:border-slate-300',
    },
    {
      title: 'Con periodo de prueba',
      value: plansWithTrial,
      icon: <Clock size={18} />,
      iconColorClass: 'text-blue-500',
      onClick: () => handleFilterChange('has_trial', filters.has_trial === 'true' ? '' : 'true'),
      borderColorClass: filters.has_trial === 'true' ? 'border-blue-500 ring-2 ring-blue-100' : 'hover:border-blue-300',
    }
  ];

  const columns: Column<SaaSPlan>[] = [
    {
      header: 'Plan',
      cell: (plan) => (
        <div key={`name-${plan.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            <Ticket size={16} />
          </div>
          <div>
            <Link href={`/admin/plans/${plan.id}`} className={`${styles.customerName} hover:text-brand-accent hover:underline`}>
              {plan.name}
            </Link>
            <span className="block text-xs text-slate-400 font-mono">slug: {plan.slug}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Precio mensual',
      cell: (plan) => (
        <div key={`price-monthly-${plan.id}`} className="text-sm font-semibold text-slate-900">
          {money(Number(plan.price_monthly), plan.currency || 'PEN')}
        </div>
      ),
    },
    {
      header: 'Precio anual',
      cell: (plan) => (
        <div key={`price-yearly-${plan.id}`} className="text-sm text-slate-600">
          {money(Number(plan.price_yearly), plan.currency || 'PEN')}
        </div>
      ),
    },
    {
      header: 'Módulos',
      cell: (plan) => {
        let totalModulesCount = 0;

        if (plan.modules && typeof plan.modules === 'object') {
          Object.values(plan.modules).forEach((categoryItems) => {
            if (Array.isArray(categoryItems)) {
              totalModulesCount += categoryItems.length;
            }
          });
        }

        return (
          <div key={`modules-${plan.id}`}>
            <span className="px-2.5 py-1 text-xs font-semibold bg-indigo-50 text-indigo-700 rounded-lg border border-indigo-100">
              {totalModulesCount} {totalModulesCount === 1 ? 'módulo' : 'módulos'}
            </span>
          </div>
        );
      },
    },
    {
      header: 'Límites clave',
      cell: (plan) => {
        const users = plan.limits?.users ?? plan.limits?.usuarios ?? 0;
        const branches = plan.limits?.branches ?? plan.limits?.sucursales ?? plan.limits?.sedes ?? 0;
        const professionals = plan.limits?.professionals ?? 0;
        const appointments = plan.limits?.appointments_month ?? 0;

        const renderLimitValue = (value: number) => {
          if (value === -1) {
            return (
              <span title="Ilimitado" className="inline-flex items-center">
                <Infinity size={14} className="text-indigo-500" />
              </span>
            );
          }
          return <span className="font-medium text-slate-800">{value.toLocaleString('es-PE')}</span>;
        };

        return (
          <div key={`limits-${plan.id}`} className="flex items-center gap-3 text-xs text-slate-600 whitespace-nowrap" data-plan-limits>
            <div className="flex items-center gap-1" title="Usuarios: todas las cuentas del personal, incluidos los profesionales. El dueño no cuenta." data-limit="users">
              <Users size={14} className="text-slate-400" />
              {renderLimitValue(users)}
            </div>
            <div className="flex items-center gap-1" title="Profesionales: los que atienden citas. Cada uno gasta un usuario." data-limit="professionals">
              <Contact size={14} className="text-slate-400" />
              {renderLimitValue(professionals)}
            </div>
            <div className="flex items-center gap-1" title="Sedes" data-limit="branches">
              <Store size={14} className="text-slate-400" />
              {renderLimitValue(branches)}
            </div>
            <div className="flex items-center gap-1" title="Citas al mes" data-limit="appointments_month">
              <CalendarDays size={14} className="text-slate-400" />
              {renderLimitValue(appointments)}
            </div>
          </div>
        );
      },
    },
    {
      header: 'Prueba',
      cell: (plan) => (
        <div key={`trial-${plan.id}`}>
          {plan.trial_config?.has_trial ? (
            <span className="px-2.5 py-1 text-xs font-medium bg-blue-50 text-blue-700 rounded-full">
              {plan.trial_config.trial_days} días
            </span>
          ) : (
            <span className="px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-500 rounded-full">
              No
            </span>
          )}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (plan) => (
        <Badge key={`status-${plan.id}`} tone={
          plan.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'
        }>
          {plan.status === 'active' ? 'Activo' : 'Inactivo'}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      cell: (plan) => (
        <div key={`actions-${plan.id}`} className="flex items-center gap-1">
          <Link
            href={`/admin/plans/${plan.id}`}
            title="Ver detalles"
            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
          >
            <Eye size={16} />
          </Link>
          <button 
            onClick={() => { setEditingPlan(plan); setShowFormModal(true); }}
            title="Editar plan"
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
          >
            <Pencil size={16} />
          </button>
          <button 
            onClick={() => handleDelete(plan.id)}
            title="Eliminar plan"
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          >
            <Trash2 size={16} />
          </button>
        </div>
      ),
    },
  ];

  const limitOf = (value: number | undefined) => (value === -1 ? 'Sin límite' : value ?? 0);
  const modulesOf = (plan: SaaSPlan) => Object.values(plan.modules ?? {}).reduce((sum: number, items) => sum + (Array.isArray(items) ? items.length : 0), 0);

  const exportColumns: ExportColumn<SaaSPlan>[] = [
    { header: 'Plan', value: (p) => p.name },
    { header: 'Identificador', value: (p) => p.slug },
    { header: 'Precio mensual', type: 'number', value: (p) => Number(p.price_monthly) },
    { header: 'Precio anual', type: 'number', value: (p) => Number(p.price_yearly) },
    { header: 'Moneda', value: (p) => p.currency || 'PEN' },
    { header: 'Módulos', type: 'number', value: modulesOf },
    { header: 'Usuarios', value: (p) => limitOf(p.limits?.users ?? p.limits?.usuarios) },
    { header: 'Profesionales', value: (p) => limitOf(p.limits?.professionals) },
    { header: 'Sedes', value: (p) => limitOf(p.limits?.branches ?? p.limits?.sucursales ?? p.limits?.sedes) },
    { header: 'Citas al mes', value: (p) => limitOf(p.limits?.appointments_month) },
    { header: 'Días de prueba', value: (p) => (p.trial_config?.has_trial ? p.trial_config.trial_days : 'No') },
    { header: 'Empresas suscritas', type: 'number', value: (p) => p.active_subscriptions_count ?? p.subscriptions_count ?? 0 },
    { header: 'Estado', value: (p) => (p.status === 'active' ? 'Activo' : 'Inactivo') },
    { header: 'Fecha de creación', type: 'date', value: (p) => p.created_at },
  ];

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<SaaSPlan>
          title="Planes SaaS"
          primaryActionLabel="Nuevo Plan"
          onPrimaryAction={() => { setEditingPlan(null); setShowFormModal(true); }}
          tabs={[{ id: 'all', label: 'Todos los planes', count: safePlans.length }]}
          activeTab={activeTab}
          onTabChange={setActiveTab}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          extraFilterCount={filters.has_trial ? 1 : 0}
          onClearFilters={() => { const cleared = { status: '', has_trial: '' }; setFilters(cleared); fetchPlans(searchTerm, cleared); }}
          columns={columns}
          dateFilters={[
            { id: 'created_at', label: 'Fecha de creación', value: (p) => p.created_at },
          ]}
          sortOptions={[
            { id: 'name', label: 'Nombre', value: (p) => p.name },
            { id: 'price_monthly', label: 'Precio mensual', type: 'number', value: (p) => Number(p.price_monthly) },
            { id: 'price_yearly', label: 'Precio anual', type: 'number', value: (p) => Number(p.price_yearly) },
            { id: 'subscriptions', label: 'Empresas suscritas', type: 'number', value: (p) => p.active_subscriptions_count ?? p.subscriptions_count ?? 0 },
            { id: 'status', label: 'Estado', value: (p) => p.status },
            { id: 'created_at', label: 'Fecha de creación', type: 'date', value: (p) => p.created_at },
          ]}
          data={safePlans}
          exportTitle="Planes"
          exportScreen="admin-plans"
          exportColumns={exportColumns}
          getItemKey={(p) => p.id}
          entityLabel="planes registrados"
          metricCards={metricCards}
        />
      </div>

      <FormSaaSPlan
        isOpen={showFormModal}
        onClose={() => setShowFormModal(false)}
        onSubmit={handleFormSubmit}
        initialData={editingPlan}
        totalPlansCount={safePlans.length}
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
        onConfirm={modalConfig.onConfirm || (() => setModalConfig({ ...modalConfig, isOpen: false }))}
        onClose={() => setModalConfig({ ...modalConfig, isOpen: false })}
      />
    </div>
  );
}