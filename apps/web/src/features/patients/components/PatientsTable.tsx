'use client';

import { useState, useEffect } from 'react';
import { User, Pencil, Trash2, Users, Mars, Venus, Phone, MapPin } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import FormPatient from './FormPatient';
import { api } from '@/lib/axios';

interface Patient {
  id: string;
  code: string;
  first_name: string;
  last_name: string;
  biological_sex?: string;
  email?: string;
  phone?: string;
  document_number?: string;
  district?: string;
  status?: {
    id: string;
    name: string;
    slug?: string;
  };
}

export default function PatientsTable() {
  const [patients, setPatients] = useState<Patient[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ biological_sex: '' });

  const [showFormModal, setShowFormModal] = useState(false);
  const [editingPatient, setEditingPatient] = useState<Patient | null>(null);

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

  const fetchPatients = async (search = '', currentFilters = filters) => {
    try {
      setLoading(true);
      const res = await api.get('/patients', {
        params: { search, ...currentFilters }
      });
      const responseData = res.data;
      if (Array.isArray(responseData)) {
        setPatients(responseData);
      } else if (responseData && Array.isArray(responseData.data)) {
        setPatients(responseData.data);
      } else {
        setPatients([]);
      }
    } catch (error) {
      console.error('Error al cargar pacientes:', error);
      setPatients([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPatients();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchPatients(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchPatients(searchTerm, updatedFilters);
  };

  // Función corregida para hacer funcionales los clics en las tarjetas métricas
  const handleMetricCardClick = (sexValue: string) => {
    const newSex = filters.biological_sex === sexValue ? '' : sexValue;
    const updatedFilters = { ...filters, biological_sex: newSex };
    setFilters(updatedFilters);
    fetchPatients(searchTerm, updatedFilters);
  };

  const handleDelete = (id: string) => {
    setModalConfig({
      isOpen: true,
      type: 'confirm',
      variant: 'danger',
      title: '¿Eliminar paciente?',
      message: '¿Estás seguro de eliminar este paciente? Esta acción se puede revertir desde la papelera.',
      confirmText: 'Sí, eliminar',
      cancelText: 'Cancelar',
      onConfirm: async () => {
        try {
          setIsActionLoading(true);
          await api.delete(`/patients/${id}`);
          fetchPatients(searchTerm, filters);
          setModalConfig(prev => ({ ...prev, isOpen: false }));
        } catch (error) {
          console.error('Error al eliminar paciente:', error);
        } finally {
          setIsActionLoading(false);
        }
      }
    });
  };

  const safePatients = Array.isArray(patients) ? patients : [];
  const totalMale = safePatients.filter(p => p.biological_sex === 'M' || p.biological_sex?.toLowerCase() === 'masculino').length;
  const totalFemale = safePatients.filter(p => p.biological_sex === 'F' || p.biological_sex?.toLowerCase() === 'femenino').length;

  const metricCards: MetricCardItem[] = [
    {
      title: 'Pacientes Masculinos',
      value: totalMale,
      icon: <Mars size={18} />,
      iconColorClass: 'text-blue-500',
      borderColorClass: filters.biological_sex === 'M' ? 'border-blue-500 ring-2 ring-blue-500/20' : 'hover:border-blue-300',
      onClick: () => handleMetricCardClick('M'),
    },
    {
      title: 'Pacientes Femeninos',
      value: totalFemale,
      icon: <Venus size={18} />,
      iconColorClass: 'text-pink-500',
      borderColorClass: filters.biological_sex === 'F' ? 'border-pink-500 ring-2 ring-pink-500/20' : 'hover:border-pink-300',
      onClick: () => handleMetricCardClick('F'),
    }
  ];

  const columns: Column<Patient>[] = [
    {
      header: 'Código',
      cell: (pat) => (
        <div key={`code-${pat.id}`} className="font-mono text-xs font-semibold text-slate-700">
          {pat.code ? pat.code : <span className="text-slate-400 font-normal italic">Sin código</span>}
        </div>
      ),
    },
    {
      header: 'Paciente',
      cell: (pat) => (
        <div key={`name-${pat.id}`} className={styles.customerCell}>
          <div className={styles.avatar}>
            <User size={16} />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className={styles.customerName}>{`${pat.first_name} ${pat.last_name}`}</span>
            </div>
            <span className="block text-xs text-slate-400 font-mono">
              {pat.email ? pat.email : 'Sin correo electrónico'}
            </span>
          </div>
        </div>
      ),
    },
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
    {
      header: 'Sexo Biológico',
      cell: (pat) => {
        const sex = pat.biological_sex;
        const label = sex === 'M' ? 'Masculino' : sex === 'F' ? 'Femenino' : 'Otro';
        const badgeColor = sex === 'M' ? 'bg-blue-50 text-blue-600' : sex === 'F' ? 'bg-pink-50 text-pink-600' : 'bg-slate-100 text-slate-600';
        return (
          <span key={`sex-${pat.id}`} className={`px-2.5 py-1 text-xs rounded-full font-medium ${badgeColor}`}>
            {label}
          </span>
        );
      },
    },
    {
      header: 'Acciones',
      cell: (pat) => (
        <div key={`actions-${pat.id}`} className="flex items-center gap-1">
          <button 
            onClick={() => { setEditingPatient(pat); setShowFormModal(true); }}
            title="Editar paciente"
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
          >
            <Pencil size={16} />
          </button>
          <button 
            onClick={() => handleDelete(pat.id)}
            title="Eliminar paciente"
            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          >
            <Trash2 size={16} />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      <div className="mb-6">
        <DataTable<Patient>
          title="Gestión de Pacientes"
          primaryActionLabel="Nuevo Paciente"
          onPrimaryAction={() => { setEditingPatient(null); setShowFormModal(true); }}
          tabs={[{ id: 'all', label: 'Todos los pacientes', count: safePatients.length }]}
          activeTab={activeTab}
          onTabChange={setActiveTab}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          columns={columns}
          data={safePatients}
          getItemKey={(p) => p.id}
          entityLabel="pacientes registrados"
          metricCards={metricCards}
        />
      </div>

      <FormPatient
        isOpen={showFormModal}
        onClose={() => { setShowFormModal(false); setEditingPatient(null); }}
        initialData={editingPatient}
        onSubmitSuccess={() => fetchPatients(searchTerm, filters)}
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