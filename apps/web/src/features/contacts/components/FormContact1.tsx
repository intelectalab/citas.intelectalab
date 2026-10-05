'use client';

import { useState, useEffect } from 'react';
import { UserPlus, X } from 'lucide-react';
import { api } from '@/lib/axios';

interface FormContactProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (formData: any) => void;
  initialData?: any | null;
}

export default function FormContact({ isOpen, onClose, onSubmit, initialData }: FormContactProps) {
  const [form, setForm] = useState({
    type_document_id: '',
    document_number: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    address: '',
    district: '',
    department: '',
    postal_code: '',
    manager_user_id: '', // <- Añadido para el responsable
  });

  const [typeDocuments, setTypeDocuments] = useState<any[]>([]);
  const [users, setUsers] = useState<any[]>([]); // <- Estado para la lista de usuarios del tenant

  useEffect(() => {
    if (initialData) {
      setForm({
        type_document_id: initialData.type_document_id || '',
        document_number: initialData.document_number || '',
        first_name: initialData.first_name || '',
        last_name: initialData.last_name || '',
        email: initialData.email || '',
        phone: initialData.phone || '',
        address: initialData.address || '',
        district: initialData.district || '',
        department: initialData.department || '',
        postal_code: initialData.postal_code || '',
        manager_user_id: initialData.manager_user_id || '',
      });
    } else {
      setForm({
        type_document_id: '',
        document_number: '',
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        address: '',
        district: '',
        department: '',
        postal_code: '',
        manager_user_id: '',
      });
    }
  }, [initialData, isOpen]);

  // Cargar tipos de documento y usuarios del tenant al abrir el modal
  useEffect(() => {
    if (isOpen) {
      api.get('/type-documents')
        .then(res => setTypeDocuments(res.data))
        .catch(err => console.error('Error cargando tipos de documento', err));

      api.get('/users')
        .then(res => setUsers(res.data))
        .catch(err => console.error('Error cargando usuarios', err));
    }
  }, [isOpen]);

  if (!isOpen) return null;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(form);
  };

  return (
    <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
      <div className="bg-white rounded-xl shadow-2xl border border-slate-100 w-full max-w-2xl overflow-hidden my-8">
        
        <div className="flex items-center justify-between px-6 py-5 border-b border-slate-100 bg-slate-50/50">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-blue-50 text-[#022340] rounded-lg">
              <UserPlus size={20} />
            </div>
            <div>
              <h3 className="text-base font-bold text-slate-900">
                {initialData ? 'Editar Contacto' : 'Registrar Nuevo Contacto'}
              </h3>
              <p className="text-xs text-slate-500">
                {initialData ? 'Modifica los datos del contacto' : 'Añade un nuevo cliente o proveedor al sistema'}
              </p>
            </div>
          </div>
          <button 
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors"
          >
            <X size={18} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
          
          {/* Tipo de Documento y Nº de Documento */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Tipo de Documento</label>
              <select
                className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2.5 bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340]"
                value={form.type_document_id}
                onChange={e => setForm({...form, type_document_id: e.target.value})}
              >
                <option value="">Seleccione...</option>
                {typeDocuments.map(doc => (
                  <option key={doc.id} value={doc.id}>{doc.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nº de Documento</label>
              <input 
                type="text"
                placeholder="Ej. 10475829102"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.document_number}
                onChange={e => setForm({...form, document_number: e.target.value})}
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombres *</label>
              <input 
                type="text" required
                placeholder="Ej. Carlos"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.first_name}
                onChange={e => setForm({...form, first_name: e.target.value})}
              />
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Apellidos *</label>
              <input 
                type="text" required
                placeholder="Ej. Mendoza"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.last_name}
                onChange={e => setForm({...form, last_name: e.target.value})}
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Correo Electrónico</label>
              <input 
                type="email"
                placeholder="correo@empresa.com"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.email}
                onChange={e => setForm({...form, email: e.target.value})}
              />
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Teléfono</label>
              <input 
                type="text"
                placeholder="+51 999 999 999"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.phone}
                onChange={e => setForm({...form, phone: e.target.value})}
              />
            </div>
          </div>

          {/* Campo Propietario / Responsable Asignado */}
          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Propietario / Responsable</label>
            <select
              className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2.5 bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340]"
              value={form.manager_user_id}
              onChange={e => setForm({...form, manager_user_id: e.target.value})}
            >
              <option value="">Seleccione un propietario...</option>
              {users.map(user => (
                <option key={user.id} value={user.id}>{user.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Dirección</label>
            <input 
              type="text"
              placeholder="Av. Principal 123"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
              value={form.address}
              onChange={e => setForm({...form, address: e.target.value})}
            />
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Distrito</label>
              <input 
                type="text"
                placeholder="Miraflores"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.district}
                onChange={e => setForm({...form, district: e.target.value})}
              />
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Departamento</label>
              <input 
                type="text"
                placeholder="Lima"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.department}
                onChange={e => setForm({...form, department: e.target.value})}
              />
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Código Postal</label>
              <input 
                type="text"
                placeholder="15074"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={form.postal_code}
                onChange={e => setForm({...form, postal_code: e.target.value})}
              />
            </div>
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 mt-6">
            <button 
              type="button" 
              onClick={onClose}
              className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Cancelar
            </button>
            <button 
              type="submit" 
              className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
            >
              {initialData ? 'Guardar Cambios' : 'Guardar Contacto'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}