'use client';

import { useEffect, useState } from 'react';
import { BellRing, Building2, CalendarCheck, Check, ClipboardCheck, Hourglass, Loader2, Lock, Puzzle, Type, Unlock } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth, type AppointmentStatus } from '@/hooks/useAuth';
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
  appointment_statuses?: AppointmentStatus[];
  booking_rules?: BookingRules & { uses_confirmation: boolean };
}

type OnMiss = 'notify' | 'notify_release' | 'release';

interface BookingRules {
  // Horas antes de la cita en que debe estar confirmada; 0 = sin plazo.
  confirm_deadline_hours: number;
  confirm_on_miss: OnMiss;
  // En "avisar y luego liberar": a cuántas horas de la cita se libera.
  confirm_release_hours: number;
  cancel_window_hours: number;
}

// Qué puede hacer la empresa con una cita que nadie confirmó a tiempo.
const ON_MISS_OPTIONS: { id: OnMiss; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'notify', title: 'Solo avisar', hint: 'La cita se mantiene. Se avisa al equipo para que llame a confirmar.', icon: <BellRing size={18} /> },
  { id: 'notify_release', title: 'Avisar y luego liberar', hint: 'Primero se avisa. Si más cerca de la hora sigue sin confirmar, la cita se cancela y el horario queda libre.', icon: <Hourglass size={18} /> },
  { id: 'release', title: 'Liberar de inmediato', hint: 'Al vencer el plazo la cita se cancela y el horario queda libre.', icon: <Unlock size={18} /> },
];

const hoursInput = 'w-24 text-sm text-center border border-slate-200 rounded-lg px-3 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] outline-none';

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
  const [statusNames, setStatusNames] = useState<Record<string, string>>({});
  // Las horas se editan como texto para poder dejar el campo vacío mientras se escribe.
  const [bookingRules, setBookingRules] = useState<{ deadline: string; onMiss: OnMiss; release: string; cancel: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  const isAdmin = Boolean(user?.is_tenant_admin);

  const apply = (payload: SettingsPayload) => {
    setData(payload);
    setModulesOff(payload.modules.filter((m) => m.can_toggle && !m.enabled).map((m) => m.key));
    setVocabulary({ ...payload.vocabulary.overrides });
    // Solo los nombres que la empresa cambió; el resto queda vacío y usa el de por defecto.
    setStatusNames(Object.fromEntries(
      (payload.appointment_statuses ?? []).filter((s) => s.name !== s.default_name).map((s) => [s.key, s.name]),
    ));
    setBookingRules(payload.booking_rules ? {
      deadline: String(payload.booking_rules.confirm_deadline_hours),
      onMiss: payload.booking_rules.confirm_on_miss,
      release: String(payload.booking_rules.confirm_release_hours),
      cancel: String(payload.booking_rules.cancel_window_hours),
    } : null);
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

  // Solo dígitos, hasta tres: las horas son números enteros.
  const setHours = (field: 'deadline' | 'release' | 'cancel', value: string) => {
    setMessage(null);
    setBookingRules((current) => (current ? { ...current, [field]: value.replace(/\D/g, '').slice(0, 3) } : current));
  };

  const toggleModule = (key: string) => {
    setMessage(null);
    setModulesOff((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));
  };

  const handleSave = async () => {
    try {
      setSaving(true);
      setMessage(null);
      const res = await api.put('/tenant-settings', {
        modules_off: modulesOff,
        vocabulary,
        appointment_statuses: statusNames,
        ...(bookingRules ? {
          booking_rules: {
            confirm_deadline_hours: Number(bookingRules.deadline || 0),
            confirm_on_miss: bookingRules.onMiss,
            confirm_release_hours: Number(bookingRules.release || 0),
            cancel_window_hours: Number(bookingRules.cancel || 0),
          },
        } : {}),
      });
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

      {(data.appointment_statuses?.length ?? 0) > 0 && (
        <section className={cardClass}>
          <div>
            <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
              <CalendarCheck className="w-4 h-4 text-[#3F768C]" /> Fases de la cita
            </h2>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Las fases por las que pasa una cita son fijas. Puedes cambiar el nombre con que las ve tu equipo; deja un campo vacío para usar el nombre de siempre.
            </p>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            {data.appointment_statuses!.map((status, index) => (
              <div key={status.key}>
                <label className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                  {index + 1}. {status.default_name}
                </label>
                <input
                  type="text"
                  maxLength={30}
                  aria-label={`Nombre de la fase ${status.default_name}`}
                  value={statusNames[status.key] ?? ''}
                  onChange={(e) => { setMessage(null); setStatusNames({ ...statusNames, [status.key]: e.target.value }); }}
                  placeholder={status.default_name}
                  className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] outline-none"
                />
              </div>
            ))}
          </div>
        </section>
      )}

      {bookingRules && (
        <section className={cardClass} data-card="booking-rules">
          <div>
            <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
              <ClipboardCheck className="w-4 h-4 text-[#3F768C]" /> Reglas de reserva
            </h2>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Cuánto tiempo tienen tus clientes para confirmar y para cancelar, y qué hace el sistema con una cita que nadie confirmó.
            </p>
          </div>

          {!data.booking_rules?.uses_confirmation ? (
            <p className="text-sm text-slate-500">Tu rubro no usa el paso de confirmación: las citas se agendan ya confirmadas.</p>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="confirm-deadline" className="text-sm font-semibold text-slate-800">La cita debe estar confirmada</label>
                <input
                  id="confirm-deadline"
                  inputMode="numeric"
                  data-rule="confirm_deadline_hours"
                  value={bookingRules.deadline}
                  onChange={(e) => setHours('deadline', e.target.value)}
                  className={hoursInput}
                />
                <span className="text-sm text-slate-600">horas antes de su inicio.</span>
                <span className="w-full text-xs text-slate-500">
                  {Number(bookingRules.deadline || 0) === 0
                    ? 'Con 0 no hay plazo: una cita puede quedar pendiente hasta su hora.'
                    : 'Las citas agendadas con menos anticipación que este plazo no cuentan: nadie tuvo tiempo de confirmarlas.'}
                </span>
              </div>

              {Number(bookingRules.deadline || 0) > 0 && (
                <div className="space-y-2">
                  <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Si llega el plazo y sigue sin confirmar</span>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Si la cita no se confirma a tiempo">
                    {ON_MISS_OPTIONS.map((option) => {
                      const chosen = bookingRules.onMiss === option.id;
                      return (
                        <button
                          key={option.id}
                          type="button"
                          role="radio"
                          aria-checked={chosen}
                          data-on-miss={option.id}
                          onClick={() => { setMessage(null); setBookingRules({ ...bookingRules, onMiss: option.id }); }}
                          className={`flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors ${
                            chosen ? 'border-[#022340] bg-[#84D0D9]/15 ring-2 ring-[#022340]/10' : 'border-slate-200 hover:bg-slate-50'
                          }`}
                        >
                          <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-[#022340] text-[#84D0D9]' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                          <span>
                            <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                            <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                          </span>
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}

              {Number(bookingRules.deadline || 0) > 0 && bookingRules.onMiss === 'notify_release' && (
                <div className="flex flex-wrap items-center gap-3" data-row="release-hours">
                  <label htmlFor="confirm-release" className="text-sm font-semibold text-slate-800">Liberar la cita si sigue sin confirmar</label>
                  <input
                    id="confirm-release"
                    inputMode="numeric"
                    data-rule="confirm_release_hours"
                    value={bookingRules.release}
                    onChange={(e) => setHours('release', e.target.value)}
                    className={hoursInput}
                  />
                  <span className="text-sm text-slate-600">horas antes de su inicio.</span>
                  <span className="w-full text-xs text-slate-500">Debe ser un número menor que el plazo para confirmar.</span>
                </div>
              )}
            </>
          )}

          <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-100">
            <label htmlFor="cancel-window" className="text-sm font-semibold text-slate-800">El cliente puede cancelar o cambiar su cita hasta</label>
            <input
              id="cancel-window"
              inputMode="numeric"
              data-rule="cancel_window_hours"
              value={bookingRules.cancel}
              onChange={(e) => setHours('cancel', e.target.value)}
              className={hoursInput}
            />
            <span className="text-sm text-slate-600">horas antes de su inicio.</span>
            <span className="w-full text-xs text-slate-500">Pasado ese momento, en el portal ya no puede hacerlo y debe comunicarse con la sede.</span>
          </div>
        </section>
      )}

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
