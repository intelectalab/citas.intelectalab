'use client';

import { useEffect, useState } from 'react';
import { Play } from 'lucide-react';
import Button from '@/app/components/common/Button';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import type { WalkinCandidate, WalkinEntry } from '../walkin';

interface ServeWalkinProps {
  entry: WalkinEntry | null;
  onClose: () => void;
  onServed: (result: { message: string; pending: string | null; appointmentId: string }) => void;
}

export default function ServeWalkin({ entry, onClose, onServed }: ServeWalkinProps) {
  const vocab = useVocabulary();
  const [candidates, setCandidates] = useState<WalkinCandidate[]>([]);
  const [canOverride, setCanOverride] = useState(false);
  const [selected, setSelected] = useState('');
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!entry) return;
    setError('');
    setReason('');
    setCandidates([]);
    setLoading(true);
    api.get(`/walkins/${entry.id}/options`)
      .then((res) => {
        const list: WalkinCandidate[] = res.data.candidates ?? [];
        setCandidates(list);
        setCanOverride(Boolean(res.data.can_override));
        const free = (id?: string | null) => list.find((c) => c.id === id && c.available)?.id;
        setSelected(free(entry.professional_id) ?? free(res.data.suggested_professional?.id) ?? list.find((c) => c.available)?.id ?? entry.professional_id ?? '');
      })
      .catch((err) => setError(apiError(err, 'No se pudo revisar quién está libre.')))
      .finally(() => setLoading(false));
  }, [entry]);

  const current = candidates.find((c) => c.id === selected);
  const forcing = Boolean(current && !current.available);
  const blocked = !current || (forcing && (!canOverride || !current.overridable));

  const serve = async () => {
    if (!entry || !current) return;
    try {
      setSaving(true);
      setError('');
      const res = await api.post(`/walkins/${entry.id}/serve`, { professional_id: current.id, override_reason: forcing ? reason.trim() || null : null });
      onServed({ message: res.data.message, pending: res.data.pending ?? null, appointmentId: res.data.appointment.id });
    } catch (err) {
      setError(apiError(err, 'No se pudo pasar a atención.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={Boolean(entry)}
      onClose={onClose}
      title={entry ? `Atender ${entry.ticket}: ${entry.customer?.full_name ?? ''}` : ''}
      subtitle={entry ? `${entry.service?.name ?? ''} · ${entry.service?.duration_minutes ?? 0} min · lleva ${entry.elapsed_minutes ?? 0} min esperando` : ''}
      icon={<Play size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <div className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          {entry?.notes && <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">{entry.notes}</p>}

          <div>
            <span className={labelClass}>¿Quién {vocab.pick('client', 'lo', 'la')} atiende ahora?</span>
            {loading ? (
              <p className="text-sm text-slate-500">Revisando quién está libre...</p>
            ) : candidates.length === 0 ? (
              <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">Hoy no trabaja en esta sede nadie que haga este servicio.</p>
            ) : (
              <div className="space-y-2" data-candidates>
                {candidates.map((c) => (
                  <OptionCard
                    key={c.id}
                    type="button"
                    selected={selected === c.id}
                    data-candidate={c.id}
                    onClick={() => { setSelected(c.id); setError(''); }}
                    className="w-full flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-left transition-colors"
                  >
                    <span>
                      <span className="block text-sm font-semibold text-slate-800">
                        {c.display_name}
                        {entry?.professional_id === c.id && <span className="ml-2 text-xs font-medium text-violet-600">{vocab.pick('client', 'Lo', 'La')} pidió</span>}
                      </span>
                      <span className={`block text-xs ${c.available ? 'text-emerald-700' : 'text-slate-500'}`}>{c.available ? 'Libre ahora' : c.reason}</span>
                    </span>
                  </OptionCard>
                ))}
              </div>
            )}
          </div>

          {forcing && current && (
            canOverride && current.overridable ? (
              <div>
                <label className={labelClass} htmlFor="walkin-override">Motivo para atender fuera de su horario *</label>
                <input id="walkin-override" className={inputClass} value={reason} maxLength={255} placeholder="Por ejemplo: se quedó media hora más hoy" onChange={(e) => setReason(e.target.value)} />
              </div>
            ) : (
              <p className="text-xs font-semibold text-amber-700" data-serve-blocked>
                {current.display_name} no está libre ahora. Elige a otra persona o espera a que se desocupe.
              </p>
            )
          )}

          <p className="text-xs text-slate-500">Se crea su cita desde este momento y empieza la atención. Si tu empresa cobra antes de atender, la cita se abre para cobrarla primero.</p>

          <ErrorAlert message={error} />
        </div>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
          <Button type="button" variant="classic" data-serve-confirm disabled={saving || loading || blocked || (forcing && !reason.trim())} onClick={serve}>
            {saving ? 'Pasando...' : 'Empezar la atención'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
