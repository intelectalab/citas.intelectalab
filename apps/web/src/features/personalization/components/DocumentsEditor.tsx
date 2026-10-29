'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { FileText, ImagePlus, RotateCcw, Trash2 } from 'lucide-react';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { resolveColors } from '@/lib/brand';
import type { BrandPayload } from '../portalLook';

type Draft = { show_logo: boolean; footer: string; footer_auto: boolean; quote_terms: string; signature_name: string; signature_role: string };

const MAX_IMAGE = 1024 * 1024;

const draftOf = (payload: BrandPayload): Draft => ({
  show_logo: payload.documents.show_logo,
  footer: payload.documents.footer,
  footer_auto: payload.documents.footer_auto,
  quote_terms: payload.documents.quote_terms,
  signature_name: payload.documents.signature.name,
  signature_role: payload.documents.signature.role,
});

export default function DocumentsEditor({ onOpenBrand }: { onOpenBrand: () => void }) {
  const [data, setData] = useState<BrandPayload | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [pending, setPending] = useState<File | null | undefined>(undefined);
  const [saved, setSaved] = useState<string | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const file = useRef<HTMLInputElement>(null);

  const apply = useCallback((payload: BrandPayload) => {
    setData(payload);
    setDraft(draftOf(payload));
    setPending(undefined);
    setPreview(null);
  }, []);

  useEffect(() => {
    api.get<BrandPayload>('/branding')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar el diseño de tus documentos.')));
  }, [apply]);

  const version = data?.documents.signature.has_image ? data.documents.signature.version ?? 0 : null;

  useEffect(() => {
    if (version === null) return;
    let url: string | null = null;
    api.get<Blob>('/branding/signature', { responseType: 'blob' })
      .then((res) => { url = URL.createObjectURL(res.data); setSaved(url); })
      .catch(() => setSaved(null));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [version]);

  if (!data || !draft) {
    return (
      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-3">
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando el diseño de tus documentos…</p>}
      </section>
    );
  }

  const canEdit = data.can_edit;
  const docs = data.documents;
  const base = draftOf(data);
  const dirty = (Object.keys(base) as (keyof Draft)[]).some((key) => base[key] !== draft[key]) || pending !== undefined;
  const signature = pending === null ? null : preview ?? (version !== null ? saved : null);
  const footer = draft.footer_auto ? docs.auto_footer : draft.footer;
  const color = resolveColors(data.branding).primary;
  const logo = draft.show_logo ? data.branding.logo_url : null;
  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => (current ? { ...current, [key]: value } : current));

  const pick = (chosen: File | undefined) => {
    if (!chosen) return;
    setError('');
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(chosen.type)) {
      setError('La firma debe ser una imagen PNG, JPG o WEBP.');
      return;
    }
    if (chosen.size > MAX_IMAGE) {
      setError('La imagen pesa más de 1 MB. Usa una más liviana.');
      return;
    }
    setPending(chosen);
    setPreview(URL.createObjectURL(chosen));
  };

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      if (pending === null) {
        await api.delete('/branding/signature');
      } else if (pending) {
        const form = new FormData();
        form.append('signature', pending);
        await api.post('/branding/signature', form, { headers: { 'Content-Type': 'multipart/form-data' } });
      }
      apply((await api.put<BrandPayload>('/branding/documents', draft)).data);
      setNotice('Listo: tus próximos documentos ya salen con este diseño.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el diseño de tus documentos.'));
    } finally {
      setBusy(false);
    }
  };

  const undo = () => {
    setDraft(base);
    setPending(undefined);
    setPreview(null);
    setError('');
  };

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="documents">
      <div>
        <SectionTitle><FileText className="w-4 h-4 text-brand-accent" /> Documentos</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Cómo salen tus presupuestos, tus fichas de atención y tus comprobantes. El color es el principal de tu marca y los documentos que ya imprimiste no cambian.</p>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] gap-6 items-start">
        <div className="space-y-5">
          <div className="flex items-start justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3" data-documents-logo>
            <span>
              <span className="block text-sm font-semibold text-slate-800">Mostrar el logo</span>
              <span className="block text-xs text-slate-500">
                {data.branding.logo_url ? 'Se usa el logo de tu marca. En el comprobante sale en blanco y negro, como lo imprime una ticketera.' : 'Aún no subiste un logo.'}{' '}
                <button type="button" onClick={onOpenBrand} className="font-bold text-brand-accent hover:text-brand-ink">{data.branding.logo_url ? 'Cambiarlo' : 'Subirlo'} en Marca y colores</button>
              </span>
            </span>
            <Switch checked={draft.show_logo} disabled={!canEdit} label="Mostrar el logo" onChange={(on) => set('show_logo', on)} />
          </div>

          <div>
            <div className="flex items-center justify-between gap-2">
              <label className={labelClass} htmlFor="documents-footer">Pie de los documentos</label>
              {canEdit && !draft.footer_auto && (
                <button type="button" data-footer-auto onClick={() => setDraft((current) => (current ? { ...current, footer_auto: true, footer: docs.auto_footer } : current))} className="text-[11px] font-semibold text-brand-accent hover:text-brand-ink mb-1.5">
                  Usar los datos de la empresa
                </button>
              )}
            </div>
            <textarea
              id="documents-footer"
              rows={2}
              maxLength={300}
              disabled={!canEdit}
              className={`${inputClass} resize-none`}
              placeholder="Sin pie"
              value={footer}
              onChange={(e) => setDraft((current) => (current ? { ...current, footer_auto: false, footer: e.target.value } : current))}
            />
            <span className="block text-xs text-slate-500 mt-1">
              {draft.footer_auto ? 'Se arma con el nombre, la dirección y el teléfono de Empresa, y se actualiza solo si cambian.' : 'Dirección, teléfono, redes o un "Gracias por tu visita". Déjalo vacío si no quieres pie.'}
            </span>
          </div>

          <div className="pt-4 border-t border-slate-100 space-y-3">
            <span className="block text-sm font-bold text-brand-ink">Firma y sello de la empresa</span>
            <span className="block text-xs text-slate-500 -mt-2">Va al pie de cada presupuesto. En la ficha de atención firma el profesional que atendió: su firma se sube en su ficha de Profesionales.</span>
            <div className="flex flex-wrap items-center gap-3" data-signature-slot>
              <div className="h-20 w-52 rounded-xl border border-dashed border-slate-300 bg-white flex items-center justify-center overflow-hidden">
                {signature ? <img src={signature} alt="Firma y sello" className="max-h-[4.5rem] max-w-48 object-contain" data-signature-image /> : <span className="text-xs text-slate-400">Sin firma</span>}
              </div>
              {canEdit && (
                <div className="flex flex-wrap gap-2">
                  <button type="button" data-upload-signature disabled={busy} onClick={() => file.current?.click()} className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold border border-brand text-brand-ink rounded-lg hover:bg-brand-soft/15 disabled:opacity-50">
                    <ImagePlus size={14} /> {signature ? 'Cambiar' : 'Subir imagen'}
                  </button>
                  {signature && (
                    <button type="button" data-remove-signature disabled={busy} onClick={() => { setPending(null); setPreview(null); }} className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-rose-200 text-rose-700 rounded-lg hover:bg-rose-50 disabled:opacity-50">
                      <Trash2 size={14} /> Quitar
                    </button>
                  )}
                  <input ref={file} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-signature-input onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
                </div>
              )}
            </div>
            <span className="block text-xs text-slate-500">Una foto o escaneo de la firma con el sello, en PNG con fondo transparente si puedes; hasta 1 MB.</span>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className={labelClass} htmlFor="signature-name">Nombre de quien firma</label>
                <input id="signature-name" maxLength={120} disabled={!canEdit} className={inputClass} placeholder="Ej. Dra. Ana Ruiz" value={draft.signature_name} onChange={(e) => set('signature_name', e.target.value)} />
              </div>
              <div>
                <label className={labelClass} htmlFor="signature-role">Cargo</label>
                <input id="signature-role" maxLength={120} disabled={!canEdit} className={inputClass} placeholder="Ej. Directora médica" value={draft.signature_role} onChange={(e) => set('signature_role', e.target.value)} />
              </div>
            </div>
          </div>

          <div className="pt-4 border-t border-slate-100">
            <div className="flex items-center justify-between gap-2">
              <label className={labelClass} htmlFor="quote-terms">Condiciones del presupuesto</label>
              {canEdit && draft.quote_terms !== docs.default_terms && (
                <button type="button" data-terms-default onClick={() => set('quote_terms', docs.default_terms)} className="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-500 hover:text-brand-ink mb-1.5">
                  <RotateCcw size={11} /> Volver al texto de ejemplo
                </button>
              )}
            </div>
            <textarea id="quote-terms" rows={4} maxLength={1500} disabled={!canEdit} className={`${inputClass} resize-y`} placeholder="Sin condiciones" value={draft.quote_terms} onChange={(e) => set('quote_terms', e.target.value)} />
            <span className="flex justify-between gap-2 text-xs text-slate-500 mt-1">
              <span>Sale al pie de cada presupuesto: vigencia, formas de pago, qué pasa si el tratamiento cambia.</span>
              <span className="shrink-0 text-slate-400">{draft.quote_terms.length}/1500</span>
            </span>
          </div>

          {canEdit && (
            <div className="flex flex-wrap items-center justify-end gap-2 pt-4 border-t border-slate-100">
              {dirty && <Button type="button" variant="secondary" onClick={undo}>Deshacer cambios</Button>}
              <Button type="button" variant="primary" data-save-documents disabled={busy || !dirty} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
            </div>
          )}
        </div>

        <div className="xl:sticky xl:top-4 space-y-2">
          <span className="text-xs font-bold text-slate-600">Vista previa de un presupuesto {dirty ? '(sin guardar)' : ''}</span>
          <div className="rounded-xl border border-slate-200 bg-white shadow-sm text-[9px] text-slate-700 aspect-[210/297] flex flex-col overflow-hidden" data-documents-preview>
            <div className="px-3 py-2.5 text-white flex items-center gap-2" style={{ backgroundColor: color }}>
              {logo && <span className="bg-white px-1.5 py-1"><img src={logo} alt="" className="max-h-6 max-w-16 object-contain" data-preview-logo /></span>}
              <span className="flex-1 min-w-0">
                <span className="block text-[7px] uppercase tracking-widest opacity-80">Presupuesto</span>
                <span className="block text-[11px] font-bold truncate">Tratamiento de ortodoncia</span>
                <span className="block opacity-90 truncate">{data.tenant.name}</span>
              </span>
              <span className="text-right font-bold">{data.codes.find((code) => code.type === 'quote')?.next ?? 'PRE-00042'}</span>
            </div>
            <div className="grid grid-cols-3 gap-1 bg-slate-50 border-b border-slate-200 px-3 py-1.5">
              <span><span className="block text-[6px] uppercase text-slate-400 font-bold">Cliente</span><b>Ana Torres</b></span>
              <span><span className="block text-[6px] uppercase text-slate-400 font-bold">Profesional</span><b>Dr. Luis Paz</b></span>
              <span><span className="block text-[6px] uppercase text-slate-400 font-bold">Válido hasta</span><b>30/11/2026</b></span>
            </div>
            <div className="px-3 pt-2 space-y-1 flex-1">
              {[['Limpieza dental', '120.00'], ['Brackets metálicos', '2,400.00'], ['Controles mensuales (6)', '600.00']].map(([name, amount]) => (
                <div key={name} className="flex justify-between border-b border-slate-100 pb-0.5"><span>{name}</span><span>S/ {amount}</span></div>
              ))}
              <div className="flex justify-end gap-6 pt-1 font-bold text-[10px]" style={{ borderTop: `1px solid ${color}` }}><span>Total</span><span>S/ 3,120.00</span></div>
              {draft.quote_terms.trim() && (
                <div className="mt-2 bg-slate-50 border border-slate-200 p-1.5" data-preview-terms>
                  <span className="block text-[6px] uppercase font-bold text-slate-500">Condiciones</span>
                  <span className="whitespace-pre-line line-clamp-4">{draft.quote_terms}</span>
                </div>
              )}
              <div className="grid grid-cols-2 gap-4 pt-3 text-center">
                <span>
                  <span className="h-9 flex items-end justify-center">{signature && <img src={signature} alt="" className="max-h-9 max-w-24 object-contain" />}</span>
                  <span className="block border-t border-slate-500 pt-0.5" data-preview-signer>{draft.signature_name || data.tenant.name}{draft.signature_role ? ` · ${draft.signature_role}` : ''}</span>
                </span>
                <span>
                  <span className="h-9 block" />
                  <span className="block border-t border-slate-500 pt-0.5">Ana Torres · Conforme</span>
                </span>
              </div>
            </div>
            <div className="px-3 py-1.5 text-[7px] text-slate-500 border-t border-slate-100" data-preview-footer>{footer || 'Sin pie'}</div>
          </div>
        </div>
      </div>
    </section>
  );
}
