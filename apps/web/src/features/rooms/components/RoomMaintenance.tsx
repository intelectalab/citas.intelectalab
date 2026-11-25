'use client';

import { useEffect, useState } from 'react';
import { Wrench } from 'lucide-react';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';

interface Props {
  room: { id: string; name: string; maintenance_reason?: string | null } | null;
  onClose: () => void;
  onDone: (message: string) => void;
}

export default function RoomMaintenance({ room, onClose, onDone }: Props) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const ending = Boolean(room?.maintenance_reason);

  useEffect(() => {
    setReason('');
    setError('');
  }, [room?.id]);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!room) return;
    try {
      setBusy(true);
      setError('');
      await api.post(`/rooms/${room.id}/maintenance`, ending ? { action: 'end' } : { action: 'start', reason: reason.trim() });
      onDone(ending ? `${room.name} volvió a estar en uso.` : `${room.name} quedó en mantenimiento: no se ofrece al agendar hasta que la vuelvas a poner en uso.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormDrawer
      isOpen={room !== null}
      onClose={onClose}
      title={ending ? `Terminar mantenimiento de ${room?.name ?? ''}` : `Mantenimiento de ${room?.name ?? ''}`}
      subtitle={ending ? `En mantenimiento: ${room?.maintenance_reason ?? ''}` : 'Mientras dure, no se puede agendar en esta sala o equipo.'}
      icon={<Wrench size={20} />}
    >
      <form onSubmit={send} className="space-y-5" data-room-maintenance>
        {ending ? (
          <p className="text-sm text-slate-600">Al terminar, vuelve a ofrecerse al agendar desde ahora.</p>
        ) : (
          <div>
            <label htmlFor="maintenance-reason" className={labelClass}>¿Qué se hace? *</label>
            <input id="maintenance-reason" required maxLength={255} placeholder="Ej. Cambio de camilla, revisión del láser" className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
            <p className="text-xs text-slate-500 mt-1.5">Las citas que ya tiene se mantienen: revísalas en la agenda.</p>
          </div>
        )}
        <ErrorAlert message={error} />
        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">Cancelar</Button>
          <Button type="submit" disabled={busy || (!ending && !reason.trim())} variant="primary">
            {busy ? 'Guardando...' : ending ? 'Volver a poner en uso' : 'Poner en mantenimiento'}
          </Button>
        </div>
      </form>
    </FormDrawer>
  );
}
