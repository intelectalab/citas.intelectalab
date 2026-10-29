'use client';

import { useEffect, useRef, useState } from 'react';
import { ImagePlus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';

export type SignatureChange = File | null | undefined;

interface SignatureFieldProps {
  professionalId: string | null;
  hasSignature: boolean;
  change: SignatureChange;
  onChange: (change: SignatureChange) => void;
  onError: (message: string) => void;
}

export const saveSignature = async (professionalId: string, change: SignatureChange) => {
  if (change === null) {
    await api.delete(`/professionals/${professionalId}/signature`);
  } else if (change) {
    const form = new FormData();
    form.append('signature', change);
    await api.post(`/professionals/${professionalId}/signature`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
  }
};

export default function SignatureField({ professionalId, hasSignature, change, onChange, onError }: SignatureFieldProps) {
  const [saved, setSaved] = useState<string | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!professionalId || !hasSignature) return;
    let url: string | null = null;
    api.get<Blob>(`/professionals/${professionalId}/signature`, { responseType: 'blob' })
      .then((res) => { url = URL.createObjectURL(res.data); setSaved(url); })
      .catch(() => setSaved(null));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [professionalId, hasSignature]);

  useEffect(() => {
    if (!change) {
      setPreview(null);
      return;
    }
    const url = URL.createObjectURL(change);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [change]);

  const shown = change === null ? null : preview ?? (hasSignature ? saved : null);

  const pick = (file: File | undefined) => {
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      onError('La firma debe ser una imagen PNG, JPG o WEBP.');
      return;
    }
    if (file.size > 1024 * 1024) {
      onError('La imagen pesa más de 1 MB. Usa una más liviana.');
      return;
    }
    onChange(file);
  };

  return (
    <div data-professional-signature>
      <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Firma</span>
      <div className="flex flex-wrap items-center gap-3">
        <div className="h-16 w-44 rounded-lg border border-dashed border-slate-300 bg-white flex items-center justify-center overflow-hidden">
          {shown ? <img src={shown} alt="Firma" className="max-h-14 max-w-40 object-contain" data-signature-image /> : <span className="text-xs text-slate-400">Sin firma</span>}
        </div>
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => input.current?.click()} className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold border border-brand text-brand-ink rounded-lg hover:bg-brand-soft/15">
            <ImagePlus size={14} /> {shown ? 'Cambiar' : 'Subir firma'}
          </button>
          {shown && (
            <button type="button" onClick={() => onChange(hasSignature ? null : undefined)} className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-rose-200 text-rose-700 rounded-lg hover:bg-rose-50">
              <Trash2 size={14} /> Quitar
            </button>
          )}
          <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-signature-input onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
        </div>
      </div>
      <span className="text-[10px] text-slate-400 mt-1 block">Sale en sus fichas de atención firmadas, sobre su nombre y su colegiatura. PNG con fondo transparente, hasta 1 MB.</span>
    </div>
  );
}
