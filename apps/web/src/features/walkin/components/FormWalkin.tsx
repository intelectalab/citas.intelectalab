'use client';

import { useEffect, useState } from 'react';
import { ListOrdered } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import QuickDependent from '@/features/customers/components/QuickDependent';
import { apiError } from '@/features/appointments/appointments';
import { choiceLabel, type WalkinChoices, type WalkinContext, type WalkinEntry } from '../walkin';

interface FormWalkinProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: WalkinEntry | null;
  context: WalkinContext;
  onSaved: (message: string) => void;
}

export default function FormWalkin({ isOpen, onClose, initialData, context, onSaved }: FormWalkinProps) {
  const vocab = useVocabulary();
  const dependents = context.dependents;

  const [customer, setCustomer] = useState<PickedCustomer | null>(null);
  const [dependentId, setDependentId] = useState('');
  const [addingDependent, setAddingDependent] = useState(false);
  const [branchId, setBranchId] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [notes, setNotes] = useState('');
  const [choices, setChoices] = useState<WalkinChoices | null>(null);
  const [loadingChoices, setLoadingChoices] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const firstBranch = context.branches[0]?.id ?? '';

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setAddingDependent(false);
    setCustomer(null);
    setBranchId(initialData?.branch_id ?? firstBranch);
    setServiceId(initialData?.service_id ?? '');
    setProfessionalId(initialData?.professional_id ?? '');
    setDependentId(initialData?.customer_dependent_id ?? '');
    setNotes(initialData?.notes ?? '');
  }, [isOpen, initialData, firstBranch]);

  useEffect(() => {
    if (!isOpen || !branchId || !serviceId) {
      setChoices(null);
      return;
    }
    let active = true;
    setLoadingChoices(true);
    api.get<WalkinChoices>('/walkins/choices', { params: { branch_id: branchId, service_id: serviceId, ...(initialData ? { walkin_id: initialData.id } : {}) } })
      .then((res) => {
        if (!active) return;
        setChoices(res.data);
        setProfessionalId((current) => (current && !res.data.professionals.some((p) => p.id === current) ? '' : current));
      })
      .catch(() => active && setChoices(null))
      .finally(() => active && setLoadingChoices(false));
    return () => { active = false; };
  }, [isOpen, branchId, serviceId, initialData]);

  const dependentOptions = initialData ? [] : customer?.dependents ?? [];

  const professionalOptions = [
    { value: '', label: choices ? choiceLabel('Quien se libere primero', choices.first_free.wait_minutes, choices.first_free.estimated_at) : 'Quien se libere primero' },
    ...(choices?.professionals ?? []).map((p) => ({ value: p.id, label: choiceLabel(p.display_name, p.wait_minutes, p.estimated_at) })),
  ];

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (!initialData && !customer) {
      setError(`Elige quién espera. Si es nuevo, pulsa "+" para registrarlo.`);
      return;
    }
    if (!serviceId) {
      setError('Elige el servicio.');
      return;
    }

    try {
      setSaving(true);
      const payload = {
        service_id: serviceId,
        professional_id: professionalId || null,
        customer_dependent_id: dependentId || null,
        notes: notes.trim() || null,
      };
      const res = initialData
        ? await api.put(`/walkins/${initialData.id}`, payload)
        : await api.post('/walkins', { ...payload, branch_id: branchId, customer_id: customer!.id });
      onSaved(res.data?.message ?? 'Listo.');
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el turno.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'walkin-form';
  const chosen = professionalId ? choices?.professionals.find((p) => p.id === professionalId) : null;
  const full = chosen ? chosen.wait_minutes === null : choices?.first_free.wait_minutes === null;

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar turno ${initialData.ticket}: ${initialData.customer?.full_name ?? ''}` : 'Anotar en la cola'}
      subtitle={initialData ? 'Mientras sigue esperando' : 'Para quien llega sin cita'}
      icon={<ListOrdered size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          {!initialData && (
            <CustomerPicker
              active={isOpen}
              value={customer}
              onChange={(picked) => { setCustomer(picked); setDependentId(picked?.dependents?.length === 1 ? picked.dependents[0].id : ''); setError(''); }}
            />
          )}

          {!initialData && dependents.enabled && customer && (
            <div>
              <Select
                label={`${vocab.dependent}${dependents.required ? ' *' : ''}`}
                placeholder={dependentOptions.length === 0 ? `Aún no tiene ${vocab.dependents.toLowerCase()}: ${vocab.pick('dependent', 'agrégalo', 'agrégala')} con +` : dependents.required ? 'Seleccione...' : vocab.pick('dependent', 'Ninguno', 'Ninguna')}
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
                    setCustomer({ ...customer, dependents: [...(customer.dependents ?? []), created] } as PickedCustomer);
                    setDependentId(created.id);
                    setAddingDependent(false);
                  }}
                />
              )}
            </div>
          )}

          {!initialData && context.branches.length > 1 && (
            <Select
              label="Sede *"
              clearable={false}
              value={branchId}
              onChange={(e) => { setBranchId(String(e.target.value)); setProfessionalId(''); }}
              options={context.branches.map((b) => ({ value: b.id, label: b.name }))}
            />
          )}

          <Select
            label="Servicio *"
            placeholder="Seleccione..."
            value={serviceId}
            onChange={(e) => setServiceId(String(e.target.value))}
            options={context.services.filter((s) => !branchId || !s.branch_ids?.length || s.branch_ids.includes(branchId) || s.id === serviceId).map((s) => ({ value: s.id, label: `${s.name} · ${s.duration_minutes} min · ${formatMoney(s.price)}` }))}
          />

          <div>
            <Select
              label={`¿Con qué ${vocab.pro.toLowerCase()}?`}
              clearable={false}
              disabled={!serviceId}
              placeholder={serviceId ? 'Calculando la espera...' : 'Elige primero el servicio'}
              value={serviceId && !loadingChoices ? professionalId : '__'}
              onChange={(e) => setProfessionalId(String(e.target.value))}
              options={serviceId && !loadingChoices ? professionalOptions : []}
            />
            <p className="text-xs text-slate-500 mt-1.5" data-choices-hint>
              {serviceId && choices && choices.professionals.length === 0
                ? `Hoy no trabaja en esta sede nadie que haga este servicio.`
                : `Solo aparecen quienes trabajan hoy en esta sede y hacen este servicio. La espera ya cuenta sus citas, sus pausas y a ${choices?.waiting ? `las ${choices.waiting} personas` : 'quienes están'} antes en la cola.`}
            </p>
            {serviceId && choices && full && (
              <p className="text-xs font-semibold text-amber-700 mt-1" data-choices-full>
                Hoy ya no hay espacio con esta elección. Puedes anotarlo igual o agendarle una cita para otro día.
              </p>
            )}
          </div>

          <div>
            <label className={labelClass} htmlFor="walkin-notes">Notas</label>
            <textarea
              id="walkin-notes"
              rows={2}
              maxLength={500}
              placeholder="Por ejemplo: corte bajo a los lados, viene con prisa"
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
          <Button type="submit" form={formId} disabled={saving} variant="classic">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Anotar en la cola'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
