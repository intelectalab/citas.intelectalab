'use client';

import { useState } from 'react';
import { KeyRound } from 'lucide-react';
import { api } from '@/lib/axios';
import { portalError } from '../portal';

interface PortalPasswordProps {
  forced?: boolean;
  onDone: () => void;
  onCancel?: () => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5';

export default function PortalPassword({ forced = false, onDone, onCancel }: PortalPasswordProps) {
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (password !== confirmation) {
      setError('Las dos contraseñas no coinciden.');
      return;
    }

    try {
      setSaving(true);
      setError('');
      await api.put('/portal/password', { current_password: current, password, password_confirmation: confirmation });
      onDone();
    } catch (err) {
      setError(portalError(err, 'No se pudo guardar tu contraseña.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} data-form="password" className={forced ? 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-5' : 'space-y-4 pt-1'}>
      {forced && (
        <div className="flex items-start gap-4">
          <span className="p-2.5 bg-[#84D0D9]/20 text-[#022340] rounded-xl shrink-0"><KeyRound className="w-5 h-5" /></span>
          <div className="space-y-1">
            <h1 className="text-xl font-black text-[#022340] tracking-tight">Crea tu contraseña</h1>
            <p className="text-sm text-slate-500 font-medium leading-relaxed">
              Entraste con tu número de documento. Antes de continuar, elige una contraseña que solo tú conozcas.
            </p>
          </div>
        </div>
      )}

      <div>
        <label className={labelClass} htmlFor="portal-current-password">{forced ? 'Tu número de documento' : 'Contraseña actual'}</label>
        <input id="portal-current-password" type="password" required autoComplete="current-password" className={inputClass} value={current} onChange={(e) => setCurrent(e.target.value)} />
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className={labelClass} htmlFor="portal-new-password">Nueva contraseña</label>
          <input id="portal-new-password" type="password" required minLength={8} autoComplete="new-password" className={inputClass} value={password} onChange={(e) => setPassword(e.target.value)} />
          <p className="text-xs text-slate-500 mt-1">Al menos 8 caracteres.</p>
        </div>
        <div>
          <label className={labelClass} htmlFor="portal-confirm-password">Repítela</label>
          <input id="portal-confirm-password" type="password" required minLength={8} autoComplete="new-password" className={inputClass} value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
        </div>
      </div>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}

      <div className="flex items-center justify-end gap-3">
        {onCancel && (
          <button type="button" onClick={onCancel} className="px-4 py-2.5 text-sm font-bold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
            Cancelar
          </button>
        )}
        <button type="submit" disabled={saving} className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
          {saving ? 'Guardando...' : forced ? 'Guardar y continuar' : 'Guardar contraseña'}
        </button>
      </div>
    </form>
  );
}
