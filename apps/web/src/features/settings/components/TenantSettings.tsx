'use client';

import { useEffect, useState } from 'react';
import { Building2, Check, Loader2, Lock, Puzzle, Type } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import Switch from '@/app/components/common/Switch';

interface SettingsModule {
  key: string;
  name: string;
  is_core: boolean;
  enabled: boolean;
  can_toggle: boolean;
  // Por qué la empresa no puede cambiarlo: módulo base, plan, rubro o sin suscripción.
  locked: 'core' | 'plan' | 'business_type' | 'platform' | 'no_subscription' | null;
}

interface SettingsPayload {
  tenant: { name: string; code: string; business_type_name: string | null; plan_name: string | null };
  modules: SettingsModule[];
  vocabulary: { defaults: Record<string, string>; overrides: Record<string, string> };
  // Los términos de dependientes solo se editan si el rubro los usa.
  dependents?: { enabled: boolean };
}

const VOCABULARY_FIELDS: { key: string; label: string }[] = [
  { key: 'client', label: 'Cliente (singular)' },
  { key: 'clients', label: 'Clientes (plural)' },
  { key: 'pro', label: 'Profesional (singular)' },
  { key: 'pros', label: 'Profesionales (plural)' },
  { key: 'dependent', label: 'Dependiente (singular)' },
  { key: 'dependents', label: 'Dependientes (plural)' },
];

const LOCK_REASONS: Record<string, string> = {
  plan: 'No incluido en tu plan',
  platform: 'Desactivado por la plataforma',
  no_subscription: 'Requiere una suscripción activa',
};

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

// Configuración que la empresa maneja por su cuenta. Solo la usa su
// administrador y solo dentro de lo que le dan su plan y su rubro: apaga y
// vuelve a encender módulos que ya tiene; no puede activar otros.
export default function TenantSettings() {
  const { user, loading: authLoading, refetchUser } = useAuth();
  const [data, setData] = useState<SettingsPayload | null>(null);
  const [modulesOff, setModulesOff] = useState<string[]>([]);
  const [vocabulary, setVocabulary] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  const isAdmin = Boolean(user?.is_tenant_admin);

  const apply = (payload: SettingsPayload) => {
    setData(payload);
    setModulesOff(payload.modules.filter((m) => m.can_toggle && !m.enabled).map((m) => m.key));
    setVocabulary({ ...payload.vocabulary.overrides });
  };

  useEffect(() => {
    if (authLoading) return;

    if (!isAdmin) {
      setLoading(false);
      return;
    }

    api.get('/tenant-settings')
      .then((res) => apply(res.data))
      .catch(() => setMessage({ type: 'error', text: 'No se pudo cargar la configuración.' }))
      .finally(() => setLoading(false));
  }, [authLoading, isAdmin]);

  const toggleModule = (key: string) => {
    setMessage(null);
    setModulesOff((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));
  };

  const handleSave = async () => {
    try {
      setSaving(true);
      setMessage(null);
      const res = await api.put('/tenant-settings', { modules_off: modulesOff, vocabulary });
      apply(res.data);
      // El menú y los nombres dependen de estos datos.
      await refetchUser();
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

  if (!isAdmin || !data) {
    return (
      <div className="max-w-2xl mx-auto pt-10 font-sans text-[#1F2937]">
        <div className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-8 flex items-start gap-4">
          <span className="p-3 bg-[#84D0D9]/20 text-[#022340] rounded-2xl shrink-0">
            <Lock className="w-6 h-6" />
          </span>
          <div className="space-y-2">
            <h1 className="text-xl font-black text-[#022340] tracking-tight">Configuración de la empresa</h1>
            <p className="text-sm text-slate-500 font-medium leading-relaxed">
              {message?.text ?? 'Solo el administrador de la empresa puede ver y cambiar esta configuración.'}
            </p>
          </div>
        </div>
      </div>
    );
  }

  const toggleable = data.modules.filter((m) => m.can_toggle);
  const core = data.modules.filter((m) => m.locked === 'core');
  // Lo que su rubro no usa no se lista: la empresa no puede hacer nada al respecto.
  const unavailable = data.modules.filter((m) => !m.can_toggle && m.locked !== 'core' && m.locked !== 'business_type');

  return (
    <div className="space-y-6 pb-10 font-sans text-[#1F2937]">
      <div>
        <h1 className="text-2xl font-black text-[#022340] tracking-tight">Configuración de la empresa</h1>
        <p className="text-sm text-slate-500 font-medium">Ajusta los módulos y los nombres que usa tu equipo.</p>
      </div>

      <section className={cardClass}>
        <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
          <Building2 className="w-4 h-4 text-[#3F768C]" /> Empresa
        </h2>
        <dl className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          {[
            ['Nombre', data.tenant.name],
            ['Rubro', data.tenant.business_type_name ?? '—'],
            ['Plan', data.tenant.plan_name ?? 'Sin plan activo'],
            ['Código de empresa', data.tenant.code],
          ].map(([label, value]) => (
            <div key={label}>
              <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">{label}</dt>
              <dd className="font-semibold text-slate-800">{value}</dd>
            </div>
          ))}
        </dl>
        <p className="text-xs text-slate-400">
          Tus clientes usan el código de empresa para crear su cuenta. El rubro y el plan los cambia el equipo de la plataforma.
        </p>
      </section>

      <section className={cardClass}>
        <div>
          <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
            <Puzzle className="w-4 h-4 text-[#3F768C]" /> Módulos
          </h2>
          <p className="text-xs text-slate-500 font-medium mt-1">
            Puedes apagar los módulos que no uses y volver a encenderlos cuando quieras. Al apagar uno deja de verse en el menú de todo tu equipo.
          </p>
        </div>

        {toggleable.length === 0 ? (
          <p className="text-sm text-slate-500">Tu empresa no tiene módulos opcionales disponibles.</p>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
            {toggleable.map((module) => {
              const isOn = !modulesOff.includes(module.key);
              return (
                <label
                  key={module.key}
                  className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3 cursor-pointer hover:bg-slate-50 transition-colors"
                >
                  <span className={`text-sm font-semibold ${isOn ? 'text-slate-800' : 'text-slate-400'}`}>{module.name}</span>
                  <Switch checked={isOn} onChange={() => toggleModule(module.key)} label={module.name} />
                </label>
              );
            })}
          </div>
        )}

        {unavailable.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2 border-t border-slate-100">
            <div className="space-y-2">
              <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">No disponibles para tu empresa</span>
              <ul className="space-y-1.5">
                {unavailable.map((module) => (
                  <li key={module.key} className="flex items-center justify-between gap-2 text-sm text-slate-400">
                    <span className="flex items-center gap-2">
                      <Lock className="w-3.5 h-3.5 shrink-0" /> {module.name}
                    </span>
                    <span className="text-[11px] font-medium shrink-0">{module.locked ? LOCK_REASONS[module.locked] : ''}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        )}
      </section>

      <section className={cardClass}>
        <div>
          <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
            <Type className="w-4 h-4 text-[#3F768C]" /> Vocabulario
          </h2>
          <p className="text-xs text-slate-500 font-medium mt-1">
            Cómo se nombra a tus clientes y a tu equipo en el menú y en las pantallas. Deja un campo vacío para usar el término de tu rubro.
          </p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {VOCABULARY_FIELDS.filter((field) => data.dependents?.enabled || !field.key.startsWith('dependent')).map((field) => (
            <div key={field.key}>
              <label className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">{field.label}</label>
              <input
                type="text"
                maxLength={40}
                value={vocabulary[field.key] ?? ''}
                onChange={(e) => { setMessage(null); setVocabulary({ ...vocabulary, [field.key]: e.target.value }); }}
                placeholder={data.vocabulary.defaults[field.key]}
                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] outline-none"
              />
            </div>
          ))}
        </div>
      </section>

      <div className="flex items-center justify-end gap-4">
        {message && (
          <span className={`text-sm font-medium ${message.type === 'ok' ? 'text-emerald-600' : 'text-red-600'}`}>{message.text}</span>
        )}
        <button
          type="button"
          onClick={handleSave}
          disabled={saving}
          className="px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
        >
          {saving ? 'Guardando...' : 'Guardar cambios'}
        </button>
      </div>
    </div>
  );
}
