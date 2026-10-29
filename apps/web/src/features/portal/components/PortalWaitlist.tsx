'use client';

import { useState } from 'react';
import { BellRing, Hourglass, Loader2, MapPin, UserRound, X } from 'lucide-react';
import { api } from '@/lib/axios';
import { formatDate } from '@/features/appointments/appointments';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { portalError, type PortalWaitlistEntry } from '../portal';

interface PortalWaitlistProps {
  entries: PortalWaitlistEntry[];
  proWord: string;
  onChanged: (notice: string) => void;
}

const timeLabel = (entry: PortalWaitlistEntry) =>
  entry.time_from && entry.time_to ? `entre las ${entry.time_from} y las ${entry.time_to}`
    : entry.time_to ? `hasta las ${entry.time_to}`
    : entry.time_from ? `desde las ${entry.time_from}`
    : 'a cualquier hora';

export default function PortalWaitlist({ entries, proWord, onChanged }: PortalWaitlistProps) {
  const [picked, setPicked] = useState<{ entry: PortalWaitlistEntry; date: string; time: string; professionalId: string; professionalName: string } | null>(null);
  const [leaving, setLeaving] = useState<PortalWaitlistEntry | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (entries.length === 0) return null;

  const book = async () => {
    if (!picked) return;

    try {
      setBusy(true);
      setError('');
      await api.post('/portal/appointments', {
        service_id: picked.entry.service?.id,
        branch_id: picked.entry.branch?.id,
        professional_id: picked.professionalId,
        customer_dependent_id: picked.entry.dependent?.id ?? null,
        starts_at: `${picked.date}T${picked.time}`,
        waitlist_id: picked.entry.id,
      });
      setPicked(null);
      onChanged('Listo: tu cita quedó reservada y saliste de la lista de espera.');
    } catch (err) {
      setPicked(null);
      setError(portalError(err, 'Ese horario ya no está libre. Elige otro.'));
      onChanged('');
    } finally {
      setBusy(false);
    }
  };

  const leave = async () => {
    if (!leaving) return;

    try {
      setBusy(true);
      setError('');
      await api.delete(`/portal/waitlist/${leaving.id}`);
      setLeaving(null);
      onChanged('Saliste de la lista de espera.');
    } catch (err) {
      setLeaving(null);
      setError(portalError(err, 'No se pudo completar. Inténtalo otra vez.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="space-y-3" data-list="waitlist">
      <h2 className="text-sm font-extrabold text-brand-ink uppercase tracking-wider">En lista de espera ({entries.length})</h2>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}

      {entries.map((entry) => {
        const hasSlots = entry.days.length > 0;

        return (
          <article
            key={entry.id}
            data-waiting={entry.id}
            data-has-slots={hasSlots}
            className={`bg-white rounded-3xl border shadow-xs overflow-hidden ${hasSlots ? 'border-emerald-300' : 'border-slate-200/80'}`}
          >
            <div className="p-4 sm:p-5 space-y-3">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-3 min-w-0">
                  <span className={`p-2.5 rounded-xl shrink-0 ${hasSlots ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'}`}>
                    {hasSlots ? <BellRing size={18} /> : <Hourglass size={18} />}
                  </span>
                  <div className="min-w-0">
                    <h3 className="text-base font-extrabold text-brand-ink leading-tight">{entry.service?.name}</h3>
                    <ul className="mt-1 space-y-0.5 text-sm text-slate-600">
                      <li className="flex items-center gap-2"><UserRound size={14} className="text-slate-400 shrink-0" /> {entry.professional ? entry.professional.display_name : `Con cualquier ${proWord.toLowerCase()}`}</li>
                      {entry.branch && <li className="flex items-center gap-2"><MapPin size={14} className="text-slate-400 shrink-0" /> {entry.branch.name}</li>}
                    </ul>
                    <p className="mt-1 text-xs text-slate-500">
                      Te sirve {timeLabel(entry)}
                      {entry.date_to ? `, hasta el ${formatDate(entry.date_to, { day: 'numeric', month: 'long' })}` : ', sin fecha límite'}
                      {entry.dependent ? ` · para ${entry.dependent.name}` : ''}.
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  data-action="leave-waitlist"
                  onClick={() => setLeaving(entry)}
                  className="inline-flex items-center gap-1 text-xs font-bold text-slate-500 hover:text-rose-600 transition-colors shrink-0"
                >
                  <X size={13} /> Salir de la lista
                </button>
              </div>

              {hasSlots ? (
                <div className="space-y-3 pt-3 border-t border-slate-100" data-waiting-slots>
                  <p className="text-sm font-bold text-emerald-700">Hay horarios libres que te sirven. Elige uno para reservarlo:</p>
                  {entry.days.map((day) => (
                    <div key={day.date} className="space-y-1.5">
                      <span className="block text-sm font-semibold text-slate-700 first-letter:uppercase">{formatDate(day.date, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
                      {day.groups.map((group) => (
                        <div key={group.professional.id} className="space-y-1">
                          {!entry.professional && <span className="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">{group.professional.display_name}</span>}
                          <div className="flex flex-wrap gap-1.5">
                            {group.slots.slice(0, 12).map((slot) => (
                              <button
                                key={slot}
                                type="button"
                                data-slot={`${day.date}T${slot}`}
                                onClick={() => setPicked({ entry, date: day.date, time: slot, professionalId: group.professional.id, professionalName: group.professional.display_name })}
                                className="px-3 py-1.5 text-sm font-semibold rounded-xl border border-slate-200 text-slate-700 hover:border-brand hover:bg-brand hover:text-white transition-colors"
                              >
                                {slot}
                              </button>
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  ))}
                </div>
              ) : (
                <p className="pt-3 border-t border-slate-100 text-sm text-slate-500">
                  Todavía no hay un horario libre que te sirva. Cuando se libere uno, lo verás aquí para reservarlo.
                </p>
              )}
            </div>
          </article>
        );
      })}

      <AppModal
        isOpen={picked !== null}
        type="confirm"
        title="¿Reservar este horario?"
        message={picked ? `${picked.entry.service?.name} · ${formatDate(picked.date, { weekday: 'long', day: 'numeric', month: 'long' })} a las ${picked.time} · ${picked.professionalName}.` : ''}
        confirmText={busy ? 'Reservando…' : 'Sí, reservar'}
        cancelText="Elegir otro"
        isLoading={busy}
        onConfirm={book}
        onClose={() => setPicked(null)}
      />

      <AppModal
        isOpen={leaving !== null}
        type="confirm"
        variant="danger"
        title="¿Salir de la lista de espera?"
        message={leaving ? `Dejarás de esperar ${leaving.service?.name}. Podrás anotarte otra vez cuando quieras.` : ''}
        confirmText="Sí, salir"
        cancelText="Seguir esperando"
        isLoading={busy}
        onConfirm={leave}
        onClose={() => setLeaving(null)}
      />

      {busy && <span className="sr-only"><Loader2 /> Guardando</span>}
    </section>
  );
}
