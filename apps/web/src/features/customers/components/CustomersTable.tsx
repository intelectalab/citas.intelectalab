'use client';

import { useState } from 'react';
import {
  Building2,
  User,
  Mail,
  Phone,
  Crown,
  Tag,
  ExternalLink
} from 'lucide-react';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';

interface Customer {
  id: string;
  code: string;
  name: string;
  type: 'Corporativo' | 'Natural';
  documentType: string;
  documentNumber: string;
  contactPerson: string;
  email: string;
  phone: string;
  segment: string;
  status: 'Activo' | 'Inactivo';
}

const initialCustomers: Customer[] = [
  {
    id: '1',
    code: 'CLI-0001',
    name: 'Tech Solutions S.A.C.',
    type: 'Corporativo',
    documentType: 'RUC',
    documentNumber: '20601234567',
    contactPerson: 'Brian Halligan',
    email: 'contacto@techsolutions.pe',
    phone: '+51 987 654 321',
    segment: 'VIP',
    status: 'Activo',
  },
  {
    id: '2',
    code: 'CLI-0002',
    name: 'Juliana Díaz',
    type: 'Natural',
    documentType: 'DNI',
    documentNumber: '72819043',
    contactPerson: 'Juliana Díaz',
    email: 'juliana.diaz@gmail.com',
    phone: '+51 912 345 678',
    segment: 'Regular',
    status: 'Activo',
  },
  {
    id: '3',
    code: 'CLI-0003',
    name: 'Comercial Johnson E.I.R.L.',
    type: 'Corporativo',
    documentType: 'RUC',
    documentNumber: '20559876543',
    contactPerson: 'Maria Johnson',
    email: 'ventas@comercialj.pe',
    phone: '+51 976 123 456',
    segment: 'Frecuente',
    status: 'Inactivo',
  },
];

export default function CustomersTable() {
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');

  const filteredCustomers = initialCustomers.filter((customer) => {
    const matchesTab =
      activeTab === 'all' ||
      (activeTab === 'corporate' && customer.type === 'Corporativo') ||
      (activeTab === 'individual' && customer.type === 'Natural');

    const matchesSearch =
      customer.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      customer.documentNumber.includes(searchTerm) ||
      customer.code.toLowerCase().includes(searchTerm.toLowerCase());

    return matchesTab && matchesSearch;
  });

  // Definición de columnas exclusivas para Clientes
  const columns: Column<Customer>[] = [
    {
      header: 'Código',
      accessorKey: 'code',
      className: styles.tdCode,
    },
    {
      header: 'Nombre / Razón Social',
      cell: (customer) => (
        <div className={styles.customerCell}>
          <div className={styles.avatar}>
            {customer.name.substring(0, 2).toUpperCase()}
          </div>
          <span className={styles.customerName}>{customer.name}</span>
        </div>
      ),
    },
    {
      header: 'Tipo',
      cell: (customer) => (
        <span className="inline-flex items-center gap-1.5 text-slate-700 font-semibold text-xs">
          {customer.type === 'Corporativo' ? (
            <Building2 size={14} className="text-[#3F768C]" />
          ) : (
            <User size={14} className="text-emerald-600" />
          )}
          {customer.type}
        </span>
      ),
    },
    {
      header: 'Documento',
      cell: (customer) => (
        <span className="font-mono text-xs">
          <span className="text-slate-400 mr-1">{customer.documentType}:</span>
          {customer.documentNumber}
        </span>
      ),
    },
    {
      header: 'Contacto Principal',
      accessorKey: 'contactPerson',
    },
    {
      header: 'Correo Electrónico',
      cell: (customer) => (
        <a
          href={`mailto:${customer.email}`}
          className="inline-flex items-center gap-1.5 text-slate-600 hover:text-[#022340] transition-colors"
        >
          <Mail size={14} className="text-slate-400" />
          <span>{customer.email}</span>
          <ExternalLink size={12} className="opacity-0 hover:opacity-100 transition-opacity text-[#3F768C]" />
        </a>
      ),
    },
    {
      header: 'Teléfono',
      cell: (customer) => (
        <div className="flex items-center gap-1.5">
          <Phone size={14} className="text-slate-400" />
          <span>{customer.phone}</span>
        </div>
      ),
    },
    {
      header: 'Segmento',
      cell: (customer) =>
        customer.segment === 'VIP' ? (
          <span className={styles.badgeVip}>
            <Crown size={12} className="text-[#022340]" />
            {customer.segment}
          </span>
        ) : (
          <span className={styles.badgeSegment}>
            <Tag size={12} className="text-slate-400" />
            {customer.segment}
          </span>
        ),
    },
    {
      header: 'Estado',
      cell: (customer) => (
        <span className={customer.status === 'Activo' ? styles.badgeActive : styles.badgeInactive}>
          <span className={customer.status === 'Activo' ? styles.statusDotActive : styles.statusDotInactive} />
          {customer.status}
        </span>
      ),
    },
  ];

  return (
    <DataTable<Customer>
      title="Clientes"
      primaryActionLabel="Nuevo Cliente"
      onPrimaryAction={() => console.log('Crear nuevo cliente')}
      tabs={[
        { id: 'all', label: 'Todos los clientes', count: 32 },
        { id: 'corporate', label: 'Corporativos', count: 20 },
        { id: 'individual', label: 'Personas Naturales', count: 12 },
      ]}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      searchTerm={searchTerm}
      onSearchChange={setSearchTerm}
      filterChips={['Tipo de Cliente', 'Segmento', 'Documento', 'Estado']}
      columns={columns}
      data={filteredCustomers}
      getItemKey={(customer) => customer.id}
      entityLabel="clientes registrados"
    />
  );
}