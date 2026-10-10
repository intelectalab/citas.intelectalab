'use client';

import { useEffect, useMemo, useState } from 'react';
import { CalendarPlus, CalendarClock, Plus, Search, X } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import FormCustomer from '@/features/customers/components/FormCustomer';
import QuickDependent from '@/features/customers/components/QuickDependent';
import QuickService from '@/features/services/components/QuickService';
import { apiError, dateOf, formatDateTime, timeOf, todayISO, type Appointment, type AppointmentContext } from '../appointments';

export interface CustomerOption {
  id: string;
  code: string;
  first_name: string;
  last_name: string;
  phone?: string | null;
  document_number?: string | null;
  dependents?: { id: string; name: string; species?: string | null }[];
}

interface SlotGroup {
  professional: { id: string; display_name: string };
  slots: string[];
}

interface AppointmentFormProps {
  isOpen: boolean;
  onClose: () => void;
  onSaved: (appointment: Appointment) => void;
  // Valores con los que abre el formulario (por ejemplo, la casilla de la agenda donde se hizo clic).
  initial?: {
    date?: string; time?: string; professional_id?: string; branch_id?: string;
    // Cita que nace de un control pendiente: llega con el cliente y el servicio ya elegidos.
    customer?: CustomerOption; customer_dependent_id?: string; service_id?: string; recall_id?: string; recall_label?: string;
    // Cita para alguien de la lista de espera.
    waitlist_id?: string;
    // Texto corto que dice de dónde viene la cita.
    origin_label?: string;
  };
  // Con una cita, el formulario la reprograma en lugar de crear otra.
  reschedule?: Appointment | null;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

// Panel lateral para agendar una cita o reprogramar una existente. Los
// horarios libres salen de la API; también se puede escribir otra hora.
export default function AppointmentForm({ isOpen, onClose, onSaved, initial, reschedule }: AppointmentFormProps) {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const dependents = user?.tenant?.dependents;
  const isReschedule = Boolean(reschedule);

  const [context, setContext] = useState<AppointmentContext | null>(null);
  // Crear lo que falta sin salir de la cita: una mascota o un servicio.
  const [addingDependent, setAddingDependent] = useState(false);
  const [serviceFormOpen, setServiceFormOpen] = useState(false);
  const [customerQuery, setCustomerQuery] = useState('');
  const [customerResults, setCustomerResults] = useState<CustomerOption[]>([]);
  const [searching, setSearching] = useState(false);
  const [customer, setCustomer] = useState<CustomerOption | null>(null);
  const [dependentId, setDependentId] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [branchId, setBranchId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [date, setDate] = useState(todayISO());
  const [time, setTime] = useState('');
  const [notes, setNotes] = useState('');
  const [reason, setReason] = useState('');
  const [overrideReason, setOverrideReason] = useState('');

  const [slotGroups, setSlotGroups] = useState<SlotGroup[]>([]);
  const [loadingSlots, setLoadingSlots] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  // El horario no está libre pero se puede forzar con un motivo.
  const [canForce, setCanForce] = useState(false);
  // Formulario de cliente nuevo, abierto encima de este panel.
  const [customerFormOpen, setCustomerFormOpen] = useState(false);
  // Sin horarios ese día: la persona quedó anotada en la lista de espera.
  const [waitlisted, setWaitlisted] = useState(false);
  const [waitlisting, setWaitlisting] = useState(false);

  // Al abrir: carga sedes, profesionales y servicios, y deja el formulario en su punto de partida.
  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setCanForce(false);
    setWaitlisted(false);
    setCustomerFormOpen(false);
    setOverrideReason('');
    setReason('');
    setCustomer(null);
    setCustomerQuery('');
    setCustomerResults([]);
    setDependentId('');
    setNotes('');
    setSlotGroups([]);

    if (reschedule) {
      setServiceId(reschedule.service?.id ?? '');
      setBranchId(reschedule.branch?.id ?? '');
      setProfessionalId(reschedule.professional?.id ?? '');
      setDate(dateOf(reschedule.starts_at));
      setTime('');
    } else {
      setServiceId(initial?.service_id ?? '');
      if (initial?.customer) {
        setCustomer(initial.customer);
        setDependentId(initial.customer_dependent_id ?? (initial.customer.dependents?.length === 1 ? initial.customer.dependents[0].id : ''));
      }
      setBranchId(initial?.branch_id ?? '');
      setProfessionalId(initial?.professional_id ?? '');
      setDate(initial?.date ?? todayISO());
      setTime(initial?.time ?? '');
    }

    api.get('/appointments/context')
      .then((res) => {
        const data: AppointmentContext = res.data;
        setContext(data);
        if (!reschedule && !initial?.branch_id) {
          setBranchId(data.branches[0]?.id ?? '');
        }
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los datos para agendar.')));
  }, [isOpen, reschedule, initial]);

  // Búsqueda de clientes mientras se escribe.
  useEffect(() => {
    if (!isOpen || customer || customerQuery.trim().length < 2) {
      setCustomerResults([]);
      return;
    }

    setSearching(true);
    const timer = setTimeout(() => {
      api.get('/customers', { params: { search: customerQuery.trim() } })
        .then((res) => setCustomerResults(res.data?.data ?? []))
        .catch(() => setCustomerResults([]))
        .finally(() => setSearching(false));
    }, 300);

    return () => clearTimeout(timer);
  }, [customerQuery, customer, isOpen]);

  const service = context?.services.find((s) => s.id === serviceId) ?? null;

  // Profesionales que atienden en la sede elegida y, si el servicio tiene asignados, solo esos.
  const professionals = useMemo(() => {
    if (!context) return [];
    return context.professionals.filter((p) =>
      (!branchId || p.branch_ids.length === 0 || p.branch_ids.includes(branchId)) &&
      (!service || service.professional_ids.length === 0 || service.professional_ids.includes(p.id)),
    );
  }, [context, branchId, service]);

  // Horarios libres del día para el servicio, la sede y el profesional elegidos.
  useEffect(() => {
    if (!isOpen || !serviceId || !branchId || !date) {
      setSlotGroups([]);
      return;
    }

    setLoadingSlots(true);
    api.get('/appointments/availability', {
      params: {
        service_id: serviceId,
        branch_id: branchId,
        date,
        ...(professionalId ? { professional_id: professionalId } : {}),
        ...(reschedule ? { ignore_id: reschedule.id } : {}),
      },
    })
      .then((res) => setSlotGroups(res.data?.professionals ?? []))
      .catch(() => setSlotGroups([]))
      .finally(() => setLoadingSlots(false));
  }, [isOpen, serviceId, branchId, date, professionalId, reschedule]);

  const pickSlot = (slotProfessionalId: string, slot: string) => {
    setProfessionalId(slotProfessionalId);
    setTime(slot);
    setError('');
    setCanForce(false);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (!isReschedule && !customer) return setError(`Elige un ${vocab.client.toLowerCase()}.`);
    if (!serviceId) return setError('Elige un servicio.');
    if (!professionalId) return setError(`Elige un ${vocab.pro.toLowerCase()} o uno de los horarios libres.`);
    if (!time) return setError('Elige una hora.');

    const startsAt = `${date}T${time}`;
    const force = canForce && overrideReason.trim() ? { override_reason: overrideReason.trim() } : {};

    try {
      setSaving(true);
      const res = reschedule
        ? await api.post(`/appointments/${reschedule.id}/reschedule`, {
            starts_at: startsAt,
            professional_id: professionalId,
            branch_id: branchId,
            reason: reason.trim() || null,
            ...force,
          })
        : await api.post('/appointments', {
            customer_id: customer!.id,
            customer_dependent_id: dependentId || null,
            service_id: serviceId,
            professional_id: professionalId,
            branch_id: branchId,
            starts_at: startsAt,
            notes: notes.trim() || null,
            ...(initial?.recall_id ? { recall_id: initial.recall_id } : {}),
            ...(initial?.waitlist_id ? { waitlist_id: initial.waitlist_id } : {}),
            ...force,
          });

      onSaved(res.data);
      onClose();
    } catch (err: any) {
      const data = err?.response?.data;
      setError(apiError(err, 'No se pudo guardar la cita.'));
      setCanForce(Boolean(data?.code === 'SLOT_UNAVAILABLE' && data?.overridable && context?.can_override));
    } finally {
      setSaving(false);
    }
  };

  // No hay horario ese día: se anota para avisarle cuando se libere uno, desde esa fecha.
  const addToWaitlist = async () => {
    if (!customer || !serviceId || !branchId) return;

    try {
      setWaitlisting(true);
      setError('');
      await api.post('/waitlist', {
        customer_id: customer.id,
        customer_dependent_id: dependentId || null,
        service_id: serviceId,
        branch_id: branchId,
        professional_id: professionalId || null,
        date_from: date,
        notes: notes.trim() || null,
      });
      setWaitlisted(true);
    } catch (err) {
      setError(apiError(err, 'No se pudo anotar en la lista de espera.'));
    } finally {
      setWaitlisting(false);
    }
  };

  const chooseCustomer = (option: CustomerOption) => {
    setCustomer(option);
    setDependentId(option.dependents?.length === 1 ? option.dependents[0].id : '');
    setError('');
  };

  const hasSlots = slotGroups.some((group) => group.slots.length > 0);
  const formId = 'appointment-form';

  return (
    <>
    <FormDrawer
      isOpen={isOpen}
      // Con el formulario de cliente encima, Escape cierra solo ese.
      onClose={customerFormOpen ? () => {} : onClose}
      title={reschedule ? `Reprogramar cita ${reschedule.code}` : 'Nueva cita'}
      subtitle={reschedule
        ? `${reschedule.customer?.name ?? ''} · ${reschedule.service?.name ?? ''} · ahora: ${formatDateTime(reschedule.starts_at)}`
        : initial?.recall_label ? `Control: ${initial.recall_label}` : initial?.origin_label ?? 'Elige a quién se atiende, el servicio y un horario libre'}
      icon={reschedule ? <CalendarClock size={20} /> : <CalendarPlus size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-4 overflow-y-auto flex-1 pr-1 pb-4">
          {!isReschedule && (
            <div>
              <label className={labelClass}>{vocab.client} *</label>
              {customer ? (
                <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-lg px-3.5 py-2.5 bg-slate-50">
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
                        value={customerQuery}
                        onChange={(e) => setCustomerQuery(e.target.value)}
                      />
                    </div>
                    <button
                      type="button"
                      data-new-customer
                      title={`Nuevo ${vocab.client.toLowerCase()}`}
                      aria-label={`Nuevo ${vocab.client.toLowerCase()}`}
                      onClick={() => setCustomerFormOpen(true)}
                      className="shrink-0 p-2.5 bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
                    >
                      <Plus size={18} className="stroke-[2.5]" />
                    </button>
                  </div>
                  {customerQuery.trim().length >= 2 && (
                    <div className="mt-1.5 border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-56 overflow-y-auto bg-white">
                      {searching && customerResults.length === 0 ? (
                        <p className="px-3.5 py-2.5 text-sm text-slate-400">Buscando...</p>
                      ) : customerResults.length === 0 ? (
                        <p className="px-3.5 py-2.5 text-sm text-slate-400">
                          Sin resultados. Si es nuevo, regístralo con el botón +.
                        </p>
                      ) : (
                        customerResults.map((option) => (
                          <button
                            key={option.id}
                            type="button"
                            data-customer={option.id}
                            onClick={() => chooseCustomer(option)}
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

          {!isReschedule && dependents?.enabled && customer && (
            <div>
              <Select
                label={`${vocab.dependent}${dependents.required ? ' *' : ''}`}
                placeholder={(customer.dependents?.length ?? 0) === 0 ? `Aún no tiene ${vocab.dependents.toLowerCase()}: agrégalo con +` : dependents.required ? 'Seleccione...' : 'Ninguno'}
                value={dependentId}
                onChange={(e) => setDependentId(String(e.target.value))}
                options={(customer.dependents ?? []).map((d) => ({ value: d.id, label: d.species ? `${d.name} (${d.species})` : d.name }))}
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

          {!isReschedule && (
            <Select
              label="Servicio *"
              placeholder="Seleccione..."
              value={serviceId}
              onChange={(e) => { setServiceId(String(e.target.value)); setTime(initial?.time ?? ''); setCanForce(false); }}
              options={(context?.services ?? []).map((s) => ({ value: s.id, label: `${s.name} · ${s.duration_minutes} min` }))}
              onAdd={user?.is_tenant_admin ? () => setServiceFormOpen(true) : undefined}
              addLabel="Nuevo servicio"
            />
          )}

          {(context?.branches.length ?? 0) > 1 && (
            <Select
              label="Sede *"
              value={branchId}
              onChange={(e) => { setBranchId(String(e.target.value)); setProfessionalId(''); setTime(''); }}
              options={(context?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))}
            />
          )}

          <Select
            label={vocab.pro}
            placeholder="Cualquiera disponible"
            value={professionalId}
            onChange={(e) => { setProfessionalId(String(e.target.value)); setCanForce(false); }}
            options={[{ value: '', label: 'Cualquiera disponible' }, ...professionals.map((p) => ({ value: p.id, label: p.display_name }))]}
          />

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Fecha *</label>
              <input
                type="date"
                required
                aria-label="Fecha de la cita"
                className={inputClass}
                value={date}
                onChange={(e) => { setDate(e.target.value); setTime(''); setCanForce(false); }}
              />
            </div>
            <div>
              <label className={labelClass}>Hora *</label>
              <input
                type="time"
                step={300}
                aria-label="Hora de la cita"
                className={inputClass}
                value={time}
                onChange={(e) => { setTime(e.target.value); setCanForce(false); }}
              />
            </div>
          </div>

          <div>
            <span className={labelClass}>Horarios libres</span>
            {!serviceId ? (
              <p className="text-sm text-slate-400">Elige un servicio para ver los horarios libres.</p>
            ) : loadingSlots ? (
              <p className="text-sm text-slate-400">Buscando horarios...</p>
            ) : !hasSlots ? (
              <div className="space-y-2" data-no-slots>
                <p className="text-sm text-slate-400">No hay horarios libres ese día. Prueba con otra fecha.</p>
                {!isReschedule && !initial?.waitlist_id && customer && (
                  waitlisted ? (
                    <p data-waitlisted className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">
                      {customer.first_name} quedó en la lista de espera desde esa fecha. Lo verás en Lista de espera.
                    </p>
                  ) : (
                    <button
                      type="button"
                      data-action="waitlist"
                      disabled={waitlisting}
                      onClick={addToWaitlist}
                      className="px-3.5 py-2 text-sm font-medium border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                    >
                      {waitlisting ? 'Anotando...' : 'Anotar en lista de espera'}
                    </button>
                  )
                )}
              </div>
            ) : (
              <div className="space-y-3">
                {slotGroups.filter((group) => group.slots.length > 0).map((group) => (
                  <div key={group.professional.id} data-slots={group.professional.id}>
                    <span className="block text-xs font-semibold text-slate-600 mb-1.5">{group.professional.display_name}</span>
                    <div className="flex flex-wrap gap-1.5">
                      {group.slots.map((slot) => {
                        const isPicked = professionalId === group.professional.id && time === slot;
                        return (
                          <button
                            key={slot}
                            type="button"
                            aria-pressed={isPicked}
                            onClick={() => pickSlot(group.professional.id, slot)}
                            className={`px-2.5 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${
                              isPicked
                                ? 'bg-[#022340] text-white border-[#022340]'
                                : 'bg-white text-slate-700 border-slate-200 hover:border-[#3F768C] hover:text-[#022340]'
                            }`}
                          >
                            {slot}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {isReschedule ? (
            <div>
              <label className={labelClass}>Motivo del cambio</label>
              <input
                type="text"
                maxLength={150}
                placeholder="Ej. Lo pidió el cliente"
                className={inputClass}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
            </div>
          ) : (
            <div>
              <label className={labelClass}>Notas</label>
              <textarea
                rows={2}
                maxLength={2000}
                placeholder="Indicaciones para la atención"
                className={inputClass}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </div>
          )}

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

          {canForce && (
            <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg space-y-2">
              <label className="block text-xs font-semibold uppercase tracking-wider text-amber-700">Agendar de todos modos</label>
              <p className="text-xs text-amber-700">Ese horario no está libre, pero puedes forzarlo indicando el motivo. Queda registrado en la cita.</p>
              <input
                type="text"
                maxLength={255}
                aria-label="Motivo para agendar de todos modos"
                placeholder="Ej. Urgencia"
                className={inputClass}
                value={overrideReason}
                onChange={(e) => setOverrideReason(e.target.value)}
              />
            </div>
          )}
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
            disabled={saving || (canForce && !overrideReason.trim())}
            className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
          >
            {saving ? 'Guardando...' : canForce ? 'Agendar de todos modos' : reschedule ? 'Reprogramar' : 'Agendar cita'}
          </button>
        </div>
      </div>
    </FormDrawer>

    {/* Servicio nuevo sin salir de la cita: al guardarlo queda elegido. */}
    <QuickService
      isOpen={isOpen && serviceFormOpen}
      onClose={() => setServiceFormOpen(false)}
      onCreated={(service) => {
        setContext((current) => (current ? { ...current, services: [...current.services, service] } : current));
        setServiceId(service.id);
        setTime('');
      }}
    />

    {/* Cliente nuevo sin salir de la cita: al guardarlo queda elegido. */}
    <FormCustomer
      isOpen={isOpen && customerFormOpen}
      onClose={() => setCustomerFormOpen(false)}
      onSubmitSuccess={(created) => {
        if (created?.id) {
          chooseCustomer(created);
          setCustomerQuery('');
        }
      }}
    />
    </>
  );
}
