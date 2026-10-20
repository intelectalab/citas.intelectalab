'use client';

import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { useState, useEffect } from 'react';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAuth } from '@/hooks/useAuth';
import { Plus, UserPlus } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';

interface Professional {
  id: string;
  display_name: string;
  document_number?: string;
  type_document_id?: string;
  license_number?: string;
  phone?: string;
  status_id?: string;
  first_name?: string;
  last_name?: string;
  status?: {
    id: string;
    name: string;
    slug?: string;
  };
  user?: {
    id: string;
    email: string;
    first_name?: string;
    last_name?: string;
  };
  specialties?: Array<{
    id: string;
    name: string;
  }>;
}

interface FormProfessionalProps {
  isOpen: boolean;
  onClose: () => void;
  initialData?: Professional | null;
  onSubmitSuccess: (credentials?: { email: string; tempPassword?: string }) => void;
}

export default function FormProfessional({ isOpen, onClose, onSubmitSuccess, initialData }: FormProfessionalProps) {
  const vocab = useVocabulary();
  const { user } = useAuth();
  const usesLicense = Boolean(user?.tenant?.rules?.['professionals.license']);
  const [form, setForm] = useState({
    display_name: '',
    first_name: '',
    last_name: '',
    email: '',
    type_document_id: '',
    document_number: '',
    license_number: '',
    phone: '',
    status_id: '',
    specialties: [] as string[],
  });

  const [typeDocuments, setTypeDocuments] = useState<any[]>([]);
  const [statuses, setStatuses] = useState<any[]>([]);
  const [specialtiesList, setSpecialtiesList] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [newSpecialty, setNewSpecialty] = useState('');
  const [addingSpecialty, setAddingSpecialty] = useState(false);

  const addSpecialty = async () => {
    const name = newSpecialty.trim();
    if (!name) return;

    try {
      setAddingSpecialty(true);
      setError('');
      const res = await api.post('/specialties', { name });
      const created = res.data?.data ?? res.data?.specialty ?? res.data;
      if (created?.id) {
        setSpecialtiesList((current) => [...current, created]);
        setForm((current) => ({ ...current, specialties: [...current.specialties, created.id] }));
        setNewSpecialty('');
      }
    } catch (err: any) {
      const first = Object.values(err?.response?.data?.errors ?? {})[0] as string[] | undefined;
      setError(first?.[0] || err?.response?.data?.message || 'No se pudo agregar la especialidad.');
    } finally {
      setAddingSpecialty(false);
    }
  };

  useEffect(() => {
    if (isOpen) {
      Promise.all([
        api.get('/type-documents'),
        api.get('/statuses', { params: { module: 'professionals' } }),
        api.get('/specialties')
      ])
        .then(([docRes, statusRes, specRes]) => {
          setTypeDocuments(docRes.data);
          setStatuses(statusRes.data);
          setSpecialtiesList(specRes.data);
        })
        .catch(err => console.error('Error cargando catálogos', err));
    }
  }, [isOpen]);

  useEffect(() => { if (isOpen) setError(''); }, [isOpen]);

  useEffect(() => {
    if (initialData) {
      setForm({
        display_name: initialData.display_name || '',
        first_name: initialData.first_name || initialData.user?.first_name || '',
        last_name: initialData.last_name || initialData.user?.last_name || '',
        email: initialData.user?.email || '',
        type_document_id: initialData.type_document_id || '',
        document_number: initialData.document_number || '',
        license_number: initialData.license_number || '',
        phone: initialData.phone || '',
        status_id: initialData.status_id || '',
        specialties: initialData.specialties ? initialData.specialties.map((s: any) => s.id) : [],
      });
    } else {
      setForm({
        display_name: '',
        first_name: '',
        last_name: '',
        email: '',
        type_document_id: '',
        document_number: '',
        license_number: '',
        phone: '',
        status_id: '',
        specialties: [],
      });
    }
  }, [initialData, isOpen]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setLoading(true);
      setError('');
      
      const payload = {
        display_name: form.display_name,
        first_name: form.first_name,
        last_name: form.last_name,
        email: form.email,
        type_document_id: form.type_document_id || null,
        document_number: form.document_number || null,
        ...(usesLicense ? { license_number: form.license_number || null } : {}),
        phone: form.phone || null,
        status_id: form.status_id || null,
        specialties: form.specialties,
      };

      if (initialData) {
        await api.put(`/professionals/${initialData.id}`, payload);
        onClose();
        onSubmitSuccess();
      } else {
        const res = await api.post('/professionals', payload);
        
        console.log("Respuesta del servidor:", res.data);

        const responseData = res.data;
        const tempPassword = responseData?.temporary_password;
        const userEmail = responseData?.professional?.user?.email || form.email;
        onClose();
        
        onSubmitSuccess({ email: userEmail, tempPassword });
      }
    } catch (err: any) {
      console.error('Error al guardar profesional:', err);
      const first = Object.values(err?.response?.data?.errors ?? {})[0] as string[] | undefined;
      setError(first?.[0] || err?.response?.data?.message || 'No se pudo guardar.');
    } finally {
      setLoading(false);
    }
  };

  const isEditing = Boolean(initialData);

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? `Editar ${vocab.pro}` : `Nuevo ${vocab.pro}`}
      subtitle={isEditing ? 'Actualiza sus datos' : 'Registra sus datos y crea su cuenta de acceso'}
      icon={<UserPlus size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form 
          id="professional-form" 
          onSubmit={handleSubmit} 
          className="space-y-4 overflow-y-auto flex-1 pr-1 pb-4"
        >
          <Select
            label="Tipo de Documento"
            placeholder="Seleccione..."
            value={form.type_document_id}
            onChange={(e) => setForm({ ...form, type_document_id: String(e.target.value) })}
            options={typeDocuments.map(doc => ({
              value: doc.id,
              label: doc.name,
            }))}
          />

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nro. de Documento</label>
            <input 
              type="text"
              placeholder="Ej. 45879632"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 font-mono"
              value={form.document_number}
              onChange={e => setForm({...form, document_number: e.target.value})}
            />
            <span className="text-[10px] text-slate-400 mt-1 block">Se usará como contraseña inicial.</span>
          </div>

          {usesLicense && (
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nro. de Colegiatura</label>
              <input
                type="text"
                placeholder="Ej. CMP-12345"
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 font-mono"
                value={form.license_number}
                onChange={e => setForm({...form, license_number: e.target.value})}
              />
            </div>
          )}

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombre Público / Visual *</label>
            <input 
              type="text" required
              placeholder="Ej. Dr. Carlos Pérez"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
              value={form.display_name}
              onChange={e => setForm({...form, display_name: e.target.value})}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombres *</label>
            <input 
              type="text" required
              placeholder="Carlos Alberto"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
              value={form.first_name}
              onChange={e => setForm({...form, first_name: e.target.value})}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Apellidos *</label>
            <input 
              type="text" required
              placeholder="Pérez Gómez"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
              value={form.last_name}
              onChange={e => setForm({...form, last_name: e.target.value})}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Correo Electrónico (Acceso) *</label>
            <input 
              type="email" required
              placeholder="carlos.perez@clinica.com"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
              value={form.email}
              onChange={e => setForm({...form, email: e.target.value})}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Teléfono / Celular</label>
            <input 
              type="text"
              placeholder="+51 999888777"
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
              value={form.phone}
              onChange={e => setForm({...form, phone: e.target.value})}
            />
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
              Especialidades (Puedes seleccionar varias)
            </label>
            <div className="border border-slate-200 rounded-lg p-3 max-h-48 overflow-y-auto space-y-2 bg-white">
              {specialtiesList.length === 0 ? (
                <p className="text-xs text-slate-400 italic">Aún no hay especialidades. Agrega la primera abajo.</p>
              ) : (
                specialtiesList.map(spec => {
                  const isSelected = form.specialties.includes(spec.id);
                  return (
                    <label 
                      key={spec.id} 
                      className="flex items-center gap-2.5 cursor-pointer text-sm text-slate-700 hover:bg-slate-50 p-1.5 rounded transition-colors"
                    >
                      <input 
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => {
                          const updated = isSelected
                            ? form.specialties.filter(id => id !== spec.id)
                            : [...form.specialties, spec.id];
                          setForm({ ...form, specialties: updated });
                        }}
                        className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
                      />
                      <span>{spec.name}</span>
                    </label>
                  );
                })
              )}
            </div>
            <div className="flex items-center gap-2 mt-2" data-new-specialty>
              <input
                type="text"
                maxLength={150}
                aria-label="Nueva especialidad"
                placeholder="Nueva especialidad"
                className="flex-1 text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800"
                value={newSpecialty}
                onChange={(e) => setNewSpecialty(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addSpecialty(); } }}
              />
              <button
                type="button"
                data-action="add-specialty"
                title="Agregar especialidad"
                aria-label="Agregar especialidad"
                disabled={addingSpecialty || !newSpecialty.trim()}
                onClick={addSpecialty}
                className="shrink-0 p-2.5 bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
              >
                <Plus size={18} className="stroke-[2.5]" />
              </button>
            </div>
          </div>

          <Select
            label="Estado"
            placeholder="Seleccione estado..."
            value={form.status_id}
            onChange={(e) => setForm({ ...form, status_id: String(e.target.value) })}
            options={statuses.map(st => ({
              value: st.id,
              label: st.name,
            }))}
          />

          <ErrorAlert message={error} />
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button 
            type="button" 
            onClick={onClose}
            className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
          >
            Cancelar
          </button>
          <Button 
            type="submit" 
            form="professional-form"
            disabled={loading} variant="classic"
          >
            {loading ? 'Guardando...' : (isEditing ? 'Guardar Cambios' : `Crear ${vocab.pro}`)}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}