export type AutomationKey = 'birthday' | 'loyal' | 'inactive' | 'first_visit' | 'no_show';

export interface Automation {
  id: string;
  key: AutomationKey;
  label: string;
  when: string;
  daily: boolean;
  active: boolean;
  subject: string;
  body: string;
  coupon_id: string | null;
  coupon_code: string | null;
  send_time: string | null;
  delay_minutes: number;
  repeat_days: number;
  enabled_at: string | null;
  results: { sent_month: number; booked_month: number; last_sent_at: string | null };
}

export interface AutomationsPayload {
  automations: Automation[];
  coupons: { id: string; code: string; description: string | null; benefit: string }[];
  variables: { token: string; label: string }[];
  email: { state: 'none' | 'simulated' | 'ok'; label: string };
  inactive_days: number;
  booked_days: number;
  message?: string;
}

export const REPEAT_OPTIONS = [
  { value: 0, label: 'Una sola vez por cliente' },
  { value: 30, label: 'Como máximo una vez cada 30 días' },
  { value: 90, label: 'Como máximo una vez cada 90 días' },
  { value: 180, label: 'Como máximo una vez cada 6 meses' },
  { value: 365, label: 'Como máximo una vez al año' },
];

export const repeatLabel = (days: number) => REPEAT_OPTIONS.find((o) => o.value === days)?.label ?? `Como máximo una vez cada ${days} días`;

export const delayLabel = (minutes: number) => {
  if (minutes <= 0) return 'Apenas pasa';
  if (minutes % 1440 === 0) return `${minutes / 1440} ${minutes === 1440 ? 'día' : 'días'} después`;
  if (minutes % 60 === 0) return `${minutes / 60} ${minutes === 60 ? 'hora' : 'horas'} después`;
  return `${minutes} min después`;
};

export const timingLabel = (a: Automation) => (a.daily ? `A las ${a.send_time ?? '09:00'}` : delayLabel(a.delay_minutes));
