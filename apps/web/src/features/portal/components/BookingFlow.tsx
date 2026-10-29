'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ArrowLeft, ArrowRight, CalendarCheck, Check, ChevronLeft, ChevronRight, Clock, Hourglass, Loader2, PawPrint, Plus, Search, UserRound, Video } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import Select from '@/app/components/common/Select';
import { addDays, dateOf, formatDate, timeOf, todayISO } from '@/features/appointments/appointments';
import { portalError, type PortalAppointment, type PortalAvailability, type PortalCatalog, type PortalDependent, type PortalOverview } from '../portal';
import { money } from '@/features/sales/sales';

interface BookingFlowProps {
  overview: PortalOverview;
  catalog: PortalCatalog;
  reschedule?: PortalAppointment | null;
  onDone: () => void;
  onExit: () => void;
  onDependentAdded: (dependent: PortalDependent) => void;
  onWaitlisted: () => void;
}

type Step = 'who' | 'service' | 'branch' | 'professional' | 'when' | 'review';

const STEP_TITLES: Record<Step, string> = {
  who: '¿Para quién es la cita?',
  service: '¿Qué servicio necesitas?',
  branch: '¿En qué sede?',
  professional: '¿Con quién?',
  when: '¿Cuándo?',
  review: 'Revisa y confirma',
};

const DAYS_PER_PAGE = 7;
const SEARCH_DAYS = 21;

const optionClass = (active: boolean, disabled = false) =>
  `w-full flex items-center gap-3 p-4 rounded-2xl border text-left transition-colors ${
    active ? 'border-brand bg-brand/5 ring-2 ring-brand/15' : 'border-slate-200 bg-white'
  } ${disabled ? 'opacity-60 cursor-not-allowed' : active ? '' : 'hover:border-brand-accent'}`;

const inputClass =
  'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand text-slate-800 bg-white';

export default function BookingFlow({ overview, catalog, reschedule, onDone, onExit, onDependentAdded, onWaitlisted }: BookingFlowProps) {
  const vocab = useVocabulary();
  const router = useRouter();
  const policies = overview.policies;
  const isReschedule = Boolean(reschedule);

  const [dependentId, setDependentId] = useState(overview.dependents.items.length === 1 ? overview.dependents.items[0].id : '');
  const [serviceId, setServiceId] = useState(reschedule?.service?.id ?? '');
  const [branchId, setBranchId] = useState(reschedule?.branch?.id ?? (catalog.branches.length === 1 ? catalog.branches[0].id : ''));
  const [professionalId, setProfessionalId] = useState(reschedule?.professional?.id ?? '');
  const [date, setDate] = useState(todayISO());
  const [time, setTime] = useState('');
  const [notes, setNotes] = useState('');
  const [pageStart, setPageStart] = useState(todayISO());

  const [availability, setAvailability] = useState<PortalAvailability | null>(null);
  const [loadingTimes, setLoadingTimes] = useState(false);
  const [searching, setSearching] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState<PortalAppointment | null>(null);

  const [waitOpen, setWaitOpen] = useState(false);
  const [waitTime, setWaitTime] = useState('any');
  const [waitDays, setWaitDays] = useState('');
  const [savingWait, setSavingWait] = useState(false);

  const joinWaitlist = async () => {
    try {
      setSavingWait(true);
      setError('');
      await api.post('/portal/waitlist', {
        service_id: serviceId,
        branch_id: branchId,
        professional_id: professionalId || null,
        customer_dependent_id: dependentId || null,
        time_of_day: waitTime,
        within_days: waitDays ? Number(waitDays) : null,
      });
      onWaitlisted();
    } catch (err) {
      setError(portalError(err, 'No se pudo anotar en la lista de espera.'));
    } finally {
      setSavingWait(false);
    }
  };

  const [addingPet, setAddingPet] = useState(overview.dependents.enabled && overview.dependents.items.length === 0);
  const [pet, setPet] = useState({ name: '', species: '' });
  const [savingPet, setSavingPet] = useState(false);

  const service = catalog.services.find((s) => s.id === serviceId) ?? null;

  const professionals = useMemo(
    () => catalog.professionals.filter((p) =>
      (!branchId || p.branch_ids.includes(branchId)) &&
      (!service || service.professional_ids.length === 0 || service.professional_ids.includes(p.id)),
    ),
    [catalog.professionals, branchId, service],
  );

  const steps = useMemo<Step[]>(() => {
    if (isReschedule) return ['when', 'review'];

    const list: Step[] = [];
    if (overview.dependents.enabled) list.push('who');
    list.push('service');
    if (catalog.branches.length > 1) list.push('branch');
    if (policies.choose_professional && professionals.length > 1) list.push('professional');
    list.push('when', 'review');
    return list;
  }, [isReschedule, overview.dependents.enabled, catalog.branches.length, policies.choose_professional, professionals.length]);

  const [stepIndex, setStepIndex] = useState(0);
  const step = steps[Math.min(stepIndex, steps.length - 1)];
  const lastDay = addDays(todayISO(), Math.max(1, policies.max_days_ahead));

  const loadTimes = (day: string) =>
    api.get('/portal/availability', {
      params: {
        service_id: serviceId,
        branch_id: branchId,
        date: day,
        ...(professionalId ? { professional_id: professionalId } : {}),
        ...(reschedule ? { ignore_id: reschedule.id } : {}),
      },
    }).then((res) => res.data as PortalAvailability);

  useEffect(() => {
    if (step !== 'when' || !serviceId || !branchId) return;

    setLoadingTimes(true);
    setAvailability(null);
    loadTimes(date)
      .then((data) => { setAvailability(data); setError(''); })
      .catch((err) => setError(portalError(err, 'No se pudieron cargar los horarios.')))
      .finally(() => setLoadingTimes(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, date, serviceId, branchId, professionalId]);

  const pickDate = (day: string) => {
    setDate(day);
    setTime('');
    setError('');
  };

  const findNextDay = async () => {
    setSearching(true);
    setError('');

    try {
      for (let offset = 1; offset <= SEARCH_DAYS; offset++) {
        const day = addDays(date, offset);
        if (day > lastDay) break;

        const data = await loadTimes(day);
        if (data.times.length > 0) {
          setPageStart(day);
          pickDate(day);
          return;
        }
      }
      setError('No encontramos horarios libres en las próximas semanas. Comunícate con la sede.');
    } catch (err) {
      setError(portalError(err, 'No se pudieron buscar horarios.'));
    } finally {
      setSearching(false);
    }
  };

  const savePet = async () => {
    try {
      setSavingPet(true);
      setError('');
      const res = await api.post('/portal/dependents', { name: pet.name, species: pet.species || null });
      onDependentAdded(res.data);
      setDependentId(res.data.id);
      setAddingPet(false);
      setPet({ name: '', species: '' });
    } catch (err) {
      setError(portalError(err, 'No se pudo guardar.'));
    } finally {
      setSavingPet(false);
    }
  };

  const canContinue = (() => {
    switch (step) {
      case 'who': return Boolean(dependentId) || !overview.dependents.required;
      case 'service': return Boolean(service);
      case 'branch': return Boolean(branchId);
      case 'professional': return true;
      case 'when': return Boolean(time);
      default: return true;
    }
  })();

  const goNext = () => { setError(''); setStepIndex((index) => Math.min(index + 1, steps.length - 1)); };
  const goBack = () => { setError(''); if (stepIndex === 0) onExit(); else setStepIndex((index) => index - 1); };

  const submit = async () => {
    try {
      setSaving(true);
      setError('');
      const startsAt = `${date}T${time}`;
      const res = reschedule
        ? await api.post(`/portal/appointments/${reschedule.id}/reschedule`, { starts_at: startsAt })
        : await api.post('/portal/appointments', {
            service_id: serviceId,
            branch_id: branchId,
            professional_id: professionalId || null,
            customer_dependent_id: dependentId || null,
            starts_at: startsAt,
            notes: notes.trim() || null,
          });
      if (res.status === 202 && res.data?.deposit?.token) {
        router.push(`/pay/${res.data.deposit.token}`);
        return;
      }
      setDone(res.data);
    } catch (err: any) {
      setError(portalError(err, 'No se pudo completar la reserva.'));
      if (err?.response?.data?.code === 'SLOT_UNAVAILABLE') {
        setTime('');
        setStepIndex(steps.indexOf('when'));
      }
    } finally {
      setSaving(false);
    }
  };

  if (done) {
    return (
      <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-8 text-center space-y-4" data-booking-done>
        <span className="w-14 h-14 mx-auto rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center"><Check size={28} /></span>
        <div className="space-y-1">
          <h2 className="text-xl font-black text-brand-ink">{isReschedule ? '¡Listo! Cambiamos tu cita' : '¡Tu cita está reservada!'}</h2>
          <p className="text-sm text-slate-600 first-letter:uppercase">
            {formatDate(dateOf(done.starts_at))}, a las {timeOf(done.starts_at)}
          </p>
          <p className="text-sm text-slate-500">
            {done.service?.name}{done.professional ? ` · ${done.professional.display_name}` : ''} · Código {done.code}
          </p>
        </div>
        <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200/70 rounded-xl px-4 py-3">
          {done.status === 'confirmed'
            ? 'Tu cita quedó confirmada.'
            : 'Tu cita quedó pendiente: confirma tu asistencia desde "Mis citas" cuando lo tengas claro.'}
          {policies.cancel_allowed && ` Puedes cambiarla o cancelarla hasta ${policies.cancel_window_hours} horas antes.`}
        </p>
        <button
          type="button"
          data-action="done"
          onClick={onDone}
          className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors"
        >
          Ver mis citas
        </button>
      </section>
    );
  }

  const days = Array.from({ length: DAYS_PER_PAGE }, (_, i) => addDays(pageStart, i)).filter((day) => day <= lastDay);
  const times = availability?.times ?? [];
  const groups: [string, string[]][] = [
    ['Mañana', times.filter((t) => t < '12:00')],
    ['Tarde', times.filter((t) => t >= '12:00' && t < '18:00')],
    ['Noche', times.filter((t) => t >= '18:00')],
  ];
  const chosenProfessional = catalog.professionals.find((p) => p.id === professionalId);
  const chosenDependent = overview.dependents.items.find((d) => d.id === dependentId);

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden" data-booking-step={step}>
      <header className="px-5 sm:px-6 pt-5 pb-4 border-b border-slate-100 space-y-3">
        <div className="flex items-center justify-between gap-3">
          <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
            {isReschedule ? `Cambiar cita ${reschedule!.code}` : 'Reservar cita'} · Paso {stepIndex + 1} de {steps.length}
          </span>
          <button type="button" onClick={onExit} className="text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors">
            Salir
          </button>
        </div>
        <div className="flex gap-1.5">
          {steps.map((item, index) => (
            <span key={item} className={`h-1.5 flex-1 rounded-full ${index <= stepIndex ? 'bg-brand' : 'bg-slate-200'}`} />
          ))}
        </div>
        <h2 className="text-lg font-black text-brand-ink tracking-tight">{STEP_TITLES[step]}</h2>
      </header>

      <div className="px-5 sm:px-6 py-5 space-y-3">
        {step === 'who' && (
          <>
            {overview.dependents.items.map((item) => (
              <button key={item.id} type="button" data-dependent={item.name} aria-pressed={dependentId === item.id} onClick={() => setDependentId(item.id)} className={optionClass(dependentId === item.id)}>
                <span className="w-10 h-10 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0"><PawPrint size={18} /></span>
                <span className="flex-1">
                  <span className="block text-sm font-bold text-slate-800">{item.name}</span>
                  {(item.species || item.breed) && <span className="block text-xs text-slate-500">{[item.species, item.breed].filter(Boolean).join(' · ')}</span>}
                </span>
              </button>
            ))}

            {!overview.dependents.required && (
              <button type="button" aria-pressed={dependentId === ''} onClick={() => setDependentId('')} className={optionClass(dependentId === '' && !addingPet)}>
                <span className="w-10 h-10 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"><UserRound size={18} /></span>
                <span className="text-sm font-bold text-slate-800">Para mí</span>
              </button>
            )}

            {addingPet ? (
              <div className="p-4 rounded-2xl border border-slate-200 bg-slate-50 space-y-3" data-new-dependent>
                <span className="block text-sm font-bold text-slate-800">Registrar {vocab.dependent.toLowerCase()}</span>
                <input type="text" maxLength={150} aria-label="Nombre" placeholder="Nombre" className={inputClass} value={pet.name} onChange={(e) => setPet({ ...pet, name: e.target.value })} />
                <input type="text" maxLength={80} aria-label="Especie" placeholder="Especie (opcional). Ej. Perro" className={inputClass} value={pet.species} onChange={(e) => setPet({ ...pet, species: e.target.value })} />
                <div className="flex justify-end gap-2">
                  {overview.dependents.items.length > 0 && (
                    <button type="button" onClick={() => setAddingPet(false)} className="px-4 py-2 text-sm font-semibold border border-slate-200 bg-white text-slate-600 rounded-xl hover:bg-slate-50">
                      Cancelar
                    </button>
                  )}
                  <button
                    type="button"
                    data-action="save-dependent"
                    disabled={savingPet || !pet.name.trim()}
                    onClick={savePet}
                    className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-50"
                  >
                    {savingPet ? 'Guardando...' : 'Guardar'}
                  </button>
                </div>
              </div>
            ) : (
              <button type="button" data-action="add-dependent" onClick={() => setAddingPet(true)} className="inline-flex items-center gap-1.5 text-sm font-bold text-brand-accent hover:text-brand-ink transition-colors">
                <Plus size={15} /> Registrar otro
              </button>
            )}
          </>
        )}

        {step === 'service' && (
          catalog.services.length === 0
            ? <p className="text-sm text-slate-500">Por ahora no hay servicios para reservar en línea. Comunícate con la sede.</p>
            : catalog.services.map((item) => (
              <button
                key={item.id}
                type="button"
                data-service={item.name}
                aria-pressed={serviceId === item.id}
                onClick={() => { setServiceId(item.id); setProfessionalId(''); setTime(''); }}
                className={optionClass(serviceId === item.id)}
              >
                <span className="flex-1 min-w-0">
                  <span className="flex items-center gap-2 text-sm font-bold text-slate-800">
                    {item.name}
                    {item.is_virtual && <span title="Por videollamada"><Video size={14} className="text-brand-accent" /></span>}
                  </span>
                  <span className="flex items-center gap-1.5 text-xs text-slate-500 mt-0.5">
                    <Clock size={12} /> {item.duration_minutes} min{item.category ? ` · ${item.category}` : ''}
                  </span>
                  {item.package && (
                    <span className="block text-xs font-semibold text-emerald-700 mt-1" data-service-package>Con tu paquete {item.package.name}: {item.package.available === 1 ? 'te queda 1 sesión por reservar' : `te quedan ${item.package.available} sesiones por reservar`}.</span>
                  )}
                  {item.needs_deposit && (
                    <span className="block text-xs font-medium text-brand-accent mt-1">Se reserva pagando un adelanto de {money(item.deposit_amount ?? 0)}{item.deposit_label?.includes('%') ? ` (${item.deposit_label})` : ''}.</span>
                  )}
                </span>
                <span className="text-sm font-extrabold text-brand-ink shrink-0">{money(item.price)}</span>
              </button>
            ))
        )}

        {step === 'branch' && catalog.branches.map((item) => (
          <button key={item.id} type="button" data-branch={item.name} aria-pressed={branchId === item.id} onClick={() => { setBranchId(item.id); setProfessionalId(''); setTime(''); }} className={optionClass(branchId === item.id)}>
            <span className="text-sm font-bold text-slate-800">{item.name}</span>
          </button>
        ))}

        {step === 'professional' && (
          <>
            <button type="button" data-professional="any" aria-pressed={professionalId === ''} onClick={() => { setProfessionalId(''); setTime(''); }} className={optionClass(professionalId === '')}>
              <span className="w-10 h-10 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"><Search size={18} /></span>
              <span>
                <span className="block text-sm font-bold text-slate-800">Cualquiera disponible</span>
                <span className="block text-xs text-slate-500">Verás más horarios para elegir</span>
              </span>
            </button>
            {professionals.map((item) => (
              <button key={item.id} type="button" data-professional={item.display_name} aria-pressed={professionalId === item.id} onClick={() => { setProfessionalId(item.id); setTime(''); }} className={optionClass(professionalId === item.id)}>
                <span className="w-10 h-10 rounded-xl bg-brand-soft/20 text-brand-ink flex items-center justify-center shrink-0"><UserRound size={18} /></span>
                <span className="text-sm font-bold text-slate-800">{item.display_name}</span>
              </button>
            ))}
          </>
        )}

        {step === 'when' && (
          <div className="space-y-4">
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                title="Días anteriores"
                disabled={pageStart <= todayISO()}
                onClick={() => setPageStart(addDays(pageStart, -DAYS_PER_PAGE) < todayISO() ? todayISO() : addDays(pageStart, -DAYS_PER_PAGE))}
                className="p-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-30 shrink-0"
              >
                <ChevronLeft size={16} />
              </button>
              <div className="flex-1 grid grid-cols-7 gap-1.5" data-days>
                {days.map((day) => (
                  <button
                    key={day}
                    type="button"
                    data-day={day}
                    aria-pressed={date === day}
                    onClick={() => pickDate(day)}
                    className={`flex flex-col items-center py-2 rounded-xl border transition-colors ${
                      date === day ? 'bg-brand border-brand text-white' : 'bg-white border-slate-200 text-slate-700 hover:border-brand-accent'
                    }`}
                  >
                    <span className="text-[10px] font-semibold uppercase opacity-80">{formatDate(day, { weekday: 'short' }).replace('.', '')}</span>
                    <span className="text-lg font-black leading-tight">{day.slice(8, 10)}</span>
                    <span className="text-[10px] opacity-80">{formatDate(day, { month: 'short' }).replace('.', '')}</span>
                  </button>
                ))}
              </div>
              <button
                type="button"
                title="Días siguientes"
                disabled={addDays(pageStart, DAYS_PER_PAGE) > lastDay}
                onClick={() => setPageStart(addDays(pageStart, DAYS_PER_PAGE))}
                className="p-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-30 shrink-0"
              >
                <ChevronRight size={16} />
              </button>
            </div>

            <p className="text-sm font-semibold text-slate-700 first-letter:uppercase">{formatDate(date)}</p>

            {loadingTimes ? (
              <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Buscando horarios...</p>
            ) : times.length === 0 ? (
              <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-3" data-no-times>
                <p className="text-sm text-slate-600">No hay horarios libres este día.</p>
                <button
                  type="button"
                  data-action="next-day"
                  disabled={searching}
                  onClick={findNextDay}
                  className="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-50"
                >
                  {searching ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />}
                  {searching ? 'Buscando...' : 'Buscar el próximo día con horarios'}
                </button>
              </div>
            ) : (
              <div className="space-y-3" data-times>
                {groups.filter(([, list]) => list.length > 0).map(([label, list]) => (
                  <div key={label}>
                    <span className="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">{label}</span>
                    <div className="grid grid-cols-4 sm:grid-cols-6 gap-1.5">
                      {list.map((slot) => (
                        <button
                          key={slot}
                          type="button"
                          data-time={slot}
                          aria-pressed={time === slot}
                          onClick={() => { setTime(slot); setError(''); }}
                          className={`py-2 text-sm font-semibold rounded-xl border transition-colors ${
                            time === slot ? 'bg-brand border-brand text-white' : 'bg-white border-slate-200 text-slate-700 hover:border-brand-accent'
                          }`}
                        >
                          {slot}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}

            {!isReschedule && !loadingTimes && (
              <div className="p-4 rounded-2xl border border-dashed border-slate-300 bg-white space-y-3" data-waitlist-box>
                {!waitOpen ? (
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-slate-600">
                      <span className="font-bold text-brand-ink">¿Ningún horario te sirve?</span> Anótate en la lista de espera y te mostraremos los horarios que se liberen.
                    </p>
                    <button
                      type="button"
                      data-action="open-waitlist"
                      onClick={() => setWaitOpen(true)}
                      className="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-brand-ink bg-white border border-slate-300 rounded-xl hover:border-brand-accent transition-colors"
                    >
                      <Hourglass size={15} className="text-brand-accent" /> Anotarme en la lista de espera
                    </button>
                  </div>
                ) : (
                  <>
                    <p className="text-sm font-bold text-brand-ink">Lista de espera para {service?.name}</p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <Select
                        label="¿A qué hora te sirve?"
                        clearable={false}
                        value={waitTime}
                        onChange={(e) => setWaitTime(String(e.target.value))}
                        options={[
                          { value: 'any', label: 'A cualquier hora' },
                          { value: 'morning', label: 'Por la mañana (hasta la 1 p. m.)' },
                          { value: 'afternoon', label: 'Por la tarde (desde la 1 p. m.)' },
                        ]}
                      />
                      <Select
                        label="¿Hasta cuándo te sirve?"
                        placeholder="Sin fecha límite"
                        value={waitDays}
                        onChange={(e) => setWaitDays(String(e.target.value))}
                        options={[
                          { value: '7', label: 'Solo esta semana (7 días)' },
                          { value: '14', label: 'Las próximas 2 semanas' },
                          { value: '30', label: 'El próximo mes' },
                        ]}
                      />
                    </div>
                    <p className="text-xs text-slate-500">
                      {professionalId ? 'Esperarás un horario con quien elegiste.' : `Te servirá un horario con cualquier ${vocab.pro.toLowerCase()}.`} Lo verás en "Mis citas" apenas haya uno libre.
                    </p>
                    <div className="flex flex-wrap gap-2">
                      <button
                        type="button"
                        data-action="join-waitlist"
                        disabled={savingWait}
                        onClick={joinWaitlist}
                        className="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-50"
                      >
                        {savingWait ? <Loader2 size={15} className="animate-spin" /> : <Hourglass size={15} />} Anotarme
                      </button>
                      <button type="button" onClick={() => setWaitOpen(false)} className="px-4 py-2 text-sm font-bold text-slate-600 hover:text-brand-ink transition-colors">
                        Mejor no
                      </button>
                    </div>
                  </>
                )}
              </div>
            )}
          </div>
        )}

        {step === 'review' && (
          <div className="space-y-4">
            <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100" data-summary>
              {([
                ['Servicio', reschedule?.service?.name ?? service?.name],
                [vocab.dependent, isReschedule ? reschedule?.dependent?.name : chosenDependent?.name],
                ['Fecha', formatDate(date)],
                ['Hora', time],
                [vocab.pro, chosenProfessional?.display_name ?? vocab.pick('pro', 'El primero disponible', 'La primera disponible')],
                ['Sede', catalog.branches.find((b) => b.id === branchId)?.name],
                ['Precio', service ? money(service.price) : reschedule ? money(reschedule.price) : null],
              ] as [string, string | null | undefined][]).filter(([, value]) => value).map(([label, value]) => (
                <div key={label} className="flex items-center justify-between gap-4 px-4 py-3">
                  <dt className="text-xs font-bold text-slate-400 uppercase tracking-wider">{label}</dt>
                  <dd className="text-sm font-semibold text-slate-800 text-right first-letter:uppercase">{value}</dd>
                </div>
              ))}
            </dl>

            {isReschedule ? (
              <p className="text-xs text-slate-500">
                Ahora tu cita es el {formatDate(dateOf(reschedule!.starts_at), { weekday: 'long', day: 'numeric', month: 'long' })} a las {timeOf(reschedule!.starts_at)}.
              </p>
            ) : (
              <textarea
                rows={2}
                maxLength={1000}
                aria-label="Comentario para la sede"
                placeholder="¿Algo que debamos saber? (opcional)"
                className={inputClass}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            )}

            {!isReschedule && service?.needs_deposit && (
              <div data-deposit-notice className="text-xs text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-xl px-4 py-3 space-y-1.5">
                <p>
                  <span className="font-bold">Para reservar pagas {money(service.deposit_amount ?? 0)} de adelanto ahora</span>; el resto ({money(Math.max(0, Number(service.price) - (service.deposit_amount ?? 0)))}) lo pagas en la sede.
                </p>
                <p>
                  Tu horario se reserva recién al pagar: tienes {policies.deposit_minutes ?? 30} minutos. Si otra persona toma ese horario antes, el link se anula y no se te cobra nada; si demoras, revisa que siga libre.
                </p>
                <p>Si cancelas con al menos {policies.deposit_refund_hours ?? 24} horas de anticipación, se te devuelve el adelanto.</p>
              </div>
            )}

            {!isReschedule && service?.package && (
              <p data-package-notice className="text-xs text-emerald-900 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                <span className="font-bold">Esta cita usa tu paquete {service.package.name}: no pagas nada ahora.</span> La sesión se descuenta cuando te atiendan.
              </p>
            )}

            {policies.pay_before_service && !service?.needs_deposit && !service?.package && (
              <p data-pay-notice className="text-xs text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-xl px-4 py-3">
                {service?.is_virtual ? (
                  <><span className="font-bold">Esta atención es por videollamada y se paga antes de empezar.</span> Comunícate con la sede para coordinar el pago.</>
                ) : (
                  <>
                    <span className="font-bold">El pago se hace en la sede, antes de la atención.</span>{' '}
                    {(policies.arrive_minutes ?? 0) > 0 ? `Llega ${policies.arrive_minutes} minutos antes de tu hora para pagar en recepción.` : 'Acércate a recepción al llegar.'}
                  </>
                )}
              </p>
            )}

            {!isReschedule && policies.miss && policies.miss.action !== 'none' && (
              <p data-miss-notice className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                {policies.miss.action === 'note'
                  ? 'Si no asistes y no cancelas a tiempo, quedará registrado en tu ficha.'
                  : `Si no asistes a ${policies.miss.limit === 1 ? 'una cita reservada' : `${policies.miss.limit} citas reservadas`} por aquí, no podrás volver a reservar en línea ${
                      policies.miss.block_days > 0 ? `durante ${policies.miss.block_days} días` : 'hasta que la sede lo permita'
                    }.`}
              </p>
            )}

            {policies.cancel_allowed && (
              <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200/70 rounded-xl px-4 py-3">
                Podrás cambiar o cancelar tu cita desde aquí hasta {policies.cancel_window_hours} horas antes.
              </p>
            )}
          </div>
        )}

        {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-3 py-2">{error}</p>}
      </div>

      <footer className="flex items-center justify-between gap-3 px-5 sm:px-6 py-4 border-t border-slate-100 bg-slate-50/60">
        <button type="button" data-action="back" onClick={goBack} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 bg-white text-slate-600 rounded-xl hover:bg-slate-50 transition-colors">
          <ArrowLeft size={15} /> {stepIndex === 0 ? 'Salir' : 'Atrás'}
        </button>
        {step === 'review' ? (
          <button
            type="button"
            data-action="submit"
            disabled={saving}
            onClick={submit}
            className="flex items-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-50"
          >
            <CalendarCheck size={16} /> {saving ? 'Guardando...' : isReschedule ? 'Cambiar mi cita' : service?.needs_deposit ? `Pagar adelanto de ${money(service.deposit_amount ?? 0)}` : 'Reservar cita'}
          </button>
        ) : (
          <button
            type="button"
            data-action="next"
            disabled={!canContinue}
            onClick={goNext}
            className="flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-40"
          >
            Continuar <ArrowRight size={15} />
          </button>
        )}
      </footer>
    </section>
  );
}
