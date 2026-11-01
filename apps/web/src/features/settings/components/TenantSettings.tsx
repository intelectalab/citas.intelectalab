'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { BellRing, Boxes, Building2, Calculator, CalendarCheck, CalendarX2, Check, CircleHelp, ClipboardCheck, DoorOpen, Eye, FileText, Gift, Link2, ListOrdered, NotebookPen, ShieldBan, Hourglass, Loader2, Lock, Minus, PowerOff, Puzzle, Timer, TrendingUp, Unlock, Users, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth, type AppointmentStatus } from '@/hooks/useAuth';
import Switch from '@/app/components/common/Switch';
import Select from '@/app/components/common/Select';
import CurrencySettings from './CurrencySettings';
import IntegrationsSettings from './IntegrationsSettings';
import MessagingSettings from './MessagingSettings';
import DataExportsLog from './DataExportsLog';
import { currencySymbol } from '@/lib/currency';
import { MODULE_HINTS } from '@/lib/modules';
import InfoTip from '@/app/components/common/InfoTip';

interface SettingsModule {
  key: string;
  name: string;
  is_core: boolean;
  enabled: boolean;
  can_toggle: boolean;
  locked: 'core' | 'plan' | 'platform' | 'no_subscription' | null;
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
  inventory_rules?: { allow_negative: boolean; consume_on_service: boolean; transfer_two_step: boolean; cost_method: CostMethod; alert_min_stock: boolean } | null;
  loyalty_rules?: { points_enabled: boolean; amount_per_point: number; point_value: number; min_redeem_points: number; points_validity_days: number; gift_cards_enabled: boolean; gift_card_validity_days: number } | null;
  quote_rules?: { validity_days: number; allow_installments: boolean; auto_create_sale: boolean; track_clinical_zones: boolean; plan_validity_days?: number; cancel_policy?: QuoteCancelPolicy } | null;
  walkin_rules?: { self_checkin: boolean; max_wait: number } | null;
  booking_misses?: { action: MissAction; limit: number; block_days: number } | null;
  payment_rules?: { require_deposit: boolean; deposit_minutes: number; deposit_refund_hours: number; link_hours: number; booking: boolean } | null;
  session_idle_minutes?: number;
  help_enabled?: boolean;
  session_portal_idle_minutes?: number;
  customer_groups?: { inactive_days: number; frequent_visits: number; vip_spend: number } | null;
}

type EquipmentDown = 'none' | 'observe' | 'disable';
type CostMethod = 'last' | 'average' | 'none';

const COST_METHOD_OPTIONS: { id: CostMethod; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'last', title: 'Último costo de compra', hint: 'El costo del producto pasa a ser el de la última recepción de compra.', icon: <TrendingUp size={18} /> },
  { id: 'average', title: 'Costo promedio ponderado', hint: 'Promedia el stock actual con las nuevas unidades recibidas.', icon: <Calculator size={18} /> },
  { id: 'none', title: 'No cambiar automáticamente', hint: 'El costo del producto solo cambia cuando lo editas a mano.', icon: <Minus size={18} /> },
];

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

type QuoteCancelPolicy = 'unused_quote' | 'unused_normal' | 'none';

const QUOTE_CANCEL_OPTIONS: { id: QuoteCancelPolicy; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'unused_quote', title: 'Lo usado al precio del presupuesto', hint: 'Respeta el descuento del plan. Ej.: 10 sesiones por S/ 800 y usó 4: lo usado vale S/ 320 y se devuelve lo que pagó de más.', icon: <FileText size={18} /> },
  { id: 'unused_normal', title: 'Lo usado a precio normal', hint: 'El cliente pierde el descuento del plan. Ej.: usó 4 sesiones de S/ 100: lo usado vale S/ 400.', icon: <Calculator size={18} /> },
  { id: 'none', title: 'No se devuelve dinero', hint: 'Lo pagado se queda y se anulan las cuotas que faltaban.', icon: <Lock size={18} /> },
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

const hoursInput = 'w-24 text-sm text-center border border-slate-200 rounded-lg px-3 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';

const LOCK_REASONS: Record<string, string> = {
  plan: 'No incluido en tu plan',
  platform: 'Desactivado por la plataforma',
  no_subscription: 'Requiere una suscripción activa',
};

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

type SectionId = 'empresa' | 'modulos' | 'moneda' | 'ayuda' | 'fases' | 'reservas' | 'inasistencias' | 'salas' | 'walkin' | 'ventas' | 'presupuestos' | 'inventario' | 'fidelizacion' | 'adelantos' | 'integraciones' | 'correo' | 'grupos' | 'seguridad' | 'descargas';

const SECTION_GROUPS: { title: string; sections: { id: SectionId; label: string }[] }[] = [
  { title: 'Tu empresa', sections: [{ id: 'empresa', label: 'Datos y código' }, { id: 'modulos', label: 'Módulos' }, { id: 'moneda', label: 'Moneda' }, { id: 'ayuda', label: 'Autoayuda' }] },
  { title: 'Agenda y reservas', sections: [{ id: 'fases', label: 'Fases de la cita' }, { id: 'reservas', label: 'Reglas de reserva' }, { id: 'inasistencias', label: 'Si reserva en línea y no asiste' }, { id: 'salas', label: 'Salas y equipos' }, { id: 'walkin', label: 'Cola sin cita' }] },
  { title: 'Ventas y cobros', sections: [{ id: 'ventas', label: 'Ventas y caja' }, { id: 'presupuestos', label: 'Presupuestos y planes' }, { id: 'inventario', label: 'Inventario y almacenes' }, { id: 'fidelizacion', label: 'Puntos y gift cards' }, { id: 'adelantos', label: 'Adelantos y links de pago' }, { id: 'integraciones', label: 'Integraciones' }] },
  { title: 'Mensajes', sections: [{ id: 'correo', label: 'Correo de tu empresa' }] },
  { title: 'Clientes', sections: [{ id: 'grupos', label: 'Grupos de clientes' }] },
  { title: 'Seguridad y registros', sections: [{ id: 'seguridad', label: 'Cierre de sesión' }, { id: 'descargas', label: 'Descargas de datos' }] },
];

const SECTION_LABELS = Object.fromEntries(SECTION_GROUPS.flatMap((g) => g.sections.map((s) => [s.id, s.label]))) as Record<SectionId, string>;

export default function TenantSettings() {
  const { user, loading: authLoading, refetchUser, can } = useAuth();
  const [data, setData] = useState<SettingsPayload | null>(null);
  const [modulesOff, setModulesOff] = useState<string[]>([]);
  const [statusNames, setStatusNames] = useState<Record<string, string>>({});
  const [bookingRules, setBookingRules] = useState<{ deadline: string; onMiss: OnMiss; release: string; cancel: string } | null>(null);
  const [equipmentDown, setEquipmentDown] = useState<EquipmentDown | null>(null);
  const [salesRules, setSalesRules] = useState<{ includeTax: boolean; taxRate: string; requireCash: boolean; partial: boolean; tolerance: string; autoRegister: boolean; refundDays: string; payFirst: boolean; arriveMinutes: string } | null>(null);
  const [inventoryRules, setInventoryRules] = useState<{ allowNegative: boolean; consumeOnService: boolean; transferTwoStep: boolean; costMethod: CostMethod; alertMinStock: boolean } | null>(null);
  const [loyaltyRules, setLoyaltyRules] = useState<{ pointsEnabled: boolean; amountPerPoint: string; pointValue: string; minRedeemPoints: string; pointsValidityDays: string; giftCardsEnabled: boolean; giftCardValidityDays: string } | null>(null);
  const [quoteRules, setQuoteRules] = useState<{ validityDays: string; allowInstallments: boolean; autoCreateSale: boolean; trackClinicalZones: boolean; planValidityDays: string; cancelPolicy: QuoteCancelPolicy } | null>(null);
  const [walkinRules, setWalkinRules] = useState<{ selfCheckin: boolean; maxWait: string } | null>(null);
  const [misses, setMisses] = useState<{ action: MissAction; limit: string; days: string } | null>(null);
  const [paymentRules, setPaymentRules] = useState<{ requireDeposit: boolean; depositMinutes: string; refundHours: string; linkHours: string } | null>(null);
  const [idle, setIdle] = useState<IdleValue>({ choice: '30', custom: '' });
  const [helpOn, setHelpOn] = useState(true);
  const [portalIdle, setPortalIdle] = useState<{ on: boolean } & IdleValue>({ on: false, choice: '30', custom: '' });
  const [groups, setGroups] = useState<{ inactive: string; frequent: string; vip: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string; section?: SectionId } | null>(null);
  const [active, setActive] = useState<SectionId>('empresa');
  const [baseline, setBaseline] = useState<Partial<Record<SectionId, string>>>({});
  const [externalDirty, setExternalDirty] = useState<Partial<Record<SectionId, boolean>>>({});
  const [pending, setPending] = useState<{ section?: SectionId; href?: string } | null>(null);
  const router = useRouter();

  const isAdmin = can('settings.view');
  const canSave = can('settings.edit');

  const apply = (payload: SettingsPayload) => {
    setData(payload);
    setModulesOff(payload.modules.filter((m) => m.can_toggle && !m.enabled).map((m) => m.key));
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
    setInventoryRules(payload.inventory_rules ? {
      allowNegative: payload.inventory_rules.allow_negative,
      consumeOnService: payload.inventory_rules.consume_on_service,
      transferTwoStep: payload.inventory_rules.transfer_two_step,
      costMethod: payload.inventory_rules.cost_method,
      alertMinStock: payload.inventory_rules.alert_min_stock,
    } : null);
    setLoyaltyRules(payload.loyalty_rules ? {
      pointsEnabled: payload.loyalty_rules.points_enabled,
      amountPerPoint: String(payload.loyalty_rules.amount_per_point),
      pointValue: String(payload.loyalty_rules.point_value),
      minRedeemPoints: String(payload.loyalty_rules.min_redeem_points),
      pointsValidityDays: String(payload.loyalty_rules.points_validity_days),
      giftCardsEnabled: payload.loyalty_rules.gift_cards_enabled,
      giftCardValidityDays: String(payload.loyalty_rules.gift_card_validity_days),
    } : null);
    setQuoteRules(payload.quote_rules ? {
      validityDays: String(payload.quote_rules.validity_days),
      allowInstallments: payload.quote_rules.allow_installments,
      autoCreateSale: payload.quote_rules.auto_create_sale,
      trackClinicalZones: payload.quote_rules.track_clinical_zones,
      planValidityDays: String(payload.quote_rules.plan_validity_days ?? 365),
      cancelPolicy: payload.quote_rules.cancel_policy ?? 'unused_quote',
    } : null);
    setWalkinRules(payload.walkin_rules ? {
      selfCheckin: payload.walkin_rules.self_checkin,
      maxWait: String(payload.walkin_rules.max_wait),
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
    setHelpOn(payload.help_enabled ?? true);
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

  const payloadOf = (id: SectionId): Record<string, unknown> | null => {
    switch (id) {
      case 'modulos': return { modules_off: [...modulesOff].sort() };
      case 'fases': return { appointment_statuses: statusNames };
      case 'ayuda': return { help_enabled: helpOn };
      case 'seguridad': return { session_idle_minutes: fromIdle(idle), session_portal_idle_minutes: portalIdle.on ? fromIdle(portalIdle) : 0 };
      case 'grupos': return groups ? { customer_groups: { inactive_days: Number(groups.inactive || 0), frequent_visits: Number(groups.frequent || 0), vip_spend: Number(groups.vip || 0) } } : null;
      case 'salas': return equipmentDown ? { equipment_down: equipmentDown } : null;
      case 'ventas': return salesRules ? {
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
        } : null;
      case 'inventario': return inventoryRules ? {
          inventory_rules: {
            allow_negative: inventoryRules.allowNegative,
            consume_on_service: inventoryRules.consumeOnService,
            transfer_two_step: inventoryRules.transferTwoStep,
            cost_method: inventoryRules.costMethod,
            alert_min_stock: inventoryRules.alertMinStock,
          },
        } : null;
      case 'fidelizacion': return loyaltyRules ? {
          loyalty_rules: {
            points_enabled: loyaltyRules.pointsEnabled,
            amount_per_point: Number(loyaltyRules.amountPerPoint || 10),
            point_value: Number(loyaltyRules.pointValue || 0.5),
            min_redeem_points: Number(loyaltyRules.minRedeemPoints || 1),
            points_validity_days: Number(loyaltyRules.pointsValidityDays || 0),
            gift_cards_enabled: loyaltyRules.giftCardsEnabled,
            gift_card_validity_days: Number(loyaltyRules.giftCardValidityDays || 0),
          },
        } : null;
      case 'presupuestos': return quoteRules ? {
          quote_rules: {
            validity_days: Number(quoteRules.validityDays || 0),
            allow_installments: quoteRules.allowInstallments,
            auto_create_sale: quoteRules.autoCreateSale,
            track_clinical_zones: quoteRules.trackClinicalZones,
            plan_validity_days: Number(quoteRules.planValidityDays || 0),
            cancel_policy: quoteRules.cancelPolicy,
          },
        } : null;
      case 'walkin': return walkinRules ? {
          walkin_rules: {
            self_checkin: walkinRules.selfCheckin,
            max_wait: Number(walkinRules.maxWait || 90),
          },
        } : null;
      case 'inasistencias': return misses ? { booking_misses: { action: misses.action, limit: Number(misses.limit || 1), block_days: Number(misses.days || 0) } } : null;
      case 'adelantos': return paymentRules ? {
          payment_rules: {
            require_deposit: paymentRules.requireDeposit,
            deposit_minutes: Number(paymentRules.depositMinutes || 0),
            deposit_refund_hours: Number(paymentRules.refundHours || 0),
            link_hours: Number(paymentRules.linkHours || 0),
          },
        } : null;
      case 'reservas': return bookingRules ? {
          booking_rules: {
            confirm_deadline_hours: Number(bookingRules.deadline || 0),
            confirm_on_miss: bookingRules.onMiss,
            confirm_release_hours: Number(bookingRules.release || 0),
            cancel_window_hours: Number(bookingRules.cancel || 0),
          },
        } : null;
      default: return null;
    }
  };

  const snapshot = (id: SectionId) => {
    const payload = payloadOf(id);
    return payload ? JSON.stringify(payload) : undefined;
  };

  const ALL_IDS = SECTION_GROUPS.flatMap((g) => g.sections.map((s) => s.id));

  useEffect(() => {
    if (!data) return;
    setBaseline(Object.fromEntries(ALL_IDS.map((id) => [id, snapshot(id)])));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  const isDirty = (id: SectionId) => Boolean(externalDirty[id]) || (baseline[id] !== undefined && snapshot(id) !== baseline[id]);

  const dirtyHandlers = useMemo(() => Object.fromEntries(SECTION_GROUPS.flatMap((g) => g.sections.map((s) => [s.id, (dirty: boolean) => {
    setExternalDirty((current) => (Boolean(current[s.id]) === dirty ? current : { ...current, [s.id]: dirty }));
  }]))) as Record<SectionId, (dirty: boolean) => void>, []);
  const markExternal = (id: SectionId) => dirtyHandlers[id];

  const saveSection = async (id: SectionId) => {
    const payload = payloadOf(id);
    if (!payload) return;
    try {
      setSaving(true);
      setMessage(null);
      const res = await api.put('/tenant-settings', payload);
      apply(res.data);
      await refetchUser();
      setMessage({ type: 'ok', text: 'Guardado.', section: id });
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setMessage({ type: 'error', text: first || err.response?.data?.message || 'No se pudo guardar.', section: id });
    } finally {
      setSaving(false);
    }
  };

  const activeDirty = data ? isDirty(active) : false;

  useEffect(() => {
    const fromHash = () => {
      const hash = window.location.hash.replace('#', '') as SectionId;
      if ((hash as string) === 'vocabulario') { router.replace('/personalization#vocabulario'); return; }
      if (ALL_IDS.includes(hash)) setActive(hash);
    };
    fromHash();
    window.addEventListener('hashchange', fromHash);
    return () => window.removeEventListener('hashchange', fromHash);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!activeDirty) return;
    const onUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
    const onClick = (event: MouseEvent) => {
      const link = (event.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
      if (!link || link.target === '_blank' || event.metaKey || event.ctrlKey) return;
      const url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || url.pathname === window.location.pathname) return;
      event.preventDefault();
      event.stopPropagation();
      setPending({ href: url.pathname + url.search + url.hash });
    };
    window.addEventListener('beforeunload', onUnload);
    document.addEventListener('click', onClick, true);
    return () => {
      window.removeEventListener('beforeunload', onUnload);
      document.removeEventListener('click', onClick, true);
    };
  }, [activeDirty]);

  const openSection = (id: SectionId, force = false) => {
    if (id === active) return;
    if (!force && activeDirty) {
      setPending({ section: id });
      return;
    }
    setMessage(null);
    setActive(id);
    window.history.replaceState(null, '', `#${id}`);
  };

  const leave = () => {
    const target = pending;
    setPending(null);
    if (data) apply(data);
    setExternalDirty({});
    if (target?.section) openSection(target.section, true);
    else if (target?.href) router.push(target.href);
  };

  if (authLoading || loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-brand-accent animate-spin" />
      </div>
    );
  }

  if (!isAdmin || !data) {
    return (
      <div className="max-w-2xl mx-auto pt-10 font-sans text-[#1F2937]">
        <div className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-8 flex items-start gap-4">
          <span className="p-3 bg-brand-soft/20 text-brand-ink rounded-2xl shrink-0">
            <Lock className="w-6 h-6" />
          </span>
          <div className="space-y-2">
            <h1 className="text-xl font-black text-brand-ink tracking-tight">Configuración de la empresa</h1>
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
  const unavailable = data.modules.filter((m) => !m.can_toggle && m.locked !== 'core');

  const available: Record<SectionId, boolean> = {
    empresa: true,
    modulos: true,
    moneda: true,
    ayuda: Boolean(user?.is_tenant_admin),
    fases: (data.appointment_statuses?.length ?? 0) > 0,
    reservas: Boolean(bookingRules),
    inasistencias: Boolean(misses),
    salas: Boolean(equipmentDown),
    walkin: Boolean(walkinRules),
    ventas: Boolean(salesRules),
    presupuestos: Boolean(quoteRules),
    inventario: Boolean(inventoryRules),
    fidelizacion: Boolean(loyaltyRules),
    adelantos: Boolean(paymentRules),
    integraciones: Boolean(paymentRules),
    correo: true,
    grupos: Boolean(groups),
    seguridad: true,
    descargas: can('users.view'),
  };

  const index = SECTION_GROUPS
    .map((group) => ({ ...group, sections: group.sections.filter((s) => available[s.id]) }))
    .filter((group) => group.sections.length > 0);

  const current: SectionId = available[active] ? active : 'empresa';
  const show = (id: SectionId) => current === id;

  const saveBar = (id: SectionId) => (
    <div className="flex flex-wrap items-center justify-end gap-4 pt-4 border-t border-slate-100" data-save-bar={id}>
      {message?.section === id && (
        <span className={`text-sm font-medium ${message.type === 'ok' ? 'text-emerald-600' : 'text-red-600'}`} data-save-message>{message.text}</span>
      )}
      {isDirty(id) && message?.section !== id && <span className="text-xs text-amber-700" data-unsaved>Tienes cambios sin guardar.</span>}
      <Button
        type="button"
        data-save-section={id}
        onClick={() => saveSection(id)}
        disabled={saving || !canSave || !isDirty(id)}
        title={canSave ? undefined : 'Tu cuenta puede ver la configuración, pero no cambiarla.'} variant="primary"
      >
        {saving ? 'Guardando...' : 'Guardar'}
      </Button>
    </div>
  );

  return (
    <div className="space-y-6 pb-10 font-sans text-[#1F2937]">
      <div>
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Configuración de la empresa</h1>
        <p className="text-sm text-slate-500 font-medium">Elige una sección a la izquierda. Cada sección se guarda con su propio botón.</p>
      </div>

      <div className="lg:hidden" data-settings-select>
        <Select
          ariaLabel="Sección"
          clearable={false}
          value={current}
          onChange={(e) => { if (e.target.value) openSection(e.target.value as SectionId); }}
          options={index.flatMap((group) => group.sections.map((s) => ({ value: s.id, label: `${group.title} · ${s.label}` })))}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[15rem_minmax(0,1fr)] gap-6 items-start">
        <nav className="hidden lg:block sticky top-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs p-3 space-y-3" aria-label="Secciones de la configuración" data-settings-index>
          {index.map((group) => (
            <div key={group.title}>
              <span className="block px-3 pb-1 text-xs font-bold text-brand-ink">{group.title}</span>
              {group.sections.map((s) => {
                const selected = current === s.id;
                return (
                  <button
                    key={s.id}
                    type="button"
                    data-section-link={s.id}
                    aria-current={selected ? 'page' : undefined}
                    onClick={() => openSection(s.id)}
                    className={`w-full flex items-center justify-between gap-2 text-left px-3 py-1.5 rounded-lg text-sm transition-colors ${
                      selected ? 'bg-brand-soft/20 text-brand-ink font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-ink'
                    }`}
                  >
                    <span className="truncate">{s.label}</span>
                    {selected && activeDirty && <span className="w-2 h-2 rounded-full bg-amber-500 shrink-0" title="Cambios sin guardar" />}
                  </button>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="min-w-0 space-y-6" data-settings-section={current}>
      {show('empresa') && (
      <section className={cardClass}>
        <SectionTitle>
          <Building2 className="w-4 h-4 text-brand-accent" /> Datos y código
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
      )}

      {show('modulos') && (
      <section className={cardClass}>
        <div>
          <SectionTitle>
            <Puzzle className="w-4 h-4 text-brand-accent" /> Módulos
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
                  <span className="flex items-center gap-1.5 min-w-0">
                    <span className={`text-sm font-semibold ${isOn ? 'text-slate-800' : 'text-slate-400'}`}>{module.name}</span>
                    {MODULE_HINTS[module.key] && <InfoTip text={MODULE_HINTS[module.key]} title={module.name} label={`Qué permite ${module.name}`} />}
                  </span>
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
        {saveBar('modulos')}
      </section>
      )}


      {show('fases') && (data.appointment_statuses?.length ?? 0) > 0 && (
        <section className={cardClass}>
          <div>
            <SectionTitle>
              <CalendarCheck className="w-4 h-4 text-brand-accent" /> Fases de la cita
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
                  className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                />
              </div>
            ))}
          </div>
          {saveBar('fases')}
        </section>
      )}

      {show('reservas') && bookingRules && (
        <section className={cardClass} data-card="booking-rules">
          <div>
            <SectionTitle>
              <ClipboardCheck className="w-4 h-4 text-brand-accent" /> Reglas de reserva
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
                          <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-brand text-brand-soft' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                          <span>
                            <span className="block text-sm font-bold text-brand-ink">{option.title}</span>
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
          {saveBar('reservas')}
        </section>
      )}

      {show('salas') && equipmentDown && (
        <section className={cardClass} data-card="resources-rules">
          <div>
            <SectionTitle>
              <DoorOpen className="w-4 h-4 text-brand-accent" /> Salas y equipos
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
                  <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-brand text-brand-soft' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                  <span>
                    <span className="block text-sm font-bold text-brand-ink">{option.title}</span>
                    <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                  </span>
                </OptionCard>
              );
            })}
          </div>
          {saveBar('salas')}
        </section>
      )}

      {show('walkin') && walkinRules && (
        <section className={cardClass} data-card="walkin-rules">
          <div>
            <SectionTitle>
              <ListOrdered className="w-4 h-4 text-brand-accent" /> Cola sin cita
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Para quien llega sin cita y espera su turno, como en una barbería.
            </p>
          </div>

          <div className="flex items-center justify-between gap-4">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Kiosko para que se anoten solos</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Una tableta en la entrada donde la persona escribe su celular, elige el servicio y recibe su turno.
              </span>
              {walkinRules.selfCheckin && user?.tenant?.code && (
                <a href={`/kiosko/${user.tenant.code}`} target="_blank" rel="noreferrer" data-kiosk-link className="inline-block mt-1.5 text-xs font-semibold text-brand-ink hover:text-brand-accent underline underline-offset-2">
                  Abrir el kiosko en esta pantalla
                </a>
              )}
            </div>
            <Switch
              checked={walkinRules.selfCheckin}
              onChange={(checked) => { setMessage(null); setWalkinRules({ ...walkinRules, selfCheckin: checked }); }}
              label="Kiosko para que se anoten solos"
            />
          </div>

          {walkinRules.selfCheckin && (
            <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-100">
              <label htmlFor="walkin-max-wait" className="text-sm font-semibold text-slate-800">En el kiosko, no anotar si la espera pasa de</label>
              <input
                id="walkin-max-wait"
                inputMode="numeric"
                data-rule="max_wait"
                value={walkinRules.maxWait}
                onChange={(e) => { setMessage(null); setWalkinRules({ ...walkinRules, maxWait: e.target.value.replace(/\D/g, '').slice(0, 3) }); }}
                className={hoursInput}
              />
              <span className="text-sm text-slate-600">minutos.</span>
              <span className="w-full text-xs text-slate-500">Si la espera es mayor, el kiosko le sugiere reservar una cita. Recepción sí puede anotarlo igual. Va de 10 a 300 minutos.</span>
            </div>
          )}

          {saveBar('walkin')}
        </section>
      )}

      {show('moneda') && <CurrencySettings />}

      {show('ayuda') && (
      <section className={cardClass} data-card="help">
        <div>
          <SectionTitle>
            <CircleHelp className="w-4 h-4 text-brand-accent" /> Autoayuda
          </SectionTitle>
          <p className="text-xs text-slate-500 font-medium mt-1">
            Un botón <span className="font-semibold text-slate-700">?</span> arriba, junto a la campana, explica la pantalla donde está cada persona: para qué sirve, qué hace cada botón y cómo se hacen las tareas de todos los días. En el menú aparece además <span className="font-semibold text-slate-700">Autoayuda</span>, con la guía de todas sus pantallas. Cada persona ve solo lo que su cuenta le permite hacer.
          </p>
        </div>

        <div className="flex items-center justify-between gap-4" data-row="help-switch">
          <span>
            <span className="block text-sm font-semibold text-slate-800">Mostrar la ayuda a mi equipo</span>
            <span className="block text-xs text-slate-500 mt-0.5">Apagada, el botón ? y Autoayuda dejan de verse para todos. Solo el administrador puede cambiarlo.</span>
          </span>
          <Switch checked={helpOn} onChange={(on) => { setMessage(null); setHelpOn(on); }} label="Mostrar la ayuda a mi equipo" />
        </div>
        {saveBar('ayuda')}
      </section>
      )}

      {show('ventas') && salesRules && (
        <section className={cardClass} data-card="sales-rules">
          <div>
            <SectionTitle>
              <Wallet className="w-4 h-4 text-brand-accent" /> Ventas y caja
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
          {saveBar('ventas')}
        </section>
      )}

      {show('inventario') && inventoryRules && (
        <section className={cardClass} data-card="inventory-rules">
          <div>
            <SectionTitle>
              <Boxes className="w-4 h-4 text-brand-accent" /> Inventario y almacenes
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Cómo se descuentan las existencias al vender o atender, cómo viaja la mercadería entre sedes y cómo se actualiza el costo al comprar.
            </p>
          </div>

          <div className="flex items-center justify-between gap-4">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Descontar insumos al completar una cita</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Cuando una cita pasa a &quot;Completada&quot;, el sistema descuenta automáticamente los insumos asignados a ese servicio en el almacén de la sede.
              </span>
            </div>
            <Switch
              checked={inventoryRules.consumeOnService}
              onChange={(on) => { setMessage(null); setInventoryRules({ ...inventoryRules, consumeOnService: on }); }}
              label="Descontar insumos al completar una cita"
            />
          </div>

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Permitir vender sin stock registrado</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Si lo activas, el punto de venta permite cobrar productos aunque su existencia llegue a cero o quede en negativo. Si lo apagas, solo permite vender lo disponible en la sede.
              </span>
            </div>
            <Switch
              checked={inventoryRules.allowNegative}
              onChange={(on) => { setMessage(null); setInventoryRules({ ...inventoryRules, allowNegative: on }); }}
              label="Permitir vender sin stock registrado"
            />
          </div>

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Traslados entre sedes en dos pasos</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Al enviar mercadería a otra sede queda &quot;En tránsito&quot; hasta que la sede de destino confirme la recepción. Si lo apagas, el stock se mueve en un solo paso.
              </span>
            </div>
            <Switch
              checked={inventoryRules.transferTwoStep}
              onChange={(on) => { setMessage(null); setInventoryRules({ ...inventoryRules, transferTwoStep: on }); }}
              label="Traslados entre sedes en dos pasos"
            />
          </div>

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Avisar cuando un producto baje del stock mínimo</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Envía una notificación interna a la administración y al gerente de sede cuando un producto llega a su alerta mínima o se agota.
              </span>
            </div>
            <Switch
              checked={inventoryRules.alertMinStock}
              onChange={(on) => { setMessage(null); setInventoryRules({ ...inventoryRules, alertMinStock: on }); }}
              label="Avisar cuando un producto baje del stock mínimo"
            />
          </div>

          <div className="pt-4 border-t border-slate-100 space-y-2.5">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Al recibir una compra, cómo actualizar el costo del producto</span>
              <span className="block text-xs text-slate-500 mt-0.5">Define cómo se calcula el costo unitario cuando ingresas mercadería con un nuevo precio de compra.</span>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Método de costo">
              {COST_METHOD_OPTIONS.map((option) => {
                const chosen = inventoryRules.costMethod === option.id;
                return (
                  <OptionCard
                    key={option.id}
                    type="button"
                    role="radio"
                    aria-checked={chosen}
                    data-cost-method={option.id}
                    onClick={() => { setMessage(null); setInventoryRules({ ...inventoryRules, costMethod: option.id }); }}
                    selected={chosen}
                    className="flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors"
                  >
                    <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-brand text-brand-soft' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                    <span>
                      <span className="block text-sm font-bold text-brand-ink">{option.title}</span>
                      <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                    </span>
                  </OptionCard>
                );
              })}
            </div>
          </div>

          {saveBar('inventario')}
        </section>
      )}

      {show('fidelizacion') && loyaltyRules && (
        <section className={cardClass} data-card="loyalty-rules">
          <div>
            <SectionTitle>
              <Gift className="w-4 h-4 text-brand-accent" /> Puntos y gift cards
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Cómo acumulan y canjean puntos tus clientes al pagar, y cuál es la vigencia por defecto de las tarjetas de regalo.
            </p>
          </div>

          <div className="flex items-center justify-between gap-4">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Acumular y canjear puntos por compras</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Cada vez que una venta con cliente asignado queda pagada, el cliente suma puntos automáticamente y puede usarlos como medio de pago en sus próximas compras.
              </span>
            </div>
            <Switch
              checked={loyaltyRules.pointsEnabled}
              onChange={(on) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, pointsEnabled: on }); }}
              label="Acumular y canjear puntos por compras"
            />
          </div>

          {loyaltyRules.pointsEnabled && (
            <div className="space-y-4 pt-4 border-t border-slate-100">
              <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="loyalty-amount-per-point" className="text-sm font-semibold text-slate-800">El cliente gana 1 punto por cada</label>
                <span className="text-sm font-semibold text-slate-600">{currencySymbol()}</span>
                <input
                  id="loyalty-amount-per-point"
                  inputMode="decimal"
                  data-rule="amount_per_point"
                  value={loyaltyRules.amountPerPoint}
                  onChange={(e) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, amountPerPoint: e.target.value.replace(/[^\d.]/g, '').slice(0, 7) }); }}
                  className={hoursInput}
                />
                <span className="text-sm text-slate-600">pagados.</span>
                <span className="w-full text-xs text-slate-500">
                  No suma puntos la parte pagada con puntos o con gift card, para no duplicar beneficios.
                </span>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="loyalty-point-value" className="text-sm font-semibold text-slate-800">Al canjear, cada punto equivale a</label>
                <span className="text-sm font-semibold text-slate-600">{currencySymbol()}</span>
                <input
                  id="loyalty-point-value"
                  inputMode="decimal"
                  data-rule="point_value"
                  value={loyaltyRules.pointValue}
                  onChange={(e) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, pointValue: e.target.value.replace(/[^\d.]/g, '').slice(0, 7) }); }}
                  className={hoursInput}
                />
                <span className="text-sm text-slate-600">de descuento en el cobro.</span>
                <span className="w-full text-xs text-slate-500">
                  Ejemplo: con 1 punto cada {currencySymbol()} {loyaltyRules.amountPerPoint || '10'} y valor de {currencySymbol()} {loyaltyRules.pointValue || '0.50'}, una compra de {currencySymbol()} 100 genera {Math.floor(100 / Math.max(0.1, Number(loyaltyRules.amountPerPoint || 10)))} puntos ({currencySymbol()} {(Math.floor(100 / Math.max(0.1, Number(loyaltyRules.amountPerPoint || 10))) * Number(loyaltyRules.pointValue || 0.5)).toFixed(2)} para su próxima visita).
                </span>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="loyalty-min-redeem" className="text-sm font-semibold text-slate-800">Mínimo acumulado para poder canjear:</label>
                <input
                  id="loyalty-min-redeem"
                  inputMode="numeric"
                  data-rule="min_redeem_points"
                  value={loyaltyRules.minRedeemPoints}
                  onChange={(e) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, minRedeemPoints: e.target.value.replace(/\D/g, '').slice(0, 6) }); }}
                  className={hoursInput}
                />
                <span className="text-sm text-slate-600">puntos.</span>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="loyalty-points-validity" className="text-sm font-semibold text-slate-800">Los puntos vencen a los</label>
                <input
                  id="loyalty-points-validity"
                  inputMode="numeric"
                  data-rule="points_validity_days"
                  value={loyaltyRules.pointsValidityDays}
                  onChange={(e) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, pointsValidityDays: e.target.value.replace(/\D/g, '').slice(0, 4) }); }}
                  className={hoursInput}
                />
                <span className="text-sm text-slate-600">días de ganarse.</span>
                <span className="w-full text-xs text-slate-500">Con 0 días, los puntos no vencen.</span>
              </div>
            </div>
          )}

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Emitir y cobrar con gift cards</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Permite crear tarjetas de regalo con código único y saldo recargable o descontable parcial y totalmente al cobrar citas o ventas.
              </span>
            </div>
            <Switch
              checked={loyaltyRules.giftCardsEnabled}
              onChange={(on) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, giftCardsEnabled: on }); }}
              label="Emitir y cobrar con gift cards"
            />
          </div>

          {loyaltyRules.giftCardsEnabled && (
            <div className="flex flex-wrap items-center gap-3 pt-2">
              <label htmlFor="loyalty-gc-validity" className="text-sm font-semibold text-slate-800">Vigencia por defecto al emitir una gift card:</label>
              <input
                id="loyalty-gc-validity"
                inputMode="numeric"
                data-rule="gift_card_validity_days"
                value={loyaltyRules.giftCardValidityDays}
                onChange={(e) => { setMessage(null); setLoyaltyRules({ ...loyaltyRules, giftCardValidityDays: e.target.value.replace(/\D/g, '').slice(0, 4) }); }}
                className={hoursInput}
              />
              <span className="text-sm text-slate-600">días.</span>
              <span className="w-full text-xs text-slate-500">Con 0 días, las gift cards se emiten sin fecha de vencimiento por defecto.</span>
            </div>
          )}

          {saveBar('fidelizacion')}
        </section>
      )}

      {show('presupuestos') && quoteRules && (
        <section className={cardClass} data-card="quote-rules">
          <div>
            <SectionTitle>
              <FileText className="w-4 h-4 text-brand-accent" /> Presupuestos y planes de tratamiento
            </SectionTitle>
            <p className="text-xs text-slate-500 font-medium mt-1">
              Cómo se arman, cuánto duran y qué ocurre cuando un cliente aprueba un presupuesto o plan de tratamiento.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <label htmlFor="quote-validity-days" className="text-sm font-semibold text-slate-800">Un presupuesto vence a los</label>
            <input
              id="quote-validity-days"
              inputMode="numeric"
              data-rule="validity_days"
              value={quoteRules.validityDays}
              onChange={(e) => { setMessage(null); setQuoteRules({ ...quoteRules, validityDays: e.target.value.replace(/\D/g, '').slice(0, 4) }); }}
              className={hoursInput}
            />
            <span className="text-sm text-slate-600">días de haberse creado.</span>
            <span className="w-full text-xs text-slate-500">Con 0 días, los presupuestos nacen sin fecha de vencimiento por defecto.</span>
          </div>

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Permitir planes de pago en cuotas</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Habilita armar un cronograma de cuotas con fechas de vencimiento dentro del presupuesto y llevar el control de qué cuotas están al día o vencidas.
              </span>
            </div>
            <Switch
              checked={quoteRules.allowInstallments}
              onChange={(on) => { setMessage(null); setQuoteRules({ ...quoteRules, allowInstallments: on }); }}
              label="Permitir planes de pago en cuotas"
            />
          </div>

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Generar la venta por cobrar automáticamente al aprobar</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Cuando el presupuesto pasa a &quot;Aprobado&quot;, se crea sola su cuenta por cobrar en Ventas para poder recibir pagos totales o por cuotas de inmediato.
              </span>
            </div>
            <Switch
              checked={quoteRules.autoCreateSale}
              onChange={(on) => { setMessage(null); setQuoteRules({ ...quoteRules, autoCreateSale: on }); }}
              label="Generar la venta por cobrar automáticamente al aprobar"
            />
          </div>

          <div className="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Registrar pieza dental, zona o etapa en cada línea</span>
              <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">
                Muestra un campo adicional en cada servicio del plan para anotar la pieza dental, zona corporal o etapa del tratamiento.
              </span>
            </div>
            <Switch
              checked={quoteRules.trackClinicalZones}
              onChange={(on) => { setMessage(null); setQuoteRules({ ...quoteRules, trackClinicalZones: on }); }}
              label="Registrar pieza dental, zona o etapa en cada línea"
            />
          </div>

          <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-100">
            <label htmlFor="quote-plan-validity" className="text-sm font-semibold text-slate-800">Al aprobarse, el plan de tratamiento dura</label>
            <input
              id="quote-plan-validity"
              inputMode="numeric"
              data-rule="plan_validity_days"
              value={quoteRules.planValidityDays}
              onChange={(e) => { setMessage(null); setQuoteRules({ ...quoteRules, planValidityDays: e.target.value.replace(/\D/g, '').slice(0, 4) }); }}
              className={hoursInput}
            />
            <span className="text-sm text-slate-600">días.</span>
            <span className="w-full text-xs text-slate-500">
              El presupuesto aprobado se convierte en un paquete con sus sesiones, que se descuentan solas al atender cada cita. Si el cliente no asiste o cancela tarde, la sesión no se pierde.
            </span>
          </div>

          <div className="space-y-2 pt-4 border-t border-slate-100">
            <span className="block text-sm font-semibold text-slate-800">Si se anula un presupuesto ya aprobado</span>
            <span className="block text-xs text-slate-500">Cómo se cobra lo que el cliente ya usó. Los productos y otros cargos del presupuesto se cobran completos.</span>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Si se anula un presupuesto ya aprobado">
              {QUOTE_CANCEL_OPTIONS.map((option) => {
                const chosen = quoteRules.cancelPolicy === option.id;
                return (
                  <OptionCard
                    key={option.id}
                    type="button"
                    role="radio"
                    aria-checked={chosen}
                    data-cancel-policy={option.id}
                    onClick={() => { setMessage(null); setQuoteRules({ ...quoteRules, cancelPolicy: option.id }); }} selected={chosen} className="flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors"
                  >
                    <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-brand text-brand-soft' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                    <span>
                      <span className="block text-sm font-bold text-brand-ink">{option.title}</span>
                      <span className="block text-xs text-slate-500 mt-0.5 leading-relaxed">{option.hint}</span>
                    </span>
                  </OptionCard>
                );
              })}
            </div>
          </div>

          {saveBar('presupuestos')}
        </section>
      )}

      {show('adelantos') && paymentRules && (
        <section className={cardClass} data-card="payment-rules">
          <div>
            <SectionTitle>
              <Link2 className="w-4 h-4 text-brand-accent" /> Adelantos y links de pago
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
          {saveBar('adelantos')}
        </section>
      )}

      {show('integraciones') && paymentRules && <IntegrationsSettings canSave={canSave} onDirtyChange={markExternal('integraciones')} />}

      {show('correo') && <MessagingSettings part="email" canSave={canSave} company={user?.tenant?.name ?? ''} onDirtyChange={markExternal('correo')} />}

      {show('inasistencias') && misses && (
        <section className={cardClass} data-card="booking-misses">
          <div>
            <SectionTitle>
              <CalendarX2 className="w-4 h-4 text-brand-accent" /> Si reserva en línea y no asiste
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
                  <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-brand text-brand-soft' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                  <span>
                    <span className="block text-sm font-bold text-brand-ink">{option.title}</span>
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
          {saveBar('inasistencias')}
        </section>
      )}

      {show('grupos') && groups && (
        <section className={cardClass} data-card="customer-groups">
          <div>
            <SectionTitle>
              <Users className="w-4 h-4 text-brand-accent" /> Grupos de clientes
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
          {saveBar('grupos')}
        </section>
      )}

      {show('seguridad') && (
      <section className={cardClass} data-card="session-idle">
        <div>
          <SectionTitle>
            <Timer className="w-4 h-4 text-brand-accent" /> Cierre de sesión
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
        {saveBar('seguridad')}
      </section>
      )}

      {show('descargas') && can('users.view') && <DataExportsLog className={cardClass} />}
        </div>
      </div>

      <AppModal
        isOpen={pending !== null}
        type="confirm"
        variant="danger"
        title="¿Salir sin guardar?"
        message={`Tienes cambios sin guardar en "${SECTION_LABELS[current]}". Si sales ahora, se pierden.`}
        confirmText="Salir sin guardar"
        cancelText="Seguir editando"
        onConfirm={leave}
        onClose={() => setPending(null)}
      />
    </div>
  );
}
