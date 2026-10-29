'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ImagePlus, Moon, Palette, RotateCcw, Sparkles, Sun, Trash2 } from 'lucide-react';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { useAuth } from '@/hooks/useAuth';
import {
  DEFAULT_BRAND,
  checkAccent,
  checkPrimary,
  initials,
  isLight,
  paletteFromImage,
  resolveColors,
  type BrandColors,
  type LogoPalette,
  type TenantBranding,
} from '@/lib/brand';
import BrandPreview from './BrandPreview';
import ColorField from './ColorField';

interface BrandData {
  branding: TenantBranding;
  defaults: BrandColors;
  tenant: { name: string };
  can_edit: boolean;
}

type Variant = 'light' | 'dark';
type Draft = { primary: string | null; accent: string | null; menu: string | null; short_name: string };

const PRIMARY_PRESETS = ['#022340', '#1D4F91', '#0F766E', '#15803D', '#9F1239', '#7C3AED', '#B45309', '#334155'];
const ACCENT_PRESETS = ['#3F768C', '#D97706', '#0E7490', '#16A34A', '#DB2777', '#6D28D9', '#C2410C', '#475569'];
const MENU_PRESETS = ['#0B192C', '#111827', '#1E293B', '#0F2A24', '#2E1065', '#3B0A18', '#F8FAFC', '#FFFFFF'];
const MAX_LOGO = 1024 * 1024;

const loadImage = (src: string) => new Promise<HTMLImageElement>((resolve, reject) => {
  const image = new Image();
  image.onload = () => resolve(image);
  image.onerror = reject;
  image.src = src;
});

export default function BrandEditor() {
  const { refetchUser } = useAuth();
  const [data, setData] = useState<BrandData | null>(null);
  const [draft, setDraft] = useState<Draft>({ primary: null, accent: null, menu: null, short_name: '' });
  const [pending, setPending] = useState<Partial<Record<Variant, File | null>>>({});
  const [previews, setPreviews] = useState<Partial<Record<Variant, string>>>({});
  const [suggestion, setSuggestion] = useState<LogoPalette | null>(null);
  const [dark, setDark] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const files = { light: useRef<HTMLInputElement>(null), dark: useRef<HTMLInputElement>(null) };

  const apply = useCallback((payload: BrandData) => {
    setData(payload);
    setDraft({
      primary: payload.branding.primary,
      accent: payload.branding.accent,
      menu: payload.branding.menu,
      short_name: payload.branding.short_name ?? '',
    });
    setPending({});
    setPreviews({});
  }, []);

  useEffect(() => {
    api.get<BrandData>('/branding')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la marca de tu empresa.')));
  }, [apply]);

  const colors = useMemo(() => resolveColors(draft), [draft]);
  const canEdit = Boolean(data?.can_edit);

  const logoOf = (variant: Variant) => {
    if (previews[variant]) return previews[variant]!;
    if (pending[variant] === null) return null;
    return variant === 'light' ? data?.branding.logo_url ?? null : data?.branding.logo_dark_url ?? null;
  };

  const dirty = Boolean(data) && (
    draft.primary !== data!.branding.primary
    || draft.accent !== data!.branding.accent
    || draft.menu !== data!.branding.menu
    || draft.short_name.trim().toUpperCase() !== (data!.branding.short_name ?? '')
    || Object.keys(pending).length > 0
  );

  const suggestFrom = async (src: string) => {
    try {
      const palette = paletteFromImage(await loadImage(src));
      setSuggestion(palette);
      if (!palette.colors) setNotice('');
    } catch {
      setError('No se pudo leer el logo para sugerir colores.');
    }
  };

  const pick = (variant: Variant, file: File | undefined) => {
    if (!file) return;
    setError('');
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      setError('El logo debe ser una imagen PNG, JPG o WEBP.');
      return;
    }
    if (file.size > MAX_LOGO) {
      setError('El logo pesa más de 1 MB. Usa una imagen más liviana.');
      return;
    }
    const url = URL.createObjectURL(file);
    setPending((current) => ({ ...current, [variant]: file }));
    setPreviews((current) => ({ ...current, [variant]: url }));
    if (variant === 'light') suggestFrom(url);
  };

  const remove = (variant: Variant) => {
    setPending((current) => ({ ...current, [variant]: null }));
    setPreviews((current) => ({ ...current, [variant]: undefined }));
    if (variant === 'light') setSuggestion(null);
  };

  const suggestFromSaved = async () => {
    const url = data?.branding.logo_url;
    if (!url) return;
    try {
      const res = await api.get<Blob>(url, { responseType: 'blob' });
      suggestFrom(URL.createObjectURL(res.data));
    } catch {
      setError('No se pudo leer el logo guardado para sugerir colores.');
    }
  };

  const save = async () => {
    if (!data) return;
    try {
      setBusy(true);
      setError('');
      setNotice('');
      let payload = data;

      for (const variant of ['light', 'dark'] as Variant[]) {
        const change = pending[variant];
        if (change === null) {
          payload = (await api.delete<BrandData>(`/branding/logo/${variant}`)).data;
        } else if (change) {
          const form = new FormData();
          form.append('variant', variant);
          form.append('logo', change);
          payload = (await api.post<BrandData>('/branding/logo', form, { headers: { 'Content-Type': 'multipart/form-data' } })).data;
        }
      }

      payload = (await api.put<BrandData>('/branding', {
        primary: draft.primary,
        accent: draft.accent,
        menu: draft.menu,
        short_name: draft.short_name.trim() || null,
      })).data;

      apply(payload);
      setSuggestion(null);
      await refetchUser();
      setNotice('Listo: tu marca ya se ve en tu panel, en el portal de tus clientes y en tus correos.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la marca.'));
    } finally {
      setBusy(false);
    }
  };

  const undo = () => {
    if (!data) return;
    apply(data);
    setSuggestion(null);
    setError('');
  };

  const resetColors = () => setDraft((current) => ({ ...current, primary: null, accent: null, menu: null }));

  if (!data) {
    return (
      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-3">
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando la marca de tu empresa…</p>}
      </section>
    );
  }

  const mark = draft.short_name.trim().toUpperCase() || initials(data.tenant.name);
  const primaryCheck = checkPrimary(colors.primary);
  const accentCheck = checkAccent(colors.accent);
  const hasCustom = Boolean(draft.primary || draft.accent || draft.menu);

  const logoSlot = (variant: Variant, title: string, hint: string) => {
    const src = logoOf(variant);
    return (
      <div className="space-y-2" data-logo-slot={variant}>
        <span className="block text-sm font-semibold text-slate-800">{title}</span>
        <span className="block text-xs text-slate-500">{hint}</span>
        <div className="flex flex-wrap items-center gap-3">
          <div className={`h-16 w-44 rounded-xl border border-dashed border-slate-300 flex items-center justify-center overflow-hidden ${variant === 'dark' ? 'bg-brand-menu' : 'bg-white'}`} style={variant === 'dark' ? { backgroundColor: colors.menu } : undefined}>
            {src ? <img src={src} alt={title} className="max-h-14 max-w-40 object-contain" data-logo-image={variant} /> : <span className={`text-xs ${variant === 'dark' ? 'text-white/60' : 'text-slate-400'}`}>Sin logo</span>}
          </div>
          {canEdit && (
            <div className="flex flex-wrap gap-2">
              <button type="button" data-upload-logo={variant} disabled={busy} onClick={() => files[variant].current?.click()} className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold border border-brand text-brand-ink rounded-lg hover:bg-brand-soft/15 disabled:opacity-50">
                <ImagePlus size={14} /> {src ? 'Cambiar' : 'Subir logo'}
              </button>
              {src && (
                <button type="button" data-remove-logo={variant} disabled={busy} onClick={() => remove(variant)} className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-rose-200 text-rose-700 rounded-lg hover:bg-rose-50 disabled:opacity-50">
                  <Trash2 size={14} /> Quitar
                </button>
              )}
              <input ref={files[variant]} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-logo-input={variant} onChange={(e) => { pick(variant, e.target.files?.[0]); e.target.value = ''; }} />
            </div>
          )}
        </div>
      </div>
    );
  };

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="brand">
      <div>
        <SectionTitle><Palette className="w-4 h-4 text-brand-accent" /> Marca y colores</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Tu logo y tus colores se ven en tu panel, en el portal de tus clientes, en tus correos y en tus documentos. Nada cambia para los demás hasta que pulses &quot;Guardar&quot;.</p>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)] gap-6 items-start">
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            {logoSlot('light', 'Logo para fondo claro', 'Para correos, portal y documentos. PNG, JPG o WEBP, hasta 1 MB; mejor horizontal y con fondo transparente.')}
            {logoSlot('dark', 'Logo para fondo oscuro (opcional)', 'Para el menú lateral si es oscuro. Si no lo subes, se usa el otro.')}
          </div>

          {suggestion && (
            <div className="rounded-2xl border border-brand-soft/60 bg-brand-soft/10 p-4 space-y-3" data-logo-suggestion>
              <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Sparkles size={15} className="text-brand-accent" /> Colores sugeridos desde tu logo</span>
              {suggestion.colors ? (
                <>
                  <div className="flex flex-wrap gap-4">
                    {([
                      ['Principal', suggestion.colors.primary, checkPrimary(suggestion.colors.primary).suggestion],
                      ['Acento', suggestion.colors.accent, checkAccent(suggestion.colors.accent).suggestion],
                      ['Menú', suggestion.colors.menu, suggestion.colors.menu],
                    ] as const).map(([name, original, adjusted]) => (
                      <div key={name} className="flex items-center gap-2" data-suggested={name}>
                        <span className="w-9 h-9 rounded-xl border border-black/10" style={{ backgroundColor: adjusted }} />
                        <span className="text-xs">
                          <span className="block font-bold text-slate-800">{name}</span>
                          <span className="block font-mono text-slate-500">{adjusted}</span>
                          {adjusted.toUpperCase() !== original.toUpperCase() && <span className="block text-[11px] text-slate-500">Del logo: {original}, ajustado para que se lea bien</span>}
                        </span>
                      </div>
                    ))}
                  </div>
                  {suggestion.mono && <p className="text-xs text-slate-600">Tu logo tiene un solo color: el acento es una versión más clara del mismo tono.</p>}
                  {canEdit && (
                    <div className="flex flex-wrap gap-2">
                      <Button type="button" variant="primary" data-use-suggestion-all onClick={() => {
                        setDraft((current) => ({
                          ...current,
                          primary: checkPrimary(suggestion.colors!.primary).suggestion,
                          accent: checkAccent(suggestion.colors!.accent).suggestion,
                          menu: suggestion.colors!.menu,
                        }));
                        setSuggestion(null);
                      }}>Usar estos colores</Button>
                      <Button type="button" variant="secondary" onClick={() => setSuggestion(null)}>Mantener los actuales</Button>
                    </div>
                  )}
                </>
              ) : (
                <p className="text-xs text-slate-600" data-no-colors>Tu logo no tiene colores (es blanco, negro o gris): se mantienen los colores actuales.</p>
              )}
            </div>
          )}

          {canEdit && !suggestion && data.branding.logo_url && pending.light === undefined && (
            <button type="button" data-suggest-from-logo onClick={suggestFromSaved} className="inline-flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink">
              <Sparkles size={14} /> Tomar colores del logo
            </button>
          )}

          <div className="max-w-xs">
            <label className={labelClass} htmlFor="brand-short-name">Sigla</label>
            <input id="brand-short-name" maxLength={6} className={`${inputClass} uppercase`} placeholder={initials(data.tenant.name)} value={draft.short_name} disabled={!canEdit} onChange={(e) => setDraft((current) => ({ ...current, short_name: e.target.value.toUpperCase() }))} />
            <span className="block text-xs text-slate-500 mt-1">Hasta 6 letras. Se muestra donde no hay logo, por ejemplo con el menú recogido.</span>
          </div>

          <div className="space-y-5 pt-4 border-t border-slate-100">
            <ColorField
              id="primary"
              label="Color principal"
              hint="Botones principales, pestaña y menú activos, interruptores, la franja de tus correos y el encabezado de tus documentos."
              value={colors.primary}
              original={DEFAULT_BRAND.primary}
              presets={PRIMARY_PRESETS}
              check={primaryCheck}
              checkText={(ratio) => `Con texto blanco encima se lee poco (${ratio.toFixed(1)}:1; lo recomendado es 4.5:1 o más).`}
              disabled={!canEdit}
              onChange={(value) => setDraft((current) => ({ ...current, primary: value }))}
            />
            <ColorField
              id="accent"
              label="Color de acento"
              hint="Enlaces, íconos e insignias. Sus fondos suaves se calculan solos."
              value={colors.accent}
              original={DEFAULT_BRAND.accent}
              presets={ACCENT_PRESETS}
              check={accentCheck}
              checkText={(ratio) => `Como texto sobre fondo blanco se lee poco (${ratio.toFixed(1)}:1; lo recomendado es 4.5:1 o más).`}
              disabled={!canEdit}
              onChange={(value) => setDraft((current) => ({ ...current, accent: value }))}
            />
            <ColorField
              id="menu"
              label="Color del menú"
              hint={`El fondo del menú lateral. Su texto se pone ${isLight(colors.menu) ? 'oscuro' : 'claro'} solo, para que se lea.`}
              value={colors.menu}
              original={DEFAULT_BRAND.menu}
              presets={MENU_PRESETS}
              disabled={!canEdit}
              onChange={(value) => setDraft((current) => ({ ...current, menu: value }))}
            />
          </div>

          <p className="text-xs text-slate-500">Los títulos usan un tono muy oscuro del principal. Los avisos (verde, ámbar y rojo), los fondos y los grises no cambian, porque significan algo o dan descanso a la vista.</p>

          {canEdit && (
            <div className="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100">
              <button type="button" data-reset-colors onClick={resetColors} disabled={!hasCustom} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-brand-ink disabled:opacity-40">
                <RotateCcw size={13} /> Restablecer al diseño original
              </button>
              <div className="flex gap-2">
                {dirty && <Button type="button" variant="secondary" onClick={undo}>Deshacer cambios</Button>}
                <Button type="button" variant="primary" data-save-brand disabled={busy || !dirty} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
              </div>
            </div>
          )}
        </div>

        <div className="xl:sticky xl:top-4 space-y-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-bold text-slate-600">Vista previa {dirty ? '(sin guardar)' : ''}</span>
            <div className="inline-flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label="Modo de la vista previa">
              {([[false, 'Claro', Sun], [true, 'Oscuro', Moon]] as const).map(([value, label, Icon]) => (
                <button key={label} type="button" role="radio" aria-checked={dark === value} data-preview-mode={label} onClick={() => setDark(value)} className={`flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold ${dark === value ? 'bg-brand text-white' : 'text-slate-600'}`}>
                  <Icon size={12} /> {label}
                </button>
              ))}
            </div>
          </div>
          <BrandPreview colors={colors} dark={dark} logo={logoOf('light')} logoDark={logoOf('dark')} mark={mark} tenantName={data.tenant.name} />
        </div>
      </div>
    </section>
  );
}
