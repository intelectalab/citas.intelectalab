'use client';

import { useCallback, useEffect, useState } from 'react';
import { Building2, CalendarDays, CalendarPlus, Home, MapPin, MessageSquareText, Phone, RotateCcw, UserRound } from 'lucide-react';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { TEXT_FIELDS, type BrandPayload, type PortalTexts } from '../portalLook';

type Draft = PortalTexts & { show_contact: boolean };

const draftOf = (payload: BrandPayload): Draft => ({ ...payload.portal.texts, show_contact: payload.portal.show_contact });

export default function PortalTextsEditor({ onOpenPrivacy }: { onOpenPrivacy: () => void }) {
  const [data, setData] = useState<BrandPayload | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [booking, setBooking] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = useCallback((payload: BrandPayload) => {
    setData(payload);
    setDraft(draftOf(payload));
  }, []);

  useEffect(() => {
    api.get<BrandPayload>('/branding')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los textos del portal.')));
  }, [apply]);

  if (!data || !draft) {
    return (
      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-3">
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando los textos del portal…</p>}
      </section>
    );
  }

  const canEdit = data.can_edit;
  const saved = draftOf(data);
  const dirty = (Object.keys(saved) as (keyof Draft)[]).some((key) => saved[key] !== draft[key]);
  const defaults = data.portal.defaults;
  const isDefault = TEXT_FIELDS.every((field) => draft[field.key] === defaults[field.key]) && draft.show_contact;
  const set = (key: keyof Draft, value: string | boolean) => setDraft((current) => (current ? { ...current, [key]: value } : current));

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      apply((await api.put<BrandPayload>('/branding/portal', draft)).data);
      setNotice('Listo: tus clientes ya ven estos textos en su portal.');
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar los textos.'));
    } finally {
      setBusy(false);
    }
  };

  const company = data.portal.privacy.company;
  const branchHint = 'la dirección y el teléfono de cada sucursal';
  const logo = data.branding.logo_url;

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="portal-texts">
      <div>
        <SectionTitle><MessageSquareText className="w-4 h-4 text-brand-accent" /> Textos del portal</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Lo que leen tus clientes al entrar a su portal. Tu logo y tus colores se cambian en &quot;Marca y colores&quot;.</p>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] gap-6 items-start">
        <div className="space-y-4">
          {TEXT_FIELDS.map((field) => {
            const value = draft[field.key];
            const changed = value !== defaults[field.key];
            return (
              <div key={field.key} data-portal-text={field.key}>
                <div className="flex items-center justify-between gap-2">
                  <label className={labelClass} htmlFor={`portal-${field.key}`}>{field.label}</label>
                  {canEdit && changed && field.key !== 'welcome' && (
                    <button type="button" data-text-default={field.key} onClick={() => set(field.key, defaults[field.key])} className="text-[11px] font-semibold text-slate-500 hover:text-brand-ink mb-1.5">Volver al original</button>
                  )}
                </div>
                {field.long ? (
                  <textarea id={`portal-${field.key}`} rows={2} maxLength={field.max} disabled={!canEdit} className={`${inputClass} resize-none`} value={value} placeholder={field.key === 'welcome' ? 'Sin mensaje' : defaults[field.key]} onChange={(e) => set(field.key, e.target.value)} />
                ) : (
                  <input id={`portal-${field.key}`} maxLength={field.max} disabled={!canEdit} className={inputClass} value={value} placeholder={defaults[field.key]} onChange={(e) => set(field.key, e.target.value)} />
                )}
                <span className="flex justify-between gap-2 text-xs text-slate-500 mt-1">
                  <span>{field.hint}</span>
                  <span className="shrink-0 text-slate-400">{value.length}/{field.max}</span>
                </span>
              </div>
            );
          })}

          <div className="flex items-start justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3" data-portal-contact-toggle>
            <span>
              <span className="block text-sm font-semibold text-slate-800">Mostrar cómo contactarte</span>
              <span className="block text-xs text-slate-500">En Inicio salen el teléfono de tu empresa y {branchHint}. Se toman de Empresa y Sucursales, así no los escribes dos veces.</span>
            </span>
            <Switch checked={draft.show_contact} disabled={!canEdit} label="Mostrar cómo contactarte" onChange={(on) => set('show_contact', on)} />
          </div>

          <p className="text-xs text-slate-500">
            La política de privacidad se edita aparte.{' '}
            <button type="button" onClick={onOpenPrivacy} className="font-bold text-brand-accent hover:text-brand-ink">Ir a Política de privacidad</button>
          </p>

          {canEdit && (
            <div className="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100">
              <button
                type="button"
                data-reset-portal
                disabled={isDefault}
                onClick={() => setDraft({ ...defaults, show_contact: true })}
                className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-brand-ink disabled:opacity-40"
              >
                <RotateCcw size={13} /> Restablecer los textos originales
              </button>
              <div className="flex gap-2">
                {dirty && <Button type="button" variant="secondary" onClick={() => setDraft(saved)}>Deshacer cambios</Button>}
                <Button type="button" variant="primary" data-save-portal disabled={busy || !dirty} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
              </div>
            </div>
          )}
        </div>

        <div className="xl:sticky xl:top-4 space-y-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-bold text-slate-600">Vista previa {dirty ? '(sin guardar)' : ''}</span>
            <div className="inline-flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label="Caso de la vista previa">
              {([[true, 'Con reserva en línea'], [false, 'Sin reserva en línea']] as const).map(([value, label]) => (
                <button key={label} type="button" role="radio" aria-checked={booking === value} data-preview-booking={String(value)} onClick={() => setBooking(value)} className={`px-2.5 py-1 rounded-md text-xs font-semibold ${booking === value ? 'bg-brand text-white' : 'text-slate-600'}`}>
                  {label}
                </button>
              ))}
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-slate-100/70 overflow-hidden text-[#1F2937]" data-portal-preview>
            <div className="bg-white border-b border-slate-200 px-4 py-3 flex items-center gap-2.5">
              {logo ? (
                <img src={logo} alt="" className="h-8 max-w-24 object-contain shrink-0" />
              ) : (
                <span className="min-w-8 h-8 px-1 rounded-xl flex items-center justify-center text-white text-[10px] font-black bg-brand shrink-0">
                  {data.branding.short_name ?? <Building2 className="w-4 h-4" />}
                </span>
              )}
              <span className="min-w-0">
                <span className="block text-sm font-black text-brand-ink truncate">{data.tenant.name}</span>
                <span className="block text-[10px] text-slate-500 truncate" data-preview-subtitle>{draft.subtitle || defaults.subtitle}</span>
              </span>
            </div>
            <div className="bg-white px-3 flex gap-3 text-[11px] font-bold text-slate-500 border-b border-slate-200">
              <span className="flex items-center gap-1 py-1.5 border-b-2 border-brand text-brand-ink"><Home size={11} /> Inicio</span>
              {booking && <span className="flex items-center gap-1 py-1.5"><CalendarPlus size={11} /> Reservar</span>}
              <span className="flex items-center gap-1 py-1.5"><CalendarDays size={11} /> Mis citas</span>
              <span className="flex items-center gap-1 py-1.5"><UserRound size={11} /> Mis datos</span>
            </div>
            <div className="p-4 space-y-3">
              <div>
                <p className="text-lg font-black text-brand-ink">Hola, Ana</p>
                <p className="text-xs text-slate-500">No tienes citas próximas.</p>
              </div>
              {draft.welcome.trim() && (
                <p className="text-xs text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-xl px-3 py-2" data-preview-welcome>{draft.welcome}</p>
              )}
              <div className="bg-white rounded-2xl border border-slate-200/80 p-3 flex items-start gap-2.5">
                <span className="p-1.5 bg-brand-soft/20 text-brand-ink rounded-lg shrink-0"><CalendarDays className="w-4 h-4" /></span>
                <span>
                  <span className="block text-xs font-bold text-brand-ink">{booking ? (draft.book_title || defaults.book_title) : 'Reserva de citas'}</span>
                  <span className="block text-[11px] text-slate-500" data-preview-book-text>{booking ? (draft.book_text || defaults.book_text) : (draft.no_booking_text || defaults.no_booking_text)}</span>
                </span>
              </div>
              {booking && <span className="block text-center text-xs font-bold bg-brand text-white rounded-xl py-2">Reservar una cita</span>}
              {draft.show_contact && (
                <div className="bg-white rounded-2xl border border-slate-200/80 p-3 space-y-1.5" data-preview-contact>
                  <span className="block text-[11px] font-bold text-brand-ink">Contáctanos</span>
                  <span className="flex items-center gap-1.5 text-[11px] text-slate-600"><Phone size={11} className="text-brand-accent" /> {company['Teléfono'] || 'El teléfono de tu empresa'}</span>
                  <span className="flex items-center gap-1.5 text-[11px] text-slate-600"><MapPin size={11} className="text-brand-accent" /> Tus sucursales, con su dirección y teléfono</span>
                </div>
              )}
              <p className="text-center text-[10px] text-slate-400">{data.tenant.name} · <span className="underline">Política de privacidad</span></p>
            </div>
          </div>
          {draft.show_contact && !company['Teléfono'] && (
            <p className="text-xs text-amber-700" data-missing-phone>Tu empresa no tiene teléfono en Empresa: en el portal solo se verán tus sucursales.</p>
          )}
        </div>
      </div>
    </section>
  );
}
