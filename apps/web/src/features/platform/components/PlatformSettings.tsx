'use client';

import { useEffect, useState } from 'react';
import { Check, DoorOpen, Gauge, LifeBuoy, Loader2, Lock, Palette, RotateCcw, Tag } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { usePlatform } from '@/hooks/usePlatform';
import Switch from '@/app/components/common/Switch';
import PlatformCurrencies from './PlatformCurrencies';

interface PlatformValues {
  product_name: string;
  support_email: string | null;
  accent_color: string;
  usage_warning_percent: number;
  usage_danger_percent: number;
  allow_tenant_signup: boolean;
  impersonation_hours: number;
}

const ACCENT_PRESETS: { name: string; value: string }[] = [
  { name: 'Verde', value: '#10B981' },
  { name: 'Turquesa', value: '#14B8A6' },
  { name: 'Celeste', value: '#0EA5E9' },
  { name: 'Azul', value: '#3B82F6' },
  { name: 'Azul petróleo', value: '#3F768C' },
  { name: 'Índigo', value: '#6366F1' },
  { name: 'Violeta', value: '#8B5CF6' },
  { name: 'Naranja', value: '#FF7A00' },
];

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const labelClass = 'block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5';
const inputClass =
  'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] outline-none';

function Title({ icon, title, hint }: { icon: React.ReactNode; title: string; hint: string }) {
  return (
    <div>
      <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
        <span className="text-[#3F768C]">{icon}</span> {title}
      </h2>
      <p className="text-xs text-slate-500 font-medium mt-1">{hint}</p>
    </div>
  );
}

function RingSample({ percent, color, label }: { percent: number; color: string; label: string }) {
  const radius = 22;
  const circumference = 2 * Math.PI * radius;

  return (
    <div className="flex flex-col items-center gap-1">
      <svg width="58" height="58" viewBox="0 0 58 58" role="img" aria-label={`${label}: ${percent}%`}>
        <circle cx="29" cy="29" r={radius} fill="none" stroke="var(--color-slate-200, #e2e8f0)" strokeWidth="6" />
        <circle
          cx="29" cy="29" r={radius} fill="none" stroke={color} strokeWidth="6" strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={circumference * (1 - percent / 100)}
          transform="rotate(-90 29 29)"
        />
        <text x="29" y="33" textAnchor="middle" className="fill-[#022340] text-[11px] font-extrabold">{percent}%</text>
      </svg>
      <span className="text-[11px] font-semibold text-slate-500">{label}</span>
    </div>
  );
}

export default function PlatformSettings() {
  const { user, loading: authLoading } = useAuth();
  const { applyPlatform } = usePlatform();
  const isAdmin = Boolean(user?.is_saas_admin);

  const [values, setValues] = useState<PlatformValues | null>(null);
  const [defaults, setDefaults] = useState<PlatformValues | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);
  const [sampleOn, setSampleOn] = useState(true);

  useEffect(() => {
    if (authLoading) return;

    if (!isAdmin) {
      setLoading(false);
      return;
    }

    api.get('/admin/platform-settings')
      .then((res) => { setValues(res.data.values); setDefaults(res.data.defaults); })
      .catch(() => setMessage({ type: 'error', text: 'No se pudo cargar la configuración.' }))
      .finally(() => setLoading(false));
  }, [authLoading, isAdmin]);

  const set = <K extends keyof PlatformValues>(key: K, value: PlatformValues[K]) => {
    setMessage(null);
    setValues((prev) => (prev ? { ...prev, [key]: value } : prev));
  };

  const handleSave = async () => {
    if (!values) return;

    try {
      setSaving(true);
      setMessage(null);
      const res = await api.put('/admin/platform-settings', { ...values, support_email: values.support_email || null });
      setValues(res.data.values);
      applyPlatform(res.data.values);
      setMessage({ type: 'ok', text: 'Configuración guardada.' });
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setMessage({ type: 'error', text: first || err.response?.data?.message || 'No se pudo guardar la configuración.' });
    } finally {
      setSaving(false);
    }
  };

  if (authLoading || loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  if (!isAdmin || !values) {
    return (
      <div className="max-w-2xl mx-auto pt-10 font-sans text-[#1F2937]">
        <div className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-8 flex items-start gap-4">
          <span className="p-3 bg-[#84D0D9]/20 text-[#022340] rounded-2xl shrink-0"><Lock className="w-6 h-6" /></span>
          <div className="space-y-2">
            <h1 className="text-xl font-black text-[#022340] tracking-tight">Configuración de la plataforma</h1>
            <p className="text-sm text-slate-500 font-medium leading-relaxed">
              {message?.text ?? 'Solo el administrador SaaS puede ver y cambiar esta configuración.'}
            </p>
          </div>
        </div>
      </div>
    );
  }

  const accent = /^#[0-9A-Fa-f]{6}$/.test(values.accent_color) ? values.accent_color : '#10B981';
  const thresholdsOk = values.usage_warning_percent < values.usage_danger_percent;

  return (
    <div className="space-y-5 pb-10 font-sans text-[#1F2937]">
      <div>
        <h1 className="text-2xl font-black text-[#022340] tracking-tight">Configuración de la plataforma</h1>
        <p className="text-sm text-slate-500 font-medium">Lo que aplica a todo el SaaS, no a una empresa en particular.</p>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-5">
        <section className={`xl:col-span-7 ${card}`}>
          <Title
            icon={<Palette size={16} />}
            title="Color de acento"
            hint="El color de lo que está encendido o va bien: interruptores y anillos de uso. Se ve en todas las pantallas, también en las de las empresas."
          />

          <div className="flex flex-wrap gap-2">
            {ACCENT_PRESETS.map((preset) => {
              const selected = preset.value.toLowerCase() === accent.toLowerCase();
              return (
                <button
                  key={preset.value}
                  type="button"
                  onClick={() => set('accent_color', preset.value)}
                  title={preset.name}
                  aria-label={`${preset.name}${selected ? ' (elegido)' : ''}`}
                  aria-pressed={selected}
                  className={`w-10 h-10 rounded-xl flex items-center justify-center transition-transform cursor-pointer ${selected ? 'ring-2 ring-offset-2 ring-slate-400 scale-105' : 'hover:scale-105'}`}
                  style={{ backgroundColor: preset.value }}
                >
                  {selected && <Check size={16} className="text-white" />}
                </button>
              );
            })}
          </div>

          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className={labelClass} htmlFor="accent-picker">Otro color</label>
              <div className="flex items-center gap-2">
                <input id="accent-picker" type="color" value={accent} onChange={(e) => set('accent_color', e.target.value.toUpperCase())} className="w-11 h-11 border border-slate-200 rounded-xl bg-white px-1 cursor-pointer" />
                <input
                  type="text"
                  value={values.accent_color}
                  onChange={(e) => set('accent_color', e.target.value)}
                  maxLength={7}
                  aria-label="Código del color"
                  className={`${inputClass} w-32 font-mono`}
                />
              </div>
            </div>
            {defaults && values.accent_color.toLowerCase() !== defaults.accent_color.toLowerCase() && (
              <button type="button" onClick={() => set('accent_color', defaults.accent_color)} className="inline-flex items-center gap-1.5 px-3 py-2.5 text-xs font-bold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 cursor-pointer">
                <RotateCcw size={13} /> Volver al verde predeterminado
              </button>
            )}
          </div>

          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4" style={{ ['--accent' as string]: accent }}>
            <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-3">Vista previa</span>
            <div className="flex flex-wrap items-center gap-8">
              <div className="flex items-center gap-3">
                <Switch checked={sampleOn} onChange={setSampleOn} label="Interruptor de muestra" />
                <span className="text-sm font-medium text-slate-700">{sampleOn ? 'Encendido' : 'Apagado'}</span>
              </div>
              <RingSample percent={35} color={accent} label="Va bien" />
              <RingSample percent={Math.min(99, values.usage_warning_percent + 5)} color="#F59E0B" label="Aviso" />
              <RingSample percent={Math.min(100, values.usage_danger_percent + 4)} color="#F43F5E" label="Alerta" />
            </div>
          </div>
        </section>

        <section className={`xl:col-span-5 ${card}`}>
          <Title
            icon={<Gauge size={16} />}
            title="Avisos de uso"
            hint="Desde qué porcentaje del límite de su plan el uso de una empresa cambia de color en su ficha."
          />
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={labelClass} htmlFor="warn">Aviso (ámbar) desde</label>
              <div className="flex items-stretch border border-slate-200 rounded-xl overflow-hidden bg-white">
                <input id="warn" type="number" min="1" max="99" value={values.usage_warning_percent} onChange={(e) => set('usage_warning_percent', Number(e.target.value))} className="w-full text-sm px-3.5 py-2.5 text-slate-800 outline-none bg-transparent" />
                <span className="flex items-center px-3 text-xs font-semibold text-slate-500 bg-slate-50 border-l border-slate-200">%</span>
              </div>
            </div>
            <div>
              <label className={labelClass} htmlFor="danger">Alerta (rojo) desde</label>
              <div className="flex items-stretch border border-slate-200 rounded-xl overflow-hidden bg-white">
                <input id="danger" type="number" min="2" max="100" value={values.usage_danger_percent} onChange={(e) => set('usage_danger_percent', Number(e.target.value))} className="w-full text-sm px-3.5 py-2.5 text-slate-800 outline-none bg-transparent" />
                <span className="flex items-center px-3 text-xs font-semibold text-slate-500 bg-slate-50 border-l border-slate-200">%</span>
              </div>
            </div>
          </div>
          {!thresholdsOk && <p className="text-xs font-medium text-red-600">El aviso debe empezar antes que la alerta.</p>}
          <p className="text-xs text-slate-400">
            Por debajo del aviso se usa el color de acento. Los colores de aviso y alerta son fijos para que siempre se lean como advertencia.
          </p>
        </section>

        <section className={`xl:col-span-5 ${card}`}>
          <Title icon={<Tag size={16} />} title="Marca y contacto" hint="El nombre que se ve en el menú y a dónde escribir si alguien necesita ayuda." />
          <div>
            <label className={labelClass} htmlFor="product">Nombre del producto</label>
            <input id="product" type="text" maxLength={60} value={values.product_name} onChange={(e) => set('product_name', e.target.value)} className={inputClass} />
          </div>
          <div>
            <label className={labelClass} htmlFor="support">Correo de soporte</label>
            <input id="support" type="email" maxLength={190} value={values.support_email ?? ''} onChange={(e) => set('support_email', e.target.value)} className={inputClass} placeholder="soporte@tuempresa.com" />
          </div>
        </section>

        <section className={`xl:col-span-4 ${card}`}>
          <Title icon={<DoorOpen size={16} />} title="Registro de empresas" hint="Si cualquiera puede crear su empresa desde la página de registro." />
          <label className="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 px-4 py-3.5 cursor-pointer">
            <span>
              <span className="block text-sm font-semibold text-slate-800">{values.allow_tenant_signup ? 'Registro abierto' : 'Registro cerrado'}</span>
              <span className="block text-xs text-slate-400">
                {values.allow_tenant_signup ? 'Las empresas se registran solas y empiezan su prueba.' : 'Nadie puede registrarse; las empresas las crea la plataforma.'}
              </span>
            </span>
            <Switch checked={values.allow_tenant_signup} onChange={(checked) => set('allow_tenant_signup', checked)} label="Registro de empresas abierto" />
          </label>
        </section>

        <section className={`xl:col-span-3 ${card}`}>
          <Title icon={<LifeBuoy size={16} />} title="Sesión de soporte" hint="Cuánto dura la sesión al entrar al panel de una empresa." />
          <div>
            <label className={labelClass} htmlFor="hours">Duración</label>
            <div className="flex items-stretch border border-slate-200 rounded-xl overflow-hidden bg-white">
              <input id="hours" type="number" min="1" max="24" value={values.impersonation_hours} onChange={(e) => set('impersonation_hours', Number(e.target.value))} className="w-full text-sm px-3.5 py-2.5 text-slate-800 outline-none bg-transparent" />
              <span className="flex items-center px-3 text-xs font-semibold text-slate-500 bg-slate-50 border-l border-slate-200">horas</span>
            </div>
          </div>
        </section>
      </div>

      <div className="flex items-center justify-end gap-4">
        {message && (
          <span className={`text-sm font-medium ${message.type === 'ok' ? 'text-emerald-600' : 'text-red-600'}`}>{message.text}</span>
        )}
        <button
          type="button"
          onClick={handleSave}
          disabled={saving || !thresholdsOk}
          className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50 cursor-pointer"
        >
          {saving ? 'Guardando...' : 'Guardar cambios'}
        </button>
      </div>

      <PlatformCurrencies />
    </div>
  );
}
