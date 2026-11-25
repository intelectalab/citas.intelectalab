'use client';

import { useEffect, useState } from 'react';
import { KeyRound } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';

interface Props {
  isOpen: boolean;
  reason: string;
  onClose: () => void;
  onApproved: (approval: { token: string; name: string; minutes: number }) => void;
}

export default function DiscountApprovalDrawer({ isOpen, reason, onClose, onApproved }: Props) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setEmail('');
    setPassword('');
    setError('');
  }, [isOpen]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const res = await api.post('/pos/discount-approval', { email: email.trim(), password });
      setPassword('');
      onApproved(res.data);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo autorizar el descuento.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Autorizar el descuento"
      subtitle="Quien tiene el permiso «Descuento alto» escribe aquí su correo y su contraseña."
      icon={<KeyRound size={20} />}
    >
      <form id="discount-approval-form" onSubmit={submit} className="space-y-4" autoComplete="off">
        {reason && <p className="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{reason}</p>}
        <ErrorAlert message={error} />

        <div>
          <label className={labelClass} htmlFor="approval-email">Correo de quien autoriza *</label>
          <input id="approval-email" type="email" required maxLength={190} autoComplete="off" className={inputClass} value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div>
          <label className={labelClass} htmlFor="approval-password">Su contraseña *</label>
          <input id="approval-password" type="password" required maxLength={200} autoComplete="new-password" className={inputClass} value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
        <p className="text-xs text-slate-500">La autorización sirve para esta venta durante unos minutos y queda en la auditoría con el nombre de quien autorizó. La contraseña no se guarda.</p>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button type="submit" variant="primary" disabled={saving || !email.trim() || !password}>{saving ? 'Revisando...' : 'Autorizar'}</Button>
        </div>
      </form>
    </FormDrawer>
  );
}
