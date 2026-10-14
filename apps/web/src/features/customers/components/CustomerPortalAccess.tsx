'use client';

import { useState } from 'react';
import { KeyRound } from 'lucide-react';
import { api } from '@/lib/axios';
import Switch from '@/app/components/common/Switch';
import { AppModal } from '@/app/components/ui/modals/AppModal';

export type PortalAccess = 'no_email' | 'no_document' | 'pending' | 'active' | 'disabled';

interface CustomerPortalAccessProps {
  customer: { id: string; first_name: string; email?: string | null; portal_access?: PortalAccess };
  clientWord: string;
  onChanged: () => void;
}

const STATES: Record<PortalAccess, { label: string; badge: string }> = {
  active: { label: 'Activo', badge: 'bg-emerald-50 text-emerald-700' },
  pending: { label: 'Aún no ingresa', badge: 'bg-sky-50 text-sky-700' },
  disabled: { label: 'Apagado', badge: 'bg-slate-100 text-slate-500' },
  no_email: { label: 'Falta su correo', badge: 'bg-amber-50 text-amber-700' },
  no_document: { label: 'Falta su documento', badge: 'bg-amber-50 text-amber-700' },
};

export default function CustomerPortalAccess({ customer, clientWord, onChanged }: CustomerPortalAccessProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [confirmReset, setConfirmReset] = useState(false);

  const access: PortalAccess = customer.portal_access ?? 'no_email';
  const hasAccess = access === 'active' || access === 'pending' || access === 'disabled';
  const word = clientWord.toLowerCase();

  const run = async (request: () => Promise<unknown>, done: string) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      await request();
      setNotice(done);
      onChanged();
    } catch (err: any) {
      setError(err?.response?.data?.message || 'No se pudo completar.');
    } finally {
      setBusy(false);
      setConfirmReset(false);
    }
  };

  const toggle = (enabled: boolean) =>
    run(() => api.put(`/customers/${customer.id}/portal-access`, { enabled }), enabled ? 'Acceso encendido.' : 'Acceso apagado. Sus sesiones abiertas se cerraron.');

  const reset = () =>
    run(() => api.post(`/customers/${customer.id}/portal-access/reset`), 'Listo: su contraseña volvió a ser su número de documento.');

  const explanation: Record<PortalAccess, string> = {
    active: `Ingresa con ${customer.email} y la contraseña que eligió.`,
    pending: `Puede ingresar con ${customer.email}. Su contraseña es su número de documento; al entrar tendrá que crear la suya.`,
    disabled: `No puede ingresar. Enciéndelo para que vuelva a entrar con ${customer.email}.`,
    no_email: `Registra su correo para que pueda ingresar a su portal. Su contraseña inicial será su número de documento.`,
    no_document: `Registra su número de documento: será su contraseña la primera vez que ingrese.`,
  };

  return (
    <div className="pt-4 border-t border-slate-100 space-y-3" data-card="portal-access" data-access={access}>
      <div className="flex items-center justify-between gap-3">
        <span className="flex items-center gap-1.5 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
          <KeyRound size={13} /> Acceso al portal
        </span>
        <span className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${STATES[access].badge}`}>{STATES[access].label}</span>
      </div>

      <p className="text-sm text-slate-600 leading-relaxed">{explanation[access]}</p>

      {hasAccess && (
        <div className="flex items-center justify-between gap-3">
          <label className="flex items-center gap-3 cursor-pointer">
            <Switch checked={access !== 'disabled'} onChange={toggle} disabled={busy} label={`Acceso de ${customer.first_name} al portal`} />
            <span className="text-sm font-semibold text-slate-700">{access === 'disabled' ? 'Apagado' : 'Encendido'}</span>
          </label>

          {access === 'active' && (
            <button
              type="button"
              data-action="reset-portal"
              disabled={busy}
              onClick={() => setConfirmReset(true)}
              className="text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors disabled:opacity-50"
            >
              Olvidó su contraseña
            </button>
          )}
        </div>
      )}

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
      {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}

      <AppModal
        isOpen={confirmReset}
        title="Restablecer su contraseña"
        message={`La contraseña de ${customer.first_name} volverá a ser su número de documento y se cerrarán sus sesiones abiertas. Al ingresar, el ${word} tendrá que crear una nueva.`}
        confirmText="Sí, restablecer"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={reset}
        onClose={() => setConfirmReset(false)}
      />
    </div>
  );
}
