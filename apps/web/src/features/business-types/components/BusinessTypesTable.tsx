'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { Layers, Pencil, Trash2, CheckCircle, XCircle, Eye, Building2, CircleDashed, RefreshCw, FolderX } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { MODULE_GROUPS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import ModulesBubble from './ModulesBubble';
import FormBusinessType, { type BusinessType } from './FormBusinessType';

export default function BusinessTypesTable() {
  const [types, setTypes] = useState<BusinessType[]>([]);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ status: '' });
  const [quickFilter, setQuickFilter] = useState<'' | 'in_use' | 'unused' | 'outdated' | 'no_catalog'>('');

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingType, setEditingType] = useState<BusinessType | null>(null);

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

  const fetchTypes = async (search = '', currentFilters = filters) => {
    try {
      const res = await api.get('/admin/business-types', { params: { search, ...currentFilters } });
      setTypes(Array.isArray(res.data) ? res.data : []);
    } catch (error) {
      console.error('Error al cargar rubros:', error);
      setTypes([]);
    }
  };

  useEffect(() => {
    fetchTypes();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchTypes(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchTypes(searchTerm, updatedFilters);
  };

  const handleDelete = (type: BusinessType) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      variant: 'danger',
      title: '¿Eliminar rubro?',
      message: `¿Estás seguro de eliminar "${type.name}"? Solo se puede eliminar si ninguna empresa lo usa.`,
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await api.delete(`/admin/business-types/${type.id}`);
          fetchTypes(searchTerm, filters);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error: any) {
          setModalConfig({
            isOpen: true,
            type: 'alert',
            title: 'No se pudo eliminar',
            message: error.response?.data?.message || 'Ocurrió un error al eliminar el rubro.',
          });
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const hasCatalog = (type: BusinessType) => (type.template?.categories ?? []).some((category: any) => (category.services ?? []).length > 0);

  const quickFilters = {
    in_use: (type: BusinessType) => (type.tenants_count ?? 0) > 0,
    unused: (type: BusinessType) => (type.tenants_count ?? 0) === 0,
    outdated: (type: BusinessType) => (type.outdated_tenants_count ?? 0) > 0,
    no_catalog: (type: BusinessType) => !hasCatalog(type),
  };

  const visibleTypes = quickFilter ? types.filter(quickFilters[quickFilter]) : types;
  const toggleQuick = (key: keyof typeof quickFilters) => setQuickFilter(quickFilter === key ? '' : key);
  const ring = (active: boolean, on: string, hover: string) => (active ? on : hover);

  const metricCards: MetricCardItem[] = [
    {
      title: 'Activos',
      value: types.filter(t => t.status === 'active').length,
      icon: <CheckCircle size={18} />,
      iconColorClass: 'text-emerald-500',
      onClick: () => handleFilterChange('status', filters.status === 'active' ? '' : 'active'),
      borderColorClass: ring(filters.status === 'active', 'border-emerald-500 ring-2 ring-emerald-100 shadow-sm', 'hover:border-emerald-300'),
    },
    {
      title: 'Inactivos',
      value: types.filter(t => t.status !== 'active').length,
      icon: <XCircle size={18} />,
      iconColorClass: 'text-slate-400',
      onClick: () => handleFilterChange('status', filters.status === 'inactive' ? '' : 'inactive'),
      borderColorClass: ring(filters.status === 'inactive', 'border-slate-500 ring-2 ring-slate-100 shadow-sm', 'hover:border-slate-300'),
    },
    {
      title: 'Con empresas',
      value: types.filter(quickFilters.in_use).length,
      icon: <Building2 size={18} />,
      iconColorClass: 'text-sky-500',
      onClick: () => toggleQuick('in_use'),
      borderColorClass: ring(quickFilter === 'in_use', 'border-sky-500 ring-2 ring-sky-100 shadow-sm', 'hover:border-sky-300'),
    },
    {
      title: 'Sin empresas',
      value: types.filter(quickFilters.unused).length,
      icon: <CircleDashed size={18} />,
      iconColorClass: 'text-slate-400',
      onClick: () => toggleQuick('unused'),
      borderColorClass: ring(quickFilter === 'unused', 'border-slate-500 ring-2 ring-slate-100 shadow-sm', 'hover:border-slate-300'),
    },
    {
      title: 'Cambios sin aplicar',
      value: types.filter(quickFilters.outdated).length,
      icon: <RefreshCw size={18} />,
      iconColorClass: 'text-amber-500',
      onClick: () => toggleQuick('outdated'),
      borderColorClass: ring(quickFilter === 'outdated', 'border-amber-500 ring-2 ring-amber-100 shadow-sm', 'hover:border-amber-300'),
    },
    {
      title: 'Sin catálogo inicial',
      value: types.filter(quickFilters.no_catalog).length,
      icon: <FolderX size={18} />,
      iconColorClass: 'text-rose-400',
      onClick: () => toggleQuick('no_catalog'),
      borderColorClass: ring(quickFilter === 'no_catalog', 'border-rose-400 ring-2 ring-rose-100 shadow-sm', 'hover:border-rose-300'),
    },
  ];

  const columns: Column<BusinessType>[] = [
    {
      header: 'Rubro',
      cell: (type) => (
        <div key={`name-${type.id}`} className={styles.customerCell}>
          <div className={styles.avatar} style={{ backgroundColor: `${type.color ?? '#3C7DC4'}20`, color: type.color ?? '#3C7DC4' }}>
            <Layers size={16} />
          </div>
          <div>
            <Link href={`/admin/business-types/${type.id}`} className={`${styles.customerName} hover:text-brand-accent hover:underline`}>
              {type.name}
            </Link>
            <span className="block text-xs text-slate-400 font-mono">slug: {type.slug}</span>
          </div>
        </div>
      ),
    },
    {
      header: 'Vocabulario',
      cell: (type) => (
        <div key={`vocab-${type.id}`} className="text-xs text-slate-600">
          <span>{type.vocabulary?.clients ?? 'Clientes'} · {type.vocabulary?.pros ?? 'Profesionales'}</span>
          {type.dependents?.enabled && (
            <span className="block text-[11px] text-slate-400">con {(type.vocabulary?.dependents ?? 'dependientes').toLowerCase()}</span>
          )}
        </div>
      ),
    },
    {
      header: 'Módulos',
      cell: (type) => (
        <div key={`modules-${type.id}`}>
          <ModulesBubble modulesOff={type.modules_off ?? []} />
        </div>
      ),
    },
    {
      header: 'Catálogo inicial',
      cell: (type) => {
        const categories = type.template?.categories ?? [];
        const services = categories.reduce((total: number, category: any) => total + (category.services?.length ?? 0), 0);
        return (
          <div key={`tpl-${type.id}`} className="text-xs text-slate-600">
            {services > 0 ? `${categories.length} categorías · ${services} servicios` : <span className="text-slate-400 italic">Sin catálogo</span>}
            {type.template?.record_template?.name && (
              <span className="block text-[11px] text-slate-400">{type.template.record_template.name}</span>
            )}
          </div>
        );
      },
    },
    {
      header: 'Empresas',
      cell: (type) => (
        <div key={`count-${type.id}`} className="flex items-center gap-2">
          <span className="text-sm font-semibold text-slate-700">{type.tenants_count ?? 0}</span>
          {(type.outdated_tenants_count ?? 0) > 0 && (
            <Link
              href={`/admin/business-types/${type.id}`}
              title="Empresas que aún no reciben los últimos cambios del rubro"
              className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-[11px] font-semibold text-amber-700 hover:bg-amber-100 whitespace-nowrap"
            >
              <RefreshCw size={11} /> {type.outdated_tenants_count} sin aplicar
            </Link>
          )}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (type) => (
        <span key={`status-${type.id}`} className={`px-2 py-0.5 text-xs rounded-full font-medium ${
          type.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'
        }`}>
          {type.status === 'active' ? 'Activo' : 'Inactivo'}
        </span>
      ),
    },
    {
      header: 'Acciones',
      cell: (type) => (
        <div key={`actions-${type.id}`} className="flex items-center gap-2">
          <Link
            href={`/admin/business-types/${type.id}`}
            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
            title="Ver detalles"
          >
            <Eye size={16} />
          </Link>
          <button
            onClick={() => { setEditingType(type); setShowFormModal(true); }}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
            title="Editar"
          >
            <Pencil size={16} />
          </button>
          <button
            onClick={() => handleDelete(type)}
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
            title="Eliminar"
          >
            <Trash2 size={16} />
          </button>
        </div>
      ),
    },
  ];

  const moduleLabels = new Map(MODULE_GROUPS.flatMap((group) => group.items.map((item) => [item.key, item.label] as const)));
  const modulesOff = (type: BusinessType) => (type.modules_off ?? []).filter((key) => OPTIONAL_MODULE_KEYS.includes(key));
  const servicesOf = (type: BusinessType) => (type.template?.categories ?? []).reduce((total: number, category: any) => total + (category.services?.length ?? 0), 0);

  const exportColumns: ExportColumn<BusinessType>[] = [
    { header: 'Rubro', value: (t) => t.name },
    { header: 'Nombre corto', value: (t) => t.short_name },
    { header: 'Identificador', value: (t) => t.slug },
    { header: 'Clientes se llaman', value: (t) => t.vocabulary?.clients ?? 'Clientes' },
    { header: 'Profesionales se llaman', value: (t) => t.vocabulary?.pros ?? 'Profesionales' },
    { header: 'Atiende a dependientes', value: (t) => (t.dependents?.enabled ? 'Sí' : 'No') },
    { header: 'Módulos encendidos', type: 'number', value: (t) => OPTIONAL_MODULE_KEYS.length - modulesOff(t).length },
    { header: 'Módulos que empiezan apagados', value: (t) => modulesOff(t).map((key) => moduleLabels.get(key) ?? key).join(', ') },
    { header: 'Categorías del catálogo', type: 'number', value: (t) => (servicesOf(t) > 0 ? (t.template?.categories ?? []).length : 0) },
    { header: 'Servicios del catálogo', type: 'number', value: servicesOf },
    { header: 'Ficha de atención', value: (t) => t.template?.record_template?.name },
    { header: 'Empresas', type: 'number', value: (t) => t.tenants_count ?? 0 },
    { header: 'Empresas sin los últimos cambios', type: 'number', value: (t) => t.outdated_tenants_count ?? 0 },
    { header: 'Estado', value: (t) => (t.status === 'active' ? 'Activo' : 'Inactivo') },
  ];

  return (
    <div className="space-y-6 mb-8">
      <DataTable<BusinessType>
        title="Plantillas de rubros"
        primaryActionLabel="Nueva plantilla"
        onPrimaryAction={() => { setEditingType(null); setShowFormModal(true); }}
        metricCards={metricCards}
        tabs={[
          { id: 'all', label: 'Todas las plantillas', count: visibleTypes.length },
        ]}
        activeTab={activeTab}
        onTabChange={setActiveTab}
        searchTerm={searchTerm}
        onSearchChange={handleSearchChange}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={handleFilterChange}
        columns={columns}
        sortOptions={[
          { id: 'name', label: 'Nombre', value: (t) => t.name },
          { id: 'tenants', label: 'Empresas', type: 'number', value: (t) => t.tenants_count ?? 0 },
          { id: 'modules_off', label: 'Módulos que empiezan apagados', type: 'number', value: (t) => t.modules_off?.length ?? 0 },
          { id: 'status', label: 'Estado', value: (t) => t.status },
        ]}
        data={visibleTypes}
        exportTitle="Rubros"
        exportScreen="admin-business-types"
        exportColumns={exportColumns}
        getItemKey={(type) => type.id}
        entityLabel="plantillas de rubro"
      />

      <FormBusinessType
        isOpen={showFormModal}
        onClose={() => setShowFormModal(false)}
        initialData={editingType}
        onSubmitSuccess={() => fetchTypes(searchTerm, filters)}
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
