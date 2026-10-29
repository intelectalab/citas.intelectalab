'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { ImagePlus, RotateCcw, Sparkles, Trash2 } from 'lucide-react';
import Button from '@/app/components/common/Button';
import DateField from '@/app/components/common/DateField';
import OptionCard from '@/app/components/common/OptionCard';
import Select from '@/app/components/common/Select';
import TimeField from '@/app/components/common/TimeField';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { DEFAULT_BRAND, checkAccent, checkPrimary, initials, paletteFromImage, resolveColors, type LogoPalette } from '@/lib/brand';
import { LOOK_DEFAULTS, PLATFORM_DEFAULTS, usePlatform, type PlatformConfig, type PlatformLook } from '@/hooks/usePlatform';
import ColorField from '@/features/personalization/components/ColorField';
import { LOGIN_BACKGROUNDS } from '../../loginPatterns';
import LivePreview, { type PreviewPage } from './LivePreview';
import LookCards from './LookCards';

type Section = 'brand' | 'landing' | 'login' | 'maintenance';
type FileName = 'logo' | 'logo-dark' | 'landing' | 'login';

interface LookData {
  look: PlatformLook & { maintenance: { message: string | null; starts_at: string | null; ends_at: string | null } };
  product: { product_name: string; accent_color: string };
}

type BrandDraft = Omit<PlatformLook['brand'], 'logo_url' | 'logo_dark_url'> & { product_name: string; accent_color: string };
type LandingDraft = Omit<PlatformLook['landing'], 'image_url'>;
type LoginDraft = Omit<PlatformLook['login'], 'image_url'>;
type MaintenanceDraft = { message: string; startDate: string; startTime: string; endDate: string; endTime: string };

const SECTIONS: { id: Section; label: string; files: FileName[] }[] = [
  { id: 'brand', label: 'Marca y colores', files: ['logo', 'logo-dark'] },
  { id: 'landing', label: 'Portada', files: ['landing'] },
  { id: 'login', label: 'Ingreso', files: ['login'] },
  { id: 'maintenance', label: 'Aviso de mantenimiento', files: [] },
];

const PAGES: Record<Section, PreviewPage[]> = {
  brand: [{ id: 'landing', label: 'Portada', path: '/' }, { id: 'login', label: 'Ingreso', path: '/login' }, { id: 'console', label: 'Consola', path: '/admin/tenants' }],
  landing: [{ id: 'landing', label: 'Portada', path: '/' }],
  login: [{ id: 'login', label: 'Ingreso', path: '/login' }],
  maintenance: [{ id: 'login', label: 'Ingreso', path: '/login' }, { id: 'landing', label: 'Portada', path: '/' }],
};

const PROGRESS_PRESETS = ['#10B981', '#3F768C', '#0EA5E9', '#8B5CF6', '#F59E0B', '#EC4899'];
const PRIMARY_PRESETS = ['#022340', '#1D4F91', '#0F766E', '#15803D', '#9F1239', '#7C3AED', '#B45309', '#334155'];
const ACCENT_PRESETS = ['#3F768C', '#D97706', '#0E7490', '#16A34A', '#DB2777', '#6D28D9', '#C2410C', '#475569'];
const MENU_PRESETS = ['#0B192C', '#111827', '#1E293B', '#0F2A24', '#2E1065', '#3B0A18', '#F8FAFC', '#FFFFFF'];

const pad = (n: number) => String(n).padStart(2, '0');
const splitIso = (iso: string | null) => {
  if (!iso) return { date: '', time: '' };
  const d = new Date(iso);
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` };
};
const joinIso = (date: string, time: string) => (date ? new Date(`${date}T${time || '00:00'}`).toISOString() : null);

const toBrand = (data: LookData): BrandDraft => {
  const { logo_url: _l, logo_dark_url: _d, ...rest } = data.look.brand;
  return { ...rest, product_name: data.product.product_name, accent_color: data.product.accent_color };
};
const toLanding = (data: LookData): LandingDraft => {
  const { image_url: _i, ...rest } = data.look.landing;
  return { ...rest, cards: rest.cards.map((c) => ({ ...c })) };
};
const toLogin = (data: LookData): LoginDraft => {
  const { image_url: _i, ...rest } = data.look.login;
  return { ...rest, cards: rest.cards.map((c) => ({ ...c })) };
};
const toMaintenance = (data: LookData): MaintenanceDraft => {
  const start = splitIso(data.look.maintenance.starts_at);
  const end = splitIso(data.look.maintenance.ends_at);
  return { message: data.look.maintenance.message ?? '', startDate: start.date, startTime: start.time, endDate: end.date, endTime: end.time };
};

const loadImage = (src: string) => new Promise<HTMLImageElement>((resolve, reject) => {
  const image = new Image();
  image.onload = () => resolve(image);
  image.onerror = reject;
  image.src = src;
});

export default function PlatformPersonalization() {
  const { applyPlatform } = usePlatform();
  const [data, setData] = useState<LookData | null>(null);
  const [base, setBase] = useState<PlatformConfig>(PLATFORM_DEFAULTS);
  const [current, setCurrent] = useState<Section>('brand');
  const [brand, setBrand] = useState<BrandDraft | null>(null);
  const [landing, setLanding] = useState<LandingDraft | null>(null);
  const [login, setLogin] = useState<LoginDraft | null>(null);
  const [maintenance, setMaintenance] = useState<MaintenanceDraft | null>(null);
  const [pending, setPending] = useState<Partial<Record<FileName, File | null>>>({});
  const [previews, setPreviews] = useState<Partial<Record<FileName, string>>>({});
  const [suggestion, setSuggestion] = useState<LogoPalette | null>(null);
  const [resetting, setResetting] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const inputs = useRef<Partial<Record<FileName, HTMLInputElement | null>>>({});

  const apply = useCallback((payload: LookData, only?: Section) => {
    setData(payload);
    if (!only || only === 'brand') setBrand(toBrand(payload));
    if (!only || only === 'landing') setLanding(toLanding(payload));
    if (!only || only === 'login') setLogin(toLogin(payload));
    if (!only || only === 'maintenance') setMaintenance(toMaintenance(payload));
  }, []);

  useEffect(() => {
    api.get<LookData>('/admin/personalization')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la personalización de la plataforma.')));
    api.get<PlatformConfig>('/platform').then((res) => setBase({ ...PLATFORM_DEFAULTS, ...res.data })).catch(() => undefined);
  }, [apply]);

  useEffect(() => {
    const hash = window.location.hash.replace('#', '') as Section;
    if (SECTIONS.some((s) => s.id === hash)) setCurrent(hash);
  }, []);

  const fileUrl = (name: FileName): string | null => {
    if (previews[name]) return previews[name]!;
    if (pending[name] === null) return null;
    const look = data?.look;
    if (!look) return null;
    return { logo: look.brand.logo_url, 'logo-dark': look.brand.logo_dark_url, landing: look.landing.image_url, login: look.login.image_url }[name];
  };

  const sectionDirty = (section: Section) => {
    if (!data) return false;
    const files = SECTIONS.find((s) => s.id === section)!.files;
    if (files.some((f) => f in pending)) return true;
    if (section === 'brand') return JSON.stringify(brand) !== JSON.stringify(toBrand(data));
    if (section === 'landing') return JSON.stringify(landing) !== JSON.stringify(toLanding(data));
    if (section === 'login') return JSON.stringify(login) !== JSON.stringify(toLogin(data));
    return JSON.stringify(maintenance) !== JSON.stringify(toMaintenance(data));
  };

  const maintenancePreview = maintenance && maintenance.message.trim()
    ? { message: maintenance.message, starts_at: joinIso(maintenance.startDate, maintenance.startTime), ends_at: joinIso(maintenance.endDate, maintenance.endTime) }
    : null;

  const config: PlatformConfig = (() => {
    const look: PlatformLook = data ? {
      brand: { ...data.look.brand, ...(brand ?? {}), logo_url: fileUrl('logo'), logo_dark_url: fileUrl('logo-dark') },
      landing: { ...data.look.landing, ...(landing ?? {}), image_url: fileUrl('landing') },
      login: { ...data.look.login, ...(login ?? {}), image_url: login?.background === 'image' ? fileUrl('login') : null },
      maintenance: maintenancePreview,
    } : LOOK_DEFAULTS;
    return { ...base, product_name: brand?.product_name || base.product_name, accent_color: brand?.accent_color ?? base.accent_color, look };
  })();

  const pick = (name: FileName, file: File | undefined) => {
    if (!file) return;
    setError('');
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      setError('La imagen debe ser PNG, JPG o WEBP.');
      return;
    }
    const max = name.startsWith('logo') ? 1024 * 1024 : 3 * 1024 * 1024;
    if (file.size > max) {
      setError(name.startsWith('logo') ? 'El logo pesa más de 1 MB. Usa una imagen más liviana.' : 'La imagen pesa más de 3 MB. Usa una más liviana.');
      return;
    }
    const url = URL.createObjectURL(file);
    setPending((p) => ({ ...p, [name]: file }));
    setPreviews((p) => ({ ...p, [name]: url }));
    if (name === 'logo') loadImage(url).then((img) => setSuggestion(paletteFromImage(img))).catch(() => undefined);
  };

  const remove = (name: FileName) => {
    setPending((p) => ({ ...p, [name]: null }));
    setPreviews((p) => ({ ...p, [name]: undefined }));
    if (name === 'logo') setSuggestion(null);
  };

  const clearFiles = (files: FileName[]) => {
    setPending((p) => Object.fromEntries(Object.entries(p).filter(([k]) => !files.includes(k as FileName))));
    setPreviews((p) => Object.fromEntries(Object.entries(p).filter(([k]) => !files.includes(k as FileName))));
  };

  const refreshPublic = async () => {
    const res = await api.get<PlatformConfig>('/platform');
    const next = { ...PLATFORM_DEFAULTS, ...res.data };
    setBase(next);
    applyPlatform(next);
  };

  const save = async () => {
    if (!data) return;
    const section = SECTIONS.find((s) => s.id === current)!;
    try {
      setBusy(true);
      setError('');
      setNotice('');
      for (const name of section.files) {
        const change = pending[name];
        if (change === null) await api.delete(`/admin/personalization/files/${name}`);
        else if (change) {
          const form = new FormData();
          form.append('file', change);
          await api.post(`/admin/personalization/files/${name}`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
        }
      }
      const body = current === 'brand' ? brand
        : current === 'landing' ? landing
        : current === 'login' ? login
        : { message: maintenance?.message.trim() || null, starts_at: joinIso(maintenance!.startDate, maintenance!.startTime), ends_at: joinIso(maintenance!.endDate, maintenance!.endTime) };
      const res = await api.put<LookData>(`/admin/personalization/${current}`, body);
      clearFiles(section.files);
      apply(res.data, current);
      setSuggestion(null);
      await refreshPublic();
      setNotice(`Guardado: "${section.label}" ya se ve así para todos.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const reset = async () => {
    const section = SECTIONS.find((s) => s.id === current)!;
    try {
      setBusy(true);
      setError('');
      const res = await api.post<LookData>(`/admin/personalization/${current}/reset`);
      clearFiles(section.files);
      apply(res.data, current);
      await refreshPublic();
      setNotice(`"${section.label}" volvió a los valores originales.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo restablecer.'));
    } finally {
      setBusy(false);
      setResetting(false);
    }
  };

  const undo = () => {
    if (!data) return;
    clearFiles(SECTIONS.find((s) => s.id === current)!.files);
    apply(data, current);
    setSuggestion(null);
  };

  const open = (id: Section) => {
    setCurrent(id);
    setError('');
    setNotice('');
    window.history.replaceState(null, '', `#${id}`);
  };

  const imageSlot = (name: FileName, title: string, hint: string, dark = false) => {
    const src = fileUrl(name);
    return (
      <div className="space-y-2" data-file-slot={name}>
        <span className="block text-sm font-semibold text-slate-800">{title}</span>
        <span className="block text-xs text-slate-500">{hint}</span>
        <div className="flex flex-wrap items-center gap-3">
          <div className={`h-20 w-48 rounded-xl border border-dashed border-slate-300 flex items-center justify-center overflow-hidden ${dark ? 'bg-brand-menu' : 'bg-white'}`}>
            {src ? <img src={src} alt={title} className={`max-h-full max-w-full ${name.startsWith('logo') ? 'object-contain p-1' : 'object-cover w-full h-full'}`} /> : <span className={`text-xs ${dark ? 'text-white/60' : 'text-slate-400'}`}>Sin imagen</span>}
          </div>
          <div className="flex flex-wrap gap-2">
            <button type="button" data-upload-file={name} disabled={busy} onClick={() => inputs.current[name]?.click()} className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold border border-brand text-brand-ink rounded-lg hover:bg-brand-soft/15 disabled:opacity-50">
              <ImagePlus size={14} /> {src ? 'Cambiar' : 'Subir'}
            </button>
            {src && (
              <button type="button" data-remove-file={name} disabled={busy} onClick={() => remove(name)} className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-rose-200 text-rose-700 rounded-lg hover:bg-rose-50 disabled:opacity-50">
                <Trash2 size={14} /> Quitar
              </button>
            )}
            <input ref={(el) => { inputs.current[name] = el; }} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-file-input={name} onChange={(e) => { pick(name, e.target.files?.[0]); e.target.value = ''; }} />
          </div>
        </div>
      </div>
    );
  };

  const text = (id: string, label: string, value: string | null, onChange: (v: string) => void, opts: { max: number; area?: boolean; hint?: string; placeholder?: string } ) => (
    <div>
      <label className={labelClass} htmlFor={id}>{label}</label>
      {opts.area
        ? <textarea id={id} rows={3} maxLength={opts.max} className={inputClass} value={value ?? ''} placeholder={opts.placeholder} onChange={(e) => onChange(e.target.value)} />
        : <input id={id} maxLength={opts.max} className={inputClass} value={value ?? ''} placeholder={opts.placeholder} onChange={(e) => onChange(e.target.value)} />}
      {opts.hint && <span className="block text-xs text-slate-500 mt-1">{opts.hint}</span>}
    </div>
  );

  if (!data || !brand || !landing || !login || !maintenance) {
    return (
      <div className="space-y-4">
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Personalización de la plataforma</h1>
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando…</p>}
      </div>
    );
  }

  const colors = resolveColors(brand);
  const dirty = sectionDirty(current);
  const sectionInfo = SECTIONS.find((s) => s.id === current)!;

  return (
    <div className="space-y-6 pb-10 font-sans text-[#1F2937]">
      <div>
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Personalización de la plataforma</h1>
        <p className="text-sm text-slate-500 font-medium">Cómo se ven la portada, el ingreso y la consola. Lo de cada empresa lo personaliza cada empresa.</p>
      </div>

      <div className="lg:hidden">
        <Select ariaLabel="Sección" clearable={false} value={current} onChange={(e) => { if (e.target.value) open(e.target.value as Section); }} options={SECTIONS.map((s) => ({ value: s.id, label: s.label }))} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[13rem_minmax(0,1fr)] gap-6 items-start">
        <nav className="hidden lg:block sticky top-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs p-3 space-y-0.5" aria-label="Secciones" data-platform-look-index>
          {SECTIONS.map((s) => (
            <button key={s.id} type="button" data-section-link={s.id} aria-current={current === s.id ? 'page' : undefined} onClick={() => open(s.id)}
              className={`w-full flex items-center justify-between gap-2 text-left px-3 py-1.5 rounded-lg text-sm transition-colors ${current === s.id ? 'bg-brand-soft/20 text-brand-ink font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-ink'}`}>
              <span>{s.label}</span>
              {sectionDirty(s.id) && <span className="w-2 h-2 rounded-full bg-amber-500 shrink-0" title="Cambios sin guardar" />}
            </button>
          ))}
        </nav>

        <div className="min-w-0 grid grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-6 items-start">
          <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-look-section={current}>
            <SectionTitle>{sectionInfo.label}</SectionTitle>
            <ErrorAlert message={error} />
            <SuccessAlert message={notice} />

            {current === 'brand' && (
              <div className="space-y-5">
                {text('product-name', 'Nombre del producto', brand.product_name, (v) => setBrand((c) => ({ ...c!, product_name: v })), { max: 60, hint: 'Se usa en la portada, el ingreso, la consola y la pestaña del navegador.' })}
                <div className="max-w-xs">
                  {text('platform-short', 'Sigla', brand.short_name, (v) => setBrand((c) => ({ ...c!, short_name: v.toUpperCase() || null })), { max: 6, placeholder: initials(brand.product_name), hint: 'Hasta 6 letras. Se muestra donde no hay logo.' })}
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                  {imageSlot('logo', 'Logo para fondo claro', 'Portada y encabezados. PNG, JPG o WEBP, hasta 1 MB.')}
                  {imageSlot('logo-dark', 'Logo para fondo oscuro (opcional)', 'Panel del ingreso y menú oscuro.', true)}
                </div>

                {suggestion && (
                  <div className="rounded-2xl border border-brand-soft/60 bg-brand-soft/10 p-4 space-y-3" data-logo-suggestion>
                    <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Sparkles size={15} className="text-brand-accent" /> Colores sugeridos desde el logo</span>
                    {suggestion.colors ? (
                      <div className="flex flex-wrap items-center gap-3">
                        {[checkPrimary(suggestion.colors.primary).suggestion, checkAccent(suggestion.colors.accent).suggestion, suggestion.colors.menu].map((c) => <span key={c} className="w-8 h-8 rounded-lg border border-black/10" style={{ backgroundColor: c }} title={c} />)}
                        <Button type="button" variant="primary" data-use-suggestion-all onClick={() => { setBrand((c) => ({ ...c!, primary: checkPrimary(suggestion.colors!.primary).suggestion, accent: checkAccent(suggestion.colors!.accent).suggestion, menu: suggestion.colors!.menu })); setSuggestion(null); }}>Usar estos colores</Button>
                        <Button type="button" variant="secondary" onClick={() => setSuggestion(null)}>Mantener los actuales</Button>
                      </div>
                    ) : <p className="text-xs text-slate-600">El logo no tiene colores: se mantienen los actuales.</p>}
                  </div>
                )}

                <div className="space-y-5 pt-4 border-t border-slate-100">
                  <ColorField id="primary" label="Color principal" hint="Botones principales, menú activo y el panel del ingreso." value={colors.primary} original={DEFAULT_BRAND.primary} presets={PRIMARY_PRESETS} check={checkPrimary(colors.primary)} checkText={(r) => `Con texto blanco encima se lee poco (${r.toFixed(1)}:1; lo recomendado es 4.5:1 o más).`} onChange={(v) => setBrand((c) => ({ ...c!, primary: v }))} />
                  <ColorField id="accent" label="Color de acento" hint="Enlaces, íconos y el título resaltado." value={colors.accent} original={DEFAULT_BRAND.accent} presets={ACCENT_PRESETS} check={checkAccent(colors.accent)} checkText={(r) => `Como texto sobre fondo blanco se lee poco (${r.toFixed(1)}:1; lo recomendado es 4.5:1 o más).`} onChange={(v) => setBrand((c) => ({ ...c!, accent: v }))} />
                  <ColorField id="menu" label="Color del menú de la consola" hint="El fondo del menú lateral de la consola." value={colors.menu} original={DEFAULT_BRAND.menu} presets={MENU_PRESETS} onChange={(v) => setBrand((c) => ({ ...c!, menu: v }))} />
                  <ColorField id="progress" label="Color de avance" hint="Interruptores encendidos y barras de avance, en la consola y en todas las empresas." value={brand.accent_color} original={PLATFORM_DEFAULTS.accent_color} presets={PROGRESS_PRESETS} onChange={(v) => setBrand((c) => ({ ...c!, accent_color: v ?? PLATFORM_DEFAULTS.accent_color }))} />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                  {text('made-by', 'Pie "Realizado por"', brand.made_by, (v) => setBrand((c) => ({ ...c!, made_by: v || null })), { max: 80, placeholder: 'Realizado por IntelectaLab', hint: 'Vacío: no se muestra.' })}
                  {text('made-by-url', 'Enlace del pie', brand.made_by_url, (v) => setBrand((c) => ({ ...c!, made_by_url: v || null })), { max: 190, placeholder: 'https://intelectalab.com' })}
                </div>
              </div>
            )}

            {current === 'landing' && (
              <div className="space-y-4">
                {text('landing-tag', 'Etiqueta (arriba del título)', landing.tag, (v) => setLanding((c) => ({ ...c!, tag: v || null })), { max: 60, hint: 'Vacía: no se muestra.' })}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {text('landing-title', 'Título', landing.title, (v) => setLanding((c) => ({ ...c!, title: v })), { max: 120 })}
                  {text('landing-highlight', 'Título resaltado (en color de acento)', landing.highlight, (v) => setLanding((c) => ({ ...c!, highlight: v || null })), { max: 80 })}
                </div>
                {text('landing-description', 'Descripción', landing.description, (v) => setLanding((c) => ({ ...c!, description: v || null })), { max: 300, area: true })}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {text('landing-primary', 'Botón principal', landing.primary_button, (v) => setLanding((c) => ({ ...c!, primary_button: v })), { max: 30 })}
                  {text('landing-secondary', 'Segundo botón', landing.secondary_button, (v) => setLanding((c) => ({ ...c!, secondary_button: v })), { max: 30 })}
                </div>
                {imageSlot('landing', 'Imagen al lado del título (opcional)', 'Si hay imagen, se muestra en lugar de las tarjetas. PNG, JPG o WEBP, hasta 3 MB, de al menos 600×400 px.')}
                <LookCards cards={landing.cards} hint="Se muestran al lado del título si no hay imagen." onChange={(cards) => setLanding((c) => ({ ...c!, cards }))} />
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                  {text('plans-title', 'Título de la sección de planes', landing.plans_title, (v) => setLanding((c) => ({ ...c!, plans_title: v })), { max: 120 })}
                  {text('plans-text', 'Texto de la sección de planes', landing.plans_text, (v) => setLanding((c) => ({ ...c!, plans_text: v || null })), { max: 200 })}
                </div>
                <p className="text-xs text-slate-500">Los planes salen solos, con lo que configures en Planes SaaS.</p>
              </div>
            )}

            {current === 'login' && (
              <div className="space-y-4">
                {text('login-tag', 'Etiqueta (arriba del título)', login.tag, (v) => setLogin((c) => ({ ...c!, tag: v || null })), { max: 60, hint: 'Vacía: no se muestra.' })}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {text('login-title', 'Título', login.title, (v) => setLogin((c) => ({ ...c!, title: v })), { max: 120 })}
                  {text('login-highlight', 'Título resaltado', login.highlight, (v) => setLogin((c) => ({ ...c!, highlight: v || null })), { max: 80 })}
                </div>
                {text('login-description', 'Descripción', login.description, (v) => setLogin((c) => ({ ...c!, description: v || null })), { max: 300, area: true })}
                <LookCards cards={login.cards} hint="Aparecen en el panel de presentación." onChange={(cards) => setLogin((c) => ({ ...c!, cards }))} />
                <div className="space-y-2 pt-4 border-t border-slate-100">
                  <span className="block text-sm font-semibold text-slate-800">Fondo del panel de presentación</span>
                  <div className="grid grid-cols-2 md:grid-cols-3 gap-2" role="radiogroup" aria-label="Fondo del panel">
                    {LOGIN_BACKGROUNDS.map((b) => (
                      <OptionCard key={b.id} type="button" role="radio" aria-checked={login.background === b.id} data-background={b.id} selected={login.background === b.id} onClick={() => setLogin((c) => ({ ...c!, background: b.id as LoginDraft['background'] }))} className="text-left rounded-xl border px-3 py-2 text-sm font-semibold">
                        {b.label}
                      </OptionCard>
                    ))}
                  </div>
                  {login.background === 'image' && imageSlot('login', 'Imagen de fondo', 'Se oscurece un poco para que el texto se lea. PNG, JPG o WEBP, hasta 3 MB, de al menos 600×400 px.')}
                </div>
                <div className="space-y-2">
                  <span className="block text-sm font-semibold text-slate-800">Posición del panel</span>
                  <div className="grid grid-cols-2 gap-2 max-w-sm" role="radiogroup" aria-label="Posición del panel">
                    {([['left', 'A la izquierda'], ['right', 'A la derecha']] as const).map(([id, label]) => (
                      <OptionCard key={id} type="button" role="radio" aria-checked={login.panel_side === id} data-panel-side={id} selected={login.panel_side === id} onClick={() => setLogin((c) => ({ ...c!, panel_side: id }))} className="text-left rounded-xl border px-3 py-2 text-sm font-semibold">
                        {label}
                      </OptionCard>
                    ))}
                  </div>
                  <span className="block text-xs text-slate-500">En el celular el panel no se muestra: solo el formulario.</span>
                </div>
                <p className="text-xs text-slate-500">El formulario es siempre correo y contraseña, igual para el personal y para los clientes.</p>
              </div>
            )}

            {current === 'maintenance' && (
              <div className="space-y-4">
                <p className="text-xs text-slate-500">Un aviso arriba de la portada, del ingreso y de todas las pantallas, para todas las empresas. Se oculta solo al vencer.</p>
                {text('maintenance-message', 'Mensaje', maintenance.message, (v) => setMaintenance((c) => ({ ...c!, message: v })), { max: 300, area: true, placeholder: 'El sistema estará en mantenimiento. Te recomendamos guardar tu trabajo antes.' })}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <span className={labelClass}>Desde (opcional)</span>
                    <div className="flex gap-2">
                      <DateField ariaLabel="Fecha de inicio" value={maintenance.startDate} onChange={(v) => setMaintenance((c) => ({ ...c!, startDate: v }))} />
                      <TimeField ariaLabel="Hora de inicio" value={maintenance.startTime} onChange={(v) => setMaintenance((c) => ({ ...c!, startTime: v }))} />
                    </div>
                  </div>
                  <div className="space-y-1.5">
                    <span className={labelClass}>Hasta</span>
                    <div className="flex gap-2">
                      <DateField ariaLabel="Fecha de fin" value={maintenance.endDate} min={maintenance.startDate || undefined} onChange={(v) => setMaintenance((c) => ({ ...c!, endDate: v }))} />
                      <TimeField ariaLabel="Hora de fin" value={maintenance.endTime} onChange={(v) => setMaintenance((c) => ({ ...c!, endTime: v }))} />
                    </div>
                  </div>
                </div>
                <p className="text-xs text-slate-500">Sin fecha de fin, el aviso queda hasta que borres el mensaje. Cada persona puede cerrarlo con la ×; vuelve a aparecer si cambias el mensaje.</p>
              </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100">
              <button type="button" data-reset-section onClick={() => setResetting(true)} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-brand-ink">
                <RotateCcw size={13} /> Restablecer valores originales
              </button>
              <div className="flex gap-2">
                {dirty && <Button type="button" variant="secondary" onClick={undo}>Deshacer cambios</Button>}
                <Button type="button" variant="primary" data-save-section disabled={busy || !dirty} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
              </div>
            </div>
          </section>

          <div className="2xl:sticky 2xl:top-4">
            <LivePreview pages={PAGES[current]} config={config} dirty={SECTIONS.some((s) => sectionDirty(s.id))} />
          </div>
        </div>
      </div>

      <AppModal
        isOpen={resetting}
        type="confirm"
        variant="danger"
        title={`¿Restablecer "${sectionInfo.label}"?`}
        message={current === 'brand' ? 'Vuelven el nombre, los colores y el pie de fábrica, y se quitan los logos. Se aplica para todos al instante.' : 'Vuelven los textos e imágenes de fábrica de esta sección. Se aplica para todos al instante.'}
        confirmText="Sí, restablecer"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={reset}
        onClose={() => setResetting(false)}
      />
    </div>
  );
}
