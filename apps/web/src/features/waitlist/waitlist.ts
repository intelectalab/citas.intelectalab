// Tipos y utilidades de la lista de espera: quién quiere una cita y no
// encontró horario.
import { formatDate } from '@/features/appointments/appointments';

export type WaitlistStatus = 'waiting' | 'notified' | 'booked' | 'expired' | 'cancelled';

export interface WaitlistEntry {
  id: string;
  status: WaitlistStatus;
  status_name: string;
  priority: boolean;
  // AAAA-MM-DD; sin última fecha espera hasta que haya horario.
  date_from: string;
  date_to: string | null;
  // Franja del día que le sirve; sin ella, cualquier hora.
  time_from: string | null;
  time_to: string | null;
  notes: string | null;
  notified_at: string | null;
  created_at: string;
  days_waiting: number;
  // Días que le quedan para recibir un horario; null si no puso última fecha.
  days_left: number | null;
  customer: { id: string; code: string; name: string; phone: string | null; email: string | null } | null;
  dependent: { id: string; name: string; species: string | null } | null;
  service: { id: string; name: string; duration_minutes: number } | null;
  // Sin profesional: lo atiende cualquiera.
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  appointment: { id: string; code: string; status: string; status_name: string; starts_at: string } | null;
}

export interface WaitlistSlotDay {
  date: string;
  groups: { professional: { id: string; display_name: string }; slots: string[] }[];
}

// Color de cada estado. Salen de la paleta para que el tema oscuro los alcance.
export const WAITLIST_STATUS_STYLES: Record<WaitlistStatus, string> = {
  waiting: 'bg-amber-50 text-amber-700',
  notified: 'bg-sky-50 text-sky-700',
  booked: 'bg-violet-50 text-violet-600',
  expired: 'bg-rose-50 text-rose-700',
  cancelled: 'bg-slate-100 text-slate-500',
};

// Franjas del día para elegir con un clic.
export const TIME_PRESETS: { id: string; label: string; from: string; to: string }[] = [
  { id: 'any', label: 'Cualquier hora', from: '', to: '' },
  { id: 'morning', label: 'Mañana', from: '08:00', to: '12:00' },
  { id: 'afternoon', label: 'Tarde', from: '12:00', to: '18:00' },
  { id: 'evening', label: 'Noche', from: '18:00', to: '22:00' },
];

const shortDate = (date: string) => formatDate(date, { day: 'numeric', month: 'short' });

// "Desde el 09 oct" · "Del 09 oct al 19 oct" · "Solo el 09 oct"
export const dateRangeLabel = (entry: Pick<WaitlistEntry, 'date_from' | 'date_to'>): string => {
  if (!entry.date_to) return `Desde el ${shortDate(entry.date_from)}`;
  if (entry.date_to === entry.date_from) return `Solo el ${shortDate(entry.date_from)}`;
  return `Del ${shortDate(entry.date_from)} al ${shortDate(entry.date_to)}`;
};

// "Cualquier hora" · "Mañana (08:00 a 12:00)" · "Desde las 15:00" · "Hasta las 12:00"
export const timeRangeLabel = (entry: Pick<WaitlistEntry, 'time_from' | 'time_to'>): string => {
  if (!entry.time_from && !entry.time_to) return 'Cualquier hora';
  const preset = TIME_PRESETS.find((p) => p.from === entry.time_from && p.to === entry.time_to);
  if (preset) return `${preset.label} (${preset.from} a ${preset.to})`;
  if (entry.time_from && entry.time_to) return `De ${entry.time_from} a ${entry.time_to}`;
  return entry.time_from ? `Desde las ${entry.time_from}` : `Hasta las ${entry.time_to}`;
};

// "Hoy" · "1 día" · "5 días"
export const waitingLabel = (days: number): string => (days <= 0 ? 'Desde hoy' : days === 1 ? 'Hace 1 día' : `Hace ${days} días`);
