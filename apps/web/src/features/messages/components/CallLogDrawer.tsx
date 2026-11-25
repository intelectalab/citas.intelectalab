'use client';

import { useEffect, useState } from 'react';
import { PhoneCall } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { formatPhone } from '@/lib/phone';
import type { Message } from '../messages';

const OUTCOMES: { id: string; label: string }[] = [
  { id: 'answered', label: 'Contestó' },
  { id: 'no_answer', label: 'No contestó' },
  { id: 'voicemail', label: 'Dejé mensaje de voz' },
  { id: 'callback', label: 'Pidió que le devuelvan la llamada' },
];

interface CallLogDrawerProps {
  isOpen: boolean;
  customer: { id: string; first_name: string; phone?: string | null };
  onClose: () => void;
  onSaved: (message: Message) => void;
}

export default function CallLogDrawer({ isOpen, customer, onClose, onSaved }: CallLogDrawerProps) {
  const [outcome, setOutcome] = useState('answered');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setOutcome('answered');
    setNote('');
    setError('');
  }, [isOpen]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const res = await api.post(`/customers/${customer.id}/calls`, { outcome, note: note.trim() });
      onSaved(res.data);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo registrar la llamada.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title="Registrar una llamada" subtitle={`Con ${customer.first_name}${customer.phone ? ` · ${formatPhone(customer.phone)}` : ''}`} icon={<PhoneCall size={20} />}>
      <form onSubmit={submit} className="space-y-4" data-call-log>
        <ErrorAlert message={error} />
        <div>
          <span className={labelClass}>¿Cómo terminó? *</span>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Resultado de la llamada">
            {OUTCOMES.map((option) => (
              <OptionCard key={option.id} type="button" role="radio" aria-checked={outcome === option.id} data-outcome={option.id} selected={outcome === option.id} onClick={() => setOutcome(option.id)} className="rounded-xl border px-3.5 py-2.5 text-left text-sm font-bold text-brand-ink">
                {option.label}
              </OptionCard>
            ))}
          </div>
        </div>
        <div>
          <label className={labelClass} htmlFor="call-note">¿De qué hablaron? *</label>
          <textarea id="call-note" rows={4} maxLength={2000} required value={note} onChange={(e) => setNote(e.target.value)} placeholder="Ej. Confirmó su cita del lunes y preguntó por el precio del paquete." className={inputClass} />
        </div>
        <p className="text-xs text-slate-500">Queda en sus mensajes y en su línea de tiempo, con tu nombre y la hora.</p>
        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button type="submit" variant="primary" disabled={saving || !note.trim()}>{saving ? 'Guardando...' : 'Registrar llamada'}</Button>
        </div>
      </form>
    </FormDrawer>
  );
}
