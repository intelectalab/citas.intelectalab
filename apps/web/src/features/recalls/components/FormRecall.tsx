'use client';

import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { Plus, Repeat, Search, X } from 'lucide-react';
import FormCustomer from '@/features/customers/components/FormCustomer';
import QuickDependent from '@/features/customers/components/QuickDependent';
import QuickService from '@/features/services/components/QuickService';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { addDays, apiError, todayISO } from '@/features/appointments/appointments';
import type { Recall } from '../recalls';

interface CustomerOption {
  id: string;
  code: string;
  first_name: string;
  last_name: string;
  phone?: string | null;
  document_number?: string | null;
  dependents?: { id: string; name: string; species?: string | null }[];
}

interface FormRecallProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Recall | null;
  services: { id: string; name: string }[];
  professionals: { id: string; display_name: string }[];
  onSaved: () => void;
}


const SHORTCUTS: { label: string; days: number }[] = [
  { label: '1 semana', days: 7 },
  { label: '1 mes', days: 30 },
  { label: '3 meses', days: 90 },
  { label: '6 meses', days: 180 },
  { label: '1 año', days: 365 },
];

export default function FormRecall({ isOpen, onClose, initialData, services, professionals, onSaved }: FormRecallProps) {
  const { user, can } = useAuth();
  const vocab = useVocabulary();
  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);

  const [customer, setCustomer] = useState<CustomerOption | null>(null);
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<CustomerOption[]>([]);
  const [searching, setSearching] = useState(false);
  const [dependentId, setDependentId] = useState('');
  const [label, setLabel] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [customerFormOpen, setCustomerFormOpen] = useState(false);
  const [addingDependent, setAddingDependent] = useState(false);
  const [serviceFormOpen, setServiceFormOpen] = useState(false);
  const [newServices, setNewServices] = useState<{ id: string; name: string }[]>([]);

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setQuery('');
    setResults([]);
    setCustomer(null);
    setDependentId(initialData?.dependent?.id ?? '');
    setLabel(initialData?.label ?? '');
    setDueDate(initialData?.due_date ?? addDays(todayISO(), 30));
    setServiceId(initialData?.service?.id ?? '');
    setProfessionalId(initialData?.professional?.id ?? (professionals.length === 1 ? professionals[0].id : ''));
    setNotes(initialData?.notes ?? '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, initialData]);

  useEffect(() => {
    if (!isOpen || initialData || customer || query.trim().length < 2) {
      setResults([]);
      return;
    }

    setSearching(true);
    const timer = setTimeout(() => {
      api.get('/customers', { params: { search: query.trim() } })
        .then((res) => setResults(res.data?.data ?? []))
        .catch(() => setResults([]))
        .finally(() => setSearching(false));
    }, 300);

    return () => clearTimeout(timer);
  }, [query, customer, isOpen, initialData]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!initialData && !customer) return setError(`Elige a quién hay que hacerle seguimiento.`);

    const payload = {
      label: label.trim(),
      due_date: dueDate,
      service_id: serviceId || null,
      professional_id: professionalId || null,
      customer_dependent_id: dependentId || null,
      notes: notes.trim() || null,
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) {
        await api.put(`/recalls/${initialData.id}`, payload);
      } else {
        await api.post('/recalls', { ...payload, customer_id: customer!.id });
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el control.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'recall-form';
  const dependentOptions = customer?.dependents ?? [];

  return (
    <>
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar control: ${initialData.customer?.name ?? ''}` : 'Nuevo control'}
      subtitle={initialData ? initialData.label : 'Para volver a ver a alguien más adelante'}
      icon={<Repeat size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          {!initialData && (
            <div>
              <label className={labelClass}>{vocab.client} *</label>
              {customer ? (
                <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-lg px-3.5 py-2.5 bg-slate-50" data-chosen-customer>
                  <div>
                    <span className="block text-sm font-semibold text-slate-800">{customer.first_name} {customer.last_name}</span>
                    <span className="block text-xs text-slate-500 font-mono">{customer.code}{customer.phone ? ` · ${customer.phone}` : ''}</span>
                  </div>
                  <button
                    type="button"
                    title={`Cambiar ${vocab.client.toLowerCase()}`}
                    onClick={() => { setCustomer(null); setDependentId(''); }}
                    className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                  >
                    <X size={16} />
                  </button>
                </div>
              ) : (
                <>
                  <div className="flex items-center gap-2">
                    <div className="relative flex-1">
                      <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                      <input
                        type="text"
                        aria-label={`Buscar ${vocab.client.toLowerCase()}`}
                        placeholder="Nombre, documento, celular o código"
                        className={`${inputClass} pl-9`}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                      />
                    </div>
                    <button
                      type="button"
                      data-new-customer
                      title={`${vocab.pick('client', 'Nuevo', 'Nueva')} ${vocab.client.toLowerCase()}`}
                      aria-label={`${vocab.pick('client', 'Nuevo', 'Nueva')} ${vocab.client.toLowerCase()}`}
                      onClick={() => setCustomerFormOpen(true)}
                      className="shrink-0 p-2.5 bg-brand text-white rounded-lg hover:bg-brand-hover shadow-sm transition-colors"
                    >
                      <Plus size={18} className="stroke-[2.5]" />
                    </button>
                  </div>
                  {query.trim().length >= 2 && (
                    <div className="mt-1.5 border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-56 overflow-y-auto bg-white">
                      {searching && results.length === 0 ? (
                        <p className="px-3.5 py-2.5 text-sm text-slate-400">Buscando...</p>
                      ) : results.length === 0 ? (
                        <p className="px-3.5 py-2.5 text-sm text-slate-400">Sin resultados.</p>
                      ) : (
                        results.map((option) => (
                          <button
                            key={option.id}
                            type="button"
                            data-customer={option.id}
                            onClick={() => { setCustomer(option); setDependentId(option.dependents?.length === 1 ? option.dependents[0].id : ''); setError(''); }}
                            className="w-full text-left px-3.5 py-2 hover:bg-slate-50 transition-colors"
                          >
                            <span className="block text-sm font-semibold text-slate-800">{option.first_name} {option.last_name}</span>
                            <span className="block text-xs text-slate-500 font-mono">
                              {option.code}{option.document_number ? ` · ${option.document_number}` : ''}{option.phone ? ` · ${option.phone}` : ''}
                            </span>
                          </button>
                        ))
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {!initialData && usesDependents && customer && (
            <div>
              <Select
                label={vocab.dependent}
                placeholder={dependentOptions.length === 0 ? `Aún no tiene ${vocab.dependents.toLowerCase()}: ${vocab.pick('dependent', 'agrégalo', 'agrégala')} con +` : vocab.pick('dependent', 'Ninguno', 'Ninguna')}
                value={dependentId}
                onChange={(e) => setDependentId(String(e.target.value))}
                options={dependentOptions.map((d) => ({ value: d.id, label: d.species ? `${d.name} (${d.species})` : d.name }))}
                onAdd={() => setAddingDependent(true)}
                addLabel={`Agregar ${vocab.dependent.toLowerCase()}`}
              />
              {addingDependent && (
                <QuickDependent
                  customerId={customer.id}
                  onCancel={() => setAddingDependent(false)}
                  onCreated={(created) => {
                    setCustomer({ ...customer, dependents: [...(customer.dependents ?? []), created] } as typeof customer);
                    setDependentId(created.id);
                    setAddingDependent(false);
                  }}
                />
              )}
            </div>
          )}

          <div>
            <label className={labelClass} htmlFor="recall-label">¿Para qué es? *</label>
            <input
              id="recall-label"
              type="text"
              required
              maxLength={150}
              placeholder="Ej. Control de presión, retoque, próxima sesión"
              className={inputClass}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
            />
          </div>

          <div>
            <label className={labelClass} htmlFor="recall-date">¿Para cuándo? *</label>
            <DateField id="recall-date" required value={dueDate} onChange={setDueDate} />
            <div className="flex flex-wrap gap-1.5 mt-2">
              {SHORTCUTS.map((shortcut) => {
                const value = addDays(todayISO(), shortcut.days);
                const isOn = dueDate === value;

                return (
                  <button
                    key={shortcut.days}
                    type="button"
                    data-shortcut={shortcut.days}
                    aria-pressed={isOn}
                    onClick={() => setDueDate(value)}
                    className={`px-2.5 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${
                      isOn ? 'bg-brand text-white border-brand' : 'bg-white text-slate-700 border-slate-200 hover:border-brand-accent hover:text-brand-ink'
                    }`}
                  >
                    En {shortcut.label}
                  </button>
                );
              })}
            </div>
          </div>

          <Select
            label="Servicio con el que se agenda"
            placeholder="Se elige al agendar"
            value={serviceId}
            onChange={(e) => setServiceId(String(e.target.value))}
            options={[...services, ...newServices].map((s) => ({ value: s.id, label: s.name }))}
            onAdd={can('services.create') ? () => setServiceFormOpen(true) : undefined}
            addLabel="Nuevo servicio"
          />

          {professionals.length > 1 && (
            <Select
              label={vocab.pro}
              placeholder="Cualquiera"
              value={professionalId}
              onChange={(e) => setProfessionalId(String(e.target.value))}
              options={professionals.map((p) => ({ value: p.id, label: p.display_name }))}
            />
          )}

          <div>
            <label className={labelClass} htmlFor="recall-notes">Notas</label>
            <textarea
              id="recall-notes"
              rows={3}
              maxLength={1000}
              placeholder="Lo que conviene recordar al llamar"
              className={inputClass}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>

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
            form={formId}
            disabled={saving} variant="classic"
          >
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Registrar control'}
          </Button>
        </div>
      </div>
    </FormDrawer>

    <FormCustomer
      isOpen={isOpen && customerFormOpen}
      onClose={() => setCustomerFormOpen(false)}
      onSubmitSuccess={(created) => {
        if (created?.id) {
          setCustomer(created);
          setDependentId('');
          setQuery('');
        }
      }}
    />
    <QuickService
      isOpen={isOpen && serviceFormOpen}
      onClose={() => setServiceFormOpen(false)}
      onCreated={(created) => { setNewServices((current) => [...current, created]); setServiceId(created.id); }}
    />
    </>
  );
}
