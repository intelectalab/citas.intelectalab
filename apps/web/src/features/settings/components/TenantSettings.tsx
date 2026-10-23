'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import { useEffect, useState } from 'react';
import { BellRing, Building2, CalendarCheck, CalendarX2, Check, ClipboardCheck, DoorOpen, Eye, Link2, NotebookPen, ShieldBan, Hourglass, Loader2, Lock, Minus, PowerOff, Puzzle, Timer, Type, Unlock, Users, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth, type AppointmentStatus } from '@/hooks/useAuth';
import Switch from '@/app/components/common/Switch';
import Select from '@/app/components/common/Select';
import CurrencySettings from './CurrencySettings';
import IntegrationsSettings from './IntegrationsSettings';
import MessagingSettings from './MessagingSettings';
import DataExportsLog from './DataExportsLog';
import { currencySymbol } from '@/lib/currency';

interface SettingsModule {
  key: string;
  name: string;
  is_core: boolean;
  enabled: boolean;
  can_toggle: boolean;
  locked: 'core' | 'plan' | 'business_type' | 'platform' | 'no_subscription' | null;
}

interface SettingsPayload {
  tenant: { name: string; code: string; business_type_name: string | null; plan_name: string | null };
  modules: SettingsModule[];
  vocabulary: { defaults: Record<string, string>; overrides: Record<string, string> };
  dependents?: { enabled: boolean };
  appointment_statuses?: AppointmentStatus[];
  booking_rules?: BookingRules & { uses_confirmation: boolean };
  resources?: { equipment_down: EquipmentDown } | null;
  sales_rules?: { prices_include_tax: boolean; tax_rate: number; require_open_cash: boolean; partial_payments: boolean; cash_tolerance: number; auto_register?: boolean; refund_days?: number; pay_before_service?: boolean; arrive_minutes?: number } | null;
  booking_misses?: { action: MissAction; limit: number; block_days: number } | null;
  payment_rules?: { require_deposit: boolean; deposit_minutes: number; deposit_refund_hours: number; link_hours: number; booking: boolean } | null;
  session_idle_minutes?: number;
  session_portal_idle_minutes?: number;
  customer_groups?: { inactive_days: number; frequent_visits: number; vip_spend: number } | null;
}

type EquipmentDown = 'none' | 'observe' | 'disable';

const EQUIPMENT_DOWN_OPTIONS: { id: EquipmentDown; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'none', title: 'No pasa nada', hint: 'La sala sigue en uso y no se muestra ningún aviso.', icon: <Minus size={18} /> },
  { id: 'observe', title: 'Mostrarlo como observación', hint: 'La sala sigue en uso, con una nota que dice qué equipo suyo está fuera de uso.', icon: <Eye size={18} /> },
  { id: 'disable', title: 'Dejar la sala fuera de uso', hint: 'La sala pasa sola a "Fuera de uso" mientras el equipo no vuelva, y regresa sola cuando vuelve.', icon: <PowerOff size={18} /> },
];

type OnMiss = 'notify' | 'notify_release' | 'release';

type MissAction = 'none' | 'note' | 'block';

const MISS_OPTIONS: { id: MissAction; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'none', title: 'No pasa nada', hint: 'La cita queda como "No asistió" y nada más.', icon: <Minus size={18} /> },
  { id: 'note', title: 'Dejar una nota', hint: 'Su ficha y la lista de clientes muestran cuántas veces faltó a una cita reservada en línea.', icon: <NotebookPen size={18} /> },
  { id: 'block', title: 'Nota y dejar de reservar en línea', hint: 'Además, al llegar a cierto número de faltas ya no puede reservar por el portal. El personal sí puede agendarle.', icon: <ShieldBan size={18} /> },
];

interface BookingRules {
  confirm_deadline_hours: number;
  confirm_on_miss: OnMiss;
  confirm_release_hours: number;
  cancel_window_hours: number;
}

const ON_MISS_OPTIONS: { id: OnMiss; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'notify', title: 'Solo avisar', hint: 'La cita se mantiene. Se avisa al equipo para que llame a confirmar.', icon: <BellRing size={18} /> },
  { id: 'notify_release', title: 'Avisar y luego liberar', hint: 'Primero se avisa. Si más cerca de la hora sigue sin confirmar, la cita se cancela y el horario queda libre.', icon: <Hourglass size={18} /> },
  { id: 'release', title: 'Liberar de inmediato', hint: 'Al vencer el plazo la cita se cancela y el horario queda libre.', icon: <Unlock size={18} /> },
];

const IDLE_OPTIONS = [
  { value: '15', label: '15 minutos' },
  { value: '30', label: '30 minutos' },
  { value: '60', label: '1 hora' },
  { value: '120', label: '2 horas' },
  { value: '240', label: '4 horas' },
  { value: '480', label: '8 horas' },
  { value: 'custom', label: 'Personalizado' },
];

type IdleValue = { choice: string; custom: string };

const toIdle = (minutes: number): IdleValue => {
  const value = String(minutes);
  return IDLE_OPTIONS.some((o) => o.value === value) ? { choice: value, custom: '' } : { choice: 'custom', custom: value };
};

const fromIdle = (idle: IdleValue) => (idle.choice === 'custom' ? Number(idle.custom || 0) : Number(idle.choice));

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

export default function TenantSettings() {
  const { user, loading: authLoading, refetchUser, can } = useAuth();
  const [data, setData] = useState<SettingsPayload | null>(null);
  const [modulesOff, setModulesOff] = useState<string[]>([]);
  const [vocabulary, setVocabulary] = useState<Record<string, string>>({});
  const [statusNames, setStatusNames] = useState<Record<string, string>>({});
  const [bookingRules, setBookingRules] = useState<{ deadline: string; onMiss: OnMiss; release: string; cancel: string } | null>(null);
  const [equipmentDown, setEquipmentDown] = useState<EquipmentDown | null>(null);
  const [salesRules, setSalesRules] = useState<{ includeTax: boolean; taxRate: string; requireCash: boolean; partial: boolean; tolerance: string; autoRegister: boolean; refundDays: string; payFirst: boolean; arriveMinutes: string } | null>(null);
  const [misses, setMisses] = useState<{ action: MissAction; limit: string; days: string } | null>(null);
  const [paymentRules, setPaymentRules] = useState<{ requireDeposit: boolean; depositMinutes: string; refundHours: string; linkHours: string } | null>(null);
  const [idle, setIdle] = useState<IdleValue>({ choice: '30', custom: '' });
  const [portalIdle, setPortalIdle] = useState<{ on: boolean } & IdleValue>({ on: false, choice: '30', custom: '' });
  const [groups, setGroups] = useState<{ inactive: string; frequent: string; vip: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  const isAdmin = can('settings.view');
  const canSave = can('settings.edit');

  const apply = (payload: SettingsPayload) => {
    setData(payload);
    setModulesOff(payload.modules.filter((m) => m.can_toggle && !m.enabled).map((m) => m.key));
    setVocabulary({ ...payload.vocabulary.overrides });
    setStatusNames(Object.fromEntries(
      (payload.appointment_statuses ?? []).filter((s) => s.name !== s.default_name).map((s) => [s.key, s.name]),
    ));
    setBookingRules(payload.booking_rules ? {
      deadline: String(payload.booking_rules.confirm_deadline_hours),
      onMiss: payload.booking_rules.confirm_on_miss,
      release: String(payload.booking_rules.confirm_release_hours),
      cancel: String(payload.booking_rules.cancel_window_hours),
    } : null);
    setEquipmentDown(payload.resources ? payload.resources.equipment_down : null);
    setSalesRules(payload.sales_rules ? {
      includeTax: payload.sales_rules.prices_include_tax,
      taxRate: String(payload.sales_rules.tax_rate),
      requireCash: payload.sales_rules.require_open_cash,
      partial: payload.sales_rules.partial_payments,
      tolerance: String(payload.sales_rules.cash_tolerance),
      autoRegister: payload.sales_rules.auto_register ?? true,
      refundDays: String(payload.sales_rules.refund_days ?? 30),
      payFirst: payload.sales_rules.pay_before_service ?? true,
      arriveMinutes: String(payload.sales_rules.arrive_minutes ?? 15),
    } : null);
    setMisses(payload.booking_misses ? { action: payload.booking_misses.action, limit: String(payload.booking_misses.limit), days: String(payload.booking_misses.block_days) } : null);
    setPaymentRules(payload.payment_rules ? {
      requireDeposit: payload.payment_rules.require_deposit,
      depositMinutes: String(payload.payment_rules.deposit_minutes),
      refundHours: String(payload.payment_rules.deposit_refund_hours),
      linkHours: String(payload.payment_rules.link_hours),
    } : null);
    setGroups(payload.customer_groups ? { inactive: String(payload.customer_groups.inactive_days), frequent: String(payload.customer_groups.frequent_visits), vip: String(payload.customer_groups.vip_spend) } : null);
    setIdle(toIdle(payload.session_idle_minutes ?? 30));
    const portalMinutes = payload.session_portal_idle_minutes ?? 0;
    setPortalIdle(portalMinutes > 0 ? { on: true, ...toIdle(portalMinutes) } : { on: false, choice: '30', custom: '' });
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
        session_idle_minutes: fromIdle(idle),
        ...(groups ? { customer_groups: { inactive_days: Number(groups.inactive || 0), frequent_visits: Number(groups.frequent || 0), vip_spend: Number(groups.vip || 0) } } : {}),
        session_portal_idle_minutes: portalIdle.on ? fromIdle(portalIdle) : 0,
        ...(equipmentDown ? { equipment_down: equipmentDown } : {}),
        ...(salesRules ? {
          sales_rules: {
            prices_include_tax: salesRules.includeTax,
            tax_rate: Number(salesRules.taxRate || 0),
            require_open_cash: salesRules.requireCash,
            partial_payments: salesRules.partial,
            cash_tolerance: Number(salesRules.tolerance || 0),
            auto_register: salesRules.autoRegister,
            refund_days: Number(salesRules.refundDays || 0),
            pay_before_service: salesRules.payFirst,
            arrive_minutes: Number(salesRules.arriveMinutes || 0),
          },
        } : {}),
        ...(misses ? { booking_misses: { action: misses.action, limit: Number(misses.limit || 1), block_days: Number(misses.days || 0) } } : {}),
        ...(paymentRules ? {
          payment_rules: {
            require_deposit: paymentRules.requireDeposit,
            deposit_minutes: Number(paymentRules.depositMinutes || 0),
            deposit_refund_hours: Number(paymentRules.refundHours || 0),
            link_hours: Number(paymentRules.linkHours || 0),
          },
        } : {}),
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

  const renderIdle = (row: string, label: string, value: IdleValue, change: (next: IdleValue) => void, inputId: string) => (
    <div className="flex flex-wrap items-center gap-3" data-row={row}>
      <span className="text-sm font-semibold text-slate-800">{label}</span>
      <div className="w-48">
        <Select
          ariaLabel={label}
          options={IDLE_OPTIONS}
          value={value.choice}
          onChange={(e) => { if (e.target.value === '') return; setMessage(null); change({ choice: String(e.target.value), custom: value.custom || '30' }); }}
        />
      </div>
      {value.choice === 'custom' ? (
        <>
          <input id={inputId} inputMode="numeric" aria-label="Minutos sin actividad" value={value.custom} onChange={(e) => { setMessage(null); change({ ...value, custom: e.target.value.replace(/\D/g, '').slice(0, 4) }); }} className={hoursInput} />
          <span className="text-sm text-slate-600">minutos sin actividad.</span>
          <span className="w-full text-xs text-slate-500">Entre 5 minutos y 1440 (24 horas).</span>
        </>
      ) : (
        <span className="text-sm text-slate-600">sin actividad.</span>
      )}
    </div>
  );

  const toggleable = data.modules.filter((m) => m.can_toggle);
  const core = data.modules.filter((m) => m.locked === 'core');
  const unavailable = data.modules.filter((m) => !m.can_toggle && m.locked !== 'core' && m.locked !== 'business_type');

  return (
    <div className="space-y-6 pb-10 font-sans text-[#1F2937]">
      <div>
        <h1 className="text-2xl font-black text-[#022340] tracking-tight">Configuración de la empresa</h1>
        <p className="text-sm text-slate-500 font-medium">Ajusta los módulos y los nombres que usa tu equipo.</p>
      </div>

      <section className={cardClass}>
        <SectionTitle>
          <Building2 className="w-4 h-4 text-[#3F768C]" /> Empresa
        </SectionTitle>
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
          <SectionTitle>
            <Puzzle className="w-4 h-4 text-[#3F768C]" /> Módulos
          </SectionTitle>
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
          <SectionTitle>
            <Type className="w-4 h-4 text-[#3F768C]" /> Vocabulario
          </SectionTitle>
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
            <SectionTitle>
              <CalendarCheck className="w-4 h-4 text-[#3F768C]" /> Fases de la cita
            </SectionTitle>
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
            <SectionTitle>
              <ClipboardCheck className="w-4 h-4 text-[#3F768C]" /> Reglas de reserva
            </SectionTitle>
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
                        <OptionCard
                          key={option.id}
                          type="button"
                          role="radio"
                          aria-checked={chosen}
                          data-on-miss={option.id}
                          onClick={() => { setMessage(null); setBookingRules({ ...bookingRules, onMiss: option.id }); }} selected={chosen} className="flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors"
                        >
                          <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-[#022340] text-[#84D0D9]' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                          <span>
                            <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                            <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                          </span>
                        </OptionCard>
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

      {equipmentDown && (
        <section className={cardClass} data-card="resources-rules">
          <div>
            <SectionTitle>
              <DoorOpen className="w-4 h-4 text-[#3F768C]" /> Salas y equipos
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Qué pasa con una sala cuando un equipo instalado en ella queda fuera de uso. La sala de cada equipo se indica al registrarlo.
            </p>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Si un equipo de una sala queda fuera de uso">
            {EQUIPMENT_DOWN_OPTIONS.map((option) => {
              const chosen = equipmentDown === option.id;
              return (
                <OptionCard
                  key={option.id}
                  type="button"
                  role="radio"
                  aria-checked={chosen}
                  data-equipment-down={option.id}
                  onClick={() => { setMessage(null); setEquipmentDown(option.id); }} selected={chosen} className="flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors"
                >
                  <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-[#022340] text-[#84D0D9]' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                  <span>
                    <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                    <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                  </span>
                </OptionCard>
              );
            })}
          </div>
        </section>
      )}

      <CurrencySettings />

      {salesRules && (
        <section className={cardClass} data-card="sales-rules">
          <div>
            <SectionTitle>
              <Wallet className="w-4 h-4 text-[#3F768C]" /> Ventas y caja
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Cómo se calcula el impuesto al cobrar y cómo se maneja el efectivo. Lo que cambies rige para las ventas nuevas; las ya creadas conservan su cálculo.
            </p>
          </div>

          <div className="divide-y divide-slate-100">
            {([
              ['payFirst', 'Cobrar antes de atender', 'La cita no pasa a "En atención" ni se da por atendida si no está pagada. Si lo apagas, se puede atender primero y cobrar después; Ventas mostrará las citas atendidas sin cobrar.'],
              ['includeTax', 'Los precios ya incluyen el IGV', 'El precio del servicio es lo que paga el cliente; el impuesto se desglosa en el comprobante. Si lo apagas, el IGV se suma encima del precio.'],
              ['requireCash', 'Exigir caja abierta para cobrar en efectivo', 'Sin un turno de caja abierto en la sede no se puede cobrar en efectivo. Si lo apagas, el cobro se registra igual, sin caja.'],
              ['partial', 'Aceptar pagos por partes', 'Una cita se puede cobrar en varios pagos o con dos medios. Si lo apagas, se cobra el total de una vez.'],
              ['autoRegister', 'Crear una caja en cada sucursal nueva', 'Cada sucursal nace con su "Caja principal". Si lo apagas, las cajas de las sucursales nuevas las creas tú desde Caja; las que ya existen no se tocan.'],
            ] as const).map(([key, title, hint]) => (
              <div key={key} className="flex items-center justify-between gap-4 py-3 first:pt-0">
                <div>
                  <span className="block text-sm font-semibold text-slate-800">{title}</span>
                  <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{hint}</span>
                </div>
                <Switch checked={salesRules[key]} onChange={(on) => { setMessage(null); setSalesRules({ ...salesRules, [key]: on }); }} label={title} />
              </div>
            ))}
          </div>

          {salesRules.payFirst && (
            <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-100" data-row="arrive-minutes">
              <label htmlFor="arrive-minutes" className="text-sm font-semibold text-slate-800">Pedir al cliente que llegue</label>
              <input
                id="arrive-minutes"
                inputMode="numeric"
                data-rule="arrive_minutes"
                value={salesRules.arriveMinutes}
                onChange={(e) => { setMessage(null); setSalesRules({ ...salesRules, arriveMinutes: e.target.value.replace(/\D/g, '').slice(0, 3) }); }}
                className={hoursInput}
              />
              <span className="text-sm text-slate-600">minutos antes de su hora, para pagar.</span>
              <span className="w-full text-xs text-slate-500">Se le dice en el portal al reservar y en cada cita que aún no ha pagado. Con 0 solo se le avisa que se paga antes de la atención.</span>
            </div>
          )}

          <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-100">
            <label htmlFor="tax-rate" className="text-sm font-semibold text-slate-800">El IGV es de</label>
            <input
              id="tax-rate"
              inputMode="decimal"
              data-rule="tax_rate"
              value={salesRules.taxRate}
              onChange={(e) => { setMessage(null); setSalesRules({ ...salesRules, taxRate: e.target.value.replace(/[^\d.]/g, '').slice(0, 5) }); }}
              className={hoursInput}
            />
            <span className="text-sm text-slate-600">%.</span>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <label htmlFor="refund-days" className="text-sm font-semibold text-slate-800">Una venta se puede devolver hasta</label>
            <input
              id="refund-days"
              inputMode="numeric"
              data-rule="refund_days"
              value={salesRules.refundDays}
              onChange={(e) => { setMessage(null); setSalesRules({ ...salesRules, refundDays: e.target.value.replace(/\D/g, '').slice(0, 3) }); }}
              className={hoursInput}
            />
            <span className="text-sm text-slate-600">días después.</span>
            <span className="w-full text-xs text-slate-500">Pasado ese plazo ya no se registra la devolución. Con 0 no hay plazo.</span>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <label htmlFor="cash-tolerance" className="text-sm font-semibold text-slate-800">Al cerrar caja se tolera una diferencia de hasta {currencySymbol()}</label>
            <input
              id="cash-tolerance"
              inputMode="decimal"
              data-rule="cash_tolerance"
              value={salesRules.tolerance}
              onChange={(e) => { setMessage(null); setSalesRules({ ...salesRules, tolerance: e.target.value.replace(/[^\d.]/g, '').slice(0, 7) }); }}
              className={hoursInput}
            />
            <span className="w-full text-xs text-slate-500">
              Si lo contado difiere en más que eso, quien cierra debe explicarlo y la administración recibe un aviso. Con 0, cualquier diferencia se explica.
            </span>
          </div>
        </section>
      )}

      {paymentRules && (
        <section className={cardClass} data-card="payment-rules">
          <div>
            <SectionTitle>
              <Link2 className="w-4 h-4 text-[#3F768C]" /> Adelantos y links de pago
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Cuánto dura un link de pago y qué pasa con el adelanto de quien reserva en línea. Cuánto es el adelanto se elige en cada servicio.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <label htmlFor="link-hours" className="text-sm font-semibold text-slate-800">Un link de pago vence</label>
            <input id="link-hours" inputMode="numeric" data-rule="link_hours" value={paymentRules.linkHours} onChange={(e) => { setMessage(null); setPaymentRules({ ...paymentRules, linkHours: e.target.value.replace(/\D/g, '').slice(0, 3) }); }} className={hoursInput} />
            <span className="text-sm text-slate-600">horas después de crearlo.</span>
            <span className="w-full text-xs text-slate-500">Pasado ese tiempo el cliente ya no puede pagar con ese link y hay que crearle uno nuevo.</span>
          </div>

          {data.payment_rules?.booking && (
            <>
              <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
                <div>
                  <span className="block text-sm font-semibold text-slate-800">Pedir el adelanto al reservar en línea</span>
                  <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">Solo en los servicios marcados con &quot;Exige adelanto&quot;. Si lo apagas, esos servicios se reservan sin pagar nada.</span>
                </div>
                <Switch checked={paymentRules.requireDeposit} onChange={(on) => { setMessage(null); setPaymentRules({ ...paymentRules, requireDeposit: on }); }} label="Pedir el adelanto al reservar en línea" />
              </div>

              {paymentRules.requireDeposit && (
                <>
                  <div className="flex flex-wrap items-center gap-3" data-row="deposit-minutes">
                    <label htmlFor="deposit-minutes" className="text-sm font-semibold text-slate-800">El cliente tiene</label>
                    <input id="deposit-minutes" inputMode="numeric" data-rule="deposit_minutes" value={paymentRules.depositMinutes} onChange={(e) => { setMessage(null); setPaymentRules({ ...paymentRules, depositMinutes: e.target.value.replace(/\D/g, '').slice(0, 4) }); }} className={hoursInput} />
                    <span className="text-sm text-slate-600">minutos para pagar el adelanto.</span>
                    <span className="w-full text-xs text-slate-500">
                      El horario no se aparta mientras tanto: la cita existe recién cuando paga. Si otra persona toma ese horario antes, su link se anula y no se le cobra nada.
                    </span>
                  </div>

                  <div className="flex flex-wrap items-center gap-3" data-row="refund-hours">
                    <label htmlFor="refund-hours" className="text-sm font-semibold text-slate-800">Se le devuelve el adelanto si cancela con al menos</label>
                    <input id="refund-hours" inputMode="numeric" data-rule="deposit_refund_hours" value={paymentRules.refundHours} onChange={(e) => { setMessage(null); setPaymentRules({ ...paymentRules, refundHours: e.target.value.replace(/\D/g, '').slice(0, 3) }); }} className={hoursInput} />
                    <span className="text-sm text-slate-600">horas de anticipación.</span>
                    <span className="w-full text-xs text-slate-500">
                      Ejemplo con 24 horas: si su cita es el sábado a las 10:00, se le devuelve si cancela hasta el viernes a las 10:00. Si cancela después o no asiste, el adelanto queda para la empresa. Con 0, siempre se devuelve si cancela antes de la cita.
                    </span>
                  </div>
                </>
              )}
            </>
          )}
        </section>
      )}

      {paymentRules && <IntegrationsSettings canSave={canSave} />}

      <MessagingSettings canSave={canSave} company={user?.tenant?.name ?? ''} />

      {misses && (
        <section className={cardClass} data-card="booking-misses">
          <div>
            <SectionTitle>
              <CalendarX2 className="w-4 h-4 text-[#3F768C]" /> Si reserva en línea y no asiste
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Qué pasa con un cliente que reservó desde el portal y su cita terminó en "No asistió". Las citas que agenda tu equipo no cuentan aquí.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Si reserva en línea y no asiste">
            {MISS_OPTIONS.map((option) => {
              const chosen = misses.action === option.id;
              return (
                <OptionCard
                  key={option.id}
                  type="button"
                  role="radio"
                  aria-checked={chosen}
                  data-miss-action={option.id}
                  onClick={() => { setMessage(null); setMisses({ ...misses, action: option.id }); }} selected={chosen} className="flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors"
                >
                  <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-[#022340] text-[#84D0D9]' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                  <span>
                    <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                    <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                  </span>
                </OptionCard>
              );
            })}
          </div>

          {misses.action === 'block' && (
            <div className="flex flex-wrap items-center gap-3" data-row="miss-block">
              <label htmlFor="miss-limit" className="text-sm font-semibold text-slate-800">Deja de reservar en línea al faltar</label>
              <input id="miss-limit" inputMode="numeric" data-rule="miss_limit" value={misses.limit} onChange={(e) => { setMessage(null); setMisses({ ...misses, limit: e.target.value.replace(/\D/g, '').slice(0, 2) }); }} className={hoursInput} />
              <span className="text-sm text-slate-600">{Number(misses.limit || 1) === 1 ? 'vez' : 'veces'}, durante</span>
              <input id="miss-days" inputMode="numeric" aria-label="Días sin reservar en línea" data-rule="miss_days" value={misses.days} onChange={(e) => { setMessage(null); setMisses({ ...misses, days: e.target.value.replace(/\D/g, '').slice(0, 4) }); }} className={hoursInput} />
              <span className="text-sm text-slate-600">días.</span>
              <span className="w-full text-xs text-slate-500">
                {Number(misses.days || 0) === 0
                  ? 'Con 0 días no vuelve a reservar en línea hasta que tú se lo permitas desde su ficha.'
                  : 'Los días se cuentan desde su última falta. Puedes perdonarle antes desde su ficha.'}
              </span>
            </div>
          )}
        </section>
      )}

      {groups && (
        <section className={cardClass} data-card="customer-groups">
          <div>
            <SectionTitle>
              <Users className="w-4 h-4 text-[#3F768C]" /> Grupos de clientes
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Definen a quiénes les llega una campaña cuando eliges un grupo. Se recalculan solos con cada visita y cada pago.
            </p>
          </div>

          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3" data-row="group-inactive">
              <span className="text-sm font-semibold text-slate-800 w-28">Inactivo</span>
              <span className="text-sm text-slate-600">si no viene hace</span>
              <input aria-label="Días para inactivo" inputMode="numeric" data-rule="inactive_days" value={groups.inactive} onChange={(e) => { setMessage(null); setGroups({ ...groups, inactive: e.target.value.replace(/\D/g, '').slice(0, 3) }); }} className={hoursInput} />
              <span className="text-sm text-slate-600">días o más y no tiene una cita próxima.</span>
              <span className="w-full text-xs text-slate-500 sm:pl-[7.75rem]">&quot;En riesgo de no volver&quot; son los que llevan dos tercios de ese tiempo ({Math.ceil(Number(groups.inactive || 0) * 0.66)} días).</span>
            </div>
            <div className="flex flex-wrap items-center gap-3" data-row="group-frequent">
              <span className="text-sm font-semibold text-slate-800 w-28">Frecuente</span>
              <span className="text-sm text-slate-600">si vino</span>
              <input aria-label="Visitas para frecuente" inputMode="numeric" data-rule="frequent_visits" value={groups.frequent} onChange={(e) => { setMessage(null); setGroups({ ...groups, frequent: e.target.value.replace(/\D/g, '').slice(0, 2) }); }} className={hoursInput} />
              <span className="text-sm text-slate-600">veces o más en los últimos 90 días.</span>
            </div>
            <div className="flex flex-wrap items-center gap-3" data-row="group-vip">
              <span className="text-sm font-semibold text-slate-800 w-28">VIP</span>
              <span className="text-sm text-slate-600">si pagó {currencySymbol()}</span>
              <input aria-label="Monto para VIP" inputMode="decimal" data-rule="vip_spend" value={groups.vip} onChange={(e) => { setMessage(null); setGroups({ ...groups, vip: e.target.value.replace(/[^\d.]/g, '').slice(0, 9) }); }} className={hoursInput} />
              <span className="text-sm text-slate-600">o más en los últimos 12 meses.</span>
            </div>
          </div>
        </section>
      )}

      <section className={cardClass} data-card="session-idle">
        <div>
          <SectionTitle>
            <Timer className="w-4 h-4 text-[#3F768C]" /> Seguridad
          </SectionTitle>
          <p className="text-xs text-slate-500 font-medium mt-1">
            Si nadie mueve el mouse, escribe ni toca la pantalla durante ese tiempo, la sesión se cierra sola. Un minuto antes aparece un aviso para seguir conectado.
          </p>
        </div>

        {renderIdle('session-idle', 'Cerrar la sesión de tu equipo tras', idle, (next) => setIdle(next), 'idle-minutes')}

        <div className="pt-4 border-t border-slate-100 space-y-3" data-row="portal-idle-switch">
          <div className="flex items-center justify-between gap-4">
            <span>
              <span className="block text-sm font-semibold text-slate-800">Desconectar también a los clientes del portal</span>
              <span className="block text-xs text-slate-500 mt-0.5">Apagado, el cliente sigue conectado en su celular hasta que cierre sesión.</span>
            </span>
            <Switch checked={portalIdle.on} onChange={(on) => { setMessage(null); setPortalIdle({ ...portalIdle, on }); }} label="Desconectar también a los clientes del portal" />
          </div>
          {portalIdle.on && renderIdle('portal-idle', 'Cerrar la sesión del cliente tras', portalIdle, (next) => setPortalIdle({ ...portalIdle, ...next }), 'portal-idle-minutes')}
        </div>
      </section>

      {can('users.view') && <DataExportsLog className={cardClass} />}

      <div className="flex items-center justify-end gap-4">
        {message && (
          <span className={`text-sm font-medium ${message.type === 'ok' ? 'text-emerald-600' : 'text-red-600'}`}>{message.text}</span>
        )}
        <Button
          type="button"
          onClick={handleSave}
          disabled={saving || !canSave}
          title={canSave ? undefined : 'Tu cuenta puede ver la configuración, pero no cambiarla.'} variant="primary"
        >
          {saving ? 'Guardando...' : 'Guardar cambios'}
        </Button>
      </div>
    </div>
  );
}
