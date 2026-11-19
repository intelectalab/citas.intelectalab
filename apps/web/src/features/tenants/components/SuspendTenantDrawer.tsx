'use client';

import { useEffect, useState } from 'react';
import { Ban, Loader2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onDone: () => void;
  tenant: { id: string; name: string };
}

export default function SuspendTenantDrawer({ isOpen, onClose, onDone, tenant }: Props) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setReason('');
      setError('');
      setBusy(false);
    }
  }, [isOpen]);

  const suspend = async () => {
    try {
      setBusy(true);
      setError('');
      await api.post(`/tenants/${tenant.id}/suspend`, { reason: reason.trim() });
      onDone();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo suspender la empresa.'));
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title={`Suspender ${tenant.name}`} subtitle="Nadie de la empresa podrá entrar" icon={<Ban size={18} />}>
      <div className="space-y-4" data-suspend-form>
        <p className="text-sm text-slate-600">
          Al suspenderla se cierran las sesiones abiertas de su equipo y de sus clientes. Sus datos se conservan y puedes reactivarla desde su página.
        </p>
        <div>
          <label className={labelClass} htmlFor="suspend-reason">Motivo de la suspensión</label>
          <textarea id="suspend-reason" rows={4} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} placeholder="Ej. Lleva dos meses sin pagar su plan" />
          <p className="text-xs text-slate-500 mt-1">Sin motivo no se puede suspender.</p>
        </div>
        <ErrorAlert message={error} />
        <button type="button" data-suspend-go disabled={busy || reason.trim().length < 5} onClick={suspend} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-red-600 text-white rounded-xl hover:bg-red-700 disabled:opacity-50 cursor-pointer">
          {busy ? <Loader2 size={16} className="animate-spin" /> : <Ban size={16} />} Suspender empresa
        </button>
      </div>
    </FormDrawer>
  );
}
