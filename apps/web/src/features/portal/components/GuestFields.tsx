'use client';

import { useState } from 'react';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { labelClass } from '@/app/components/ui/forms/styles';

export interface GuestData {
  first_name: string;
  last_name: string;
  type_document_id: string;
  document_number: string;
  phone: string;
  email: string;
  dependent_name: string;
  dependent_species: string;
  accept_privacy: boolean;
  marketing: boolean;
  website: string;
}

export const emptyGuest: GuestData = {
  first_name: '',
  last_name: '',
  type_document_id: '',
  document_number: '',
  phone: '',
  email: '',
  dependent_name: '',
  dependent_species: '',
  accept_privacy: false,
  marketing: false,
  website: '',
};

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export const guestReady = (data: GuestData, dependentRequired: boolean) =>
  data.first_name.trim() !== '' &&
  data.last_name.trim() !== '' &&
  data.document_number.trim().length >= 6 &&
  data.phone.replace(/\D/g, '').length >= 6 &&
  EMAIL.test(data.email.trim()) &&
  (!dependentRequired || data.dependent_name.trim() !== '') &&
  data.accept_privacy;

const inputClass =
  'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand text-slate-800 bg-white';



interface GuestFieldsProps {
  value: GuestData;
  onChange: (value: GuestData) => void;
  documentTypes: { id: string; name: string; value: string | null }[];
  dependents: { enabled: boolean; required: boolean };
  dependentLabel: string;
  privacyHtml: string | null;
}

export default function GuestFields({ value, onChange, documentTypes, dependents, dependentLabel, privacyHtml }: GuestFieldsProps) {
  const [showPrivacy, setShowPrivacy] = useState(false);
  const set = (patch: Partial<GuestData>) => onChange({ ...value, ...patch });
  const term = dependentLabel.toLowerCase();

  return (
    <div className="space-y-3" data-guest-fields>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label className="block">
          <span className={labelClass}>Nombres</span>
          <input type="text" name="first_name" autoComplete="given-name" maxLength={100} className={inputClass} value={value.first_name} onChange={(e) => set({ first_name: e.target.value })} />
        </label>
        <label className="block">
          <span className={labelClass}>Apellidos</span>
          <input type="text" name="last_name" autoComplete="family-name" maxLength={100} className={inputClass} value={value.last_name} onChange={(e) => set({ last_name: e.target.value })} />
        </label>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-[minmax(0,10rem)_1fr] gap-3">
        {documentTypes.length > 0 && (
          <Select
            label="Documento"
            placeholder="Tipo"
            value={value.type_document_id}
            onChange={(e) => set({ type_document_id: String(e.target.value) })}
            options={documentTypes.map((type) => ({ value: type.id, label: type.value || type.name }))}
          />
        )}
        <label className="block">
          <span className={labelClass}>Número de documento</span>
          <input type="text" name="document_number" inputMode="text" maxLength={20} className={inputClass} value={value.document_number} onChange={(e) => set({ document_number: e.target.value.replace(/[^A-Za-z0-9-]/g, '') })} />
        </label>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label className="block">
          <span className={labelClass}>Celular</span>
          <input type="tel" name="phone" autoComplete="tel" maxLength={30} className={inputClass} value={value.phone} onChange={(e) => set({ phone: e.target.value.replace(/[^0-9+\s()-]/g, '') })} />
        </label>
        <label className="block">
          <span className={labelClass}>Correo</span>
          <input type="email" name="email" autoComplete="email" maxLength={190} className={inputClass} value={value.email} onChange={(e) => set({ email: e.target.value })} />
        </label>
      </div>

      {dependents.enabled && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3" data-guest-dependent>
          <label className="block">
            <span className={labelClass}>Nombre de tu {term}{dependents.required ? '' : ' (opcional)'}</span>
            <input type="text" name="dependent_name" maxLength={150} className={inputClass} value={value.dependent_name} onChange={(e) => set({ dependent_name: e.target.value })} />
          </label>
          <label className="block">
            <span className={labelClass}>Especie (opcional)</span>
            <input type="text" name="dependent_species" maxLength={80} placeholder="Ej. Perro" className={inputClass} value={value.dependent_species} onChange={(e) => set({ dependent_species: e.target.value })} />
          </label>
        </div>
      )}

      <input
        type="text"
        name="website"
        tabIndex={-1}
        autoComplete="off"
        aria-hidden="true"
        className="absolute -left-[9999px] w-px h-px opacity-0"
        value={value.website}
        onChange={(e) => set({ website: e.target.value })}
      />

      <p className="text-xs text-slate-500">Si ya te atendiste antes, usa el mismo documento o celular: tu cita se suma a tu historial.</p>

      <div className="flex items-start gap-2.5 text-sm text-slate-700" data-field="accept_privacy">
        <Switch checked={value.accept_privacy} onChange={(checked) => set({ accept_privacy: checked })} label="Acepto la política de privacidad" />
        <span>
          Acepto la{' '}
          {privacyHtml ? (
            <button type="button" onClick={() => setShowPrivacy(!showPrivacy)} className="font-bold text-brand-accent hover:text-brand-ink underline-offset-2 hover:underline">
              política de privacidad
            </button>
          ) : 'política de privacidad'}{' '}
          y el uso de mis datos para gestionar mi cita.
        </span>
      </div>

      {showPrivacy && privacyHtml && (
        <div className="max-h-56 overflow-y-auto text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 rich-text" data-privacy-text dangerouslySetInnerHTML={{ __html: privacyHtml }} />
      )}

      <div className="flex items-start gap-2.5 text-sm text-slate-700" data-field="marketing">
        <Switch checked={value.marketing} onChange={(checked) => set({ marketing: checked })} label="Recibir promociones por correo" />
        <span>Quiero recibir promociones por correo (opcional).</span>
      </div>
    </div>
  );
}
