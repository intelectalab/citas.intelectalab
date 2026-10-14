'use client';

import { useState, useEffect } from 'react';
import { Mail, Phone, Pencil, Trash2 } from 'lucide-react';
import { DataTable, type Column, type FilterConfig } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormContact from './FormContact1';
import { api } from '@/lib/axios';

interface Contact {
  id: string;
  first_name: string;
  last_name: string | null;
  email: string | null;
  phone: string | null;
  document_number: string | null;
  address: string | null;
  district: string | null;
  department: string | null;
}

export default function ContactsTable() {
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  
  const [managersOptions, setManagersOptions] = useState<{ label: string; value: string }[]>([]);

  const [filters, setFilters] = useState<Record<string, any>>({
    owner_id: '',
    lead_status: '',
    created_at: ''
  });

  const [showModal, setShowModal] = useState(false);
  const [editingContact, setEditingContact] = useState<Contact | null>(null);

  useEffect(() => {
    const fetchFilterOptions = async () => {
      try {
        const res = await api.get('/users');
        const options = res.data.map((user: any) => ({
          label: `${user.name} ${user.last_name || ''}`,
          value: user.id
        }));
        setManagersOptions(options);
      } catch (error) {
        console.warn('El endpoint /api/users aún no está disponible en Laravel.');
        setManagersOptions([]); 
      }
    };

    fetchFilterOptions();
  }, []);

  const contactFilterConfigs: FilterConfig[] = [
    {
      id: 'owner_id',
      label: 'Propietario del contacto',
      type: 'select',
      options: managersOptions
    },
    {
      id: 'lead_status',
      label: 'Estado del lead',
      type: 'select',
      options: [
        { label: 'Suscriptor', value: 'suscriptor' },
        { label: 'Lead', value: 'lead' },
        { label: 'Lead calificado por marketing (MQL)', value: 'mql' },
        { label: 'Lead calificado por ventas (SQL)', value: 'sql' },
        { label: 'Oportunidad', value: 'oportunidad' },
        { label: 'Cliente', value: 'cliente' },
      ]
    },
    {
      id: 'created_at',
      label: 'Fecha de creación',
      type: 'date',
    }
  ];

  const fetchContacts = async (search = '', currentFilters = filters) => {
    try {
      setLoading(true);
      const res = await api.get('/contacts', {
        params: { 
          search,
          ...currentFilters
        }
      });
      setContacts(res.data);
    } catch (error) {
      console.error('Error al cargar contactos:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchContacts();
  }, []);

  const handleSearchChange = (val: string) => {
    setSearchTerm(val);
    fetchContacts(val, filters);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    const updatedFilters = { ...filters, [filterId]: value };
    setFilters(updatedFilters);
    fetchContacts(searchTerm, updatedFilters);
  };

  const openCreateModal = () => {
    setEditingContact(null);
    setShowModal(true);
  };

  const openEditModal = (contact: Contact) => {
    setEditingContact(contact);
    setShowModal(true);
  };

  const handleFormSubmit = async (formData: any) => {
    try {
      if (editingContact) {
        await api.put(`/contacts/${editingContact.id}`, formData);
      } else {
        await api.post('/contacts', formData);
      }

      setShowModal(false);
      fetchContacts(searchTerm, filters);
    } catch (error) {
      console.error('Error al guardar contacto:', error);
    }
  };

  const handleDelete = async (id: string) => {
    if (!confirm('¿Estás seguro de que deseas eliminar este contacto?')) return;

    try {
      await api.delete(`/contacts/${id}`);
      fetchContacts(searchTerm, filters);
    } catch (error) {
      console.error('Error al eliminar contacto:', error);
    }
  };

  const columns: Column<Contact>[] = [
    {
      header: 'Nombre Completo',
      cell: (contact) => {
        const fullName = `${contact.first_name} ${contact.last_name || ''}`;
        return (
          <div className={styles.customerCell}>
            <div className={styles.avatar}>
              {contact.first_name[0]?.toUpperCase()}
            </div>
            <div>
              <span className={styles.customerName}>{fullName}</span>
              {contact.document_number && (
                <span className="block text-xs text-slate-400">Doc: {contact.document_number}</span>
              )}
            </div>
          </div>
        );
      },
    },
    {
      header: 'Correo Electrónico',
      cell: (contact) => contact.email ? (
        <a href={`mailto:${contact.email}`} className="inline-flex items-center gap-1.5 text-slate-600 hover:text-[#022340]">
          <Mail size={14} className="text-slate-400" />
          <span>{contact.email}</span>
        </a>
      ) : <span className="text-slate-400">Sin correo</span>,
    },
    {
      header: 'Teléfono',
      cell: (contact) => (
        <div className="flex items-center gap-1.5">
          <Phone size={14} className="text-slate-400" />
          <span>{contact.phone || 'Sin teléfono'}</span>
        </div>
      ),
    },
    {
      header: 'Ubicación',
      cell: (contact) => (
        <span className="text-slate-600 text-xs">
          {contact.district && contact.department ? `${contact.district}, ${contact.department}` : (contact.address || 'No especificada')}
        </span>
      ),
    },
    {
      header: 'Acciones',
      cell: (contact) => (
        <div className="flex items-center gap-2">
          <button 
            onClick={() => openEditModal(contact)}
            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
            title="Editar"
          >
            <Pencil size={16} />
          </button>
          <button 
            onClick={() => handleDelete(contact.id)}
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
      <DataTable<Contact>
        title="Contactos"
        primaryActionLabel="Nuevo Contacto"
        onPrimaryAction={openCreateModal}
        tabs={[
          { id: 'all', label: 'Todos los contactos', count: contacts.length },
        ]}
        activeTab={activeTab}
        onTabChange={setActiveTab}
        searchTerm={searchTerm}
        onSearchChange={handleSearchChange}
        filterConfigs={contactFilterConfigs}
        filterValues={filters}
        onFilterChange={handleFilterChange}
        columns={columns}
        data={contacts}
        getItemKey={(contact) => contact.id}
        entityLabel="contactos registrados"
      />

      <FormContact
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        onSubmit={handleFormSubmit}
        initialData={editingContact}
      />
    </div>
  );
}