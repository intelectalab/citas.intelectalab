'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useRef, useState } from 'react';
import { Check, ImagePlus, Palette, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import EmailPreview from '@/features/messages/components/EmailPreview';
import type { EmailDesign, MessagingSettingsData } from '@/features/messages/messages';

const HEX = /^#[0-9A-Fa-f]{6}$/;

interface EmailDesignCardProps {
  design: EmailDesign;
  canSave: boolean;
  onSaved: (payload: MessagingSettingsData) => void;
  onDirtyChange?: (dirty: boolean) => void;
}

export default function EmailDesignCard({ design, canSave, onSaved, onDirtyChange }: EmailDesignCardProps) {
  const [color, setColor] = useState(design.color);
  const [custom, setCustom] = useState(design.color);
  const [showName, setShowName] = useState(design.show_name);
  const [footer, setFooter] = useState(design.footer);
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const changed = color.toUpperCase() !== design.color.toUpperCase() || showName !== design.show_name || footer.trim() !== design.footer.trim();
  const valid = HEX.test(color);

  useEffect(() => { onDirtyChange?.(changed); }, [changed, onDirtyChange]);

  const run = async (fn: () => Promise<{ data: MessagingSettingsData }>, done: string) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await fn();
      onSaved(res.data);
      setVersion((v) => v + 1);
      setNotice(done);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el diseño.'));
    } finally {
      setBusy(false);
    }
  };

  const upload = (file: File | undefined) => {
    if (!file) return;
    if (file.size > 1024 * 1024) {
      setError('El logo pesa más de 1 MB. Usa una imagen más liviana.');
      return;
    }
    const form = new FormData();
    form.append('logo', file);
    run(() => api.post('/messaging/design/logo', form, { headers: { 'Content-Type': 'multipart/form-data' } }), 'Logo guardado: ya aparece en tus correos y en tu página de pago.');
  };

  const pickCustom = (value: string) => {
    const next = value.startsWith('#') ? value : `#${value}`;
    setCustom(next.slice(0, 7));
    if (HEX.test(next)) setColor(next.toUpperCase());
  };

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4" data-card="email-design">
      <div>
        <SectionTitle><Palette className="w-4 h-4 text-[#3F768C]" /> Diseño del correo</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Cómo se ven todos los correos que reciben tus clientes: avisos, mensajes y campañas.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="space-y-5">
          <div>
            <span className={labelClass}>Logo</span>
            <div className="flex flex-wrap items-center gap-3">
              <div className="h-16 w-40 rounded-xl border border-dashed border-slate-300 bg-slate-50 flex items-center justify-center overflow-hidden" data-logo-box>
                {design.logo_url
                  ? <img src={design.logo_url} alt={`Logo de ${design.company}`} className="max-h-14 max-w-36 object-contain" data-logo />
                  : <span className="text-xs text-slate-400">Sin logo</span>}
              </div>
              {canSave && (
                <div className="flex flex-wrap gap-2">
                  <button type="button" data-upload-logo disabled={busy} onClick={() => fileRef.current?.click()} className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold border border-[#022340] text-[#022340] rounded-lg hover:bg-[#84D0D9]/15 disabled:opacity-50">
                    <ImagePlus size={14} /> {design.logo_url ? 'Cambiar logo' : 'Subir logo'}
                  </button>
                  {design.logo_url && (
                    <button type="button" data-remove-logo disabled={busy} onClick={() => run(() => api.delete('/messaging/design/logo'), 'Logo quitado.')} className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-rose-200 text-rose-700 rounded-lg hover:bg-rose-50 disabled:opacity-50">
                      <Trash2 size={14} /> Quitar
                    </button>
                  )}
                  <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-logo-input onChange={(e) => { upload(e.target.files?.[0]); e.target.value = ''; }} />
                </div>
              )}
            </div>
            <p className="text-xs text-slate-500 mt-1.5">PNG, JPG o WEBP, hasta 1 MB. Se ve mejor un logo horizontal con fondo blanco o transparente.</p>
          </div>

          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span>
              <span className="block text-sm font-semibold text-slate-800">Mostrar el nombre de la empresa</span>
              <span className="block text-xs text-slate-500">{design.company}, arriba del correo{design.logo_url ? ', junto al logo' : ''}.</span>
            </span>
            <Switch checked={showName} onChange={setShowName} label="Mostrar el nombre de la empresa" disabled={!canSave} />
          </div>

          <div>
            <span className={labelClass}>Color principal</span>
            <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Color principal">
              {design.colors.map((c) => {
                const selected = color.toUpperCase() === c.toUpperCase();
                return (
                  <button key={c} type="button" role="radio" aria-checked={selected} aria-label={`Color ${c}`} data-color={c} disabled={!canSave} onClick={() => { setColor(c); setCustom(c); }}
                    className={`w-8 h-8 rounded-full flex items-center justify-center transition-transform hover:scale-110 disabled:hover:scale-100 ${selected ? 'ring-2 ring-offset-2 ring-[#022340]' : ''}`} style={{ backgroundColor: c }}>
                    {selected && <Check size={15} className="text-white" />}
                  </button>
                );
              })}
              <div className="flex items-center gap-1.5 ml-1">
                <span className="w-8 h-8 rounded-full border border-slate-200" style={{ backgroundColor: HEX.test(custom) ? custom : '#ffffff' }} />
                <input aria-label="Otro color" data-custom-color className="w-24 text-sm border border-slate-200 rounded-lg px-2.5 py-1.5 font-mono uppercase" value={custom} disabled={!canSave} onChange={(e) => pickCustom(e.target.value.trim())} placeholder="#3F768C" />
              </div>
            </div>
            <p className="text-xs text-slate-500 mt-1.5">Se usa en la franja de arriba y en los enlaces.</p>
          </div>

          <div>
            <label className={labelClass} htmlFor="email-footer">Pie del correo</label>
            <textarea id="email-footer" rows={3} maxLength={500} className={inputClass} value={footer} disabled={!canSave} placeholder="Dirección, teléfono, redes sociales" onChange={(e) => setFooter(e.target.value)} />
            <div className="flex flex-wrap items-center justify-between gap-2 mt-1.5">
              <p className="text-xs text-slate-500">Se repite al final de todos los correos. Déjalo vacío si no quieres pie.</p>
              {canSave && design.default_footer && footer.trim() !== design.default_footer && (
                <button type="button" data-default-footer onClick={() => setFooter(design.default_footer)} className="text-xs font-bold text-[#3F768C] hover:text-[#022340]">Usar los datos de la empresa</button>
              )}
            </div>
          </div>

          <ErrorAlert message={error} />
          <SuccessAlert message={notice} />

          {canSave && (
            <div className="flex flex-wrap items-center gap-3">
              <button type="button" data-save-design disabled={busy || !changed || !valid} onClick={() => run(() => api.put('/messaging/design', { color, show_name: showName, footer }), 'Diseño guardado: tus próximos correos ya salen así.')}
                className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] disabled:opacity-50">
                {busy ? 'Guardando...' : 'Guardar diseño'}
              </button>
              {changed && (
                <button type="button" onClick={() => { setColor(design.color); setCustom(design.color); setShowName(design.show_name); setFooter(design.footer); }} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Deshacer cambios</button>
              )}
            </div>
          )}
        </div>

        <EmailPreview design={{ color: valid ? color : design.color, show_name: showName, footer }} version={version} title="Vista previa (con datos de ejemplo)" />
      </div>
    </section>
  );
}
