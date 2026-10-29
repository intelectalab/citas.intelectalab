'use client';

import Button from '@/app/components/common/Button';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert } from '@/app/components/common/Alert';
import { labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { CalendarPlus, Clock } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import { Pager, usePaged } from '@/app/components/common/Pager';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import type { PosService, WalkInOptions } from '../pos';

export interface BookedWalkIn {
  id: string;
  code: string;
  starts_at: string;
  status_name: string;
  customer: PickedCustomer;
  service: PosService;
  professional: string;
}

interface WalkInPanelProps {
  isOpen: boolean;
  onClose: () => void;
  branchId: string | null;
  services: PosService[];
  customer: PickedCustomer | null;
  customerLocked: boolean;
  onBooked: (booked: BookedWalkIn) => void;
}


const chip = (active: boolean, strong = false) =>
  `px-3 py-1.5 text-sm font-semibold rounded-lg border transition-colors tabular-nums ${
    active ? 'bg-brand text-white border-brand' : strong ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
  }`;

export default function WalkInPanel({ isOpen, onClose, branchId, services, customer, customerLocked, onBooked }: WalkInPanelProps) {
  const [person, setPerson] = useState<PickedCustomer | null>(customer);
  const [serviceId, setServiceId] = useState('');
  const [options, setOptions] = useState<WalkInOptions | null>(null);
  const [loading, setLoading] = useState(false);
  const [choice, setChoice] = useState<{ professionalId: string; at: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setPerson(customer);
    setServiceId('');
    setOptions(null);
    setChoice(null);
    setError('');
  }, [isOpen, customer]);

  useEffect(() => {
    if (!isOpen || !serviceId || !branchId) return;

    setLoading(true);
    setChoice(null);
    setError('');
    api.get('/pos/walk-in', { params: { branch_id: branchId, service_id: serviceId } })
      .then((res) => {
        const data: WalkInOptions = res.data;
        setOptions(data);
        const free = data.professionals.find((p) => p.free_now);
        if (free) setChoice({ professionalId: free.id, at: 'now' });
      })
      .catch((err) => setError(apiError(err, 'No se pudieron revisar los horarios.')))
      .finally(() => setLoading(false));
  }, [isOpen, serviceId, branchId]);

  const professionals = options?.professionals ?? [];
  const { rows, page, pages, setPage } = usePaged(professionals);
  const service = services.find((s) => s.id === serviceId) ?? null;
  const chosen = professionals.find((p) => p.id === choice?.professionalId);

  const submit = async () => {
    if (!person || !service || !choice || !branchId) return;

    try {
      setSaving(true);
      setError('');
      const res = await api.post('/pos/walk-in', {
        branch_id: branchId,
        service_id: service.id,
        professional_id: choice.professionalId,
        customer_id: person.id,
        at: choice.at,
      });
      onBooked({ ...res.data, customer: person, service, professional: chosen?.display_name ?? '' });
    } catch (err) {
      setError(apiError(err, 'No se pudo agendar.'));
    } finally {
      setSaving(false);
    }
  };

  const summary = chosen && choice
    ? `${choice.at === 'now' ? `Ahora (${options?.now})` : `Hoy a las ${choice.at}`} con ${chosen.display_name}`
    : null;

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Agendar para ahora"
      subtitle="Para quien llegó sin cita: queda en la agenda y pasa al carrito"
      icon={<CalendarPlus size={20} />}
    >
      <div className="flex flex-col h-full justify-between" data-walk-in>
        <div className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <CustomerPicker
            value={person}
            onChange={setPerson}
            active={isOpen}
            locked={customerLocked}
            lockedHint="Es el cliente del carrito"
          />

          <Select
            label="Servicio *"
            placeholder="¿Qué le van a hacer?"
            value={serviceId}
            clearable={false}
            onChange={(e) => setServiceId(String(e.target.value))}
            options={services.map((s) => ({ value: s.id, label: `${s.name} · ${s.duration_minutes} min · ${money(s.price)}` }))}
          />

          {serviceId && (
            <div>
              <span className={labelClass}>¿Quién lo atiende y a qué hora?</span>

              {loading ? (
                <p className="text-sm text-slate-500">Revisando quién está libre...</p>
              ) : professionals.length === 0 ? (
                <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                  Nadie de esta sede atiende este servicio. Revisa los horarios del equipo o los servicios de cada profesional.
                </p>
              ) : (
                <div className="space-y-3">
                  <p className="text-xs text-slate-500">
                    Son las {options?.now}. Elige &quot;Ahora&quot; si la persona está libre, o uno de sus próximos horarios libres de hoy.
                  </p>
                  <ul className="space-y-3">
                    {rows.map((pro) => (
                      <li key={pro.id} data-walk-in-pro={pro.display_name} className={`rounded-xl border px-4 py-3 space-y-2.5 ${choice?.professionalId === pro.id ? 'border-brand ring-2 ring-brand/10' : 'border-slate-200'}`}>
                        <div className="flex items-start justify-between gap-3">
                          <ProfessionalLink id={pro.id} className="text-sm font-bold text-brand-ink">{pro.display_name}</ProfessionalLink>
                          <Badge tone={pro.free_now ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'} bold className="shrink-0">
                            {pro.free_now ? 'Libre ahora' : 'Ahora no'}
                          </Badge>
                        </div>
                        {!pro.free_now && pro.now_reason && <p className="text-xs text-slate-500">{pro.now_reason}</p>}
                        <div className="flex flex-wrap gap-2">
                          {pro.free_now && (
                            <button type="button" data-at="now" onClick={() => setChoice({ professionalId: pro.id, at: 'now' })} className={chip(choice?.professionalId === pro.id && choice.at === 'now', true)}>
                              Ahora ({options?.now})
                            </button>
                          )}
                          {pro.slots.map((slot) => (
                            <button key={slot} type="button" data-at={slot} onClick={() => setChoice({ professionalId: pro.id, at: slot })} className={chip(choice?.professionalId === pro.id && choice.at === slot)}>
                              {slot}
                            </button>
                          ))}
                          {!pro.free_now && pro.slots.length === 0 && <span className="text-xs text-slate-400">No le quedan horarios libres hoy.</span>}
                        </div>
                      </li>
                    ))}
                  </ul>
                  {pages > 1 && <Pager page={page} pages={pages} onChange={setPage} />}
                </div>
              )}
            </div>
          )}

          {summary && (
            <p data-walk-in-summary className="flex items-center gap-2 text-sm text-brand-ink bg-brand-soft/15 border border-brand-soft/40 rounded-lg px-3 py-2">
              <Clock size={15} className="shrink-0" /> {summary}{service ? ` · ${service.duration_minutes} min` : ''}
            </p>
          )}

          <ErrorAlert message={error} />
        </div>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="button" data-walk-in-submit disabled={saving || !person || !service || !choice} onClick={submit} variant="primary">
            {saving ? 'Agendando...' : 'Agendar y pasar al carrito'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
