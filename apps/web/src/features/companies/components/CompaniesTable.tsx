'use client';

import { useState } from 'react';
import {
  Building2,
  Phone,
  MapPin,
  Globe2,
  Calendar,
  Clock,
  UserCheck
} from 'lucide-react';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';

export interface Company {
  id: string;
  name: string;
  logoUrl?: string;
  owner: string;
  createdAt: string;
  phone: string;
  lastActivity: string;
  city: string;
  country: string;
  isMine: boolean;
}

const initialCompanies: Company[] = [
  {
    id: '1',
    name: 'Juanita',
    owner: 'Geraldine Ignacio (rubi...',
    createdAt: '7 de jul. de 2026 23:49 GMT-5',
    phone: '--',
    lastActivity: '--',
    city: 'lima',
    country: '--',
    isMine: true,
  },
  {
    id: '2',
    name: 'HubSpot',
    owner: 'Sin propietario',
    createdAt: '7 de jul. de 2026 23:38 GMT-5',
    phone: '--',
    lastActivity: '10 de jul. de 2026 23:38 GMT-5',
    city: '--',
    country: '--',
    isMine: false,
  },
];

export default function CompaniesTable() {
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');

  const filteredCompanies = initialCompanies.filter((company) => {
    const matchesTab =
      activeTab === 'all' ||
      (activeTab === 'mine' && company.isMine);

    const matchesSearch =
      company.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      company.owner.toLowerCase().includes(searchTerm.toLowerCase()) ||
      company.city.toLowerCase().includes(searchTerm.toLowerCase());

    return matchesTab && matchesSearch;
  });

  const columns: Column<Company>[] = [
    {
      header: 'Nombre de la empresa',
      cell: (company) => (
        <div className={styles.customerCell}>
          <div className={styles.avatar}>
            <Building2 size={16} className="text-[#022340]" />
          </div>
          <span className={`${styles.customerName} font-semibold text-[#022340]`}>
            {company.name}
          </span>
        </div>
      ),
    },
    {
      header: 'Propietario del registro de empresa',
      cell: (company) => (
        <div className="flex items-center gap-2">
          <div className="w-6 h-6 rounded-full bg-slate-200 text-slate-700 text-[10px] font-bold flex items-center justify-center">
            {company.owner !== 'Sin propietario'
              ? company.owner.substring(0, 2).toUpperCase()
              : '--'}
          </div>
          <span className={company.owner === 'Sin propietario' ? 'text-slate-400 italic' : 'text-slate-700'}>
            {company.owner}
          </span>
        </div>
      ),
    },
    {
      header: 'Fecha de creación',
      cell: (company) => (
        <div className="flex items-center gap-1.5 text-slate-600 text-xs">
          <Calendar size={13} className="text-slate-400" />
          <span>{company.createdAt}</span>
        </div>
      ),
    },
    {
      header: 'Número de teléfono',
      cell: (company) => (
        <div className="flex items-center gap-1.5 text-slate-600">
          {company.phone !== '--' && <Phone size={13} className="text-slate-400" />}
          <span>{company.phone}</span>
        </div>
      ),
    },
    {
      header: 'Última actividad',
      cell: (company) => (
        <div className="flex items-center gap-1.5 text-slate-600 text-xs">
          {company.lastActivity !== '--' && <Clock size={13} className="text-slate-400" />}
          <span>{company.lastActivity}</span>
        </div>
      ),
    },
    {
      header: 'Ciudad',
      cell: (company) => (
        <div className="flex items-center gap-1.5 text-slate-700 capitalize">
          {company.city !== '--' && <MapPin size={13} className="text-[#3F768C]" />}
          <span>{company.city}</span>
        </div>
      ),
    },
    {
      header: 'País/región',
      cell: (company) => (
        <div className="flex items-center gap-1.5 text-slate-600">
          {company.country !== '--' && <Globe2 size={13} className="text-slate-400" />}
          <span>{company.country}</span>
        </div>
      ),
    },
  ];

  return (
    <DataTable<Company>
      title="Empresas"
      primaryActionLabel="Agregar empresas"
      onPrimaryAction={() => console.log('Crear nueva empresa')}
      tabs={[
        { id: 'all', label: 'Todos empresas', count: filteredCompanies.length },
        { id: 'mine', label: 'Mis empresas', count: filteredCompanies.filter(c => c.isMine).length },
      ]}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      searchTerm={searchTerm}
      onSearchChange={setSearchTerm}
      filterChips={[
        'Propietario del registro de empresa',
        'Fecha de creación',
        'Última actividad',
        'Estado del lead',
      ]}
      columns={columns}
      data={filteredCompanies}
      getItemKey={(company) => company.id}
      entityLabel="empresas"
    />
  );
}