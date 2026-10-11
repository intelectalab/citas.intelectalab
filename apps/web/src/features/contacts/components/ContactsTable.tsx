'use client';

import { useState, useEffect } from 'react';
import { Mail, Phone, Pencil, Trash2, Briefcase } from 'lucide-react';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormContact from './FormContact';
import { api } from '@/lib/axios';

interface TenantContact {
    id: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    position: string | null;
    user_id: string | null;
}

export default function ContactsTable() {
    const [contacts, setContacts] = useState<TenantContact[]>([]);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState('all');
    const [searchTerm, setSearchTerm] = useState('');

    const [showModal, setShowModal] = useState(false);
    const [editingContact, setEditingContact] = useState<TenantContact | null>(null);

    const fetchContacts = async (search = '') => {
        try {
            setLoading(true);
            const res = await api.get('/tenant-contacts', {
                params: { search }
            });
            setContacts(res.data.data || res.data);
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
        fetchContacts(val);
    };

    const openCreateModal = () => {
        setEditingContact(null);
        setShowModal(true);
    };

    const openEditModal = (contact: TenantContact) => {
        setEditingContact(contact);
        setShowModal(true);
    };

    const handleFormSubmit = async (formData: any) => {
        try {
            if (editingContact) {
                await api.put(`/tenant-contacts/${editingContact.id}`, formData);
            } else {
                await api.post('/tenant-contacts', formData);
            }

            setShowModal(false);
            fetchContacts(searchTerm);
        } catch (error) {
            console.error('Error al guardar contacto:', error);
        }
    };

    const handleDelete = async (id: string) => {
        if (!confirm('¿Estás seguro de que deseas eliminar este contacto?')) return;

        try {
            await api.delete(`/tenant-contacts/${id}`);
            fetchContacts(searchTerm);
        } catch (error) {
            console.error('Error al eliminar contacto:', error);
        }
    };

    const columns: Column<TenantContact>[] = [
        {
            header: 'Nombre Completo',
            cell: (contact) => {
                const fullName = `${contact.first_name} ${contact.last_name}`;
                return (
                    <div className={styles.customerCell}>
                        <div className={styles.avatar}>
                            {contact.first_name[0]?.toUpperCase()}
                        </div>
                        <div>
                            <span className={styles.customerName}>{fullName}</span>
                            {contact.position && (
                                <span className="block text-xs text-slate-400 flex items-center gap-1">
                                    <Briefcase size={12} /> {contact.position}
                                </span>
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
            header: 'Empresa',
            cell: (contact: any) => (
                <span className="text-slate-600 font-medium text-xs bg-slate-100 px-2.5 py-1 rounded-md">
                    {contact.tenant?.name || contact.tenant?.company_name || 'Empresa Asignada'}
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
            <DataTable<TenantContact>
                title="Contactos del Tenant"
                primaryActionLabel="Nuevo Contacto"
                onPrimaryAction={openCreateModal}
                tabs={[
                    { id: 'all', label: 'Todos los contactos', count: contacts.length },
                ]}
                activeTab={activeTab}
                onTabChange={setActiveTab}
                searchTerm={searchTerm}
                onSearchChange={handleSearchChange}
                columns={columns}
                sortOptions={[
                  { id: 'name', label: 'Nombre', value: (c) => `${c.first_name} ${c.last_name}` },
                  { id: 'last_name', label: 'Apellido', value: (c) => c.last_name },
                  { id: 'email', label: 'Correo', value: (c) => c.email },
                ]}
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