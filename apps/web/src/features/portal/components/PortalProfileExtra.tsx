'use client';

import { useState } from 'react';
import { Cake, MapPin } from 'lucide-react';
import { api } from '@/lib/axios';
import DateField from '@/app/components/common/DateField';
import { formatDate, todayISO, addDays } from '@/features/appointments/appointments';
import { portalError } from '../portal';

interface Props {
  birthDate: string | null;
  district: string | null;
  onSaved: (values: { birth_date: string | null; district: string | null }) => void;
  onError: (message: string) => void;
}

export default function PortalProfileExtra({ birthDate, district, onSaved, onError }: Props) {
  const [editing, setEditing] = useState(false);
  const [birth, setBirth] = useState(birthDate ?? '');
  const [place, setPlace] = useState(district ?? '');
  const [saving, setSaving] = useState(false);

  const save = async () => {
    try {
      setSaving(true);
      const res = await api.put('/portal/profile', { birth_date: birth || null, district: place.trim() || null });
      onSaved({ birth_date: res.data.birth_date ?? null, district: res.data.district ?? null });
      setEditing(false);
    } catch (err) {
      onError(portalError(err, 'No se pudieron guardar tus datos.'));
    } finally {
      setSaving(false);
    }
  };

  if (!editing) {
    return (
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm pt-3 border-t border-slate-100" data-profile-extra>
        <div>
          <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Fecha de nacimiento</dt>
          <dd className="flex items-center gap-1.5 font-semibold text-slate-800"><Cake size={14} className="text-slate-400" /> {birthDate ? formatDate(birthDate, { day: 'numeric', month: 'long', year: 'numeric' }) : 'Sin registrar'}</dd>
        </div>
        <div>
          <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Distrito</dt>
          <dd className="flex items-center gap-1.5 font-semibold text-slate-800"><MapPin size={14} className="text-slate-400" /> {district || 'Sin registrar'}</dd>
        </div>
        <div className="sm:col-span-2">
          <button type="button" data-action="edit-profile-extra" onClick={() => setEditing(true)} className="text-xs font-bold text-brand-accent hover:text-brand-ink">
            Cambiar fecha de nacimiento y distrito
          </button>
          <p className="text-xs text-slate-500 mt-0.5">Con tu fecha de nacimiento podemos saludarte en tu cumpleaños.</p>
        </div>
      </dl>
    );
  }

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-3 border-t border-slate-100" data-profile-extra-form>
      <label className="block space-y-1">
        <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Fecha de nacimiento</span>
        <DateField value={birth} onChange={setBirth} ariaLabel="Fecha de nacimiento" max={addDays(todayISO(), -1)} min="1900-01-01" clearable />
      </label>
      <label className="block space-y-1">
        <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Distrito</span>
        <input
          type="text"
          maxLength={100}
          value={place}
          onChange={(e) => setPlace(e.target.value)}
          placeholder="Ej. Miraflores"
          className="w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand"
        />
      </label>
      <div className="sm:col-span-2 flex gap-2">
        <button type="button" data-action="save-profile-extra" disabled={saving} onClick={save} className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
          {saving ? 'Guardando...' : 'Guardar'}
        </button>
        <button type="button" onClick={() => { setEditing(false); setBirth(birthDate ?? ''); setPlace(district ?? ''); }} className="px-4 py-2 text-sm font-semibold border border-slate-200 bg-white text-slate-600 rounded-xl hover:bg-slate-50">
          Cancelar
        </button>
      </div>
    </div>
  );
}
