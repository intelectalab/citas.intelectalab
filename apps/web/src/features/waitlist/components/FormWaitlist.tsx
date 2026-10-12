'use client';

import { useEffect, useMemo, useState } from 'react';
import { Hourglass } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import TimeField from '@/app/components/common/TimeField';
import Switch from '@/app/components/common/Switch';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import QuickDependent from '@/features/customers/components/QuickDependent';
import QuickService, { type CreatedService } from '@/features/services/components/QuickService';
import { apiError, todayISO, type AppointmentContext } from '@/features/appointments/appointments';
import { TIME_PRESETS, type WaitlistEntry } from '../waitlist';

interface FormWaitlistProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: WaitlistEntry | null;
  context: AppointmentContext | null;
  onSaved: () => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

// Panel para anotar a alguien en la lista de espera o cambiar lo que pidió:
// qué servicio, con quién, entre qué fechas y a qué horas le sirve.
export default function FormWaitlist({ isOpen, onClose, initialData, context, onSaved }: FormWaitlistProps) {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const dependents = user?.tenant?.dependents;

  const [customer, setCustomer] = useState<PickedCustomer | null>(null);
  const [dependentId, setDependentId] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [branchId, setBranchId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [dateFrom, setDateFrom] = useState(todayISO());
  const [dateTo, setDateTo] = useState('');
  const [timeFrom, setTimeFrom] = useState('');
  const [timeTo, setTimeTo] = useState('');
  const [priority, setPriority] = useState(false);
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  // Lo creado sin salir del panel: una mascota o un servicio.
  const [addingDependent, setAddingDependent] = useState(false);
  const [serviceFormOpen, setServiceFormOpen] = useState(false);
  const [newServices, setNewServices] = useState<CreatedService[]>([]);
  const services = [...(context?.services ?? []), ...newServices];

  const branches = context?.branches ?? [];

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setCustomer(null);
    setDependentId(initialData?.dependent?.id ?? '');
    setServiceId(initialData?.service?.id ?? '');
    setBranchId(initialData?.branch?.id ?? context?.branches[0]?.id ?? '');
    setProfessionalId(initialData?.professional?.id ?? '');
    setDateFrom(initialData?.date_from ?? todayISO());
    setDateTo(initialData?.date_to ?? '');
    setTimeFrom(initialData?.time_from ?? '');
    setTimeTo(initialData?.time_to ?? '');
    setPriority(initialData?.priority ?? false);
    setNotes(initialData?.notes ?? '');
    // Solo al abrir: las listas no cambian mientras el panel está abierto.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, initialData]);

  const service = services.find((s) => s.id === serviceId) ?? null;

  // Profesionales que atienden en la sede elegida y, si el servicio tiene asignados, solo esos.
  const professionals = useMemo(() => {
    if (!context) return [];
    return context.professionals.filter((p) =>
      (!branchId || p.branch_ids.length === 0 || p.branch_ids.includes(branchId)) &&
      (!service || service.professional_ids.length === 0 || service.professional_ids.includes(p.id)),
    );
  }, [context, branchId, service]);

  const preset = TIME_PRESETS.find((p) => p.from === timeFrom && p.to === timeTo)?.id ?? 'custom';
  const dependentOptions = customer?.dependents ?? [];

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!initialData && !customer) return setError('Elige a quién se anota.');
    if (!serviceId) return setError('Elige el servicio que espera.');
    if (dateTo && dateTo < dateFrom) return setError('La última fecha no puede ser anterior a la primera.');
    if (timeFrom && timeTo && timeTo <= timeFrom) return setError('La hora final debe ser posterior a la inicial.');

    const payload = {
      service_id: serviceId,
      branch_id: branchId,
      professional_id: professionalId || null,
      customer_dependent_id: dependentId || null,
      date_from: dateFrom,
      date_to: dateTo || null,
      time_from: timeFrom || null,
      time_to: timeTo || null,
      priority,
      notes: notes.trim() || null,
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) {
        await api.put(`/waitlist/${initialData.id}`, payload);
      } else {
        await api.post('/waitlist', { ...payload, customer_id: customer!.id });
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar en la lista de espera.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'waitlist-form';

  return (
    <>
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar espera: ${initialData.customer?.name ?? ''}` : 'Anotar en lista de espera'}
      subtitle={initialData ? initialData.service?.name ?? '' : 'Para avisarle cuando se libere un horario'}
      icon={<Hourglass size={20} />}
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

          {!initialData && dependents?.enabled && customer && (
            <div>
              <Select
                label={`${vocab.dependent}${dependents.required ? ' *' : ''}`}
                placeholder={dependentOptions.length === 0 ? `Aún no tiene ${vocab.dependents.toLowerCase()}: agrégalo con +` : dependents.required ? 'Seleccione...' : 'Ninguno'}
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

          <Select
            label="Servicio que espera *"
            placeholder="Seleccione..."
            value={serviceId}
            onChange={(e) => setServiceId(String(e.target.value))}
            options={services.map((s) => ({ value: s.id, label: `${s.name} · ${s.duration_minutes} min` }))}
            onAdd={user?.is_tenant_admin ? () => setServiceFormOpen(true) : undefined}
            addLabel="Nuevo servicio"
          />

          {branches.length > 1 && (
            <Select
              label="Sede *"
              value={branchId}
              onChange={(e) => { setBranchId(String(e.target.value)); setProfessionalId(''); }}
              options={branches.map((b) => ({ value: b.id, label: b.name }))}
            />
          )}

          <Select
            label={`¿Con qué ${vocab.pro.toLowerCase()}?`}
            placeholder="Cualquiera disponible"
            value={professionalId}
            onChange={(e) => setProfessionalId(String(e.target.value))}
            options={professionals.map((p) => ({ value: p.id, label: p.display_name }))}
          />

          <div>
            <span className={labelClass}>¿Qué fechas le sirven?</span>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-slate-500 mb-1" htmlFor="waitlist-from">Desde *</label>
                <DateField
                  id="waitlist-from"
                  required
                  value={dateFrom}
                  onChange={(value) => { setDateFrom(value); if (dateTo && dateTo < value) setDateTo(value); }}
                />
              </div>
              <div>
                <label className="block text-xs text-slate-500 mb-1" htmlFor="waitlist-to">Hasta</label>
                <DateField id="waitlist-to" min={dateFrom} align="right" placeholder="Sin fecha final" value={dateTo} onChange={setDateTo} />
              </div>
            </div>
            <p className="text-xs text-slate-500 mt-1.5">Sin fecha final, espera hasta que haya un horario. Con ella, sale de la lista al pasar ese día.</p>
          </div>

          <div>
            <span className={labelClass}>¿A qué hora?</span>
            <div className="flex flex-wrap gap-2">
              {TIME_PRESETS.map((option) => (
                <button
                  key={option.id}
                  type="button"
                  data-time={option.id}
                  aria-pressed={preset === option.id}
                  onClick={() => { setTimeFrom(option.from); setTimeTo(option.to); }}
                  className={`px-3.5 py-2 text-sm font-medium border rounded-full transition-colors ${
                    preset === option.id ? 'border-[#022340] bg-[#022340] text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                  }`}
                >
                  {option.label}
                </button>
              ))}
            </div>
            <div className="grid grid-cols-2 gap-3 mt-3">
              <div>
                <label className="block text-xs text-slate-500 mb-1" htmlFor="waitlist-time-from">Desde las</label>
                <TimeField id="waitlist-time-from" value={timeFrom} onChange={setTimeFrom} />
              </div>
              <div>
                <label className="block text-xs text-slate-500 mb-1" htmlFor="waitlist-time-to">Hasta las</label>
                <TimeField id="waitlist-time-to" value={timeTo} onChange={setTimeTo} />
              </div>
            </div>
          </div>

          <label className="flex items-center justify-between gap-3 px-4 py-3 border border-slate-200 rounded-xl cursor-pointer">
            <span>
              <span className="block text-sm font-semibold text-slate-800">Prioritario</span>
              <span className="block text-xs text-slate-500">Pasa al inicio de la lista, antes que quienes llegaron primero.</span>
            </span>
            <Switch checked={priority} onChange={setPriority} label="Marcar como prioritario" />
          </label>

          <div>
            <label className={labelClass} htmlFor="waitlist-notes">Notas</label>
            <textarea
              id="waitlist-notes"
              rows={2}
              maxLength={1000}
              placeholder="Lo que conviene saber al llamarle"
              className={inputClass}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
          >
            Cancelar
          </button>
          <button
            type="submit"
            form={formId}
            disabled={saving}
            className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
          >
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Anotar en la lista'}
          </button>
        </div>
      </div>
    </FormDrawer>

    {/* Servicio nuevo sin salir del panel: al guardarlo queda elegido. */}
    <QuickService
      isOpen={isOpen && serviceFormOpen}
      onClose={() => setServiceFormOpen(false)}
      onCreated={(created) => { setNewServices((current) => [...current, created]); setServiceId(created.id); }}
    />
    </>
  );
}
