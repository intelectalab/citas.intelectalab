'use client';

import { useEffect, useState } from 'react';
import { Loader2, LogIn } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  tenant: { id: string; name: string };
  ticket?: { id: string; code: string; subject: string } | null;
}

export default function ImpersonateDrawer({ isOpen, onClose, tenant, ticket = null }: Props) {
  const { startImpersonation } = useAuth();
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setReason(ticket ? `Atender el pedido ${ticket.code}: ${ticket.subject}` : '');
      setError('');
    }
  }, [isOpen, ticket]);

  const enter = async () => {
    try {
      setBusy(true);
      setError('');
      await startImpersonation(tenant.id, { reason: reason.trim(), ticket_id: ticket?.id ?? null });
    } catch (err) {
      setError(apiError(err, 'No se pudo entrar al panel de la empresa.'));
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title={`Entrar como ${tenant.name}`} subtitle="Abre su panel como su administrador" icon={<LogIn size={18} />}>
      <div className="space-y-4" data-impersonate-form>
        <p className="text-sm text-slate-600">
          Escribe para qué entras. El motivo queda en la auditoría de la plataforma y en la de la empresa, y quienes administran la empresa reciben un aviso mientras dura la sesión.
        </p>
        <div>
          <label className={labelClass} htmlFor="impersonate-reason">Motivo</label>
          <textarea id="impersonate-reason" rows={4} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} placeholder="Ej. Revisar por qué no se registra el pago de una cita" />
        </div>
        <ErrorAlert message={error} />
        <button type="button" data-impersonate-go disabled={busy || reason.trim().length < 5} onClick={enter} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-amber-400 text-brand-ink rounded-xl hover:bg-amber-300 disabled:opacity-50 cursor-pointer">
          {busy ? <Loader2 size={16} className="animate-spin" /> : <LogIn size={16} />} Entrar al panel
        </button>
      </div>
    </FormDrawer>
  );
}
