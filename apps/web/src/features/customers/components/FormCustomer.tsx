'use client';

import { useState, useEffect, useRef } from 'react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAuth } from '@/hooks/useAuth';
import CustomerDependents, { type CustomerDependent } from './CustomerDependents';
import { User, ChevronDown } from 'lucide-react';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { stageOf, type BoardInfo } from '../board';

interface FormCustomerProps {
  isOpen: boolean;
  onClose: () => void;
  initialData?: any | null;
  onSubmitSuccess: (customer?: any) => void;
}

export default function FormCustomer({ isOpen, onClose, initialData, onSubmitSuccess }: FormCustomerProps) {
  const vocab = useVocabulary();
  const clientLower = vocab.client.toLowerCase();
  const { user } = useAuth();
  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);
  const clinical = Boolean(user?.tenant?.rules?.['customers.clinical_fields']);
  const [pendingDependents, setPendingDependents] = useState<CustomerDependent[]>([]);
  const [loading, setLoading] = useState(false);
  const [board, setBoard] = useState<BoardInfo | null>(null);
  const [documentTypes, setDocumentTypes] = useState<any[]>([]);
  const [activeAccordion, setActiveAccordion] = useState<number | string>(1);
  const sectionRefs = useRef<{ [key: string]: HTMLDivElement | null }>({});

  const [form, setForm] = useState({
    code: '',
    first_name: '',
    last_name: '',
    birth_date: '',
    biological_sex: 'M',
    blood_type: '',
    type_document_id: '',
    document_number: '',
    email: '',
    phone: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    address: '',
    district: '',
    department: '',
    postal_code: '',
    medical_notes: '',
    pipeline_stage_id: '',
  });

  const sections = [
    { id: 1, title: '1. Información Personal' },
    { id: 2, title: '2. Identificación' },
    { id: 3, title: '3. Contacto y Ubicación' },
    { id: 4, title: '4. Observaciones y Estado' },
    ...(usesDependents ? [{ id: 5, title: `5. ${vocab.dependents}` }] : []),
  ];

  const bloodTypes = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];

  useEffect(() => {
    if (initialData) {
      setForm({
        code: initialData.code || '',
        first_name: initialData.first_name || '',
        last_name: initialData.last_name || '',
        birth_date: initialData.birth_date ? initialData.birth_date.split('T')[0] : '',
        biological_sex: initialData.biological_sex || 'M',
        blood_type: initialData.blood_type || '',
        type_document_id: initialData.type_document_id || '',
        document_number: initialData.document_number || '',
        email: initialData.email || '',
        phone: initialData.phone || '',
        emergency_contact_name: initialData.emergency_contact_name || '',
        emergency_contact_phone: initialData.emergency_contact_phone || '',
        address: initialData.address || '',
        district: initialData.district || '',
        department: initialData.department || '',
        postal_code: initialData.postal_code || '',
        medical_notes: initialData.medical_notes || '',
        pipeline_stage_id: initialData.pipeline_stage_id || '',
      });
    } else {
      setForm({
        code: '',
        first_name: '',
        last_name: '',
        birth_date: '',
        biological_sex: 'M',
        blood_type: '',
        type_document_id: '',
        document_number: '',
        email: '',
        phone: '',
        emergency_contact_name: '',
        emergency_contact_phone: '',
        address: '',
        district: '',
        department: '',
        postal_code: '',
        medical_notes: '',
        pipeline_stage_id: '',
      });
    }
    setPendingDependents([]);
  }, [initialData, isOpen]);

  useEffect(() => {
    if (isOpen) {
      api.get('/customer-board')
        .then(res => setBoard(res.data?.ready ? res.data : null))
        .catch(() => setBoard(null));

      api.get('/type-documents')
        .then(res => {
          setDocumentTypes(res.data);
          if (!form.type_document_id && res.data.length > 0) {
            setForm(prev => ({ ...prev, type_document_id: res.data[0].id }));
          }
        })
        .catch(err => console.error('Error cargando tipos de documento:', err));
    }
  }, [isOpen]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setLoading(true);
      if (initialData?.id) {
        await api.put(`/customers/${initialData.id}`, form);
        onSubmitSuccess();
      } else {
        const res = await api.post('/customers', usesDependents ? { ...form, dependents: pendingDependents } : form);
        onSubmitSuccess(res.data?.customer);
      }
      onClose();
    } catch (error) {
      console.error('Error guardando cliente:', error);
    } finally {
      setLoading(false);
    }
  };

  const isEditing = Boolean(initialData?.id);

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={isEditing ? `Editar ${vocab.client}` : `Nuevo ${vocab.client}`}
      subtitle={`Complete la información personal y de contacto del ${clientLower}.`}
      icon={<User size={20} />}
    >
      <form onSubmit={handleSubmit} className="flex flex-col h-full justify-between">
        <div className="divide-y divide-slate-200 pb-24 overflow-y-auto px-1">
          {sections.map((sec) => {
            const isOpenSection = activeAccordion === sec.id;

            return (
              <div
                key={sec.id}
                ref={(el) => {
                  sectionRefs.current[sec.id] = el;
                }}
                className="bg-white transition-all duration-300"
              >
                <button
                  type="button"
                  onClick={() => setActiveAccordion(isOpenSection ? 0 : sec.id)}
                  className="w-full flex items-center justify-between py-4 px-2 bg-white hover:bg-slate-50/85 transition-colors text-left"
                >
                  <span className="text-xs font-bold uppercase tracking-wider text-slate-800">{sec.title}</span>
                  <div className={`p-1 rounded-full bg-slate-100 text-slate-600 transition-transform duration-200 ${isOpenSection ? 'rotate-180' : ''}`}>
                    <ChevronDown size={14} />
                  </div>
                </button>

                <div
                  className={`grid transition-all duration-300 ease-in-out overflow-hidden ${
                    isOpenSection ? 'grid-rows-[1fr] opacity-100 pb-6 pt-2 px-2' : 'grid-rows-[0fr] opacity-0'
                  }`}
                >
                  <div className="overflow-hidden space-y-4">
                    {sec.id === 1 && (
                      <div className="space-y-4">
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Código Interno</label>
                          <input
                            type="text"
                            value={form.code}
                            onChange={(e) => setForm({ ...form, code: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Ej. PAT-001"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombres *</label>
                          <input
                            type="text"
                            required
                            value={form.first_name}
                            onChange={(e) => setForm({ ...form, first_name: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Ej. Juliana"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Apellidos *</label>
                          <input
                            type="text"
                            required
                            value={form.last_name}
                            onChange={(e) => setForm({ ...form, last_name: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Ej. Díaz"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">F. Nacimiento</label>
                          <DateField
                            ariaLabel="Fecha de nacimiento"
                            max={new Date().toLocaleDateString('en-CA')}
                            value={form.birth_date ?? ''}
                            onChange={(value) => setForm({ ...form, birth_date: value })}
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">{clinical ? 'Sexo Biológico' : 'Sexo'}</label>
                          <Select
                            ariaLabel="Sexo"
                            clearable={false}
                            value={form.biological_sex}
                            onChange={(e) => setForm({ ...form, biological_sex: String(e.target.value) })}
                            options={[{ value: 'M', label: 'Masculino' }, { value: 'F', label: 'Femenino' }]}
                          />
                        </div>
                        {clinical && (
                        <div data-field="blood-type">
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Tipo de Sangre</label>
                          <Select
                            ariaLabel="Tipo de sangre"
                            placeholder="Seleccione tipo..."
                            value={form.blood_type}
                            onChange={(e) => setForm({ ...form, blood_type: String(e.target.value) })}
                            options={bloodTypes.map((bt) => ({ value: bt, label: bt }))}
                          />
                        </div>
                        )}
                      </div>
                    )}

                    {sec.id === 2 && (
                      <div className="space-y-4">
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Tipo de Documento</label>
                          <Select
                            ariaLabel="Tipo de documento"
                            placeholder="Seleccione documento..."
                            value={form.type_document_id}
                            onChange={(e) => setForm({ ...form, type_document_id: String(e.target.value) })}
                            options={documentTypes.map((dt) => ({ value: dt.id, label: dt.name || dt.display_name }))}
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">N° de Documento</label>
                          <input
                            type="text"
                            value={form.document_number}
                            onChange={(e) => setForm({ ...form, document_number: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Número de documento..."
                          />
                        </div>
                      </div>
                    )}

                    {sec.id === 3 && (
                      <div className="space-y-4">
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Correo Electrónico</label>
                          <input
                            type="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="correo@ejemplo.com"
                          />
                          <p className="text-xs text-slate-500 mt-1.5" data-hint="portal">
                            Con su correo y su número de documento podrá ingresar a su portal: la primera vez, su contraseña es su número de documento.
                          </p>
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Teléfono</label>
                          <input
                            type="text"
                            value={form.phone}
                            onChange={(e) => setForm({ ...form, phone: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="+51 ..."
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Dirección</label>
                          <input
                            type="text"
                            value={form.address}
                            onChange={(e) => setForm({ ...form, address: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Av. Principal 123"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Distrito</label>
                          <input
                            type="text"
                            value={form.district}
                            onChange={(e) => setForm({ ...form, district: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Distrito"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Departamento</label>
                          <input
                            type="text"
                            value={form.department}
                            onChange={(e) => setForm({ ...form, department: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Departamento"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombre de Contacto de Emergencia</label>
                          <input
                            type="text"
                            value={form.emergency_contact_name}
                            onChange={(e) => setForm({ ...form, emergency_contact_name: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="Nombre del contacto"
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Teléfono de Contacto de Emergencia</label>
                          <input
                            type="text"
                            value={form.emergency_contact_phone}
                            onChange={(e) => setForm({ ...form, emergency_contact_phone: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder="+51 ..."
                          />
                        </div>
                      </div>
                    )}

                    {sec.id === 4 && (
                      <div className="space-y-4">
                        <div>
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">{clinical ? 'Antecedentes y observaciones' : 'Observaciones'}</label>
                          <textarea
                            rows={3}
                            value={form.medical_notes}
                            onChange={(e) => setForm({ ...form, medical_notes: e.target.value })}
                            className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            placeholder={clinical ? 'Alergias, antecedentes y otros datos relevantes...' : 'Preferencias o datos a tener en cuenta...'}
                          />
                        </div>
                        {board && (
                        <div data-field="stage">
                          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Etapa en el tablero</label>
                          <Select
                            ariaLabel="Etapa en el tablero"
                            placeholder={`Sin elegir: aparece en "${stageOf(board, null)?.name ?? ''}"`}
                            value={board.stages.some((stage) => stage.id === form.pipeline_stage_id) ? form.pipeline_stage_id : ''}
                            onChange={(e) => setForm({ ...form, pipeline_stage_id: String(e.target.value) })}
                            options={board.stages.map((stage) => ({ value: stage.id, label: stage.name }))}
                          />
                          <p className="text-xs text-slate-500 mt-1.5">Es la columna donde aparece en el tablero de {vocab.clients.toLowerCase()}. Cada cambio queda en su página.</p>
                        </div>
                        )}
                      </div>
                    )}

                    {sec.id === 5 && isOpen && (
                      <CustomerDependents
                        customerId={initialData?.id ?? null}
                        onPendingChange={setPendingDependents}
                      />
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        <div className="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 p-4 flex items-center justify-end gap-3 z-10">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors"
          >
            Cancelar
          </button>
          <button
            type="submit"
            disabled={loading}
            className="px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-white bg-[#030336] hover:bg-[#030336]/90 rounded-lg transition-colors shadow-sm disabled:opacity-50"
          >
            {loading ? 'Guardando...' : isEditing ? `Actualizar ${clientLower}` : `Crear ${clientLower}`}
          </button>
        </div>
      </form>
    </FormDrawer>
  );
}