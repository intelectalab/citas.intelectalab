'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { Palette } from 'lucide-react';
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
  onOpenBrand?: () => void;
}

export default function EmailDesignCard({ design, canSave, onSaved, onDirtyChange, onOpenBrand }: EmailDesignCardProps) {
  const color = design.color;
  const [showName, setShowName] = useState(design.show_name);
  const [footer, setFooter] = useState(design.footer);
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const changed = showName !== design.show_name || footer.trim() !== design.footer.trim();
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

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4" data-card="email-design">
      <div>
        <SectionTitle><Palette className="w-4 h-4 text-brand-accent" /> Diseño del correo</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Cómo se ven todos los correos que reciben tus clientes: avisos, mensajes y campañas.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="space-y-5">
          <div>
            <span className={labelClass}>Logo y color</span>
            <div className="flex flex-wrap items-center gap-3">
              <div className="h-16 w-40 rounded-xl border border-dashed border-slate-300 bg-slate-50 flex items-center justify-center overflow-hidden" data-logo-box>
                {design.logo_url
                  ? <img src={design.logo_url} alt={`Logo de ${design.company}`} className="max-h-14 max-w-36 object-contain" data-logo />
                  : <span className="text-xs text-slate-400">Sin logo</span>}
              </div>
              <span className="w-8 h-8 rounded-full border border-slate-200 shrink-0" style={{ backgroundColor: design.color }} title={design.color} data-email-color />
              {onOpenBrand && (
                <button type="button" data-open-brand onClick={onOpenBrand} className="text-xs font-bold text-brand-accent hover:text-brand-ink">
                  Cambiar en Marca y colores
                </button>
              )}
            </div>
            <p className="text-xs text-slate-500 mt-1.5">Los correos usan el logo para fondo claro y el color principal de tu marca, igual que tu panel y tu portal.</p>
          </div>

          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span>
              <span className="block text-sm font-semibold text-slate-800">Mostrar el nombre de la empresa</span>
              <span className="block text-xs text-slate-500">{design.company}, arriba del correo{design.logo_url ? ', junto al logo' : ''}.</span>
            </span>
            <Switch checked={showName} onChange={setShowName} label="Mostrar el nombre de la empresa" disabled={!canSave} />
          </div>

          <div>
            <label className={labelClass} htmlFor="email-footer">Pie del correo</label>
            <textarea id="email-footer" rows={3} maxLength={500} className={inputClass} value={footer} disabled={!canSave} placeholder="Dirección, teléfono, redes sociales" onChange={(e) => setFooter(e.target.value)} />
            <div className="flex flex-wrap items-center justify-between gap-2 mt-1.5">
              <p className="text-xs text-slate-500">Se repite al final de todos los correos. Déjalo vacío si no quieres pie.</p>
              {canSave && design.default_footer && footer.trim() !== design.default_footer && (
                <button type="button" data-default-footer onClick={() => setFooter(design.default_footer)} className="text-xs font-bold text-brand-accent hover:text-brand-ink">Usar los datos de la empresa</button>
              )}
            </div>
          </div>

          <ErrorAlert message={error} />
          <SuccessAlert message={notice} />

          {canSave && (
            <div className="flex flex-wrap items-center gap-3">
              <button type="button" data-save-design disabled={busy || !changed || !valid} onClick={() => run(() => api.put('/messaging/design', { color, show_name: showName, footer }), 'Diseño guardado: tus próximos correos ya salen así.')}
                className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
                {busy ? 'Guardando...' : 'Guardar diseño'}
              </button>
              {changed && (
                <button type="button" onClick={() => { setShowName(design.show_name); setFooter(design.footer); }} className="px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50">Deshacer cambios</button>
              )}
            </div>
          )}
        </div>

        <EmailPreview design={{ color: valid ? color : design.color, show_name: showName, footer }} version={version} title="Vista previa (con datos de ejemplo)" />
      </div>
    </section>
  );
}
