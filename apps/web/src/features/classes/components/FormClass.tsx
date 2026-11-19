'use client';

import Link from 'next/link';
import { useEffect, useMemo, useState } from 'react';
import { CalendarClock } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import DateField from '@/app/components/common/DateField';
import TimeField from '@/app/components/common/TimeField';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import type { ClassOptions, ClassSession } from '../classes';

interface FormClassProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: ClassSession | null;
  options: ClassOptions | null;
  maxWeeks?: number;
  onSaved: (message: string, id?: string) => void;
}

function tomorrow(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function FormClass({ isOpen, onClose, initialData, options, maxWeeks = 12, onSaved }: FormClassProps) {
  const vocab = useVocabulary();
  const [serviceId, setServiceId] = useState('');
  const [branchId, setBranchId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [roomId, setRoomId] = useState('');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('19:00');
  const [capacity, setCapacity] = useState('');
  const [weeks, setWeeks] = useState('1');
  const [online, setOnline] = useState(true);
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const services = options?.services ?? [];
  const branches = options?.branches ?? [];

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setServiceId(initialData?.service.id ?? '');
    setBranchId(initialData?.branch?.id ?? options?.branches[0]?.id ?? '');
    setProfessionalId(initialData?.professional?.id ?? '');
    setRoomId(initialData?.room?.id ?? '');
    setDate(initialData?.date ?? tomorrow());
    setTime(initialData?.time ?? '19:00');
    setCapacity(initialData ? String(initialData.capacity) : '');
    setWeeks('1');
    setOnline(initialData?.online_booking ?? true);
    setNotes(initialData?.notes ?? '');
  }, [isOpen, initialData, options]);

  const service = services.find((s) => s.id === serviceId);

  const professionals = useMemo(() => (options?.professionals ?? []).filter((p) =>
    (!branchId || p.branch_ids.includes(branchId)) && (!service || service.professional_ids.length === 0 || service.professional_ids.includes(p.id)),
  ), [options, branchId, service]);

  const rooms = (options?.rooms ?? []).filter((r) => r.branch_id === branchId);

  const pickService = (id: string) => {
    setServiceId(id);
    const chosen = services.find((s) => s.id === id);
    if (chosen && !initialData) setCapacity(String(chosen.capacity));
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    if (!serviceId) return setError('Elige la clase.');
    if (!professionalId) return setError(`Elige quién da la clase.`);
    if (!date || !time) return setError('Elige la fecha y la hora.');

    const payload = {
      service_id: serviceId,
      branch_id: branchId,
      professional_id: professionalId,
      room_id: roomId || null,
      date,
      time,
      capacity: capacity ? Number(capacity) : null,
      online_booking: online,
      notes: notes.trim() || null,
    };

    try {
      setSaving(true);
      if (initialData) {
        const res = await api.put(`/classes/${initialData.id}`, payload);
        const promoted = Number(res.data?.result?.promoted ?? 0);
        onSaved(promoted > 0 ? `Clase actualizada. ${promoted === 1 ? 'Entró 1 persona' : `Entraron ${promoted} personas`} de la lista de espera.` : 'Clase actualizada.', initialData.id);
      } else {
        const res = await api.post('/classes', { ...payload, repeat_weeks: Number(weeks) });
        const created = Number(res.data?.created ?? 1);
        const skipped: { date: string; reason: string }[] = res.data?.skipped ?? [];
        const skippedText = skipped.length ? ` No se programó: ${skipped.map((s) => `${s.date.split('-').reverse().slice(0, 2).join('/')} (${s.reason})`).join('; ')}.` : '';
        onSaved(created > 1 ? `Se programaron ${created} clases.${skippedText}` : `Clase programada.${skippedText}`, res.data?.class?.id);
      }
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la clase.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'class-form';
  const weekOptions = [{ value: '1', label: 'Solo esta vez' }, ...Array.from({ length: maxWeeks - 1 }, (_, i) => ({ value: String(i + 2), label: `Cada semana, ${i + 2} semanas` }))];

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar ${initialData.title} del ${initialData.date.split('-').reverse().join('/')}` : 'Programar clase'}
      subtitle={initialData ? 'Los inscritos se mantienen' : 'Clase grupal con cupos'}
      icon={<CalendarClock size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={submit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-class-form>
          <div>
            <Select
              label="Clase *"
              placeholder={services.length ? 'Seleccione...' : 'Aún no hay servicios de clase grupal'}
              value={serviceId}
              disabled={Boolean(initialData)}
              clearable={false}
              onChange={(e) => pickService(String(e.target.value))}
              options={services.filter((s) => !branchId || !s.branch_ids?.length || s.branch_ids.includes(branchId) || s.id === serviceId).map((s) => ({ value: s.id, label: `${s.name} · ${s.duration_minutes} min · ${formatMoney(s.price)}` }))}
            />
            {services.length === 0 && (
              <p className="text-xs text-slate-500 mt-1.5">Crea la clase en <Link href="/services" className="text-brand-accent hover:underline">Servicios</Link> y marca que es grupal, con su cupo.</p>
            )}
          </div>

          {branches.length > 1 && (
            <Select
              label="Sede *"
              clearable={false}
              value={branchId}
              onChange={(e) => { setBranchId(String(e.target.value)); setRoomId(''); setProfessionalId(''); }}
              options={branches.map((b) => ({ value: b.id, label: b.name }))}
            />
          )}

          <div>
            <Select
              label={`${vocab.pro} *`}
              placeholder={professionals.length ? 'Seleccione...' : 'Nadie en esta sede da esta clase'}
              value={professionalId}
              onChange={(e) => setProfessionalId(String(e.target.value))}
              options={professionals.map((p) => ({ value: p.id, label: p.display_name }))}
            />
            <p className="text-xs text-slate-500 mt-1.5">Mientras dura la clase no se le pueden agendar citas.</p>
          </div>

          <Select
            label="Sala"
            placeholder={rooms.length ? 'Sin sala' : 'Esta sede no tiene salas registradas'}
            value={roomId}
            onChange={(e) => setRoomId(String(e.target.value))}
            options={rooms.map((r) => ({ value: r.id, label: r.name }))}
          />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className={labelClass}>Fecha *</label>
              <DateField value={date} onChange={setDate} required ariaLabel="Fecha de la clase" />
            </div>
            <div>
              <label className={labelClass}>Hora *</label>
              <TimeField value={time} onChange={setTime} required ariaLabel="Hora de la clase" />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className={labelClass} htmlFor="class-capacity">Cupos</label>
              <input id="class-capacity" type="number" min={initialData ? Math.max(1, initialData.taken) : 1} max={500} className={inputClass} placeholder={service ? String(service.capacity) : ''} value={capacity} onChange={(e) => setCapacity(e.target.value)} />
              {initialData && initialData.taken > 0 && <p className="text-xs text-slate-500 mt-1.5">Ya hay {initialData.taken} inscritos: no puede ser menos.</p>}
            </div>
            {!initialData && (
              <Select
                label="Repetir"
                clearable={false}
                value={weeks}
                onChange={(e) => setWeeks(String(e.target.value))}
                options={weekOptions}
              />
            )}
          </div>
          {!initialData && Number(weeks) > 1 && (
            <p className="text-xs text-slate-500 -mt-2">Se programa el mismo día y hora cada semana. Si una fecha choca con otra cosa, esa se salta y te avisamos.</p>
          )}

          <div className="flex items-start justify-between gap-4 p-3 rounded-xl border border-slate-200">
            <div>
              <p className="text-sm font-semibold text-slate-800">Inscripción en línea</p>
              <p className="text-xs text-slate-500">Que {vocab.clients.toLowerCase()} se inscriban desde su portal y la página de reservas.</p>
            </div>
            <Switch checked={online} onChange={setOnline} label="Inscripción en línea" />
          </div>

          <div>
            <label className={labelClass} htmlFor="class-notes">Notas</label>
            <textarea id="class-notes" rows={2} maxLength={1000} className={inputClass} placeholder="Por ejemplo: traer mat propio" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">Cancelar</button>
          <Button type="submit" form={formId} disabled={saving} variant="classic">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Programar'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
